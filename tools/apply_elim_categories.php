<?php
declare(strict_types=1);

/**
 * 套用以琳書房(elim)官方分類 → 站內瀏覽分類(2026-07-31)
 *
 * 分類雙軌(熊哥 7/31 要求,兩軌並存互可查照):
 *   軌一(已完成於 import):以琳官網原始分類完整路徑(一書多分類)由
 *     tools/import.php 寫入 subjects(scheme='elim') + book_subjects,永久存證。
 *   軌二(本工具):讀軌一的存證,依 elim_category_map 換算站內瀏覽分類
 *     (books.category_id + book_subjects scheme='cat')。
 *
 * 資料來源即 DB 本身(scheme='elim' 的 book_subjects),不需外部對照檔。
 *
 * 寫入規則(比照 apply_logos_categories.php):
 *   - elim-only 書(books.source='elim'):取代 scheme='cat' 舊分類,
 *     設 books.category_id = primary(依以琳選單順序取最前對映)。
 *   - 跨站合併書(source=campus/logos,同 ISBN/書名作者已在庫):不覆蓋既有
 *     primary,只追加對映分類(兩邊皆可瀏覽);--override-all 才一併取代。
 *   - unpublish:elim-only 且**所有**歸屬分類於對映表皆 unpublish=1(如
 *     書籍/日誌月曆)→ is_published=0(沿 7/30 非書下架決議,留庫可還原)。
 *
 * 先決條件:先跑 database/migrations/2026-07-31_elim_category_map.sql。
 *
 * 用法(主機 CLI):
 *   php tools/apply_elim_categories.php --dry-run      # 只統計不寫入
 *   php tools/apply_elim_categories.php                # 寫入
 *   php tools/apply_elim_categories.php --override-all # 合併書也取代 primary(慎用)
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

// primary 優先序:子分類優先(依以琳選單順序),父分類(書籍/聖經)壓最後
// ——同掛父+子的書應取較具體的子分類(與 elim_crawler.py CAT_ORDER 一致)
$ELIM_ORDER_LIST = ['書籍/神學研經','書籍/教會事奉','書籍/禱告靈修','書籍/醫治輔導',
                    '書籍/福音見證','書籍/生活家庭','書籍/童書系列','書籍/教材系列','書籍/休閒藝文',
                    '書籍/日誌月曆','書籍/外文書'];
$pathOrder = function (string $p) use ($ELIM_ORDER_LIST): int {
    $i = array_search($p, $ELIM_ORDER_LIST, true);
    if ($i !== false) return $i;
    if (str_starts_with($p, '聖經/')) return 50;  // 聖經各譯本
    if ($p === '聖經') return 800;
    if ($p === '書籍') return 900;
    return 999;
};

$pdo = db();
$pdo->exec('SET NAMES utf8mb4');

// ── 站內分類 name→id/code ──────────────────────────────────
$cats = []; $catCode = [];
foreach ($pdo->query('SELECT category_id, code, name FROM categories')->fetchAll() as $r) {
    $cats[$r['name']]    = (int) $r['category_id'];
    $catCode[$r['name']] = $r['code'];
}

// ── elim 對映表 ────────────────────────────────────────────
$map = []; $unpub = [];
foreach ($pdo->query('SELECT elim_path, internal_name, unpublish FROM elim_category_map')->fetchAll() as $r) {
    $map[$r['elim_path']]   = $r['internal_name'];
    $unpub[$r['elim_path']] = (bool) $r['unpublish'];
}
if (!$map) exit("elim_category_map 為空(請先跑 2026-07-31_elim_category_map.sql)\n");
$missing = [];
foreach ($map as $p => $name) if (!isset($cats[$name])) $missing[] = "$p→$name";
if ($missing) exit('對映目標分類不存在:' . implode('、', $missing) . "\n");

// ── 第一階段:逐書彙整 scheme='elim' 存證路徑 ───────────────
$byBook = []; // book_id => ['source'=>?, 'paths'=>[path,...]]
$sql = "SELECT b.book_id, b.source, s.label
          FROM book_subjects bs
          JOIN subjects s ON s.subject_id = bs.subject_id AND s.scheme = 'elim'
          JOIN books    b ON b.book_id    = bs.book_id";
foreach ($pdo->query($sql) as $r) {
    $bid = (int) $r['book_id'];
    if (!isset($byBook[$bid])) {
        if ($limit && count($byBook) >= $limit) continue;
        $byBook[$bid] = ['source' => $r['source'], 'paths' => []];
    }
    $byBook[$bid]['paths'][$r['label']] = 1;
}
if (!$byBook) exit("找不到 scheme='elim' 的書(請先跑 import.php --source=elim)\n");

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

$stats = []; $pathStats = []; $elimOnly = 0; $merged = 0; $multi = 0; $downs = 0; $done = 0;

if (!$dry) $pdo->beginTransaction();
foreach ($byBook as $bid => $d) {
    $isElimOnly = ($d['source'] === 'elim');
    $isElimOnly ? $elimOnly++ : $merged++;

    $paths = array_keys($d['paths']);
    usort($paths, fn($a, $b) => $pathOrder($a) <=> $pathOrder($b));

    $mapped = [];
    $allUnpub = true;
    $specific = 0; // 非父分類的歸屬數(父分類「書籍/聖經」太籠統,不作下架判斷依據)
    foreach ($paths as $p) {
        $pathStats[$p] = ($pathStats[$p] ?? 0) + 1;
        $isParent = ($p === '書籍' || $p === '聖經');
        if (!$isParent) {
            $specific++;
            if (!isset($map[$p]) || !$unpub[$p]) $allUnpub = false;
        }
        if (isset($map[$p]) && !in_array($map[$p], $mapped, true)) $mapped[] = $map[$p];
    }
    if ($specific === 0) $allUnpub = false; // 只掛父分類 → 不下架
    if (!$mapped) continue;
    $primary = $mapped[0];
    if (count($mapped) >= 2) $multi++;
    $stats[$primary] = ($stats[$primary] ?? 0) + 1;
    $willDown = $isElimOnly && $allUnpub;
    if ($willDown) $downs++;

    if ($dry) continue;

    $replace = $isElimOnly || $overrideAll;
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
arsort($stats); arsort($pathStats);
echo "\n== 以琳書歸類 ==\n";
echo '共 ' . count($byBook) . " 本(elim-only {$elimOnly}、跨站合併 {$merged});"
   . "多分類(>=2 站內類):{$multi};非書下架:{$downs}\n";
echo "\n== 以琳官方分類命中(書數,含跨類重複)==\n";
foreach ($pathStats as $p => $n) echo sprintf("  %-16s %6d\n", $p, $n);
echo "\n== 對映後 primary 站內分類分布 ==\n";
foreach ($stats as $c => $n) echo sprintf("  %-8s %6d\n", $c, $n);

echo "\n" . ($dry ? '[dry-run 未寫入]' : '完成寫入') . "\n";
echo $dry
    ? "確認分布合理後,拿掉 --dry-run 再跑一次即寫入。\n"
    : "驗證(Navicat):\n"
    . "  SELECT COUNT(*) FROM subjects WHERE scheme='elim';\n"
    . "  SELECT c.name, COUNT(*) FROM books b JOIN categories c ON c.category_id=b.category_id\n"
    . "    JOIN editions e ON e.book_id=b.book_id AND e.source='elim'\n"
    . "    WHERE b.is_published=1 GROUP BY c.name ORDER BY 2 DESC;\n";
