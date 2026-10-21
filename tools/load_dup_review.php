<?php
declare(strict_types=1);

/**
 * 把 check_new_books.php 產的疑似重複對照表匯進 dup_review（後台 /admin/dup.php 用）—— 2026-10-21（Asana 1219668289655146）
 *
 * 對照表只有書名／作者／ISBN；出版社、商品頁網址、封面從同一份爬蟲 jsonl 補（用來源貨號 pid 對）。
 * 已經在表上的（同清單、同來源、同貨號、同候選書）略過，不會蓋掉已經判過的結果 → 可以重跑。
 *
 * 用法：
 *   php tools/load_dup_review.php --tsv=crawler/data/bappress_dup.tsv --jsonl=crawler/data/bappress_books.jsonl --source=bappress
 *   php tools/load_dup_review.php ... --apply      # 寫入（預設 dry-run）
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/lib/db.php';

$opt   = getopt('', ['tsv:', 'jsonl:', 'source:', 'list::', 'apply']);
$apply = isset($opt['apply']);
$tsv   = (string) ($opt['tsv'] ?? '');
$jsonl = (string) ($opt['jsonl'] ?? '');
$src   = (string) ($opt['source'] ?? '');
$list  = (string) ($opt['list'] ?? ($src . '_dup'));
if (!is_file($tsv) || !is_file($jsonl) || !preg_match('/^[a-z0-9_]{2,20}$/', $src)) {
    fwrite(STDERR, "用法：php tools/load_dup_review.php --tsv=對照表.tsv --jsonl=爬蟲.jsonl --source=代碼 [--apply]\n");
    exit(1);
}

// 爬蟲紀錄：pid → 出版社、網址、封面
$rec = [];
foreach (new SplFileObject($jsonl) as $line) {
    $r = json_decode(trim((string) $line), true);
    if (is_array($r) && isset($r['pid'])) {
        $rec[(string) $r['pid']] = $r;
    }
}

$cut = fn(?string $s, int $n): ?string => ($s === null || trim($s) === '') ? null : mb_substr(trim($s), 0, $n, 'UTF-8');
$url = fn(?string $s): ?string => ($s !== null && preg_match('#^https?://#i', $s)) ? mb_substr($s, 0, 500, 'UTF-8') : null;

$rows = []; $bad = 0; $noRec = 0;
$fh = fopen($tsv, 'r');
$i = 0;
while (($c = fgetcsv($fh, 0, "\t", '"', '')) !== false) {
    if ($i++ === 0) continue;                                      // 標題列（含 BOM）
    if (count($c) < 10 || !ctype_digit((string) $c[4])) { $bad++; continue; }
    $r = $rec[(string) $c[0]] ?? null;
    if ($r === null) $noRec++;
    $rows[] = [
        ':l' => $list, ':s' => $src, ':k' => $cut($c[0], 64),
        ':t' => $cut($c[1], 255), ':a' => $cut($c[2], 255),
        ':p' => $cut($r['publisher'] ?? null, 255), ':i' => $cut($c[3], 20),
        ':u' => $url($r['source_url'] ?? null), ':v' => $url($r['cover_url'] ?? ($r['cover'] ?? null)),
        ':b' => (int) $c[4], ':h' => $cut($c[9], 40),
    ];
}
fclose($fh);

$pdo = db();
$has = $pdo->prepare('SELECT 1 FROM dup_review WHERE list_name = :l AND source = :s AND source_key = :k AND candidate_book_id = :b');
$book = $pdo->prepare('SELECT 1 FROM books WHERE book_id = :b');
$ins = $pdo->prepare('INSERT IGNORE INTO dup_review
    (list_name, source, source_key, src_title, src_author, src_publisher, src_isbn, src_url, src_cover, candidate_book_id, match_how)
    VALUES (:l, :s, :k, :t, :a, :p, :i, :u, :v, :b, :h)');

$new = 0; $dup = 0; $gone = 0; $done = 0;
if ($apply) $pdo->beginTransaction();
try {
    foreach ($rows as $r) {
        $has->execute([':l' => $r[':l'], ':s' => $r[':s'], ':k' => $r[':k'], ':b' => $r[':b']]);
        if ($has->fetchColumn()) { $dup++; continue; }
        $book->execute([':b' => $r[':b']]);
        if (!$book->fetchColumn()) { $gone++; continue; }           // 站上那本已經不在（被合併掉）就不列
        $new++;
        if ($apply) { $ins->execute($r); $done += $ins->rowCount(); }
    }
    if ($apply) $pdo->commit();
} catch (Throwable $e) {
    if ($apply) $pdo->rollBack();
    fwrite(STDERR, '寫入失敗，已全部退回：' . $e->getMessage() . "\n");
    exit(1);
}

printf("%s 對照表 %d 列（格式不對略過 %d）；爬蟲紀錄對不到 %d 列（出版社／網址／封面會空著）\n",
       $apply ? '【寫入】' : '【dry-run，不寫入】', count($rows) + $bad, $bad, $noRec);
printf("新增 %d、已在表上 %d、站上那本已不在 %d%s\n", $new, $dup, $gone, $apply ? "；實際寫入 {$done}" : '');
exit(0);
