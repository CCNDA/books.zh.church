<?php
declare(strict_types=1);

/**
 * 拆開被 ISBN 誤併的校園書房 edition(2026-10-16,Asana 1216590012421010)
 *
 * 背景:import.php 原本用 isbn_pair($r['isbn'] ?? $r['isbn_meta']),校園的 isbn 是
 *   整頁第一個「ISBN：」,會抓到「相關商品」的號 → 另一本書的 edition 被併進來。
 *   根因已在 pick_isbn() 修正(同分支);本工具只處理已經併錯的 6 筆。
 *   正式庫唯讀實查與逐本對校園商品頁 og:title 的結果見 Asana 票留言(10/16)。
 *
 * 本工具只做「拆開」,不建新書:
 *   1. 刪掉混入的 edition 與它帶的 identifiers／formats_prices／links／media
 *      (這些列都掛在該 edition 上,和原書的其他版本無關)
 *   2. 從原書移除「只屬於混入那本」的作者:名字出現在混入商品 authors_raw、
 *      而且沒有出現在原書 books.author 裡,才移除(兩邊都有的作者不動)
 *   3. 從 books.buy_links 平面欄位拿掉混入商品的網址
 *      (api 的購書彙整是 links + buy_links 聯集,只刪 links 會留下假按鈕)
 *   4. books.extra.campus 若存的是混入那筆,改成 --campus-json 給的本書商品資料;
 *      沒給就只報告、不動(寧可留舊資料,也不要清成空的)
 * 拆完之後,用已修好的 import.php 重新匯入那 6 個混入商品:
 *   正確 ISBN 有既有書的會自動併過去(95009、57889、86339),沒有的會新建。
 *   source_url 在第 1 步已刪,import 的 doneUrls 不會擋。
 *
 * 用法(cora-lab 先在 books_dev 跑):
 *   php tools/split_misjoined.php                       # 預設 dry-run,只列出要動的列
 *   php tools/split_misjoined.php --apply [--campus-json=<檔>]
 * 每一筆都會先檢查「edition 還掛在原書、來源是 campus、網址是混入商品」,
 * 不符合就跳過那筆 → 冪等,重跑不會多刪。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/lib/db.php';

$opt   = getopt('', ['apply', 'campus-json::']);
$apply = array_key_exists('apply', $opt);
$campus = [];
if (!empty($opt['campus-json'])) {
    $campus = json_decode((string) file_get_contents($opt['campus-json']), true);
    if (!is_array($campus)) exit("--campus-json 讀不到或不是 JSON 物件(product_id => 商品資料)\n");
}

// 原書 book_id => [混入的 edition_id, 混入商品 ProductID, 原書自己的校園 ProductID]
// 來源:Asana 1216590012421010 10/16 14:3x「拆分計畫」留言;混入商品編號是 10/17 dry-run 從 editions.source_url 讀出
const PAIRS = [
    37499 => [84066, '000621977', '000614382'],
    40929 => [48692, '000609265', '000609258'],
    41928 => [49006, '000586405', '000562683'],
    43551 => [51291, '000581134', '000581127'],
    45744 => [50951, '000587617', '000587600'],
    45997 => [50735, '000581097', '000576918'],
];
const CAMPUS_URL = 'https://shop.campus.org.tw/ProductDetails.aspx?ProductID=';

$pdo = db();
$pdo->exec('SET NAMES utf8mb4');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
echo ($apply ? '【寫入】' : '【dry-run,不寫入】'), ' 資料庫 ', $pdo->query('SELECT DATABASE()')->fetchColumn(), "\n";

/**
 * 作者字串拆成名字。persons.name 有時連註記一起存(「余惠娥(Hui Er Yu)」「楊聖立(文)」),
 * 有時沒有 → 每個名字回傳 [原樣, 去掉尾端括號註記] 兩種寫法,比對時兩種都試。
 */
function author_tokens(string $s): array
{
    $out = [];
    foreach (preg_split('/[、,，;；\/]+/u', $s) as $t) {
        $raw  = trim($t);
        $bare = trim(preg_replace('/[(（][^)）]*[)）]\s*$/u', '', $raw));
        if ($bare !== '') $out[] = array_values(array_unique([$raw, $bare]));
    }
    return $out;
}

$sum = ['done' => 0, 'skip' => 0, 'rows' => 0];
foreach (PAIRS as $bookId => [$edId, $intruderPid, $ownPid]) {
    $bk = $pdo->query("SELECT book_id, title, author, buy_links, extra FROM books WHERE book_id = $bookId")->fetch();
    $ed = $pdo->query("SELECT edition_id, book_id, source, source_url FROM editions WHERE edition_id = $edId")->fetch();
    echo "\n== 原書 {$bookId}《" . ($bk['title'] ?? '?') . "》  混入 edition $edId\n";
    if (!$bk || !$ed || (int) $ed['book_id'] !== $bookId || $ed['source'] !== 'campus') {
        echo "   跳過:edition 已不在原書或不是 campus(可能已拆過)\n";
        $sum['skip']++;
        continue;
    }
    preg_match('/ProductID=(\d+)/', $ed['source_url'], $m);
    $edPid = $m[1] ?? '';
    if ($edPid === $ownPid || $edPid !== $intruderPid) {
        echo "   跳過:edition 網址是 $edPid,和計畫不符,不動\n";
        $sum['skip']++;
        continue;
    }
    $intruderUrl = $ed['source_url'];
    $extra = json_decode((string) $bk['extra'], true) ?: [];
    $exCampus = $extra['campus'] ?? null;
    $exIsIntruder = is_array($exCampus) && ($exCampus['product_id'] ?? '') === $edPid;
    echo "   混入商品 {$edPid}《" . ($exIsIntruder ? ($exCampus['title'] ?? '?') : '(extra 不是它,看不到書名)') . "》\n";

    // 1. edition 與它帶的列
    $plan = [];
    foreach (['identifiers', 'formats_prices', 'links', 'media'] as $t) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE edition_id = $edId")->fetchColumn();
        $plan[] = ["DELETE FROM $t WHERE edition_id = $edId", $n];
    }
    $plan[] = ["DELETE FROM editions WHERE edition_id = $edId AND book_id = $bookId", 1];

    // 2. 只屬於混入那本的作者
    $rmPersons = [];
    if ($exIsIntruder && !empty($exCampus['authors_raw'])) {
        $own = (string) $bk['author'];
        // 原書作者欄也有這個名字(去掉註記後比)→ 兩本共同作者,不動
        $tokens = array_filter(author_tokens((string) $exCampus['authors_raw']),
            fn($v) => mb_strpos($own, end($v)) === false);
        if ($tokens) {
            $st = $pdo->prepare("SELECT bp.book_person_id, p.name FROM book_persons bp JOIN persons p USING (person_id)
                                  WHERE bp.book_id = ? AND p.name = ?");
            foreach (array_merge(...array_values($tokens)) as $t) {
                $st->execute([$bookId, $t]);
                foreach ($st->fetchAll() as $r) $rmPersons[(int) $r['book_person_id']] = $r['name'];
            }
        }
    } elseif (!$exIsIntruder) {
        echo "   ⚠ extra.campus 不是混入那筆,作者無法比對,這本作者不動(要人工看)\n";
    }
    foreach ($rmPersons as $bpId => $name) $plan[] = ["DELETE FROM book_persons WHERE book_person_id = $bpId   -- $name", 1];

    // 3. 平面購書欄位
    $bl = json_decode((string) $bk['buy_links'], true) ?: [];
    $bl2 = array_values(array_filter($bl, fn($l) => ($l['url'] ?? '') !== $intruderUrl));
    $blChanged = count($bl2) !== count($bl);
    if ($blChanged) $plan[] = ['UPDATE books.buy_links 拿掉 ' . $intruderUrl, count($bl) - count($bl2)];

    // 4. extra.campus
    $newCampus = null;
    if ($exIsIntruder) {
        if (isset($campus[$ownPid]) && is_array($campus[$ownPid]) && ($campus[$ownPid]['product_id'] ?? '') === $ownPid) {
            $newCampus = $campus[$ownPid];
            $plan[] = ["UPDATE books.extra.campus {$edPid} → {$ownPid}《" . ($newCampus['title'] ?? '?') . '》', 1];
        } else {
            echo "   ⚠ extra.campus 是混入那筆,要換成本書商品 $ownPid —— 沒有 --campus-json 資料,這步先不動\n";
        }
    }

    foreach ($plan as [$sql, $n]) echo "   $sql   ($n 列)\n";
    $sum['rows'] += array_sum(array_column($plan, 1));
    if (!$apply) { $sum['done']++; continue; }

    $pdo->beginTransaction();
    try {
        foreach (['identifiers', 'formats_prices', 'links', 'media'] as $t) $pdo->exec("DELETE FROM $t WHERE edition_id = $edId");
        $pdo->exec("DELETE FROM editions WHERE edition_id = $edId AND book_id = $bookId");
        if ($rmPersons) $pdo->exec('DELETE FROM book_persons WHERE book_person_id IN (' . implode(',', array_keys($rmPersons)) . ')');
        if ($blChanged) {
            $pdo->prepare('UPDATE books SET buy_links = ? WHERE book_id = ?')
                ->execute([json_encode($bl2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $bookId]);
        }
        if ($newCampus !== null) {
            $extra['campus'] = $newCampus;
            $pdo->prepare('UPDATE books SET extra = ? WHERE book_id = ?')
                ->execute([json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $bookId]);
        }
        $pdo->commit();
        echo "   ✓ 已寫入\n";
        $sum['done']++;
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "   ✗ 失敗已還原:" . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n合計:處理 {$sum['done']} 本、跳過 {$sum['skip']} 本、牽動 {$sum['rows']} 列\n";
echo "下一步:php tools/build_search_text.php 重建這 6 本的搜尋欄;再用 import.php 重匯混入的 6 個校園商品\n";
