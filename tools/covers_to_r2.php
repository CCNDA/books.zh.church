<?php
declare(strict_types=1);

/**
 * 封面轉存 R2(D5 配套,可續跑)
 *
 * 流程:media(cover,url_or_path 仍=來源網址)且 books.cover_url 為空者,
 * 每書取第一張 → 下載 → SigV4 PUT 到 R2({prefix}{book_id}.jpg)→
 * 更新 books.cover_url 與 media.url_or_path 為公開網址。
 *
 * 用法(主機 SSH):
 *   php tools/covers_to_r2.php --limit=50 --dry-run   # 先看要處理哪些
 *   php tools/covers_to_r2.php --limit=500            # 分批跑,重跑自動續
 *   php tools/covers_to_r2.php                        # 全量
 *
 * 節流:每張間隔 0.6-1.2 秒(對來源站禮貌);失敗記數跳過,重跑再試。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/api/lib/db.php';

$opt   = getopt('', ['limit::', 'dry-run']);
$limit = (int) ($opt['limit'] ?? 0);
$dry   = array_key_exists('dry-run', $opt);

$r2 = app_config()['r2'] ?? null;
if (!$r2 || empty($r2['access_key_id'])) {
    exit("config/app.local.php 缺 r2 設定\n");
}
$endpoint = rtrim($r2['endpoint'], '/');
$bucket   = $r2['bucket'];
$prefix   = trim($r2['prefix'] ?? 'books/', '/') . '/';
$public   = rtrim($r2['public_url'], '/');

// ── SigV4(R2/S3 相容,region=auto) ─────────────────────
function r2_put(string $endpoint, string $bucket, string $key, string $body,
                string $contentType, string $ak, string $sk): bool
{
    $host    = parse_url($endpoint, PHP_URL_HOST);
    $uri     = '/' . $bucket . '/' . str_replace('%2F', '/', rawurlencode($key));
    $amzDate = gmdate('Ymd\THis\Z');
    $date    = gmdate('Ymd');
    $region  = 'auto';
    $service = 's3';
    $payloadHash = hash('sha256', $body);

    $headers = [
        'content-type'         => $contentType,
        'host'                 => $host,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date'           => $amzDate,
    ];
    ksort($headers);
    $canonicalHeaders = '';
    $signedHeaders = [];
    foreach ($headers as $k => $v) {
        $canonicalHeaders .= $k . ':' . trim($v) . "\n";
        $signedHeaders[] = $k;
    }
    $signedHeadersStr = implode(';', $signedHeaders);
    $canonicalRequest = "PUT\n$uri\n\n$canonicalHeaders\n$signedHeadersStr\n$payloadHash";
    $scope = "$date/$region/$service/aws4_request";
    $stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonicalRequest);
    $kDate    = hash_hmac('sha256', $date, 'AWS4' . $sk, true);
    $kRegion  = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $auth = "AWS4-HMAC-SHA256 Credential=$ak/$scope, SignedHeaders=$signedHeadersStr, Signature=$signature";
    $ch = curl_init($endpoint . $uri);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $auth,
            'Content-Type: ' . $contentType,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
        ],
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function fetch_image(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_USERAGENT      => 'CCNDA-BooksBot/1.0 (+https://books.zh.church; cover mirror)',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ct   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($code !== 200 || !$body || strlen($body) < 500) return null;
    if (!preg_match('#image/(jpe?g|png|webp|gif)#i', $ct, $m)) {
        // 部分主機回 octet-stream,以magic bytes判斷
        $sig = substr($body, 0, 4);
        if (str_starts_with($sig, "\xFF\xD8")) { $m = [1 => 'jpeg']; }
        elseif (str_starts_with($sig, "\x89PNG")) { $m = [1 => 'png']; }
        else return null;
    }
    $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
    $ct2 = $ext === 'jpg' ? 'image/jpeg' : 'image/' . $ext;
    return [$body, $ext, $ct2];
}

// ── 主流程 ───────────────────────────────────────────────

$pdo = db();
$sql = "SELECT t.book_id, t.media_id, m2.url_or_path AS src
        FROM (SELECT b.book_id, MIN(m.media_id) AS media_id
              FROM books b
              JOIN editions e ON e.book_id = b.book_id
              JOIN media m ON m.edition_id = e.edition_id AND m.media_type = 'cover'
              WHERE b.cover_url IS NULL AND m.url_or_path LIKE 'http%'
              GROUP BY b.book_id) t
        JOIN media m2 ON m2.media_id = t.media_id
        ORDER BY t.book_id";
$rows = $pdo->query($sql)->fetchAll();
echo "待轉存:" . count($rows) . " 本\n";

$ok = $fail = 0;
foreach ($rows as $i => $r) {
    if ($limit && ($ok + $fail) >= $limit) break;
    if ($dry) { echo "  [{$r['book_id']}] {$r['src']}\n"; $ok++; continue; }

    usleep(random_int(600000, 1200000));
    $img = fetch_image($r['src']);
    if (!$img) { echo "  [下載失敗] book {$r['book_id']} {$r['src']}\n"; $fail++; continue; }
    [$body, $ext, $ct] = $img;
    $key = $prefix . $r['book_id'] . '.' . $ext;
    if (!r2_put($endpoint, $bucket, $key, $body, $ct, $r2['access_key_id'], $r2['secret_access_key'])) {
        echo "  [R2 上傳失敗] book {$r['book_id']}\n"; $fail++; continue;
    }
    $publicUrl = $public . '/' . $key;
    $pdo->prepare("UPDATE books SET cover_url = :u WHERE book_id = :id")
        ->execute([':u' => $publicUrl, ':id' => $r['book_id']]);
    $pdo->prepare("UPDATE media SET url_or_path = :u WHERE media_id = :m")
        ->execute([':u' => $publicUrl, ':m' => $r['media_id']]);
    $ok++;
    if ($ok % 50 === 0) echo "  進度:$ok 成功 / $fail 失敗(共 " . count($rows) . ")\n";
}
echo ($dry ? "[dry-run] " : "") . "完成:$ok 成功、$fail 失敗(失敗者重跑會再試)\n";
