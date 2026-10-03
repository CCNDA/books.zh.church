<?php
declare(strict_types=1);
/**
 * 一次性修復:以修好後的規則重切既有書的署名,補寫 persons + book_persons。
 *
 * ════════ 為什麼需要這支 ════════
 * tools/import.php 對**已存在的 edition**(source + source_url 命中)會直接跳過,
 * 所以程式修好之後,既有資料不會自己變好。票上實測(2026-09-08):
 *   - books.author 含全形分號 47 本 → 那幾本的多位作者從來沒被拆開
 *   - persons.name 含全形分號 56 筆 → 好幾個人被塞在同一列
 *   - HTML entity 被分隔符從中間切斷 15 筆 →「古倫神父(Anselm Gr&uuml」
 *
 * ════════ 做什麼、不做什麼 ════════
 *  做:   重讀原始署名(books.extra[來源] 的 *_raw,退而求其次用 books.author),
 *        以 pn_split_names() 重切,**補**缺的 persons 與 book_persons。
 *  不做:**只補不刪**(熊哥裁示)。舊的錯誤關聯原樣留著,
 *        由本工具列成「可能作廢」清單,跟 tools/check_person_names.php 產的
 *        清理 SQL 一起交人審 —— 刪除一律走那條路,不在這裡偷做。
 *
 * ★ 切法與 import.php **完全同一份**(tools/lib_person.php 的 pn_split_names)。
 *   兩邊各寫一份,這支就會補出一批跟匯入器不一致的 persons,而且沒有人會發現。
 *
 * ════════ 用法 ════════
 *   php tools/fix_person_names.php                     # dry-run 全庫掃描(預設,不寫)
 *   php tools/fix_person_names.php --fast              # 只掃署名含 ; 或 & 的書(快,但會漏)
 *   php tools/fix_person_names.php --source=logos
 *   php tools/fix_person_names.php --book=105019
 *   php tools/fix_person_names.php --limit=40          # 明細列幾筆(預設 30)
 *   php tools/fix_person_names.php --apply             # 真的寫入
 *
 * ★ 預設是**全庫掃描**而不是「撈含全形分號的書」—— 條件要描述「發生了什麼事」
 *   (重切結果和現況不一致),不是「資料長什麼樣」。用資料形狀當條件,
 *   本案已經靜默關掉過全站無 ISBN 書的模糊比對。`--fast` 只是趕時間時的捷徑,
 *   它會漏掉形狀以外的不一致,用了就要在交付說明裡講明。
 *
 * ★ 結尾一律**回查資料庫對帳**,不拿本工具自印的數字當證據
 *   (`rowCount()` 對 INSERT IGNORE 會回 0,本來就不能當證據)。
 *
 * 相關:Asana 1218277971470042、tools/lib_person.php、tools/check_person_names.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/lib_person.php';

const FPN_REV = '2026-10-03.1';      // ★ 版本戳記:FTP 沒蓋到時唯一能當場抓出來的辦法

/** 爬蟲欄位 → book_persons.role */
const FPN_RAW_FIELDS = [
    'authors_raw'      => 'author',
    'translators_raw'  => 'translator',
    'illustrators_raw' => 'illustrator',
    'editors_raw'      => 'editor',
];

$opt    = getopt('', ['apply', 'fast', 'source::', 'book::', 'limit::']);
$apply  = array_key_exists('apply', $opt);
$fast   = array_key_exists('fast', $opt);
$limit  = max(1, (int) ($opt['limit'] ?? 30));
$source = (string) ($opt['source'] ?? '');
$bookId = (int) ($opt['book'] ?? 0);

echo "fix_person_names rev " . FPN_REV . "  模式:" . ($apply ? '★ APPLY(會寫入)' : 'dry-run(不寫)')
   . ($fast ? '  掃描:--fast(只掃署名含 ; 或 & 的書,會漏)' : '  掃描:全庫') . "\n\n";

$pdo = db();
$pdo->exec('SET NAMES utf8mb4');

// ── 1. 取書 ──────────────────────────────────────────────────────────────
$where = ['1=1'];
$bind  = [];
if ($bookId)        { $where[] = 'b.book_id = :bid';  $bind[':bid'] = $bookId; }
if ($source !== '') { $where[] = 'b.source = :src';   $bind[':src'] = $source; }
if ($fast) {
    // ★ 形狀條件。全形分號用 COLLATE utf8mb4_bin 比,否則 unicode_ci 會把半形分號
    //   也視為相等(票上的查詢陷阱備忘)。
    $where[] = "(b.author COLLATE utf8mb4_bin LIKE CONCAT('%', CHAR(0xEFBC9B USING utf8mb4), '%')
                 OR b.author LIKE '%;%' OR b.author LIKE '%&%'
                 OR b.extra LIKE '%&%' OR b.extra COLLATE utf8mb4_bin LIKE CONCAT('%', CHAR(0xEFBC9B USING utf8mb4), '%'))";
}
$st = $pdo->prepare("SELECT b.book_id, b.source, b.title, b.author, b.extra
                       FROM books b WHERE " . implode(' AND ', $where) . "
                      ORDER BY b.book_id");
$st->execute($bind);

// ── 2. 現有關聯 ──────────────────────────────────────────────────────────
$have = [];     // book_id => [ "name\trole" => person_id ]
$q = $pdo->query("SELECT bp.book_id, bp.role, p.person_id, p.name
                    FROM book_persons bp JOIN persons p ON p.person_id = bp.person_id");
foreach ($q as $r) {
    $have[(int) $r['book_id']][$r['name'] . "\t" . $r['role']] = (int) $r['person_id'];
}
$personMap = [];
foreach ($pdo->query("SELECT person_id, name FROM persons") as $r) {
    $personMap[$r['name']] = (int) $r['person_id'];
}
echo "預載:persons " . count($personMap) . "、有關聯的書 " . count($have) . "\n\n";

// ── 3. 逐書重切比對 ──────────────────────────────────────────────────────
$nScanned = 0; $nChanged = 0; $nAddLink = 0; $nAddPerson = 0; $nStale = 0; $nHold = 0;
$shown = 0;
$staleRows = [];          // 可能作廢的關聯(**不刪**,只列)
$holdRows  = [];          // 規則不敢動的段落

$insP = $pdo->prepare("INSERT INTO persons (name) VALUES (:n)");
$insL = $pdo->prepare("INSERT IGNORE INTO book_persons (book_id, person_id, role, role_order, credit_text)
                       VALUES (:b, :p, :r, :o, :c)");

if ($apply) $pdo->beginTransaction();

while ($b = $st->fetch(PDO::FETCH_ASSOC)) {
    $nScanned++;
    $bid   = (int) $b['book_id'];
    $extra = $b['extra'] ? (json_decode((string) $b['extra'], true) ?: []) : [];

    // 原始署名:extra[來源] 的四個 *_raw 最完整(books.author 是 cap(255) 的平面後備)
    $want = [];     // "name\trole" => ['name'=>, 'role'=>, 'credit'=>]
    $sawRaw = false;
    foreach ($extra as $src => $rec) {
        if (!is_array($rec)) continue;
        foreach (FPN_RAW_FIELDS as $field => $role) {
            $raw = $rec[$field] ?? null;
            if (!is_string($raw) || trim($raw) === '') continue;
            $sawRaw = true;
            // 以琳多人名以「/」分隔 —— 與 import.php 的 map_record() 同一條特例,
            // 不同步就會補出跟匯入器不一樣的人名。
            if ($src === 'elim') $raw = str_replace('/', "\u{3001}", $raw);
            foreach (pn_split_names($raw) as $a) {
                $r = $a['role'] ?? $role;
                $want[$a['name'] . "\t" . $r] = ['name' => $a['name'], 'role' => $r, 'credit' => $a['credit']];
                if ($a['hold']) {
                    $nHold++;
                    if (count($holdRows) < 200) $holdRows[] = [$bid, $a['name'], implode(' / ', $a['hold'])];
                }
            }
        }
    }
    // extra 裡沒有任何 *_raw(舊資料)→ 退回平面欄,只當作者
    if (!$sawRaw && trim((string) $b['author']) !== '') {
        foreach (pn_split_names((string) $b['author']) as $a) {
            $r = $a['role'] ?? 'author';
            $want[$a['name'] . "\t" . $r] = ['name' => $a['name'], 'role' => $r, 'credit' => $a['credit']];
        }
    }
    if (!$want) continue;

    $cur     = $have[$bid] ?? [];
    $missing = array_diff_key($want, $cur);
    $stale   = array_diff_key($cur, $want);
    if (!$missing && !$stale) continue;

    $nChanged++;
    if ($shown < $limit) {
        $shown++;
        echo "#{$bid} [{$b['source']}] " . mb_strimwidth((string) $b['title'], 0, 40, '…', 'UTF-8') . "\n";
        foreach ($missing as $k => $v) echo "    + {$v['name']}  ({$v['role']})\n";
        foreach ($stale as $k => $pid) {
            [$n, $r] = explode("\t", $k);
            echo "    ? {$n}  ({$r},person {$pid}) ← 重切後不再出現,**本工具不刪**,交清理 SQL\n";
        }
    }
    foreach ($stale as $k => $pid) {
        $nStale++;
        [$n, $r] = explode("\t", $k);
        if (count($staleRows) < 500) $staleRows[] = [$bid, $pid, $n, $r];
    }

    $order = 0;
    foreach ($missing as $v) {
        if (!isset($personMap[$v['name']])) {
            $nAddPerson++;
            if ($apply) {
                $insP->execute([':n' => mb_substr($v['name'], 0, 150, 'UTF-8')]);
                $personMap[$v['name']] = (int) $pdo->lastInsertId();
            } else {
                $personMap[$v['name']] = -1;           // dry-run 佔位,避免重複計數
            }
        }
        $nAddLink++;
        if ($apply && $personMap[$v['name']] > 0) {
            $insL->execute([
                ':b' => $bid, ':p' => $personMap[$v['name']], ':r' => $v['role'],
                ':o' => $order++, ':c' => mb_substr($v['credit'], 0, 255, 'UTF-8'),
            ]);
        }
    }
}
if ($apply) $pdo->commit();

if ($shown >= $limit && $nChanged > $limit) echo "…(共 {$nChanged} 本有差異,--limit=N 看更多)\n";

echo "\n═══ 統計(★ 這是工具自印的,不是證據) ═══\n";
printf("  掃描          %6d 本\n", $nScanned);
printf("  有差異        %6d 本\n", $nChanged);
printf("  要新增 person %6d 人\n", $nAddPerson);
printf("  要新增關聯    %6d 筆\n", $nAddLink);
printf("  可能作廢的關聯%6d 筆(**本工具不刪**)\n", $nStale);
printf("  規則 hold     %6d 段(要人工看,見 check_person_names.php)\n", $nHold);

// ★ 陷阱 27:應該有值卻是 0,當場追,不要記進待辦。
if (!$apply && $nChanged === 0) {
    echo "\n★★ dry-run 掃出 0 本有差異。\n"
       . "   票上 2026-09-08 實測 books.author 含全形分號就有 47 本。\n"
       . "   清理尚未執行就回 0,幾乎一定是這支工具沒讀到該讀的欄位(extra 的 *_raw)\n"
       . "   或規則退化了,**當場追,不要當成沒事**。\n";
}
if ($nAddLink > 0 && $nAddPerson === 0) {
    echo "\n★ 要補 {$nAddLink} 筆關聯、卻一個新 person 都不用建 —— 有可能是對的\n"
       . "  (那些人早就存在,只是沒掛到這本書),但也可能是 personMap 比對方式出錯。抽幾筆查。\n";
}

if ($holdRows) {
    echo "\n── 規則 hold 的段落(前 " . min(20, count($holdRows)) . " 筆)──\n";
    foreach (array_slice($holdRows, 0, 20) as [$bid, $n, $why]) {
        echo "   #{$bid}  " . mb_strimwidth($n, 0, 40, '…', 'UTF-8') . "  ← {$why}\n";
    }
}
if ($staleRows) {
    echo "\n── 可能作廢的關聯(前 " . min(20, count($staleRows)) . " 筆;**沒有被刪**)──\n";
    foreach (array_slice($staleRows, 0, 20) as [$bid, $pid, $n, $r]) {
        echo "   #{$bid}  person {$pid}  " . mb_strimwidth($n, 0, 40, '…', 'UTF-8') . "  ({$r})\n";
    }
    echo "   ★ 這些要不要刪,由 tools/check_person_names.php 產的清理 SQL 決定,人審過才跑。\n";
}

// ── 4. 回查對帳 ──────────────────────────────────────────────────────────
echo "\n═══ 回查資料庫(這個才算證據) ═══\n";
$chk = $pdo->query("SELECT (SELECT COUNT(*) FROM persons) AS persons_total,
                           (SELECT COUNT(*) FROM book_persons) AS links_total")->fetch(PDO::FETCH_ASSOC);
printf("  persons 現有 %d 列、book_persons 現有 %d 筆\n", (int) $chk['persons_total'], (int) $chk['links_total']);
if ($apply) {
    echo "  ★ 把 apply 前後這兩個數字都記下來,差額要等於上面的「要新增」兩行;\n"
       . "    對不上就是有東西被 INSERT IGNORE 靜默吃掉了,當場追。\n";
} else {
    echo "  (dry-run,資料未動。預期 apply 後:persons +{$nAddPerson}、book_persons +{$nAddLink})\n";
}
exit(0);
