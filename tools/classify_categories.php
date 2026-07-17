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
 * 用法(主機 CLI):
 *   php tools/classify_categories.php --dry-run              # 只統計不寫入
 *   php tools/classify_categories.php --dry-run --dump-catchall=80  # 印出落入綜合其他的書名(調規則用)
 *   php tools/classify_categories.php                        # 寫入(僅 NULL 分類的書)
 *   php tools/classify_categories.php --all                  # 重分所有已上架書(含校園)
 *   php tools/classify_categories.php --limit=500 --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/api/lib/db.php';

$opt      = getopt('', ['dry-run', 'all', 'limit::', 'dump-catchall::']);
$dry      = array_key_exists('dry-run', $opt);
$all      = array_key_exists('all', $opt);
$limit    = (int) ($opt['limit'] ?? 0);
$dumpN    = array_key_exists('dump-catchall', $opt) ? (int) ($opt['dump-catchall'] ?: 50) : 0;

/**
 * 分類規則(依優先序;primary 取第一個命中者)。
 * 格式:分類名 => [關鍵字...]。關鍵字為子字串比對(UTF-8)。
 * 順序刻意:格式/對象類(詩本、兒童)最前,信仰核心其次,一般主題再次,最後才落一般類。
 */
$RULES = [
    '詩本樂譜'   => ['詩本','樂譜','聖詩','讚美詩','詩歌集','琴譜','五線譜','簡譜','敬拜詩歌','詩歌本','頌讚','聖歌'],
    '兒童主日學' => ['主日學','兒童崇拜','兒童聚會','兒童營','兒童事工','兒童牧養','兒童崇拜','幼兒主日學'],
    '兒童教材'   => ['繪本','童書','圖畫書','幼兒','兒童','小朋友','注音','漫畫','兒童聖經','兒童故事','著色','貼紙','立體書','兒歌','童話','小小','寶寶'],
    '青少年家庭' => ['親子','教養','婚姻','夫妻','家庭','父母','青少年','子女','兩性','戀愛','婚前','管教','青年','單親','育兒','孩子','母親','父親','爸爸','媽媽','家教','恩愛','溝通','擇偶','性教育','青春期','世代','教子','為人父母','家庭祭壇'],
    '見證'       => ['見證','傳記','生命故事','回憶錄','自傳','口述','蒙恩','神蹟','醫治見證','走過','走出','抗癌','戒毒','生命轉化','更新生命','一生','傳奇','足跡','歲月'],
    '福音'       => ['福音','佈道','慕道','決志','傳福音','未信','佈道會','福音預工','歸主','信主','得救','領人歸主','四律'],
    '聖經研究'   => ['釋經','註釋','解經','研經','導論','舊約','新約','福音書','保羅','詩篇','摩西五經','經文','聖經神學','原文','希臘文','希伯來文','創世記','出埃及','利未記','民數記','申命記','約書亞','士師記','路得記','撒母耳','列王','歷代志','尼希米','以斯帖','約伯','箴言','傳道書','雅歌','以賽亞','耶利米','以西結','但以理','何西阿','約珥','阿摩司','約拿','彌迦','哈巴谷','西番雅','哈該','撒迦利亞','瑪拉基','馬太','馬可','路加','約翰福音','使徒行傳','羅馬書','哥林多','加拉太','以弗所','腓立比','歌羅西','帖撒羅尼迦','提摩太','提多','腓利門','希伯來書','雅各書','彼得','約翰壹','約翰一','猶大書','啟示錄','登山寶訓','山上寶訓','比喻'],
    '聖經'       => ['讀經','查經','聖經概論','聖經導覽','聖經地圖','讀聖經','靈修版聖經','聖經入門','認識聖經','聖經人物','聖經故事','串珠','經課'],
    '神學'       => ['神學','教義','系統神學','基督論','護教','三一','救恩','救贖','恩典','稱義','成聖','聖潔','聖靈','十字架','末世','預定','揀選','盟約','約','主權','信仰','基督教要義','信經','世界觀','護教學','罪','因信稱義','聖靈論','救恩論','神論','人論','教會論','啟示','真理'],
    '靈修'       => ['靈修','默想','禱告','靈命','屬靈操練','靈程','與神同行','安靜','退修','靈性','靈旅','敬拜','讚美','安息','順服','交託','受苦','苦難','盼望','靈糧','每日','安慰','同在','親近神','禱告生活','讀經計畫','靈修札記'],
    '門徒造就'   => ['門徒','造就','事奉','領導','服事','小組','栽培','屬靈成長','牧養','事工','裝備','生命成長','執事','教牧','品格','塑造','陶造','講道','證道','信息','講章','教導','呼召','使命','恩賜','團契','導師','師徒','生命影響生命','門訓','屬靈','服侍'],
    '教會復興'   => ['教會','復興','宣教','差傳','植堂','教會歷史','普世宣教','差會','禾場','福音派','教會論','崇拜','聖禮','洗禮','聖餐','牧會','宗派','更新','宣教士','宣道','跨文化'],
    '心理'       => ['心理','情緒','輔導','創傷','憂鬱','躁鬱','焦慮','諮商','療癒','精神','壓力','自我形象','人際','關係修復','情緒病','憂傷','哀傷','失落','恐懼','傷痛','內在醫治','饒恕','個性','性格','上癮','成癮','界線','完美主義','自尊','安全感','心靈'],
    '健康'       => ['健康','醫學','養生','疾病','懷孕','醫療','飲食','營養','保健','身心','醫生','護理','婦產','癌','睡眠','運動','減重','中醫','西醫','銀髮','長照','失智','復健','疾患','藥','病人','照顧','高血壓','糖尿'],
    '倫理'       => ['倫理','道德','生命倫理','性倫理','醫學倫理','社會倫理','商業倫理','墮胎','安樂死','生死'],
    '哲學'       => ['哲學','思想','邏輯','存在主義','形上','知識論','哲人','理性','世界觀','康德','尼采','蘇格拉底','思辨'],
    '社會'       => ['社會','文化','政治','經濟','職場','工作','管理','法律','社會學','公共','世界局勢','族群','教育','學校','教室','老師','理財','金錢','財務','企業','時代','貧窮','難民','移民','人權','服務','老人','長者','退休','領袖魅力','國際','城市','公民'],
    '歷史'       => ['歷史','近代史','古代','史話','通史','文明史','史記','歷代','王朝','戰爭','二戰','大戰','年表','源流'],
    '文學'       => ['小說','散文','詩集','文學','故事集','隨筆','散文集','文集','短篇','長篇','寓言','日記','書信集','小品','美文','新詩'],
    '藝術'       => ['藝術','音樂','繪畫','美術','設計','電影','攝影','書法','美學','畫','建築','雕塑','手作','手工','影像','舞蹈','戲劇','展'],
    '科學'       => ['科學','物理','生物','化學','天文','進化','數學','科技','宇宙','量子','基因','人工智慧','電腦'],
    '環境'       => ['環境','生態','環保','大自然','氣候','永續','土地','農業'],
    '地理'       => ['地理','旅遊','地圖','遊記','旅行','國家','地方志'],
    '傳媒'       => ['傳媒','媒體','網路','傳播','社群','新聞','數位'],
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
$catCode = [];
foreach (db()->query('SELECT code, name FROM categories')->fetchAll() as $r) {
    $catCode[$r['name']] = $r['code'];
}
$catSubjectId = [];
if (!$dry) {
    $insSubj = db()->prepare("INSERT INTO subjects (scheme, code, label) VALUES ('cat', :c, :l)
                              ON DUPLICATE KEY UPDATE subject_id = LAST_INSERT_ID(subject_id)");
    foreach ($need as $name) {
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

if (!$dry) {
    $updBook = db()->prepare('UPDATE books SET category_id = :cid WHERE book_id = :bid');
    $insBS   = db()->prepare('INSERT INTO book_subjects (book_id, subject_id, weight) VALUES (:b, :s, :w)
                              ON DUPLICATE KEY UPDATE weight = VALUES(weight)');
}

$stats = [];
$multi = 0;
$catchN = 0;
$done = 0;
$catchSample = [];

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
    if (!$matched) {
        $matched = [$CATCH_ALL];
        $catchN++;
        if ($dumpN && count($catchSample) < $dumpN) {
            $catchSample[] = trim(($b['title'] ?? '') . '  〔' . ($b['publisher'] ?? '') . '〕');
        }
    }
    if (count($matched) >= 2) $multi++;

    $primary = $matched[0];
    $stats[$primary] = ($stats[$primary] ?? 0) + 1;

    if (!$dry) {
        $updBook->execute([':cid' => $cats[$primary], ':bid' => (int) $b['book_id']]);
        foreach ($matched as $i => $cat) {
            $insBS->execute([':b' => (int) $b['book_id'], ':s' => $catSubjectId[$cat], ':w' => $i === 0 ? 10 : 5]);
        }
        if (++$done % 500 === 0) { db()->commit(); db()->beginTransaction(); echo "  已處理 $done…\n"; }
    }
}
if (!$dry && db()->inTransaction()) db()->commit();

// ── 報表 ──────────────────────────────────────────────
arsort($stats);
echo "\n== primary 分類分布 ==\n";
foreach ($stats as $cat => $n) echo sprintf("  %-8s %6d\n", $cat, $n);
$total = count($rows);
$catchPct = $total ? round($catchN * 100 / $total, 1) : 0;
echo "\n多分類(>=2 類)書數:{$multi};落入「{$CATCH_ALL}」:{$catchN}({$catchPct}%)\n";

if ($dumpN && $catchSample) {
    echo "\n== 落入「{$CATCH_ALL}」樣本(前 " . count($catchSample) . " 本,調規則用)==\n";
    foreach ($catchSample as $t) echo "  - $t\n";
}

echo "\n" . ($dry ? "[dry-run 未寫入] " : "完成寫入 ") . "共 {$total} 本\n";
echo $dry
    ? "確認分布合理後,拿掉 --dry-run 再跑一次即寫入。\n"
    : "驗證:SELECT COUNT(*) FROM books WHERE is_published=1 AND category_id IS NULL; -- 應為 0\n";
