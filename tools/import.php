<?php
declare(strict_types=1);

/**
 * 爬蟲 JSONL → 正規化關聯表匯入器(D5)
 *
 * 依 docs/data-mapping-import.md:
 * - 直接寫關聯表:persons/book_persons、publishers、editions、identifiers、
 *   subjects/book_subjects、formats_prices、links、media;books 平面欄位為過渡後備
 * - 跨站合併:ISBN13 同 → 同一 book(Work),各站各建一個 edition(價格各記幣別:campus=TWD、logos=HKD)
 *   無 ISBN → 「正規化書名+第一作者」完全相同才合併;存疑不合併(寧可重複待人工)
 * - 不丟資料:原始紀錄整包存 books.extra JSON(依來源分鍵)
 * - 可重跑:已匯入的版本(source+source_url)自動跳過;封面先記 media,R2 轉存另跑
 *
 * 用法(主機 SSH;JSONL 先 FTP 上傳):
 *   php tools/import.php --file=crawler/data/logos_books.jsonl  --source=logos  --dry-run
 *   php tools/import.php --file=crawler/data/logos_books.jsonl  --source=logos
 *   php tools/import.php --file=crawler/data/campus_books.jsonl --source=campus
 *   php tools/import.php --file=crawler/data/elim_books.jsonl   --source=elim
 *   php tools/import.php --file=crawler/data/grace_books.jsonl  --source=grace
 *   php tools/import.php --file=crawler/data/wdbook_books.jsonl --source=wdbook
 *   php tools/import.php --file=crawler/data/methodist_books.jsonl --source=methodist
 *   php tools/import.php --file=crawler/data/osb_books.jsonl    --source=osb
 *   php tools/import.php --file=crawler/data/taosheng_books.jsonl --source=taosheng
 *   php tools/import.php --file=crawler/data/cclm_books.jsonl  --source=cclm
 *   php tools/import.php --file=crawler/data/cosmiccare_books.jsonl --source=cosmiccare
 *   php tools/import.php --file=crawler/data/mezu_books.jsonl      --source=mezu
 *
 * elim(以琳書房,2026-07-31):紀錄含 categories 完整清單(一書多分類,
 * 路徑碼+名稱路徑),全部寫 subjects(scheme='elim')原樣存證;站內瀏覽分類
 * 之後由 tools/apply_elim_categories.php 依 elim_category_map 對映(雙軌並存)。
 *
 * grace(天恩出版社,2026-08-06):同 elim 的雙軌分類(subjects scheme='grace'
 * 存證 → tools/apply_grace_categories.php 依 grace_category_map 對映)。
 * 電子書(紀錄 is_ebook=true,8/6 決議):照書上架、與紙本同書合併——
 * 同名同作者即使 ISBN 不同(電子書各有 eISBN)也視為同一作品的另一版本;
 * 價格 media_type='ebook',購書連結標示「天恩出版社(電子書)」與紙本並列。
 *
 * wdbook(微讀書城,2026-08-09):WeDevote 純電子書店(USD)。全站皆
 * 電子書(is_ebook=true),與紙本同書合併沿 8/6 天恩電子書規則;簡體書
 * 欄位已由爬蟲以 OpenCC 轉繁體入庫(原始簡體存 extra.hans),購書連結
 * 標「微讀書城」(簡體書標「微讀書城(簡體)」)。雙軌分類
 * subjects(scheme='wdbook', code=微讀分類 id)存證 →
 * tools/apply_wdbook_categories.php 依 wdbook_category_map 對映。
 *
 * osb(格子外面,2026-08-18):Cyberbiz 商城(TWD,全站繁體)。範圍=
 * 全部書籍(osb)+聖經三分類+★新書到(沿以琳「只抓書籍+聖經」);雙軌分類
 * subjects(scheme='osb', code=collection handle,中文 handle 直接入 code)
 * 存證 → tools/apply_osb_categories.php 依 osb_category_map 對映。
 * 購書連結標「格子外面」。
 *
 * taosheng(道聲,2026-08-19):Cyberbiz 商城(與 osb 同平台,TWD)。全站
 * 抓入存證(1,637 件),非書(影音/月曆/桌遊/刮刮卡/福音機)由對映表下架;
 * 代銷他社書全收(8/19 決議),同 ISBN 自動跨站合併。雙軌分類
 * subjects(scheme='taosheng') → tools/apply_taosheng_categories.php。
 *
 * cclm(橄欖華宣,2026-08-19):自建 SSR 商城(TWD)。範圍=書籍全枝
 * (約 4,400)+聖經全枝(沿以琳「只抓書籍+聖經」);聖經周邊非書由對映表
 * 下架。雙軌分類 subjects(scheme='cclm') → tools/apply_cclm_categories.php。
 *
 * cosmiccare(宇宙光全人關懷機構,2026-08-21):自建 SSR 商城(TWD)。全站
 * 抓入存證(1,671 件:書籍/繪本/雜誌/影音/禮品),非書(影音/禮品/雜誌訂閱/
 * 海外運費)由對映表下架;《宇宙光雜誌》約 120 期收錄(歸「期刊雜誌」,
 * ISBN 欄放的是 ISSN 條碼 977…,爬蟲不會誤認為 ISBN13)。作者系列 Tag 與
 * ★福利書不參與分類(8/21 決議)。雙軌分類 subjects(scheme='cosmiccare')
 * → tools/apply_cosmiccare_categories.php。
 *
 * mezu(真哪噠買書網,2026-08-22):EasyStore 商城(TWD,繁體)。全站
 * 10,027 件抓入存證——商品清單走 sitemap_products.xml(權威全站清單),
 * 分類歸屬走 119 個 collection 清單;非書(影音/禮品/文具/客製化月曆)
 * 由對映表下架。站方商品描述是 Froala 自由文字、欄位標籤不統一,故原文
 * 整段存 extra.desc_raw、通用標籤採集存 extra.spec_all,只有白名單欄位
 * 入平面欄(8/22 決議:欄位解析留待後續版本)。雙軌分類
 * subjects(scheme='mezu')→ tools/apply_mezu_categories.php。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/api/lib/db.php';
// 書名促銷詞剝除規則。與 tools/check_title_promo.php **共用同一份** ——
// 那支是這條規則的驗證者(產 dry-run 對照表給人勾),兩邊各寫一份會悄悄分家。
require_once __DIR__ . '/lib_title.php';
// 人名正規化規則(entity 解碼、角色詞、字尾註記、分隔符與括號定義)。
// 與 tools/check_person_names.php、tools/fix_person_names.php **共用同一份**。
require_once __DIR__ . '/lib_person.php';

/**
 * 來源 → 幣別對映(2026-09-01 決議 A,海外五站前置)。
 *
 * 原本是 import 內一行硬編碼三元式:`$source === 'logos' ? 'HKD' : 'TWD'`,
 * 只認得基道一站是港幣、其餘一律台幣。海外站(天道、突破、海天、浸信會=港幣;
 * 麥種=美金)接進來會被整批誤標成台幣,而 formats_prices.currency 一旦寫錯,
 * 書目頁就會把 HK$128 顯示成「TWD 128」——數字對、幣別錯,比缺值更難察覺。
 *
 * 規則:爬蟲輸出的 currency 優先,本表只當 fallback;不做匯率換算,
 * 書目頁直接標示原幣。新增來源務必在此登錄,漏登會靜默落回 TWD。
 */
const SOURCE_CURRENCY = [
    // 台灣
    'campus'     => 'TWD', 'elim'    => 'TWD', 'grace'      => 'TWD',
    'wdbook'     => 'TWD', 'osb'     => 'TWD', 'taosheng'   => 'TWD',
    'cclm'       => 'TWD', 'cosmiccare' => 'TWD', 'mezu'    => 'TWD',
    'twgbr'      => 'TWD', 'pctpress' => 'TWD',
    // 香港
    'logos'      => 'HKD', 'tiendao' => 'HKD', 'rockhouse'  => 'HKD',
    'btproduct'  => 'HKD', 'bappress' => 'HKD',
    // 美國
    'akow'       => 'USD',
    // 馬來西亞(衛理書房,砂拉越;既有行為即 MYR 標價但先前落 TWD,一併更正)
    'methodist'  => 'MYR',
];
const DEFAULT_CURRENCY = 'TWD';

// ★ 沒有線上購書管道的來源(熊哥 2026-09-05 裁示,起於突破機構)。
//   這些站的商品頁網址不是購書連結,而是「書籍資訊」的一般外部連結
//   → links.link_type 寫 'official' 而非 'buy',api/index.php 的購書彙整就不會撈到它,
//     前端也就不會長出一個點下去買不到書的假購書按鈕。
//   突破機構 buy.php 只是批發聯絡資訊頁(電話/傳真/電郵),不是購物車。
//   ★ 2026-09-21 更正:浸信會(bappress)**不屬於此列**。票上與本註解原本都推測它
//     「無購書管道、可沿用突破機構作法」,9/20–9/21 實測推翻:shop.bappress.org 是
//     Yii SSR 網上書店,有 addtocart、會員與運費計算,幣別 HKD → 購書連結照常寫 'buy'。
//     (這已是本專案第三次「票上的站台描述是建票當時的推測」被實測推翻。)
//   ★ 2026-09-13 更正:麥種(akow)**不屬於此列**。原票假設它只有訂購頁,
//     實測是完整的 WooCommerce 購物車(USD 結帳),購書連結照常寫 'buy'。
const NO_BUY_SOURCES = ['btproduct'];

/* 站方 ISBN 不具唯一性的來源(2026-09-13 麥種 akow 實測)
 * ────────────────────────────────────────────────────────────
 * 麥種母資料有「同一個 ISBN 掛在兩本不同書上」,且 akow.org 與 akow.tw 兩站
 * 完全一致 → 是出版社母資料錯,不是單站手誤,日後新書還會再犯:
 *   9781951456115《真正的快樂》 vs《現代神學精髓》
 *   9781939251176《以西結書註釋(上下)》vs《主耶穌的畫像》(連出版日期都被複製)
 *   9781939251015《舊約歷史書手冊》 vs《主耶穌的比喻》
 * 對這些來源,ISBN 命中不再是硬證據,要再確認「真的是同一作品」才合併:
 *   (1) 書名+第一作者的模糊鍵相同,或
 *   (2) 一繁一簡的同書(去掉簡體標記後字數相同、逐字四成以上相同)
 * 判不出來就不合併、且本筆不寫 isbn13(原值仍完整留在 extra 的 isbn/isbn_from),
 * 寧可重複待人工,也不要把兩本不相干的書併成一本。
 * ★ 只在「同一次匯入的同來源」之間生效,跨站 ISBN 合併行為完全不變。
 */
const ISBN_NOT_UNIQUE_SOURCES = ['akow'];

$opt    = getopt('', ['file:', 'source:', 'limit::', 'dry-run']);
$file   = $opt['file'] ?? null;
$source = $opt['source'] ?? null;
$limit  = (int) ($opt['limit'] ?? 0);
$dry    = array_key_exists('dry-run', $opt);
$SOURCES = array_keys(SOURCE_CURRENCY);
if (!$file || !in_array($source, $SOURCES, true)) {
    exit("用法:php tools/import.php --file=xxx.jsonl --source=" . implode('|', $SOURCES) . " [--limit=N] [--dry-run]\n");
}
if (!is_file($file)) {
    exit("找不到檔案:$file\n");
}

// ── 工具函式 ─────────────────────────────────────────────

function norm_isbn(?string $s): ?string
{
    if (!$s) return null;
    $s = strtoupper(preg_replace('/[^0-9Xx]/', '', $s));
    if (preg_match('/^(97[89]\d{10})$/', $s)) return $s;                // ISBN13
    if (preg_match('/^\d{9}[\dX]$/', $s)) return $s;                    // ISBN10
    return null;
}

function isbn10_to_13(string $isbn10): string
{
    $core = '978' . substr($isbn10, 0, 9);
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int) $core[$i] * ($i % 2 ? 3 : 1);
    }
    return $core . ((10 - $sum % 10) % 10);
}

/** 任意 ISBN → [isbn13, isbn10](缺者為 null) */
function isbn_pair(?string $raw): array
{
    $n = norm_isbn($raw);
    if (!$n) return [null, null];
    return strlen($n) === 13 ? [$n, null] : [isbn10_to_13($n), $n];
}

/** YYYYMMDD/YYYY-MM-DD/YYYY → [書用 YYYY-MM 或 YYYY, 版本用完整] */
function parse_date(?string $s): array
{
    if (!$s) return [null, null];
    $d = preg_replace('/[^0-9]/', '', $s);
    if (strlen($d) >= 8) return [substr($d, 0, 4) . '-' . substr($d, 4, 2),
                                 substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2)];
    if (strlen($d) >= 6) return [substr($d, 0, 4) . '-' . substr($d, 4, 2), substr($d, 0, 4) . '-' . substr($d, 4, 2)];
    if (strlen($d) >= 4) return [substr($d, 0, 4), substr($d, 0, 4)];
    return [null, null];
}

/** 舊 bug 殘值(「出版社：」等純標籤)視為空 */
function tidy(?string $s): ?string
{
    $s = trim((string) $s);
    if ($s === '' || preg_match('/^[^:：]{0,8}[:：]$/u', $s)) return null;
    return $s;
}

/**
 * 截斷至欄位長度(以字元計,保留完整多位元組)。
 * MySQL VARCHAR(n) 對 utf8mb4 以「字元」計長,故 mb_substr 至 n 字元即安全。
 * 完整原文另整包存於 books.extra,截斷僅影響平面後備欄的顯示,不損資料。
 */
function cap(?string $s, int $n): ?string
{
    if ($s === null) return null;
    return mb_strlen($s, 'UTF-8') > $n ? mb_substr($s, 0, $n, 'UTF-8') : $s;
}

/**
 * 多人名拆分 —— 規則本體在 tools/lib_person.php,**全專案只有那一份**。
 * 這裡只負責套上本檔的 tidy()(舊 bug 殘值視為空)與 cap()(對照欄寬截斷)。
 *
 * ════════ 為什麼規則不留在這裡 ════════
 * 2026-10-03(Asana 1218277971470042):tools/fix_person_names.php 要用
 * **一模一樣**的切法重切既有資料。切法留在 import.php 裡,修復工具就得抄一份;
 * 兩份只要差一點,修復工具補出來的 persons 就會跟匯入器不一致,而且不會有人發現。
 * 本案已經為「同一組分隔符寫兩份」付過代價(全形分號兩個月沒被拆過)。
 *
 * ════════ 這支修過的三件事(時間順序) ════════
 * (1) 2026-09-08 全形分號 U+FF1B 從未被拆:舊字元集的 hexdump 是 5b 3b 3b e3 80 81 5d,
 *     也就是 [ ; ; 、 ] —— 兩個半形分號、沒有全形分號。全形分號在某次編輯中退化成
 *     第二個半形分號,regex 照樣編譯得過,只是靜默少拆一半。
 *     → 分隔符一律以 \u{} escape 書寫(純 ASCII,不會退化),且定義只留一份。
 * (2) 2026-09-08 切割改為**括號感知**:括號內的分隔符不切
 *     (「…創作小組 (彼、桀、onki、gi)」不該拆成六個人)。
 * (3) ★★ 2026-10-03 **decode 排到切割之前**:entity 結尾的 `;` 就是分隔符,
 *     「Anselm Gr&uuml;n」先切再解碼會變成兩個人。順序比內容重要。
 *     同批加上角色詞與字尾註記剝除(「文」自成一列、「梁家麟著」vs「梁家麟」)。
 *
 * ★ 共用邏輯改動的已知影響:split_names() 也餵給 fuzzy_key() 建 $fuzzyMap,
 *   所以「梁家麟著 → 梁家麟」這類收斂會**讓跨站模糊比對多命中幾筆**。
 *   這是本次要的效果,但上主機後務必先對既有來源跑一次 --dry-run,
 *   看合併明細是「小幅增加」而不是暴增(陷阱 27:0 和暴增都要當場追)。
 */
function split_delims(string $s): array
{
    return pn_split_delims($s, function (string $raw): void {
        echo "\n  [警告] 人名括號不成對,改用直接切割:{$raw}\n";
    });
}

function split_names(?string $raw): array
{
    // tidy() 判「純標籤殘值」(「出版社:」「作者&nbsp;:」)要在 decode 之後才準,
    // 所以這裡 decode 一份**只為了做這個判斷**;真正的切割由 pn_split_names()
    // 自己 decode —— decode 的位置是它的契約的一部分,不外包給呼叫端,
    // 否則早晚又會有人在外面先切一刀(那正是本次要修的 bug)。
    if (tidy(decode_person_entities($raw)) === null) return [];
    $out = [];
    foreach (pn_split_names($raw) as $a) {
        $out[] = [
            'name'   => cap($a['name'], 150),    // persons.name(150)
            'credit' => cap($a['credit'], 255),  // book_persons.credit_text(255)
            'role'   => $a['role'],              // null = 沿用欄位本身的角色
        ];
    }
    return $out;
}

/** 模糊合併鍵:書名+第一作者(去空白+標點符號、轉小寫;2026-08-03 修:
 *  與 merge_duplicate_books.php 的 norm() 同步,避免「：」vs「--」等標點變體再拆成兩筆) */
function fuzzy_key(?string $title, ?string $firstAuthor): ?string
{
    if (!$title || !$firstAuthor) return null;
    $n = fn($s) => mb_strtolower(preg_replace('/[\s\p{P}\p{S}]+/u', '', $s), 'UTF-8');
    return $n($title) . '|' . $n($firstAuthor);
}

/** 去掉書名尾端的簡繁標記,讓「基督徒的信仰／简」與「基督徒的信仰」可比字數 */
function strip_script_tag(?string $t): string
{
    $t = preg_replace('/[（(\/／\-－]\s*(简体|簡體|正體|繁體|简|簡)\s*[)）]?\s*$/u', '', (string) $t);
    return trim((string) $t);
}

/** 最長共同子字串的字數(短書名,O(n*m) 足夠) */
function lcs_len(string $a, string $b): int
{
    $n = mb_strlen($a, 'UTF-8');
    $m = mb_strlen($b, 'UTF-8');
    if ($n === 0 || $m === 0) return 0;
    $prev = array_fill(0, $m + 1, 0);
    $best = 0;
    for ($i = 1; $i <= $n; $i++) {
        $cur = array_fill(0, $m + 1, 0);
        $ca = mb_substr($a, $i - 1, 1, 'UTF-8');
        for ($j = 1; $j <= $m; $j++) {
            if ($ca === mb_substr($b, $j - 1, 1, 'UTF-8')) {
                $cur[$j] = $prev[$j - 1] + 1;
                if ($cur[$j] > $best) $best = $cur[$j];
            }
        }
        $prev = $cur;
    }
    return $best;
}

/** 同一個 ISBN 上的兩筆,是否真的是同一作品(僅用於 ISBN_NOT_UNIQUE_SOURCES)。
 *
 *  ★ 2026-09-14 放寬:第一版只認「模糊鍵相同」與「簡繁同字數」,結果把**正常的
 *    跨站合併也擋掉了**(dry-run 擋了 30 次,其中 26 次是誤擋)——別站的書名常
 *    帶書系前綴或副標:「腓立比書」vs「麥種聖經註釋:腓立比書」、
 *    「保羅神學聖靈論」vs「保羅神學:聖靈論」。這個防線的本分是「書名明顯對不上時
 *    才攔」,不是「書名不完全一樣就攔」。
 *
 *  四條判準,任一成立即視為同一作品:
 *   (1) 書名+第一作者的模糊鍵相同
 *   (2) 去標點後一方包含另一方(書系前綴/副標/冊次)
 *   (3) 最長共同子字串 ≥3 字且占短書名一半以上
 *       (「以賽亞書註釋」vs「以賽亞書(下)-麥種聖經註釋(NICOT)」共同「以賽亞書」4/6)
 *   (4) 簡繁同書:字數相同且逐字四成以上相同(簡繁差的是字形不是字數)
 *       —— 這條不能少:「聖經的偉大教義」vs「圣经的伟大教义」共同子字串只有 1 字,
 *          過不了 (3),但它們確實是同一本。
 *
 *  站方真正抄錯的那幾組四條全過不了:「真正的快樂」vs「現代神學精髓」、
 *  「舊約歷史書手冊」vs「主耶穌的比喻」、「喜樂平安的人生」vs「當主耶穌面對世界」
 *  ——共同子字串 ≤1 字、字數也不同。
 */
function same_work(?string $t1, ?string $a1, ?string $t2, ?string $a2): bool
{
    $k1 = fuzzy_key($t1, $a1);
    $k2 = fuzzy_key($t2, $a2);
    if ($k1 !== null && $k1 === $k2) return true;                       // (1)

    $norm = fn($s) => (string) preg_replace('/[\s\p{P}\p{S}]+/u', '', strip_script_tag($s));
    $s1 = $norm($t1);
    $s2 = $norm($t2);
    if ($s1 === '' || $s2 === '') return false;
    if ($s1 === $s2) return true;

    if (mb_strpos($s1, $s2, 0, 'UTF-8') !== false                       // (2)
        || mb_strpos($s2, $s1, 0, 'UTF-8') !== false) return true;

    $l1  = mb_strlen($s1, 'UTF-8');
    $l2  = mb_strlen($s2, 'UTF-8');
    $min = min($l1, $l2);
    $lcs = lcs_len($s1, $s2);
    if ($lcs >= 3 && $min > 0 && $lcs / $min >= 0.5) return true;       // (3)

    if ($l1 !== $l2) return false;                                      // (4)
    $same = 0;
    for ($i = 0; $i < $l1; $i++) {
        if (mb_substr($s1, $i, 1, 'UTF-8') === mb_substr($s2, $i, 1, 'UTF-8')) $same++;
    }
    return $l1 > 0 && $same / $l1 >= 0.4;
}

/** 重量「850克」→ 850 */
function weight_g(?string $s): ?int
{
    return $s && preg_match('/(\d+)/', $s, $m) ? (int) $m[1] : null;
}

// ── 來源映射(→ 統一中介格式) ───────────────────────────

function map_record(string $source, array $r): array
{
    $isJunkTitle = !tidy($r['title'] ?? null);
    [$isbn13, $isbn10] = isbn_pair($r['isbn'] ?? ($r['isbn_meta'] ?? null));
    [$bDate, $eDate]   = parse_date($r['publish_date'] ?? null);
    // 以琳多人名以「/」分隔(如「薛玉光/古維華」)→ 先換成頓號再拆;
    // 僅 elim 適用,避免影響其他來源既有行為。原始字串仍完整保留於 *_raw 與 extra。
    $names = fn(?string $s): array => split_names(
        $source === 'elim' && $s !== null ? str_replace('/', '、', $s) : $s);
    $m = [
        // ★ 2026-09-29:剝掉站方寫進商品名稱的促銷詞(（新書79折）、(特價)、【瑕疵…】…)。
        //   書名是 fuzzy_key() 的輸入,促銷尾註會讓跨站比對比不中 → 站上長出重複書
        //   (已實測:79674↔45452↔83271、79675↔102456、88945↔62842)。
        //   規則不確定時 strip_title_promo() 回 hold 並退回原值,**絕不半套**。
        //   站方原始書名不必另存:$extraRec = $raw 整筆進 books.extra[來源],原值一直在。
        'title'          => cap(strip_title_promo(tidy($r['title'] ?? null))['clean'], 255),
        'original_title' => cap(tidy($r['title_en'] ?? null), 255),
        'authors'        => $names($r['authors_raw'] ?? null),
        'translators'    => $names($r['translators_raw'] ?? null),
        'illustrators'   => $names($r['illustrators_raw'] ?? null),
        'editors'        => $names($r['editors_raw'] ?? null),
        'authors_raw'    => cap(tidy($r['authors_raw'] ?? null), 255),
        'publisher'      => cap(tidy($r['publisher'] ?? null), 100), // books.publisher(100)、publishers.name_zh(150) 取小者
        'book_date'      => $bDate,
        'edition_date'   => $eDate,
        'isbn13'         => $isbn13,
        'isbn10'         => $isbn10,
        'page_count'     => isset($r['page_count']) ? (int) $r['page_count'] : null,
        'binding'        => cap(tidy($r['binding'] ?? null), 50),
        'language'       => cap(tidy($r['language'] ?? null), 50),
        'series'         => cap(tidy($r['series_text'] ?? ($r['series'] ?? null)), 255),
        'summary'        => str_replace("\t", "\n", trim((string) ($r['summary'] ?? ''))) ?: null,
        'keywords'       => cap(tidy($r['keywords'] ?? null), 500),
        'dimensions'     => cap(tidy($r['dimensions'] ?? null), 50),
        'weight_g'       => weight_g($r['weight'] ?? null),
        'store_code'     => cap(tidy($r['item_no'] ?? ($r['code'] ?? null)), 30),
        'price'          => isset($r['price_list']) && $r['price_list'] !== '' ? $r['price_list']
                            : ($r['price_sale'] ?? null),
        'currency'       => tidy($r['currency'] ?? null) ?: (SOURCE_CURRENCY[$source] ?? DEFAULT_CURRENCY), // 爬蟲值優先,對映表當 fallback(決議 A)
        'cover_url'      => tidy($r['cover_url'] ?? null),
        'source_url'     => $r['source_url'] ?? null,
        'subject_code'   => cap(tidy($r['category_source'] ?? null), 40), // 2026-08-25 20→40:subjects.code 已加寬(福音書房 24 碼 ID handle)
        'subject_label'  => cap(tidy($r['category_text'] ?? null), 150),
        'skip'           => $isJunkTitle,
    ];
    // 來源分類清單(elim:一書多分類,原樣存證;其他來源退回單一平面欄位)
    $m['subjects'] = [];
    foreach ((array) ($r['categories'] ?? []) as $c) {
        $code  = cap(tidy(is_array($c) ? ($c['code'] ?? null) : null), 40); // 同上,subjects.code(40)
        $label = cap(tidy(is_array($c) ? ($c['path'] ?? null) : null), 150);
        if ($code || $label) $m['subjects'][] = [$code, $label ?: $code];
    }
    if (!$m['subjects'] && ($m['subject_code'] || $m['subject_label'])) {
        $m['subjects'][] = [$m['subject_code'], $m['subject_label'] ?: $m['subject_code']];
    }
    return $m;
}

// ── 預載(可重跑 + 跨站合併的比對基礎) ───────────────────

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

$doneUrls = [];
foreach ($pdo->query("SELECT source_url FROM editions WHERE source_url IS NOT NULL") as $r) {
    $doneUrls[$r['source_url']] = true;
}
$isbnMap = [];
foreach ($pdo->query("SELECT book_id, isbn13 FROM books WHERE isbn13 IS NOT NULL") as $r) {
    $isbnMap[$r['isbn13']] = (int) $r['book_id'];
}
foreach ($pdo->query(
    "SELECT e.book_id, i.id_value FROM identifiers i JOIN editions e ON e.edition_id = i.edition_id
     WHERE i.id_type = 'ISBN13'") as $r) {
    $isbnMap[$r['id_value']] = (int) $r['book_id'];
}
$fuzzyMap = [];
$bookIsbn = [];   // book_id → isbn13(模糊命中時判斷可否合併用)
$bookWork = [];   // book_id → [title, 第一作者](ISBN 不唯一來源的同作品判定用)
foreach ($pdo->query("SELECT book_id, title, author, isbn13 FROM books") as $r) {
    $bookIsbn[(int) $r['book_id']] = $r['isbn13'] ?: null;
    $first = split_names($r['author'])[0]['name'] ?? null;
    $bookWork[(int) $r['book_id']] = ['title' => $r['title'], 'author' => $first];
    $k = fuzzy_key($r['title'], $first);
    if ($k) $fuzzyMap[$k] = (int) $r['book_id'];
}
// 同一次匯入裡已出現過的 ISBN → 該筆的書名/作者(同檔內撞號時要比對的對象)
$isbnSeen = [];
$personMap = [];
foreach ($pdo->query("SELECT person_id, name FROM persons") as $r) {
    $personMap[$r['name']] = (int) $r['person_id'];
}
$pubMap = [];
foreach ($pdo->query("SELECT publisher_id, name_zh FROM publishers") as $r) {
    $pubMap[$r['name_zh']] = (int) $r['publisher_id'];
}
$subjMap = [];
foreach ($pdo->query("SELECT subject_id, scheme, code, label FROM subjects") as $r) {
    $subjMap[$r['scheme'] . '|' . $r['code'] . '|' . $r['label']] = (int) $r['subject_id'];
}
echo "預載:editions " . count($doneUrls) . "、isbn " . count($isbnMap)
   . "、fuzzy " . count($fuzzyMap) . "、persons " . count($personMap) . "\n";

// ── 匯入主迴圈 ───────────────────────────────────────────

$stats = ['read' => 0, 'skip_done' => 0, 'skip_bad' => 0, 'new_book' => 0, 'merged' => 0, 'edition' => 0,
          // dry-run 合併明細(8/21 起):合併率異常高時要能當場分辨
          // 「ISBN 命中」與「書名+第一作者模糊比對」,後者才是誤併風險所在。
          'merge_isbn' => 0, 'merge_fuzzy' => 0, 'merge_infile' => 0,
          // 站方 ISBN 不唯一而擋下的合併(akow)——正常應該只有個位數,
          // 若某次突然變多,代表站方又批次抄錯,要回頭看來源不是看程式
          'isbn_conflict' => 0];
$fuzzySamples = [];   // dry-run:模糊命中的前 N 筆,供人眼核對
$newSamples   = [];   // dry-run:判定為新書的全部筆數,供抽查「新書真的是新書」
$fh = fopen($file, 'r');
$batch = 0;
if (!$dry) $pdo->beginTransaction();

while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $raw = json_decode($line, true);
    if (!is_array($raw)) { $stats['skip_bad']++; continue; }
    $stats['read']++;
    if ($limit && $stats['read'] > $limit) break;

    $m = map_record($source, $raw);
    if ($m['skip'] || !$m['source_url']) { $stats['skip_bad']++; continue; }
    if (isset($doneUrls[$m['source_url']])) { $stats['skip_done']++; continue; }

    // 電子書(8/6 天恩決議;8/9 微讀沿用):價格記 ebook、放寬同名合併。
    // 購書連結:天恩電子書標「(電子書)」;微讀全站皆電子書故不加尾綴,
    // 惟簡體書標「(簡體)」,與繁體版本並列時可辨。
    $isEbook     = in_array($source, ['grace', 'wdbook'], true) && !empty($raw['is_ebook']);
    $platformName = ['campus' => '校園書房', 'logos' => '基道 BookFinder',
                    'elim' => '以琳書房', 'grace' => '天恩出版社',
                    'wdbook' => '微讀書城', 'methodist' => '衛理書房',
                    'osb' => '格子外面', 'taosheng' => '道聲', 'cclm' => '橄欖華宣',
                    'cosmiccare' => '宇宙光', 'mezu' => '真哪噠',
                    'twgbr' => '福音書房', 'pctpress' => '教會公報社',
                    // 海外(2026-09 起,一站一版 v1.11.0～v1.15.0)
                    'tiendao' => '天道書樓', 'btproduct' => '突破機構',
                    'rockhouse' => '海天書樓', 'bappress' => '浸信會出版社',
                    'akow' => '麥種傳道會'][$source] ?? null;
    // 漏登名稱會讓購書鈕變成空標題,寧可當場中止也不要產出無名連結
    if ($platformName === null) {
        exit("來源 $source 未登錄購書平台名稱,請補 \$platformName 對映表\n");
    }
    // ★ 無購書管道的來源不進 buy_links 平面欄位。
    //   只改 links.link_type 是不夠的 —— api/index.php:499 的購書彙整是
    //   「links(link_type='buy') + books.buy_links(平面後備)」兩邊聯集,
    //   漏掉平面欄位一樣會在書目頁長出點下去買不到書的假購書按鈕。
    // ★ 2026-09-22(熊哥裁示):站方重複建檔的那一筆不掛購書連結。
    //   起於 bappress 的 `-D####` 貨號:同書名、同 Barcode、同 ISBN、同定價、同分類,
    //   頁面上沒有任何版本標記 —— 兩條連結掛上去只會是兩顆看不出差別的按鈕。
    //   ★ 旗標由爬蟲判定並寫在資料裡(is_dup_listing),**不是這裡用貨號長相去猜**:
    //     -D 後綴只有在「主貨號也確實存在」時才算重複,否則它就是唯一一筆,
    //     拿掉連結會讓那本書一條購書管道都沒有。
    //   商品代碼仍照常寫進 identifiers(STORE),對帳不受影響。
    $hasBuy = !in_array($source, NO_BUY_SOURCES, true) && empty($raw['is_dup_listing']);
    // 購書連結的平台名要標出版本,否則同一本書下掛兩條連結時看不出差別。
    // 2026-09-14 加入 akow:麥種同一作品常有正體/簡體兩版,爬蟲已判定 script(hant/hans)。
    //   ★ 麥種的簡體版一律另編 ISBN(已知 4/4 案例),所以多數情況下簡繁是兩本書、
    //     各自只有一條連結;但站方偶有簡繁共用同一個 ISBN 的情形(如麥種基督教要義),
    //     那時兩版會併成同一本書、掛兩條連結 —— 就是靠這個標註分辨。
    // ★ 2026-09-22(熊哥裁示):通用版本標註。爬蟲判定得出差異就寫在 variant_tag,
    //   這裡直接採用,不必為每個來源再加一條 source 分支。
    //   起於 bappress:同一個 ISBN 掛著「19克超薄和合本皮面聖經-藍色」與「-紅色」
    //   兩個商品(而 /books/{該ISBN} 本身是 404),併成一本後另一色的書名會消失,
    //   兩條連結若不標註就完全看不出差別。
    //   ★ 爬蟲那邊的判準是「同 ISBN 有多筆且書名不同時才抽差異詞」——
    //     不是看到顏色詞就標,否則《藍色的天空》也會被標成「(藍色)」。
    $variantTag = '';
    if (!empty($raw['variant_tag'])) {
        $variantTag = '(' . tidy((string) $raw['variant_tag']) . ')';
    } elseif ($source === 'grace' && $isEbook) {
        $variantTag = '(電子書)';
    } elseif (in_array($source, ['wdbook', 'methodist'], true) && !empty($raw['is_hans'])) {
        $variantTag = '(簡體)';
    } elseif ($source === 'akow') {
        $variantTag = (($raw['script'] ?? '') === 'hans') ? '(簡體)' : '(正體)';
    }
    $buyPlatform = $platformName . $variantTag;

    // 1. 找/建 book(Work)
    $bookId = null;
    $isMerge = false;
    $mergeVia = null;                 // 'isbn' | 'fuzzy' | 'infile'(dry-run 明細用)
    $isbnBlocked = false;             // ISBN 命中但判定不是同一作品(見下)
    if ($m['isbn13'] && isset($isbnMap[$m['isbn13']])) {
        $bookId = $isbnMap[$m['isbn13']];
        $isMerge = true;
        // dry-run 時新書會以 -1 佔位,故 -1 代表「同一檔案內重複的 ISBN」而非在庫命中
        $mergeVia = $bookId === -1 ? 'infile' : 'isbn';
        // ★ 站方 ISBN 不唯一的來源(akow):ISBN 命中不算硬證據,要再確認是同一作品。
        //   同檔內撞號比 $isbnSeen,在庫命中比 $bookWork;判不出來就不合併也不寫 isbn13。
        if (in_array($source, ISBN_NOT_UNIQUE_SOURCES, true)) {
            $other = $bookId === -1 ? ($isbnSeen[$m['isbn13']] ?? null)
                                    : ($bookWork[$bookId] ?? null);
            if ($other !== null
                && !same_work($m['title'], $m['authors'][0]['name'] ?? null,
                              $other['title'] ?? null, $other['author'] ?? null)) {
                echo "  [ISBN 不唯一] {$m['isbn13']}:「{$m['title']}」≠「{$other['title']}」"
                   . "→ 不合併,本筆不寫 isbn13(原值仍在 extra)\n";
                $m['isbn13'] = null;
                $m['isbn10'] = null;
                $bookId   = null;
                $isMerge  = false;
                $mergeVia = null;
                $isbnBlocked = true;
                $stats['isbn_conflict']++;
            }
        }
    }
    // ★ $isbnBlocked 時直接當新書:剛剛已判定「不是同一作品」,
    //   再跑模糊比對只會用同一組書名+作者再比一次,沒有意義。
    //   注意這裡一定要用旗標而不是「isbn13 為 null」——絕大多數來源的書本來就沒有 ISBN,
    //   那些必須照常走模糊比對。
    if ($bookId === null && !$isbnBlocked) {
        // 模糊比對(書名+第一作者)。2026-08-02 修:帶 ISBN 的紀錄也要比——
        // 「A 站有 ISBN、B 站同書無 ISBN」曾因此拆成兩筆(525 組)。
        // 僅當既有書無 ISBN 或同 ISBN 才合併;異 ISBN 存疑不合併(交 merge 工具)。
        $fk = fuzzy_key($m['title'], $m['authors'][0]['name'] ?? null);
        if ($fk && isset($fuzzyMap[$fk])) {
            $cand = $fuzzyMap[$fk];
            $candIsbn = $bookIsbn[$cand] ?? null;
            // 電子書例外(8/6):eISBN 本來就與紙本不同,同名同作者即視為
            // 同一作品的電子版本 → 即使異 ISBN 也合併(books.isbn13 以
            // COALESCE 保留紙本,eISBN 只記在該版本的 identifiers)。
            if (!$m['isbn13'] || $candIsbn === null || $candIsbn === $m['isbn13'] || $isEbook) {
                $bookId = $cand;
                $isMerge = true;
                $mergeVia = 'fuzzy';
            }
        }
    }

    // 記住本次匯入已出現過的 ISBN(同檔內撞號時要拿來比對是不是同一作品)
    if ($m['isbn13'] && !isset($isbnSeen[$m['isbn13']])) {
        $isbnSeen[$m['isbn13']] = ['title'  => $m['title'],
                                   'author' => $m['authors'][0]['name'] ?? null];
    }

    $extraRec = $raw;
    if ($dry) {
        if (!$bookId) {
            $stats['new_book']++;
            // ★ 2026-09-22:新書要能逐筆查核。
            //   「新書 N」只代表**沒比對到**,不代表站上沒有 —— 麥種那次報「新書 100」,
            //   實際 95 本是站上已有的書(fuzzy_key 沒有作者就不比對)。
            //   沒有清單就沒辦法抽查書名,那個 N 就只是個不能引用的數字。
            $newSamples[] = [$m['title'], $m['authors'][0]['name'] ?? '', $m['isbn13'] ?? ''];
        } else {
            $stats['merged']++;
            $stats['merge_' . $mergeVia]++;
            if ($mergeVia === 'fuzzy' && count($fuzzySamples) < 30) {
                $fuzzySamples[] = [$m['title'], $m['authors'][0]['name'] ?? '', (int) $bookId];
            }
        }
        $stats['edition']++;
        $doneUrls[$m['source_url']] = true;
        if ($m['isbn13'] && !$bookId) $isbnMap[$m['isbn13']] = -1;
        continue;
    }

    if ($bookId === null) {
        $stmt = $pdo->prepare(
            "INSERT INTO books (title, original_title, author, publisher, publish_date,
                                isbn13, isbn10, page_count, binding, language, series,
                                summary, keywords, buy_links, extra, source, is_published)
             VALUES (:t, :ot, :au, :pub, :pd, :i13, :i10, :pc, :bd, :lg, :se, :su, :kw, :bl, :ex, :src, 1)"
        );
        unset($extraRec['summary']); // 已入 books.summary
        $stmt->execute([
            ':t' => $m['title'], ':ot' => $m['original_title'], ':au' => $m['authors_raw'],
            ':pub' => $m['publisher'], ':pd' => $m['book_date'],
            ':i13' => $m['isbn13'], ':i10' => $m['isbn10'], ':pc' => $m['page_count'],
            ':bd' => $m['binding'], ':lg' => $m['language'], ':se' => $m['series'],
            ':su' => $m['summary'], ':kw' => $m['keywords'],
            ':bl' => json_encode($hasBuy ? [$isEbook
                        ? ['platform' => $source, 'url' => $m['source_url'], 'label' => $buyPlatform]
                        : ['platform' => $source, 'url' => $m['source_url']]] : [],
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ex' => json_encode([$source => $extraRec], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':src' => $source,
        ]);
        $bookId = (int) $pdo->lastInsertId();
        $stats['new_book']++;
        $bookIsbn[$bookId] = $m['isbn13'];
        if ($m['isbn13']) $isbnMap[$m['isbn13']] = $bookId;
        $fk = fuzzy_key($m['title'], $m['authors'][0]['name'] ?? null);
        if ($fk) $fuzzyMap[$fk] = $bookId;
    } else {
        // 合併:只補空欄,不覆蓋;extra 增鍵;buy_links 追加
        $cur = $pdo->prepare("SELECT summary, extra, buy_links FROM books WHERE book_id = :id");
        $cur->execute([':id' => $bookId]);
        $curRow = $cur->fetch();
        if ($curRow['summary'] === null && $m['summary'] !== null) {
            unset($extraRec['summary']);
        }
        $extra = $curRow['extra'] ? (json_decode($curRow['extra'], true) ?: []) : [];
        $extra[$source] = $extraRec;
        $bl = $curRow['buy_links'] ? (json_decode($curRow['buy_links'], true) ?: []) : [];
        if ($hasBuy) {
            $bl[] = $isEbook
                ? ['platform' => $source, 'url' => $m['source_url'], 'label' => $buyPlatform]
                : ['platform' => $source, 'url' => $m['source_url']];
        }
        $stmt = $pdo->prepare(
            "UPDATE books SET
               original_title = COALESCE(original_title, :ot), author = COALESCE(author, :au),
               publisher = COALESCE(publisher, :pub), publish_date = COALESCE(publish_date, :pd),
               isbn13 = COALESCE(isbn13, :i13), isbn10 = COALESCE(isbn10, :i10),
               page_count = COALESCE(page_count, :pc), binding = COALESCE(binding, :bd),
               language = COALESCE(language, :lg), series = COALESCE(series, :se),
               summary = COALESCE(summary, :su), keywords = COALESCE(keywords, :kw),
               buy_links = :bl, extra = :ex
             WHERE book_id = :id"
        );
        $stmt->execute([
            ':ot' => $m['original_title'], ':au' => $m['authors_raw'], ':pub' => $m['publisher'],
            ':pd' => $m['book_date'], ':i13' => $m['isbn13'], ':i10' => $m['isbn10'],
            ':pc' => $m['page_count'], ':bd' => $m['binding'], ':lg' => $m['language'],
            ':se' => $m['series'], ':su' => $m['summary'], ':kw' => $m['keywords'],
            ':bl' => json_encode($bl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ex' => json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => $bookId,
        ]);
        $stats['merged']++;
        // 合併時 COALESCE 可能補上 ISBN → 同步記憶,後續同 ISBN 紀錄才配得到
        if ($m['isbn13'] && empty($bookIsbn[$bookId])) {
            $bookIsbn[$bookId] = $m['isbn13'];
            $isbnMap[$m['isbn13']] = $bookId;
        }
    }

    // 2. persons + book_persons(多角色:作者/譯者/繪者/編者;credit_text 保留原樣)
    foreach ([
        'author'      => $m['authors'],
        'translator'  => $m['translators'],
        'illustrator' => $m['illustrators'],
        'editor'      => $m['editors'],
    ] as $role => $people) {
        $order = 0;
        foreach ($people as $a) {
            if (!isset($personMap[$a['name']])) {
                $st = $pdo->prepare("INSERT INTO persons (name) VALUES (:n)");
                $st->execute([':n' => $a['name']]);
                $personMap[$a['name']] = (int) $pdo->lastInsertId();
            }
            $st = $pdo->prepare(
                "INSERT IGNORE INTO book_persons (book_id, person_id, role, role_order, credit_text)
                 VALUES (:b, :p, :role, :o, :c)"
            );
            // 署名本身寫明了角色(「圖:李小華」出現在 authors_raw)→ 以署名為準;
            // 沒剝到角色詞時 $a['role'] 為 null,沿用欄位本身的角色。
            $st->execute([':b' => $bookId, ':p' => $personMap[$a['name']],
                          ':role' => $a['role'] ?? $role,
                          ':o' => $order++, ':c' => $a['credit']]);
        }
    }

    // 3. publisher
    $pubId = null;
    if ($m['publisher']) {
        if (!isset($pubMap[$m['publisher']])) {
            // uq_name_zh 為 utf8mb4_unicode_ci(不分大小寫/全半形),PHP 陣列鍵卻區分大小寫;
            // 以 upsert 取回既有 id,避免大小寫/全半形變體撞唯一鍵而 1062。
            $st = $pdo->prepare(
                "INSERT INTO publishers (name_zh) VALUES (:n)
                 ON DUPLICATE KEY UPDATE publisher_id = LAST_INSERT_ID(publisher_id)");
            $st->execute([':n' => $m['publisher']]);
            $pubMap[$m['publisher']] = (int) $pdo->lastInsertId();
        }
        $pubId = $pubMap[$m['publisher']];
    }

    // 4. edition(每站一版)
    $st = $pdo->prepare(
        "INSERT INTO editions (book_id, publisher_id, publish_date, page_count, binding,
                               dimensions, weight_g, source, source_url)
         VALUES (:b, :p, :d, :pc, :bd, :dim, :w, :src, :url)"
    );
    $st->execute([
        ':b' => $bookId, ':p' => $pubId, ':d' => $m['edition_date'], ':pc' => $m['page_count'],
        ':bd' => $m['binding'], ':dim' => $m['dimensions'], ':w' => $m['weight_g'],
        ':src' => $source, ':url' => $m['source_url'],
    ]);
    $editionId = (int) $pdo->lastInsertId();
    $doneUrls[$m['source_url']] = true;
    $stats['edition']++;

    // 5. identifiers
    $idRows = [];
    if ($m['isbn13']) $idRows[] = ['ISBN13', $m['isbn13']];
    if ($m['isbn10']) $idRows[] = ['ISBN10', $m['isbn10']];
    if ($m['store_code']) $idRows[] = ['STORE', $m['store_code']];
    foreach ($idRows as [$t, $v]) {
        $st = $pdo->prepare(
            "INSERT IGNORE INTO identifiers (edition_id, id_type, id_value) VALUES (:e, :t, :v)");
        $st->execute([':e' => $editionId, ':t' => $t, ':v' => $v]);
    }

    // 6. 價格(各站幣別;電子書記 ebook)
    if ($m['price'] !== null && is_numeric($m['price'])) {
        $st = $pdo->prepare(
            "INSERT INTO formats_prices (edition_id, media_type, price, currency)
             VALUES (:e, :mt, :p, :c)");
        $st->execute([':e' => $editionId, ':mt' => $isEbook ? 'ebook' : 'print',
                      ':p' => $m['price'], ':c' => $m['currency']]);
    }

    // 7. 來源連結(版本層;天恩電子書標示「天恩出版社(電子書)」)
    //    無購書管道的來源寫 'official'(見檔頭 NO_BUY_SOURCES),其餘寫 'buy'。
    // ★★ 2026-09-22 修:站方重複建檔的那一筆**整條 links 都不寫**。
    //    第一版只改了上面的 $hasBuy(那管的是 books.buy_links 平面欄),
    //    這裡照樣寫了 buy link → 11 筆 -D 重複建檔全部還是掛了連結,
    //    實查 links 3,704 筆(預期 3,693)才發現。
    //    ★ 本檔自己的註解早就寫過「只改 links.link_type 是不夠的,兩邊是聯集」——
    //      反過來也成立:只改平面欄一樣不夠。**兩處都要改,而且要回查資料庫驗。**
    //    identifiers(商品代碼)仍照常寫,對帳不受影響。
    if (empty($raw['is_dup_listing'])) {
        $linkType = in_array($source, NO_BUY_SOURCES, true) ? 'official' : 'buy';
        $st = $pdo->prepare(
            "INSERT INTO links (edition_id, link_type, platform, url)
             VALUES (:e, :lt, :pf, :u)");
        $st->execute([':e' => $editionId, ':lt' => $linkType,
                      ':pf' => $buyPlatform, ':u' => $m['source_url']]);
    }

    // 8. 封面(先記來源網址;R2 轉存腳本後續更新 url_or_path 與 books.cover_url)
    if ($m['cover_url']) {
        $st = $pdo->prepare(
            "INSERT INTO media (edition_id, media_type, url_or_path, is_primary, source_url)
             VALUES (:e, 'cover', :u, 1, :s)");
        $st->execute([':e' => $editionId, ':u' => $m['cover_url'], ':s' => $m['cover_url']]);
    }

    // 9. 來源分類(subjects scheme=campus/logos/elim/grace/wdbook;elim/grace/wdbook 一書多分類全數存證)
    foreach ($m['subjects'] as [$sCode, $sLabel]) {
        $key = "$source|$sCode|$sLabel";
        if (!isset($subjMap[$key])) {
            // uq_scheme_code_label 同為 unicode_ci;upsert 取回既有 id 防變體撞鍵 1062。
            $st = $pdo->prepare(
                "INSERT INTO subjects (scheme, code, label) VALUES (:s, :c, :l)
                 ON DUPLICATE KEY UPDATE subject_id = LAST_INSERT_ID(subject_id)");
            $st->execute([':s' => $source, ':c' => $sCode, ':l' => $sLabel]);
            $subjMap[$key] = (int) $pdo->lastInsertId();
        }
        $st = $pdo->prepare("INSERT IGNORE INTO book_subjects (book_id, subject_id) VALUES (:b, :s)");
        $st->execute([':b' => $bookId, ':s' => $subjMap[$key]]);
    }

    if (++$batch % 500 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        echo "  已處理 {$stats['read']}(新書 {$stats['new_book']}、合併 {$stats['merged']})\n";
    }
}
if (!$dry && $pdo->inTransaction()) $pdo->commit();
fclose($fh);

echo ($dry ? "[dry-run 模擬] " : "") . "完成:讀 {$stats['read']}、新書 {$stats['new_book']}、"
   . "合併 {$stats['merged']}、版本 {$stats['edition']}、已存在跳過 {$stats['skip_done']}、"
   . "無效跳過 {$stats['skip_bad']}\n";

// dry-run 合併明細:ISBN 命中是硬證據,模糊比對(書名+第一作者)才需要人眼把關。
// 沿 8/19 橄欖華宣慣例——合併率偏高時,先確認高的是 ISBN 那一段再正式匯入。
if ($dry) {
    echo "  合併明細:ISBN 命中 {$stats['merge_isbn']}、模糊比對 {$stats['merge_fuzzy']}、"
       . "同檔內重複 {$stats['merge_infile']}\n";
    if ($fuzzySamples) {
        $exist = [];
        $uniq = array_values(array_unique(array_filter(
            array_column($fuzzySamples, 2), fn($x) => $x > 0)));
        if ($uniq) {
            $ph = implode(',', array_fill(0, count($uniq), '?'));
            $st = $pdo->prepare("SELECT book_id, title, author, isbn13 FROM books WHERE book_id IN ($ph)");
            $st->execute($uniq);
            foreach ($st as $r) $exist[(int) $r['book_id']] = $r;
        }
        echo "  模糊比對樣本(最多 30 筆,請確認左右是否同一本書):\n";
        foreach ($fuzzySamples as [$t, $a, $bid]) {
            $e = $exist[$bid] ?? [];
            echo "    「{$t}」/" . ($a !== '' ? $a : '(無作者)') . "\n"
               . "      ↔ #{$bid}「" . ($e['title'] ?? '?') . "」/" . ($e['author'] ?? '')
               . ' ' . ($e['isbn13'] ?? '') . "\n";
        }
    }

    /* ★★ 新書清單:全部列出,供「新書真的是新書」的抽查。
     * 麥種 2026-09-15 那次報「新書 100」,實際 95 本是站上已有的書
     * (fuzzy_key 第一行「沒有作者就不比對」,而該站 46% 的書沒作者欄)。
     * 「新書 N」只代表**沒比對到**,不代表站上沒有 —— 沒有清單就無法抽查,
     * 那個 N 就是個不能對外引用的數字。
     * 這裡順便把「沒有 ISBN」的標出來:那些是只能靠模糊比對的,風險最高。 */
    if ($newSamples) {
        $noIsbn = array_values(array_filter($newSamples, fn($r) => $r[2] === ''));
        echo "\n  新書清單(" . count($newSamples) . " 筆;其中無 ISBN "
           . count($noIsbn) . " 筆 ← 只能靠模糊比對,誤判風險最高):\n";
        foreach ($newSamples as [$t, $a, $i]) {
            echo "    " . ($i !== '' ? $i : '(無ISBN)      ') . "  「{$t}」/"
               . ($a !== '' ? $a : '(無作者)') . "\n";
        }
        echo "  ★ 匯入前請用書名在站上搜幾本,確認不是已有的書;"
           . "無 ISBN 那幾筆務必逐筆看。\n";
    }
}
