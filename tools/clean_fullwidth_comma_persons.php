<?php
declare(strict_types=1);

/**
 * 全形逗號黏在一起的 person（「李志秋，張心瑋」）換成切開後的幾個人 —— 2026-10-21（Asana 1219676923286530）
 *
 * 起因：lib_person 的 PN_DELIMS 在 v1.19.5 加了全形逗號，但正式庫裡已經黏在一起的那批要另外處理。
 * ★ 為什麼不用 fix_person_names.php --book=N：它會把那本書**所有**署名欄重切一次、補進「缺的」人，
 *   連跟全形逗號無關、本來就切不好的欄位（「李格爾&郭佩蘭」「布賴恩．拜爾 (新約：) 沃爾特．埃爾韋爾」）
 *   也一起補進來——開發庫演練 216 本多生出 6 個這種髒名字（小布 v1.19.5 複測第 7 項抓到）。
 *   本工具只動「名字含全形逗號的那一筆關聯」，其他欄位一律不碰。
 *
 * 規則與 lib_person.php 的 pn_split_names() 同一份：
 *   - 切出來只有一個名字、或任何一段 hold（規則不敢動）→ 不碰（括號內英文名、學位、誤進人名欄的介紹文字）
 *   - 其他：每一段找同名 person（站上已有就用最小的那個 person_id，沒有才新建），
 *     掛到同一本書（角色＝署名讀出的角色，讀不出來就沿用舊那筆的角色；同書同人同角色已存在就略過），
 *     再刪掉舊的那一筆關聯；舊 person 沒有任何關聯了才刪
 *
 * 用法：
 *   php tools/clean_fullwidth_comma_persons.php            # dry-run（預設，不寫）
 *   php tools/clean_fullwidth_comma_persons.php --apply    # 寫入（一個交易，失敗全部退回）
 *   之後對印出的書號跑 tools/build_search_text.php --book=N（搜尋欄還是舊字）
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/lib/db.php';
require __DIR__ . '/lib_person.php';

$opt   = getopt('', ['apply']);
$apply = isset($opt['apply']);

$pdo     = db();
$persons = $pdo->prepare('SELECT person_id, name FROM persons WHERE INSTR(name, :c) > 0 ORDER BY person_id');
$persons->execute([':c' => "\u{FF0C}"]);
$persons = $persons->fetchAll(PDO::FETCH_ASSOC);

$links   = $pdo->prepare('SELECT book_person_id, book_id, role, role_order FROM book_persons WHERE person_id = :p ORDER BY book_id');
$findP   = $pdo->prepare('SELECT MIN(person_id) FROM persons WHERE name = :n');

$plan = []; $skip = [];
foreach ($persons as $p) {
    $pid   = (int) $p['person_id'];
    $parts = pn_split_names($p['name']);
    $names = array_values(array_unique(array_column($parts, 'name')));
    if (count($names) < 2)                          { $skip[] = [$p, '切不開（括號內、學位或單一名字）']; continue; }
    if (array_filter(array_column($parts, 'hold'))) { $skip[] = [$p, '有一段規則不敢動（hold）']; continue; }
    $links->execute([':p' => $pid]);
    foreach ($links->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $new = [];
        foreach ($parts as $i => $a) {
            $findP->execute([':n' => $a['name']]);
            $have = $findP->fetchColumn();
            $new[] = ['name' => $a['name'], 'pid' => $have ? (int) $have : null,
                      'role' => $a['role'] ?? $r['role'], 'order' => (int) $r['role_order'] + $i,
                      'credit' => mb_substr((string) $a['credit'], 0, 255)];
        }
        $plan[] = [$p, $r, $new];
    }
}

echo ($apply ? '【寫入】' : '【dry-run，不寫入】'), ' 名字含全形逗號的 person ', count($persons), " 個\n";
foreach ($plan as [$p, $r, $new]) {
    printf("  #%d  %s「%s」→ %s\n", $r['book_id'], $r['role'], $p['name'], implode('｜', array_map(
        fn($x) => $x['name'] . "（{$x['role']}，" . ($x['pid'] ? "既有 {$x['pid']}" : '新建') . '）', $new)));
}
foreach ($skip as [$p, $why]) {
    printf("  ○ 不碰 person %d「%s」（%s）\n", $p['person_id'], $p['name'], $why);
}

$insP      = $pdo->prepare('INSERT INTO persons (name) VALUES (:n)');
$insL      = $pdo->prepare('INSERT IGNORE INTO book_persons (book_id, person_id, role, role_order, credit_text)
                            VALUES (:b, :p, :r, :o, :c)');
$delLink   = $pdo->prepare('DELETE FROM book_persons WHERE book_person_id = :id AND person_id = :p');
$orphan    = $pdo->prepare('SELECT COUNT(*) FROM book_persons WHERE person_id = :p');
$delPerson = $pdo->prepare('DELETE FROM persons WHERE person_id = :p');
$nNewP = 0; $nAdd = 0; $nDel = 0; $nDelP = 0; $books = [];
foreach ($plan as [$p, $r]) $books[(int) $r['book_id']] = true;
if ($apply) {
    $pdo->beginTransaction();
    try {
        $made = [];                                   // 同一次執行新建過的名字，不要建兩次
        $touched = [];
        foreach ($plan as [$p, $r, $new]) {
            foreach ($new as $x) {
                $pidNew = $x['pid'] ?? ($made[$x['name']] ?? null);
                if ($pidNew === null) {
                    $insP->execute([':n' => $x['name']]);
                    $pidNew = $made[$x['name']] = (int) $pdo->lastInsertId();
                    $nNewP++;
                }
                $insL->execute([':b' => (int) $r['book_id'], ':p' => $pidNew, ':r' => $x['role'],
                                ':o' => $x['order'], ':c' => $x['credit']]);
                $nAdd += $insL->rowCount();
            }
            $delLink->execute([':id' => (int) $r['book_person_id'], ':p' => (int) $p['person_id']]);
            $nDel += $delLink->rowCount();
            $touched[(int) $p['person_id']] = true;
        }
        foreach (array_keys($touched) as $pid) {
            $orphan->execute([':p' => $pid]);
            if ((int) $orphan->fetchColumn() === 0) {
                $delPerson->execute([':p' => $pid]);
                $nDelP += $delPerson->rowCount();
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, '寫入失敗，已全部退回：' . $e->getMessage() . "\n");
        exit(1);
    }
}
printf("\n要換的關聯 %d 筆、不碰 %d 個 person%s\n", count($plan), count($skip),
       $apply ? "；新建 person {$nNewP} 個、新增關聯 {$nAdd} 筆（同書同人同角色已存在的略過）、刪舊關聯 {$nDel} 筆、刪舊 person {$nDelP} 個" : '');
echo '影響的書（之後跑 build_search_text --book）：', implode(' ', array_keys($books)), "\n";
exit(0);
