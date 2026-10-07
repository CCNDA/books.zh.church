<?php
declare(strict_types=1);

/**
 * 發佈版本更新說明到 Discord(2026-08-21)
 *
 * 用途:每次發版(git tag vX.Y.Z)後,把「寫給一般使用者看」的更新說明
 *   貼到 Discord 頻道。文案不由 CHANGELOG 自動轉換——CHANGELOG 是給
 *   開發者看的,語氣與取捨都不同——而是另外寫在 release-notes/vX.Y.Z.md,
 *   本工具只負責送出。
 *
 * 設定(憑證不進 git):在 config/app.local.php 增加
 *   'discord' => [
 *       'webhook_url' => 'https://discord.com/api/webhooks/....',
 *       'username'    => '屬靈共同書目',   // 選填,顯示名稱
 *   ],
 * 或設環境變數 DISCORD_WEBHOOK_URL。
 *
 * 用法(主機 CLI):
 *   php tools/notify_discord.php --dry-run          # 只印出將送出的內容
 *   php tools/notify_discord.php                    # 送出 release-notes/v{VERSION}.md
 *   php tools/notify_discord.php --version=1.6.0    # 指定版本
 *   php tools/notify_discord.php --file=path/to.md  # 指定檔案
 *
 * 安全:
 *   - allowed_mentions 設為不解析任何 mention,避免文案裡的 @everyone
 *     意外通知全頻道。要 tag 人請到 Discord 手動編輯訊息。
 *   - 超過 Discord 2000 字上限時自動依段落切成多則依序送出。
 *   - 預設**不會**自動執行;請在確認過 --dry-run 內容後才送出。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
require $root . '/app/lib/db.php';   // 取得 app_config()

const DISCORD_LIMIT = 1900;          // 保留餘裕(官方上限 2000)

$opt     = getopt('', ['version::', 'file::', 'dry-run', 'username::']);
$dry     = array_key_exists('dry-run', $opt);
$version = trim((string) ($opt['version'] ?? ''));
if ($version === '') {
    $vf = $root . '/VERSION';
    $version = is_file($vf) ? trim((string) file_get_contents($vf)) : '';
}
if ($version === '') exit("找不到版號(VERSION 檔為空,請用 --version=X.Y.Z)\n");

$file = (string) ($opt['file'] ?? ($root . "/release-notes/v{$version}.md"));
if (!is_file($file)) {
    exit("找不到更新說明:{$file}\n"
       . "請先寫好該檔(寫給一般使用者看的版本,不是 CHANGELOG)。\n");
}
$body = trim((string) file_get_contents($file));
if ($body === '') exit("更新說明是空的:{$file}\n");

// ── 設定 ───────────────────────────────────────────────────
$cfg      = app_config();
$webhook  = (string) ($cfg['discord']['webhook_url'] ?? getenv('DISCORD_WEBHOOK_URL') ?: '');
$username = (string) ($opt['username'] ?? ($cfg['discord']['username'] ?? ''));

// ── 切段(Discord 單則 2000 字上限;優先在空行、其次換行處切)──
function chunk_message(string $text, int $limit = DISCORD_LIMIT): array
{
    $out = [];
    while (mb_strlen($text) > $limit) {
        $slice = mb_substr($text, 0, $limit);
        $cut   = mb_strrpos($slice, "\n\n");
        if ($cut === false || $cut < $limit * 0.5) $cut = mb_strrpos($slice, "\n");
        if ($cut === false || $cut < $limit * 0.5) $cut = $limit;
        $out[] = rtrim(mb_substr($text, 0, $cut));
        $text  = ltrim(mb_substr($text, $cut));
    }
    if (trim($text) !== '') $out[] = trim($text);
    return $out;
}

$parts = chunk_message($body);

echo "版本 v{$version};來源 {$file}\n";
echo '共 ' . mb_strlen($body) . " 字,切成 " . count($parts) . " 則\n";
echo str_repeat('─', 60) . "\n";
foreach ($parts as $i => $p) {
    echo "【第 " . ($i + 1) . " 則," . mb_strlen($p) . " 字】\n{$p}\n";
    echo str_repeat('─', 60) . "\n";
}

if ($dry) {
    echo "[dry-run 未送出]\n";
    echo $webhook === ''
        ? "注意:尚未設定 webhook(config/app.local.php 的 discord.webhook_url)。\n"
        : "確認內容無誤後,拿掉 --dry-run 即送出。\n";
    exit(0);
}

if ($webhook === '') {
    exit("未設定 Discord webhook:請在 config/app.local.php 加 'discord' => ['webhook_url' => '...']\n");
}

// ── 送出 ───────────────────────────────────────────────────
$sent = 0;
foreach ($parts as $i => $p) {
    $payload = ['content' => $p, 'allowed_mentions' => ['parse' => []]];
    if ($username !== '') $payload['username'] = $username;

    $ch = curl_init($webhook);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $n = $i + 1;
    if ($code === 200 || $code === 204) {
        $sent++;
        echo "  第 {$n} 則:送出成功(HTTP {$code})\n";
    } else {
        echo "  第 {$n} 則:失敗(HTTP {$code})" . ($err ? " {$err}" : '')
           . ($res ? ' ' . mb_substr((string) $res, 0, 200) : '') . "\n";
        exit("中止:後續段落未送出,修正後可重跑(注意已送出的段落會重複)。\n");
    }
    if ($i < count($parts) - 1) sleep(1);   // 避免 rate limit
}
echo "完成:{$sent}/" . count($parts) . " 則已發佈到 Discord\n";
