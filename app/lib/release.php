<?php
declare(strict_types=1);

/**
 * 版本與更新紀錄(footer 版號、public/changelog.php、GET /api/version 共用)。
 *
 * 版號只有一個來源:repo 根目錄的 VERSION。footer 原本把「v1.15」寫死在 HTML,
 * 發版時要人工同步,於是 VERSION 已是 1.15.1、footer 還停在 1.15(title 寫 1.15.0)。
 *
 * 更新紀錄的版本清單來自 CHANGELOG.md 的「## [x.y.z] - YYYY-MM-DD —— 標題」:
 * 每一版都有這一行,release-notes/ 只有部分版本有讀者版說明(有就附上)。
 */

function release_root(): string
{
    return dirname(__DIR__, 2);
}

function app_version(): string
{
    $v = @file_get_contents(release_root() . '/VERSION');
    return $v === false ? '' : trim($v);
}

/**
 * @return list<array{version: string, date: ?string, title: string, note: ?string}> 新到舊
 */
function release_list(): array
{
    $md = @file_get_contents(release_root() . '/CHANGELOG.md');
    if ($md === false) {
        return [];
    }
    preg_match_all('/^## \[(\d+\.\d+\.\d+)\][ \t]*(?:-[ \t]*(\d{4}-\d{2}-\d{2}))?[ \t]*(?:——|--)?[ \t]*(.*)$/mu',
        $md, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $r) {
        $note = @file_get_contents(release_root() . '/release-notes/v' . $r[1] . '.md');
        $out[] = [
            'version' => $r[1],
            'date'    => ($r[2] ?? '') !== '' ? $r[2] : null,
            'title'   => trim($r[3] ?? ''),
            'note'    => $note === false ? null : $note,
        ];
    }
    usort($out, static fn(array $a, array $b): int => version_compare($b['version'], $a['version']));
    return $out;
}

/**
 * release-notes 用到的 markdown 只有 # ／## 標題、- 清單、**粗體** 與段落(2026-10-11 盤點,
 * 沒有表格、連結、圖片、程式碼),只轉這幾種;其餘一律先 HTML escape,不輸出任何原始 HTML。
 * 第一個 # 標題是該版的大標,頁面另外顯示,這裡略過。
 */
function release_note_html(string $md): string
{
    $inline = static function (string $s): string {
        $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s) ?? $s;
    };
    $html = '';
    $para = [];
    $inList = false;
    $skippedTitle = false;
    $flush = static function () use (&$para, &$html, $inline): void {
        if ($para) {
            $html .= '<p>' . $inline(implode('', $para)) . "</p>\n";
            $para = [];
        }
    };
    foreach (preg_split('/\R/u', $md) ?: [] as $line) {
        $t = rtrim($line);
        if (preg_match('/^#\s+/u', $t) && !$skippedTitle) {
            $skippedTitle = true;
            continue;
        }
        if (preg_match('/^\s*[-*]\s+(.*)$/u', $t, $mm)) {
            $flush();
            if (!$inList) {
                $html .= "<ul>\n";
                $inList = true;
            }
            $html .= '<li>' . $inline($mm[1]) . "</li>\n";
            continue;
        }
        if ($inList) {
            $html .= "</ul>\n";
            $inList = false;
        }
        if (preg_match('/^(#{1,4})\s+(.*)$/u', $t, $mm)) {
            $flush();
            // changelog.php 每一版的標題是 h2，說明裡從 h3 開始（標題層級不能跳，WCAG 1.3.1）
            $lv = min(4, strlen($mm[1]) + 1); // ## → h3、### → h4（第一個 # 是標題，上面已略過）
            $html .= "<h{$lv}>" . $inline($mm[2]) . "</h{$lv}>\n";
            continue;
        }
        if (trim($t) === '') {
            $flush();
            continue;
        }
        $para[] = trim($t);
    }
    if ($inList) {
        $html .= "</ul>\n";
    }
    $flush();
    return $html;
}
