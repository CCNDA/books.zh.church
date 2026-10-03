<?php
declare(strict_types=1);
/**
 * lib_person.php 的測試案例 —— **防誤傷是重點**。
 *
 * 跑法(主機或任何有 PHP 8.3 + mbstring 的機器):
 *   php tools/test_lib_person.php
 * 全綠才可以把 import.php 上主機。
 */
require_once __DIR__ . '/lib_person.php';

$fail = 0; $pass = 0;
function ok(bool $cond, string $what, string $got = ''): void
{
    global $fail, $pass;
    if ($cond) { $pass++; return; }
    $fail++;
    echo "  [FAIL] {$what}" . ($got !== '' ? "  實得:{$got}" : '') . "\n";
}

echo "\n== 1. entity 解碼(必須在 split 之前) ==\n";
$cases = [
    'Anselm Gr&uuml;n'        => 'Anselm Grün',
    '&Ouml;sterreich'         => 'Österreich',
    'A &amp; B'               => 'A & B',
    '&eacute;mile'            => 'émile',
    '&szlig;'                 => 'ß',
    '&#169;'                  => '©',
    '梁家麟'                   => '梁家麟',          // 沒有 & → 原樣
    'Smith & Jones'           => 'Smith & Jones',   // 裸 & 不是 entity
];
foreach ($cases as $in => $want) {
    $got = decode_person_entities($in);
    ok($got === $want, "decode「{$in}」應得「{$want}」", $got);
}
// ★ &semi; 刻意不解碼(解出來是分隔符,會把一個人切成兩個)
ok(decode_person_entities('A&semi;B') === 'A&semi;B', '&semi; 不解碼', decode_person_entities('A&semi;B'));

echo "\n== 2. decode 之後再 split 才救得回來(順序測試) ==\n";
// 模擬 import.php 的順序:decode → 以分隔符切
$raw = '古倫神父(Anselm Gr&uuml;n)';
$wrongOrder = explode(';', $raw);                              // 先切(舊行為)
$rightOrder = explode(';', decode_person_entities($raw));      // 先解碼(新行為)
ok(count($wrongOrder) === 2, '舊順序會把古倫神父切成兩段', (string) count($wrongOrder));
ok(count($rightOrder) === 1, '新順序只剩一段', (string) count($rightOrder));
ok($rightOrder[0] === '古倫神父(Anselm Grün)', '新順序內容正確', $rightOrder[0]);

echo "\n== 3. 角色詞:整列就是角色詞 ==\n";
foreach (['文' => 'author', '圖' => 'illustrator', '譯' => 'translator',
          '著' => 'author', '繪' => 'illustrator', '編' => 'editor',
          '主編' => 'editor', ' 文 ' => 'author'] as $in => $want) {
    ok(person_is_role_word($in) === $want, "「{$in}」應判為 {$want}", var_export(person_is_role_word($in), true));
    $n = normalize_person_name($in);
    ok($n['kind'] === 'role_word' && $n['clean'] === '', "「{$in}」normalize 應為 role_word 且 clean 空", $n['kind'] . '/' . $n['clean']);
}

echo "\n== 4. ★防誤傷:含角色詞字元的真名一個都不准動 ==\n";
$realNames = [
    '文子梁', '金玉梅', '陳志文', '王大文', '李文', '文心蘭', '歐陽文忠',
    '圖登嘉措', '作慕容', '繪里香', '譯迦南',          // 以角色詞開頭的真名
    '梁家麟', '黃伯和', '邁爾', '梁淑儀', '潘秋松',
    'Anselm Grün', 'C. S. Lewis', '托馬斯‧奧登',
];
foreach ($realNames as $n) {
    $r = normalize_person_name($n);
    ok($r['clean'] === $n && $r['kind'] === 'clean' && !$r['hold'],
        "真名「{$n}」不可被改", "clean={$r['clean']} kind={$r['kind']} hold=" . implode('|', $r['hold']));
}

echo "\n== 5. 角色前綴:一定要有冒號才剝 ==\n";
$pfx = [
    '文:王小明'   => ['王小明', 'author'],
    '圖:李小華'   => ['李小華', 'illustrator'],
    '譯:陳大年'   => ['陳大年', 'translator'],
    '文、圖:王小明' => ['王小明', 'author'],
    '文/圖:王小明'  => ['王小明', 'author'],
];
foreach ($pfx as $in => [$wantName, $wantRole]) {
    $r = normalize_person_name($in);
    ok($r['clean'] === $wantName && $r['role'] === $wantRole,
        "「{$in}」應剝成「{$wantName}」/{$wantRole}", "{$r['clean']}/" . var_export($r['role'], true));
}
// 冒號前不是角色詞 → 不剝(而且會被標成整段文字,進人工清單)
$r = normalize_person_name('經文引用:和合本修訂版聖經');
ok($r['kind'] === 'long_text', '「經文引用:…」應判 long_text', $r['kind']);

echo "\n== 6. 字尾註記:票上第三節那六筆要收斂到同一個名字 ==\n";
$tails = [
    '梁家麟著'   => '梁家麟',
    '邁爾著'     => '邁爾',
    '梁淑儀 編'  => '梁淑儀',
    '黃伯和主編' => '黃伯和',
    '黃伯和編'   => '黃伯和',
    '黃伯和編輯' => '黃伯和',
    '潘秋松審訂' => '潘秋松',
    '某某某等'   => '某某某',
    '王小明編著' => '王小明',
    '王小明合著' => '王小明',
];
foreach ($tails as $in => $want) {
    $r = normalize_person_name($in);
    ok($r['clean'] === $want && !$r['hold'], "「{$in}」應剝成「{$want}」",
        "{$r['clean']} hold=" . implode('|', $r['hold']));
}
// ★ 長的先試:若先試「輯」會得到「黃伯和編」
ok(normalize_person_name('黃伯和編輯')['clean'] === normalize_person_name('黃伯和編')['clean'],
   '編輯/編/主編 三種寫法要收斂到同一個名字');

echo "\n== 7. ★剝完太短一律 hold,不採用 ==\n";
foreach (['李著', '王編', '陳譯', '李等'] as $in) {
    $r = normalize_person_name($in);
    ok($r['hold'] !== [] && $r['clean'] === $in, "「{$in}」應 hold 且退回原值",
       "clean={$r['clean']} hold=" . implode('|', $r['hold']));
}
// 剝完還是角色詞 → hold
$r = normalize_person_name('著者');
ok($r['kind'] === 'role_word', '「著者」整列就是角色詞', $r['kind']);

echo "\n== 8. 全形分號與 entity 殘骸:標記而不剝字 ==\n";
$r = normalize_person_name("甲\u{FF1B}乙");
ok($r['kind'] === 'fullwidth_semi' && $r['clean'] === "甲\u{FF1B}乙", '全形分號列應標 fullwidth_semi 並退回原值', $r['kind']);
$r = normalize_person_name('古倫神父(Anselm Gr&uuml');
ok($r['kind'] === 'entity', 'entity 殘骸應標 entity', $r['kind']);
$r = normalize_person_name('Anselm Gr&uuml;n');
ok($r['kind'] === 'entity', '完整 entity 也應標 entity', $r['kind']);

echo "\n== 9. 整段文字:只標記,不提供 clean ==\n";
$longs = [
    '序 走在信心之路的你…….IX',
    '經文引用:和合本修訂版聖經(和合本2010上帝版)',
    '凸桑中文聖經協會(代規格:13.6 x 19.6 cm',
    '醫生對我說……',
];
foreach ($longs as $in) {
    $r = normalize_person_name($in);
    ok($r['kind'] === 'long_text' && $r['clean'] === $in && $r['hold'] !== [],
        "「" . mb_substr($in, 0, 12) . "…」應判 long_text 且退回原值", $r['kind']);
}

echo "\n== 10. 異體字:只比對,不轉換 ==\n";
ok(pn_same_variant('托', '託'), '托/託 是異體字');
ok(pn_same_variant('奧', '奥'), '奧/奥 是異體字');
ok(!pn_same_variant('明', '民'), '明/民 不是異體字');
// ★ 簡繁刻意不收:不可以把簡繁當成異體字自動併
ok(!pn_same_variant('聖', '圣'), '★ 簡繁不可視為異體字');
ok(!pn_same_variant('馬', '马'), '★ 簡繁不可視為異體字');

echo "\n== 11. ★regex 裡不可出現字面全形字元(陷阱 11) ==\n";
$src = file_get_contents(__DIR__ . '/lib_person.php');
// 抓出所有 preg_* 的 pattern 字串,檢查有沒有混進字面全形標點
preg_match_all("/preg_[a-z_]+\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $pm);
$bad = [];
foreach ($pm[1] as $pat) {
    if (preg_match('/[\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]/u', $pat, $m)) {
        $bad[] = $pat . '  ←  含字面全形「' . $m[0] . '」(U+' . strtoupper(dechex(mb_ord($m[0], 'UTF-8'))) . ')';
    }
}
ok($bad === [], 'preg 樣式一律用 \\x{} escape,不得寫字面全形字元', implode("\n         ", $bad));

// 同時驗分隔符常數的實際位元組(退化成兩個半形分號就是靠這條抓出來的)
$hex = strtoupper(bin2hex(implode('', PN_DELIMS)));
ok($hex === '3BEFBC9BE38081', 'PN_DELIMS 位元組應為 3B EFBC9B E38081(; ; 、)', $hex);

echo "\n== 12. pn_split_names 端到端(實際出貨的那條路) ==\n";
$e2e = [
    // 輸入 => [期望人名…]
    '古倫神父(Anselm Gr&uuml;n)'                      => ['古倫神父(Anselm Grün)'],
    '文、圖:王小明'                                    => ['王小明', '王小明'],  // 作者兼繪者 → 兩列
    '文:王小明;圖:李小華'                              => ['王小明', '李小華'],
    "甲\u{FF1B}乙"                                     => ['甲', '乙'],          // ★ 全形分號要拆
    '彭正雄、陳碧凌、Breakazine 創作小組 (彼、桀、onki、gi)'
        => ['彭正雄', '陳碧凌', 'Breakazine 創作小組 (彼、桀、onki、gi)'],     // 括號內不拆
    '梁家麟著;邁爾著'                                  => ['梁家麟', '邁爾'],
    '黃伯和主編、黃伯和編輯'                            => ['黃伯和', '黃伯和'],  // 兩種寫法收斂成同一人
    '陳志文、李文'                                     => ['陳志文', '李文'],    // ★ 真名不可被剝
    '王小明;等'                                        => ['王小明'],
];
foreach ($e2e as $in => $want) {
    $got = array_column(pn_split_names($in), 'name');
    ok($got === $want, "切「{$in}」應得 [" . implode(', ', $want) . ']', '[' . implode(', ', $got) . ']');
}
// ★ 複合角色標籤:「文、圖:王小明」= 同一人掛兩個角色,不是兩個人,也不是只剩一個角色
$r = pn_split_names('文、圖:王小明');
ok(array_column($r, 'role') === ['author', 'illustrator'], '文、圖:某某 → 同一人掛 author + illustrator',
   implode(',', array_map(fn($x) => (string) $x, array_column($r, 'role'))));
ok(count(array_unique(array_column($r, 'name'))) === 1, '而且是同一個人');
// ★ 但「文:甲;圖:乙」不可以整串套同一組角色(乙不是作者)
$r = pn_split_names('文:甲大年;圖:乙小華');
ok(array_column($r, 'name') === ['甲大年', '乙小華'] && array_column($r, 'role') === ['author', 'illustrator'],
   '不同角色各有其人時不可整串套', implode(',', array_column($r, 'name')) . '|' . implode(',', array_column($r, 'role')));

// 角色提示:「圖:李小華」即使出現在 authors_raw,也要回 illustrator
$r = pn_split_names('文:王小明;圖:李小華');
ok($r[0]['role'] === 'author' && $r[1]['role'] === 'illustrator', '角色提示要跟著署名走',
   var_export(array_column($r, 'role'), true));
// 沒剝到角色詞 → role 為 null(呼叫端沿用欄位角色)
ok(pn_split_names('梁家麟')[0]['role'] === null, '沒剝到角色詞時 role 應為 null');
// credit_text 要是 decode 後的署名原文,不是剝過的名字
$r = pn_split_names('梁家麟著');
ok($r[0]['credit'] === '梁家麟著' && $r[0]['name'] === '梁家麟', 'credit 保留署名原文、name 是剝過的',
   $r[0]['credit'] . '/' . $r[0]['name']);
// 括號不成對 → 退回直接切割,而且要出聲
$warned = [];
$r = pn_split_names('甲(乙;丙', function ($s) use (&$warned) { $warned[] = $s; });
ok($warned !== [], '括號不成對要大聲失敗');

echo "\n== 13. ★主機實測回來的真實資料(2026-10-03 第一次 dry-run 抓到的三個缺陷) ==\n";

// ── 13a. entity 誤判:「& + 字母」不是 entity ──────────────────────────
//    第一版用寬鬆樣式盤出 43 列,票上 9/8 量的是 11 —— 對不上就是判準太寬。
foreach (['Corrine L. Carvalho&Paul V. Niskanen', 'CLOUD&TOWNSEND', 'Tony&Tina牧師',
          'BENSON&FRIENDS', 'Gary Ezzo&Ro', '約書亞樂團&HILLSONG Y&F',
          '賴特 & 伯德 (N.T. Wright&Michael F. Bird)'] as $n) {
    ok(person_entity_fragment($n) === null, "「{$n}」只是人名中間一個 & 號,不是 entity",
       (string) person_entity_fragment($n));
    ok(normalize_person_name($n)['kind'] !== 'entity', "「{$n}」不可判成 entity");
}
foreach (['傅堂恩&amp' => '&amp', '古倫神父（Anselm Gr&uuml' => '&uuml',
          '克雷梅爾（Michael Kr&auml' => '&auml', '葛德&bull' => '&bull',
          '葛德‧泰森 (Gerd Thei&szlig' => '&szlig', '安德魯．布查(Andr&eacute' => '&eacute',
          '約格．辛克（J&ouml' => '&ouml'] as $n => $want) {
    ok(person_entity_fragment($n) === $want, "「{$n}」是真的 entity 殘骸", (string) person_entity_fragment($n));
}

// ── 13b. 字尾註記:剝完不可以留下半截連接詞 ────────────────────────────
$tails2 = [
    '伊爾文等著'          => '伊爾文',
    '威廉．克萊因 等合著' => '威廉．克萊因',
    '徐淑貞 等合著'       => '徐淑貞',
    '榮鳳,保羅合編著'     => '榮鳳,保羅',
    '漆立平&漆哈拿合著'   => '漆立平&漆哈拿',
    '王正中主編'          => '王正中',
    '唐佑之著'            => '唐佑之',
    '潘秋松審訂'          => '潘秋松',
];
foreach ($tails2 as $in => $want) {
    $r = normalize_person_name($in);
    ok($r['clean'] === $want && !$r['hold'], "「{$in}」應剝成「{$want}」",
       $r['clean'] . ' hold=' . implode('|', $r['hold']));
}
// ★ 剝完還帶斜線的不給改名(「賴倍偉/張蘭玉」仍是兩個人黏在一起,改成那樣只是換個垃圾)。
//   代價是這類轉進人工清單;`.` 與 `&` 不在守門名單裡 —— 它們在單一署名裡也會出現,
//   而 `/` `\\` `:` 是站方用來分隔「一筆」與「下一筆」的結構符號。這條線是這樣畫的。
$r = normalize_person_name('賴倍偉/張蘭玉合著');
ok($r['hold'] !== [] && $r['clean'] === '賴倍偉/張蘭玉合著', '剝完還帶斜線 → hold,不給改名', $r['clean']);
ok(normalize_person_name('漆立平&漆哈拿合著')['clean'] === '漆立平&漆哈拿', '`&` 不在守門名單裡');

// ★★ 「黃伯和」的「和」是名字的字 —— 這就是為什麼「和著」「和編」不可以進字尾清單:
//    它們比「編」長會先命中,「黃伯和編」會被剝成「黃伯」。(2026-10-03 自己踩過一次。)
ok(normalize_person_name('黃伯和主編')['clean'] === '黃伯和', '「黃伯和主編」不可剝成「黃伯」');
ok(normalize_person_name('黃伯和編')['clean'] === '黃伯和', '★「黃伯和編」不可剝成「黃伯」');
ok(normalize_person_name('黃伯和編輯')['clean'] === '黃伯和', '「黃伯和編輯」不可剝成「黃伯」');
// 代價:前面有空格的「… 和著」只能判 hold 交人工 —— 寧可不剝,不可吃掉名字裡的字
$r = normalize_person_name('蔡春曦.蔡黃玉珍 和著');
ok($r['hold'] !== [] && $r['clean'] === '蔡春曦.蔡黃玉珍 和著',
   '「… 和著」剝完殘尾是半截連接詞 → hold 退回原值', $r['clean']);

// ── 13c. 兩個人黏在同一列,沒有分隔符可切 → hold,不可只剝最後一個註記 ──
foreach (['傑克.海福德著 呂妙芬譯', '戈登費依著 顧添祥譯', 'R.C.BRIGGS著 葉約翰譯',
          '邁爾著 鐘越娜譯'] as $in) {
    $r = normalize_person_name($in);
    ok($r['hold'] !== [] && $r['clean'] === $in, "「{$in}」兩個人黏一起,應 hold 並退回原值",
       $r['clean'] . ' hold=' . implode('|', $r['hold']));
}

echo "\n== 14. ★站方用 \\ 與斜線分隔署名(實測 109 列) ==\n";
$slash = [
    '文：江淑文\圖：陳嘉鈴'           => ['江淑文', '陳嘉鈴'],
    '文:南希．葛絲瑞/圖:珍妮．布雷克' => ['南希．葛絲瑞', '珍妮．布雷克'],
    '文／懶鬼KK；圖／懶鬼漫畫部同事s' => ['懶鬼KK', '懶鬼漫畫部同事s'],
    '文:姜蜜&游紫玲\圖:禧平'          => ['姜蜜&游紫玲', '禧平'],
    '波特/主編'                        => ['波特'],
    '主編：陳廷忠'                     => ['陳廷忠'],
    '文\圖:陳嘉鈴'                     => ['陳嘉鈴', '陳嘉鈴'],   // 複合標籤 → 同一人兩個角色
];
foreach ($slash as $in => $want) {
    $got = array_column(pn_split_names($in), 'name');
    ok($got === $want, "切「{$in}」應得 [" . implode(', ', $want) . ']', '[' . implode(', ', $got) . ']');
}
// ★★ 斜線不可以無條件當分隔符:「江淑文」的「文」前面不是分隔符,不准腰斬
ok(array_column(pn_split_names('亨利.克勞德/約翰.湯森德'), 'name') === ['亨利.克勞德/約翰.湯森德'],
   '沒有角色詞時斜線不可當分隔符(寧可不拆也不可拆錯)');
ok(array_column(pn_split_names('江淑文'), 'name') === ['江淑文'], '「江淑文」不可被腰斬成「江淑」');
// 角色要跟著標籤走
$r = pn_split_names('文：江淑文\圖：陳嘉鈴');
ok(array_column($r, 'role') === ['author', 'illustrator'], '斜線分隔時角色也要對',
   implode(',', array_map(fn($x) => (string) $x, array_column($r, 'role'))));

echo "\n== 15. 異體字:實測命中的那幾對 ==\n";
foreach ([['恒','恆'], ['托','託'], ['于','於'], ['奥','奧'], ['眞','真'], ['啓','啟'],
          ['杰','傑'], ['昇','升'], ['衛','衞'], ['峰','峯'], ['黃','黄'], ['臺','台']] as [$a, $b]) {
    ok(pn_same_variant($a, $b), "{$a}/{$b} 應判為異體字");
}
foreach ([['常','長'], ['義','文'], ['倪','巴'], ['如','茹'], ['明','民'], ['聖','圣'], ['馬','马']] as [$a, $b]) {
    ok(!pn_same_variant($a, $b), "★ {$a}/{$b} **不是**異體字(可能是錯字或兩個人,要人工)");
}

echo "\n== 16. ★★ 會切成多個人的列,不可以走「改名」那條路 ==\n";
// 2026-10-03 第二輪主機實測抓到的汙染型 bug:
// check_person_names 用的是 normalize_person_name(單一人名),它不會切。
// 「文:江淑文\圖:陳嘉鈴」只剝掉開頭標籤 →「江淑文\圖:陳嘉鈴」被當成新名字去改名,
// **比原本更糟**。這類必須判成 needs_resplit,交 fix_person_names.php 重切。
$multi = [
    '文：江淑文\圖：陳嘉鈴',
    '文：鄭和茵 \ 圖：蔡兆倫',
    '圖/那信 文/贖君',
    '文/吳昭誼 圖/林育如',
    '文:姜蜜&游紫玲\圖:禧平',
    '文:南希．葛絲瑞/圖:珍妮．布雷克',
    '文／避雨，圖／那羊',
    '文/安德蕾．普蘭&圖/馬帝歐．朗彭',
    '圖/劉芳 文/陳進隆',
    '文/吳盈光姊妹 圖/林佳怡姊妹',
    "甲\u{FF1B}乙",
];
// 三種 kind 的處置相同(都走 fix_person_names.php 重切),差別只在標籤哪個比較具體
$resplitKinds = ['needs_resplit', 'fullwidth_semi', 'entity'];
foreach ($multi as $in) {
    $r = normalize_person_name($in);
    ok(in_array($r['kind'], $resplitKinds, true) && $r['clean'] === $in && $r['hold'] !== [],
       "「{$in}」應判成要重切並退回原值", "kind={$r['kind']} clean={$r['clean']}");
    ok(count(pn_split_names($in)) >= 2, "「{$in}」pn_split_names 要切得出 2 個以上", (string) count(pn_split_names($in)));
}
// ★ 單一個人不可以被誤判成要重切
foreach (['文：江淑文', '文、圖:王小明', '梁家麟著', '黃伯和編', '陳志文', '文子梁',
          '漆立平&漆哈拿合著', '邱林川&阮耀啟', '亨利.克勞德/約翰.湯森德'] as $in) {
    ok(normalize_person_name($in)['kind'] !== 'needs_resplit',
       "「{$in}」不可被判成要重切", normalize_person_name($in)['kind']);
}

echo "\n== 17. 署名在後的角色詞(「巴刻著 趙中輝譯」這一大批) ==\n";
$suffixPairs = [
    '巴刻著 趙中輝譯'      => [['巴刻', 'author'], ['趙中輝', 'translator']],
    '華德.凱瑟著 潘秋松譯' => [['華德.凱瑟', 'author'], ['潘秋松', 'translator']],
    '傑克.海福德著 呂妙芬譯' => [['傑克.海福德', 'author'], ['呂妙芬', 'translator']],
    '陸艾文著/高鳳仙譯'    => [['陸艾文', 'author'], ['高鳳仙', 'translator']],
    '唐諾.古特立著 高以峰等譯' => [['唐諾.古特立', 'author'], ['高以峰', 'translator']],
    '史考基著 呂瑞玉\ 譯'  => [['史考基', 'author'], ['呂瑞玉', 'translator']],
];
foreach ($suffixPairs as $in => $want) {
    $r = pn_split_names($in);
    $got = array_map(fn($x) => [$x['name'], $x['role']], $r);
    ok($got === $want, "切「{$in}」", json_encode($got, JSON_UNESCAPED_UNICODE));
}
// ★★ 「等」不是切點:它的意思是「與其他人」,不是一個署名的結束。
//    「王小明 等 李四」切開會憑空斷定那是兩個人 —— 沒有證據就不切。
ok(count(pn_split_names('王小明 等 李四')) === 1, '★「等」後面有分隔符也不可以當切點',
   json_encode(array_column(pn_split_names('王小明 等 李四'), 'name'), JSON_UNESCAPED_UNICODE));
ok(count(pn_split_names('趙曉彤 等 雷日昇')) === 1, '★ 同上');
// ★ 複合註記的角色要查得到:「等譯」的角色是譯者,不是沿用欄位的 author
$r = pn_split_names('唐諾.古特立著 高以峰等譯');
ok($r[1]['role'] === 'translator', '「高以峰等譯」的角色應為 translator', (string) $r[1]['role']);
ok(normalize_person_name('王小明等編')['role'] === 'editor', '「等編」的角色應為 editor',
   (string) normalize_person_name('王小明等編')['role']);

// ★ 不可誤切:註記詞後面不是分隔符就不是切點
ok(array_column(pn_split_names('黃伯和編輯'), 'name') === ['黃伯和'], '「黃伯和編輯」不可被切開');
ok(array_column(pn_split_names('梁淑儀 編'), 'name') === ['梁淑儀'], '「梁淑儀 編」不可被切開');
ok(array_column(pn_split_names('伊爾文等著'), 'name') === ['伊爾文'], '「伊爾文等著」不可被切開');

echo "\n== 18. 名字後面用空白或連字號掛角色詞 ==\n";
$trailing = [
    '雅樹 文'      => ['雅樹', 'author'],
    '棗田 圖'      => ['棗田', 'illustrator'],
    '游紫玲-文'    => ['游紫玲', 'author'],
    '禧平-圖'      => ['禧平', 'illustrator'],
    '飯嶌玲子 繪'  => ['飯嶌玲子', 'illustrator'],
    '雷日昇 攝影'  => ['雷日昇', 'illustrator'],
];
foreach ($trailing as $in => [$wn, $wr]) {
    $r = pn_split_names($in);
    ok(count($r) === 1 && $r[0]['name'] === $wn && $r[0]['role'] === $wr,
       "「{$in}」→「{$wn}」/{$wr}", json_encode(array_map(fn($x)=>[$x['name'],$x['role']], $r), JSON_UNESCAPED_UNICODE));
}
// ★ 沒有分隔符就不准剝(這是「陳志文」活下來的理由)
foreach (['陳志文', '李文', '文子梁', '金玉梅', '王大文'] as $in) {
    ok(pn_split_names($in)[0]['name'] === $in, "「{$in}」不可被剝");
}

echo "\n== 19. ★第三輪主機實測:三個新誤傷 ==\n";

// ── 19a. 「原著」「新編」比「著」「編」長,要排在前面,否則「原」「新」會被留下 ──
$compound = [
    '李懷光原著'   => ['李懷光', 'author'],
    '某某某新譯'   => ['某某某', 'translator'],
    '王小明改寫'   => ['王小明', 'author'],
    '王小明審校'   => ['王小明', 'editor'],
];
foreach ($compound as $in => [$wn, $wr]) {
    $r = normalize_person_name($in);
    ok($r['clean'] === $wn && !$r['hold'], "「{$in}」應剝成「{$wn}」", $r['clean'] . ' ' . implode('|', $r['hold']));
}
// ★ 「邱瓊苑新編」不剝:「吳小新編」與「侯士庭新編」在規則上分不出來
//   (一個是「吳小新」+「編」,一個是「侯士庭」+「新編」)→ 交人工,不猜。
ok(array_column(pn_split_names('李懷光原著/邱瓊苑新編'), 'name') === ['李懷光', '邱瓊苑新編'],
   '「原著」照剝,「新編」交人工',
   json_encode(array_column(pn_split_names('李懷光原著/邱瓊苑新編'), 'name'), JSON_UNESCAPED_UNICODE));

// ── 19b. ★★ 註記詞後面接括號 → 那是同一個人的英文名,**不是**下一個人 ──
//    「約珥．薩頓 主編 (Joel Sutton)」被切開會憑空生出一個叫「(Joel Sutton)」的人。
//    這是本輪新加的切法自己造出來的傷,不是舊資料的問題。
foreach (['約珥．薩頓 主編 (Joel Sutton)', '董家驊主編 (12位作者合著)',
          '柏饒齊 著 (Walter C. Kaiser Jr.)'] as $in) {
    ok(count(pn_split_names($in)) === 1, "「{$in}」不可被切開(括號裡是同一人的註記)",
       json_encode(array_column(pn_split_names($in), 'name'), JSON_UNESCAPED_UNICODE));
}
// 但後面不是括號就照切
ok(count(pn_split_names('巴刻著 趙中輝譯')) === 2, '後面不是括號還是要切');

// ── 19c. ★ 剝完的結果若還帶著結構符號(冒號/斜線/反斜線)→ 不是乾淨的人名,一律 hold ──
//    「圖/賽卓．卡利耶羅 編文/道格．莫斯」剝掉開頭的「圖/」之後,
//    剩下的「賽卓．卡利耶羅 編文/道格．莫斯」被當成新名字拿去改名 —— 又是改成垃圾。
foreach (['圖/賽卓．卡利耶羅 編文/道格．莫斯',
          '主編:邱林川&阮耀啟／圖:某某'] as $in) {
    $r = normalize_person_name($in);
    ok($r['hold'] !== [] || $r['kind'] === 'needs_resplit',
       "「{$in}」剝完還帶結構符號 → 不可產生改名計畫", "kind={$r['kind']} clean={$r['clean']}");
}
// ★ 但「沒有要改」的列不受影響:本來就含斜線又剝不出東西的,維持原樣不重新分類
$r = normalize_person_name('亨利.克勞德/約翰.湯森德');
ok($r['kind'] === 'clean' && $r['clean'] === '亨利.克勞德/約翰.湯森德',
   '沒有要改的列不因為含斜線就被重新分類', "kind={$r['kind']}");

echo "\n== 20. ★★第四輪:剝除詞分布表抓出來的誤傷 ==\n";
// 這一批全部是「剝壞真名」,不是覆蓋率不足 —— 分布表的「併入既有列比例」把它們釣出來:
// 剝完的名字站上一列都沒有,就是剝出了一個根本不存在的人。

// ── 20a. 「翻譯者」「創作者」的結尾就是註記詞本身(與「陳志文」同型) ──
$glued = [
    '創造科學翻譯者',      // 「翻譯者」不是「翻」+「譯者」
    '傳月刊靈修作者',      // 「靈修作者」
    '胖手收   插畫創作者',  // 「插畫創作者」
    '吳小新編',            // ★「吳小新」是人名,「編」才是註記
    '侯士庭新編',          // ★ 跟上一個在規則上分不出來 → 兩個都交人工
];
foreach ($glued as $in) {
    $r = normalize_person_name($in);
    ok($r['clean'] === $in, "「{$in}」沒有分隔符 → 一個字都不准剝", $r['clean']);
}
// 有分隔符就照剝
foreach (['李安琴 譯者' => '李安琴', '劉怡君 改寫' => '劉怡君', '張晉霖 改編' => '張晉霖',
          '蕭恩松 審校' => '蕭恩松'] as $in => $want) {
    ok(normalize_person_name($in)['clean'] === $want, "「{$in}」有分隔符 → 剝成「{$want}」",
       normalize_person_name($in)['clean']);
}
// 「原著」不需要分隔符(「賈禮榮原著」實測 78% 併得進既有列)
ok(normalize_person_name('賈禮榮原著')['clean'] === '賈禮榮', '「賈禮榮原著」照剝');

// ── 20b. 更長的註記詞要補進來,否則殘尾是半截 ──
foreach (['錢錕 總審訂' => '錢錕', '保羅．梅爾原文編譯' => '保羅．梅爾'] as $in => $want) {
    ok(normalize_person_name($in)['clean'] === $want, "「{$in}」→「{$want}」",
       normalize_person_name($in)['clean']);
}

// ── 20c. 剝完殘尾是符號 → hold ──
foreach (['田頌恩◎審訂', '郭承天..等 編著'] as $in) {
    $r = normalize_person_name($in);
    ok($r['hold'] !== [] && $r['clean'] === $in, "「{$in}」剝完殘尾是符號 → hold", $r['clean']);
}

// ── 20d. 中段署名的分隔符要含頓號與點 ──
$r = normalize_person_name('孫揚光口述.吳淑玲編撰');
ok($r['hold'] !== [] || $r['kind'] === 'needs_resplit',
   '「孫揚光口述.吳淑玲編撰」中段有「口述」→ 兩個人,不可只剝字尾', "kind={$r['kind']} clean={$r['clean']}");
// ★ 但名字裡的點不可誤判成中段署名
ok(normalize_person_name('華德.凱瑟著')['clean'] === '華德.凱瑟', '「華德.凱瑟著」的點在名字裡,照剝');
ok(normalize_person_name('漆立平.漆哈拿編著')['clean'] === '漆立平.漆哈拿', '同上');

echo "\n== 21. ★整段都是角色詞的片段,不是人 ==\n";
// 2026-10-03 第五輪(--resplit-only dry-run 實測):「文/圖」被建成一個叫「文/圖」的 person。
// 原因:剝掉前綴「文/」之後只剩「圖」一個字 → 太短判 hold → 照原值走 → 建人。
// 整段拆開後每一塊都是角色詞,那整段就不是人。
foreach (['文/圖', '文／圖', '文、圖', '圖/文', '著/譯', '編/繪', '文\圖'] as $in) {
    $r = normalize_person_name($in);
    ok($r['kind'] === 'role_word' && $r['clean'] === '',
       "「{$in}」整段都是角色詞 → 不是人", "kind={$r['kind']} clean={$r['clean']}");
    ok(pn_split_names($in) === [], "「{$in}」不該切出任何人", json_encode(array_column(pn_split_names($in), 'name'), JSON_UNESCAPED_UNICODE));
}
// ★ 防誤傷:有一塊不是角色詞就不算
foreach (['文/王小明', '文子梁', '陳志文', '圖們江', '作慕容'] as $in) {
    ok(normalize_person_name($in)['kind'] !== 'role_word', "「{$in}」不可被當成純角色標籤",
       normalize_person_name($in)['kind']);
}

echo "\n== 22. ★第六輪:C 段(直接改名)前 30 列就抓到的兩個 ==\n";

// 22a. 剝掉單字註記後殘尾落在連接字 → hold(不管前面有沒有空白)
//      「曾思瀚&鄧紹光和著」剝掉「著」會剩「…鄧紹光和」——「和著」只剝掉一半。
//      ★ 不能把「和」無條件當殘尾剝掉:「黃伯和主編 → 黃伯和」的「和」是名字的字。
//        兩者在字形上分不開 → **一律 hold**,代價是「黃伯和著」這種也進人工清單。
//        寧可多幾列人工,也不要改出一個不存在的人。
// ★ 只留實測到的那一筆。另外兩個是我自己編的 —— 編出來的資料不該拿來設計規則。
foreach (['曾思瀚&鄧紹光和著'] as $in) {
    $r = normalize_person_name($in);
    ok($r['hold'] !== [] && $r['clean'] === $in, "「{$in}」剝完殘尾是連接字 → hold", $r['clean']);
}
// ★ 但「和」在**註記詞之前就結束**的照剝(殘尾不是連接字)
// ★★ 這三個是票上要收斂到同一個人的那六筆,新規則一個都不准擋到
ok(normalize_person_name('黃伯和主編')['clean'] === '黃伯和', '「黃伯和主編」照剝');
ok(normalize_person_name('黃伯和編輯')['clean'] === '黃伯和', '「黃伯和編輯」照剝');
ok(normalize_person_name('黃伯和編')['clean'] === '黃伯和', '★「黃伯和編」照剝(沒有多人分隔符)');
ok(normalize_person_name('黃伯和著')['clean'] === '黃伯和', '★「黃伯和著」照剝');

// 22b. 剝完仍以註記詞結尾 → 沒剝乾淨,hold
foreach (['白立德著著', '王小明編編', '李大年譯譯'] as $in) {
    $r = normalize_person_name($in);
    ok($r['hold'] !== [] && $r['clean'] === $in, "「{$in}」剝完仍以註記詞結尾 → hold", $r['clean']);
}
// ★ 正常的一層註記照剝
foreach (['白立德著' => '白立德', '梁家麟著' => '梁家麟', '黃錫木主編' => '黃錫木'] as $in => $want) {
    ok(normalize_person_name($in)['clean'] === $want, "「{$in}」→「{$want}」", normalize_person_name($in)['clean']);
}

echo "\n== 23. ★第七輪:C 段 339 列全列審出來的「撰著」 ==\n";

// 23a. 「撰著」是複合註記詞,整個剝掉。
//      沒有這條,只會命中短的「著」→「尹可名撰著」變成「尹可名撰」,又是工具自己造髒資料。
//      ★ 這兩筆是 2026-10-03 C 段實測資料,不是編的。
foreach (['尹可名撰著' => '尹可名', '尹可名 撰著' => '尹可名'] as $in => $want) {
    $r = normalize_person_name($in);
    ok($r['clean'] === $want && $r['hold'] === [], "「{$in}」→「{$want}」", $r['clean'] . ' hold=' . json_encode($r['hold'], JSON_UNESCAPED_UNICODE));
}
// 23b. 剝完殘尾落在「撰」這種角色字 → 沒剝乾淨,hold(攔住下一個還沒發現的複合詞)
//      PN_TAIL_NOTES 本身沒有「撰」,所以第六輪那條殘尾檢查看不到它 → 另開一張表。
foreach (['王小明撰編', '李大年述著'] as $in) {
    $r = normalize_person_name($in);
    ok($r['hold'] !== [] && $r['clean'] === $in, "「{$in}」剝完殘尾是角色字 → hold", $r['clean']);
}
// 23c. ★ 防誤傷:殘尾表裡的字當成名字最後一字時絕不可以擋
//      「陳志文」「佛洛.麥克艾文」的「文」、「詹姆斯．拉文」—— C 段實際有這幾列。
foreach (['佛洛.麥克艾文著' => '佛洛.麥克艾文', '詹姆斯．拉文 主編' => '詹姆斯．拉文',
          '伍謂文主編' => '伍謂文', '馮 煒 文 著' => '馮 煒 文'] as $in => $want) {
    $r = normalize_person_name($in);
    ok($r['clean'] === $want && $r['hold'] === [], "「{$in}」→「{$want}」(名字末字是角色字,不可擋)", $r['clean']);
}

echo "\n== 24. ★第八輪:C 段 337 列整批重跑才抓到的「主編著」「共同主編」 ==\n";

// 24a. 兩筆都是 2026-10-03 已經寫進生產資料才被抓到的實測資料。
//      第七輪我只掃了自己手挑的字集(著編譯撰輯訂校選註繪圖文),沒有「主」→ 漏掉。
foreach (['林治平主編著' => '林治平', '郭榮剛 共同主編' => '郭榮剛',
          '郭榮剛 共同編著' => '郭榮剛'] as $in => $want) {
    $r = normalize_person_name($in);
    ok($r['clean'] === $want && $r['hold'] === [], "「{$in}」→「{$want}」",
       $r['clean'] . ' hold=' . json_encode($r['hold'], JSON_UNESCAPED_UNICODE));
}
// 24b. 殘尾落在「主」「同」→ hold(攔住下一個還沒收錄的複合詞)
foreach (['王小明主譯'] as $in) {
    $r = normalize_person_name($in);
    ok($r['hold'] !== [] && $r['clean'] === $in, "「{$in}」剝完殘尾是角色字 → hold", $r['clean']);
}
// 24b-2. ★「同」試過又拿掉 —— 它會誤傷真人。「共同*」改用複合詞涵蓋。
foreach (['李大年共同編' => '李大年', '郭榮剛 共同譯' => '郭榮剛'] as $in => $want) {
    ok(normalize_person_name($in)['clean'] === $want, "「{$in}」→「{$want}」(共同* 用複合詞)",
       normalize_person_name($in)['clean']);
}
foreach (['周文同著' => '周文同', '李漢文著' => '李漢文'] as $in => $want) {
    $r = normalize_person_name($in);
    ok($r['clean'] === $want && $r['hold'] === [], "★「{$in}」→「{$want}」(名字末字是「同」,不可擋)",
       $r['clean'] . ' hold=' . json_encode($r['hold'], JSON_UNESCAPED_UNICODE));
}
// 24c. ★ 防誤傷:單獨的「主編」「編著」照剝,不可以被新規則擋掉
foreach (['黃錫木主編' => '黃錫木', '劉立意編著' => '劉立意',
          '蔡錦圖 編著' => '蔡錦圖', '吳小新 主編' => '吳小新'] as $in => $want) {
    ok(normalize_person_name($in)['clean'] === $want, "「{$in}」→「{$want}」照剝",
       normalize_person_name($in)['clean']);
}

echo "\n== 25. ★第九輪:字間加空白的名字,不可以被當成「名字+角色標籤」 ==\n";

// 「馮 煒 文 著」剝掉「著」之後剩「馮 煒 文」—— 規則還想再剝一次「文」變成「馮 煒」。
// 站方把「馮煒文」寫成「馮 煒 文」,每個字之間都有空白,那些空白不是分隔符。
// ★ 這一列目前靠「文 尚未放行」擋著沒出事,但 `文` 是下一批要放行的詞(33 列)。
foreach (['馮 煒 文', '梁 淑 慧', '林 郁'] as $in) {
    $r = normalize_person_name($in);
    ok($r['clean'] === $in, "★「{$in}」整串都是單字+空白,一個字都不准剝", $r['clean']);
}
// ★ 防誤傷:真的是「名字 + 角色標籤」的照剝
foreach (['某某 文' => '某某', '王小明 圖' => '王小明', '飯嶌玲子 繪' => '飯嶌玲子',
          '雷日昇 攝影' => '雷日昇', '李 安 琴 譯' => '李 安 琴'] as $in => $want) {
    ok(normalize_person_name($in)['clean'] === $want, "「{$in}」→「{$want}」照剝",
       normalize_person_name($in)['clean']);
}

echo "\n────────────────────────────\n";
echo ($fail === 0 ? "全綠:{$pass} 項通過\n" : "**{$fail} 項失敗**(通過 {$pass})\n");
exit($fail === 0 ? 0 : 1);
