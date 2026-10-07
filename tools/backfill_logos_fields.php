<?php
declare(strict_types=1);

/**
 * 基道欄位回填 —— 把離線重解析補回來的 ISBN／出版日期／頁數／尺寸／重量寫進資料庫。
 *
 * 背景(2026-08-29,Asana 1217967657916253):
 *   基道商品頁的產品資訊由 JavaScript document.write 產生,BeautifulSoup 抓不到,
 *   所以全站 22,855 筆 publish_date 為 0%、ISBN 只有「商品碼剛好是 ISBN」的 62%。
 *   解析已修(logos_crawler.parse_info_script),商品頁快取都在,由
 *   crawler/reparse_logos.py 離線重解析產出 data/logos_reparsed.jsonl。
 *
 * 為什麼不用 import.php:
 *   import.php 會跳過 source_url 已存在於 editions 的紀錄(可重跑的設計),
 *   拿它來補既有書的欄位是無效的(會全部落在「已存在跳過」)。故另寫本工具。
 *
 * 原則:
 *   - **只補空欄位,絕不覆蓋既有值**(全部走 COALESCE)
 *   - 冪等可重跑
 *   - **不做合併**。補回 ISBN 後可能發現「這本其實和站上另一本是同一本書」,
 *     本工具只把撞號清單列出來供複核,實際合併交給 tools/merge_duplicate_books.php,
 *     那是破壞性操作,必須人工看過。
 *
 * 寫入範圍:
 *   editions  publish_date / page_count / binding / dimensions / weight_g
 *   books     publish_date / page_count / binding / isbn13 / isbn10
 *   identifiers  ISBN13 / ISBN10(INSERT IGNORE,掛在該 edition 下)
 *   books.extra  該來源鍵下加 spec_all(站方標籤原值,不丟資料;--skip-extra 可略過)
 *
 * 用法(主機 CLI):
 *   php tools/backfill_logos_fields.php --dry-run
 *   php tools/backfill_logos_fields.php
 *   php tools/backfill_logos_fields.php --file=/path/to.jsonl --limit=500 --dry-run
 *   php tools/backfill_logos_fields.php --skip-extra
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/lib/db.php';
require __DIR__ . '/lib_isbn.php';

$opt       = getopt('', ['dry-run', 'file::', 'limit::', 'source::', 'skip-extra']);
$dry       = array_key_exists('dry-run', $opt);
$skipExtra = array_key_exists('skip-extra', $opt);
$limit     = (int) ($opt['limit'] ?? 0);
$source    = (string) ($opt['source'] ?? 'logos');
$file      = (string) ($opt['file'] ?? dirname(__DIR__) . '/crawler/data/logos_reparsed.jsonl');

if (!is_file($file)) {
    exit("找不到 $file\n(請先於主機跑 python3 crawler/reparse_logos.py)\n");
}

/**
 * 爬蟲已把日期正規化為 YYYY-MM-DD / YYYY-MM / YYYY(見 logos_crawler.norm_date),
 * 這裡只認這三種,認不出來就跳過並計數——寧可少補,不要寫進髒值。
 * (注意:import.php 的 parse_date() 對「2026-7-15」這種未補零格式會算出月份 71,
 *  所以正規化一定要在來源端做完,不能倚賴下游。)
 */
function split_date(?string $s): array
{
    $s = trim((string) $s);
    if ($s === '') return [null, null];
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        if ((int) $m[2] >= 1 && (int) $m[2] <= 12 && (int) $m[3] >= 1 && (int) $m[3] <= 31) {
            return ["$m[1]-$m[2]", $s];
        }
        return [null, null];
    }
    if (preg_match('/^(\d{4})-(\d{2})$/', $s, $m)) {
        return ((int) $m[2] >= 1 && (int) $m[2] <= 12) ? [$s, $s] : [null, null];
    }
    if (preg_match('/^(\d{4})$/', $s)) return [$s, $s];
    return [null, null];
}

/** 「390」「390克」→ 390;抓不到數字回 null */
function weight_g(?string $s): ?int
{
    if (!$s) return null;
    return preg_match('/\d+/', $s, $m) ? (int) $m[0] : null;
}

$pdo = db();

// ── 預載:source_url → edition/book ─────────────────────────
$byUrl = [];
$st = $pdo->prepare(
    'SELECT edition_id, book_id, source_url FROM editions
      WHERE source = :s AND source_url IS NOT NULL');
$st->execute([':s' => $source]);
foreach ($st->fetchAll() as $r) {
    $byUrl[$r['source_url']] = ['e' => (int) $r['edition_id'], 'b' => (int) $r['book_id']];
}
echo '預載 ' . count($byUrl) . " 筆 $source 版本(依 source_url)\n";

// ── 預載:isbn13 → 已有哪些 book(補號後偵測撞書用) ─────────
$isbnOwner = [];
foreach ($pdo->query("SELECT book_id, isbn13 FROM books
                       WHERE isbn13 IS NOT NULL AND isbn13 <> ''")->fetchAll() as $r) {
    $isbnOwner[$r['isbn13']][] = (int) $r['book_id'];
}
echo '預載 ' . count($isbnOwner) . " 個已用 ISBN13\n";

// ── 準備語句 ───────────────────────────────────────────────
$updEdition = $pdo->prepare(
    'UPDATE editions SET
        publish_date = COALESCE(publish_date, :pd),
        page_count   = COALESCE(page_count, :pc),
        binding      = COALESCE(binding, :bd),
        dimensions   = COALESCE(dimensions, :dim),
        weight_g     = COALESCE(weight_g, :wt)
      WHERE edition_id = :e');

$updBook = $pdo->prepare(
    'UPDATE books SET
        publish_date = COALESCE(publish_date, :pd),
        page_count   = COALESCE(page_count, :pc),
        binding      = COALESCE(binding, :bd),
        isbn13       = COALESCE(NULLIF(isbn13, ""), :i13),
        isbn10       = COALESCE(NULLIF(isbn10, ""), :i10)
      WHERE book_id = :b');

$insIdent = $pdo->prepare(
    'INSERT IGNORE INTO identifiers (edition_id, id_type, id_value) VALUES (:e, :t, :v)');

$getExtra = $pdo->prepare('SELECT extra FROM books WHERE book_id = :b');
$setExtra = $pdo->prepare('UPDATE books SET extra = :ex WHERE book_id = :b');

// ── 主迴圈 ─────────────────────────────────────────────────
$stats = ['read' => 0, 'no_edition' => 0, 'updated' => 0, 'bad_date' => 0,
          'ident_try' => 0, 'extra' => 0, 'extra_same' => 0];
$fieldSeen = ['publish_date' => 0, 'page_count' => 0, 'binding' => 0,
              'dimensions' => 0, 'weight' => 0, 'isbn' => 0];
$collide = [];   // 補號後與別本書撞 ISBN13 的清單(供複核,不自動合併;只留前 200 筆樣本)
$collideN = 0;   // 撞號總數——只印樣本不給總數的話,看不出規模
$missing = [];   // jsonl 有、資料庫沒有的 source_url(對帳工單的線索)

if (!$dry) $pdo->beginTransaction();

$fh = fopen($file, 'r');
if (!$fh) exit("開不了 $file\n");
$done = 0;
while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $r = json_decode($line, true);
    if (!is_array($r)) continue;
    $stats['read']++;
    if ($limit && $stats['read'] > $limit) break;

    $url = $r['source_url'] ?? null;
    if (!$url || !isset($byUrl[$url])) {
        $stats['no_edition']++;
        if (count($missing) < 50) $missing[] = ($r['code'] ?? '?') . '  ' . ($r['title'] ?? '');
        continue;
    }
    $eid = $byUrl[$url]['e'];
    $bid = $byUrl[$url]['b'];

    [$bDate, $eDate] = split_date($r['publish_date'] ?? null);
    if (!empty($r['publish_date']) && $eDate === null) $stats['bad_date']++;

    $pc  = isset($r['page_count']) && $r['page_count'] !== '' ? (int) $r['page_count'] : null;
    $bd  = isset($r['binding']) && $r['binding'] !== '' ? mb_substr((string) $r['binding'], 0, 50) : null;
    $dim = isset($r['dimensions']) && $r['dimensions'] !== '' ? mb_substr((string) $r['dimensions'], 0, 50) : null;
    $wt  = weight_g($r['weight'] ?? null);
    [$i13, $i10] = isbn_pair($r['isbn'] ?? null);

    foreach (['publish_date' => $eDate, 'page_count' => $pc, 'binding' => $bd,
              'dimensions' => $dim, 'weight' => $wt, 'isbn' => $i13] as $k => $v) {
        if ($v !== null) $fieldSeen[$k]++;
    }

    // 補號後撞書偵測:這個 ISBN13 已經屬於另一本書 → 列出來複核,不自動合併
    if ($i13 && isset($isbnOwner[$i13])) {
        foreach ($isbnOwner[$i13] as $other) {
            if ($other !== $bid) {
                $collideN++;
                if (count($collide) < 200) {
                    $collide[] = "$i13  book $bid ({$r['title']})  ↔  book $other";
                }
                break;
            }
        }
    }

    if ($dry) { $done++; continue; }

    $updEdition->execute([':pd' => $eDate, ':pc' => $pc, ':bd' => $bd,
                          ':dim' => $dim, ':wt' => $wt, ':e' => $eid]);
    $updBook->execute([':pd' => $bDate, ':pc' => $pc, ':bd' => $bd,
                       ':i13' => $i13, ':i10' => $i10, ':b' => $bid]);
    $stats['updated']++;

    // 注意:不要拿 rowCount() 當 INSERT IGNORE 的新增計數——本專案的 PDO 設定下它一律回 0
    // (2026-08-29 實測:報告說「新增 0 列」,實際資料庫已有 21,179 列)。
    // 這裡只計嘗試次數,真正的總數在結尾直接查資料庫。
    if ($i13) { $insIdent->execute([':e' => $eid, ':t' => 'ISBN13', ':v' => $i13]); $stats['ident_try']++; }
    if ($i10) { $insIdent->execute([':e' => $eid, ':t' => 'ISBN10', ':v' => $i10]); $stats['ident_try']++; }

    if (!$skipExtra && !empty($r['spec_all'])) {
        $getExtra->execute([':b' => $bid]);
        $cur = $getExtra->fetchColumn();
        $extra = $cur ? (json_decode((string) $cur, true) ?: []) : [];
        if (!isset($extra[$source]) || !is_array($extra[$source])) $extra[$source] = [];
        if (($extra[$source]['spec_all'] ?? null) === $r['spec_all']) {
            $stats['extra_same']++;      // 已相同 → 略過(重跑時正常;首次跑若大量出現就是有問題)
        } else {
            $extra[$source]['spec_all'] = $r['spec_all'];
            $setExtra->execute([
                ':ex' => json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':b'  => $bid,
            ]);
            $stats['extra']++;
        }
    }

    if (++$done % 500 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        echo "  已處理 {$done} 筆…\n";   // 變數名一定要用 {} 包住:PHP 的識別字允許 0x80-0xFF,
                                        // 「$done…」會被當成名為 done… 的變數(全形字元陷阱)
    }
}
fclose($fh);
if (!$dry) $pdo->commit();

// ── 報告 ───────────────────────────────────────────────────
echo "\n讀 {$stats['read']} 筆\n";
echo "  資料庫查無對應版本(source_url 不在 editions):{$stats['no_edition']}\n";
echo "  " . ($dry ? '可補' : '已更新') . ":" . ($dry ? ($stats['read'] - $stats['no_edition']) : $stats['updated']) . " 筆\n";
echo "  日期格式認不得而略過:{$stats['bad_date']}\n";
if (!$dry) {
    echo "  identifiers 寫入嘗試:{$stats['ident_try']} 次(INSERT IGNORE)\n";
    $q = $pdo->prepare("SELECT COUNT(*) FROM identifiers i JOIN editions e ON e.edition_id = i.edition_id
                         WHERE e.source = :s AND i.id_type IN ('ISBN13','ISBN10')");
    $q->execute([':s' => $source]);
    echo "  → 資料庫實際 identifiers 列數($source):" . $q->fetchColumn() . "\n";
    echo "  extra 補 spec_all:{$stats['extra']} 本(已相同而略過 {$stats['extra_same']} 本)\n";
}
echo "\n== 各欄位在來源檔中有值的筆數(實際是否寫入視原欄位是否為空)==\n";
foreach ($fieldSeen as $k => $v) echo "  " . str_pad($k, 14) . " $v\n";

if ($missing) {
    echo "\n== jsonl 有、資料庫查無的紀錄(前 " . count($missing) . " 筆;屬對帳工單 1217967706140716)==\n";
    foreach ($missing as $s) echo "  - $s\n";
}
if ($collideN) {
    echo "\n== 補回 ISBN 後與別本書撞號:共 {$collideN} 筆(列出前 " . count($collide) . " 筆)==\n";
    echo "   撞號代表「這本很可能與站上另一本是同一本書」,補回 ISBN 才浮現。\n";
    echo "   本工具**不做合併**——那是破壞性操作,複核後以 tools/merge_duplicate_books.php 處理。\n";
    foreach ($collide as $s) echo "  - $s\n";
    if ($collideN > count($collide)) {
        echo "   …其餘 " . ($collideN - count($collide)) . " 筆未列出;要完整清單可用下列 SQL:\n";
        echo "   SELECT isbn13, COUNT(*) n, GROUP_CONCAT(book_id) ids FROM books\n";
        echo "    WHERE isbn13 IS NOT NULL AND isbn13 <> '' GROUP BY isbn13 HAVING n > 1;\n";
    }
}
echo "\n" . ($dry ? "dry-run:未寫入任何資料\n" : "完成寫入\n");
echo "驗證:SELECT COUNT(*) FROM books b JOIN editions e ON e.book_id=b.book_id\n"
   . "       WHERE e.source='$source' AND b.publish_date IS NULL; -- 應大幅下降\n";
