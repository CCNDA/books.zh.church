<?php
declare(strict_types=1);
/**
 * lang_group() 分類規則單元測試（M1-B 語言篩選；Asana 1220724721485985）
 * 用法：php tools/test_lang_group.php [lang_group.php 路徑]   全過 exit 0；有一條不過 exit 1
 *   帶別的路徑＝拿那一版跑（陰性對照用）
 */
require $argv[1] ?? __DIR__ . '/../app/lib/lang_group.php';

$cases = [
    // 繁體
    ['繁體中文', 'zh-Hant'], ['繁體', 'zh-Hant'], ['zh-Hant', 'zh-Hant'], ['Traditional Chinese', 'zh-Hant'],
    ['繁體中文 注音', 'zh-Hant'],                     // 注音只是附註
    // 只寫中文、沒註明繁簡 → 先歸繁（台港書房）
    ['中文', 'zh-Hant'], ['華語', 'zh-Hant'], ['國語', 'zh-Hant'],
    // 簡體
    ['簡體中文', 'zh-Hans'], ['简体中文', 'zh-Hans'], ['zh-Hans', 'zh-Hans'], ['簡體中文 日期：2014年4月', 'zh-Hans'],
    // 英文
    ['English', 'en'], ['en', 'en'], ['英文', 'en'], ['NASB', 'en'],
    // 多語
    ['中英對照', 'multi'], ['繁體中文、英文', 'multi'], ['繁體／簡體', 'multi'], ['中文、台語', 'multi'], ['英文 日文', 'multi'],
    // 其他語言
    ['台語', 'other'], ['臺語白話字', 'other'], ['客語', 'other'], ['希臘文', 'other'], ['阿美語', 'other'], ['日文', 'other'],
    // 未填／雜訊
    [null, null], ['', null], ['  ', null], ['nan', null], ['NaN', null], ['map', null], ['20', null], ['平裝', null],
];

$ok = $bad = 0;
foreach ($cases as [$in, $want]) {
    $got = lang_group($in);
    $label = var_export($in, true) . ' → ' . var_export($want, true);
    if ($got === $want) { $ok++; echo "  ✓ {$label}\n"; }
    else { $bad++; echo "  ✗ {$label}（實際 " . var_export($got, true) . "）\n"; }
}
// 分組代碼要跟 API／搜尋頁的清單一致
$keys = array_keys(LANG_GROUPS);
if ($keys === ['zh-Hant', 'zh-Hans', 'en', 'multi', 'other']) { $ok++; echo "  ✓ LANG_GROUPS 五個代碼\n"; }
else { $bad++; echo "  ✗ LANG_GROUPS 代碼變了：" . implode(',', $keys) . "（API、public/index.html 的 LANGS 要一起改）\n"; }

echo "\n" . ($bad ? "失敗 {$bad} 項" : "全綠：{$ok} 項通過") . "\n";
exit($bad ? 1 : 0);
