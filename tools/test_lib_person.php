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
    '王小明譯者' => '王小明',
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

echo "\n────────────────────────────\n";
echo ($fail === 0 ? "全綠:{$pass} 項通過\n" : "**{$fail} 項失敗**(通過 {$pass})\n");
exit($fail === 0 ? 0 : 1);
