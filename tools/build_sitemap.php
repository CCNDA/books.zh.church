<?php
declare(strict_types=1);

/**
 * 產生 sitemap（Asana 1219388780554980）。每日新品跑完後執行（crawler/daily_new.sh），也可以手動跑。
 *
 *   php tools/build_sitemap.php            # 寫 public/sitemap.xml（索引）＋ public/sitemaps/books-N.xml
 *   php tools/build_sitemap.php --dry-run  # 只印數量
 *
 * - 只列上架書（is_published = 1）＋ 首頁、關於本站、版本更新紀錄
 * - 每個 books-N.xml 最多 SITEMAP_MAX 個網址（規格上限 50,000，留餘裕用 45,000）
 * - ★ 書目頁不寫 <lastmod>：books.updated_at 每天會被分類工具碰到（2026-10-11 實查：全部上架書的
 *   updated_at 都落在最近幾天），拿它當 lastmod 等於天天告訴 Google「全部改過了」，反而讓 lastmod 失去可信度。
 * - 先寫 .tmp 再改名，爬蟲不會讀到寫一半的檔
 * - 產出檔在 .gitignore（/public/sitemap.xml、/public/sitemaps/），不進版控
 */

require dirname(__DIR__) . '/app/lib/db.php';

date_default_timezone_set('Asia/Taipei'); // 主機是 UTC；索引的 lastmod 用台北日期

const SITE = 'https://books.zh.church';
const SITEMAP_MAX = 45000;

$dry = in_array('--dry-run', $argv, true);
$pub = dirname(__DIR__) . '/public';
$dir = $pub . '/sitemaps';

// 和書目頁（app/lib/book_detail.php）同一個條件：books JOIN v_book_list 且上架，才不會列出 404 的頁
$ids = db()->query('SELECT b.book_id FROM books b JOIN v_book_list v ON v.book_id = b.book_id
                     WHERE b.is_published = 1 ORDER BY b.book_id')->fetchAll(PDO::FETCH_COLUMN);
if (!$ids) {
    fwrite(STDERR, "查到 0 本上架書（資料庫異常？）—— 中止，不動既有的 sitemap\n");
    exit(1);
}
$static = ['/', '/about.html', '/api.html', '/changelog.php'];
$chunks = array_chunk($ids, SITEMAP_MAX);

printf("上架書 %d 本 → %d 個 books 檔（每檔最多 %d）＋ 固定頁 %d 個\n", count($ids), count($chunks), SITEMAP_MAX, count($static));
if ($dry) {
    exit(0);
}

if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    fwrite(STDERR, "建不了 {$dir}\n");
    exit(1);
}

function write_atomic(string $path, string $xml): void
{
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $xml) === false || !rename($tmp, $path)) {
        fwrite(STDERR, "寫入失敗：{$path}\n");
        exit(1);
    }
    @chmod($path, 0644);
}

$head = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$urlset = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// 固定頁
$xml = $head . $urlset;
foreach ($static as $p) {
    $xml .= '  <url><loc>' . SITE . $p . "</loc></url>\n";
}
write_atomic($dir . '/pages.xml', $xml . "</urlset>\n");

// 書目頁
$files = ['pages.xml'];
foreach ($chunks as $i => $chunk) {
    $xml = $head . $urlset;
    foreach ($chunk as $id) {
        $xml .= '  <url><loc>' . SITE . '/book/' . (int) $id . "</loc></url>\n";
    }
    $name = 'books-' . ($i + 1) . '.xml';
    write_atomic($dir . '/' . $name, $xml . "</urlset>\n");
    $files[] = $name;
}

// 清掉上一輪多出來的 books-N.xml（上架書變少時）
foreach (glob($dir . '/books-*.xml') ?: [] as $old) {
    if (!in_array(basename($old), $files, true)) {
        @unlink($old);
    }
}

// 索引
$today = date('Y-m-d');
$xml = $head . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($files as $f) {
    $xml .= '  <sitemap><loc>' . SITE . '/sitemaps/' . $f . '</loc><lastmod>' . $today . "</lastmod></sitemap>\n";
}
write_atomic($pub . '/sitemap.xml', $xml . "</sitemapindex>\n");
printf("寫好 sitemap.xml（索引 %d 個檔）\n", count($files));
