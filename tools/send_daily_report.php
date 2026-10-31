<?php
declare(strict_types=1);

/**
 * 每日新品摘要信(Asana 1217767336780488,母票 1217767344711740)
 *
 * 讀 daily_new.sh 寫的 crawler/logs/summary-YYYYMMDD.tsv 與 last_run.json / last_ok.json,
 * 加上正式庫的全站累計,組成摘要信(HTML + 純文字),經 SES SMTP 寄出。
 * 失效偵測與【警報】信是下一張票(4.),本工具只寄「每天固定一封」的摘要。
 *
 * 設定(憑證不進 git):config/app.local.php
 *   'aws' => [ 'ses_from' => ..., 'ses_from_name' => ..., 'smtp' => [host, port, encryption, username, password] ],
 *   'daily_report' => [ 'to' => ['收件人@example.org'] ],   // 沒設就不寄(不猜收件人)
 *
 * 用法(主機 CLI):
 *   php tools/send_daily_report.php --dry-run                  # 只印出信件內容(純文字＋HTML),不連 SMTP
 *   php tools/send_daily_report.php --to=someone@ccnda.org     # 測試模式:只寄給這個位址,主旨加〔測試〕
 *   php tools/send_daily_report.php                            # 寄給 daily_report.to
 *   php tools/send_daily_report.php --date=20261030            # 指定哪一天的摘要(預設今天)
 *   php tools/send_daily_report.php --log-dir=/path/to/logs    # 換讀摘要的資料夾(預設 crawler/logs,測試用)
 *
 * 注意:件數是「偵測」數(書房新品頁上看到的),還沒分新增／合併／封面轉存——summary TSV 目前只記這個。
 *
 * 寄信失敗:記到 crawler/logs/mail-YYYYMMDD.log,等 30 秒重試一次;兩次都失敗回傳 1。
 * log 與錯誤訊息只寫 SMTP 伺服器的回應碼與文字,不寫帳號密碼。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
require $root . '/app/lib/book_detail.php';   // db()、app_config()、BUY_PLATFORM_LABELS
require $root . '/tools/lib_mail.php';

$opts   = getopt('', ['dry-run', 'to:', 'date:', 'log-dir:']);
$dry    = isset($opts['dry-run']);
$testTo = isset($opts['to']) ? trim((string) $opts['to']) : '';
$date   = isset($opts['date']) ? (string) $opts['date'] : date('Ymd');
$logDir = isset($opts['log-dir']) ? rtrim((string) $opts['log-dir'], '/') : $root . '/crawler/logs';

if (! preg_match('/^\d{8}$/', $date)) {
    fwrite(STDERR, "--date 要寫成 YYYYMMDD\n");
    exit(2);
}
if ($testTo !== '' && ! filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "--to 不是有效的 email\n");
    exit(2);
}

$mailLog = $logDir . "/mail-$date.log";
function mail_log(string $msg): void
{
    global $mailLog;
    @file_put_contents($mailLog, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

// ── 讀摘要 ───────────────────────────────────────────────
$summaryFile = "$logDir/summary-$date.tsv";
$rows = [];
$warnings = [];
if (is_readable($summaryFile)) {
    $fh = fopen($summaryFile, 'r');
    fgetcsv($fh, 0, "\t", '"', '');                 // 表頭
    while (($r = fgetcsv($fh, 0, "\t", '"', '')) !== false) {
        if (count($r) < 5) {
            continue;
        }
        $rows[] = ['time' => $r[0], 'step' => $r[1], 'status' => $r[2],
                   'count' => (int) $r[3], 'sec' => (int) $r[4], 'note' => $r[5] ?? ''];
    }
    fclose($fh);
} else {
    $warnings[] = "找不到當天的摘要檔 summary-$date.tsv(排程可能沒跑)";
}

$readJson = static function (string $f): ?array {
    if (! is_readable($f)) {
        return null;
    }
    $j = json_decode((string) file_get_contents($f), true);
    return is_array($j) ? $j : null;
};
$lastRun = $readJson("$logDir/last_run.json");
$lastOk  = $readJson("$logDir/last_ok.json");

$crawl = [];
$other = [];
foreach ($rows as $r) {
    if (str_starts_with($r['step'], 'crawl:')) {
        $crawl[] = $r;
    } else {
        $other[] = $r;
    }
    if ($r['status'] === 'fail' || $r['status'] === 'skip') {
        $warnings[] = $r['step'] . ' ' . ($r['status'] === 'fail' ? '失敗' : '略過') . ($r['note'] !== '' ? ':' . $r['note'] : '');
    }
}
// 摘要只有表頭(例如磁碟滿、步驟都沒寫進去):不能當成「今天沒新書」(v1.19.2 複測記的邊角)
$incomplete = ! is_readable($summaryFile) || $rows === [];
if (is_readable($summaryFile) && $rows === []) {
    $warnings[] = '摘要檔只有表頭,沒有任何步驟紀錄';
}
// 來源整行沒寫進去時信裡會少一行、看不出來:跟前一份摘要比,前一天有、今天沒有的來源列進注意
$prevFiles = array_filter(glob("$logDir/summary-*.tsv") ?: [], static fn ($f) => basename($f) < "summary-$date.tsv");
if ($prevFiles !== []) {
    sort($prevFiles);
    $prevSrc = [];
    foreach (file(end($prevFiles), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $step = explode("\t", $line)[1] ?? '';
        if (str_starts_with($step, 'crawl:')) {
            $prevSrc[$step] = true;
        }
    }
    foreach (array_keys(array_diff_key($prevSrc, array_flip(array_column($crawl, 'step')))) as $step) {
        $warnings[] = $step . ' 今天沒有紀錄(' . basename(end($prevFiles)) . ' 有)';
    }
}

$detected = array_sum(array_map(static fn ($r) => $r['status'] === 'ok' ? $r['count'] : 0, $crawl));
$fails    = count(array_filter($rows, static fn ($r) => $r['status'] === 'fail'));
$elapsed  = '';
if ($rows !== []) {
    $t0 = strtotime($rows[0]['time']) - $rows[0]['sec'];
    $t1 = strtotime(end($rows)['time']);
    if ($t0 && $t1 && $t1 >= $t0) {
        $elapsed = sprintf('%d 分 %02d 秒', intdiv($t1 - $t0, 60), ($t1 - $t0) % 60);
    }
}

// ── 全站累計(查詢失敗不擋寄信,寫進警告)─────────────────
$stats = null;
try {
    $stats = db()->query(
        'SELECT COUNT(*) AS total, SUM(is_published = 1) AS published, '
        . 'SUM(is_published = 1 AND category_id IS NULL) AS no_category FROM books'
    )->fetch();
} catch (Throwable $e) {
    $warnings[] = '查不到全站累計(資料庫連線失敗)';
}

// ── 組信 ─────────────────────────────────────────────────
$statusLabel = ['ok' => '完成', 'none' => '無新書', 'fail' => '失敗', 'skip' => '略過'];
// btproduct（突破機構）不在 BUY_PLATFORM_LABELS（沒有購書連結），名稱同 public/api/index.php 的 SOURCE_NAME_OVERRIDES
const REPORT_SOURCE_NAMES = ['btproduct' => '突破機構'];
$label = static fn (string $src) => BUY_PLATFORM_LABELS[$src] ?? REPORT_SOURCE_NAMES[$src] ?? $src;
$day = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);

// last_run.json 只留最新一份;補寄別天(--date)時它的 log 是別天的,改用當天的檔名
$logName = "new-arrivals-$date.log";
if (isset($lastRun['log']) && str_ends_with((string) $lastRun['log'], "-$date.log")) {
    $logName = (string) $lastRun['log'];
}

$subject = $incomplete
    ? "[書目網] 每日新品 $day:摘要不完整,請查 log"
    : "[書目網] 每日新品 $day:偵測 $detected 本" . ($fails > 0 ? ",失敗 $fails 步" : '');
if ($testTo !== '') {
    $subject = '〔測試〕' . $subject;
}

$text = "書目網每日新品摘要 $day\n\n";
$text .= "各來源(偵測件數為書房新品頁上看到的數量,匯入時會和站上既有的書合併)\n";
foreach ($crawl as $r) {
    $src = substr($r['step'], 6);
    $text .= sprintf("  %s:%s %d 本(%d 秒)%s\n", $label($src), $statusLabel[$r['status']] ?? $r['status'],
        $r['count'], $r['sec'], $r['note'] !== '' ? ' ' . $r['note'] : '');
}
if ($crawl === []) {
    $text .= "  (沒有任何來源的紀錄)\n";
}
$text .= "\n其他步驟\n";
foreach ($other as $r) {
    $text .= sprintf("  %s:%s(%d 秒)%s\n", $r['step'], $statusLabel[$r['status']] ?? $r['status'], $r['sec'],
        $r['note'] !== '' ? ' ' . $r['note'] : '');
}
if ($stats) {
    $text .= sprintf("\n全站累計:書目 %d 本、線上上架 %d 本、上架但沒有分類 %d 本\n",
        $stats['total'], $stats['published'], $stats['no_category']);
}
$text .= "\n執行耗時:" . ($elapsed !== '' ? $elapsed : '不明') . "\n";
$text .= "log 檔:$logName\n";
$text .= '上次全部成功:' . ($lastOk['finished_at'] ?? '沒有紀錄') . "\n";
if ($warnings !== []) {
    $text .= "\n注意\n";
    foreach ($warnings as $w) {
        $text .= "  ・$w\n";
    }
}

$h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
// HTML 版面（熊哥 2026-11-01「可以美觀一點嗎」）：信件用 table＋inline style，Gmail／Outlook 都吃；不用 emoji
$dur = static fn (int $s) => $s >= 60 ? sprintf('%d 分 %02d 秒', intdiv($s, 60), $s % 60) : "$s 秒";
$tw  = static function (?string $iso): string {
    if (! $iso || ($t = strtotime($iso)) === false) {
        return '沒有紀錄';
    }
    return (new DateTime('@' . $t))->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Y-m-d H:i');
};
$badge = static fn (string $text, string $fg, string $bg) =>
    '<span style="display:inline-block;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:bold;color:' . $fg . ';background:' . $bg . '">' . $text . '</span>';
$font = "font-family:-apple-system,'Segoe UI','Noto Sans TC','Microsoft JhengHei',sans-serif";

if ($incomplete) {
    $state = $badge('摘要不完整', '#ffffff', '#b00020');
} elseif ($fails > 0) {
    $state = $badge("$fails 個步驟失敗", '#ffffff', '#b00020');
} else {
    $state = $badge('全部完成', '#1b5e20', '#c8e6c9');
}

$html = '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
      . '<title>' . $h($subject) . '</title></head>'
      . '<body style="margin:0;padding:0;background:#f2f4f7;' . $font . ';color:#1f2933">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7"><tr><td align="center" style="padding:24px 12px">'
      . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e3e7ec">'
      // 標頭
      . '<tr><td style="background:#1f4e5f;padding:20px 24px;color:#ffffff">'
      . '<div style="font-size:13px;opacity:.85">書目網 books.zh.church</div>'
      . '<div style="font-size:22px;font-weight:bold;margin:4px 0 8px">每日新品摘要 ' . $h($day) . '</div>'
      . $state . '</td></tr>';

// 注意
if ($warnings !== []) {
    $html .= '<tr><td style="padding:16px 24px 0"><div style="border-left:4px solid #b00020;background:#fdecee;padding:10px 14px;border-radius:4px">'
           . '<div style="font-weight:bold;color:#b00020;margin-bottom:4px">注意</div>';
    foreach ($warnings as $w) {
        // 「crawl:logos 失敗…」在信裡改成來源中文名（純文字版維持原樣，方便對 log）
        $w = preg_replace_callback('/^crawl:([a-z0-9_]+)/', static fn ($m) => $label($m[1]), $w);
        $html .= '<div style="font-size:14px">・' . $h($w) . '</div>';
    }
    $html .= '</div></td></tr>';
}

// 數字卡
$kpi = static function (string $title, string $value, string $color = '#1f2933', string $sub = '') use ($h): string {
    return '<td width="50%" style="padding:6px"><div style="background:#f7f9fb;border:1px solid #e3e7ec;border-radius:8px;padding:12px 8px;text-align:center">'
         . '<div style="font-size:12px;color:#5f6b7a">' . $h($title) . '</div>'
         . '<div style="font-size:24px;font-weight:bold;color:' . $color . ';margin-top:2px">' . $h($value) . '</div>'
         . ($sub !== '' ? '<div style="font-size:11px;color:#8a96a3">' . $h($sub) . '</div>' : '')
         . '</div></td>';
};
// 2×2 排：手機上一排四張會把標題擠成三行（熊哥 2026-11-01 手機截圖）
$html .= '<tr><td style="padding:16px 18px 4px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed"><tr>'
       . $kpi('今日新書', $incomplete ? '?' : (string) $detected, '#1f4e5f', '本')
       . $kpi('上架未分類', $stats ? (string) (int) $stats['no_category'] : '?',
              ($stats && (int) $stats['no_category'] > 0) ? '#c25e00' : '#1b5e20', '本')
       . '</tr><tr>'
       . $kpi('書目總數', $stats ? number_format((int) $stats['total']) : '?', '#1f2933', '本')
       . $kpi('線上上架', $stats ? number_format((int) $stats['published']) : '?', '#1f2933', '本')
       . '</tr></table></td></tr>';

// 各來源：有新書的在上面、失敗／略過其次、沒新書的灰色放最後（每個來源都列，不漏行）
$rank = static fn ($r) => $r['status'] === 'ok' && $r['count'] > 0 ? 0 : (in_array($r['status'], ['fail', 'skip'], true) ? 1 : 2);
$sorted = $crawl;
usort($sorted, static fn ($a, $b) => $rank($a) <=> $rank($b) ?: $b['count'] <=> $a['count']);
$cell = 'padding:9px 10px;border-bottom:1px solid #eef1f4;font-size:14px';
$html .= '<tr><td style="padding:12px 24px 0"><div style="font-size:16px;font-weight:bold;margin-bottom:6px">各來源</div>'
       . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">';
foreach ($sorted as $r) {
    $name = $h($label(substr($r['step'], 6)));
    $muted = $rank($r) === 2;
    if ($r['status'] === 'ok' && $r['count'] > 0) {
        $st = $badge($r['count'] . ' 本', '#1b5e20', '#c8e6c9');
    } elseif ($r['status'] === 'fail') {
        $st = $badge('失敗', '#ffffff', '#b00020');
    } elseif ($r['status'] === 'skip') {
        $st = $badge('略過', '#7a4f01', '#ffe8b3');
    } else {
        $st = '<span style="font-size:13px;color:#9aa5b1">' . $h($statusLabel[$r['status']] ?? $r['status']) . '</span>';
    }
    $html .= '<tr><td style="' . $cell . ';color:' . ($muted ? '#9aa5b1' : '#1f2933') . ';' . ($muted ? '' : 'font-weight:bold') . '">' . $name
           . ($r['note'] !== '' ? '<div style="font-size:12px;font-weight:normal;color:#b00020">' . $h($r['note']) . '</div>' : '') . '</td>'
           . '<td style="' . $cell . '" align="right">' . $st . '</td>'
           . '<td style="' . $cell . ';color:#9aa5b1;font-size:12px;white-space:nowrap" align="right" width="80">' . $h($dur((int) $r['sec'])) . '</td></tr>';
}
if ($crawl === []) {
    $html .= '<tr><td style="' . $cell . ';color:#b00020">沒有任何來源的紀錄</td></tr>';
}
$html .= '</table><div style="font-size:12px;color:#8a96a3;margin-top:6px">偵測件數是書房新品頁上看到的數量，匯入時會和站上既有的書合併。</div></td></tr>';

// 其他步驟：全部完成就收成一行；有失敗／略過的逐條列出
if ($other !== []) {
    $bad = array_filter($other, static fn ($r) => in_array($r['status'], ['fail', 'skip'], true));
    $html .= '<tr><td style="padding:16px 24px 0"><div style="font-size:16px;font-weight:bold;margin-bottom:6px">其他步驟</div>';
    if ($bad === []) {
        $html .= '<div style="font-size:14px">' . $badge('全部完成', '#1b5e20', '#c8e6c9') . ' 共 ' . count($other) . ' 個步驟</div>';
    } else {
        foreach ($bad as $r) {
            $html .= '<div style="font-size:14px;margin:2px 0">' . ($r['status'] === 'fail' ? $badge('失敗', '#ffffff', '#b00020') : $badge('略過', '#7a4f01', '#ffe8b3'))
                   . ' ' . $h($r['step']) . ($r['note'] !== '' ? '：' . $h($r['note']) : '') . '</div>';
        }
        $html .= '<div style="font-size:13px;color:#5f6b7a;margin-top:4px">其餘 ' . (count($other) - count($bad)) . ' 個步驟完成</div>';
    }
    $html .= '<div style="font-size:12px;color:#9aa5b1;margin-top:6px;line-height:1.6">'
           . $h(implode('、', array_map(static fn ($r) => $r['step'], $other))) . '</div></td></tr>';
}

// 頁尾
$html .= '<tr><td style="padding:18px 24px 22px"><div style="border-top:1px solid #eef1f4;padding-top:12px;font-size:13px;color:#5f6b7a;line-height:1.8">'
       . '執行耗時：' . $h($elapsed !== '' ? $elapsed : '不明') . '<br>'
       . '上次全部成功：' . $h($tw($lastOk['finished_at'] ?? null)) . '（台北時間）<br>'
       // 只放檔名＋可斷行：整條路徑沒有斷點，手機上會把整封信撐寬（11/1 手機截圖）
       . 'log 檔：<span style="font-family:Consolas,Menlo,monospace;font-size:12px;word-break:break-all">' . $h(basename($logName)) . '</span>'
       . '</div></td></tr>'
       . '</table></td></tr></table></body></html>';

if ($dry) {
    echo "Subject: $subject\n\n$text\n----- HTML -----\n$html\n";
    exit(0);
}

// ── 寄出(寄法在 tools/lib_mail.php,與警報信共用)───────────────
$cfg = app_config();
$aws = $cfg['aws'] ?? [];
$to  = $testTo !== '' ? [$testTo] : array_values(array_filter((array) ($cfg['daily_report']['to'] ?? [])));
if (($err = mail_check($aws, $to)) !== null) {
    fwrite(STDERR, "$err,不寄(daily_report.to／aws;要測試請用 --to=)\n");
    mail_log("$err,未寄");
    exit(2);
}
if (mail_send($aws, $to, $subject, $text, $html, 'mail_log')) {
    echo '寄出 → ' . implode(', ', $to) . "\n";
    exit(0);
}
exit(1);
