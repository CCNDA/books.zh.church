<?php
declare(strict_types=1);

/**
 * 找出「書名＋出版社相同」但沒被合併的重複書 —— 唯讀,只產 review 清單(2026-10-14)
 *
 * ═══ 為什麼需要這支工具 ═══
 * 熊哥 10/14 回報:搜「張悅慈」出現三筆《邪惡與上帝新世界》(Asana 1219483419251976)。
 * 一筆有 ISBN(基道等四家),真哪噠、微讀兩筆沒有 ISBN、作者寫法各不同
 * (「賴特」/「賴特（N. T. Wright）」/「湯姆．賴特 (N. T. Wright)、…」)。
 * merge_duplicate_books.php 模式一以「正規化書名＋第一作者」分組,作者寫法不同就分到不同組。
 * 正式庫量到:書名＋出版社完全相同、至少一筆沒 ISBN、非空 ISBN ≤ 1 種的,608 組／1,237 本。
 *
 * ═══ 判斷規則(存疑就不預填「併入」)═══
 *   分組:正規化書名＋正規化出版社(norm() 與 merge_duplicate_books.php 相同:去空白標點、轉小寫)
 *   排除:組內非空 ISBN 兩種以上(可能是不同版本,交 1217969441137237 複核);全部都有 ISBN(那是 ISBN 合併的事)
 *   每一筆重複書對主檔(主檔挑法同模式一:有 isbn → 版本多 → id 小)分三級:
 *     高 → 預填「併入」:作者相容(一邊空白,或正規化後一邊包含另一邊、且較短的 ≥ 2 字),
 *                         兩邊至少一邊有作者,而且兩筆來自不同書房
 *     待判 → 留白:兩邊都沒作者(《聖經》這類書誤併就是災難)、或同一家書房上了兩筆
 *                  (多半是平裝/精裝、套書分冊、電子書等不同商品)
 *     不併 → 預填「不併」:兩邊都有作者但互不包含(同名不同書)
 *
 * ═══ 輸出 ═══
 * crawler/data/title_publisher_dup_review.tsv(UTF-8 with BOM,Excel 可直開),欄位與 --pairs 模式相容:
 *   判定 / 信心 / 理由 / 重複id / 重複書名 / 主檔id / 主檔書名 / 出版社 / 重複作者 / 主檔作者 / 重複來源 / 主檔來源 / 主檔ISBN
 * 人看過後交:
 *   php tools/merge_duplicate_books.php --pairs=crawler/data/title_publisher_dup_review.tsv --dry-run
 *   php tools/merge_duplicate_books.php --pairs=crawler/data/title_publisher_dup_review.tsv
 *
 * 用法(唯讀,不寫資料庫):php tools/find_title_publisher_duplicates.php [--out=路徑]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/lib/db.php';
require_once __DIR__ . '/lib_dupmatch.php';   // 判斷規則與 import.php 共用

$opt = getopt('', ['out::']);
$out = (string) ($opt['out'] ?? (dirname(__DIR__) . '/crawler/data/title_publisher_dup_review.tsv'));

$pdo = db();
$pdo->exec('SET NAMES utf8mb4');

// 出版社用 editions.publisher_id 的正規群組(COALESCE(canonical_id, publisher_id),與 API 篩選相同),
// 「校園」「校園書房」「校園書房出版社」才會算同一家;沒有 publisher_id 的版本退回 books.publisher 字串。
// 一本書可能掛好幾家出版社 → 用連通分組(union-find)把「同書名且共用任一出版社」的書併成一組,
// 確保每本書只出現在一組:--pairs 模式遇到同一本被指定兩次會中止,主檔若又是別組的重複書也會出錯。
$sql = "SELECT b.book_id, b.title, b.author, b.publisher, b.isbn13,
               COUNT(DISTINCT e.edition_id) AS ed_cnt,
               GROUP_CONCAT(DISTINCT e.source ORDER BY e.source) AS srcs,
               GROUP_CONCAT(DISTINCT COALESCE(p.canonical_id, p.publisher_id)) AS pubs
          FROM books b
          JOIN editions e ON e.book_id = b.book_id
          LEFT JOIN publishers p ON p.publisher_id = e.publisher_id
         WHERE b.is_published = 1
         GROUP BY b.book_id";
$books = [];
$byKey = [];
foreach ($pdo->query($sql) as $r) {
    $t = dm_norm($r['title']);
    if ($t === '') continue;
    $keys = array_map(fn($id) => "$t|p$id", array_filter(explode(',', (string) $r['pubs'])));
    if (!$keys && dm_norm($r['publisher']) !== '') $keys = ["$t|s" . dm_norm($r['publisher'])];
    if (!$keys) continue;
    $books[$r['book_id']] = $r;
    foreach ($keys as $k) $byKey[$k][] = (int) $r['book_id'];
}
$parent = [];
$find = function (int $x) use (&$parent, &$find): int {
    if (!isset($parent[$x]) || $parent[$x] === $x) return $parent[$x] = $x;
    return $parent[$x] = $find($parent[$x]);
};
foreach ($byKey as $ids) {
    if (count($ids) < 2) continue;
    $root = $find($ids[0]);
    foreach (array_slice($ids, 1) as $id) $parent[$find($id)] = $root;
}
$groups = [];
foreach (array_keys($parent) as $id) $groups[$find($id)][] = $books[$id];

$rows = [];
$stat = ['groups' => 0, '高' => 0, '待判' => 0, '不併' => 0, 'skip_multi_isbn' => 0, 'skip_all_isbn' => 0];
foreach ($groups as $g) {
    if (count($g) < 2) continue;
    $isbns = array_values(array_unique(array_filter(array_column($g, 'isbn13'))));
    if (count($isbns) > 1) { $stat['skip_multi_isbn']++; continue; }
    if (count(array_filter($g, fn($r) => empty($r['isbn13']))) === 0) { $stat['skip_all_isbn']++; continue; }
    usort($g, fn($x, $y) => [(int) empty($x['isbn13']), -(int) $x['ed_cnt'], (int) $x['book_id']]
                        <=> [(int) empty($y['isbn13']), -(int) $y['ed_cnt'], (int) $y['book_id']]);
    $keep = array_shift($g);
    $stat['groups']++;
    $ks = explode(',', (string) $keep['srcs']);
    foreach ($g as $d) {
        [$level, $why] = dm_judge($keep['author'], $d['author'], $ks, explode(',', (string) $d['srcs']));
        $stat[$level]++;
        $rows[] = [$level === '高' ? '併入' : ($level === '不併' ? '不併' : ''), $level, $why,
                   $d['book_id'], $d['title'], $keep['book_id'], $keep['title'], (string) $keep['publisher'],
                   (string) $d['author'], (string) $keep['author'], (string) $d['srcs'], (string) $keep['srcs'], (string) $keep['isbn13']];
    }
}

// 高 → 待判 → 不併,同級依主檔 id
$order = ['高' => 0, '待判' => 1, '不併' => 2];
usort($rows, fn($x, $y) => [$order[$x[1]], $x[5]] <=> [$order[$y[1]], $y[5]]);

$fh = fopen($out, 'w');
if ($fh === false) exit("寫不進 {$out}\n");
fwrite($fh, "\xEF\xBB\xBF");
fwrite($fh, implode("\t", ['判定(併入/不併/其他)', '信心', '理由', '重複id', '重複書名', '主檔id', '主檔書名', '出版社',
                           '重複作者', '主檔作者', '重複來源', '主檔來源', '主檔ISBN']) . "\n");
foreach ($rows as $r) {
    fwrite($fh, implode("\t", array_map(fn($x) => str_replace(["\t", "\n", "\r"], ' ', (string) $x), $r)) . "\n");
}
fclose($fh);

echo "書名＋出版社相同的候選組:{$stat['groups']} 組(另跳過:多 ISBN {$stat['skip_multi_isbn']} 組、全部都有 ISBN {$stat['skip_all_isbn']} 組)\n";
echo "逐筆:高(預填併入){$stat['高']}、待判(留白){$stat['待判']}、不併 {$stat['不併']}\n";
echo "清單:{$out}\n";
echo "人看過後:php tools/merge_duplicate_books.php --pairs={$out} --dry-run\n";
