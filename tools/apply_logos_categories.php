<?php
declare(strict_types=1);

/**
 * 套用基道(logos)官方分類 —— 以官網權威歸類重判基道書的類別。
 *
 * 輸入:crawler/data/logos_code_categories.jsonl(由 crawler/logos_categories.py 產生)
 *   每列:{"code": "...", "paths": ["聖經/硬面聖經", ...], "tops": ["聖經", ...]}
 *
 * 對照商品:code → identifiers(id_type='STORE') → editions(source='logos') → books。
 * 一本書可能有多個 logos 商品碼(不同版本/格式),故先**逐書彙整**所有 code 的
 * 分類再一次寫入(避免重複刪改)。primary 依官網選單順序(TOPICAL)取最前者。
 *
 * 對每本「基道商品」:
 *   1. 原生存證:每個 path 寫 subjects(scheme='logos', code=主分類, label=完整路徑)
 *      + book_subjects(INSERT IGNORE,永久保留官網原始權威分類,可作日後細分依據)。
 *   2. 瀏覽分類:依 logos_category_map 把「主分類」換算成站內 categories.name。
 *      - logos-only 書(books.source='logos'):**取代**關鍵字猜測——刪掉舊的
 *        scheme='cat' book_subjects,改寫官網對映分類,並設 books.category_id=primary。
 *      - 跨站合併書(books.source='campus',同 ISBN 已有校園分類):**不覆蓋**校園
 *        primary 與既有 cat,只**追加**官網對映分類(INSERT IGNORE),兩邊皆可瀏覽。
 *      - --override-all:合併書也一併取代(慎用)。
 *
 * 先決條件:先跑 database/migrations/2026-07-17_categories_extend.sql(建齊擴充分類)
 *   與 2026-07-17_logos_category_map.sql(建對映表)。
 *
 * 用法(主機 CLI):
 *   php tools/apply_logos_categories.php --dry-run                 # 只統計不寫入
 *   php tools/apply_logos_categories.php                           # 寫入
 *   php tools/apply_logos_categories.php --override-all            # 合併書也取代 primary
 *   php tools/apply_logos_categories.php --file=/path/to.jsonl --limit=500 --dry-run
 * 冪等可重跑。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/api/lib/db.php';

$opt         = getopt('', ['dry-run', 'override-all', 'file::', 'limit::']);
$dry         = array_key_exists('dry-run', $opt);
$overrideAll = array_key_exists('override-all', $opt);
$limit       = (int) ($opt['limit'] ?? 0);
$file        = (string) ($opt['file'] ?? (dirname(__DIR__) . '/crawler/data/logos_code_categories.jsonl'));

if (!is_file($file)) exit("找不到對照檔:$file\n(請先於主機跑 crawler/logos_categories.py 產生)\n");

// 官網選單順序(決定 primary 優先序)
$TOPICAL = ['神學／教義','讀經／研經','聖經','信仰入門','教會歷史','靈修／禱告',
            '信徒生活','教會事工','分齡牧養','社會／倫理','哲學／宗教比較',
            '見證／傳記','文藝／勵志','童書','精選影音','其他'];
$TOP_ORDER = array_flip($TOPICAL);

$pdo = db();

// ── 載入站內分類 name→id / code ────────────────────────────
$cats = []; $catCode = [];
foreach ($pdo->query('SELECT category_id, code, name FROM categories')->fetchAll() as $r) {
    $cats[$r['name']]    = (int) $r['category_id'];
    $catCode[$r['name']] = $r['code'];
}

// ── 載入 logos 主分類 → 站內分類 對映 ─────────────────────
$map = [];
foreach ($pdo->query('SELECT logos_top, internal_name FROM logos_category_map')->fetchAll() as $r) {
    $map[$r['logos_top']] = $r['internal_name'];
}
if (!$map) exit("logos_category_map 為空(請先跑 2026-07-17_logos_category_map.sql)\n");
$missing = [];
foreach ($map as $top => $name) if (!isset($cats[$name])) $missing[] = "$top→$name";
if ($missing) exit("對映目標分類不存在(請先跑 categories_extend):" . implode('、', $missing) . "\n");

// ── 第一階段:逐書彙整所有商品碼的分類 ─────────────────────
$findBooks = $pdo->prepare(
    "SELECT DISTINCT b.book_id, b.source
       FROM identifiers i
       JOIN editions e ON e.edition_id = i.edition_id
       JOIN books    b ON b.book_id    = e.book_id
      WHERE i.id_type = 'STORE' AND i.id_value = :code AND e.source = 'logos'
        AND b.is_published = 1");

$byBook = [];   // book_id => ['source'=>?, 'paths'=>[path=>1], 'tops'=>[top=>1]]
$rowsSeen = 0; $unmatchedCodes = 0;
$fh = fopen($file, 'r');
while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $rec = json_decode($line, true);
    if (!is_array($rec) || empty($rec['code'])) continue;
    $rowsSeen++;
    if ($limit && $rowsSeen > $limit) { $rowsSeen--; break; }

    $paths = array_values(array_unique(array_map('strval', $rec['paths'] ?? [])));
    $tops  = array_values(array_unique(array_map('strval', $rec['tops'] ?? [])));
    if (!$paths) continue;

    $findBooks->execute([':code' => (string) $rec['code']]);
    $books = $findBooks->fetchAll();
    if (!$books) { $unmatchedCodes++; continue; }

    foreach ($books as $b) {
        $bid = (int) $b['book_id'];
        if (!isset($byBook[$bid])) $byBook[$bid] = ['source' => $b['source'], 'paths' => [], 'tops' => []];
        foreach ($paths as $p) $byBook[$bid]['paths'][$p] = 1;
        foreach ($tops  as $t) $byBook[$bid]['tops'][$t]  = 1;
    }
}
fclose($fh);

// ── 第二階段:每本書寫一次 ─────────────────────────────────
if (!$dry) {
    $upSubjLogos = $pdo->prepare("INSERT INTO subjects (scheme, code, label) VALUES ('logos', :c, :l)
                                  ON DUPLICATE KEY UPDATE subject_id = LAST_INSERT_ID(subject_id)");
    $upSubjCat   = $pdo->prepare("INSERT INTO subjects (scheme, code, label) VALUES ('cat', :c, :l)
                                  ON DUPLICATE KEY UPDATE subject_id = LAST_INSERT_ID(subject_id)");
    $insBS  = $pdo->prepare("INSERT INTO book_subjects (book_id, subject_id, weight) VALUES (:b, :s, :w)
                             ON DUPLICATE KEY UPDATE weight = VALUES(weight)");
    $delCat = $pdo->prepare("DELETE bs FROM book_subjects bs JOIN subjects s ON s.subject_id = bs.subject_id
                             WHERE bs.book_id = :b AND s.scheme = 'cat'");
    $updBook = $pdo->prepare('UPDATE books SET category_id = :cid WHERE book_id = :bid');
}
$catSubjId = [];
$catSid = function (string $name) use (&$catSubjId, &$upSubjCat, $catCode, $pdo) {
    if (isset($catSubjId[$name])) return $catSubjId[$name];
    $upSubjCat->execute([':c' => $catCode[$name] ?? null, ':l' => $name]);
    return $catSubjId[$name] = (int) $pdo->lastInsertId();
};

$stats = []; $topStats = []; $logosBooks = 0; $mergedBooks = 0; $multi = 0; $done = 0;

if (!$dry) $pdo->beginTransaction();
foreach ($byBook as $bid => $d) {
    $isLogosOnly = ($d['source'] === 'logos');
    $isLogosOnly ? $logosBooks++ : $mergedBooks++;

    // tops 依官網選單順序排序 → 對映 → 去重保序
    $tops = array_keys($d['tops']);
    usort($tops, fn($a, $b) => ($TOP_ORDER[$a] ?? 999) <=> ($TOP_ORDER[$b] ?? 999));
    $mapped = [];
    foreach ($tops as $t) {
        if (isset($map[$t]) && !in_array($map[$t], $mapped, true)) $mapped[] = $map[$t];
    }
    if (!$mapped) continue;
    $primary = $mapped[0];
    if (count($mapped) >= 2) $multi++;
    $stats[$primary] = ($stats[$primary] ?? 0) + 1;
    foreach ($tops as $t) $topStats[$t] = ($topStats[$t] ?? 0) + 1;

    if ($dry) continue;

    // 1. 原生存證 scheme='logos'
    foreach (array_keys($d['paths']) as $path) {
        $top = explode('/', $path, 2)[0];
        $upSubjLogos->execute([':c' => mb_substr($top, 0, 20), ':l' => mb_substr($path, 0, 150)]);
        $insBS->execute([':b' => $bid, ':s' => (int) $pdo->lastInsertId(), ':w' => 0]);
    }

    // 2. 瀏覽分類 scheme='cat'
    $replace = $isLogosOnly || $overrideAll;
    if ($replace) $delCat->execute([':b' => $bid]);
    foreach ($mapped as $i => $name) {
        $insBS->execute([':b' => $bid, ':s' => $catSid($name), ':w' => $i === 0 ? 10 : 5]);
    }
    if ($replace) $updBook->execute([':cid' => $cats[$primary], ':bid' => $bid]);

    if (++$done % 500 === 0) { $pdo->commit(); $pdo->beginTransaction(); echo "  已處理 $done…\n"; }
}
if (!$dry && $pdo->inTransaction()) $pdo->commit();

// ── 報表 ───────────────────────────────────────────────────
arsort($stats); arsort($topStats);
$books = count($byBook);
echo "\n== 對照檔處理 ==\n";
echo "讀取商品碼列:{$rowsSeen}" . ($limit ? "(--limit={$limit})" : '') . ";未對照商品碼:{$unmatchedCodes}\n";
echo "對照到書:共 {$books} 本(logos-only {$logosBooks}、跨站合併 {$mergedBooks});多分類(>=2 站內類):{$multi}\n";

echo "\n== logos 主分類命中(書數,含跨類重複)==\n";
foreach ($topStats as $t => $n) echo sprintf("  %-14s %6d\n", $t, $n);
echo "\n== 對映後 primary 站內分類分布 ==\n";
foreach ($stats as $c => $n) echo sprintf("  %-8s %6d\n", $c, $n);

echo "\n" . ($dry ? "[dry-run 未寫入] " : "完成寫入 ") . "共 {$books} 本基道書\n";
echo $dry
    ? "確認分布合理後,拿掉 --dry-run 再跑一次即寫入。\n"
    : "驗證(Navicat):\n"
    . "  SELECT COUNT(*) FROM subjects WHERE scheme='logos';\n"
    . "  SELECT c.name, COUNT(*) FROM books b JOIN categories c ON c.category_id=b.category_id\n"
    . "    WHERE b.is_published=1 GROUP BY c.name ORDER BY 2 DESC;\n";
