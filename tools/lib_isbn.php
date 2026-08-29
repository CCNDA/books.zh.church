<?php
declare(strict_types=1);

/**
 * ISBN 正規化共用函式。
 *
 * 與 tools/import.php 內同名函式為同一份實作;2026-08-29 新增 backfill_logos_fields.php 時
 * 抽出成共用檔,避免兩處各寫一份而走鐘。import.php 尚未改用本檔(不在該次工單範圍),
 * 日後重構時再統一 require 此檔並刪除 import.php 內的副本。
 * 故此處以 function_exists 包起來,兩邊同時載入也不會撞名。
 */

if (!function_exists('norm_isbn')) {
    /** 去除連字號與空白;只認 ISBN13(978/979 開頭)與 ISBN10,其餘回 null */
    function norm_isbn(?string $s): ?string
    {
        if (!$s) return null;
        $s = strtoupper(preg_replace('/[^0-9Xx]/', '', $s));
        if (preg_match('/^(97[89]\d{10})$/', $s)) return $s;   // ISBN13
        if (preg_match('/^\d{9}[\dX]$/', $s)) return $s;       // ISBN10
        return null;
    }
}

if (!function_exists('isbn10_to_13')) {
    function isbn10_to_13(string $isbn10): string
    {
        $core = '978' . substr($isbn10, 0, 9);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $core[$i] * ($i % 2 ? 3 : 1);
        }
        return $core . ((10 - $sum % 10) % 10);
    }
}

if (!function_exists('isbn_pair')) {
    /** 任意 ISBN → [isbn13, isbn10](缺者為 null) */
    function isbn_pair(?string $raw): array
    {
        $n = norm_isbn($raw);
        if (!$n) return [null, null];
        return strlen($n) === 13 ? [$n, null] : [isbn10_to_13($n), $n];
    }
}
