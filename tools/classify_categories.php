<?php
declare(strict_types=1);

/**
 * 書目分類器(關鍵字規則,可重跑)—— 讓每本書都落到分類
 *
 * 背景:基道(logos)與每日新品書無來源分類欄位,category_id 為 NULL(約 1.78 萬本)。
 *   本工具以「書名+副標+關鍵字+摘要+出版社+作者」關鍵字比對,將每本 is_published=1 的書
 *   分到 ≥1 個分類:
 *     - primary(規則優先序最高者)→ books.category_id
 *     - 全部命中的分類 → book_subjects(scheme='cat'),支援「一書多分類」
 *     - 完全未命中 → 「綜合其他」catch-all,保證無 NULL
 *
 *   先跑 database/migrations/2026-07-17_categories_extend.sql 建齊分類,再跑本工具。
 *
 * 設計原則:
 *   - 不破壞既有校園分類:預設只處理 category_id IS NULL 的書(--all 可全部重分)。
 *   - 冪等可重跑:book_subjects 以 ON DUPLICATE KEY 覆寫 weight;category_id 直接覆寫。
 *   - 這是「第一輪自動分類」,求覆蓋率;精修由內容面依 CategoryV11 細類續做。
 *
 * 用法(主機 CLI):
 *   php tools/classify_categories.php --dry-run          # 只統計不寫入
 *   php tools/classify_categories.php                    # 寫入(僅 NULL 分類的書)
 *   php tools/classify_categories.php --all              # 重分所有已上架書(含校園)
 *   php tools/classify_categories.php --limit=500 --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/api/lib/db.php';

$opt   = getopt('', ['dry-run', 'all', 'limit::']);
$dry   = array_key_exists('dry-run', $opt);
$all   = array_key_exists('all', $opt);
$limit = (int) ($opt['limit'] ?? 0);

/**
 * 分類規則(依優先序;primary 取第一個命中者)。
 * 格式:分類名 => [關鍵字...]。關鍵字為子字串比對(UTF-8)。
 * 順序刻意:格式/對象類(詩本、兒童)最前,信仰核心其次,一般主題再次,最後才落一般類。
 */
$RULES = [
    '詩本樂譜'     => ['詩本', '樂譜', '聖詩', '讚美詩', '詩歌集', '琴譜', '五線譜', '簡譜', '敬拜詩歌'],
    '兒童主日學'   => ['主日學', '兒童崇拜', '兒童事工', '兒童牧養', '兒童崇拜'],
    '兒童教材'     => ['繪本', '童書', '圖畫書', '幼兒', '兒童', '小朋友', '注音', '漫畫聖經', '兒童聖經'],
    '青少年家庭'   => ['親子', '教養', '婚姻', '夫妻', '家庭', '父母', '青少年', '子女', '兩性', '戀愛', '婚前', '管教', '青年', '單親', '為人父母'],
    '見證'         => ['見證', '傳記', '生命故事', '回憶錄', '自傳', '口述', '生命見證', '蒙恩'],
    '福音'         => ['福音', '佈道', '慕道', '決志', '傳福音', '未信', '佈道會', '福音預工'],
    '聖經研究'     => ['釋經', '註釋', '解經', '研經', '導論', '舊約', '新約', '福音書', '保羅', '詩篇', '摩西五經', '經文', '聖經神學', '原文', '希臘文', '希伯來文'],
    '聖經'         => ['讀經', '查經', '聖經概論', '聖經導覽', '聖經地圖', '讀聖經', '靈修版聖經', '聖經入門'],
    '神學'         => ['神學', '教義', '系統神學', '基督論', '護教', '三一', '救恩', '末世', '恩典論', '信仰概論', '加爾文', '改革宗', '天主教神學'],
    '靈修'         => ['靈修', '默想', '禱告', '靈命', '屬靈操練', '靈程', '與神同行', '安靜', '退修', '靈性', '靈旅'],
    '門徒造就'     => ['門徒', '造就', '事奉', '領導', '服事', '小組', '栽培', '屬靈成長', '牧養', '事工', '裝備', '生命成長', '執事', '教牧'],
    '教會復興'     => ['教會', '復興', '宣教', '差傳', '植堂', '教會歷史', '普世宣教', '差會', '禾場', '福音派'],
    '心理'         => ['心理', '情緒', '輔導', '創傷', '憂鬱', '躁鬱', '焦慮', '諮商', '療癒', '精神', '壓力', '自我形象', '人際', '關係修復', '情緒病'],
    '健康'         => ['健康', '醫學', '養生', '疾病', '懷孕', '醫療', '飲食', '營養', '保健', '身心', '醫生', '護理', '婦產', '養育身體'],
    '倫理'         => ['倫理', '道德', '生命倫理', '性倫理', '醫學倫理', '社會倫理'],
    '哲學'         => ['哲學', '思想史', '邏輯', '存在主義', '形上', '知識論', '哲人'],
    '社會'         => ['社會', '文化', '政治', '經濟', '職場', '管理', '法律', '社會學', '公共', '世界局勢', '族群', '教育學', '學校', '教室'],
    '歷史'         => ['歷史', '近代史', '古代', '史話', '通史', '文明史', '史記'],
    '文學'         => ['小說', '散文', '詩集', '文學', '故事集', '隨筆', '散文集', '文集', '繪畫故事'],
    '藝術'         => ['藝術', '音樂', '繪畫', '美術', '設計', '電影', '攝影', '書法', '美學'],
    '科學'         => ['科學', '物理', '生物', '化學', '天文', '進化', '數學', '科技'],
    '環境'         => ['環境', '生態', '環保', '大自然', '氣候', '永續'],
    '地理'         => ['地理', '旅遊', '地圖', '遊記'],
    '傳媒'         => ['傳媒', '媒體', '網路', '傳播', '社群媒體'],
];
$CATCH_ALL = '綜合其他';

// ── 載入分類 id ────────────────────────────────────────
$cats = [];
foreach (db()->query('SELECT category_id, name FROM categories')->fetchAll() as $r) {
    $cats[$r['name']] = (int) $r['category_id'];
}
$need = array_merge(array_keys($RULES), [$CATCH_ALL]);
$missing = array_values(array_filter($need, fn($n) => !isset($cats[$n])));
if ($missing) {
    exit("缺少分類(請先跑 2026-07-17_categories_extend.sql):" . implode('、', $missing) . "\n");
}

// ── 為多分類建立 scheme='cat' 的 subjects(對應每個分類)──
$catSubjectId = [];
$selSubj = db()->prepare("SELECT subject_id FROM subjects WHERE scheme='cat' AND label=:l");
$insSubj = db()->prepare("INSERT INTO subjects (scheme, code, label) VALUES ('cat', :c, :l)
                          ON DUPLICATE KEY UPDATE subject_id = LAST_INSERT_ID(subject_id)");
$catCode = [];
foreach (db()->query('SELECT category_id, code, name FROM categories')->fetchAll() as $r) {
    $catCode[$r['name']] = $r['code'];
}
foreach ($need as $name) {
    if (!$dry) {
        $insSubj->execute([':c' => $catCode[$name] ?? null, ':l' => $name]);
        $catSubjectId[$name] = (int) db()->lastInsertId();
    }
}

// ── 取書 ──────────────────────────────────────────────
$where = 'b.is_published = 1' . ($all ? '' : ' AND b.category_id IS NULL');
$sql = "SELECT b.book_id, b.title, b.subtitle, b.author, b.publisher, b.keywords, b.summary_short
        FROM books b WHERE $where ORDER BY b.book_id" . ($limit ? " LIMIT $limit" : '');
$rows = db()->query($sql)->fetchAll();
echo ($dry ? "[dry-run] " : "") . "待處理:" . count($rows) . " 本" . ($all ? "(--all 全部上架)" : "(僅未分類)") . "\n";

$updBook = db()->prepare('UPDATE books SET category_id = :cid WHERE book_id = :bid');
$insBS   = db()->prepare('INSERT INTO book_subjects (book_id, subject_id, weight) VALUES (:b, :s, :w)
                          ON DUPLICATE KEY UPDATE weight = VALUES(weight)');

$stats = [];   // primary 分類統計
$multi = 0;    // 命中 ≥2 類的書數
$catchN = 0;
$done = 0;

if (!$dry) db()->beginTransaction();
foreach ($rows as $b) {
    $hay = implode(' ', array_filter([
        $b['title'], $b['subtitle'], $b['author'], $b['publisher'], $b['keywords'], $b['summary_short'],
    ]));

    $matched = [];
    foreach ($RULES as $cat => $kws) {
        foreach ($kws as $kw) {
            if ($kw !== '' && mb_strpos($hay, $kw) !== false) { $matched[] = $cat; break; }
        }
    }
    if (!$matched) { $matched = [$CATCH_ALL]; $catchN++; }
    if (count($matched) >= 2) $multi++;

    $primary = $matched[0];
    $stats[$primary] = ($stats[$primary] ?? 0) + 1;

    if (!$dry) {
        $updBook->execute([':cid' => $cats[$primary], ':bid' => (int) $b['book_id']]);
        foreach ($matched as $i => $cat) {
            $insBS->execute([':b' => (int) $b['book_id'], ':s' => $catSubjectId[$cat], ':w' => $i === 0 ? 10 : 5]);
        }
    }
    if (!$dry && ++$done % 500 === 0) { db()->commit(); db()->beginTransaction(); echo "  已處理 $done…\n"; }
}
if (!$dry && db()->inTransaction()) db()->commit();

// ── 報表 ──────────────────────────────────────────────
arsort($stats);
echo "\n== primary 分類分布 ==\n";
foreach ($stats as $cat => $n) echo sprintf("  %-8s %6d\n", $cat, $n);
echo "\n多分類(≥2 類)書數:$multi;落入「$CATCH_ALL」:$catchN\n";
echo ($dry ? "[dry-run 未寫入] " : "完成寫入 ") . "共 " . count($rows) . " 本\n";
echo $dry ? "確認分布合理後,拿掉 --dry-run 再跑一次即寫入。\n" : "驗證:SELECT COUNT(*) FROM books WHERE is_published=1 AND category_id IS NULL; -- 應為 0\n";
