<?php
declare(strict_types=1);

/**
 * 套用突破機構(btproduct)官方分類 → 站內瀏覽分類(2026-09-05)
 *
 * 第十六來源、海外第一批第 2 站。改自 apply_tiendao_categories.php,主要差異有三:
 *
 *  1. **對映吃兩軸**:主題分類 cat=(code=6 位數字)與系列 ser=(code='S'+數字)
 *     都在 btproduct_category_map 裡,靠 code 前綴分流。
 *     為什麼系列也要吃:全站 583 本裡有 11 本**只有系列、沒有主題分類**
 *     (站方資料本身沒填,9/5 逐本查證過)。不吃系列的話它們只能靠關鍵字猜測。
 *     sort_order 讓主題分類(10-99)一律排在系列(500+)之前 → primary 仍由主題決定。
 *
 *  2. **單書覆蓋 btproduct_book_override**:站方「宗教」類只有 16 本卻混了四種書
 *     (聖經人物 7、神學 2、靈修 4、見證 3),整類對到任何一類都會錯一半以上。
 *     覆蓋在對映算完之後套用 —— 內容面判斷優先於機械對映。
 *     覆蓋表以 **pid** 為鍵,pid 來自 editions.source_url 的 `id=` 尾碼。
 *
 *     ★ 2026-09-08 修:覆蓋原本被「跨站合併書不改 primary」的規則擋住,對那 13 本
 *     合併書完全無效。實測 pid 本來就正確的兩筆:「賜我眾山的力量」目標靈修、實際
 *     教會復興;「凡事謝恩」目標靈修、實際社會 —— 都沒套上。單書覆蓋的存在理由
 *     就是要蓋過機械規則(見本檔第 17 行),不該被合併保護擋住。
 *     故改為:**命中覆蓋表且有指定分類時,強制改寫 books.category_id**;
 *     但**不刪別站貢獻的 cat 標籤**(那是 --override-all 的行為),只改 primary
 *     並追加自己的標籤 —— 多重呈現鐵律:不做破壞其他來源資料的清洗。
 *
 *  3. **下架範圍小**:抓取範圍只有 /tc/book/(禮品區整區不抓),所以本站沒有
 *     「非書混進來」的問題。unpublish 只用於教科書(熊哥 9/5 裁示)與站方測試資料。
 *
 * 分類雙軌(沿 7/31 以琳決議):
 *   軌一(已完成於 import):站方分類由 tools/import.php 寫入 subjects(scheme='btproduct')
 *     + book_subjects 永久存證;
 *   軌二(本工具):讀軌一的存證,依對映表換算站內瀏覽分類
 *     (books.category_id + book_subjects scheme='cat')。
 *
 * 重點(同 apply_tiendao_categories.php):
 *   - internal_name 可 NULL(=僅存證/純下架);查表一律 array_key_exists
 *     (8/17 教訓:isset 對 NULL 值回 false,下架列會被誤判為未對映)。
 *   - btproduct-only 的書才下架;跨站合併的書不動 is_published(別站可能還有貨)。
 *
 * 先決條件:先跑 database/migrations/2026-09-05_btproduct_category_map.sql。
 *
 * 用法(主機 CLI):
 *   php tools/apply_btproduct_categories.php --dry-run      # 只統計不寫入
 *   php tools/apply_btproduct_categories.php                # 寫入
 *   php tools/apply_btproduct_categories.php --override-all # 合併書也取代 primary(慎用)
 *   ※ 單書覆蓋表命中的書一律強制改 primary,不需要也不應該為此加 --override-all。
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

// ── 對映表(鍵=bt_code;cat 為數字、ser 為 S+數字)──────────
$map = []; $unpub = []; $order = []; $srcName = [];
foreach ($pdo->query('SELECT bt_code, bt_name, internal_name, unpublish, sort_order FROM btproduct_category_map')->fetchAll() as $r) {
    $map[$r['bt_code']]     = $r['internal_name'];
    $unpub[$r['bt_code']]   = (bool) $r['unpublish'];
    $order[$r['bt_code']]   = (int) $r['sort_order'];
    $srcName[$r['bt_code']] = $r['bt_name'];
}
if (!$map) exit("btproduct_category_map 為空(請先跑 2026-09-05_btproduct_category_map.sql)\n");
$missing = [];
foreach ($map as $g => $name) {
    if ($name !== null && $name !== '' && !isset($cats[$name])) $missing[] = "{$srcName[$g]}→{$name}";
}
if ($missing) exit('對映目標分類不存在:' . implode('、', array_unique($missing)) . "\n");

// ── 單書覆蓋表(鍵=pid)────────────────────────────────────
$ovName = []; $ovUnpub = []; $ovTitle = [];
foreach ($pdo->query('SELECT pid, title, internal_name, unpublish FROM btproduct_book_override')->fetchAll() as $r) {
    $ovName[(string) $r['pid']]  = $r['internal_name'];
    $ovUnpub[(string) $r['pid']] = (bool) $r['unpublish'];
    $ovTitle[(string) $r['pid']] = $r['title'];
}
$ovMissing = [];
foreach ($ovName as $p => $name) {
    if ($name !== null && $name !== '' && !isset($cats[$name])) $ovMissing[] = "{$ovTitle[$p]}→{$name}";
}
if ($ovMissing) exit('覆蓋表目標分類不存在:' . implode('、', array_unique($ovMissing)) . "\n");

// ── book_id ↔ pid(pid 藏在 source_url 的 id= 尾碼)────────
// 例:https://www.btproduct.com/tc/book/product.php?id=57154298 → 57154298
$pidOf = [];
$sql = "SELECT b.book_id, SUBSTRING_INDEX(e.source_url, 'id=', -1) AS pid
          FROM editions e
          JOIN books b ON b.book_id = e.book_id
         WHERE e.source = 'btproduct' AND e.source_url IS NOT NULL";
foreach ($pdo->query($sql) as $r) {
    $pidOf[(int) $r['book_id']] = (string) $r['pid'];
}

// ── 第一階段:逐書彙整 scheme='btproduct' 存證分類 ─────────────
$byBook = []; // book_id => ['source'=>?, 'codes'=>[code=>label]]
$sql = "SELECT b.book_id, b.source, s.code, s.label
          FROM book_subjects bs
          JOIN subjects s ON s.subject_id = bs.subject_id AND s.scheme = 'btproduct'
          JOIN books    b ON b.book_id    = bs.book_id";
foreach ($pdo->query($sql) as $r) {
    $bid = (int) $r['book_id'];
    if (!isset($byBook[$bid])) {
        if ($limit && count($byBook) >= $limit) continue;
        $byBook[$bid] = ['source' => $r['source'], 'codes' => []];
    }
    $byBook[$bid]['codes'][(string) $r['code']] = $r['label'];
}
// 覆蓋表裡的書可能完全沒有存證分類(那 8 本孤兒書就是),也要納入處理
foreach ($pidOf as $bid => $pid) {
    // ★ array_key_exists 而非 isset:測試資料那三筆的 internal_name 是 NULL,
    //   isset 對 NULL 回 false → 它們不會被納入 byBook、也就永遠不會被下架。
    //   這正是檔頭第 30 行自己寫的 8/17 衛理教訓,寫的時候還是踩了一次。
    if (array_key_exists($pid, $ovName) && !isset($byBook[$bid])) {
        $byBook[$bid] = ['source' => null, 'codes' => []];
    }
}
if (!$byBook) exit("找不到 scheme='btproduct' 的書(請先跑 import.php --source=btproduct)\n");

// source 欄位對「只有覆蓋、沒有存證」那批是 NULL,補查一次
$needSrc = array_keys(array_filter($byBook, fn($d) => $d['source'] === null));
if ($needSrc) {
    $in = implode(',', array_fill(0, count($needSrc), '?'));
    $st = $pdo->prepare("SELECT book_id, source FROM books WHERE book_id IN ($in)");
    $st->execute($needSrc);
    foreach ($st as $r) $byBook[(int) $r['book_id']]['source'] = $r['source'];
}

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
$srcOnly = 0; $merged = 0; $multi = 0; $downs = 0; $done = 0;
$fromSer = 0; $overridden = 0; $noMap = 0; $forcedPrim = 0;

if (!$dry) $pdo->beginTransaction();
foreach ($byBook as $bid => $d) {
    $isSrcOnly = ($d['source'] === 'btproduct');
    $isSrcOnly ? $srcOnly++ : $merged++;

    $codes = array_keys($d['codes']);
    usort($codes, fn($a, $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));

    $mapped = [];
    $anyUnpub = false;
    $hasCat = false;             // 是否有主題分類(非 S 開頭)命中對映
    foreach ($codes as $c) {
        $disp = $srcName[$c] ?? ($d['codes'][$c] ?: $c);
        $labelStats[$disp] = ($labelStats[$disp] ?? 0) + 1;
        // array_key_exists 而非 isset:internal_name 為 NULL 的純下架/存證列
        // isset 會誤判為未對映而跳過下架(8/17 衛理教訓)
        if (!array_key_exists($c, $map)) { $unmapped[$disp] = ($unmapped[$disp] ?? 0) + 1; continue; }
        if ($unpub[$c]) $anyUnpub = true;
        $target = $map[$c];
        if ($target !== null && $target !== '' && !in_array($target, $mapped, true)) {
            $mapped[] = $target;
            // ★ 不可寫 $c[0] —— PHP 會把「純數字字串」陣列鍵自動轉成 int,
            //   cat 的 6 位數字 code 取出來是 int,對 int 取 offset 會噴
            //   "Trying to access array offset on int"(每本書一行,log 被洗版)。
            //   先轉字串再比。
            if (!str_starts_with((string) $c, 'S')) $hasCat = true;
        }
    }
    if ($mapped && !$hasCat) $fromSer++;   // 這本書的分類完全靠系列軸得來

    // ── 單書覆蓋(內容面判斷優先於機械對映)──
    $pid = $pidOf[$bid] ?? null;
    $hadOverride = false;   // 命中覆蓋表**且有指定分類**才算(純下架列 internal_name=NULL 不算)
    if ($pid !== null && array_key_exists($pid, $ovName)) {
        $overridden++;
        if ($ovUnpub[$pid]) $anyUnpub = true;
        $ovTarget = $ovName[$pid];
        // 覆蓋值放到最前面當 primary;原對映結果保留為次分類(多重呈現,不丟資訊)
        if ($ovTarget !== null && $ovTarget !== '') {
            $mapped      = array_values(array_unique(array_merge([$ovTarget], $mapped)));
            $hadOverride = true;
        }
    }

    $willDown = $isSrcOnly && $anyUnpub;
    if ($willDown) $downs++;

    if (!$mapped) {
        $noMap++;
        if ($willDown && !$dry) $downPub->execute([':bid' => $bid]);
        continue; // 無對映 → 分類交 classify 關鍵字回填
    }
    $primary = $mapped[0];
    if (count($mapped) >= 2) $multi++;
    $stats[$primary] = ($stats[$primary] ?? 0) + 1;

    // $replace   = 整組取代(先清空本書的 cat 標籤再重寫)—— btproduct-only 或 --override-all
    // $forcePrim = 2026-09-08 新增:合併書命中單書覆蓋 → 只改 primary,**不清空**別站的標籤
    $replace   = $isSrcOnly || $overrideAll;
    $forcePrim = !$replace && $hadOverride;
    if ($forcePrim) $forcedPrim++;

    if ($dry) continue;

    if ($replace) $delCat->execute([':b' => $bid]);
    foreach ($mapped as $i => $name) {
        $insBS->execute([':b' => $bid, ':s' => $catSid($name), ':w' => $i === 0 ? 10 : 5]);
    }
    if ($replace || $forcePrim) $updBook->execute([':cid' => $cats[$primary], ':bid' => $bid]);
    if ($willDown) $downPub->execute([':bid' => $bid]);

    if (++$done % 500 === 0) { $pdo->commit(); $pdo->beginTransaction(); echo "  已處理 {$done}…\n"; }
}
if (!$dry && $pdo->inTransaction()) $pdo->commit();

// ── 報表 ───────────────────────────────────────────────────
arsort($stats); arsort($labelStats);
echo "\n== 突破機構書歸類 ==\n";
echo '共 ' . count($byBook) . " 本(btproduct-only {$srcOnly}、跨站合併 {$merged});"
   . "多分類(>=2 站內類):{$multi}\n";
echo "單書覆蓋命中:{$overridden}(其中合併書強制改 primary:{$forcedPrim});"
   . "分類完全靠系列軸得來:{$fromSer};"
   . "仍無對映(交 classify 關鍵字):{$noMap};下架:{$downs}\n";
echo "\n== 突破官方分類命中(書數)==\n";
foreach ($labelStats as $g => $n) echo sprintf("  %-34s %6d\n", $g, $n);
if ($unmapped) {
    arsort($unmapped);
    echo "\n== 未對映分類(已略過;如需歸類請補進 btproduct_category_map)==\n";
    foreach ($unmapped as $g => $n) echo sprintf("  %-34s %6d\n", $g, $n);
}
echo "\n== 對映後 primary 站內分類分布 ==\n";
foreach ($stats as $c => $n) echo sprintf("  %-10s %6d\n", $c, $n);

echo "\n" . ($dry ? '[dry-run 未寫入]' : '完成寫入') . "\n";
echo $dry
    ? "確認分布合理後,拿掉 --dry-run 再跑一次即寫入。\n"
    : "驗證(Navicat):\n"
    . "  SELECT LEFT(code,1) AS kind, COUNT(*) FROM subjects WHERE scheme='btproduct' GROUP BY 1;\n"
    . "  SELECT c.name, COUNT(*) FROM books b JOIN categories c ON c.category_id=b.category_id\n"
    . "    JOIN editions e ON e.book_id=b.book_id AND e.source='btproduct'\n"
    . "    WHERE b.is_published=1 GROUP BY c.name ORDER BY 2 DESC;\n"
    . "  SELECT COUNT(*) FROM books b JOIN editions e ON e.book_id=b.book_id\n"
    . "    WHERE e.source='btproduct' AND b.is_published=0;   -- 預期 19\n"
    . "  -- 19 = btproduct-only 的教科書 16 + 站方測試資料 3。\n"
    . "  -- 教科書共 18 本,但跨站合併的那 2 本不下架 —— is_published 在**作品層**,\n"
    . "  -- 下架會把別站的同一本書一起藏掉(沿天恩/衛理規則)。\n"
    . "  -- ★ 2026-09-08 新增:單書覆蓋是否真的生效,預期回傳 0 列\n"
    . "  SELECT o.pid, o.title, o.internal_name AS want, c.name AS got\n"
    . "    FROM btproduct_book_override o\n"
    . "    JOIN editions e ON e.source='btproduct'\n"
    . "     AND e.source_url = CONCAT('https://www.btproduct.com/tc/book/product.php?id=', o.pid)\n"
    . "    JOIN books b ON b.book_id = e.book_id\n"
    . "    LEFT JOIN categories c ON c.category_id = b.category_id\n"
    . "   WHERE o.internal_name IS NOT NULL\n"
    . "     AND (c.name IS NULL OR c.name <> o.internal_name);\n";
