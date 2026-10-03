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
    '責任編輯', '總編輯',
    '編輯', '主編', '編著', '合著', '編譯', '譯著', '編撰', '編寫',
    '校訂', '審訂', '選編', '彙編', '口述', '著者', '作者', '編者', '譯者',
    '著', '編', '譯', '等',
];

/**
 * 整列就是這些 → **不是人**,不建 persons 列。
 * ★「等」是舊 split_names() 本來就會跳過的(「王小明;等」的那個「等」),
 *   搬規則時差點漏掉 —— 端到端測試抓到的。不要拿掉。
 */
const PN_FILLER_WORDS = ['等', '等人', '其他', '其它', '無', '不詳', 'N/A', 'n/a', '-'];

/** 字尾註記前面允許有的連接字(「梁淑儀 編」「某某・等著」) */
const PN_TAIL_GLUE = '[\s\x{3000}\x{00B7}\x{2022}\x{FF65}\x{30FB}\/\x{FF0F}]*';

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

/**
 * 字串裡還有沒有沒解掉的 entity。
 * ★ 結尾的 `;` 寫成可有可無,是為了抓**被分隔符切斷的殘骸**
 *   —— `Anselm Gr&uuml` 正是分號被當成分隔符吃掉後留下的半截。
 */
function person_entity_fragment(?string $s): ?string
{
    $s = (string) $s;
    if (preg_match('/&(?:[a-zA-Z][a-zA-Z0-9]{1,30}|#\d{1,6}|#x[0-9a-fA-F]{1,6});?/u', $s, $m)) {
        return $m[0];
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

/** 把「文、圖」「文/圖」這種複合標籤拆開;每一段都是角色詞才回 role 陣列,否則回 null */
function pn_label_roles(string $label): ?array
{
    $label = (string) preg_replace('/[\s\x{3000}]+/u', '', $label);
    if ($label === '') return null;
    $parts = preg_split('/[\/\x{FF0F}\x{3001}\x{FF1B};,\x{FF0C}&\x{FF06}]/u', $label, -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return null;
    $roles = [];
    foreach ($parts as $p) {
        if (!isset(PN_ROLE_WORDS[$p])) return null;   // 有一段不是角色詞 → 整個不算
        $roles[] = PN_ROLE_WORDS[$p];
    }
    return $roles;
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

    if (!preg_match('/^([^:\x{FF1A}]{1,8})[:\x{FF1A}][ \t\x{3000}]*(.+)$/u', $s, $m)) {
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

    foreach (PN_TAIL_NOTES as $note) {
        $re = '/' . PN_TAIL_GLUE . preg_quote($note, '/') . '$/u';
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
        $out['clean']    = $cand;
        $out['role']     = PN_ROLE_WORDS[$note] ?? null;
        $out['stripped'] = [trim($m[0])];
        return $out;
    }
    return $out;
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

    // (a) 全形分號還在 → 這一列是「好幾個人塞在同一列」,要重切不是剝字
    if (mb_strpos($orig, "\u{FF1B}", 0, 'UTF-8') !== false) {
        $out['kind']   = 'fullwidth_semi';
        $out['hold'][] = '含全形分號,是多人擠在同一列 → 走 fix_person_names.php 重切';
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
    if (!preg_match('/^([^:\x{FF1A}]{1,12})[:\x{FF1A}][ \t\x{3000}]*(.+)$/u', $s, $m)) return null;
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
    $raw = trim(decode_person_entities($raw));
    if ($raw === '') return [];

    // ★ 複合角色標籤要在切割之前剝,否則「文、圖:王小明」的「文」會被當成分隔
    //   而不是連接 —— 人救得回來,角色會掉一個。
    $leadRoles = null;
    if (($lead = pn_strip_leading_role_label($raw)) !== null) {
        $leadRoles = $lead['roles'];
        $raw       = $lead['rest'];
    }

    $out = [];
    foreach (pn_split_delims($raw, $warn) as $p) {
        $p = trim($p);
        if ($p === '') continue;
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
