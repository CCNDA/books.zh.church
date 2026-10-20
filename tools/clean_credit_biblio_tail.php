<?php
declare(strict_types=1);

/**
 * book_persons.credit_text 去掉書目尾巴（ISBN／書號＋13 碼、「出版：…」）——2026-10-20（Asana 1219613623198535）
 *
 * 起因：作者清理把 persons.name 清乾淨了，但每筆關聯自己的 credit_text 還是原始署名，
 *   書頁會印成「唐澄暐(唐澄暐 ISBN ： 9789865524272)」。判斷規則與 lib_person.php 的 pn_cut_biblio_tail() 同一份。
 * 不動：整段只有 ISBN 的（那種 person 已由清理 SQL 刪掉）；人工清單上的 person（--review 檔「處理」欄是「人工…」的列）。
 *
 * 用法：
 *   php tools/clean_credit_biblio_tail.php --review=docs/review/2026-10-19_person_biblio_tail.tsv            # dry-run（預設，不寫）
 *   php tools/clean_credit_biblio_tail.php --review=docs/review/2026-10-19_person_biblio_tail.tsv --apply    # 寫入
 * 寫完要對印出的書號跑 tools/build_search_text.php --book=N（搜尋欄還是舊字）。
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/lib/db.php';
require __DIR__ . '/lib_person.php';

$opt    = getopt('', ['apply', 'review:']);
$apply  = isset($opt['apply']);
$review = (string) ($opt['review'] ?? '');
if ($review === '' || !is_file($review)) {
    fwrite(STDERR, "要給 --review=<審核清單 TSV>（用來跳過人工清單上的 person）\n");
    exit(1);
}

$skip = [];
foreach (file($review, FILE_IGNORE_NEW_LINES) as $i => $line) {
    $c = explode("\t", preg_replace('/^\xEF\xBB\xBF/', '', $line));
    if ($i === 0 || count($c) < 3) continue;
    if (str_starts_with(trim($c[0]), '人工')) $skip[(int) $c[2]] = true;
}

$pdo  = db();
$rows = $pdo->query(
    "SELECT bp.book_person_id, bp.book_id, bp.person_id, bp.credit_text, p.name
       FROM book_persons bp JOIN persons p ON p.person_id = bp.person_id
      WHERE bp.credit_text REGEXP '97[89][0-9]{10}' OR bp.credit_text LIKE '%ISBN%'
         OR bp.credit_text LIKE '%書號%' OR bp.credit_text LIKE '%出版：%'
      ORDER BY bp.book_id, bp.book_person_id")->fetchAll(PDO::FETCH_ASSOC);

$up = $pdo->prepare('UPDATE book_persons SET credit_text = :new WHERE book_person_id = :id AND credit_text = :old');
$plan = []; $skipped = [];
foreach ($rows as $r) {
    $old = (string) $r['credit_text'];
    if (isset($skip[(int) $r['person_id']])) { $skipped[] = [$r, '人工清單']; continue; }
    if (pn_is_isbn_only($old))              { $skipped[] = [$r, '整段只有 ISBN']; continue; }
    $cut = pn_cut_biblio_tail($old);
    if ($cut === null)                      { $skipped[] = [$r, '規則切不出尾巴']; continue; }
    $new = trim($cut[0]);
    if ($new === '' || $new === $old)       { $skipped[] = [$r, '切完是空的或沒變']; continue; }
    $plan[] = [$r, $new];
}

echo ($apply ? '【寫入】' : '【dry-run，不寫入】'), ' credit_text 有書目尾巴的關聯 ', count($rows), " 筆\n";
foreach ($plan as [$r, $new]) {
    printf("  #%d  %s  「%s」→「%s」\n", $r['book_id'], $r['name'], $r['credit_text'], $new);
}
foreach ($skipped as [$r, $why]) {
    printf("  ○ 跳過 #%d  %s  「%s」（%s）\n", $r['book_id'], $r['name'], $r['credit_text'], $why);
}

$done = 0; $books = [];
if ($apply) {
    $pdo->beginTransaction();
    foreach ($plan as [$r, $new]) {
        $up->execute([':new' => $new, ':id' => (int) $r['book_person_id'], ':old' => $r['credit_text']]);
        $done += $up->rowCount();
        $books[(int) $r['book_id']] = true;
    }
    $pdo->commit();
} else {
    foreach ($plan as [$r]) $books[(int) $r['book_id']] = true;
}
printf("\n要改 %d 筆、跳過 %d 筆%s\n", count($plan), count($skipped), $apply ? "、實際改了 {$done} 筆" : '');
echo '影響的書（之後跑 build_search_text --book）：', implode(' ', array_keys($books)), "\n";
exit(0);
