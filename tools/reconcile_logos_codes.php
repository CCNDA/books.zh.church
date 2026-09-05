<?php
declare(strict_types=1);

/**
 * 基道(logos)商品碼對帳 —— 拿站方分類清單的權威商品碼比對資料庫,找出「站方有、我們沒有」的書。
 *
 * 為什麼需要:log 全綠不代表書都收到了。2026-09-04 首次比對,站方分類清單 29,725 個商品碼裡
 * 有 8,236 個在資料庫找不到對應的基道書(28%)。可能是七月後的新品沒抓到、非書品項,
 * 或早期涵蓋率只有 77.8% 時漏掉的那批。
 *
 * 輸入:crawler/data/logos_code_categories.jsonl(由 crawler/logos_categories.py 產生)
 *       每列 {"code": "...", "paths": ["其他/工具書", ...], "tops": ["其他", ...]}
 * 比對:code → identifiers(id_type='STORE') → editions(source='logos') → books
 *
 * 產出(crawler/data/):
 *   logos_reconcile_YYYYMMDD.tsv   完整對帳表(UTF-8 with BOM,Excel 可直開)
 *   logos_recover_codes.txt        判定為「疑似漏抓的書」的商品碼,一行一個 → 餵給
 *                                  crawler/recover_logos.py 補抓
 *
 * 判定:商品碼的**所有**分類路徑都落在非書清單(影音/單張/教會用品/聖經周邊)才算非書;
 *       只要有一條路徑是書類就當書處理(寧可多抓,不可漏書)。
 *
 * 用法:
 *   php tools/reconcile_logos_codes.php                 # 對帳並產出檔案
 *   php tools/reconcile_logos_codes.php --no-write      # 只印統計不寫檔
 *   php tools/reconcile_logos_codes.php --file=/path/to.jsonl
 * 唯讀,不改資料庫。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/api/lib/db.php';

$opt     = getopt('', ['file::', 'no-write']);
$noWrite = array_key_exists('no-write', $opt);
$root    = dirname(__DIR__);
$dataDir = $root . '/crawler/data';
$file    = (string) ($opt['file'] ?? ($dataDir . '/logos_code_categories.jsonl'));

if (!is_file($file)) {
    exit("找不到對照檔:$file\n(請先於主機跑 crawler/logos_cat_refresh.sh)\n");
}

// 非書路徑前綴:整條路徑都落在這裡才判非書
const NONBOOK_PREFIXES = [
    '精選影音',            // 音樂/電影/講座/電腦軟件
    '其他/單張',
    '其他/教會用品',
    '聖經/聖經周邊用品',
];

function isNonBookPath(string $path): bool
{
    foreach (NONBOOK_PREFIXES as $p) {
        if ($path === $p || str_starts_with($path, $p . '/')) return true;
    }
    return false;
}

// ── 1. 讀對照檔 ────────────────────────────────────────────
$site = [];   // code => paths[]
$fh = fopen($file, 'r');
while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $r = json_decode($line, true);
    if (!is_array($r) || !isset($r['code'])) continue;
    $site[(string) $r['code']] = array_values((array) ($r['paths'] ?? []));
}
fclose($fh);
printf("對照檔:%s\n站方分類清單商品碼:%d 個\n", basename($file), count($site));

// ── 2. 讀資料庫既有的基道商品碼 ─────────────────────────────
$pdo = db();
$db = [];     // code => ['book_id'=>, 'is_published'=>]
$sql = "SELECT i.id_value AS code, b.book_id, b.is_published
          FROM identifiers i
          JOIN editions e ON e.edition_id = i.edition_id
          JOIN books    b ON b.book_id    = e.book_id
         WHERE i.id_type = 'STORE' AND e.source = 'logos'";
foreach ($pdo->query($sql) as $r) {
    $db[(string) $r['code']] = ['book_id' => (int) $r['book_id'], 'is_published' => (int) $r['is_published']];
}
printf("資料庫既有基道商品碼:%d 個(其中已下架 %d)\n",
    count($db), count(array_filter($db, static fn(array $x): bool => $x['is_published'] === 0)));

// ── 3. 三方比對 ────────────────────────────────────────────
$missBook = [];   // 站方有、DB 沒有,且判定為書
$missOther = [];  // 站方有、DB 沒有,判定為非書
foreach ($site as $code => $paths) {
    if (isset($db[$code])) continue;
    $allNonBook = $paths !== [];
    foreach ($paths as $p) {
        if (!isNonBookPath((string) $p)) { $allNonBook = false; break; }
    }
    // 注意:PHP 不能把三元運算式當賦值目標(Cannot use temporary expression in write context)
    if ($allNonBook) {
        $missOther[$code] = $paths;
    } else {
        $missBook[$code] = $paths;
    }
}
$onlyDb = array_diff_key($db, $site);   // DB 有、站方清單沒有(絕版/下架/站方移除)

printf("\n== 對帳結果 ==\n");
printf("站方有、DB 沒有:%d(疑似漏抓的書 %d、非書品項 %d)\n",
    count($missBook) + count($missOther), count($missBook), count($missOther));
printf("DB 有、站方清單沒有:%d(絕版/站方下架/分類已清空,不必補)\n", count($onlyDb));
printf("兩邊都有:%d\n", count($site) - count($missBook) - count($missOther));

// 漏抓者依主分類分布,看破口集中在哪
$byTop = [];
foreach ($missBook as $paths) {
    $tops = [];
    foreach ($paths as $p) $tops[explode('/', (string) $p, 2)[0]] = true;
    if (!$tops) $tops = ['(無分類)' => true];
    foreach (array_keys($tops) as $t) $byTop[$t] = ($byTop[$t] ?? 0) + 1;
}
arsort($byTop);
printf("\n== 疑似漏抓的書:主分類分布(含跨類重複)==\n");
foreach ($byTop as $t => $n) printf("  %-16s %6d\n", $t, $n);

if ($noWrite) {
    printf("\n[--no-write] 未寫檔。\n");
    exit(0);
}

// ── 4. 產出 TSV 與補抓清單 ──────────────────────────────────
$stamp = date('Ymd');
$tsv   = $dataDir . "/logos_reconcile_$stamp.tsv";
$codes = $dataDir . '/logos_recover_codes.txt';

$out = fopen($tsv, 'w');
fwrite($out, "\xEF\xBB\xBF");   // BOM,Excel 直開不亂碼
fwrite($out, "商品碼\t判定\t主分類\t完整分類路徑\t商品網址\n");
foreach ([['疑似漏抓的書', $missBook], ['非書品項', $missOther]] as [$verdict, $set]) {
    foreach ($set as $code => $paths) {
        $tops = [];
        foreach ($paths as $p) $tops[explode('/', (string) $p, 2)[0]] = true;
        fwrite($out, implode("\t", [
            $code,
            $verdict,
            implode('；', array_keys($tops)),
            implode('；', $paths),
            'https://www.logos.com.hk/bf/acms/content.asp?site=logosbf&op=show&type=product&code=' . rawurlencode((string) $code),
        ]) . "\n");
    }
}
fclose($out);

file_put_contents($codes, implode("\n", array_keys($missBook)) . "\n");

printf("\n產出:\n  %s(%d 列)\n  %s(%d 個待補抓商品碼)\n",
    $tsv, count($missBook) + count($missOther), $codes, count($missBook));
printf("\n下一步(補抓 → 匯入):\n");
printf("  cd %s/crawler && python3 -u recover_logos.py --codes-file=data/logos_recover_codes.txt\n", $root);
printf("  php %s/tools/import.php --file=<recover_logos.py 印出的 delta 路徑> --source=logos\n", $root);
printf("  完成後再跑一次本工具,『站方有、DB 沒有』應大幅下降。\n");
