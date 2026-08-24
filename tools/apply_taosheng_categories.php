<?php
declare(strict_types=1);

/**
 * 套用道聲(taosheng)官方分類 → 站內瀏覽分類(2026-08-19)
 *
 * 分類雙軌(沿 7/31 以琳決議,兩軌並存互可查照):
 *   軌一(已完成於 import):道聲站方分類(歸屬由爬蟲「清單走訪」蒐集,
 *     一書多分類)由 tools/import.php 寫入 subjects(scheme='taosheng')
 *     + book_subjects 永久存證;
 *   軌二(本工具):讀軌一的存證,依 taosheng_category_map 換算站內瀏覽分類
 *     (books.category_id + book_subjects scheme='cat')。
 *
 * 重點(同 apply_osb_categories.php):
 *   - internal_name 可 NULL(=僅存證/純下架);查表一律 array_key_exists
 *     (8/17 教訓:isset 對 NULL 值回 false,下架列會被誤判為未對映)。
 *   - unpublish:非書(影音/月曆/刮刮卡/桌遊/禮品/福音機);taosheng-only 書命中任一即下架(沿天恩規則)。
 *   - 出版社彙整分類(香港道聲/格子外面/上誼…)僅存證不歸類。
 *
 * 先決條件:先跑 database/migrations/2026-08-19_taosheng_category_map.sql。
 *
 * 用法(主機 CLI):
 *   php tools/apply_taosheng_categories.php --dry-run      # 只統計不寫入
 *   php tools/apply_taosheng_categories.php                # 寫入
 *   php tools/apply_taosheng_categories.php --override-all # 合併書也取代 primary(慎用)
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

$pdo = db();
$pdo->exec('SET NAMES utf8mb4');

// ── 站內分類 name→id/code ──────────────────────────────────
$cats = []; $catCode = [];
foreach ($pdo->query('SELECT category_id, code, name FROM categories')->fetchAll() as $r) {
    $cats[$r['name']]    = (int) $r['category_id'];
    $catCode[$r['name']] = $r['code'];
}

// ── 道聲對映表(鍵=ts_code)────────────────────────────────
$map = []; $unpub = []; $order = []; $srcName = [];
foreach ($pdo->query('SELECT ts_code, ts_name, internal_name, unpublish, sort_order FROM taosheng_category_map')->fetchAll() as $r) {
    $map[$r['ts_code']]     = $r['internal_name'];
    $unpub[$r['ts_code']]   = (bool) $r['unpublish'];
    $order[$r['ts_code']]   = (int) $r['sort_order'];
    $srcName[$r['ts_code']] = $r['ts_name'];
}
if (!$map) exit("taosheng_category_map 為空(請先跑 2026-08-18_taosheng_category_map.sql)\n");
$missing = [];
foreach ($map as $g => $name) {
    if ($name !== null && $name !== '' && !isset($cats[$name])) $missing[] = "{$srcName[$g]}→{$name}";
}
if ($missing) exit('對映目標分類不存在:' . implode('、', array_unique($missing)) . "\n");

// ── 第一階段:逐書彙整 scheme='taosheng' 存證分類 ────────────────
$byBook = []; // book_id => ['source'=>?, 'codes'=>[code=>label]]
$sql = "SELECT b.book_id, b.source, s.code, s.label
          FROM book_subjects bs
          JOIN subjects s ON s.subject_id = bs.subject_id AND s.scheme = 'taosheng'
          JOIN books    b ON b.book_id    = bs.book_id";
foreach ($pdo->query($sql) as $r) {
    $bid = (int) $r['book_id'];
    if (!isset($byBook[$bid])) {
        if ($limit && count($byBook) >= $limit) continue;
        $byBook[$bid] = ['source' => $r['source'], 'codes' => []];
    }
    $byBook[$bid]['codes'][(string) $r['code']] = $r['label'];
}
if (!$byBook) exit("找不到 scheme='taosheng' 的書(請先跑 import.php --source=taosheng)\n");

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

$stats = []; $labelStats = []; $unmapped = []; $srcOnly = 0; $merged = 0; $multi = 0; $downs = 0; $done = 0;

if (!$dry) $pdo->beginTransaction();
foreach ($byBook as $bid => $d) {
    $isSrcOnly = ($d['source'] === 'taosheng');
    $isSrcOnly ? $srcOnly++ : $merged++;

    $codes = array_keys($d['codes']);
    usort($codes, fn($a, $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));

    $mapped = [];
    $anyUnpub = false;
    foreach ($codes as $c) {
        $disp = $srcName[$c] ?? ($d['codes'][$c] ?: $c);
        $labelStats[$disp] = ($labelStats[$disp] ?? 0) + 1;
        // array_key_exists 而非 isset:internal_name 為 NULL 的純下架/存證列
        // isset 會誤判為未對映而跳過下架(8/17 衛理教訓)
        if (!array_key_exists($c, $map)) { $unmapped[$disp] = ($unmapped[$disp] ?? 0) + 1; continue; }
        if ($unpub[$c]) $anyUnpub = true;
        $target = $map[$c];
        if ($target !== null && $target !== '' && !in_array($target, $mapped, true)) $mapped[] = $target;
    }
    $willDown = $isSrcOnly && $anyUnpub;
    if ($willDown) $downs++;

    if (!$mapped) {
        if ($willDown && !$dry) $downPub->execute([':bid' => $bid]);
        continue; // 無對映 → 分類交 classify 關鍵字回填
    }
    $primary = $mapped[0];
    if (count($mapped) >= 2) $multi++;
    $stats[$primary] = ($stats[$primary] ?? 0) + 1;

    if ($dry) continue;

    $replace = $isSrcOnly || $overrideAll;
    if ($replace) $delCat->execute([':b' => $bid]);
    foreach ($mapped as $i => $name) {
        $insBS->execute([':b' => $bid, ':s' => $catSid($name), ':w' => $i === 0 ? 10 : 5]);
    }
    if ($replace) $updBook->execute([':cid' => $cats[$primary], ':bid' => $bid]);
    if ($willDown) $downPub->execute([':bid' => $bid]);

    if (++$done % 500 === 0) { $pdo->commit(); $pdo->beginTransaction(); echo "  已處理 {$done}…\n"; }
}
if (!$dry && $pdo->inTransaction()) $pdo->commit();

// ── 報表 ───────────────────────────────────────────────────
arsort($stats); arsort($labelStats);
echo "\n== 道聲書歸類 ==\n";
echo '共 ' . count($byBook) . " 本(taosheng-only {$srcOnly}、跨站合併 {$merged});"
   . "多分類(>=2 站內類):{$multi};非書下架:{$downs}\n";
echo "\n== 道聲官方分類命中(書數)==\n";
foreach ($labelStats as $g => $n) echo sprintf("  %-34s %6d\n", $g, $n);
if ($unmapped) {
    arsort($unmapped);
    echo "\n== 未對映分類(已略過;如需歸類請補進 taosheng_category_map)==\n";
    foreach ($unmapped as $g => $n) echo sprintf("  %-34s %6d\n", $g, $n);
}
echo "\n== 對映後 primary 站內分類分布 ==\n";
foreach ($stats as $c => $n) echo sprintf("  %-8s %6d\n", $c, $n);

echo "\n" . ($dry ? '[dry-run 未寫入]' : '完成寫入') . "\n";
echo $dry
    ? "確認分布合理後,拿掉 --dry-run 再跑一次即寫入。\n"
    : "驗證(Navicat):\n"
    . "  SELECT COUNT(*) FROM subjects WHERE scheme='taosheng';\n"
    . "  SELECT c.name, COUNT(*) FROM books b JOIN categories c ON c.category_id=b.category_id\n"
    . "    JOIN editions e ON e.book_id=b.book_id AND e.source='taosheng'\n"
    . "    WHERE b.is_published=1 GROUP BY c.name ORDER BY 2 DESC;\n";
