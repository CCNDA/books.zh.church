<?php
declare(strict_types=1);

/**
 * books.search_text 反正規化搜尋欄建置工具(M1-B)
 *
 * 為什麼需要:
 *   api/index.php 的 get_books() 原本對 v_book_list 的 v.author / v.publisher /
 *   v.translator 做 LIKE,而那三欄在 view 裡是「相關子查詢 + GROUP_CONCAT」
 *   → 每一列都要執行子查詢,65,290 列 × 6 個。改查 books.search_text 單欄後,
 *   仍是全表掃描但成本差一個數量級。
 *   ★ 不用 FULLTEXT:MariaDB 無 ngram 分詞,對中文無效(8/17 已定案)。
 *
 * 用法(主機 SSH,在 /home/ubuntu/books 下執行):
 *   php tools/build_search_text.php --dry-run            # 看樣本與長度,不寫入
 *   php tools/build_search_text.php --all                # 全量重建(首次執行用這個)
 *   php tools/build_search_text.php                      # 預設 --missing,只補 NULL(cron 用)
 *   php tools/build_search_text.php --book=60911         # 單書重建(除錯用)
 *   php tools/build_search_text.php --all --batch=500    # 記憶體吃緊時縮小批次
 *   php tools/build_search_text.php --all --summary-chars=2000   # 截斷摘要以省空間
 *
 * ★ 執行順序鐵律:
 *   migration 2026-09-16_m1b_search_text_and_indexes.sql(建欄位)
 *   → 本工具 --all
 *   → 回查 SELECT COUNT(*) FROM books WHERE search_text IS NULL  應趨近 0
 *   → **最後**才改 api/index.php 讓 q 查 search_text
 *   順序跳了 = 搜尋查一個空欄位 = 全站搜不到任何書。
 *
 * ★ 併入每日排程:crawler/daily_new.sh 結尾加一行
 *   php tools/build_search_text.php --missing
 *   否則每日新書的 search_text 會是 NULL,搜不到。
 */

const TOOL_REV = '2026-09-17.1';   // 版本戳記:FTP 沒蓋到時靠這行看出來

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/api/lib/db.php';

$opt          = getopt('', ['all', 'missing', 'book::', 'batch::', 'limit::',
                            'summary-chars::', 'dry-run']);
$modeAll      = array_key_exists('all', $opt);
$bookId       = (int) ($opt['book'] ?? 0);
$batch        = max(100, min(5000, (int) ($opt['batch'] ?? 2000)));
$limit        = (int) ($opt['limit'] ?? 0);        // 0 = 不限
$summaryChars = (int) ($opt['summary-chars'] ?? 0); // 0 = 摘要全文
$dry          = array_key_exists('dry-run', $opt);

$pdo = db();

// ★ GROUP_CONCAT 預設長度可能不足,人名/ISBN 聚合會被靜默截斷 → 先放寬
$pdo->exec('SET SESSION group_concat_max_len = 1000000');

// ── search_text 的組成 ────────────────────────────────────
// 原則:寧可多收。平面欄與正規化關聯表**兩套都放** ——
//   view 是 COALESCE(正規化, 平面),但搜尋欄沒有「哪個才對」的問題,
//   任一邊有值都該搜得到。
//   identifiers 全收(含 id_type='STORE' 商品代碼)→ 使用者可用商品碼搜到書。
//   keywords 納入 = 第一階段要求的「Tag 搜尋」(現行 api 漏了這項)。
$summaryExpr = $summaryChars > 0
    ? "LEFT(b.summary, {$summaryChars})"
    : 'b.summary';

$searchExpr = "CONCAT_WS(' ',
        b.title, b.subtitle, b.original_title,
        b.author, b.translator, b.publisher, b.series, b.keywords,
        {$summaryExpr},
        b.isbn13, b.isbn10,
        (SELECT GROUP_CONCAT(DISTINCT CONCAT_WS(' ', p.name, p.name_en) SEPARATOR ' ')
           FROM book_persons bp
           JOIN persons p ON p.person_id = bp.person_id
          WHERE bp.book_id = b.book_id),
        (SELECT GROUP_CONCAT(DISTINCT CONCAT_WS(' ', cano.name_zh, cano.name_en) SEPARATOR ' ')
           FROM editions e
           JOIN publishers pub  ON pub.publisher_id = e.publisher_id
           JOIN publishers cano ON cano.publisher_id = COALESCE(pub.canonical_id, pub.publisher_id)
          WHERE e.book_id = b.book_id),
        (SELECT GROUP_CONCAT(DISTINCT i.id_value SEPARATOR ' ')
           FROM identifiers i
           JOIN editions e2 ON e2.edition_id = i.edition_id
          WHERE e2.book_id = b.book_id)
    )";

// ── 單書模式 ──────────────────────────────────────────────
if ($bookId > 0) {
    if ($dry) {
        $st = $pdo->prepare("SELECT b.book_id, b.title, {$searchExpr} AS st
                               FROM books b WHERE b.book_id = :id1");
        $st->execute([':id1' => $bookId]);
        $r = $st->fetch();
        if (!$r) { exit("book_id {$bookId} 不存在\n"); }
        printf("[dry-run] book %d《%s》 長度 %d\n%s\n",
               $bookId, $r['title'], mb_strlen((string) $r['st']),
               mb_substr((string) $r['st'], 0, 400) . ' …');
        exit(0);
    }
    $st = $pdo->prepare("UPDATE books b SET b.search_text = {$searchExpr}
                          WHERE b.book_id = :id1");
    $st->execute([':id1' => $bookId]);
    // ★ 不信 rowCount(值相同時會回 0),回查實際狀態
    $chk = $pdo->prepare('SELECT CHAR_LENGTH(search_text) AS len FROM books WHERE book_id = :id2');
    $chk->execute([':id2' => $bookId]);
    printf("book %d 完成,search_text 長度 %s\n", $bookId, (string) $chk->fetchColumn());
    exit(0);
}

// ── 批次模式 ──────────────────────────────────────────────
$cond = $modeAll ? '1=1' : '(b.search_text IS NULL OR b.search_text = \'\')';

$total = (int) $pdo->query("SELECT COUNT(*) FROM books b WHERE {$cond}")->fetchColumn();
printf("[rev %s] 模式 %s;待處理 %s 筆;批次 %d%s\n",
       TOOL_REV, $modeAll ? '--all(全量重建)' : '--missing(只補空值)',
       number_format($total), $batch, $dry ? ';DRY-RUN 不寫入' : '');
if ($total === 0) { exit("沒有需要處理的資料。\n"); }

if ($dry) {
    $st = $pdo->query("SELECT b.book_id, b.title, {$searchExpr} AS st
                         FROM books b WHERE {$cond} ORDER BY b.book_id LIMIT 3");
    foreach ($st->fetchAll() as $r) {
        printf("  book %-7d 長度 %-7d 《%s》\n",
               (int) $r['book_id'], mb_strlen((string) $r['st']), (string) $r['title']);
        printf("    %s …\n", mb_substr(preg_replace('/\s+/u', ' ', (string) $r['st']) ?? '', 0, 200));
    }
    exit("\n[dry-run] 未寫入任何資料。確認樣本無誤後去掉 --dry-run 重跑。\n");
}

$selSql = "SELECT MAX(book_id) AS max_id, COUNT(*) AS n FROM (
             SELECT b.book_id FROM books b
              WHERE {$cond} AND b.book_id > :last1
              ORDER BY b.book_id LIMIT {$batch}) t";
$sel = $pdo->prepare($selSql);

// 注意:EMULATE_PREPARES=false → 同名參數不可重複使用,故 :last2 / :max1 分開編號
$upd = $pdo->prepare("UPDATE books b SET b.search_text = {$searchExpr}
                       WHERE {$cond} AND b.book_id > :last2 AND b.book_id <= :max1");

$last = 0; $done = 0; $t0 = microtime(true);
while (true) {
    $sel->execute([':last1' => $last]);
    $row = $sel->fetch();
    $n   = (int) ($row['n'] ?? 0);
    if ($n === 0) { break; }
    $maxId = (int) $row['max_id'];

    $upd->execute([':last2' => $last, ':max1' => $maxId]);
    $done += $n;
    $last  = $maxId;

    printf("  %s / %s(至 book_id %d)耗時 %.1fs\n",
           number_format($done), number_format($total), $last, microtime(true) - $t0);

    if ($limit > 0 && $done >= $limit) { echo "  已達 --limit,停止。\n"; break; }
}

// ── ★ 結尾一律回查資料庫對帳,不拿工具自印的數字當證據 ──
$nullLeft = (int) $pdo->query('SELECT COUNT(*) FROM books
                                WHERE search_text IS NULL OR search_text = \'\'')->fetchColumn();
$allBooks = (int) $pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();
$avgLen   = (int) $pdo->query('SELECT COALESCE(AVG(CHAR_LENGTH(search_text)),0) FROM books
                                WHERE search_text IS NOT NULL')->fetchColumn();
$maxLen   = (int) $pdo->query('SELECT COALESCE(MAX(CHAR_LENGTH(search_text)),0) FROM books')->fetchColumn();

printf("\n[資料庫實查] 全站 %s 本;search_text 仍為空 %s 本;平均長度 %s 字;最長 %s 字\n",
       number_format($allBooks), number_format($nullLeft),
       number_format($avgLen), number_format($maxLen));

if ($nullLeft > 0 && $modeAll) {
    printf("⚠️ --all 跑完仍有 %s 本為空,請追查原因(不要當成正常)。\n", number_format($nullLeft));
}
echo "完成。\n";
echo "★ 下一步:確認上面「仍為空」趨近 0 之後,才可以改 api/index.php 讓 q 查 search_text。\n";
