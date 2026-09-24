<?php
declare(strict_types=1);

/**
 * 套用浸信會出版社(國際)bappress 的站方分類 —— 以官網歸類決定站內瀏覽分類。
 *
 * 輸入:資料庫裡 import already 存證的 subjects(scheme='bappress', code=站方分類數字 id)。
 *       **不需要 jsonl** —— 分類在匯入時就寫進去了。
 * 對映:bappress_category_map(bappress_code → internal_name / unpublish / sort_order)
 *       由 database/migrations/2026-09-21_bappress_category_map.sql 建立(81 列)。
 *
 * 處置:
 *   1. primary 取 **sort_order 最小**的那個對映分類(對映表已把「聖經版本」「註釋」
 *      這類具體主題排在 400 區間、「信徒生活/生活教導」這類泛用大類排在 600)。
 *   2. bappress-only 的書(只有這一個來源)→ **取代**關鍵字猜測:
 *      刪掉舊的 scheme='cat' book_subjects,改寫對映分類,並設 books.category_id。
 *   3. 跨站合併書(還有別的來源)→ **只追加**,不覆蓋既有 primary 與 cat。
 *      這批佔絕大多數(3,491 本是 ISBN 合併進既有書的),覆蓋它們等於用浸信會的
 *      分類蓋掉校園/基道的既有歸類,那不是我們要的。
 *   4. unpublish=1 的分類(文具 77、影音 52)→ 下架。
 *      ★★ **只下架 bappress-only 的書。** 跨站合併書絕不能因為浸信會把某本書
 *         歸在「影音」就整本下架 —— 那本書在校園可能是正常書目,一下架
 *         連帶讓別站的購書連結也看不到,而且完全沒有徵兆。
 *         (天恩「非書任一命中下架」的慣例,前提也是單源書。)
 *
 * 先決條件:先跑 2026-09-21_bappress_category_map.sql,且該檔的驗證一要回 0 列
 *           (internal_name 打錯不會報錯,只會靜默不歸類)。
 *
 * 用法(主機 CLI):
 *   php tools/apply_bappress_categories.php --dry-run    # 只統計不寫入
 *   php tools/apply_bappress_categories.php              # 寫入
 * 冪等可重跑。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/api/lib/db.php';

$opt   = getopt('', ['dry-run', 'limit::']);
$dry   = array_key_exists('dry-run', $opt);
$limit = (int) ($opt['limit'] ?? 0);

$pdo = db();

// ── 站內分類 name → id / code ──────────────────────────────
$cats = [];
$catCode = [];
foreach ($pdo->query('SELECT category_id, code, name FROM categories')->fetchAll() as $r) {
    $cats[$r['name']]    = (int) $r['category_id'];
    $catCode[$r['name']] = $r['code'];
}

// ── 對映表 ─────────────────────────────────────────────────
$map = [];
foreach ($pdo->query(
    'SELECT bappress_code, bappress_name, internal_name, unpublish, sort_order
       FROM bappress_category_map')->fetchAll() as $r) {
    $map[(string) $r['bappress_code']] = [
        'name'      => $r['internal_name'],
        'unpublish' => (int) $r['unpublish'],
        'sort'      => (int) $r['sort_order'],
        'label'     => $r['bappress_name'],
    ];
}
if (!$map) {
    exit("bappress_category_map 為空(請先跑 2026-09-21_bappress_category_map.sql)\n");
}
// ★ 對映目標必須真的存在於 categories,否則靜默不歸類(這是本專案踩過的坑)
$missing = [];
foreach ($map as $code => $m) {
    if ($m['name'] !== null && !isset($cats[$m['name']])) {
        $missing[] = "{$code}({$m['label']})→{$m['name']}";
    }
}
if ($missing) {
    exit("★ 對映目標分類不存在:" . implode('、', $missing) . "\n");
}
printf("對映表 %d 列;其中有對映 %d、僅存證 %d、非書下架 %d\n",
    count($map),
    count(array_filter($map, fn($m) => $m['name'] !== null)),
    count(array_filter($map, fn($m) => $m['name'] === null && $m['unpublish'] === 0)),
    count(array_filter($map, fn($m) => $m['unpublish'] === 1)));

// ── 逐書彙整 bappress 分類碼 ───────────────────────────────
// 同時算來源數:判斷是不是 bappress-only(單源)。
// ★ 用 editions 實算,不用 books.source —— 後者只是「建檔來源」,
//   一本書被別站合併進來之後它不會變,拿它當單源判準會誤判。
$sql = "SELECT bs.book_id, s.code, b.title,
               (SELECT COUNT(DISTINCT e.source) FROM editions e
                 WHERE e.book_id = bs.book_id) AS src_cnt
          FROM book_subjects bs
          JOIN subjects s ON s.subject_id = bs.subject_id
          JOIN books b ON b.book_id = bs.book_id
         WHERE s.scheme = 'bappress'";
$byBook = [];
foreach ($pdo->query($sql) as $r) {
    $bid = (int) $r['book_id'];
    if (!isset($byBook[$bid])) {
        $byBook[$bid] = ['codes' => [], 'src' => (int) $r['src_cnt'],
                         'title' => (string) $r['title']];
    }
    $byBook[$bid]['codes'][(string) $r['code']] = true;
}
if (!$byBook) {
    exit("★ 找不到任何 scheme='bappress' 的 subjects —— 請確認 import 已跑過。\n");
}
printf("bappress 書 %d 本(其中單源 %d、跨站合併 %d)\n",
    count($byBook),
    count(array_filter($byBook, fn($d) => $d['src'] === 1)),
    count(array_filter($byBook, fn($d) => $d['src'] > 1)));

// ── 寫入 ───────────────────────────────────────────────────
if (!$dry) {
    $upSubjCat = $pdo->prepare(
        "INSERT INTO subjects (scheme, code, label) VALUES ('cat', :c, :l)
         ON DUPLICATE KEY UPDATE subject_id = LAST_INSERT_ID(subject_id)");
    $insBS = $pdo->prepare(
        "INSERT INTO book_subjects (book_id, subject_id, weight) VALUES (:b, :s, :w)
         ON DUPLICATE KEY UPDATE weight = VALUES(weight)");
    $delCat = $pdo->prepare(
        "DELETE bs FROM book_subjects bs JOIN subjects s ON s.subject_id = bs.subject_id
          WHERE bs.book_id = :b AND s.scheme = 'cat'");
    $updBook  = $pdo->prepare('UPDATE books SET category_id = :cid WHERE book_id = :bid');
    $unpubSql = $pdo->prepare('UPDATE books SET is_published = 0 WHERE book_id = :bid');
}
$catSubjId = [];
$catSid = function (string $name) use (&$catSubjId, &$upSubjCat, $catCode, $pdo) {
    if (isset($catSubjId[$name])) {
        return $catSubjId[$name];
    }
    $upSubjCat->execute([':c' => $catCode[$name] ?? null, ':l' => $name]);
    return $catSubjId[$name] = (int) $pdo->lastInsertId();
};

$stats = ['replace' => 0, 'append' => 0, 'unpub' => 0, 'unpub_skip_merged' => 0,
          'no_map' => 0, 'unknown_code' => []];
$dist = [];
$done = 0;

if (!$dry) {
    $pdo->beginTransaction();
}
foreach ($byBook as $bid => $d) {
    if ($limit && $done >= $limit) {
        break;
    }
    $only = ($d['src'] === 1);

    /* ★★ 書名例外:熊哥 2026-09-22 裁示「歌詞集/歌書集**算書**,歸詩本樂譜」。
     * 當時我只把這兩個詞從爬蟲的**非書關鍵字**移除,以為就完事了 ——
     * 但站方把《生命粵樂之珍情留路-歌書集》掛在「影音」(code 52,unpublish=1),
     * **分類這條路徑照樣把它打下架**(9/23 實查 book 105377 才發現)。
     * 一個裁示有兩條路徑會推翻它,只改一條等於沒改 ——
     * 這和 links / buy_links 那次是同一種錯,同一天犯兩次。
     * 這裡是第二條路徑:命中例外就不下架,並直接指定 primary。 */
    $exceptCat = null;
    if (preg_match('/歌詞集|歌書集/u', $d['title'])) {
        $exceptCat = '詩本樂譜';
    }

    // 非書判定
    $hasUnpub = false;
    foreach (array_keys($d['codes']) as $c) {
        if (isset($map[$c]) && $map[$c]['unpublish'] === 1) {
            $hasUnpub = true;
        }
        if (!isset($map[$c])) {
            $stats['unknown_code'][$c] = ($stats['unknown_code'][$c] ?? 0) + 1;
        }
    }
    if ($hasUnpub && $exceptCat !== null) {
        $hasUnpub = false;                 // 例外:裁示已認定為書
        $stats['except'] = ($stats['except'] ?? 0) + 1;
    }
    if ($hasUnpub) {
        if ($only) {
            $stats['unpub']++;
            if (!$dry) {
                $unpubSql->execute([':bid' => $bid]);
            }
            $done++;
            continue;
        }
        // ★ 跨站合併書不下架:它在別站可能是正常書目
        $stats['unpub_skip_merged']++;
    }

    // 對映 → 依 sort_order 排序 → 去重保序
    $cand = [];
    foreach (array_keys($d['codes']) as $c) {
        if (isset($map[$c]) && $map[$c]['name'] !== null) {
            $cand[] = [$map[$c]['sort'], (int) $c, $map[$c]['name']];
        }
    }
    if (!$cand && $exceptCat === null) {
        $stats['no_map']++;      // 只掛書系/本社書籍/最新產品 → 留給 classify 用關鍵字猜
        $done++;
        continue;
    }
    usort($cand, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $mapped = [];
    foreach ($cand as [$s, $c, $name]) {
        if (!in_array($name, $mapped, true)) {
            $mapped[] = $name;
        }
    }
    // 例外分類排到最前面當 primary(裁示指定的歸類優先於對映表)
    if ($exceptCat !== null) {
        $mapped = array_values(array_unique(array_merge([$exceptCat], $mapped)));
        // 例外書若先前被下架過(手動或前一輪 apply),這裡一併恢復
        if (!$dry) {
            $pdo->prepare('UPDATE books SET is_published = 1 WHERE book_id = :bid')
                ->execute([':bid' => $bid]);
        }
    }
    $primary = $mapped[0];
    $dist[$primary] = ($dist[$primary] ?? 0) + 1;

    if (!$dry) {
        if ($only) {
            $delCat->execute([':b' => $bid]);
        }
        foreach ($mapped as $i => $name) {
            $insBS->execute([':b' => $bid, ':s' => $catSid($name), ':w' => $i === 0 ? 10 : 5]);
        }
        if ($only) {
            $updBook->execute([':cid' => $cats[$primary], ':bid' => $bid]);
        }
    }
    $stats[$only ? 'replace' : 'append']++;

    if (!$dry && ++$done % 500 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        echo "  已處理 {$done}…\n";
    } elseif ($dry) {
        $done++;
    }
}
if (!$dry && $pdo->inTransaction()) {
    $pdo->commit();
}

// ── 報表 ───────────────────────────────────────────────────
arsort($dist);
echo "\n== 處置 ==\n";
printf("  單源書:取代分類      %d\n", $stats['replace']);
printf("  跨站合併書:只追加     %d\n", $stats['append']);
printf("  非書下架(單源)       %d\n", $stats['unpub']);
printf("  ★ 非書但跨站合併,**不下架** %d(它在別站可能是正常書目)\n",
    $stats['unpub_skip_merged']);
printf("  只掛書系/篩選頁,無主題分類 %d(留給 classify 關鍵字)\n", $stats['no_map']);
printf("  ★ 書名例外(歌詞集/歌書集 → 詩本樂譜,不因影音分類下架)%d\n",
    $stats['except'] ?? 0);

echo "\n== primary 站內分類分布 ==\n";
foreach ($dist as $c => $n) {
    printf("  %-10s %6d\n", $c, $n);
}

if ($stats['unknown_code']) {
    echo "\n★★ 對映表沒有的分類碼(站方新增?請補對映表):\n";
    foreach ($stats['unknown_code'] as $c => $n) {
        printf("  code=%-6s %d 本\n", $c, $n);
    }
}

echo "\n" . ($dry ? "[dry-run 未寫入]" : "完成寫入") . "\n";
echo $dry
    ? "確認分布合理後,拿掉 --dry-run 再跑一次即寫入。\n"
    : "★ 驗證(Navicat,不要只看上面的數字):\n"
    . "  -- 一、bappress 書的 primary 分類分布\n"
    . "  SELECT c.name, COUNT(*) FROM books b\n"
    . "    JOIN categories c ON c.category_id = b.category_id\n"
    . "   WHERE EXISTS (SELECT 1 FROM editions e WHERE e.book_id=b.book_id AND e.source='bappress')\n"
    . "   GROUP BY c.name ORDER BY 2 DESC;\n"
    . "  -- 二、下架數(應等於上面的『非書下架(單源)』)\n"
    . "  SELECT COUNT(*) FROM books b WHERE b.is_published=0\n"
    . "   AND EXISTS (SELECT 1 FROM editions e WHERE e.book_id=b.book_id AND e.source='bappress');\n"
    . "  -- 三、★ 反向守門:跨站合併書有沒有被誤下架(應回 0 列)\n"
    . "  SELECT b.book_id, b.title FROM books b\n"
    . "   WHERE b.is_published=0\n"
    . "     AND (SELECT COUNT(DISTINCT e.source) FROM editions e WHERE e.book_id=b.book_id) > 1\n"
    . "     AND EXISTS (SELECT 1 FROM editions e WHERE e.book_id=b.book_id AND e.source='bappress')\n"
    . "   LIMIT 20;\n";
