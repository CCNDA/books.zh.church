<?php
declare(strict_types=1);

/**
 * 套用麥種傳道會(akow)站方分類 → 站內瀏覽分類(2026-09-14)
 *
 * 分類雙軌(沿 7/31 以琳決議,兩軌並存互可查照):
 *   軌一(已完成於 import):站方分類由 tools/import.php 寫入 subjects(scheme='akow')
 *     + book_subjects 永久存證;
 *   軌二(本工具):讀軌一的存證,依 akow_category_map 換算站內瀏覽分類
 *     (books.category_id + book_subjects scheme='cat')。
 *
 * ★ 本站與其他來源不同的一點:**對映表的鍵有兩種**(熊哥 2026-09-13 決議「兩層並用」)
 *   - 數字碼(如 48)= WooCommerce product_cat,粗,143/144 本有值,sort_order 5xx
 *   - `sub:xxx` = 爬蟲從商品簡介抽出的站方細分類(如 sub:聖經論叢／詩篇),
 *     精確但只有 42/144 本有,sort_order 110 → **排序在前,會贏過 product_cat**
 *   排序靠 sort_order,所以有細分類的書以細分類決定 primary,沒有的自動落回 product_cat。
 *   ★ 細分類有簡繁兩種寫法(神學類／教義 與 神学类／教义),對映表兩種都登錄,
 *     不做任何字形轉換(utf8mb4_unicode_ci 不把簡繁視為相等,少登一種就漏)。
 *
 * 其餘規則同 apply_pctpress_categories.php:
 *   - internal_name 可 NULL(=僅存證不歸類,本站的五個「書系」即是,已由爬蟲寫入 series 欄);
 *     查表一律 array_key_exists(8/17 教訓:isset 對 NULL 值回 false,會被誤判為未對映)。
 *   - unpublish:本站全為 0(144 本全是書,沒有影音禮品),機制保留以備站方日後新增品類。
 *   - 預設只有 akow-only 的書會取代 primary;跨站合併的書(9/14 dry-run 實測 44 本)
 *     只加掛 book_subjects,不動既有 primary —— 別站的分類多半比麥種自己的粗分類準。
 *
 * 先決條件:先跑 database/migrations/2026-09-14_akow_category_map.sql(46 列)
 *           並確認該檔末尾的驗證查詢回 0 列(internal_name 必須真的存在於 categories)
 *
 * 用法(主機 CLI):
 *   php tools/apply_akow_categories.php --dry-run      # 只統計不寫入
 *   php tools/apply_akow_categories.php                # 寫入
 *   php tools/apply_akow_categories.php --override-all # 合併書也取代 primary(慎用)
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

// ── 麥種對映表(鍵=akow_code:數字=product_cat、sub:xxx=站方細分類)──
$map = []; $unpub = []; $order = []; $srcName = [];
try {
    $mapRows = $pdo->query('SELECT akow_code, akow_path, internal_name, unpublish, sort_order
                              FROM akow_category_map')->fetchAll();
} catch (PDOException $e) {
    // 1146 = 表不存在。原本會噴 PDOException 堆疊,看不出是「還沒跑 migration」。
    if (str_contains($e->getMessage(), '1146')) {
        exit("akow_category_map 不存在 —— 請先用 Navicat 執行\n"
           . "  database/migrations/2026-09-14_akow_category_map.sql(46 列)\n"
           . "並確認該檔末尾的驗證查詢回 0 列,再跑本工具。\n");
    }
    throw $e;
}
foreach ($mapRows as $r) {
    $map[$r['akow_code']]     = $r['internal_name'];
    $unpub[$r['akow_code']]   = (bool) $r['unpublish'];
    $order[$r['akow_code']]   = (int) $r['sort_order'];
    $srcName[$r['akow_code']] = $r['akow_path'];
}
if (!$map) exit("akow_category_map 為空(請先跑 2026-09-14_akow_category_map.sql)\n");
$missing = [];
foreach ($map as $g => $name) {
    if ($name !== null && $name !== '' && !isset($cats[$name])) $missing[] = "{$srcName[$g]}→{$name}";
}
// 這一關就是 9/13「六個站內分類名猜錯五個」的防線:寧可當場中止,也不要靜默不歸類
if ($missing) exit('對映目標分類不存在:' . implode('、', array_unique($missing)) . "\n");

// ── 第一階段:逐書彙整 scheme='akow' 存證分類 ──────────────────
$byBook = []; // book_id => ['source'=>?, 'codes'=>[code=>label]]
$sql = "SELECT b.book_id, b.source, s.code, s.label
          FROM book_subjects bs
          JOIN subjects s ON s.subject_id = bs.subject_id AND s.scheme = 'akow'
          JOIN books    b ON b.book_id    = bs.book_id";
foreach ($pdo->query($sql) as $r) {
    $bid = (int) $r['book_id'];
    if (!isset($byBook[$bid])) {
        if ($limit && count($byBook) >= $limit) continue;
        $byBook[$bid] = ['source' => $r['source'], 'codes' => []];
    }
    $byBook[$bid]['codes'][(string) $r['code']] = $r['label'];
}
if (!$byBook) exit("找不到 scheme='akow' 的書(請先跑 import.php --source=akow)\n");

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

$stats = []; $labelStats = []; $unmapped = [];
$srcOnly = 0; $merged = 0; $multi = 0; $downs = 0; $done = 0; $noTopic = 0;
$byFine  = 0; $byCoarse = 0;   // primary 是細分類決定的 vs product_cat 決定的

if (!$dry) $pdo->beginTransaction();
foreach ($byBook as $bid => $d) {
    $isSrcOnly = ($d['source'] === 'akow');
    $isSrcOnly ? $srcOnly++ : $merged++;

    // ★ array_keys() 會把 '48' 這種純數字鍵轉回 int(PHP 的陣列鍵規則),
    //   而本站的鍵是混合型(數字碼 + 'sub:xxx')→ 一律轉回字串再用,
    //   否則 str_starts_with() 收到 int 會 TypeError。
    //   (以字串查 $map['48'] 仍能取到 int 鍵 48,PHP 會自動轉換,查表不受影響。)
    $codes = array_map('strval', array_keys($d['codes']));
    // sort_order 小者優先 → 細分類(110)排在 product_cat(5xx)之前
    usort($codes, fn($a, $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));

    $mapped = [];
    $anyUnpub = false;
    $primaryFromFine = false;
    foreach ($codes as $c) {
        $disp = $srcName[$c] ?? ($d['codes'][$c] ?: $c);
        $labelStats[$disp] = ($labelStats[$disp] ?? 0) + 1;
        // array_key_exists 而非 isset:internal_name 為 NULL 的書系/存證列
        // isset 會誤判為未對映(8/17 衛理教訓)
        if (!array_key_exists($c, $map)) { $unmapped[$disp] = ($unmapped[$disp] ?? 0) + 1; continue; }
        if ($unpub[$c]) $anyUnpub = true;
        $target = $map[$c];
        if ($target !== null && $target !== '' && !in_array($target, $mapped, true)) {
            if (!$mapped) $primaryFromFine = str_starts_with($c, 'sub:');
            $mapped[] = $target;
        }
    }
    $willDown = $isSrcOnly && $anyUnpub;
    if ($willDown) $downs++;

    if (!$mapped) {
        $noTopic++;
        if ($willDown && !$dry) $downPub->execute([':bid' => $bid]);
        continue; // 無對映 → 分類交 classify 關鍵字回填
    }
    $primary = $mapped[0];
    $primaryFromFine ? $byFine++ : $byCoarse++;
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
echo "\n== 麥種傳道會書歸類 ==\n";
echo '共 ' . count($byBook) . " 本(akow-only {$srcOnly}、跨站合併 {$merged});"
   . "多分類(>=2 站內類):{$multi};非書下架:{$downs};"
   . "無主題分類(交 classify):{$noTopic}\n";
echo "primary 由**站方細分類**決定:{$byFine} 本;由 product_cat 決定:{$byCoarse} 本\n";
echo "  (9/14 全量實測:42 本有細分類,故 byFine 應在 42 上下;明顯偏低代表細分類沒進 subjects)\n";

echo "\n== 站方分類命中(書數;僅列前 40)==\n";
$i = 0;
foreach ($labelStats as $g => $n) {
    echo sprintf("  %-36s %6d\n", $g, $n);
    if (++$i >= 40) { echo '  …(其餘 ' . (count($labelStats) - 40) . " 類略)\n"; break; }
}
if ($unmapped) {
    arsort($unmapped);
    echo "\n== 未對映分類(已略過;站方新增分類請補進 akow_category_map)==\n";
    foreach ($unmapped as $g => $n) echo sprintf("  %-36s %6d\n", $g, $n);
}
echo "\n== 對映後 primary 站內分類分布 ==\n";
foreach ($stats as $c => $n) echo sprintf("  %-10s %6d\n", $c, $n);

echo "\n" . ($dry ? '[dry-run 未寫入]' : '完成寫入') . "\n";
echo $dry
    ? "確認分布合理後,拿掉 --dry-run 再跑一次即寫入。\n"
    : "驗證(Navicat):\n"
    . "  SELECT COUNT(*) FROM subjects WHERE scheme='akow';\n"
    . "  SELECT c.name, COUNT(*) FROM books b JOIN categories c ON c.category_id=b.category_id\n"
    . "    JOIN editions e ON e.book_id=b.book_id AND e.source='akow'\n"
    . "    WHERE b.is_published=1 GROUP BY c.name ORDER BY 2 DESC;\n";
