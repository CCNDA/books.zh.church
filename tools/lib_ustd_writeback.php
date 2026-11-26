<?php
declare(strict_types=1);
/**
 * 天道北美全量對照 → 書目網寫回：規則（純函式，不碰資料庫）
 * 設計：docs/ustiendao-writeback-design.md（小布 11/26 通過 2f64f70＋66a5cab 細節）；Asana 1220919560670331
 *
 * 輸入
 *   $report   crawler/ustiendao_recheck.py 的報告（counts、changes、stopped、limit、snapshot_size…）
 *   $editions 目前資料庫裡 source='ustiendao' 的 edition：
 *             [edition_id => ['url'=>source_url, 'isbn13'=>?string,
 *                             'formats'=>[format_id => ['price'=>?string,'currency'=>?string,'availability'=>?string]],
 *                             'links'=>[link_id => ['note'=>?string]]]]
 * 輸出（plan）
 *   ops：['type'=>'price'|'availability'|'link_note', ...]；review：要人看的；unmatched：對不到的；halt：停的原因或 null
 */

const USTD_GONE_MARK = '[天道北美已下架]';
const USTD_HALT_ABS = 50;      // 下架或改價超過這個數就停
const USTD_HALT_RATIO = 0.01;  // 「真的變了」任一類超過天道北美總數 1% 就停
const USTD_HALT_FILL_RATIO = 0.20;       // 第一次讀到有沒有貨（舊值 None）超過 20% 才停（小布 11/26）
const USTD_HALT_FILL_SAME_MIN = 500;     // 第一次讀到超過 500 本……
const USTD_HALT_FILL_SAME_SHARE = 0.95;  // ……而且 95% 以上同一個值也停（爬蟲讀壞）

/** 網址最後一段 .html 前面那串（http／https、www、尾端參數不影響） */
function ustd_slug(?string $url): ?string
{
    if ($url === null || $url === '') return null;
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path)) return null;
    return preg_match('#/([^/]+)\.html?$#i', $path, $m) ? $m[1] : null;
}

/** 商品號 → edition_id 清單 */
function ustd_index(array $editions): array
{
    $idx = [];
    foreach ($editions as $eid => $e) {
        $s = ustd_slug($e['url'] ?? null);
        if ($s !== null) $idx[$s][] = (int) $eid;
    }
    return $idx;
}

function ustd_note_add(?string $note): string
{
    $note = (string) $note;
    return str_contains($note, USTD_GONE_MARK) ? $note : trim($note . ' ' . USTD_GONE_MARK);
}

function ustd_note_remove(?string $note): ?string
{
    $n = trim(str_replace(USTD_GONE_MARK, '', (string) $note));
    return $n === '' ? null : $n;
}

function ustd_plan(array $report, array $editions): array
{
    $plan = ['ops' => [], 'review' => [], 'unmatched' => [], 'halt' => null, 'counts' => []];
    $total = max(1, (int) ($report['snapshot_size'] ?? count($editions)));
    $counts = $report['counts'] ?? [];

    // 異常閘（設計第 7 條；小布 11/26 同意的算法）：從 changes 自己分類算，不用報告 counts（它把「第一次讀到」和「真的變了」混在一起）
    $n = ['gone' => 0, 'price' => 0, 'isbn_changed' => 0, 'av_change' => 0, 'av_fill' => 0];
    $fillVals = [];
    foreach ($report['changes'] ?? [] as $c) {
        $k = $c['kind'] ?? '';
        if (isset($n[$k])) $n[$k]++;
        if ($k === 'availability' && ($c['new'] ?? null) !== null) {
            if (($c['old'] ?? null) === null) { $n['av_fill']++; $fillVals[$c['new']] = ($fillVals[$c['new']] ?? 0) + 1; }
            elseif ($c['old'] !== $c['new']) $n['av_change']++;
        }
    }
    foreach (['gone', 'price'] as $k) {
        if ($n[$k] > USTD_HALT_ABS) { $plan['halt'] = "$k 有 {$n[$k]} 本，超過 " . USTD_HALT_ABS; return $plan; }
    }
    foreach (['gone', 'price', 'isbn_changed', 'av_change'] as $k) {   // 真的變了：超過總數 1% 就停
        if ($n[$k] > $total * USTD_HALT_RATIO) { $plan['halt'] = "$k 有 {$n[$k]} 本，超過總數 1%"; return $plan; }
    }
    if ($n['av_fill'] > $total * USTD_HALT_FILL_RATIO) { $plan['halt'] = "第一次讀到有沒有貨 {$n['av_fill']} 本，超過總數 20%"; return $plan; }
    if ($n['av_fill'] > USTD_HALT_FILL_SAME_MIN && max($fillVals) / $n['av_fill'] >= USTD_HALT_FILL_SAME_SHARE) {   // 爬蟲讀壞常是每頁同一個值
        $plan['halt'] = "第一次讀到有沒有貨 {$n['av_fill']} 本、95% 以上同一個值，像爬蟲讀壞"; return $plan;
    }
    $plan['gate'] = $n;
    if (!empty($report['stopped'])) { $plan['halt'] = '對照中途停了：' . $report['stopped']; return $plan; }

    $idx = ustd_index($editions);
    $goneEids = [];
    foreach ($report['changes'] ?? [] as $c) {
        $slug = ustd_slug($c['url'] ?? null) ?? ($c['product_id'] ?? null);
        $eids = $slug !== null ? ($idx[$slug] ?? []) : [];
        if (!$eids) { $plan['unmatched'][] = $c; continue; }
        if (count($eids) > 1) { $plan['review'][] = $c + ['why' => '同一商品號對到 ' . count($eids) . ' 個 edition']; continue; }
        $eid = $eids[0];
        $e = $editions[$eid];
        $kind = $c['kind'] ?? '';
        if ($kind === 'isbn_changed') { $plan['review'][] = $c + ['why' => '換成別本', 'edition_id' => $eid]; continue; }
        if (!empty($c['isbn']) && !empty($e['isbn13']) && $c['isbn'] !== $e['isbn13']) {
            $plan['review'][] = $c + ['why' => "ISBN 不符（庫 {$e['isbn13']}）", 'edition_id' => $eid]; continue;
        }
        switch ($kind) {
            case 'price':
                foreach ($e['formats'] as $fid => $f) {
                    if (($f['currency'] ?? null) !== 'USD') { $plan['review'][] = $c + ['why' => '幣別不是 USD：' . ($f['currency'] ?? 'NULL'), 'edition_id' => $eid]; continue; }
                    $new = number_format((float) $c['new'], 2, '.', '');
                    if ((string) $f['price'] !== $new) $plan['ops'][] = ['type' => 'price', 'format_id' => $fid, 'edition_id' => $eid, 'old' => $f['price'], 'new' => $new];
                }
                break;
            case 'availability':
                if (($c['new'] ?? null) === null) break;  // 書頁沒標＝不明，不動
                foreach ($e['formats'] as $fid => $f) {
                    if (($f['availability'] ?? null) !== $c['new']) $plan['ops'][] = ['type' => 'availability', 'format_id' => $fid, 'edition_id' => $eid, 'old' => $f['availability'] ?? null, 'new' => $c['new']];
                }
                break;
            case 'gone':
                $goneEids[$eid] = true;
                foreach ($e['formats'] as $fid => $f) {
                    if (($f['availability'] ?? null) !== 'Discontinued') $plan['ops'][] = ['type' => 'availability', 'format_id' => $fid, 'edition_id' => $eid, 'old' => $f['availability'] ?? null, 'new' => 'Discontinued'];
                }
                foreach ($e['links'] as $lid => $l) {
                    $n = ustd_note_add($l['note'] ?? null);
                    if ($n !== (string) ($l['note'] ?? '')) $plan['ops'][] = ['type' => 'link_note', 'link_id' => $lid, 'edition_id' => $eid, 'old' => $l['note'] ?? null, 'new' => $n];
                }
                break;
            default:   // availability_unknown／unparsable／error：只記錄（重新上架另外處理）
                break;
        }
    }

    // 重新上架（設計第 4 條）：只有「全量對照、沒中途停」才能這樣推論——不在這次下架名單裡的，就是找得到
    if ((int) ($report['limit'] ?? -1) === 0) {
        $touchedF = [];  // 這次報告已經給新值的格式，不要再清成 NULL（避免同一列兩個操作）
        foreach ($plan['ops'] as $o) if ($o['type'] === 'availability') $touchedF[$o['format_id']] = true;
        foreach ($editions as $eid => $e) {
            if (isset($goneEids[$eid])) continue;
            foreach ($e['formats'] as $fid => $f) {
                if (isset($touchedF[$fid])) continue;
                if (($f['availability'] ?? null) === 'Discontinued') $plan['ops'][] = ['type' => 'availability', 'format_id' => $fid, 'edition_id' => (int) $eid, 'old' => 'Discontinued', 'new' => null];
            }
            foreach ($e['links'] as $lid => $l) {
                if (str_contains((string) ($l['note'] ?? ''), USTD_GONE_MARK)) $plan['ops'][] = ['type' => 'link_note', 'link_id' => $lid, 'edition_id' => (int) $eid, 'old' => $l['note'], 'new' => ustd_note_remove($l['note'])];
            }
        }
    }
    foreach ($plan['ops'] as $o) $plan['counts'][$o['type']] = ($plan['counts'][$o['type']] ?? 0) + 1;
    return $plan;
}
