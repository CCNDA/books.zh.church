<?php
declare(strict_types=1);

/**
 * 每日新品排程的【警報】信(Asana 1217767377517110,母票 1217767344711740)
 *
 * 摘要信(send_daily_report.php)每天固定一封;這支只在出事時寄,標題帶【警報】方便信箱規則過濾。
 * 讀的資料跟摘要信一樣:crawler/logs/summary-YYYYMMDD.tsv、last_ok.json。
 *
 * 會叫的狀況(每一種有一個「指紋」,同一個指紋 20 小時內只寄一次,見 ALERT_THROTTLE_HOURS):
 *   crawl-fail:<來源>     某來源抓取或匯入失敗(HTTP 非 200、連線逾時、匯入 SQL 錯誤都記在這裡)
 *   step-fail:<步驟>      其他步驟失敗(分類、搜尋欄、守門、sitemap;PHP fatal 會讓步驟失敗)
 *   step-skip:<步驟>      關鍵步驟被略過(工具不見了)
 *   summary-missing       當天的摘要檔不見,或只有表頭(排程沒跑、或跑到一半就停)
 *   zero-days:<來源>      某來源連續 N 天(預設 14)都是 0 本新書——可能不是沒新書,是壞了
 *   no-success            超過 26 小時沒有「全部步驟成功」(last_ok.json 太舊或不存在)
 *
 * 還沒做:「商品節點 0 個」「關鍵欄位缺漏超過門檻」要各爬蟲自己回報,目前 summary TSV 沒有這些資料。
 *   網站改版最常見的樣子是抓到 0 個商品 → 會先表現成那個來源連續 0 本,由 zero-days 接住(會慢 N 天)。
 *
 * 用法(主機 CLI):
 *   php tools/send_alert.php --dry-run                 # 只印會寄什麼,不寄、不記節流
 *   php tools/send_alert.php                           # 有狀況就寄給 alert.to(沒設就用 daily_report.to)
 *   php tools/send_alert.php --only=no-success         # 只檢查「超過 26 小時沒成功」(給另外的排程用,不看當天摘要;crontab 見 deploy/cron-new-arrivals.md)
 *   php tools/send_alert.php --to=someone@ccnda.org    # 測試模式:只寄這個位址、主旨加〔測試〕、不記節流
 *   php tools/send_alert.php --date=20261030 --log-dir=/path   # 指定哪一天、哪個資料夾(測試用)
 *   php tools/send_alert.php --now=2026-10-31T15:00:00 # 指定「現在」(測試 26 小時用)
 *
 * 節流紀錄:crawler/logs/alert_state.json(上鎖、先寫暫存檔再改名)({指紋: 上次寄出的時間})。寄出成功才記;--dry-run、--to= 不記。
 * 結束碼:0＝沒狀況或已寄出(或全被節流)、1＝寄不出去、2＝設定不全或參數錯。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
require $root . '/app/lib/book_detail.php';   // app_config()、BUY_PLATFORM_LABELS
require $root . '/tools/lib_mail.php';

// 每天一次的排程，跑完時間每天差幾分鐘：門檻設 24 會讓「今天比昨天早跑完」的那天被擋掉、變成兩天才一封。
// 20 小時：同一天內（主排程＋每 6 小時那支）不重複，隔天照寄。
const ALERT_THROTTLE_HOURS = 20;
const ALERT_STALE_HOURS    = 26;   // 每天跑一次＋最多 2 小時的延誤
const ALERT_ZERO_DAYS      = 14;   // 母票:「連續 N 天 0 新品(門檻待定,如 14 天)」
// 關鍵步驟:被略過也要叫。名單直接讀 daily_new.sh 的 CRITICAL_RE(只有一份,不會兩邊對不起來);讀不到才用這份備用
const ALERT_CRITICAL_FALLBACK = ['classify', 'search_text', 'guard:year', 'guard:nonbook'];

$opts   = getopt('', ['dry-run', 'to:', 'date:', 'log-dir:', 'only:', 'now:']);
$dry    = isset($opts['dry-run']);
$testTo = isset($opts['to']) ? trim((string) $opts['to']) : '';
$only   = isset($opts['only']) ? (string) $opts['only'] : '';
$now    = isset($opts['now']) ? strtotime((string) $opts['now']) : time();
$date   = isset($opts['date']) ? (string) $opts['date'] : date('Ymd', $now);
$logDir = isset($opts['log-dir']) ? rtrim((string) $opts['log-dir'], '/') : $root . '/crawler/logs';

if ($now === false || ! preg_match('/^\d{8}$/', $date) || ! in_array($only, ['', 'no-success'], true)) {
    fwrite(STDERR, "參數錯:--date=YYYYMMDD、--now=看得懂的時間、--only 只能是 no-success\n");
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
    @file_put_contents($mailLog, '[' . date('Y-m-d H:i:s') . "] [警報] $msg\n", FILE_APPEND);
}

$critical = ALERT_CRITICAL_FALLBACK;
$sh = @file_get_contents($root . '/crawler/daily_new.sh');
if (is_string($sh) && preg_match("/^CRITICAL_RE='\\^\\(([a-z_:|]+)\\)\\$'/m", $sh, $m)) {
    $critical = explode('|', $m[1]);
}

$label = static fn (string $src) => BUY_PLATFORM_LABELS[$src] ?? $src;
$day   = static fn (string $ymd) => substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);

/** 讀一天的 summary TSV → 列陣列;檔案不在回 null。 */
function read_summary(string $file): ?array
{
    if (! is_readable($file)) {
        return null;
    }
    $rows = [];
    $fh = fopen($file, 'r');
    fgetcsv($fh, 0, "\t", '"', '');
    while (($r = fgetcsv($fh, 0, "\t", '"', '')) !== false) {
        if (count($r) >= 5) {
            $rows[] = ['step' => $r[1], 'status' => $r[2], 'count' => (int) $r[3], 'note' => $r[5] ?? ''];
        }
    }
    fclose($fh);
    return $rows;
}

// ── 找狀況 ───────────────────────────────────────────────
$alerts = [];   // 指紋 => 一句說明
$add = static function (string $fp, string $msg) use (&$alerts): void {
    $alerts[$fp] = $msg;
};

if ($only === '') {
    $rows = read_summary("$logDir/summary-$date.tsv");
    if ($rows === null) {
        $add('summary-missing', "找不到 {$day($date)} 的摘要檔(summary-$date.tsv):每日排程可能沒有跑");
    } elseif ($rows === []) {
        $add('summary-missing', "{$day($date)} 的摘要檔只有表頭:每日排程可能跑到一半就停了(例如磁碟滿)");
    } else {
        foreach ($rows as $r) {
            $isCrawl = str_starts_with($r['step'], 'crawl:');
            $name = $isCrawl ? $label(substr($r['step'], 6)) : $r['step'];
            $note = $r['note'] !== '' ? "({$r['note']})" : '';
            if ($r['status'] === 'fail') {
                // 指紋帶備註(抓取失敗／匯入失敗…):同一來源換了一種錯,不會被前一種的節流壓掉
                $add(($isCrawl ? 'crawl-fail:' . substr($r['step'], 6) : 'step-fail:' . $r['step'])
                    . ($r['note'] !== '' ? ':' . $r['note'] : ''),
                    $isCrawl ? "$name 抓取或匯入失敗$note:網站可能改版或連不上,詳見當天 log"
                             : "步驟 $name 失敗$note:可能是 PHP 錯誤或 SQL 錯誤,詳見當天 log");
            } elseif ($r['status'] === 'skip' && in_array($r['step'], $critical, true)) {
                $add('step-skip:' . $r['step'], "關鍵步驟 $name 被略過$note:工具可能不見了");
            }
        }
        // 連續 N 天 0 本:往回看 N 份摘要(今天算第 1 天);中間缺一天就不判(資料不夠不能說它壞了)
        $zero = [];
        foreach ($rows as $r) {
            if (str_starts_with($r['step'], 'crawl:') && in_array($r['status'], ['ok', 'none'], true) && $r['count'] === 0) {
                $zero[$r['step']] = 1;
            }
        }
        for ($i = 1; $i < ALERT_ZERO_DAYS && $zero !== []; $i++) {
            $prev = read_summary("$logDir/summary-" . date('Ymd', strtotime("$date -$i day")) . '.tsv');
            if ($prev === null || $prev === []) {   // 缺一天、或那天只有表頭:資料不夠,不判
                $zero = [];
                break;
            }
            $prevZero = [];
            foreach ($prev as $r) {
                if (isset($zero[$r['step']]) && in_array($r['status'], ['ok', 'none'], true) && $r['count'] === 0) {
                    $prevZero[$r['step']] = true;
                }
            }
            $zero = array_intersect_key($zero, $prevZero);
        }
        foreach (array_keys($zero) as $step) {
            $src = substr($step, 6);
            $add("zero-days:$src", "{$label($src)} 連續 " . ALERT_ZERO_DAYS . ' 天都是 0 本新書:可能不是沒有新書,是網站改版抓不到了');
        }
    }
}

// 超過 26 小時沒有全部成功(兩種模式都查)
$lastOk = is_readable("$logDir/last_ok.json") ? json_decode((string) file_get_contents("$logDir/last_ok.json"), true) : null;
$okAt = is_array($lastOk) && isset($lastOk['finished_at']) ? strtotime((string) $lastOk['finished_at']) : false;
if ($okAt === false) {
    $add('no-success', '找不到上次全部成功的紀錄(last_ok.json):排程可能從來沒有全部成功過,或紀錄檔不見了');
} elseif ($now - $okAt > ALERT_STALE_HOURS * 3600) {
    $add('no-success', sprintf('已經 %.1f 小時沒有全部成功(上次 %s,超過 %d 小時)', ($now - $okAt) / 3600,
        date('Y-m-d H:i', $okAt), ALERT_STALE_HOURS));
}

if ($alerts === []) {
    echo "沒有狀況\n";
    exit(0);
}

// ── 節流:同一個指紋 ALERT_THROTTLE_HOURS 小時內只寄一次(測試與 dry-run 不看也不記)────────
$stateFile = "$logDir/alert_state.json";
// 主排程和每 6 小時那支可能同時跑:整段「讀→判→寫」鎖住,不然後寫的會蓋掉先寫的指紋
$lock = @fopen("$stateFile.lock", 'c');
if ($lock) {
    flock($lock, LOCK_EX);
}
$state = is_readable($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
if (! is_array($state)) {
    $state = [];   // 檔案壞掉(或內容不是物件):當成沒寄過,最多多寄一次
}
$send = $alerts;
$held = [];
if (! $dry && $testTo === '') {
    foreach ($alerts as $fp => $msg) {
        if (isset($state[$fp]) && $now - (int) strtotime((string) $state[$fp]) < ALERT_THROTTLE_HOURS * 3600) {
            $held[] = $fp;
            unset($send[$fp]);
        }
    }
}
if ($send === []) {
    echo '狀況 ' . count($alerts) . " 項都在 " . ALERT_THROTTLE_HOURS . " 小時內寄過,這次不寄\n";
    mail_log('狀況都在節流期內,未寄:' . implode(',', $held));
    exit(0);
}

// ── 組信 ─────────────────────────────────────────────────
$first = reset($send);
$subject = '【警報】[書目網] ' . mb_strimwidth(preg_replace('/[:(].*$/u', '', $first), 0, 40, '…', 'UTF-8')
         . (count($send) > 1 ? ' 等 ' . count($send) . ' 項' : '');
if ($testTo !== '') {
    $subject = '〔測試〕' . $subject;
}
$text = "書目網每日新品排程警報(" . date('Y-m-d H:i', $now) . ")\n\n";
foreach ($send as $fp => $msg) {
    $text .= "・$msg\n";
}
if ($held !== []) {
    $text .= "\n另有 " . count($held) . " 項 " . ALERT_THROTTLE_HOURS . " 小時內已經寄過,這次不重複:" . implode('、', $held) . "\n";
}
$text .= "\nlog:crawler/logs/new-arrivals-$date.log(主機上)\n同一個狀況 " . ALERT_THROTTLE_HOURS . " 小時內只寄一次。\n";
$h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$html = '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><title>' . $h($subject) . '</title></head>'
      . '<body style="font-family:sans-serif;color:#222;line-height:1.5">'
      . '<h1 style="font-size:18px;color:#b00020">書目網每日新品排程警報</h1><p>' . $h(date('Y-m-d H:i', $now)) . '</p><ul>';
foreach ($send as $msg) {
    $html .= '<li>' . $h($msg) . '</li>';
}
$html .= '</ul>';
if ($held !== []) {
    $html .= '<p style="color:#555">另有 ' . count($held) . ' 項 ' . ALERT_THROTTLE_HOURS . ' 小時內已經寄過,這次不重複:' . $h(implode('、', $held)) . '</p>';
}
$html .= '<p style="color:#555">log:crawler/logs/new-arrivals-' . $h($date) . '.log(主機上)。同一個狀況 ' . ALERT_THROTTLE_HOURS . ' 小時內只寄一次。</p></body></html>';

if ($dry) {
    echo "Subject: $subject\n\n$text";
    exit(0);
}

// ── 寄出 ─────────────────────────────────────────────────
$cfg = app_config();
$aws = $cfg['aws'] ?? [];
$to  = $testTo !== '' ? [$testTo]
     : array_values(array_filter((array) ($cfg['alert']['to'] ?? $cfg['daily_report']['to'] ?? [])));
if (($err = mail_check($aws, $to)) !== null) {
    fwrite(STDERR, "$err,不寄(alert.to／daily_report.to／aws;要測試請用 --to=)\n");
    mail_log("$err,未寄");
    exit(2);
}
if (! mail_send($aws, $to, $subject, $text, $html, 'mail_log')) {
    exit(1);
}
echo '寄出 → ' . implode(', ', $to) . "\n";
if ($testTo === '') {
    foreach (array_keys($send) as $fp) {
        $state[$fp] = date('c', $now);
    }
    // 先寫暫存檔再改名:寫到一半斷電也不會留下半個 JSON
    $tmp = "$stateFile.tmp";
    if (@file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n") === false
        || ! @rename($tmp, $stateFile)) {
        mail_log("節流紀錄寫不進 $stateFile:下次同樣的狀況會再寄一次");
        fwrite(STDERR, "節流紀錄寫不進 $stateFile\n");
    }
}
exit(0);
