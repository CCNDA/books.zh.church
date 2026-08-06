<?php
declare(strict_types=1);

/**
 * 套用天恩出版社(grace)官方分類 → 站內瀏覽分類(2026-08-06)
 *
 * 分類雙軌(沿 7/31 以琳決議,兩軌並存互可查照):
 *   軌一(已完成於 import):天恩官網原始分類(平面多分類,一書可多類)由
 *     tools/import.php 寫入 subjects(scheme='grace') + book_subjects,永久存證。
 *   軌二(本工具):讀軌一的存證,依 grace_category_map 換算站內瀏覽分類
 *     (books.category_id + book_subjects scheme='cat')。
 *
 * 資料來源即 DB 本身(scheme='grace' 的 book_subjects),不需外部檔。
 *
 * 寫入規則(比照 apply_elim_categories.php,差異如下):
 *   - grace-only 書(books.source='grace'):取代 scheme='cat' 舊分類,
 *     設 books.category_id = primary(依 grace_category_map.sort_order 取最前對映)。
 *   - 跨站合併書(source=campus/logos/elim):不覆蓋既有 primary,只追加
 *     對映分類(兩邊皆可瀏覽);--override-all 才一併取代。
 *   - 「新書快報」「暢銷排行」「電子書」不在對映表 → 自動略過(促銷/格式類)。
 *   - unpublish(與以琳「全數命中才下架」不同):grace-only 書歸屬分類
 *     **命中任一** unpublish=1(文創禮品/質選文創好物/專輯有聲/虛擬商品/
 *     年度日月曆)→ is_published=0——天恩分類皆平面主題類,掛非書分類即
 *     代表商品型態(如專輯常同掛禱告敬拜,全數命中永不成立)。
 *     例外:掛「電子書」分類的商品不下架(8/6 決議電子書照書上架)。
 *
 * 先決條件:先跑 database/migrations/2026-08-06_grace_category_map.sql。
 *
 * 用法(主機 CLI):
 *   php tools/apply_grace_categories.php --dry-run      # 只統計不寫入
 *   php tools/apply_grace_categories.php                # 寫入
 *   php tools/apply_grace_categories.php --override-all # 合併書也取代 primary(慎用)
 * 冪等可重跑(改完對映表後重跑即生效)。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/api/lib/db.php';

$opt         = getopt('', ['dry-run', 'override-all', 'limit::']);
$dry         = array_key_exists('dry-run', $opt);
$overrideAll = array_key_exists('override-all', $opt);
$limit       = (int) ($opt['limit'] ?? 0);

const EBOOK_LABEL = '電子書'; // 格式分類:不對映、且保護不因非書分類下架

$pdo = db();
$pdo->exec('SET NAMES utf8mb4');

// ── 站內分類 name→id/code ──────────────────────────────────
$cats = []; $catCode = [];
foreach ($pdo->query('SELECT category_id, code, name FROM categories')->fetchAll() as $r) {
    $cats[$r['name']]    = (int) $r['category_id'];
    $catCode[$r['name']] = $r['code'];
}

// ── grace 對映表 ───────────────────────────────────────────
$map = []; $unpub = []; $order = [];
foreach ($pdo->query('SELECT grace_name, internal_name, unpublish, sort_order FROM grace_category_map')->fetchAll() as $r) {
    $map[$r['grace_name']]   = $r['internal_name'];
    $unpub[$r['grace_name']] = (bool) $r['unpublish'];
    $order[$r['grace_name']] = (int) $r['sort_order'];
}
if (!$map) exit("grace_category_map 為空(請先跑 2026-08-06_grace_category_map.sql)\n");
$missing = [];
foreach ($map as $g => $name) if (!isset($cats[$name])) $missing[] = "$g→$name";
if ($missing) exit('對映目標分類不存在:' . implode('、', $missing) . "\n");

// ── 第一階段:逐書彙整 scheme='grace' 存證分類 ──────────────
$byBook = []; // book_id => ['source'=>?, 'labels'=>[label,...]]
$sql = "SELECT b.book_id, b.source, s.label
          FROM book_subjects bs
          JOIN subjects s ON s.subject_id = bs.subject_id AND s.scheme = 'grace'
          JOIN books    b ON b.book_id    = bs.book_id";
foreach ($pdo->query($sql) as $r) {
    $bid = (int) $r['book_id'];
    if (!isset($byBook[$bid])) {
        if ($limit && count($byBook) >= $limit) continue;
        $byBook[$bid] = ['source' => $r['source'], 'labels' => []];
    }
    $byBook[$bid]['labels'][$r['label']] = 1;
}
if (!$byBook) exit("找不到 scheme='grace' 的書(請先跑 import.php --source=grace)\n");

// ── 第二階段:每本書寫一次 ─────────────────────────────────
if (!$dry) {
    $upSubjCat = $pdo->prepare("INSERT INTO subjects (scheme, code, label) VALUES ('cat', :c, :l)
                                ON DUPLICATE KEY UPDATE subject_id = LAST_INSERT_ID(subject_id)");
    $insBS   = $pdo->prepare('INSERT INTO book_subjects (book_id, subject_id, weight) VALUES (:b, :s, :w)
                              ON DUPLICATE KEY UPDATE weight = VALUES(weight)');
    $delCat  = $pdo->prepare("DELETE bs FROM book_subjects bs JOIN subjects s ON s.subject_id = bs.subject_id
                              WHERE bs.book_id = :b AND s.scheme = 'cat'");
    $updBook = $pdo->prepare('UPDATE books SET category_id = :cid WHERE book_id = :bid');
    $downPub = $pdo->prepare('UPDATE books SET is_published = 0 WHERE book_id = :bid');
}
$catSubjId = [];
$catSid = function (string $name) use (&$catSubjId, &$upSubjCat, $catCode, $pdo): int {
    if (isset($catSubjId[$name])) return $catSubjId[$name];
    $upSubjCat->execute([':c' => $catCode[$name] ?? null, ':l' => $name]);
    return $catSubjId[$name] = (int) $pdo->lastInsertId();
};

$stats = []; $labelStats = []; $graceOnly = 0; $merged = 0; $multi = 0; $downs = 0; $done = 0;

if (!$dry) $pdo->beginTransaction();
foreach ($byBook as $bid => $d) {
    $isGraceOnly = ($d['source'] === 'grace');
    $isGraceOnly ? $graceOnly++ : $merged++;

    $labels = array_keys($d['labels']);
    usort($labels, fn($a, $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));

    $mapped = [];
    $anyUnpub = false;
    $isEbook  = in_array(EBOOK_LABEL, $labels, true);
    foreach ($labels as $g) {
        $labelStats[$g] = ($labelStats[$g] ?? 0) + 1;
        if (isset($unpub[$g]) && $unpub[$g]) $anyUnpub = true;
        if (isset($map[$g]) && !in_array($map[$g], $mapped, true)) $mapped[] = $map[$g];
    }
    $willDown = $isGraceOnly && $anyUnpub && !$isEbook;
    if ($willDown) $downs++;

    if (!$mapped) {
        if ($willDown && !$dry) $downPub->execute([':bid' => $bid]);
        continue; // 只掛促銷/格式類 → 分類交 classify 關鍵字回填
    }
    $primary = $mapped[0];
    if (count($mapped) >= 2) $multi++;
    $stats[$primary] = ($stats[$primary] ?? 0) + 1;

    if ($dry) continue;

    $replace = $isGraceOnly || $overrideAll;
    if ($replace) $delCat->execute([':b' => $bid]);
    foreach ($mapped as $i => $name) {
        $insBS->execute([':b' => $bid, ':s' => $catSid($name), ':w' => $i === 0 ? 10 : 5]);
    }
    if ($replace) $updBook->execute([':cid' => $cats[$primary], ':bid' => $bid]);
    if ($willDown) $downPub->execute([':bid' => $bid]);

    if (++$done % 500 === 0) { $pdo->commit(); $pdo->beginTransaction(); echo "  已處理 $done…\n"; }
}
if (!$dry && $pdo->inTransaction()) $pdo->commit();

// ── 報表 ───────────────────────────────────────────────────
arsort($stats); arsort($labelStats);
echo "\n== 天恩書歸類 ==\n";
echo '共 ' . count($byBook) . " 本(grace-only {$graceOnly}、跨站合併 {$merged});"
   . "多分類(>=2 站內類):{$multi};非書下架:{$downs}\n";
echo "\n== 天恩官方分類命中(書數,含跨類重複)==\n";
foreach ($labelStats as $g => $n) echo sprintf("  %-16s %6d\n", $g, $n);
echo "\n== 對映後 primary 站內分類分布 ==\n";
foreach ($stats as $c => $n) echo sprintf("  %-8s %6d\n", $c, $n);

echo "\n" . ($dry ? '[dry-run 未寫入]' : '完成寫入') . "\n";
echo $dry
    ? "確認分布合理後,拿掉 --dry-run 再跑一次即寫入。\n"
    : "驗證(Navicat):\n"
    . "  SELECT COUNT(*) FROM subjects WHERE scheme='grace';\n"
    . "  SELECT c.name, COUNT(*) FROM books b JOIN categories c ON c.category_id=b.category_id\n"
    . "    JOIN editions e ON e.book_id=b.book_id AND e.source='grace'\n"
    . "    WHERE b.is_published=1 GROUP BY c.name ORDER BY 2 DESC;\n";
