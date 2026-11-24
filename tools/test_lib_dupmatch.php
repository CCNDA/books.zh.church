<?php
declare(strict_types=1);
/**
 * lib_dupmatch.php 的測試(2026-11-24 加作者集合包含,Asana 1220592217365786)。
 * 跑法:php tools/test_lib_dupmatch.php   全綠 exit 0
 * 重點:找重複清單(setMatch=true)放寬;import.php 自動合併(setMatch=false)不變。
 */
require_once __DIR__ . '/lib_dupmatch.php';

$fail = 0; $pass = 0;
function ok(bool $cond, string $what, string $got = ''): void
{
    global $fail, $pass;
    if ($cond) { $pass++; return; }
    $fail++;
    echo "  [FAIL] {$what}" . ($got !== '' ? "  實得:{$got}" : '') . "\n";
}
$lv = fn(?string $a, ?string $b, bool $set, array $sa = ['x'], array $sb = ['y']) => dm_judge($a, $b, $sa, $sb, $set)[0];

echo "== 作者集合包含(找重複清單) ==\n";
ok($lv('John R. Kohlenberger、王正中', '王正中', true) === '高', '主檔累積別名:一邊的作者都在另一邊 → 高', $lv('John R. Kohlenberger、王正中', '王正中', true));
ok($lv('王正中', 'John R. Kohlenberger;王正中', true) === '高', '順序反過來也一樣');
ok($lv('賴特（N. T. Wright）', '湯姆．賴特、N. T. Wright', true) === '高', '括號裡的英文名也算一種寫法');
ok($lv('溫儒彬、王正中', '王正中', true) === '高', '多的那邊包含少的那邊(第一作者不同)');
ok($lv('溫儒彬、王正中', '張三、王正中', true) === '不併', '兩邊各有一位對不到 → 不併');
ok($lv('張三', '李四', true) === '不併', '完全不同 → 不併');
ok($lv('王', 'John、王小明', true) === '不併', '單字不算(太短)');
ok($lv('John R. Kohlenberger、王正中', '王正中', true, ['a'], ['a']) === '待判', '同一家書房 → 仍然待判');
ok($lv(null, '', true) === '待判', '兩邊都沒作者 → 待判');
ok(dm_judge('John R. Kohlenberger、王正中', '王正中', ['x'], ['y'], true)[1] === '一邊的作者都在另一邊的作者欄裡(主檔累積別名)', '理由寫清楚');

echo "== import.php 自動合併(setMatch=false)不變 ==\n";
ok($lv('John R. Kohlenberger、王正中', '王正中', false) === '不併', '不開集合比對 → 跟以前一樣不併');
ok($lv('賴特', '湯姆．賴特', false) === '高', '原本的「互相包含」照舊');
ok($lv('賴特（N. T. Wright）', '賴特', false) === '高', '原本的去括號照舊');
ok($lv('張三', '李四', false) === '不併', '不同作者照舊不併');

echo "\n通過 {$pass}、失敗 {$fail}\n";
exit($fail ? 1 : 0);
