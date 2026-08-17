<?php
declare(strict_types=1);

/**
 * 一次性修正:微讀購書連結「(簡體)」標籤(2026-08-12)
 *
 * 背景一(標籤誤判):wdbook_crawler.py 的 is_hans 原以「s2tw 轉換前後有
 *   差異」判定簡體,但繁體書經異體字正規化(恒→恆、祢→禰)也會產生差異
 *   → 大量繁體書被 import.php 誤標「微讀書城(簡體)」。爬蟲已修
 *   (以「語言」欄位為準)。
 * 背景二(為何不能用 SQL 按 extra 修):books.extra['wdbook'] 只保留該來源
 *   最後一筆匯入紀錄——同作品繁簡雙版本(兩個 wdbook editions 合併於一書,
 *   約 251 本)會互相覆蓋,extra 的語言只代表其中一版,按 book 層判定必然
 *   錯標另一條連結(8/12 查數實證:「簡體中文×微讀書城 27」等矛盾組)。
 *   故本工具**逐連結**修:讀 crawler/data/wdbook_books.jsonl 逐筆(per-pid)
 *   的 language,以 source_url 對應 links 列與 books.buy_links 內的 entry。
 *
 * 判定(與修正後爬蟲一致):有 language → 含「簡體」即簡體;
 *   無 language 欄位 → hans 非空才視為簡體。
 *
 * 用法(主機 CLI):
 *   php tools/fix_wdbook_hans_labels.php --dry-run   # 只統計
 *   php tools/fix_wdbook_hans_labels.php             # 寫入
 * 冪等可重跑;若先前誤跑過 book 層 SQL 修正,本工具會一併矯正回來。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/api/lib/db.php';

$opt  = getopt('', ['dry-run', 'file::']);
$dry  = array_key_exists('dry-run', $opt);
$file = $opt['file'] ?? (dirname(__DIR__) . '/crawler/data/wdbook_books.jsonl');
if (!is_file($file)) {
    exit("找不到 $file(可用 --file= 指定 jsonl 路徑)\n");
}

// ── 建 url → 正確 label 對照(逐筆紀錄,per-edition 的唯一真相)──
$want = [];
$hansN = 0;
$fh = fopen($file, 'r');
while (($line = fgets($fh)) !== false) {
    $r = json_decode(trim($line), true);
    if (!is_array($r) || empty($r['source_url'])) continue;
    $lang   = (string) ($r['language'] ?? '');
    $isHans = $lang !== '' ? (mb_strpos($lang, '簡體') !== false) : !empty($r['hans']);
    if ($isHans) $hansN++;
    $want[$r['source_url']] = '微讀書城' . ($isHans ? '(簡體)' : '');
}
fclose($fh);
echo 'jsonl 紀錄 ' . count($want) . " 筆(簡體 {$hansN}、繁體 " . (count($want) - $hansN) . ")\n";

$pdo = db();
$pdo->exec('SET NAMES utf8mb4');

// ── 1. links(版本層;url 即該 edition 的 source_url)──────────
$rows = $pdo->query("SELECT l.link_id, l.url, l.platform
                       FROM links l
                       JOIN editions e ON e.edition_id = l.edition_id AND e.source = 'wdbook'
                      WHERE l.link_type = 'buy'")->fetchAll();
$updL = $dry ? null : $pdo->prepare('UPDATE links SET platform = :p WHERE link_id = :id');
$fixL = 0; $missL = 0;
foreach ($rows as $r) {
    $w = $want[$r['url']] ?? null;
    if ($w === null) { $missL++; continue; }
    if ($r['platform'] !== $w) {
        $fixL++;
        if (!$dry) $updL->execute([':p' => $w, ':id' => $r['link_id']]);
    }
}
echo 'links:共 ' . count($rows) . " 條,修正 $fixL,jsonl 查無 $missL\n";

// ── 2. books.buy_links(平面後備欄;JSON 內逐 entry 以 url 對應)──
$rows = $pdo->query("SELECT book_id, buy_links FROM books
                      WHERE buy_links LIKE '%wdbook.com%'")->fetchAll();
$updB = $dry ? null : $pdo->prepare('UPDATE books SET buy_links = :bl WHERE book_id = :id');
$fixB = 0;
foreach ($rows as $r) {
    $bl = json_decode((string) $r['buy_links'], true);
    if (!is_array($bl)) continue;
    $chg = false;
    foreach ($bl as &$e) {
        if (!is_array($e) || empty($e['url'])) continue;
        $w = $want[$e['url']] ?? null;
        if ($w !== null && (($e['label'] ?? null) !== $w)) {
            $e['label'] = $w;
            $chg = true;
        }
    }
    unset($e);
    if ($chg) {
        $fixB++;
        if (!$dry) $updB->execute([
            ':bl' => json_encode($bl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => $r['book_id'],
        ]);
    }
}
echo 'buy_links:掃 ' . count($rows) . " 本,修正 $fixB 本\n";

echo $dry ? "[dry-run 未寫入]\n" : "完成寫入\n";
echo $dry
    ? "統計合理後拿掉 --dry-run 再跑一次即寫入。\n"
    : "驗證(Navicat):\n"
    . "  SELECT JSON_UNQUOTE(JSON_EXTRACT(b.extra,'\$.wdbook.language')) lang, l.platform, COUNT(*)\n"
    . "  FROM links l JOIN editions e ON e.edition_id=l.edition_id AND e.source='wdbook'\n"
    . "  JOIN books b ON b.book_id=e.book_id GROUP BY 1,2 ORDER BY 1,2;\n"
    . "  (注意:繁簡雙版本合併書的 extra 只剩一版語言,此查詢仍會有少量「矛盾」組,\n"
    . "   屬 extra 覆蓋的已知限制,連結本身已按逐筆語言修正。)\n";
