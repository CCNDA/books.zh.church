<?php
declare(strict_types=1);
/**
 * 人名正規化規則 —— import.php、tools/check_person_names.php、
 * tools/fix_person_names.php **共用同一份**。
 *
 * ════════ 為什麼要獨立成一支 lib ════════
 * 這條規則有三個使用者:
 *   (1) tools/check_person_names.php —— 盤點既有 persons、產對照表給人勾(它是規則的驗證者)
 *   (2) tools/fix_person_names.php   —— 依規則重切既有書的署名
 *   (3) tools/import.php             —— 新抓進來的書套用同一條規則
 * 三邊若各寫一份,**驗過的規則與實際跑的規則會悄悄分家** ——
 * 對照表說「這樣剝」、匯入卻剝成另一樣,而且不會有人發現。
 * 所以規則只有這裡一份,三邊都 require。(先例:lib_isbn.php、lib_title.php)
 *
 * ★ 分隔符與括號的定義也搬進來了(PN_DELIMS / PN_OPEN / PN_CLOSE),
 *   import.php 的 split_delims() 改用這裡的常數 —— 本案已經吃過
 *   「同一組分隔符寫兩份、其中一份的全形分號退化成半形」的虧兩個月。
 *
 * ════════ 契約(與 lib_title.php 相同) ════════
 * 每個函式回 ['clean'=>…, 'stripped'=>[…], 'hold'=>[…]]。
 * **`hold` 非空 → 呼叫端不可採用 clean**,照原值走、列進人工清單。
 * 規則只負責分辨「確定可以剝」與「不確定」,不確定一律交給人。
 *
 * ════════ 四個必須先懂的坑 ════════
 *
 * 1. ★★ **entity 裡的 `;` 本身就是分隔符。**
 *    「古倫神父(Anselm Grün)」爬下來是未解碼的 `Anselm Gr&uuml;n`,
 *    結尾那個半形分號正好是 split_delims() 的分隔符 → 同一位作者被從中間切斷,
 *    散成「古倫神父(Anselm Gr&uuml」與「n)」兩筆,站上顯示成「Anselm Gr&uuml」。
 *    → **decode 一定要排在 split 之前**,順序反了等於沒修。
 *
 * 2. ★★ **「文」「圖」可以是角色詞,也可以是人名的字。**
 *    「文、圖:王小明」被切開後「文」自成一列(person 33420,票上 34 本);
 *    但「陳志文」「文子梁」「李文」裡的「文」是名字。
 *    → 角色詞只在**整列完全等於角色詞**、或**「角色詞+冒號」當前綴**時才處理,
 *      一律完整比對,**絕不用包含比對**。
 *    → 同理,**「文」「圖」「作」「繪」不可以放進字尾剝除清單** ——
 *      「陳志文」會被剝成「陳志」。這是本規則最容易出事的地方。
 *
 * 3. ★ **字尾剝除要長的先試。** 「黃伯和編輯」先試「編輯」才對;
 *    先試「輯」會剝成「黃伯和編」,正好製造出票上那第三筆髒資料。
 *
 * 4. ★ **剝完剩不到 2 字一律 hold。** 「李繪」剝成「李」沒有意義,
 *    而且正是誤傷真名時會出現的形狀。
 *
 * ════════ 全形標點怎麼寫(陷阱 11) ════════
 * 全形字元一律用 \x{FF1B} / "\u{FF1B}" 這種 escape,**不寫字面全形字元**:
 * 字面全形字元會在生成/複製/存檔時退化(「[;;]」曾讓全形分號整整兩個月從未被拆過),
 * 退化後 regex 照樣編譯得過、只是靜默少比對一半,看不出來。
 *
 * ════════ 原值怎麼保留 ════════
 * `book_persons.credit_text` 存封面署名原文(保留排版與全形),
 * `books.extra[來源]` 存整筆爬蟲原始紀錄 → 原值一直在,不必另開 *_raw 欄。
 *
 * 相關:Asana 1218277971470042(本票)、1218279391096310(9/9 結案的程式面)
 */

// ── 分隔符與括號(**全專案唯一一份**,import.php 的 split_delims() 也吃這裡) ──
const PN_DELIMS = [';', "\u{FF1B}", "\u{3001}"];                                        // ; ； 、
const PN_OPEN   = ['(' => 1, "\u{FF08}" => 1, '[' => 1, "\u{FF3B}" => 1, "\u{3010}" => 1]; // ( （ [ ［ 【
const PN_CLOSE  = [')' => 1, "\u{FF09}" => 1, ']' => 1, "\u{FF3D}" => 1, "\u{3011}" => 1]; // ) ） ] ］ 】

/**
 * 角色詞 → book_persons.role。
 * ★ 這張表**只用於「整列完全等於」與「角色詞+冒號前綴」**兩種情況。
 *   不要拿它去做包含比對,理由見檔頭第 2 點。
 */
const PN_ROLE_WORDS = [
    '文'       => 'author',      '著'   => 'author',      '作'   => 'author',
    '著者'     => 'author',      '作者' => 'author',      '編著' => 'author',
    '合著'     => 'author',      '口述' => 'author',      '撰'   => 'author',
    '編'       => 'editor',      '主編' => 'editor',      '編輯' => 'editor',
    '編者'     => 'editor',      '總編輯' => 'editor',    '責任編輯' => 'editor',
    '校訂'     => 'editor',      '審訂' => 'editor',      '選編' => 'editor',
    '彙編'     => 'editor',      '編撰' => 'editor',      '編寫' => 'editor',
    '譯'       => 'translator',  '譯者' => 'translator',  '翻譯' => 'translator',
    '編譯'     => 'translator',  '譯著' => 'translator',
    '圖'       => 'illustrator', '繪'   => 'illustrator', '繪圖' => 'illustrator',
    '插圖'     => 'illustrator', '插畫' => 'illustrator', '漫畫' => 'illustrator',
    '攝影'     => 'illustrator',
];

/**
 * 可以當**字尾註記**剝掉的詞。**比角色詞表窄**,而且是刻意的。
 *
 * ★★ **「文」「圖」「作」「繪」不在裡面,不要順手補齊。**
 *    「陳志文」「王大文」「李文」會被剝成「陳志」「王大」「李」;
 *    「繪」也是女性名字用字。這四個字只在「整列就是它」時才當角色詞處理。
 * ★ 長的排前面 —— 比對順序就是這個陣列的順序(見檔頭第 3 點)。
 */
const PN_TAIL_NOTES = [
    // ★★ 複合尾註要排在最前面(2026-10-03 主機實測補上)。
    //    沒有這一批,「伊爾文等著」只會剝掉「著」變成「伊爾文等」、
    //    「威廉．克萊因 等合著」變成「威廉．克萊因 等」、
    //    「榮鳳,保羅合編著」變成「榮鳳,保羅合」—— 查髒資料的工具自己造髒資料。
    // ★ 2026-10-03 第七輪(C 段 339 列全列審出來的):「尹可名撰著」只命中短的「著」
    //   → 變成「尹可名撰」。「撰」不在本表裡,所以第六輪的殘尾檢查也看不到它 ——
    //   補進複合詞,並另立 PN_TAIL_RESIDUE 把這一型的下一個還沒發現的詞攔成 hold。
    // ★ 2026-10-03 第八輪(C 段 337 列整批重跑比對出來的,而且是**已經寫進生產資料**
    //   才被抓到的):「林治平主編著」只命中「編著」→「林治平主」、
    //   「郭榮剛 共同主編」只命中「主編」→「郭榮剛 共同」。又是同一型。
    '共同主編', '共同編著', '共同編譯', '共同編', '共同著', '共同譯',
    '主編著', '主編譯',
    '等人合著', '等人合編', '合編著', '等合著', '等合編', '等編著', '撰著',
    // ★★ 「和著」「和編」**不可以放進來**(2026-10-03 自己踩過):
    //    它們比「編」長,會先命中 →「黃伯和編」被剝成「黃伯」。
    //    「蔡春曦.蔡黃玉珍 和著」那種前面有空格的,改由「著」+ PN_DANGLING 擋成 hold,
    //    交人工判 —— 寧可不剝,也不可以把人家名字裡的字吃掉。
    // ★ 2026-10-03 第三輪實測補:「李懷光原著」被剝成「李懷光原」、
    //   「侯士庭新編」被剝成「侯士庭新」—— 又是「長的沒排前面」那一條。
    '責任編輯', '總編輯', '等主編', '等著', '等編', '等譯',
    '原文編譯', '總審訂', '原著', '編選', '編註', '新譯', '改寫', '改編', '審校',
    '編輯', '主編', '編著', '合著', '合編', '編譯', '譯著', '編撰', '編寫',
    '校訂', '審訂', '審譯', '選編', '彙編', '口述',
    '著', '編', '譯', '等',
];

/**
 * 剝完之後**不該留在字尾**的角色字 —— 留著就表示原本是個還沒收錄的複合註記詞。
 *
 * ★★ 2026-10-03 第七輪。第六輪的殘尾檢查只比對 PN_TAIL_NOTES,
 *    而「撰」「述」不在那張表裡(它們在 PN_ROLE_WORDS),所以
 *    「尹可名撰著」剝掉「著」剩「尹可名撰」完全沒被攔到,直接產了改名 SQL。
 *
 * ★★ 這張表**只放幾乎不會當人名末字的角色字**。
 *    「文」「圖」「作」絕對不可以放進來 —— C 段實際就有
 *    「佛洛.麥克艾文著」「伍謂文主編」「詹姆斯．拉文 主編」「馮 煒 文 著」,
 *    放進來會把四個真人的名字擋成 hold。寧可漏,不可誤傷。
 */
/**
 * 可能是**名字用字**的角色詞 —— 「陳志文」「圖們江」「王大作」。
 * 只有這幾個需要「字間空白可能只是排版」那道額外防護,見 strip_person_trailing_role()。
 */
const PN_AMBIGUOUS_TAIL = ['文', '圖', '作'];

const PN_TAIL_RESIDUE = ['撰', '述', '輯', '繪', '攝', '主'];
// ★ 第八輪補「主」:「林治平主」是剝一半的殘尾。
//   ★★ 「同」**試過又拿掉**:放進來會把「周文同著 → 周文同」(站上真有這個人)
//      擋成 hold —— 誤傷一個真人,比漏掉一個複合詞嚴重。
//      「郭榮剛 共同主編」改用 PN_TAIL_NOTES 的「共同*」複合詞處理。
//   代價:「中主著 → 中主」會改走 hold 進人工,實測只有這一列。

/**
 * **前面必須有分隔符**才能剝的註記詞。
 *
 * ★★ 2026-10-03 第四輪 —— 剝除詞分布表釣出來的:
 *    「創造科學翻**譯者**」→「創造科學翻」、「傳月刊靈修**作者**」→「傳月刊靈修」、
 *    「胖手收 插畫創**作者**」→「胖手收 插畫創」、「吳小**新編**」→「吳小」。
 *    這些詞的**結尾就是註記詞本身**,直接剝會把名字砍掉一截 ——
 *    和「陳志**文**」不可當字尾剝是同一型,只是藏得更深:混在 2,400 列明細裡看不到,
 *    是分布表的「併入既有列 0%」把它們指出來的
 *    (剝完的名字站上一列都沒有 = 剝出了一個不存在的人)。
 *    → 要求前置分隔符後,「李安琴 譯者」照剝、「創造科學翻譯者」一個字都不動。
 * ★ 「原著」刻意**不在**這裡:「賈禮榮原著」實測 78% 併得進既有列,沒有分隔符也是對的。
 */
const PN_TAIL_NOTES_SPACED = [
    '著者', '作者', '編者', '譯者', '繪者', '新編', '校對',
];

/**
 * 剝完之後**不可以**留在字尾的字:它們是連接詞的半截,不是名字的結尾。
 * ★ 一律要求前面有分隔符才算 —— 「黃伯和」的「和」是名字的字,
 *   「蔡黃玉珍 和」的「和」前面有空格,那才是半截的「和著」。
 *   這條是 lib_title.php 的 TP_DANGLING 同一招:**剝完殘尾不像名字就 hold,
 *   不自作聰明多吃幾個字**。
 */
const PN_DANGLING = '(?:[\s\x{3000}][\x{7B49}\x{5408}\x{548C}\x{8207}\x{53CA}\x{66A8}]'
                  . '|[,\x{FF0C}\x{3001}\x{FF1B};\/\x{FF0F}\\\\\-\x{FF0D}\x{2014}]'
                  // ★ 第四輪補:「田頌恩◎審訂」→「田頌恩◎」、「郭承天..等 編著」→「郭承天..」
                  . '|[\x{25CE}\x{25CB}\x{25CF}\x{2605}\x{2606}\x{25C6}\x{00B7}\x{2027}\x{FF0E}]|\.+)$';

/**
 * 整列就是這些 → **不是人**,不建 persons 列。
 * ★「等」是舊 split_names() 本來就會跳過的(「王小明;等」的那個「等」),
 *   搬規則時差點漏掉 —— 端到端測試抓到的。不要拿掉。
 */
const PN_FILLER_WORDS = ['等', '等人', '其他', '其它', '無', '不詳', 'N/A', 'n/a', '-'];

/** 字尾註記前面允許有的連接字(「梁淑儀 編」「某某・等著」) */
const PN_TAIL_GLUE = '[\s\x{3000}\x{00B7}\x{2022}\x{FF65}\x{30FB}\/\x{FF0F}\\\\]*';

/** 剝完字尾註記後,至少要剩幾個字才敢採用(見檔頭第 4 點) */
const PN_MIN_LEN = 2;

/** persons.name 超過這個字數就當「疑似整段文字」,一律人工看 */
const PN_LONG_LEN = 25;

// ──────────────────────────────────────────────────────────────────────────
// 一、HTML entity 解碼(**必須排在 split 之前**)
// ──────────────────────────────────────────────────────────────────────────

/**
 * 解碼署名字串裡的 HTML entity。
 *
 * ★ 呼叫時機:**split_delims() 之前**。entity 結尾的 `;` 就是分隔符,
 *   先切再解碼等於沒修(檔頭第 1 點)。
 *
 * ★ `&semi;`(HTML5 的分號 entity)刻意**不解碼** —— 解出來是個 `;`,
 *   下一步就會被當成分隔符,等於把一個人切成兩個。原樣留著讓它進人工清單。
 *   實務上人名不會有 `&semi;`,寫這一行是為了講明「這裡不是漏掉」。
 */
function decode_person_entities(?string $s): string
{
    $s = (string) $s;
    if ($s === '' || strpos($s, '&') === false) return $s;

    $guard = "\x01PN_SEMI\x02";
    $s = str_ireplace('&semi;', $guard, $s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return str_replace($guard, '&semi;', $s);
}

/** 所有合法的 HTML entity 名稱(由 PHP 自己的表建,不是手列的) */
function pn_entity_names(): array
{
    static $names = null;
    if ($names !== null) return $names;
    $names = [];
    foreach (get_html_translation_table(HTML_ENTITIES, ENT_QUOTES | ENT_HTML5, 'UTF-8') as $ent) {
        $names[trim($ent, '&;')] = true;
    }
    return $names;
}

/**
 * 字串裡還有沒有沒解掉的 entity。
 *
 * ★ 結尾的 `;` 可有可無,是為了抓**被分隔符切斷的殘骸**
 *   —— `Anselm Gr&uuml` 正是分號被當成分隔符吃掉後留下的半截。
 *
 * ★★ 但「& + 字母」**不等於 entity**。2026-10-03 主機實測:用那個寬鬆樣式盤出 43 列,
 *    其中三十列是「Corrine L. Carvalho&Paul V. Niskanen」「CLOUD&TOWNSEND」
 *    「Tony&Tina牧師」這種**兩個人名中間一個 & 號**,根本沒有 entity。
 *    票上 9/8 量的是 11 筆 —— 數字對不上就是判準太寬。
 *    → 沒有分號的殘骸,**名稱必須真的在 HTML entity 表裡**才算。
 *    (有分號的本來就會被 decode 掉,走到這裡還留著的才是真殘骸。)
 */
function person_entity_fragment(?string $s): ?string
{
    $s = (string) $s;
    if (!preg_match_all('/&(#\d{1,6};?|#x[0-9a-fA-F]{1,6};?|[a-zA-Z][a-zA-Z0-9]{1,30};?)/u', $s, $ms)) {
        return null;
    }
    $valid = pn_entity_names();
    foreach ($ms[1] as $i => $body) {
        if ($body[0] === '#') return $ms[0][$i];              // 數值型一律算
        $name = rtrim($body, ';');
        if (isset($valid[$name])) return $ms[0][$i];
    }
    return null;
}

// ──────────────────────────────────────────────────────────────────────────
// 二、角色詞與字尾註記
// ──────────────────────────────────────────────────────────────────────────

/**
 * 判斷「整列就是一個角色詞」(票上 person 33420「文」、33594「圖」、35736「譯」…)。
 * ★ 完整比對,不是包含比對。
 *
 * @return string|null 對應的 role,不是角色詞則回 null
 */
function person_is_role_word(?string $s): ?string
{
    $s = (string) preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', (string) $s);
    return PN_ROLE_WORDS[$s] ?? null;
}

/**
 * 整段拆開後**每一塊都是角色詞** → 這段根本不是人。
 *
 * ★ 2026-10-03 第五輪(`--resplit-only` dry-run 實測)抓到:
 *   「文/圖」被建成一個叫「文/圖」的 person。
 *   路徑是:剝掉前綴「文/」之後只剩「圖」一個字 → 太短判 hold → 照原值走 → 建人。
 *   hold 是對的(剝完只剩一個字本來就不該採用),錯在**沒有人問過「這整段是不是人」**。
 * ★ 防誤傷靠的還是同一招:**有一塊不是角色詞就不算**。
 *   「文/王小明」「文子梁」「圖們江」一個都碰不到。
 */
function pn_is_all_role_words(string $s): bool
{
    $parts = preg_split('/[\/\x{FF0F}\\\\\x{3001}\x{FF1B};,\x{FF0C}&\x{FF06}:\x{FF1A}\s\x{3000}]+/u',
                        $s, -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts || count($parts) < 2) return false;      // 單一塊交給 person_is_role_word()
    foreach ($parts as $p) {
        if (!isset(PN_ROLE_WORDS[$p]) && !in_array($p, PN_FILLER_WORDS, true)) return false;
    }
    return true;
}

/** 把「文、圖」「文/圖」這種複合標籤拆開;每一段都是角色詞才回 role 陣列,否則回 null */
function pn_label_roles(string $label): ?array
{
    $label = (string) preg_replace('/[\s\x{3000}]+/u', '', $label);
    if ($label === '') return null;
    $parts = preg_split('/[\/\x{FF0F}\\\\\x{3001}\x{FF1B};,\x{FF0C}&\x{FF06}]/u', $label, -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return null;
    $roles = [];
    foreach ($parts as $p) {
        if (!isset(PN_ROLE_WORDS[$p])) return null;   // 有一段不是角色詞 → 整個不算
        $roles[] = PN_ROLE_WORDS[$p];
    }
    return $roles;
}

/**
 * 比對「角色標籤 + 分隔 + 其餘」。
 *
 * ★★ 斜線在這份資料裡是**兩種角色**,順序因此不能顛倒:
 *    (a) 標籤內的連接號 ——「文/圖:王小明」的斜線連接兩個角色詞
 *    (b) 代替冒號 ——「文／懶鬼KK」的斜線就是冒號
 *    所以**先試冒號**(此時標籤內允許有斜線,(a) 才成立),
 *    沒有冒號再試斜線當冒號((b))。順序反了「文/圖:王小明」會被切成「圖:王小明」。
 *
 * @return array{0:string,1:string,2:string}|null [全部, 標籤, 其餘]
 */
function pn_match_role_label(string $s): ?array
{
    // (a) 冒號當分隔;標籤內可含斜線與反斜線
    if (preg_match('/^([^:\x{FF1A}]{1,12})[:\x{FF1A}][ \t\x{3000}]*(.+)$/u', $s, $m)) return $m;
    // (b) 沒有冒號 → 斜線代冒號;此時標籤內不可再有斜線
    if (preg_match('/^([^:\x{FF1A}\/\x{FF0F}]{1,12})[\/\x{FF0F}][ \t\x{3000}]*(.+)$/u', $s, $m)) return $m;
    return null;
}

/**
 * 剝掉「角色:姓名」的角色前綴。
 * ★ **一定要有冒號**(半形或全形)才動,且冒號前要完全等於角色詞 ——
 *   否則「文子梁」「文心蘭」會被當成「文:子梁」。
 * ★ 冒號後用 `[ \t\x{3000}]*` 不用 `\s*`:`\s*` 會吃掉換行、把下一行誤當人名(陷阱 11)。
 *
 * @return array{clean:string, role:?string, stripped:string[], hold:string[]}
 */
function strip_person_role_prefix(?string $raw): array
{
    $s   = trim((string) $raw);
    $out = ['clean' => $s, 'role' => null, 'stripped' => [], 'hold' => []];
    if ($s === '') return $out;

    if (($m = pn_match_role_label($s)) === null) {
        return $out;
    }
    $roles = pn_label_roles($m[1]);
    if ($roles === null) return $out;

    $rest = trim($m[2]);
    if (mb_strlen($rest, 'UTF-8') < PN_MIN_LEN) {
        $out['hold'][] = '角色前綴「' . trim($m[1]) . '」剝完只剩 ' . mb_strlen($rest, 'UTF-8') . ' 字';
        return $out;
    }
    $out['clean']    = $rest;
    $out['role']     = $roles[0];
    $out['stripped'] = [trim($m[1]) . ':'];
    return $out;
}

/**
 * 剝掉字尾註記(「梁家麟著」→「梁家麟」、「黃伯和編輯」→「黃伯和」)。
 *
 * ★ 長的先試(PN_TAIL_NOTES 的順序就是比對順序)。
 * ★ 剝完 < PN_MIN_LEN 字 → hold,不採用。
 * ★ 剝完若本身還是角色詞(「著者」剝成「著」)→ hold,
 *   這代表這一列根本沒有人名,該走角色詞那條路。
 *
 * @return array{clean:string, role:?string, stripped:string[], hold:string[]}
 */
function strip_person_tail_note(?string $raw): array
{
    $s   = trim((string) $raw);
    $out = ['clean' => $s, 'role' => null, 'stripped' => [], 'hold' => []];
    if ($s === '') return $out;

    // ★ 中段還有另一個署名 → 這一列是兩個人黏在一起,剝字尾救不了。
    //   「傑克.海福德著 呂妙芬譯」只剝掉最後的「譯」會得到
    //   「傑克.海福德著 呂妙芬」—— 比原本更糟。沒有分隔符可切,只能交給人。
    if (($mid = pn_mid_credit($s)) !== null) {
        $out['hold'][] = '中段還有署名「' . $mid . '」→ 兩個以上的人黏在同一列,要人工拆';
        return $out;
    }

    // ★★ PN_TAIL_NOTES_SPACED 的詞黏在字尾、前面又沒有分隔符 → **一律 hold**,
    //    而且**不可以往下掉到比較短的詞**。
    //    「吳小新編」與「侯士庭新編」在規則上無法分辨(一個是「吳小新」+「編」,
    //    一個是「侯士庭」+「新編」)—— 掉到「編」會剝出「侯士庭新」這種不存在的人。
    //    分不出來就交人工,這是本檔從頭到尾的那條線。
    foreach (PN_TAIL_NOTES_SPACED as $note) {
        if (preg_match('/' . preg_quote($note, '/') . '$/u', $s)
            && !preg_match('/' . str_replace(']*', ']+', PN_TAIL_GLUE) . preg_quote($note, '/') . '$/u', $s)) {
            $out['hold'][] = '字尾「' . $note . '」前面沒有分隔符,分不出它是註記還是名字的字,要人工看';
            return $out;
        }
    }

    // 主清單不需要前置分隔符;PN_TAIL_NOTES_SPACED 那批**一定要有**(見該常數的說明)。
    $glue = [];
    foreach (PN_TAIL_NOTES        as $note) $glue[$note] = PN_TAIL_GLUE;
    foreach (PN_TAIL_NOTES_SPACED as $note) $glue[$note] = str_replace(']*', ']+', PN_TAIL_GLUE);

    foreach ($glue as $note => $g) {
        $re = '/' . $g . preg_quote($note, '/') . '$/u';
        if (!preg_match($re, $s, $m)) continue;

        $cand = (string) preg_replace('/[\s\x{3000}]+$/u', '', (string) preg_replace($re, '', $s));
        if ($cand === '') {
            return $out;      // 整列就是註記本身 → 交給角色詞那條路,這裡不動
        }
        if (mb_strlen($cand, 'UTF-8') < PN_MIN_LEN) {
            $out['hold'][] = '字尾「' . $note . '」剝完只剩 ' . mb_strlen($cand, 'UTF-8') . ' 字,不敢剝';
            return $out;
        }
        if (person_is_role_word($cand) !== null) {
            $out['hold'][] = '字尾「' . $note . '」剝完剩「' . $cand . '」,本身還是角色詞';
            return $out;
        }
        if (preg_match('/' . PN_DANGLING . '/u', $cand)) {
            $out['hold'][] = '字尾「' . $note . '」剝完殘尾「'
                           . mb_substr($cand, -3, 3, 'UTF-8') . '」不像名字結尾,要人工看';
            return $out;
        }
        // ★★ 2026-10-03 第六輪(C 段前 30 列就抓到):剝掉**單字**註記後,
        //    殘尾若落在連接字 → hold,**不管前面有沒有空白**。
        //    「曾思瀚&鄧紹光和著」剝掉「著」會剩「…鄧紹光和」——「和著」只剝掉一半。
        //    ★ 不能把「和」無條件剝掉:「黃伯和主編 → 黃伯和」的「和」是名字的字,
        //      兩者在字形上分不開。所以**一律 hold**,代價是「黃伯和著」這種也進人工清單。
        //      寧可多幾列人工,也不要改出一個不存在的人。
        //    ★ 只限單字註記:「黃伯和主編」剝的是兩字的「主編」,不受影響。
        //    ★★ 只有在剩下的字串**還含多人分隔符**時才算 —— 那才是「合著/和著」說得通的情境。
        //       「黃伯和編」剩「黃伯和」沒有分隔符 → 照剝(票上那六筆要收斂到同一個人,
        //       這條不能擋到它)。兩種寫法在字形上分不開,只能靠這個旁證。
        if (mb_strlen($note, 'UTF-8') === 1
            && preg_match('/[&\x{FF06}.\x{FF0E}\x{3001},\x{FF0C}\/\x{FF0F}]/u', $cand)
            && preg_match('/[\x{548C}\x{5408}\x{8207}\x{53CA}\x{66A8}\x{7B49}]$/u', $cand)) {
            $out['hold'][] = '字尾「' . $note . '」剝完殘尾「'
                           . mb_substr($cand, -1, 1, 'UTF-8') . '」是連接字的一半,要人工看';
            return $out;
        }
        // ★★ 剝完**仍以註記詞結尾** → 根本沒剝乾淨(「白立德著著」→「白立德著」)。
        //    重複剝到底看似更聰明,但那是猜;留給人看比較誠實,而且實測只有個位數。
        //    ★ 第七輪再補 PN_TAIL_RESIDUE:「撰」「述」不在 PN_TAIL_NOTES 裡,
        //      「尹可名撰著」就是這樣漏出去變成「尹可名撰」的。
        foreach (array_merge(PN_TAIL_NOTES, PN_TAIL_RESIDUE) as $again) {
            if (preg_match('/' . preg_quote($again, '/') . '$/u', $cand)) {
                $out['hold'][] = '字尾「' . $note . '」剝完仍以「' . $again . '」結尾,沒剝乾淨,要人工看';
                return $out;
            }
        }
        $out['clean']    = $cand;
        $out['role']     = pn_note_role($note);
        $out['stripped'] = [trim($m[0])];
        return $out;
    }
    return $out;
}

/**
 * 註記詞 → role。複合註記(「等譯」「等合著」「合編著」)查不到時,
 * 退而查它的字尾一到兩個字 ——「等譯」的角色就是「譯」。
 * ★ 不這樣做,「唐諾.古特立著 高以峰等譯」切出來的高以峰會掉角色,
 *   靜默變成 author(沿用欄位),而他是譯者。
 */
function pn_note_role(string $note): ?string
{
    return PN_ROLE_WORDS[$note]
        ?? PN_ROLE_WORDS[mb_substr($note, -2, 2, 'UTF-8')]
        ?? PN_ROLE_WORDS[mb_substr($note, -1, 1, 'UTF-8')]
        ?? null;
}

/**
 * 字串**中段**(不是結尾)是不是還有一個角色註記,後面又接著別的字。
 * 「傑克.海福德著 呂妙芬譯」的「著 」就是;「黃伯和編輯」的「編」後面接「輯」不是。
 *
 * @return string|null 命中的註記詞
 */
function pn_mid_credit(string $s): ?string
{
    foreach (PN_TAIL_NOTES as $note) {
        // 註記詞 + 分隔符 + 還有東西 → 中段署名
        // ★ 第四輪補 `.`/`．`/`、`:「孫揚光口述.吳淑玲編撰」的點是兩筆署名之間的分隔。
        //   不會誤判「華德.凱瑟著」—— 那個點不在註記詞後面,而在名字中間。
        $re = '/' . preg_quote($note, '/') . '[\s\x{3000}\x{FF0F}\/\\\\.\x{FF0E}\x{3001}]+\S/u';
        if (preg_match($re, $s)) return $note;
    }
    return null;
}

// ──────────────────────────────────────────────────────────────────────────
// 三、整段文字偵測(只標記,絕不自動改)
// ──────────────────────────────────────────────────────────────────────────

/**
 * 這一列像不像「來源端欄位錯位,整段文字被當成人名」。
 * 票上第五節那一類:作者簡介整段、「序 走在信心之路的你…….IX」、
 * 「經文引用:和合本修訂版聖經(和合本2010上帝版)」、
 * 「凸桑中文聖經協會(代規格:13.6 x 19.6 cm」。
 *
 * ★ 一律只回理由,**不提供 clean** —— 這類要人看,沒有安全的自動解。
 *
 * @return string[] 命中的理由(空陣列 = 看起來是正常人名)
 */
function person_long_text_flags(?string $raw): array
{
    $s = trim((string) $raw);
    if ($s === '') return [];
    $flags = [];
    $len   = mb_strlen($s, 'UTF-8');

    if ($len > PN_LONG_LEN) $flags[] = "長度 {$len} 字";
    if (preg_match('/[\x{3002}\x{FF01}\x{FF1F}]|\.{3}|\x{2026}/u', $s)) $flags[] = '含句末標點或刪節號';
    if (preg_match_all('/[,\x{FF0C}]/u', $s) >= 2) $flags[] = '含兩個以上逗號';

    // 括號不成對 → 來源端就被切斷了(票上 47608 那一筆)
    $open = PN_OPEN; $close = PN_CLOSE; $depth = 0;
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if (isset($open[$ch]))  $depth++;
        if (isset($close[$ch])) $depth--;
    }
    if ($depth !== 0) $flags[] = '括號不成對';

    // 冒號前不是角色詞的標籤(「經文引用:…」「代規格:…」)
    if (preg_match('/^([^:\x{FF1A}]{1,8})[:\x{FF1A}]/u', $s, $m) && pn_label_roles($m[1]) === null) {
        $flags[] = '冒號前「' . trim($m[1]) . '」不是角色詞';
    }
    // 尺寸、頁數這類規格殘留
    if (preg_match('/\d+(\.\d+)?\s*(cm|mm|公分|頁|元|\x{00D7})/iu', $s)) $flags[] = '含規格數字';

    return $flags;
}

// ──────────────────────────────────────────────────────────────────────────
// 四、異體字(只用於**找出候選**,絕不拿來改寫資料)
// ──────────────────────────────────────────────────────────────────────────

/**
 * 繁體圈內的異體字對(同一個字的不同寫法)。
 *
 * ★★ **刻意不含簡繁對應。** 專案鐵律:判定簡繁只做標記與比對,絕不做字形轉換
 *    (繁體來源套 s2tw 會把「后位→後位」「米母干→米母乾」改壞)。
 *    這張表只在 check_person_names.php 裡當「這一對差的是不是異體字」的**信心指標**,
 *    **不會寫回任何欄位**,合併與否一律由人裁示。
 * ★ 候選本身不靠這張表產生 —— 候選是用「同長度、只差一個字」的通配鍵分群出來的,
 *   所以表裡沒收到的異體字也找得到,只是排在後面、標「未知差異」。
 *
 * key = 較常見的寫法,value = 其他寫法。
 */
const PN_VARIANTS = [
    '托' => ['託'], '啟' => ['啓'], '峰' => ['峯'], '群' => ['羣'],
    '為' => ['爲'], '秘' => ['祕'], '裡' => ['裏'], '傑' => ['杰'],
    '奧' => ['奥'], '內' => ['内'], '真' => ['眞'], '溫' => ['温'],
    '衛' => ['衞'], '卻' => ['却'], '查' => ['査'], '恆' => ['恒'],
    '郎' => ['郞'], '吳' => ['吴'], '黃' => ['黄'], '俞' => ['兪'],
    '褚' => ['禇'], '昇' => ['升'], '於' => ['于'], '絕' => ['絶'],
    '麼' => ['麽'], '瑯' => ['琅'], '冰' => ['氷'], '戶' => ['户'],
    '污' => ['汙'], '緣' => ['縁'], '每' => ['毎'], '叟' => ['叜'],
    '臺' => ['台'],   // 2026-10-03 實測命中:臺灣福音書房編輯部 vs 台灣福音書房編輯部
];

/** 字 → 正規寫法(由 PN_VARIANTS 反查建表) */
function pn_variant_canon_map(): array
{
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach (PN_VARIANTS as $canon => $alts) {
        $map[$canon] = $canon;
        foreach ($alts as $a) $map[$a] = $canon;
    }
    return $map;
}

/** 兩個字是不是同一個字的異體寫法 */
function pn_same_variant(string $a, string $b): bool
{
    if ($a === $b) return true;
    $m = pn_variant_canon_map();
    return isset($m[$a], $m[$b]) && $m[$a] === $m[$b];
}

// ──────────────────────────────────────────────────────────────────────────
// 五、總入口
// ──────────────────────────────────────────────────────────────────────────

/**
 * 對**單一個已經切好的人名**跑全部規則。
 *
 * ★ 注意這裡**不做切割** —— 切割是 pn_split_names() 的事,
 *   而 decode 必須排在切割之前(見檔頭第 1 點)。
 *
 * kind 的意思:
 *   clean          看起來正常,沒動
 *   role_prefix    剝掉了「角色:」前綴
 *   tail_note      剝掉了字尾註記
 *   role_word      **整列就是角色詞,根本不是人** → clean 為空,關聯該移除
 *   filler         整列是填充詞(「等」「其他」)→ 同上,不是人
 *   needs_resplit  **這一列其實是好幾個人** → 要重切,絕不可改名
 *   fullwidth_semi 含全形分號 = 多人擠在同一列 → 要重切,不是剝字
 *   entity         有 entity 殘骸 = 被分隔符切斷的半截 → 要重新 decode 再切
 *   long_text      疑似整段文字 → 人工
 *
 * @return array{clean:string, role:?string, stripped:string[], hold:string[], kind:string}
 */
function normalize_person_name(?string $raw): array
{
    $orig = trim((string) $raw);
    $out  = ['clean' => $orig, 'role' => null, 'stripped' => [], 'hold' => [], 'kind' => 'clean'];
    if ($orig === '') return $out;

    // (a-1) 整段拆開後每一塊都是角色詞(「文/圖」「文、圖」「著/譯」)→ **根本不是人**。
    //       這個結論比「要重切」更明確,所以排在最前面:重切出來也是空的,
    //       但標成 role_word 可以直接進刪除清單,不必繞一圈。
    if (pn_is_all_role_words($orig)) {
        $out['kind']     = 'role_word';
        $out['clean']    = '';
        $out['stripped'] = [$orig];
        return $out;
    }

    // (a0) ★★ 這一列其實是好幾個人 → 要**重切**,不是剝字。
    //      2026-10-03 第二輪主機實測抓到的汙染型 bug:check_person_names 用的是這支
    //      (單一人名),它不會切 →「文:江淑文\圖:陳嘉鈴」只剝掉開頭標籤,
    //      剩下的「江淑文\圖:陳嘉鈴」被當成新名字拿去改名,**比原本更糟**。
    //      判斷條件描述的是「發生了什麼事」(同一條切割管線會切出多段),
    //      不是「資料長什麼樣」(含某個字元)—— 共用檔守則。
    // ★ 全形分號這個標籤比較具體(票上 9/8 實測 56 列,check 工具拿它當基準值),
    //   所以排在一般的 needs_resplit 之前 —— 兩者的處置一樣,都走重切。
    if (mb_strpos($orig, "\u{FF1B}", 0, 'UTF-8') !== false) {
        $out['kind']   = 'fullwidth_semi';
        $out['hold'][] = '含全形分號,是多人擠在同一列 → 走 fix_person_names.php 重切';
        return $out;
    }
    $nSeg = count(pn_segments($orig)['segs']);
    if ($nSeg > 1) {
        $out['kind']   = 'needs_resplit';
        $out['hold'][] = '這一列會切成 ' . $nSeg . ' 個人 → 走 fix_person_names.php 重切,不可改名';
        return $out;
    }

    // (b) entity 殘骸 → 同上,是被切斷的半截,剝字沒有用
    if (($e = person_entity_fragment($orig)) !== null) {
        $out['kind']   = 'entity';
        $out['hold'][] = 'HTML entity 殘骸「' . $e . '」→ 走 fix_person_names.php 重新 decode 再切';
        return $out;
    }
    // (c-0) 整列是填充詞(「等」「其他」…)→ 不是人
    if (in_array(preg_replace('/[\s\x{3000}]+/u', '', $orig), PN_FILLER_WORDS, true)) {
        $out['kind']     = 'filler';
        $out['clean']    = '';
        $out['stripped'] = [$orig];
        return $out;
    }
    // (c) 整列就是角色詞 → clean 為空,代表「這一列不是人」
    if (($r = person_is_role_word($orig)) !== null) {
        $out['kind']     = 'role_word';
        $out['clean']    = '';
        $out['role']     = $r;
        $out['stripped'] = [$orig];
        return $out;
    }
    // (d) 疑似整段文字 → 只標記,不剝
    if ($flags = person_long_text_flags($orig)) {
        $out['kind'] = 'long_text';
        $out['hold'] = $flags;
        return $out;
    }
    // (e) 角色前綴
    $p = strip_person_role_prefix($orig);
    if ($p['hold']) {
        $out['kind'] = 'role_prefix';
        $out['hold'] = $p['hold'];
        return $out;
    }
    $cur   = $p['clean'];
    $kind  = $p['stripped'] ? 'role_prefix' : 'clean';
    $role  = $p['role'];
    $strip = $p['stripped'];

    // (f0) 名字後面用明確分隔符掛的角色詞(「雅樹 文」「游紫玲-文」)
    $w = strip_person_trailing_role($cur);
    if ($w['stripped']) {
        $cur   = $w['clean'];
        $kind  = 'tail_note';
        $role  = $role ?? $w['role'];
        $strip = array_merge($strip, $w['stripped']);
    }

    // (f) 字尾註記
    $t = strip_person_tail_note($cur);
    if ($t['hold']) {
        $out['kind'] = 'tail_note';
        $out['hold'] = $t['hold'];
        return $out;
    }
    if ($t['stripped']) {
        $cur   = $t['clean'];
        $kind  = 'tail_note';
        $role  = $role ?? $t['role'];
        $strip = array_merge($strip, $t['stripped']);
    }

    // ★ 收尾防線:**只在真的剝了東西時**才要求結果乾淨。
    //   「圖/賽卓．卡利耶羅 編文/道格．莫斯」剝掉開頭的「圖/」之後,剩下的還帶著
    //   「編文/道格．莫斯」—— 那不是一個人的名字,拿去改名就是改成垃圾。
    //   人名不會含冒號、斜線或反斜線,那些是結構符號。
    //   ★ 沒有要改的列不受這條影響(本來就含斜線又剝不出東西的維持原樣),
    //     否則會把幾千列本來分類正確的資料重新歸類,動到不該動的地方。
    if ($strip && preg_match('/[:\x{FF1A}\/\x{FF0F}\\\\]/u', $cur)) {
        $out['kind']   = $kind;
        $out['hold'][] = '剝完仍含結構符號(冒號/斜線/反斜線):「' . $cur . '」→ 不是乾淨的人名,要人工看';
        return $out;
    }

    $out['clean']    = $cur;
    $out['role']     = $role;
    $out['stripped'] = $strip;
    $out['kind']     = $kind;
    return $out;
}

// ──────────────────────────────────────────────────────────────────────────
// 六、切割(decode → 括號感知切割 → 逐段正規化)
//
// ★ 這一段原本長在 import.php 裡。搬過來是因為 tools/fix_person_names.php
//   必須用**一模一樣**的切法重切既有資料 —— 兩邊切法只要差一點,
//   修復工具就會補出一批跟匯入器不一致的 persons,而且沒有人會發現。
//   import.php 的 split_delims()/split_names() 現在只是套上 tidy()/cap() 的薄殼。
// ──────────────────────────────────────────────────────────────────────────

/**
 * 括號感知的分隔符切割:括號內的分隔符不切。
 *
 * 實例(突破機構 pid 29152561,book_id 105019):
 *   「彭正雄、陳碧凌、Breakazine 創作小組 (彼、桀、onki、gi)」
 * 不感知括號會拆成六個 person,其中「Breakazine 創作小組 (彼」是殘缺髒資料。
 * 括號內的並列是同一個署名的內部結構,不是多位作者。
 * 多值鐵律的兩面:既不可漏拆,也不可過度拆分。
 *
 * @param callable|null $warn 括號不成對時的回報管道(null = 不出聲)
 */
function pn_split_delims(string $s, ?callable $warn = null): array
{
    $delims = PN_DELIMS;
    $open   = PN_OPEN;
    $close  = PN_CLOSE;

    $depth = 0;
    $buf   = '';
    $out   = [];
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if (isset($open[$ch]))  { $depth++;                    $buf .= $ch; continue; }
        if (isset($close[$ch])) { $depth = max(0, $depth - 1); $buf .= $ch; continue; }
        if ($depth === 0 && in_array($ch, $delims, true)) { $out[] = $buf; $buf = ''; continue; }
        $buf .= $ch;
    }
    // 括號不成對(來源資料本來就殘缺)→ 大聲失敗並退回直接切割,
    // 不讓未閉合的括號把整段後半吞成一個人名。
    if ($depth !== 0) {
        if ($warn) $warn($s);
        return explode(';', str_replace($delims, ';', $s));
    }
    $out[] = $buf;
    return $out;
}

/**
 * 整串署名開頭的**複合角色標籤**(「文、圖:王小明」的「文、圖:」)。
 *
 * ★ 為什麼要在切割之前處理:「文、圖:王小明」的「、」同時是角色標籤的連接號**和**
 *   人名分隔符。先切就會得到「文」(自成一列的角色詞,票上第二節那 34 本)
 *   與「圖:王小明」—— 人救回來了,但「文」那個角色憑空消失,
 *   王小明只剩繪者、不是作者。
 *
 * ★ 只有在**剩下的部分不再出現任何「角色詞:」**時才套用。
 *   「文:甲;圖:乙」的剩餘是「甲;圖:乙」,裡面還有「圖:」——
 *   這種整串套同一組角色會把乙也標成作者,所以退回去走逐段處理(那條路本來就對)。
 *
 * @return array{roles:string[], rest:string}|null
 */
function pn_strip_leading_role_label(string $s): ?array
{
    if (($m = pn_match_role_label($s)) === null) return null;
    $roles = pn_label_roles($m[1]);
    if ($roles === null || count($roles) < 2) return null;     // 單一角色走逐段那條路就夠了
    $rest = trim($m[2]);
    // 剩餘還有「角色詞:」→ 整串不是同一組角色,退回逐段處理
    foreach (preg_split('/[' . "\x{3001}" . '\x{FF1B};]/u', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $seg) {
        if (preg_match('/^([^:\x{FF1A}]{1,8})[:\x{FF1A}]/u', trim($seg), $mm)
            && pn_label_roles($mm[1]) !== null) {
            return null;
        }
    }
    return ['roles' => array_values(array_unique($roles)), 'rest' => $rest];
}

/** 角色詞的 regex 選項(長的排前面,否則「總編輯」會先被「編」吃掉) */
function pn_role_alt(): string
{
    static $alt = null;
    if ($alt !== null) return $alt;
    $words = array_keys(PN_ROLE_WORDS);
    usort($words, fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
    $alt = implode('|', array_map(fn($w) => preg_quote($w, '/'), $words));
    return $alt;
}

/**
 * 在「角色詞 + 冒號或斜線」的邊界再切一刀。
 *
 * ★ 為什麼需要(2026-10-03 主機實測):站方用 `\`、`/`、`／` 當人名分隔符,
 *   「文:江淑文\圖:陳嘉鈴」「文:南希．葛絲瑞/圖:珍妮．布雷克」這種寫法有 109 列。
 *   這些字元**不能**無條件當分隔符 ——「亨利.克勞德/約翰.湯森德」是兩個人沒錯,
 *   但「文／懶鬼KK」的斜線卻是代替冒號的。**無法從字元本身判斷**。
 *
 * ★ 所以只切在一個可以證明的位置:**角色詞緊接冒號或斜線,而且它前面是開頭或分隔符**。
 *   這條件下切點必定是一個新署名的開頭。
 *   「江淑文／圖:陳」的「文」前面是「淑」不是分隔符 → 不切(名字不會被腰斬)。
 */
function pn_split_role_boundaries(string $s): array
{
    // 角色詞前面允許的分隔符(2026-10-03 第二輪實測補上 `,` 與 `&`:
    //「文／避雨,圖／那羊」「文/安德蕾．普蘭&圖/馬帝歐．朗彭」)
    $re = '/(?=(?:^|(?<=[;\x{FF1B}\x{3001}\\\\\/\x{FF0F}\s\x{3000},\x{FF0C}&\x{FF06}]))(?:' . pn_role_alt()
        . ')[:\x{FF1A}\/\x{FF0F}])/u';
    $parts = preg_split($re, $s, -1, PREG_SPLIT_NO_EMPTY);
    return $parts === false || !$parts ? [$s] : $parts;
}

/** 切出來的片段,頭尾的分隔符殘渣要去掉(「文:江淑文\」的那個反斜線) */
function pn_trim_segment(string $s): string
{
    return (string) preg_replace(
        '/^[\s\x{3000},\x{FF0C}\x{3001};\x{FF1B}\/\x{FF0F}\\\\&\x{FF06}]+|[\s\x{3000},\x{FF0C}\x{3001};\x{FF1B}\/\x{FF0F}\\\\&\x{FF06}]+$/u',
        '', $s);
}

/** 字尾註記的 regex 選項(長的先,且**排除「等」** —— 它不單獨當切點) */
function pn_tail_alt(): string
{
    static $alt = null;
    if ($alt !== null) return $alt;
    $words = array_values(array_filter(PN_TAIL_NOTES, fn($w) => $w !== '等'));
    $alt = implode('|', array_map(fn($w) => preg_quote($w, '/'), $words));
    return $alt;
}

/**
 * 署名寫在人名**後面**時的切點:「巴刻著 趙中輝譯」「陸艾文著/高鳳仙譯」。
 *
 * ★ 2026-10-03 實測:這一型在 long_text 裡有好幾百列,而且角色是現成的 ——
 *   切開之後作者與譯者各自歸位,不必猜。
 * ★ 切點條件很窄:**註記詞後面必須緊接分隔符,而且後面還有字**。
 *   「黃伯和編輯」的「編」後面是「輯」→ 不切;「梁淑儀 編」的「編」在結尾 → 不切。
 *   這和「陳志文」活下來是同一個理由:**沒有分隔符就不是切點**。
 */
function pn_split_suffix_credits(string $s): array
{
    // ★★ 後面若是左括號就**不切**:「約珥．薩頓 主編 (Joel Sutton)」的括號裡
    //    是同一個人的英文名,切開會憑空生出一個叫「(Joel Sutton)」的人。
    //    (這是本切法自己會造的傷,不是舊資料的問題 —— 2026-10-03 第三輪抓到。)
    $sep  = '[\s\x{3000}\/\x{FF0F}\\\\]';
    $open = '[(\x{FF08}\[\x{FF3B}\x{3010}]';
    $re   = '/(?:' . pn_tail_alt() . ')(?=' . $sep . '+(?!' . $open . ')\S)/u';
    if (!preg_match_all($re, $s, $m, PREG_OFFSET_CAPTURE)) return [$s];
    $out  = [];
    $prev = 0;
    foreach ($m[0] as [$txt, $off]) {
        $end   = $off + strlen($txt);
        $out[] = substr($s, $prev, $end - $prev);
        $prev  = $end;
    }
    $out[] = substr($s, $prev);
    $out = array_values(array_filter(array_map('trim', $out), fn($x) => $x !== ''));
    return $out ?: [$s];
}

/**
 * 名字後面用**明確的分隔符**掛一個角色詞:「雅樹 文」「游紫玲-文」「飯嶌玲子 繪」「雷日昇 攝影」。
 *
 * ★ 這裡可以用完整的角色詞表(含「文」「圖」「繪」),因為**分隔符是必要條件** ——
 *   「陳志文」「李文」「文子梁」沒有分隔符,一個都碰不到。
 *   這是本檔唯一讓「文」出現在字尾處理裡的地方,條件放寬一個字都不行。
 */
function strip_person_trailing_role(?string $raw): array
{
    $s   = trim((string) $raw);
    $out = ['clean' => $s, 'role' => null, 'stripped' => [], 'hold' => []];
    if ($s === '') return $out;

    $re = '/^(.{2,}?)[\s\x{3000}\-\x{FF0D}\/\x{FF0F}\\\\]+(' . pn_role_alt() . ')$/u';
    if (!preg_match($re, $s, $m)) return $out;

    $cand = trim($m[1]);
    if (mb_strlen($cand, 'UTF-8') < PN_MIN_LEN || person_is_role_word($cand) !== null) {
        return $out;
    }
    // ★★ 2026-10-03 第九輪:站方常把名字寫成「馮 煒 文」(每個字之間都加空白)。
    //    那些空白**不是分隔符**,是排版 —— 可是這條規則的前提就是「空白=分隔符」,
    //    於是「馮 煒 文」被剝成「馮 煒」,砍掉人家名字的最後一個字。
    //    只對「文」「圖」「作」這三個**本來就可能是名字用字**的詞設防:
    //    剩下的部分如果整串都是「單字+空白」,就不剝。
    //    ★ 「某某 文」「王小明 圖」照剝(剩下的不是單字串);
    //      「李 安 琴 譯」也照剝(「譯」不在這三個字裡,它不會是名字的最後一字)。
    if (in_array($m[2], PN_AMBIGUOUS_TAIL, true)) {
        $segs = preg_split('/[\s\x{3000}]+/u', $cand, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $allSingle = count($segs) >= 2;
        foreach ($segs as $sg) { if (mb_strlen($sg, 'UTF-8') !== 1) { $allSingle = false; break; } }
        if ($allSingle) {
            $out['hold'][] = '「' . $cand . ' ' . $m[2] . '」整串是單字加空白的寫法,'
                           . '那個空白可能只是排版不是分隔符,要人工看';
            return $out;
        }
    }
    $out['clean']    = $cand;
    $out['role']     = PN_ROLE_WORDS[$m[2]] ?? null;
    $out['stripped'] = [$m[2]];
    return $out;
}

/**
 * 切段管線(decode → 複合標籤 → 分隔符 → 角色詞邊界 → 後置署名),**不做正規化**。
 * normalize_person_name() 用它判斷「這一列其實是好幾個人」,
 * pn_split_names() 用它取得要逐段處理的片段 —— 兩邊同一條,不會分家。
 */
function pn_segments(?string $raw, ?callable $warn = null): array
{
    $raw = trim(decode_person_entities($raw));
    if ($raw === '') return ['segs' => [], 'roles' => null];

    $roles = null;
    if (($lead = pn_strip_leading_role_label($raw)) !== null) {
        $roles = $lead['roles'];
        $raw   = $lead['rest'];
    }
    $segs = [];
    foreach (pn_split_delims($raw, $warn) as $p) {
        foreach (pn_split_role_boundaries($p) as $q) {
            foreach (pn_split_suffix_credits($q) as $r) {
                $r = pn_trim_segment(trim($r));
                if ($r !== '') $segs[] = $r;
            }
        }
    }
    return ['segs' => $segs, 'roles' => $roles];
}

/**
 * 署名字串 → 人名陣列。**不做截斷**(截斷是呼叫端對照欄寬的事)。
 *
 * 順序是這支的重點,不可調換:
 *   1. decode entity —— entity 結尾的 `;` 是分隔符,先切就來不及了
 *   2. 括號感知切割
 *   3. 逐段 normalize_person_name()
 *
 * @return array<int, array{name:string, credit:string, role:?string, kind:string, hold:string[]}>
 *   name   要寫進 persons.name 的值(hold 時 = 原值)
 *   credit 署名原文(已 decode)
 *   role   角色改寫提示;null = 沒剝到角色詞,沿用呼叫端欄位本身的角色
 *   kind   見 normalize_person_name() 的說明
 *   hold   非空 = 規則不敢動這一段,已退回原值,要列進人工清單
 */
function pn_split_names(?string $raw, ?callable $warn = null): array
{
    // ★ 複合角色標籤要在切割之前剝(「文、圖:王小明」的「、」是連接號不是分隔符),
    //   decode 也要在切割之前(entity 結尾的 `;` 就是分隔符)—— 順序在 pn_segments() 裡。
    ['segs' => $segs, 'roles' => $leadRoles] = pn_segments($raw, $warn);

    $out = [];
    foreach ($segs as $p) {
        $n = normalize_person_name($p);
        // 整段就是角色詞(「文、圖:王小明」切開後自成一列的那個「文」)→ 不是人,不建列
        if ($n['kind'] === 'role_word' || $n['kind'] === 'filler') continue;
        // hold = 規則不敢動 → 照原值走,**絕不半套**
        $name = $n['hold'] ? $p : $n['clean'];
        if ($name === '') continue;
        // 複合角色標籤:同一個人要掛在每一個角色上(「文、圖:王小明」= 作者兼繪者)。
        // book_persons 的 UNIQUE 是 (book_id, person_id, role),多一列正是要的結果。
        foreach ($leadRoles ?? [$n['hold'] ? null : $n['role']] as $role) {
            $out[] = [
                'name'   => $name,
                'credit' => $p,
                'role'   => $role,
                'kind'   => $n['kind'],
                'hold'   => $n['hold'],
            ];
        }
    }
    return $out;
}
