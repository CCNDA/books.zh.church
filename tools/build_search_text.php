<?php
declare(strict_types=1);

/**
 * books.search_key / books.search_text 搜尋欄建置工具(M1-B)
 *
 * 兩欄的分工(2026-09-17 依線上實測改為兩欄):
 *   search_key   VARCHAR(1500) 不含摘要 —— q 的**預設**搜尋目標
 *   search_text  MEDIUMTEXT    含摘要   —— 僅 API 帶 deep=1 時搜
 *
 * 為什麼拆兩欄(實測數據,不是猜的):
 *   EXPLAIN 顯示 `search_text LIKE '%…%'` 是 type=ALL(全表掃描,無索引可用),
 *   而 search_text 平均 463 字、65,290 列 ≈ 90 MB 文字要逐列比對 → 實測 4,946 ms。
 *   ★ 瓶頸**不是** v_book_list:COUNT 走 view 5,644ms vs 走 books 4,946ms,只差 12%。
 *   不含摘要平均只有 59.02 字(最長 533)→ 比值 7.85,預期降到約 630 ms。
 *
 * ★ 不用 FULLTEXT:MariaDB 無 ngram parser,對中文無分詞。
 *   要真正走索引得自建 bigram 分詞欄,列為檢核點 2 之後的優化票。
 *
 * 用法(主機 SSH,在 /home/ubuntu/books 下):
 *   php tools/build_search_text.php --dry-run          # 看樣本與兩欄長度,不寫入
 *   php tools/build_search_text.php --all              # 全量重建(欄位新增後首次用這個)
 *   php tools/build_search_text.php                    # 預設 --missing,只補空值(cron 用)
 *   php tools/build_search_text.php --book=60911       # 單書(除錯)
 *   php tools/build_search_text.php --all --batch=500  # 縮小批次
 *   php tools/build_search_text.php --all --summary-chars=2000   # 截斷摘要(只影響 search_text)
 *
 * ★ 併入每日排程:crawler/daily_new.sh 結尾加一行
 *   php tools/build_search_text.php --missing
 *   否則每日新書兩欄都是 NULL,搜不到。
 */

const TOOL_REV = '2026-09-17.2';   // 版本戳記:FTP 沒蓋到時靠這行看出來
const KEY_MAXLEN = 1500;           // 與 migration 的 VARCHAR(1500) 一致

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
$limit        = (int) ($opt['limit'] ?? 0);
$summaryChars = (int) ($opt['summary-chars'] ?? 0);  // 0 = 摘要全文
$dry          = array_key_exists('dry-run', $opt);

$pdo = db();

// ★ GROUP_CONCAT 預設長度可能不足,人名/ISBN 聚合會被**靜默截斷** → 先放寬
$pdo->exec('SET SESSION group_concat_max_len = 1000000');

// ── search_key:不含摘要 ───────────────────────────────────
// 原則:寧可多收。平面欄與正規化關聯表**兩套都放** ——
//   v_book_list 是 COALESCE(正規化, 平面),但搜尋欄沒有「哪個才對」的問題,
//   任一邊有值都該搜得到。
//   identifiers 全收(含 id_type='STORE' 商品代碼)→ 可用商品碼搜到書(實測 RCU63A 命中 3 本)。
//   keywords 納入 = 第一階段要求的「Tag 搜尋」(現行 api 原本漏了這項)。
$keyExpr = "CONCAT_WS(' ',
        b.title, b.subtitle, b.original_title,
        b.author, b.translator, b.publisher, b.series, b.keywords,
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

// ★ 截到 KEY_MAXLEN:欄位是 VARCHAR(1500),strict mode 下超長會直接報錯而非截斷
$keyStore = 'LEFT(' . $keyExpr . ', ' . KEY_MAXLEN . ')';

// ── search_text:search_key + 摘要 ────────────────────────
$summaryExpr = $summaryChars > 0 ? "LEFT(b.summary, {$summaryChars})" : 'b.summary';
$textExpr    = "CONCAT_WS(' ', {$keyExpr}, {$summaryExpr})";

$setBoth = "b.search_key = {$keyStore}, b.search_text = {$textExpr}";

// ── 單書模式 ──────────────────────────────────────────────
if ($bookId > 0) {
    if ($dry) {
        $st = $pdo->prepare("SELECT b.book_id, b.title,
                                    CHAR_LENGTH({$keyStore}) AS key_len,
                                    CHAR_LENGTH({$textExpr}) AS text_len,
                                    {$keyStore} AS k
                               FROM books b WHERE b.book_id = :id1");
        $st->execute([':id1' => $bookId]);
        $r = $st->fetch();
        if (!$r) { exit("book_id {$bookId} 不存在\n"); }
        printf("[dry-run] book %d《%s》 search_key %d 字 / search_text %d 字\n  %s\n",
               $bookId, (string) $r['title'], (int) $r['key_len'], (int) $r['text_len'],
               mb_substr((string) $r['k'], 0, 300));
        exit(0);
    }
    $st = $pdo->prepare("UPDATE books b SET {$setBoth} WHERE b.book_id = :id1");
    $st->execute([':id1' => $bookId]);
    // ★ 不信 rowCount(值相同時回 0),回查實際狀態
    $chk = $pdo->prepare('SELECT CHAR_LENGTH(search_key) AS k, CHAR_LENGTH(search_text) AS t
                            FROM books WHERE book_id = :id2');
    $chk->execute([':id2' => $bookId]);
    $r = $chk->fetch();
    printf("book %d 完成:search_key %s 字、search_text %s 字\n",
           $bookId, (string) $r['k'], (string) $r['t']);
    exit(0);
}

// ── 批次模式 ──────────────────────────────────────────────
// ★ --missing 的條件同時看兩欄:search_key 是 2026-09-17 新增的,
//   舊資料 search_text 有值但 search_key 為 NULL,必須被抓進來。
$cond = $modeAll
    ? '1=1'
    : "(b.search_key IS NULL OR b.search_key = '' OR b.search_text IS NULL OR b.search_text = '')";

$total = (int) $pdo->query("SELECT COUNT(*) FROM books b WHERE {$cond}")->fetchColumn();
printf("[rev %s] 模式 %s;待處理 %s 筆;批次 %d%s\n",
       TOOL_REV, $modeAll ? '--all(全量重建)' : '--missing(只補空值)',
       number_format($total), $batch, $dry ? ';DRY-RUN 不寫入' : '');
if ($total === 0) { exit("沒有需要處理的資料。\n"); }

if ($dry) {
    $st = $pdo->query("SELECT b.book_id, b.title,
                              CHAR_LENGTH({$keyStore}) AS key_len,
                              CHAR_LENGTH({$textExpr}) AS text_len,
                              {$keyStore} AS k
                         FROM books b WHERE {$cond} ORDER BY b.book_id LIMIT 3");
    foreach ($st->fetchAll() as $r) {
        printf("  book %-7d key %-5d / text %-6d 《%s》\n",
               (int) $r['book_id'], (int) $r['key_len'], (int) $r['text_len'], (string) $r['title']);
        printf("    %s\n", mb_substr(preg_replace('/\s+/u', ' ', (string) $r['k']) ?? '', 0, 180));
    }
    exit("\n[dry-run] 未寫入。★ 注意 key 欄長度應遠小於 text(不含摘要,實測平均 59 字)。\n");
}

$sel = $pdo->prepare("SELECT MAX(book_id) AS max_id, COUNT(*) AS n FROM (
                        SELECT b.book_id FROM books b
                         WHERE {$cond} AND b.book_id > :last1
                         ORDER BY b.book_id LIMIT {$batch}) t");

// 注意:EMULATE_PREPARES=false → 同名參數不可重複使用,故參數逐一編號
$upd = $pdo->prepare("UPDATE books b SET {$setBoth}
                       WHERE {$cond} AND b.book_id > :last2 AND b.book_id <= :max1");

// ★★ 2026-09-17:同步到 book_search 瘦表。
//   實測 books 表 DATA_LENGTH 501 MB(每列 7.7KB,大頭是 extra/summary/buy_links),
//   InnoDB 全表掃描以 page 為單位讀整列 → 掃 books 找關鍵字要 4,543 ms,
//   而只有兩欄的 book_search 僅 14 MB → 同一個查詢 389 ms(11.7 倍)。
//   books.search_key 仍保留(來源),本表由它同步而來。
$sync = $pdo->prepare(
    "INSERT INTO book_search (book_id, search_key)
     SELECT book_id, search_key FROM books
      WHERE book_id > :last3 AND book_id <= :max2 AND search_key IS NOT NULL
     ON DUPLICATE KEY UPDATE search_key = VALUES(search_key)");

$last = 0; $done = 0; $t0 = microtime(true);
while (true) {
    $sel->execute([':last1' => $last]);
    $row = $sel->fetch();
    $n   = (int) ($row['n'] ?? 0);
    if ($n === 0) { break; }
    $maxId = (int) $row['max_id'];

    $upd->execute([':last2' => $last, ':max1' => $maxId]);
    $sync->execute([':last3' => $last, ':max2' => $maxId]);
    $done += $n;
    $last  = $maxId;

    printf("  %s / %s(至 book_id %d)耗時 %.1fs\n",
           number_format($done), number_format($total), $last, microtime(true) - $t0);

    if ($limit > 0 && $done >= $limit) { echo "  已達 --limit,停止。\n"; break; }
}

// ── ★ 結尾一律回查資料庫對帳,不拿工具自印的數字當證據 ──
$row = $pdo->query('SELECT COUNT(*) AS all_books,
                           SUM(search_key  IS NULL OR search_key  = \'\') AS key_null,
                           SUM(search_text IS NULL OR search_text = \'\') AS text_null,
                           COALESCE(AVG(CHAR_LENGTH(search_key)), 0)  AS key_avg,
                           COALESCE(MAX(CHAR_LENGTH(search_key)), 0)  AS key_max,
                           COALESCE(AVG(CHAR_LENGTH(search_text)), 0) AS text_avg,
                           COALESCE(MAX(CHAR_LENGTH(search_text)), 0) AS text_max
                      FROM books')->fetch();

printf("\n[資料庫實查] 全站 %s 本\n", number_format((int) $row['all_books']));
printf("  search_key   空 %s 本;平均 %s 字、最長 %s 字\n",
       number_format((int) $row['key_null']), number_format((int) $row['key_avg']),
       number_format((int) $row['key_max']));
printf("  search_text  空 %s 本;平均 %s 字、最長 %s 字\n",
       number_format((int) $row['text_null']), number_format((int) $row['text_avg']),
       number_format((int) $row['text_max']));

// ★ book_search 瘦表對帳:API 實際查的是這張表,筆數對不上就是搜尋會漏書
$bs = $pdo->query('SELECT COUNT(*) AS n, SUM(search_key IS NULL OR search_key = \'\') AS empty_n
                     FROM book_search')->fetch();
printf("  book_search  %s 筆(應等於全站本數);其中空值 %s 筆\n",
       number_format((int) $bs['n']), number_format((int) $bs['empty_n']));

if ((int) $row['key_max'] >= KEY_MAXLEN) {
    printf("⚠️ 有 search_key 達到上限 %d 字(被截斷)。若筆數多,考慮加寬欄位。\n", KEY_MAXLEN);
}
if ($modeAll && ((int) $row['key_null'] > 0 || (int) $row['text_null'] > 0)) {
    echo "⚠️ --all 跑完仍有空值,請追查原因(不要當成正常)。\n";
}
if ((int) $bs['n'] !== (int) $row['all_books']) {
    printf("⚠️ book_search %s 筆 ≠ books %s 筆 —— **搜尋會漏掉差額那些書**,請追查。\n",
           number_format((int) $bs['n']), number_format((int) $row['all_books']));
}
echo "完成。\n";
echo "★ API 的關鍵字搜尋走 book_search 瘦表,不是 books —— 上面那筆數對不上就是會漏書。\n";
