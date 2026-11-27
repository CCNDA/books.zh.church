<?php
declare(strict_types=1);

/**
 * book_lang 小表重建（M1-B 語言篩選；Asana 1220724721485985）
 *
 * 每次全量重建：讀 books 的 book_id、language 兩欄 → lang_group() 分組 → 寫 book_lang。
 *   - 有分組的 upsert；分組變成「未填」或書已刪除的，從 book_lang 刪掉。
 *   - 冪等：重跑結果一樣；只動 book_lang，不改 books。
 * 用法（主機 SSH，在 /home/ubuntu/books 下）：
 *   php tools/build_book_lang.php            # 重建並印各組筆數
 *   php tools/build_book_lang.php --dry-run  # 只算、不寫
 * 排程：跟在 build_search_text.php 後面跑（每日新書匯入後）。
 */

const TOOL_REV = '2026-11-20.1';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/lib/db.php';
require dirname(__DIR__) . '/app/lib/lang_group.php';

$dry = in_array('--dry-run', $argv, true);
$pdo = db();
printf("build_book_lang %s %s\n", TOOL_REV, $dry ? '（--dry-run，不寫入）' : '');

$want = [];          // book_id => group
$count = array_fill_keys(array_keys(LANG_GROUPS), 0);
$none = 0;
$st = $pdo->query('SELECT book_id, language FROM books');
while ($r = $st->fetch(PDO::FETCH_NUM)) {
    $g = lang_group($r[1]);
    if ($g === null) { $none++; continue; }
    $want[(int) $r[0]] = $g;
    $count[$g]++;
}
$st->closeCursor();

$have = [];
foreach ($pdo->query('SELECT book_id, lang_group FROM book_lang') as $r) {
    $have[(int) $r['book_id']] = $r['lang_group'];
}
$upsert = array_filter($want, fn($g, $id) => ($have[$id] ?? null) !== $g, ARRAY_FILTER_USE_BOTH);
$delete = array_keys(array_diff_key($have, $want));

printf("  books %d 本：", count($want) + $none);
foreach ($count as $g => $n) printf("%s %d｜", LANG_GROUPS[$g], $n);
printf("未填 %d\n", $none);
printf("  要新增或改組 %d 筆、要刪除 %d 筆\n", count($upsert), count($delete));
if ($dry) exit(0);

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare('INSERT INTO book_lang (book_id, lang_group) VALUES (?, ?)
                          ON DUPLICATE KEY UPDATE lang_group = VALUES(lang_group)');
    foreach ($upsert as $id => $g) $ins->execute([$id, $g]);
    foreach (array_chunk($delete, 1000) as $chunk) {
        $in = implode(',', array_map('intval', $chunk));   // 全是整數
        $pdo->exec("DELETE FROM book_lang WHERE book_id IN ($in)");
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, '🔴 寫入失敗，已回復：' . $e->getMessage() . "\n");
    exit(1);
}

// 對帳：book_lang 筆數要等於有分組的書
$got = (int) $pdo->query('SELECT COUNT(*) FROM book_lang')->fetchColumn();
if ($got !== count($want)) {
    fwrite(STDERR, sprintf("🔴 對帳不符：book_lang %d 筆 ≠ 有分組的書 %d 本\n", $got, count($want)));
    exit(1);
}
printf("✅ book_lang %d 筆（＝有分組的書）\n", $got);
