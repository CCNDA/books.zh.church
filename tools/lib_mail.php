<?php
declare(strict_types=1);

/**
 * 排程通知信共用的寄信程式(SES SMTP):每日摘要信 send_daily_report.php、警報信 send_alert.php。
 * 2026-10-31 從 send_daily_report.php 抽出(Asana 1217767377517110),兩支信的寄法才不會分岔。
 *
 * 設定:config/app.local.php 的 aws(ses_from／ses_from_name／ses_reply_to／smtp)。憑證不進 git。
 * 寄不出去等 30 秒重試一次;紀錄只寫 SMTP 伺服器的回應,不寫帳號密碼。
 */

/** 收件人與 SMTP 設定檢查:有問題回一句說明,沒問題回 null。 */
function mail_check(array $aws, array $to): ?string
{
    if ($to === []) {
        return '沒有收件人';
    }
    // 收件人會進 To: 與 RCPT TO:,不是有效 email(例如夾了換行)就不寄,免得插進 header 或 SMTP 指令
    foreach ($to as $addr) {
        if (! filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            return '收件人裡有不是 email 的值';
        }
    }
    foreach (['host', 'port', 'username', 'password'] as $k) {
        if (empty($aws['smtp'][$k])) {
            return "aws.smtp.$k 沒有設定";
        }
    }
    if (! filter_var((string) ($aws['ses_from'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        return 'aws.ses_from 不是有效的 email';
    }
    return null;
}

/**
 * 組信並寄出(純文字＋HTML)。成功回 true;兩次都失敗回 false。$log 收一行紀錄。
 * 呼叫前要先過 mail_check()。
 */
function mail_send(array $aws, array $to, string $subject, string $text, string $html, callable $log): bool
{
    $from = (string) $aws['ses_from'];
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
            smtp_send($aws['smtp'], $from, $to, $message);
            $log('寄出 → ' . implode(', ', $to) . " 主旨:$subject");
            return true;
        } catch (Throwable $e) {
            $log("第 $attempt 次寄信失敗:" . $e->getMessage());
            fwrite(STDERR, "第 $attempt 次寄信失敗:" . $e->getMessage() . "\n");
            if ($attempt === 1) {
                sleep(30);
            }
        }
    }
    return false;
}

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
