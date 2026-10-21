<?php
declare(strict_types=1);

/**
 * 全形逗號黏在一起的 person（「李志秋，張心瑋」）重切後，刪掉舊的那一列 —— 2026-10-21（Asana 1219676923286530）
 *
 * 起因：lib_person 的 PN_DELIMS 在 v1.19.5 加了全形逗號。fix_person_names.php --book=N 只會「補」切開後的人，
 *   舊的黏在一起的那個 person 與它的關聯還在，書頁會同時出現「李志秋，張心瑋」和「李志秋」「張心瑋」。
 * 判斷規則與 lib_person.php 的 pn_split_names() 同一份：
 *   - 切出來只有一個名字、或任何一段 hold（規則不敢動）→ 不碰
 *   - 每一個切出來的名字都已經掛在**同一本書**上 → 刪舊的那一筆關聯；person 沒有關聯了就刪掉
 *   - 有任何一個名字還沒掛上（重切沒跑、或跑了但被擋）→ 不刪，列出來
 *
 * 用法（順序不可調換）：
 *   1. 先對影響的書跑重切：本工具 dry-run 會印出書號，對每本跑 php tools/fix_person_names.php --book=N --apply
 *   2. php tools/clean_fullwidth_comma_persons.php            # dry-run（預設，不寫）
 *   3. php tools/clean_fullwidth_comma_persons.php --apply    # 寫入
 *   4. 對印出的書號跑 tools/build_search_text.php --book=N（搜尋欄還是舊字）
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/lib/db.php';
require __DIR__ . '/lib_person.php';

$opt   = getopt('', ['apply', 'books']);
$apply = isset($opt['apply']);
if ($apply && isset($opt['books'])) {
    fwrite(STDERR, "--books 只印書號、不寫入，不要和 --apply 一起用\n");
    exit(1);
}

$pdo     = db();
$persons = $pdo->prepare('SELECT person_id, name FROM persons WHERE INSTR(name, :c) > 0 ORDER BY person_id');
$persons->execute([':c' => "\u{FF0C}"]);
$persons = $persons->fetchAll(PDO::FETCH_ASSOC);

$links = $pdo->prepare('SELECT book_person_id, book_id, role FROM book_persons WHERE person_id = :p ORDER BY book_id');
// ★ 要同一本書、**同一個角色**：只比名字的話，新切出來的人若掛成別的角色，會誤判成已有、把舊關聯的角色資訊刪掉
$has   = $pdo->prepare(
    'SELECT 1 FROM book_persons bp JOIN persons p ON p.person_id = bp.person_id
      WHERE bp.book_id = :b AND bp.role = :r AND p.name = :n AND bp.person_id <> :self LIMIT 1');

$plan = []; $wait = []; $skip = []; $allBooks = [];
foreach ($persons as $p) {
    $pid   = (int) $p['person_id'];
    $parts = pn_split_names($p['name']);
    $names = array_values(array_unique(array_column($parts, 'name')));
    $held  = array_filter(array_column($parts, 'hold'));
    $links->execute([':p' => $pid]);
    $rows = $links->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $allBooks[(int) $r['book_id']] = true;
    if (count($names) < 2) { $skip[] = [$p, '切不開（括號內、學位或單一名字）']; continue; }
    if ($held)             { $skip[] = [$p, '有一段規則不敢動（hold）']; continue; }
    foreach ($rows as $r) {
        $missing = [];
        foreach ($names as $n) {
            $has->execute([':b' => (int) $r['book_id'], ':r' => $r['role'], ':n' => $n, ':self' => $pid]);
            if (!$has->fetchColumn()) $missing[] = $n;
        }
        if ($missing) $wait[] = [$p, $r, $missing];
        else          $plan[] = [$p, $r, $names];
    }
}

if (isset($opt['books'])) {               // 只印要先重切的書號（給 fix_person_names --book 迴圈用）
    echo implode("\n", array_keys($allBooks)), "\n";
    exit(0);
}

echo ($apply ? '【寫入】' : '【dry-run，不寫入】'), ' 名字含全形逗號的 person ', count($persons), " 個\n";
foreach ($plan as [$p, $r, $names]) {
    printf("  刪 #%d  %s「%s」→ 已有：%s\n", $r['book_id'], $r['role'], $p['name'], implode('｜', $names));
}
foreach ($wait as [$p, $r, $missing]) {
    printf("  ○ 先不刪 #%d「%s」：還沒掛上 %s（重切跑了嗎？）\n", $r['book_id'], $p['name'], implode('｜', $missing));
}
foreach ($skip as [$p, $why]) {
    printf("  ○ 不碰 person %d「%s」（%s）\n", $p['person_id'], $p['name'], $why);
}

$delLink   = $pdo->prepare('DELETE FROM book_persons WHERE book_person_id = :id AND person_id = :p');
$orphan    = $pdo->prepare('SELECT COUNT(*) FROM book_persons WHERE person_id = :p');
$delPerson = $pdo->prepare('DELETE FROM persons WHERE person_id = :p');
$doneLinks = 0; $donePersons = 0; $books = [];
if ($apply) {
    $pdo->beginTransaction();
    try {
        $touched = [];
        foreach ($plan as [$p, $r]) {
            $delLink->execute([':id' => (int) $r['book_person_id'], ':p' => (int) $p['person_id']]);
            $doneLinks += $delLink->rowCount();
            $books[(int) $r['book_id']] = true;
            $touched[(int) $p['person_id']] = true;
        }
        foreach (array_keys($touched) as $pid) {
            $orphan->execute([':p' => $pid]);
            if ((int) $orphan->fetchColumn() === 0) {
                $delPerson->execute([':p' => $pid]);
                $donePersons += $delPerson->rowCount();
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, '寫入失敗，已全部退回：' . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    foreach ($plan as [$p, $r]) $books[(int) $r['book_id']] = true;
}
printf("\n要刪關聯 %d 筆、先不刪 %d 筆、不碰 %d 個 person%s\n", count($plan), count($wait), count($skip),
       $apply ? "；實際刪了關聯 {$doneLinks} 筆、person {$donePersons} 個" : '');
echo '影響的書（之後跑 build_search_text --book）：', implode(' ', array_keys($books)), "\n";
exit(0);
