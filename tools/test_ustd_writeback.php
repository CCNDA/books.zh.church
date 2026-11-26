<?php
declare(strict_types=1);
/** 天道北美寫回規則單元測試：php tools/test_ustd_writeback.php [lib 路徑]（Asana 1220919560670331） */
require $argv[1] ?? __DIR__ . '/lib_ustd_writeback.php';

$ok = $bad = 0;
function t(string $name, bool $cond, string $info = ''): void { global $ok, $bad; if ($cond) { $ok++; echo "  ✓ $name\n"; } else { $bad++; echo "  ✗ $name $info\n"; } }
function ed(string $url, ?string $isbn, array $fp, array $links = []): array { return ['url' => $url, 'isbn13' => $isbn, 'formats' => $fp, 'links' => $links]; }
function fp(?string $price, ?string $cur = 'USD', ?string $av = null): array { return ['price' => $price, 'currency' => $cur, 'availability' => $av]; }
function apply(array $eds, array $plan): array {   // 模擬寫入，用來測「跑兩次第二次 0 筆」
    foreach ($plan['ops'] as $o) foreach ($eds as $eid => &$e) {
        if ($o['type'] !== 'link_note' && isset($e['formats'][$o['format_id'] ?? -1])) $e['formats'][$o['format_id']][$o['type'] === 'price' ? 'price' : 'availability'] = $o['new'];
        if ($o['type'] === 'link_note' && isset($e['links'][$o['link_id']])) $e['links'][$o['link_id']]['note'] = $o['new'];
    }
    return $eds;
}

// 商品號
t('slug：https＋www', ustd_slug('https://www.ustiendao.com/1-22357000.html') === '1-22357000');
t('slug：http、沒 www、帶參數', ustd_slug('http://ustiendao.com/1-22357000.html?ref=x') === '1-22357000');
t('slug：copy-of 這種', ustd_slug('https://www.ustiendao.com/copy-of-21.html') === 'copy-of-21');
t('slug：不是 .html 回 null', ustd_slug('https://www.ustiendao.com/cart') === null);

$eds = [
    1 => ed('https://www.ustiendao.com/1-111.html', '9780000000011', [11 => fp('29.95')]),
    2 => ed('http://ustiendao.com/1-222.html', '9780000000022', [21 => fp('10.00')]),
    3 => ed('https://www.ustiendao.com/1-333.html', null, [31 => fp('5.00', 'USD', 'InStock')], [301 => ['note' => null]]),
    4 => ed('https://www.ustiendao.com/1-444.html', null, [41 => fp('8.00', 'USD', 'Discontinued')], [401 => ['note' => '舊備註 ' . USTD_GONE_MARK]]),
    5 => ed('https://www.ustiendao.com/1-555.html', null, [51 => fp('8.00', 'TWD')]),
    6 => ed('https://www.ustiendao.com/dup.html', null, [61 => fp('1.00')]),
    7 => ed('https://www.ustiendao.com/dup.html', null, [71 => fp('1.00')]),
    8 => ed('https://www.ustiendao.com/1-888.html', null, [81 => fp('3.00', 'USD', 'InStock')]),
];
$rep = ['snapshot_size' => 9999, 'limit' => 0, 'stopped' => null, 'counts' => ['price' => 2, 'gone' => 1, 'availability' => 1],
    'changes' => [
        ['kind' => 'price', 'url' => 'https://www.ustiendao.com/1-111.html', 'isbn' => '9780000000011', 'old' => '29.95', 'new' => '34.95'],
        ['kind' => 'availability', 'url' => 'https://www.ustiendao.com/1-222.html', 'isbn' => '9780000000022', 'old' => null, 'new' => 'InStock'],
        ['kind' => 'availability_unknown', 'url' => 'https://www.ustiendao.com/1-888.html', 'old' => 'InStock', 'new' => null],
        ['kind' => 'gone', 'url' => 'https://www.ustiendao.com/1-333.html'],
        ['kind' => 'price', 'url' => 'https://www.ustiendao.com/1-555.html', 'old' => '8', 'new' => '9'],
        ['kind' => 'price', 'url' => 'https://www.ustiendao.com/dup.html', 'old' => '1', 'new' => '2'],
        ['kind' => 'isbn_changed', 'url' => 'https://www.ustiendao.com/1-111.html', 'new' => '9789999999999'],
        ['kind' => 'price', 'url' => 'https://www.ustiendao.com/nope.html', 'old' => '1', 'new' => '2'],
    ]];
$p = ustd_plan($rep, $eds);
$has = fn($type, $key, $id, $new) => (bool) array_filter($p['ops'], fn($o) => $o['type'] === $type && ($o[$key] ?? null) === $id && $o['new'] === $new);
t('改價：29.95→34.95', $has('price', 'format_id', 11, '34.95'));
t('None→InStock 寫入', $has('availability', 'format_id', 21, 'InStock'));
t('書頁沒標（None）不蓋已知值 InStock', !array_filter($p['ops'], fn($o) => ($o['format_id'] ?? 0) === 81));
t('下架：availability＝Discontinued', $has('availability', 'format_id', 31, 'Discontinued'));
t('下架：note 加標記', $has('link_note', 'link_id', 301, USTD_GONE_MARK));
t('重新上架（全量、不在下架名單）：Discontinued 清成 NULL', $has('availability', 'format_id', 41, null));
t('重新上架：note 標記拿掉、原備註留著', $has('link_note', 'link_id', 401, '舊備註'));
t('幣別不是 USD → 人審、不寫', !array_filter($p['ops'], fn($o) => ($o['format_id'] ?? 0) === 51) && (bool) array_filter($p['review'], fn($r) => str_contains($r['why'], 'USD')));
t('同一商品號兩個 edition → 人審、不寫', !array_filter($p['ops'], fn($o) => in_array($o['format_id'] ?? 0, [61, 71], true)) && (bool) array_filter($p['review'], fn($r) => str_contains($r['why'], '2 個 edition')));
t('換成別本 → 人審', (bool) array_filter($p['review'], fn($r) => $r['why'] === '換成別本'));
t('對不到 → unmatched', count($p['unmatched']) === 1);
t('沒有異常閘', $p['halt'] === null);

// 冪等：照 plan 寫入後再跑一次 → 0 筆
$p2 = ustd_plan($rep, apply($eds, $p));
t('同一份報告跑兩次，第二次 0 筆', count($p2['ops']) === 0, json_encode($p2['ops'], JSON_UNESCAPED_UNICODE));

// 部分對照（limit≠0）不能推論重新上架
$p3 = ustd_plan(['limit' => 100] + $rep, $eds);
t('部分對照：不清 Discontinued', !array_filter($p3['ops'], fn($o) => ($o['format_id'] ?? 0) === 41));

// 下架又同時給新的有沒有貨：只留報告給的值，不再清成 NULL（同一列一個操作）
$p4 = ustd_plan(['changes' => [['kind' => 'availability', 'url' => 'https://www.ustiendao.com/1-444.html', 'new' => 'OutOfStock']]] + $rep, $eds);
$ops41 = array_values(array_filter($p4['ops'], fn($o) => ($o['format_id'] ?? 0) === 41));
t('重新上架又有新值：只有一個操作、值是 OutOfStock', count($ops41) === 1 && $ops41[0]['new'] === 'OutOfStock', json_encode($ops41));

// 異常閘
t('下架 51 本 → 停', ustd_plan(['counts' => ['gone' => 51]] + $rep, $eds)['halt'] !== null);
t('改價 51 本 → 停', ustd_plan(['counts' => ['price' => 51]] + $rep, $eds)['halt'] !== null);
t('有貨 101 本（>1%）→ 停', ustd_plan(['counts' => ['availability' => 101]] + $rep, $eds)['halt'] !== null);
t('對照中途停 → 停', ustd_plan(['stopped' => 'Cloudflare'] + $rep, $eds)['halt'] !== null);
t('這次實際數字（下架 13、改價 32、有貨 161）→ 有貨 161 超過 1%，會停', ustd_plan(['counts' => ['gone' => 13, 'price' => 32, 'availability' => 161]] + $rep, $eds)['halt'] !== null);

echo "\n" . ($bad ? "失敗 {$bad} 項" : "全綠：{$ok} 項通過") . "\n";
exit($bad ? 1 : 0);
