<?php
declare(strict_types=1);

/**
 * 套用微讀書城(wdbook)官方分類 → 站內瀏覽分類(2026-08-09)
 *
 * 分類雙軌(沿 7/31 以琳決議,兩軌並存互可查照):
 *   軌一(已完成於 import):微讀官網分類(階層樹;商品麵包屑僅帶一個
 *     子分類)由 tools/import.php 寫入 subjects(scheme='wdbook') +
 *     book_subjects,永久存證——code=微讀分類 id、label=繁體完整路徑。
 *   軌二(本工具):讀軌一的存證,依 wdbook_category_map 換算站內瀏覽
 *     分類(books.category_id + book_subjects scheme='cat')。
 *
 * 與 apply_grace_categories.php 的差異:
 *   - 對映鍵為分類 id(subjects.code):站方分類名稱隨介面語系浮動
 *     (常回簡體),id 才穩定。
 *   - 微讀全站皆電子書、無非書商品:對映表 unpublish 現皆 0
 *     (機制保留,若日後站方出現非書分類可於 Navicat 補設)。
 *
 * 寫入規則(比照 apply_grace_categories.php):
 *   - wdbook-only 書(books.source='wdbook'):取代 scheme='cat' 舊分類,
 *     設 books.category_id = primary(依 wdbook_category_map.sort_order 取最前)。
 *   - 跨站合併書(source=campus/logos/elim/grace):不覆蓋既有 primary,
 *     只追加對映分類(兩邊皆可瀏覽);--override-all 才一併取代。
 *   - 未對映 id(促銷分類或站方新增分類)自動略過並列入報表。
 *   - unpublish:wdbook-only 書命中任一 unpublish=1 → is_published=0。
 *
 * 先決條件:先跑 database/migrations/2026-08-09_wdbook_category_map.sql。
 *
 * 用法(主機 CLI):
 *   php tools/apply_wdbook_categories.php --dry-run      # 只統計不寫入
 *   php tools/apply_wdbook_categories.php                # 寫入
 *   php tools/apply_wdbook_categories.php --override-all # 合併書也取代 primary(慎用)
 * 冪等可重跑(改完對映表後重跑即生效)。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/lib/db.php';

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

// ── wdbook 對映表(鍵=微讀分類 id)──────────────────────────
$map = []; $unpub = []; $order = []; $wdName = [];
foreach ($pdo->query('SELECT wd_id, wd_name, internal_name, unpublish, sort_order FROM wdbook_category_map')->fetchAll() as $r) {
    $map[$r['wd_id']]    = $r['internal_name'];
    $unpub[$r['wd_id']]  = (bool) $r['unpublish'];
    $order[$r['wd_id']]  = (int) $r['sort_order'];
    $wdName[$r['wd_id']] = $r['wd_name'];
}
if (!$map) exit("wdbook_category_map 為空(請先跑 2026-08-09_wdbook_category_map.sql)\n");
$missing = [];
foreach ($map as $g => $name) if (!isset($cats[$name])) $missing[] = "{$wdName[$g]}→{$name}";
if ($missing) exit('對映目標分類不存在:' . implode('、', array_unique($missing)) . "\n");

// ── 第一階段:逐書彙整 scheme='wdbook' 存證分類(以 code=id)──
$byBook = []; // book_id => ['source'=>?, 'codes'=>[wd_id,...]]
$sql = "SELECT b.book_id, b.source, s.code, s.label
          FROM book_subjects bs
          JOIN subjects s ON s.subject_id = bs.subject_id AND s.scheme = 'wdbook'
          JOIN books    b ON b.book_id    = bs.book_id";
foreach ($pdo->query($sql) as $r) {
    $bid = (int) $r['book_id'];
    if (!isset($byBook[$bid])) {
        if ($limit && count($byBook) >= $limit) continue;
        $byBook[$bid] = ['source' => $r['source'], 'codes' => []];
    }
    $byBook[$bid]['codes'][(string) $r['code']] = $r['label'];
}
if (!$byBook) exit("找不到 scheme='wdbook' 的書(請先跑 import.php --source=wdbook)\n");

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

$stats = []; $labelStats = []; $unmapped = []; $wdOnly = 0; $merged = 0; $multi = 0; $downs = 0; $done = 0;

if (!$dry) $pdo->beginTransaction();
foreach ($byBook as $bid => $d) {
    $isWdOnly = ($d['source'] === 'wdbook');
    $isWdOnly ? $wdOnly++ : $merged++;

    $codes = array_keys($d['codes']);
    usort($codes, fn($a, $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));

    $mapped = [];
    $anyUnpub = false;
    foreach ($codes as $c) {
        $disp = $wdName[$c] ?? ($d['codes'][$c] ?: $c);
        $labelStats[$disp] = ($labelStats[$disp] ?? 0) + 1;
        if (!isset($map[$c])) { $unmapped[$disp] = ($unmapped[$disp] ?? 0) + 1; continue; }
        if ($unpub[$c]) $anyUnpub = true;
        if (!in_array($map[$c], $mapped, true)) $mapped[] = $map[$c];
    }
    $willDown = $isWdOnly && $anyUnpub;
    if ($willDown) $downs++;

    if (!$mapped) {
        if ($willDown && !$dry) $downPub->execute([':bid' => $bid]);
        continue; // 無對映 → 分類交 classify 關鍵字回填
    }
    $primary = $mapped[0];
    if (count($mapped) >= 2) $multi++;
    $stats[$primary] = ($stats[$primary] ?? 0) + 1;

    if ($dry) continue;

    $replace = $isWdOnly || $overrideAll;
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
echo "\n== 微讀書歸類 ==\n";
echo '共 ' . count($byBook) . " 本(wdbook-only {$wdOnly}、跨站合併 {$merged});"
   . "多分類(>=2 站內類):{$multi};非書下架:{$downs}\n";
echo "\n== 微讀官方分類命中(書數)==\n";
foreach ($labelStats as $g => $n) echo sprintf("  %-28s %6d\n", $g, $n);
if ($unmapped) {
    arsort($unmapped);
    echo "\n== 未對映分類(已略過;如需歸類請補進 wdbook_category_map)==\n";
    foreach ($unmapped as $g => $n) echo sprintf("  %-28s %6d\n", $g, $n);
}
echo "\n== 對映後 primary 站內分類分布 ==\n";
foreach ($stats as $c => $n) echo sprintf("  %-8s %6d\n", $c, $n);

echo "\n" . ($dry ? '[dry-run 未寫入]' : '完成寫入') . "\n";
echo $dry
    ? "確認分布合理後,拿掉 --dry-run 再跑一次即寫入。\n"
    : "驗證(Navicat):\n"
    . "  SELECT COUNT(*) FROM subjects WHERE scheme='wdbook';\n"
    . "  SELECT c.name, COUNT(*) FROM books b JOIN categories c ON c.category_id=b.category_id\n"
    . "    JOIN editions e ON e.book_id=b.book_id AND e.source='wdbook'\n"
    . "    WHERE b.is_published=1 GROUP BY c.name ORDER BY 2 DESC;\n";
