<?php
declare(strict_types=1);

/**
 * 書目分類器(校園照來源、基道照關鍵字;可重跑)
 *
 * 分類原則:
 *  - 校園書(有 scheme='campus' 來源分類)→ 依來源代碼權威對應(campus_category()),不用關鍵字。
 *  - 基道 / 每日新品(當初爬取無來源分類)→ 關鍵字規則(RULES + TITLE_ONLY)。
 *  - 「聖經」類只由校園來源(code 01)判定;基道真聖經待重爬來源分類後歸位,不用關鍵字猜。
 *  - 卷名 / 詩篇等「摘要常引用」的詞只比對書名+副標+關鍵字(TITLE_ONLY),
 *    避免摘要引用經文而誤判(如讚美操歌本摘要列詩篇 → 舊版誤歸聖經研究)。
 *  - 每本 is_published=1 書落到 1 個 primary(category_id)+ book_subjects(scheme='cat')多分類;
 *    完全未命中 → 綜合其他。每本重跑前先清掉自己的 cat 標籤,確保冪等。
 *
 * 用法(主機 CLI):
 *   php tools/classify_categories.php --dry-run
 *   php tools/classify_categories.php --dry-run --dump-catchall=80
 *   php tools/classify_categories.php --all          # 重分所有已上架書(校園來源 + 基道關鍵字)
 *   php tools/classify_categories.php --campus-only  # 只重分校園來源書(照來源歸位,不動基道官方分類)
 *   php tools/classify_categories.php --orphans      # 只重分無來源分類的書(新品:非校園來源、非基道官方)
 *   php tools/classify_categories.php --limit=500 --dry-run
 *
 *   注意:校園分類先前被舊版關鍵字 --all 覆蓋,務必用 --all 重跑一次才能照來源歸位。
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require dirname(__DIR__) . '/api/lib/db.php';

$opt   = getopt('', ['dry-run', 'all', 'campus-only', 'orphans', 'limit::', 'dump-catchall::']);
$dry   = array_key_exists('dry-run', $opt);
$all   = array_key_exists('all', $opt);
$campusOnly = array_key_exists('campus-only', $opt);
$orphans    = array_key_exists('orphans', $opt);   // 無來源分類的書(新品:非校園來源、非基道官方)
$limit = (int) ($opt['limit'] ?? 0);
$dumpN = array_key_exists('dump-catchall', $opt) ? (int) ($opt['dump-catchall'] ?: 50) : 0;

// ── 基道 / 新品關鍵字規則(校園不走這裡)──────────────────
// 一般關鍵字:比對「書名+副標+作者+出版社+關鍵字+摘要」全文
$RULES = [
    '詩本樂譜'   => ['詩本','樂譜','聖詩','讚美詩','詩歌集','琴譜','五線譜','簡譜','敬拜詩歌','詩歌本','頌讚','聖歌','合唱','聖樂','婚禮用詩','合唱譜','讚美操','聖徒詩歌','生命聖詩','教會聖詩','頌主聖歌','世紀讚美','萬民頌揚','兒童詩歌','敬拜讚美'],
    '兒童主日學' => ['主日學','兒童崇拜','兒童聚會','兒童營','兒童事工','兒童牧養','幼兒主日學'],
    '兒童教材'   => ['繪本','童書','圖畫書','幼兒','小朋友','注音','漫畫','兒童聖經','兒童故事','著色本','著色簿','貼紙書','兒歌','童謠','童話故事','幼稚','學齡前'],
    '青少年家庭' => ['親子','教養','婚姻','夫妻','家庭','父母','青少年','子女','兩性','戀愛','婚前','管教','青年','單親','育兒','孩子','母親','父親','爸爸','媽媽','家教','恩愛','溝通','擇偶','性教育','青春期','世代','教子','為人父母','家庭祭壇'],
    '見證'       => ['見證','傳記','生命故事','回憶錄','自傳','口述','蒙恩','神蹟','醫治見證','走過','走出','抗癌','戒毒','生命轉化','更新生命','傳奇','足跡','歲月'],
    '福音'       => ['福音','佈道','慕道','決志','傳福音','未信','佈道會','福音預工','歸主','信主','得救','領人歸主','四律'],
    '聖經研究'   => ['釋經','註釋','解經','研經','聖經導論','聖經神學','原文查考','希臘文','希伯來文','經文彙編','查經','聖經概論','聖經綜覽','聖經導覽','聖經入門','認識聖經','聖經手冊','聖經背景','聖經地圖','聖經人物','偽經','次經'],
    '神學'       => ['神學','教義','系統神學','基督論','護教','三一','救恩','救贖','恩典','稱義','成聖','聖潔','聖靈','十字架','末世','預定','揀選','盟約','主權','基督教要義','信經','世界觀','因信稱義','救恩論','教會論','啟示','要道','異端','異教','駁異','教理','上帝之城'],
    '靈修'       => ['靈修','默想','禱告','祈禱','靈命','屬靈操練','靈程','與神同行','與主同行','安靜','退修','靈性','敬拜','讚美','安息','順服','交託','受苦','苦難','盼望','靈糧','安慰','同在','親近神','禱告生活','讀經計畫','靈修札記','盧雲','泰澤'],
    '門徒造就'   => ['門徒','造就','事奉','領導','服事','小組','栽培','屬靈成長','牧養','事工','裝備','生命成長','執事','教牧','品格','塑造','陶造','講道','證道','講章','教導','呼召','使命','恩賜','團契','導師','師徒','生命影響生命','門訓','服侍','組員本','組長本','課程','研習','學員','天國工人'],
    '教會復興'   => ['教會','復興','宣教','差傳','植堂','教會歷史','教會史','基督教史','普世宣教','差會','禾場','福音派','崇拜','聖禮','洗禮','聖餐','牧會','宗派','宣教士','宣道','跨文化'],
    '心理'       => ['心理','情緒','輔導','創傷','憂鬱','躁鬱','焦慮','諮商','療癒','精神','壓力','自我形象','人際','關係修復','情緒病','憂傷','哀傷','失落','恐懼','傷痛','內在醫治','饒恕','個性','性格','上癮','成癮','界線','完美主義','自尊','安全感','陪伴','傷慟','哀悼','悲傷','孤單','同理','依附','人格','自我探索','臨床','喪親','療傷','DSM','MBTI'],
    '健康'       => ['健康','醫學','養生','疾病','懷孕','醫療','飲食','營養','保健','身心','護理','婦產','癌','睡眠','運動','減重','中醫','西醫','銀髮','長照','失智','復健','疾患','高血壓','糖尿'],
    '倫理'       => ['倫理','道德','生命倫理','性倫理','醫學倫理','社會倫理','商業倫理','墮胎','安樂死','生死'],
    '哲學'       => ['哲學','思想','邏輯','存在主義','形上','知識論','哲人','理性','康德','尼采','蘇格拉底','思辨'],
    '社會'       => ['社會','文化','政治','經濟','職場','工作','管理','法律','社會學','公共','世界局勢','族群','教育','理財','金錢','財務','企業','貧窮','難民','移民','人權','老人','長者','退休','國際','城市','公民','習慣','斷捨離','致富','效率','整理','領導力','管家'],
    '歷史'       => ['歷史','近代史','古代','史話','通史','文明史','史記','歷代','王朝','戰爭','二戰','大戰','年表','源流','帝國','全史','羅馬','王國','興衰','列傳'],
    '文學'       => ['小說','散文','詩集','文學','故事集','隨筆','散文集','文集','短篇','長篇','寓言','日記','書信集','小品','美文','新詩','文選','選集','詩選','劇本'],
    '藝術'       => ['藝術','音樂','繪畫','美術','設計','電影','攝影','書法','美學','建築','雕塑','手作','手工','影像','舞蹈','戲劇','插畫','畫冊'],
    '科學'       => ['科學','物理','生物','化學','天文','進化','數學','科技','宇宙','量子','基因','人工智慧','電腦','創造雜誌','昆蟲'],
    '環境'       => ['環境','生態','環保','大自然','氣候','永續','土地','農業'],
    '地理'       => ['地理','旅遊','地圖','遊記','旅行','地方志'],
    '傳媒'       => ['傳媒','媒體','網路','傳播','社群','新聞','數位','文字工作'],
];

// 僅比對「書名+副標+關鍵字」的關鍵字(避免摘要引用經文而誤判)
$TITLE_ONLY = [
    '聖經研究' => ['創世記','出埃及','利未記','民數記','申命記','約書亞','士師記','路得記','撒母耳','列王','歷代志','以斯拉','尼希米','以斯帖','約伯記','詩篇','箴言','傳道書','雅歌','以賽亞','耶利米','以西結','但以理','何西阿','約珥','阿摩司','俄巴底亞','約拿','彌迦','那鴻','哈巴谷','西番雅','哈該','撒迦利亞','瑪拉基','馬太福音','馬可福音','路加福音','約翰福音','使徒行傳','羅馬書','哥林多','加拉太','以弗所','腓立比','歌羅西','帖撒羅尼迦','提摩太','提多','腓利門','希伯來書','雅各書','彼得前','彼得後','約翰壹','約翰一','約翰二','約翰三','猶大書','啟示錄','摩西五經','登山寶訓','山上寶訓','福音書','保羅書信','先知書'],
];
$CATCH_ALL = '綜合其他';

// 非書商品下架判定(v1.1.1,熊哥 7/30 決議):月曆/賀卡等紙品、禮品非書籍,
// 一律 is_published=0(沿 7/15「非書籍商品資料層下架、留庫可還原」原則)。
// 新品詳情頁常無分類代碼但有來源標籤 category_text(如「訓練材料/紙品」),
// 故於分類前先比對:來源標籤(較廣)+ 書名/關鍵字(較嚴,避免誤殺真書)。
$NONBOOK_LABEL_KWS = ['紙品', '禮品', '文具', '禮品百貨'];
$NONBOOK_TITLE_KWS = ['月曆', '桌曆', '年曆', '日曆', '掛曆', '週曆', '三角曆',
                      '賀卡', '金句卡', '經文卡', '書籤', '紅包袋', '信紙', '信封', '獎勵卡', '拼圖',
                      '試讀冊', '試讀本'];  // 8/6:天恩免費宣傳品(0元試讀冊)無分類會走關鍵字,一併下架

/**
 * 校園來源代碼 → 站上分類(權威)。
 * $code = 校園 category_source(如 0410);$label = 來源標籤(如「實踐神學／輔導」)。
 */
function campus_category(string $code, string $label): string {
    $top = substr($code, 0, 2);
    $sub = '';
    if (($p = mb_strpos($label, '／')) !== false) $sub = mb_substr($label, $p + 1);
    static $direct = [
        '01' => '聖經', '02' => '聖經研究', '03' => '神學',
        '05' => '教會復興', '06' => '門徒造就', '07' => '見證',
        '08' => '福音', '18' => '詩本樂譜',
    ];
    if (isset($direct[$top])) return $direct[$top];
    $has = function (array $ks) use ($sub) {
        foreach ($ks as $k) if ($k !== '' && mb_strpos($sub, $k) !== false) return true;
        return false;
    };
    switch ($top) {
        case '04': // 實踐神學
            if ($has(['宣教','差傳','教會','小組','崇拜','儀式','靈恩'])) return '教會復興';
            if ($has(['輔導','醫治']))                                   return '心理';
            if ($has(['社會參與']))                                       return '社會';
            if ($has(['倫理']))                                           return '倫理';
            if ($has(['文字工作']))                                       return '傳媒';
            return '門徒造就'; // 講道 / 教牧 / 教育 / 管家職份 及其餘
        case '09': // 生活教導
            if ($has(['職業']))     return '社會';
            if ($has(['特殊問題'])) return '心理';
            if ($has(['倫理']))     return '倫理';
            return '青少年家庭'; // 婚姻 / 家庭 / 兒童 / 青少年 / 交友 / 成人 / 生活教導
        case '10': // 訓練材料
            if ($has(['研經','歸納法']))     return '聖經研究';
            if ($has(['兒童','幼稚','幼兒'])) return '兒童教材';
            if ($has(['主日學']))            return '兒童主日學';
            if ($has(['紙品']))              return '綜合其他'; // 正常到不了:紙品已在主迴圈下架
            return '門徒造就'; // 小組材料 / 工作訓練 / 青少年教材 / 成人教材
        case '11': // 文藝類
            if ($has(['畫冊']))            return '藝術';
            if ($has(['CD','DVD','影音'])) return '兒童教材';
            return '文學'; // 小說故事 / 詩、散文 / 劇本遊戲
    }
    return '綜合其他'; // 未知校園碼
}

/**
 * 標籤反查校園代碼(v1.1.2):每日新品詳情頁常無 category_source 代碼、
 * 只有 category_text 標籤(如「聖經／註釋本聖經」),但整站爬取已在 subjects
 * 建立完整「代碼+標籤」對照 → 以標籤反查代碼,即可照來源權威歸位,
 * 不再落入關鍵字誤判(如 74695 新約聖經被摘要「福音派」帶去福音類)。
 * 先精確比對整個標籤;不中再以大類前綴比對、只取 2 碼大類。查無回 ''。
 */
function campus_code_by_label(string $label): string
{
    static $cache = [], $stExact = null, $stTop = null;
    if (isset($cache[$label])) {
        return $cache[$label];
    }
    if ($stExact === null) {
        $stExact = db()->prepare("SELECT s.code FROM subjects s
                                  WHERE s.scheme='campus' AND s.code IS NOT NULL AND s.label = :l
                                  ORDER BY s.code LIMIT 1");
        $stTop   = db()->prepare("SELECT s.code FROM subjects s
                                  WHERE s.scheme='campus' AND s.code IS NOT NULL AND s.label LIKE :l
                                  ORDER BY s.code LIMIT 1");
    }
    $stExact->execute([':l' => $label]);
    $code = (string) ($stExact->fetchColumn() ?: '');
    if ($code === '') {
        $p   = mb_strpos($label, '／');
        $top = $p !== false ? mb_substr($label, 0, $p) : $label;
        $stTop->execute([':l' => $top . '／%']);
        $code = (string) ($stTop->fetchColumn() ?: '');
        if ($code !== '') {
            $code = substr($code, 0, 2); // 子類不確定,只信大類碼
        }
    }
    return $cache[$label] = $code;
}

// ── 載入分類 id / code ─────────────────────────────
$cats = [];
$catCode = [];
foreach (db()->query('SELECT category_id, code, name FROM categories')->fetchAll() as $r) {
    $cats[$r['name']]    = (int) $r['category_id'];
    $catCode[$r['name']] = $r['code'];
}
$campusTargets = ['聖經','聖經研究','神學','教會復興','門徒造就','見證','福音','詩本樂譜',
                  '青少年家庭','兒童教材','兒童主日學','心理','社會','倫理','傳媒','文學','藝術'];
$need = array_values(array_unique(array_merge(
    array_keys($RULES), array_keys($TITLE_ONLY), $campusTargets, [$CATCH_ALL]
)));
$missing = array_values(array_filter($need, fn($n) => !isset($cats[$n])));
if ($missing) exit("缺少分類(請先跑 2026-07-17_categories_extend.sql):" . implode('、', $missing) . "\n");

// ── scheme='cat' subjects(多分類用)──
$catSubjectId = [];
if (!$dry) {
    $insSubj = db()->prepare("INSERT INTO subjects (scheme, code, label) VALUES ('cat', :c, :l)
                              ON DUPLICATE KEY UPDATE subject_id = LAST_INSERT_ID(subject_id)");
    foreach ($need as $name) {
        $insSubj->execute([':c' => $catCode[$name] ?? null, ':l' => $name]);
        $catSubjectId[$name] = (int) db()->lastInsertId();
    }
}

// ── 取書(含校園來源碼 / 標籤)──
// --campus-only:只重分「有校園來源分類(scheme='campus')」的書,照來源代碼歸位,
//   不動基道官方分類(apply_logos_categories.php 的成果)。修正校園書被舊版關鍵字覆蓋。
if ($campusOnly) {
    $where = "b.is_published = 1 AND EXISTS (SELECT 1 FROM book_subjects bs JOIN subjects s "
           . "ON s.subject_id = bs.subject_id WHERE bs.book_id = b.book_id AND s.scheme = 'campus')";
} elseif ($orphans) {
    // 無來源分類的書:非校園來源(scheme='campus')、也非基道官方(scheme='logos'),
    // 只能靠關鍵字的新品。重分它們,不動校園來源書與基道官方分類。
    $where = "b.is_published = 1"
           . " AND NOT EXISTS (SELECT 1 FROM book_subjects bs JOIN subjects s ON s.subject_id=bs.subject_id WHERE bs.book_id=b.book_id AND s.scheme='campus')"
           . " AND NOT EXISTS (SELECT 1 FROM book_subjects bs JOIN subjects s ON s.subject_id=bs.subject_id WHERE bs.book_id=b.book_id AND s.scheme='logos')";
} else {
    $where = 'b.is_published = 1' . ($all ? '' : ' AND b.category_id IS NULL');
}
$sql = "SELECT b.book_id, b.title, b.subtitle, b.author, b.publisher, b.keywords, b.summary_short, b.summary,
        (SELECT s.code  FROM book_subjects bs JOIN subjects s ON s.subject_id=bs.subject_id
         WHERE bs.book_id=b.book_id AND s.scheme='campus' ORDER BY s.code LIMIT 1) AS campus_code,
        (SELECT s.label FROM book_subjects bs JOIN subjects s ON s.subject_id=bs.subject_id
         WHERE bs.book_id=b.book_id AND s.scheme='campus' ORDER BY s.code LIMIT 1) AS campus_label
        FROM books b WHERE $where ORDER BY b.book_id" . ($limit ? " LIMIT $limit" : '');
$rows = db()->query($sql)->fetchAll();
echo ($dry ? "[dry-run] " : "") . "待處理:" . count($rows) . " 本" . ($campusOnly ? "(--campus-only 校園來源書)" : ($orphans ? "(--orphans:全部無 campus/logos 存證的上架書 —— ★會覆寫其他 12 家來源已 apply 的 primary)" : ($all ? "(--all 全部上架)" : "(僅未分類 category_id IS NULL)"))) . "\n";

if (!$dry) {
    $unpubBook = db()->prepare('UPDATE books SET is_published = 0 WHERE book_id = :bid');
    $updBook = db()->prepare('UPDATE books SET category_id = :cid WHERE book_id = :bid');
    $delBS   = db()->prepare("DELETE bs FROM book_subjects bs JOIN subjects s ON s.subject_id=bs.subject_id
                              WHERE bs.book_id = :b AND s.scheme='cat'");
    $insBS   = db()->prepare('INSERT INTO book_subjects (book_id, subject_id, weight) VALUES (:b, :s, :w)
                              ON DUPLICATE KEY UPDATE weight = VALUES(weight)');
}

$stats = []; $multi = 0; $catchN = 0; $done = 0; $catchSample = []; $srcN = 0; $kwN = 0;
$unpubN = 0; $unpubSample = [];

if (!$dry) db()->beginTransaction();
foreach ($rows as $b) {
    // 非書商品(紙品/禮品)先下架,不進分類
    $lblHay = (string) ($b['campus_label'] ?? '');
    $ttlHay = implode(' ', array_filter([$b['title'], $b['subtitle'], $b['keywords']]));
    $nonbook = false;
    foreach ($NONBOOK_LABEL_KWS as $kw) {
        if ($lblHay !== '' && mb_strpos($lblHay, $kw) !== false) { $nonbook = true; break; }
    }
    if (!$nonbook) {
        foreach ($NONBOOK_TITLE_KWS as $kw) {
            if (mb_strpos($ttlHay, $kw) !== false) { $nonbook = true; break; }
        }
    }
    if ($nonbook) {
        $unpubN++;
        if (count($unpubSample) < 50) {
            $unpubSample[] = trim(($b['title'] ?? '') . '  〔' . ($lblHay ?: ($b['publisher'] ?? '')) . '〕');
        }
        if (!$dry) {
            $unpubBook->execute([':bid' => (int) $b['book_id']]);
            if (++$done % 500 === 0) { db()->commit(); db()->beginTransaction(); echo "  已處理 {$done}…\n"; }
        }
        continue;
    }

    $campusCode  = (string) ($b['campus_code'] ?? '');
    $campusLabel = (string) ($b['campus_label'] ?? '');
    if ($campusCode === '' && $campusLabel !== '') {
        // 新品:無代碼但有來源標籤 → 反查代碼(見 campus_code_by_label)
        $campusCode = campus_code_by_label($campusLabel);
    }
    if ($campusCode !== '') {
        // 校園:照來源代碼
        $matched = [campus_category($campusCode, $campusLabel)];
        $srcN++;
    } else {
        // 基道 / 新品:關鍵字
        $hayFull  = implode(' ', array_filter([$b['title'],$b['subtitle'],$b['author'],$b['publisher'],$b['keywords'],$b['summary_short'],$b['summary']]));
        $hayTitle = implode(' ', array_filter([$b['title'],$b['subtitle'],$b['keywords']]));
        $matched = [];
        foreach ($RULES as $cat => $kws) {
            foreach ($kws as $kw) {
                if ($kw !== '' && mb_strpos($hayFull, $kw) !== false) { $matched[] = $cat; break; }
            }
        }
        foreach ($TITLE_ONLY as $cat => $kws) {
            if (in_array($cat, $matched, true)) continue;
            foreach ($kws as $kw) {
                if ($kw !== '' && mb_strpos($hayTitle, $kw) !== false) { $matched[] = $cat; break; }
            }
        }
        if (!$matched) {
            $matched = [$CATCH_ALL];
            $catchN++;
            if ($dumpN && count($catchSample) < $dumpN) {
                $catchSample[] = trim(($b['title'] ?? '') . '  〔' . ($b['publisher'] ?? '') . '〕');
            }
        }
        $kwN++;
    }
    $matched = array_values(array_unique($matched));
    if (count($matched) >= 2) $multi++;
    // 受眾/形式類(兒童教材、兒童主日學)只在「沒有其他主題類命中」時才當 primary,
    // 避免成人書因偶然命中受眾關鍵字被誤歸兒童(仍保留為次要標籤)。
    static $LOW_PRIMARY = ['兒童教材', '兒童主日學'];
    $pool = array_values(array_filter($matched, fn($c) => !in_array($c, $LOW_PRIMARY, true)));
    $primary = $pool ? $pool[0] : $matched[0];
    $stats[$primary] = ($stats[$primary] ?? 0) + 1;

    if (!$dry) {
        $updBook->execute([':cid' => $cats[$primary], ':bid' => (int) $b['book_id']]);
        $delBS->execute([':b' => (int) $b['book_id']]);
        foreach ($matched as $i => $cat) {
            $insBS->execute([':b' => (int) $b['book_id'], ':s' => $catSubjectId[$cat], ':w' => $i === 0 ? 10 : 5]);
        }
        if (++$done % 500 === 0) { db()->commit(); db()->beginTransaction(); echo "  已處理 {$done}…\n"; }
    }
}
if (!$dry && db()->inTransaction()) db()->commit();

// ── 報表 ──
arsort($stats);
echo "\n== primary 分類分布 ==\n";
foreach ($stats as $cat => $n) echo sprintf("  %-8s %6d\n", $cat, $n);
$total = count($rows);
$catchPct = $total ? round($catchN * 100 / $total, 1) : 0;
echo "\n來源: 校園(照來源){$srcN} 本、基道/新品(關鍵字){$kwN} 本\n";
echo "多分類(>=2 類):{$multi};落入「{$CATCH_ALL}」:{$catchN}({$catchPct}%)\n";
echo "非書商品下架(紙品/月曆等):{$unpubN}\n";
if ($unpubN && $unpubSample) {
    echo "== 下架清單樣本(前 " . count($unpubSample) . " 筆,複核用)==\n";
    foreach ($unpubSample as $t) echo "  - $t\n";
}

if ($dumpN && $catchSample) {
    echo "\n== 落入「{$CATCH_ALL}」樣本(前 " . count($catchSample) . " 本,調規則用)==\n";
    foreach ($catchSample as $t) echo "  - $t\n";
}

echo "\n" . ($dry ? "[dry-run 未寫入] " : "完成寫入 ") . "共 {$total} 本\n";
echo $dry
    ? "確認分布合理後,拿掉 --dry-run 再跑一次即寫入。\n"
    : "驗證:SELECT COUNT(*) FROM books WHERE is_published=1 AND category_id IS NULL; -- 應為 0\n";
