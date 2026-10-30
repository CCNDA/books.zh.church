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
$label = static fn (string $src) => BUY_PLATFORM_LABELS[$src] ?? $src;
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
$td = 'style="padding:4px 10px;border-bottom:1px solid #ddd"';
$html = '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><title>' . $h($subject) . '</title></head>'
      . '<body style="font-family:sans-serif;color:#222;line-height:1.5">'
      . '<h1 style="font-size:18px">書目網每日新品摘要 ' . $h($day) . '</h1>';
if ($warnings !== []) {
    $html .= '<div style="border-left:4px solid #b00020;padding:6px 12px;background:#fdecee"><strong>注意</strong><ul>';
    foreach ($warnings as $w) {
        $html .= '<li>' . $h($w) . '</li>';
    }
    $html .= '</ul></div>';
}
$html .= '<h2 style="font-size:16px">各來源</h2>'
       . '<table style="border-collapse:collapse"><caption style="text-align:left;color:#555">偵測件數是書房新品頁上看到的數量,匯入時會和站上既有的書合併</caption>'
       . '<thead><tr><th scope="col" ' . $td . '>來源</th><th scope="col" ' . $td . '>狀態</th>'
       . '<th scope="col" ' . $td . '>偵測</th><th scope="col" ' . $td . '>秒數</th><th scope="col" ' . $td . '>備註</th></tr></thead><tbody>';
foreach ($crawl as $r) {
    $html .= '<tr><td ' . $td . '>' . $h($label(substr($r['step'], 6))) . '</td><td ' . $td . '>'
           . $h($statusLabel[$r['status']] ?? $r['status']) . '</td><td ' . $td . ' align="right">' . $r['count']
           . '</td><td ' . $td . ' align="right">' . $r['sec'] . '</td><td ' . $td . '>' . $h($r['note']) . '</td></tr>';
}
if ($crawl === []) {
    $html .= '<tr><td ' . $td . ' colspan="5">(沒有任何來源的紀錄)</td></tr>';
}
$html .= '</tbody></table>';
if ($other !== []) {
    $html .= '<h2 style="font-size:16px">其他步驟</h2><ul>';
    foreach ($other as $r) {
        $html .= '<li>' . $h($r['step']) . ':' . $h($statusLabel[$r['status']] ?? $r['status'])
               . '(' . $r['sec'] . ' 秒)' . $h($r['note'] !== '' ? ' ' . $r['note'] : '') . '</li>';
    }
    $html .= '</ul>';
}
$html .= '<h2 style="font-size:16px">全站</h2><ul>';
if ($stats) {
    $html .= '<li>書目 ' . (int) $stats['total'] . ' 本、線上上架 ' . (int) $stats['published'] . ' 本</li>'
           . '<li>上架但沒有分類 ' . (int) $stats['no_category'] . ' 本</li>';
}
$html .= '<li>執行耗時:' . $h($elapsed !== '' ? $elapsed : '不明') . '</li>'
       . '<li>log 檔:' . $h($logName) . '</li>'
       . '<li>上次全部成功:' . $h($lastOk['finished_at'] ?? '沒有紀錄') . '</li></ul></body></html>';

if ($dry) {
    echo "Subject: $subject\n\n$text\n----- HTML -----\n$html\n";
    exit(0);
}

// ── 寄出 ─────────────────────────────────────────────────
$cfg  = app_config();
$aws  = $cfg['aws'] ?? [];
$smtp = $aws['smtp'] ?? [];
$to   = $testTo !== '' ? [$testTo] : array_values(array_filter((array) ($cfg['daily_report']['to'] ?? [])));
// 收件人會進 To: 與 RCPT TO:,不是有效 email(例如夾了換行)就不寄,免得插進 header 或 SMTP 指令
foreach ($to as $addr) {
    if (! filter_var($addr, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "daily_report.to 裡有不是 email 的值,不寄\n");
        mail_log('daily_report.to 裡有不是 email 的值,未寄');
        exit(2);
    }
}
if ($to === []) {
    fwrite(STDERR, "config 沒有 daily_report.to,不寄(要測試請用 --to=)\n");
    mail_log('沒有收件人(daily_report.to),未寄');
    exit(2);
}
foreach (['host', 'port', 'username', 'password'] as $k) {
    if (empty($smtp[$k])) {
        fwrite(STDERR, "config aws.smtp.$k 沒有設定\n");
        mail_log("aws.smtp.$k 沒有設定,未寄");
        exit(2);
    }
}
$from = (string) ($aws['ses_from'] ?? '');
if (! filter_var($from, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "config aws.ses_from 不是有效的 email\n");
    exit(2);
}

// RFC 2047:一個 encoded-word 最長 75 字元 → 每 12 個字一段(UTF-8 最多 48 bytes、base64 64 字元),段與段之間折行
$enc  = static fn (string $s) => implode("\r\n ", array_map(
    static fn ($c) => '=?UTF-8?B?' . base64_encode($c) . '?=', mb_str_split($s, 12, 'UTF-8')));
$host = substr(strrchr($from, '@'), 1);
$boundary = 'b' . bin2hex(random_bytes(12));
$headers = [
    'Date: ' . date('r'),
    'From: ' . (($aws['ses_from_name'] ?? '') !== '' ? $enc($aws['ses_from_name']) . ' ' : '') . "<$from>",
    'To: ' . implode(', ', $to),
    'Subject: ' . $enc($subject),
    'Message-ID: <' . date('YmdHis') . '.' . bin2hex(random_bytes(6)) . "@$host>",
    'MIME-Version: 1.0',
    "Content-Type: multipart/alternative; boundary=\"$boundary\"",
];
if (filter_var($aws['ses_reply_to'] ?? '', FILTER_VALIDATE_EMAIL)) {
    $headers[] = 'Reply-To: ' . $aws['ses_reply_to'];
}
$part = static fn (string $type, string $body) => "--$boundary\r\nContent-Type: $type; charset=UTF-8\r\n"
    . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
$message = implode("\r\n", $headers) . "\r\n\r\n"
         . $part('text/plain', $text) . $part('text/html', $html) . "--$boundary--\r\n";

for ($attempt = 1; $attempt <= 2; $attempt++) {
    try {
        smtp_send($smtp, $from, $to, $message);
        mail_log("寄出 → " . implode(', ', $to) . " 主旨:$subject");
        echo "寄出 → " . implode(', ', $to) . "\n";
        exit(0);
    } catch (Throwable $e) {
        mail_log("第 $attempt 次寄信失敗:" . $e->getMessage());
        fwrite(STDERR, "第 $attempt 次寄信失敗:" . $e->getMessage() . "\n");
        if ($attempt === 1) {
            sleep(30);
        }
    }
}
exit(1);

/**
 * 最小的 SMTP 用戶端(STARTTLS + AUTH LOGIN)。encryption=ssl 走 465 直接 TLS,其他走 STARTTLS。
 * 例外訊息只帶伺服器回應,不帶帳號密碼。
 */
function smtp_send(#[\SensitiveParameter] array $smtp, string $from, array $to, string $message): void
{
    $ssl = ($smtp['encryption'] ?? 'tls') === 'ssl';
    $fp = @stream_socket_client(($ssl ? 'tls://' : 'tcp://') . $smtp['host'] . ':' . (int) $smtp['port'],
        $errno, $errstr, 20);
    if (! $fp) {
        throw new RuntimeException("連不上 SMTP:$errstr");
    }
    stream_set_timeout($fp, 30);
    $read = static function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $out;
    };
    $expect = static function (string $what, int $code, ?string $cmd = null) use ($fp, $read): void {
        if ($cmd !== null) {
            fwrite($fp, $cmd . "\r\n");
        }
        $resp = $read();
        if ((int) substr($resp, 0, 3) !== $code) {
            throw new RuntimeException("$what 回應不對:" . trim($resp === '' ? '(沒有回應)' : $resp));
        }
    };
    try {
        $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
        $expect('連線', 220);
        $expect('EHLO', 250, $ehlo);
        if (! $ssl) {
            $expect('STARTTLS', 220, 'STARTTLS');
            if (! stream_socket_enable_crypto($fp, true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('TLS 交握失敗');
            }
            $expect('EHLO(TLS 後)', 250, $ehlo);
        }
        $expect('AUTH', 334, 'AUTH LOGIN');
        $expect('帳號', 334, base64_encode((string) $smtp['username']));
        $expect('密碼(認證)', 235, base64_encode((string) $smtp['password']));
        $expect('MAIL FROM', 250, "MAIL FROM:<$from>");
        foreach ($to as $rcpt) {
            $expect("RCPT TO $rcpt", 250, "RCPT TO:<$rcpt>");
        }
        $expect('DATA', 354, 'DATA');
        // 行首的「.」要多加一個(dot-stuffing)
        $expect('送出內容', 250, preg_replace('/^\./m', '..', rtrim($message, "\r\n")) . "\r\n.");
        fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}
