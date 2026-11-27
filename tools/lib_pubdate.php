<?php
declare(strict_types=1);

/**
 * 出版日期解析（tools/import.php 用；2026-11-27 小芯從 import.php 的 parse_date() 抽出並加防呆，Asana 1220948673053089）
 *
 * 熊叔叔 11/27 TG「甲 照建議兩批都修」。正式庫查到兩批壞資料：
 *   ① 民國年月被當西元：衛理、天恩、格子外面、橄欖等書房給「930501」「0111-04-26」這類民國日期，
 *      舊版只取前 4 碼當年份 → 9305 年、0111 年。
 *   ② 假日期：校園等書房空白日期被填成 1899-12-30（Excel 起點）、1900-01-01、0000-00-00。
 * 規則：
 *   - 先把非數字刪掉（舊行為，各爬蟲的輸出都依賴它）
 *   - 假日期（18991230、19000101、00000000 開頭）→ 沒有日期
 *   - 前 4 碼是合理西元年（1500～明年）→ 照舊
 *   - 民國：2 碼年（40～99，後接合法月份）或 3～4 碼年（以 0／1 開頭，數值 40～今年民國），換成西元
 *   - 都不是（例如 2108 這種未來年份、0008 這種太小的年份）→ 沒有日期，寧可空白也不要錯
 * 回傳 [書用 YYYY-MM 或 YYYY, 版本用完整]，跟舊版一樣。
 */

const PUBDATE_PLACEHOLDERS = ['18991230', '19000101', '00000000'];

function pubdate_fmt(int $y, ?string $mm, ?string $dd): array
{
    $m = ($mm !== null && $mm !== '' && (int) $mm >= 1 && (int) $mm <= 12) ? sprintf('%02d', (int) $mm) : null;
    $d = ($m !== null && $dd !== null && $dd !== '' && (int) $dd >= 1 && (int) $dd <= 31) ? sprintf('%02d', (int) $dd) : null;
    $ys = sprintf('%04d', $y);
    if ($m === null) {
        return [$ys, $ys];
    }
    return [$ys . '-' . $m, $ys . '-' . $m . ($d !== null ? '-' . $d : '')];
}

function parse_date(?string $s): array
{
    if (!$s) return [null, null];
    $d = preg_replace('/[^0-9]/', '', $s);
    if (strlen($d) < 4) return [null, null];
    foreach (PUBDATE_PLACEHOLDERS as $ph) {
        if (str_starts_with($d, substr($ph, 0, min(strlen($d), 8))) && strlen($d) >= 6) return [null, null];
    }
    $maxYear = (int) date('Y') + 1;
    $rocMax  = (int) date('Y') - 1911;
    $y4 = (int) substr($d, 0, 4);

    // 合理西元年：照舊行為切（YYYYMMDD／YYYYMM／YYYY）
    if ($y4 >= 1500 && $y4 <= $maxYear) {
        return pubdate_fmt($y4, substr($d, 4, 2) ?: null, substr($d, 6, 2) ?: null);
    }
    // 民國 3～4 碼年（0111…、1100801…）：前 3 碼或去掉前導 0 的前 4 碼
    if ($d[0] === '0' && strlen($d) >= 6) {
        $roc = (int) substr($d, 0, 4);
        if ($roc >= 40 && $roc <= $rocMax) {   // 0008 這種當民國 8 年（1919）多半是錯的，不收
            return pubdate_fmt($roc + 1911, substr($d, 4, 2), substr($d, 6, 2) ?: null);
        }
    }
    if ($d[0] === '1' && strlen($d) >= 5) {
        $roc = (int) substr($d, 0, 3);
        if ($roc >= 100 && $roc <= $rocMax && (int) substr($d, 3, 2) >= 1 && (int) substr($d, 3, 2) <= 12) {
            return pubdate_fmt($roc + 1911, substr($d, 3, 2), substr($d, 5, 2) ?: null);
        }
    }
    // 民國 2 碼年（930501、9305…）：40～99 年、後面兩碼是合法月份
    $roc2 = (int) substr($d, 0, 2);
    $m2 = (int) substr($d, 2, 2);
    if ($roc2 >= 40 && $roc2 <= 99 && $m2 >= 1 && $m2 <= 12) {
        return pubdate_fmt($roc2 + 1911, substr($d, 2, 2), substr($d, 4, 2) ?: null);
    }
    return [null, null];
}
