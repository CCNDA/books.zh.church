<?php
declare(strict_types=1);
/**
 * books.language 原始寫法 → 語言分組（M1-B 語言篩選；Asana 1220724721485985）
 * 熊叔叔 2026-11-20 TG 選「甲 先做語言篩選（統一寫法），庫存不做」。
 *
 * 原始欄位是各書房爬來的自由文字（繁體中文／繁體／zh-Hant／簡體中文 日期：2014年4月／中英對照／nan／書名誤植…），
 * 不改原始值，只另外算一個分組，存在 book_lang 小表（tools/build_book_lang.php 維護）。
 *
 * 分組：
 *   zh-Hant 繁體中文｜zh-Hans 簡體中文｜en 英文｜multi 多語對照｜other 其他語言｜null 未填（含雜訊）
 * 規則：
 *   - 繁／zh-Hant／Traditional → 繁；簡／简 → 簡；英／English／en／NASB → 英；台語、客語、原住民語、希臘、希伯來、日韓越泰… → 其他
 *   - 命中兩類以上 → 多語；只寫「中文／華語／國語」但另有英文或其他語言 → 多語
 *   - 只寫「中文／華語／國語／普通話」、沒註明繁簡 → 繁（開發庫 146 本，來源都是道聲、以琳、校園、邏各斯、天道等台港書房）
 *   - 注音、拼音、日期、規格、出版地這類附註不影響分類
 *   - nan／null／map、純數字、書名誤植（都不含上述字眼）→ 未填
 */
const LANG_GROUPS = ['zh-Hant' => '繁體中文', 'zh-Hans' => '簡體中文', 'en' => '英文', 'multi' => '多語對照', 'other' => '其他語言'];

function lang_group(?string $raw): ?string
{
    if ($raw === null) return null;
    $v = trim($raw);
    if ($v === '' || in_array(strtolower($v), ['nan', 'null', 'map', 'none', 'n/a'], true)) return null;
    $hant  = preg_match('/繁|zh-Hant|Traditional/iu', $v) === 1;
    $hans  = preg_match('/簡|简|zh-Hans|Simplified/iu', $v) === 1;
    $en    = preg_match('/英|English|^en$|NASB/iu', $v) === 1;
    $other = preg_match('/台語|臺語|粵語|客語|^hak$|日文|日語|韓文|韓語|希臘|希伯來|拉丁|越南|泰文|泰語|西班牙|德文|法文|俄文|印尼|緬甸|義大利|阿拉伯|阿美|布農|卑南|達悟|泰雅|德固達雅|排灣|魯凱|賽夏|鄒語|白話字|羅馬字|漢羅/u', $v) === 1;
    $zh    = preg_match('/中文|中英|中\(簡\)|中\/|華語|國語|普通話|^中/u', $v) === 1;
    $n = (int) $hant + (int) $hans + (int) $en + (int) $other;
    if ($n >= 2 || (($en || $other) && $zh && !$hant && !$hans)) return 'multi';
    if ($hant) return 'zh-Hant';
    if ($hans) return 'zh-Hans';
    if ($en) return 'en';
    if ($other) return 'other';
    if ($zh) return 'zh-Hant';
    return null;
}
