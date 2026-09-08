<?php
declare(strict_types=1);

/**
 * 重複書合併工具(2026-08-02)
 *
 * 背景:import.php 原規則「紀錄帶 ISBN 時不做模糊比對」,造成「A 站有 ISBN、
 * B 站同書無 ISBN」被拆成兩筆(診斷:同書名+作者分多筆共 525 組)。
 * 本工具把「正規化書名+第一作者」相同的多筆書合併為一筆(Work/Edition 模型:
 * 同作品的各站紀錄本就該是同 book 下的多個 edition)。
 *
 * 安全規則(存疑不合併):
 *   - 群組內非空 isbn13 有兩種以上 → 不自動合併,僅列入報表(可能是不同版本
 *     同名書,也可能是修訂版;交內容面判斷)。
 *   - 只處理 is_published=1 的書(下架書不參與,避免非書資料復活)。
 *   - 主檔挑選:有 isbn13 者優先 → 版本數多者 → book_id 小者(短網址較舊較穩)。
 *
 * 合併動作(單一交易,冪等可重跑):
 *   editions/reviews/book_clicks/links/media 改掛主檔;book_persons/
 *   book_subjects/book_series 以 UPDATE IGNORE 搬移(撞唯一鍵者刪除);
 *   books 平面欄位 COALESCE 補空;buy_links 併聯(URL 去重);extra 併鍵
 *   (同來源鍵以主檔為準,重複來源的副檔原始紀錄存為「{source}_dup{id}」,
 *   不丟任何資料);最後刪副檔書列。
 *
 * 用法(主機 CLI):
 *   php tools/merge_duplicate_books.php --dry-run          # 只列清單與統計
 *   php tools/merge_duplicate_books.php --dry-run --full   # dry-run 並列出每一組
 *   php tools/merge_duplicate_books.php                    # 寫入
 *   php tools/merge_duplicate_books.php --limit=50         # 只處理前 50 組
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/api/lib/db.php';

$opt   = getopt('', ['dry-run', 'full', 'limit::']);
$dry   = array_key_exists('dry-run', $opt);
$full  = array_key_exists('full', $opt);
$limit = (int) ($opt['limit'] ?? 0);

/** 正規化:去空白+標點符號、轉小寫(2026-08-03 修:「某確類：…」與「某確類--…」
 *  等標點變體視為同名;誤合風險由「作者相同+多 ISBN 不合併」規則守住) */
function norm(?string $s): string
{
    return mb_strtolower(preg_replace('/[\s\p{P}\p{S}]+/u', '', (string) $s), 'UTF-8');
}

/** 第一作者(與 import.php split_names 分隔符一致,另含 elim 的「/」)。
 *  2026-09-08 修:原字元集 hexdump 是 5b 3b 3b e3 80 81 —— 兩個半形分號、
 *  全形分號 U+FF1B 遺失(與 import.php:197 同一個退化),以全形分號分隔的
 *  作者串因此取到整串當第一作者、合併鍵算錯。改以 \u{} escape 書寫免疫退化。
 *  註:本函式不做括號感知(import.php split_delims 才有)—— 這裡只取第一段,
 *  且 norm() 會把標點全部去掉,括號差異不影響鍵值。 */
function first_author(?string $raw): string
{
    $p = preg_split('/[;' . "\u{FF1B}\u{3001}" . '\/]/u', (string) $raw);
    return norm(preg_replace('/\s*等$/u', '', trim($p[0] ?? '')));
}

$pdo = db();
$pdo->exec('SET NAMES utf8mb4');

// ── 分組 ───────────────────────────────────────────────────
$groups = [];
$sqlBooks = "SELECT b.book_id, b.title, b.author, b.isbn13, b.source,
                    (SELECT COUNT(*) FROM editions e WHERE e.book_id = b.book_id) AS ed_cnt
               FROM books b WHERE b.is_published = 1";
foreach ($pdo->query($sqlBooks) as $r) {
    $t = norm($r['title']);
    $a = first_author($r['author']);
    if ($t === '' || $a === '') continue;   // 無作者的(聖經等)不自動合併
    $groups["$t|$a"][] = $r;
}
$groups = array_filter($groups, fn($g) => count($g) > 1);
ksort($groups);

$stats = ['groups' => 0, 'merged_books' => 0, 'skipped_isbn' => 0];
$plans = [];   // [primary, [dups...], note]
foreach ($groups as $key => $g) {
    $isbns = array_values(array_unique(array_filter(array_column($g, 'isbn13'))));
    if (count($isbns) > 1) {
        $stats['skipped_isbn']++;
        if ($full) {
            $ids = implode(',', array_column($g, 'book_id'));
            echo "[跳過:多 ISBN] {$g[0]['title']}(book_id: $ids;ISBN: " . implode('/', $isbns) . ")\n";
        }
        continue;
    }
    // 主檔:有 isbn → 版本多 → id 小
    usort($g, function ($x, $y) {
        return [(int) empty($x['isbn13']), -(int) $x['ed_cnt'], (int) $x['book_id']]
           <=> [(int) empty($y['isbn13']), -(int) $y['ed_cnt'], (int) $y['book_id']];
    });
    $primary = array_shift($g);
    $plans[] = [$primary, $g];
    $stats['groups']++;
    $stats['merged_books'] += count($g);
    if ($limit && $stats['groups'] >= $limit) break;
}

echo "候選群組:" . ($stats['groups'] + $stats['skipped_isbn']) . "(可自動合併 {$stats['groups']} 組、"
   . "多 ISBN 待人工 {$stats['skipped_isbn']} 組);將移除重複書 {$stats['merged_books']} 筆\n";

if ($dry) {
    foreach ($plans as [$p, $dups]) {
        $srcs = implode(',', array_unique(array_merge([$p['source']], array_column($dups, 'source'))));
        $line = "  ○ {$p['title']} ← 主 {$p['book_id']}({$p['source']}"
              . ($p['isbn13'] ? ',isbn' : '') . "),併入 "
              . implode(',', array_column($dups, 'book_id')) . " [$srcs]";
        if ($full || $stats['groups'] <= 60) echo $line . "\n";
    }
    echo "[dry-run 未寫入] 確認清單合理後拿掉 --dry-run 執行;完整逐組清單加 --full。\n";
    exit;
}

// ── 執行合併 ───────────────────────────────────────────────
$stMoveEd    = $pdo->prepare('UPDATE editions    SET book_id = :p WHERE book_id = :d');
$stMoveRev   = $pdo->prepare('UPDATE reviews     SET book_id = :p WHERE book_id = :d');
$stMoveClk   = $pdo->prepare('UPDATE book_clicks SET book_id = :p WHERE book_id = :d');
$stMoveLink  = $pdo->prepare('UPDATE links       SET book_id = :p WHERE book_id = :d');
$stMoveMedia = $pdo->prepare('UPDATE media       SET book_id = :p WHERE book_id = :d');
$stMovePer   = $pdo->prepare('UPDATE IGNORE book_persons  SET book_id = :p WHERE book_id = :d');
$stMoveSub   = $pdo->prepare('UPDATE IGNORE book_subjects SET book_id = :p WHERE book_id = :d');
$stMoveSer   = $pdo->prepare('UPDATE IGNORE book_series   SET book_id = :p WHERE book_id = :d');
$stGetBook   = $pdo->prepare('SELECT * FROM books WHERE book_id = :id');
$stDelBook   = $pdo->prepare('DELETE FROM books WHERE book_id = :id');
$stFill      = $pdo->prepare(
    'UPDATE books SET
       original_title = COALESCE(original_title, :ot), author = COALESCE(author, :au),
       publisher = COALESCE(publisher, :pub), publish_date = COALESCE(publish_date, :pd),
       isbn13 = COALESCE(isbn13, :i13), isbn10 = COALESCE(isbn10, :i10),
       page_count = COALESCE(page_count, :pc), binding = COALESCE(binding, :bd),
       language = COALESCE(language, :lg), series = COALESCE(series, :se),
       summary = COALESCE(summary, :su), keywords = COALESCE(keywords, :kw),
       cover_url = COALESCE(cover_url, :cv), category_id = COALESCE(category_id, :cid),
       buy_links = :bl, extra = :ex
     WHERE book_id = :id');

$done = 0;
$pdo->beginTransaction();
foreach ($plans as [$p, $dups]) {
    $stGetBook->execute([':id' => (int) $p['book_id']]);
    $prim = $stGetBook->fetch();
    if (!$prim) continue;
    $bl = $prim['buy_links'] ? (json_decode($prim['buy_links'], true) ?: []) : [];
    $ex = $prim['extra'] ? (json_decode($prim['extra'], true) ?: []) : [];
    $fill = array_fill_keys(['original_title','author','publisher','publish_date','isbn13','isbn10',
                             'page_count','binding','language','series','summary','keywords',
                             'cover_url','category_id'], null);

    foreach ($dups as $dRow) {
        $d = (int) $dRow['book_id'];
        $stGetBook->execute([':id' => $d]);
        $dup = $stGetBook->fetch();
        if (!$dup) continue;

        foreach ([$stMoveEd, $stMoveRev, $stMoveClk, $stMoveLink, $stMoveMedia,
                  $stMovePer, $stMoveSub, $stMoveSer] as $st) {
            $st->execute([':p' => (int) $p['book_id'], ':d' => $d]);
        }
        foreach ($fill as $k => $v) {
            if ($v === null && $dup[$k] !== null && $dup[$k] !== '') $fill[$k] = $dup[$k];
        }
        foreach (($dup['buy_links'] ? (json_decode($dup['buy_links'], true) ?: []) : []) as $l) {
            $bl[] = $l;
        }
        foreach (($dup['extra'] ? (json_decode($dup['extra'], true) ?: []) : []) as $srcKey => $recRaw) {
            if (!isset($ex[$srcKey])) $ex[$srcKey] = $recRaw;
            else $ex[$srcKey . '_dup' . $d] = $recRaw;   // 不丟資料
        }
        $stDelBook->execute([':id' => $d]);   // 殘餘撞鍵列由 FK CASCADE 清掉
    }

    // buy_links URL 去重(保序)
    $seen = []; $bl2 = [];
    foreach ($bl as $l) {
        $u = is_array($l) ? ($l['url'] ?? '') : '';
        if ($u === '' || !isset($seen[$u])) { $bl2[] = $l; if ($u !== '') $seen[$u] = 1; }
    }

    $stFill->execute([
        ':ot' => $fill['original_title'], ':au' => $fill['author'], ':pub' => $fill['publisher'],
        ':pd' => $fill['publish_date'], ':i13' => $fill['isbn13'], ':i10' => $fill['isbn10'],
        ':pc' => $fill['page_count'], ':bd' => $fill['binding'], ':lg' => $fill['language'],
        ':se' => $fill['series'], ':su' => $fill['summary'], ':kw' => $fill['keywords'],
        ':cv' => $fill['cover_url'], ':cid' => $fill['category_id'],
        ':bl' => json_encode($bl2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ex' => json_encode($ex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':id' => (int) $p['book_id'],
    ]);
    if (++$done % 100 === 0) { $pdo->commit(); $pdo->beginTransaction(); echo "  已合併 $done 組…\n"; }
}
if ($pdo->inTransaction()) $pdo->commit();

echo "完成:合併 {$done} 組、移除重複書 {$stats['merged_books']} 筆;多 ISBN 待人工 {$stats['skipped_isbn']} 組\n";
echo "驗證(Navicat):\n"
   . "  SELECT COUNT(*) FROM (SELECT e.book_id FROM editions e GROUP BY e.book_id\n"
   . "    HAVING COUNT(DISTINCT e.source)=3) t;  -- 三站交集應增加\n";
