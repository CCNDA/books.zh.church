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

$opt   = getopt('', ['limit::', 'dry-run', 'source::']);
$limit = (int) ($opt['limit'] ?? 0);
$srcFilter = (string) ($opt['source'] ?? '');  // 只轉指定來源(campus|logos|elim|grace|wdbook|methodist|osb|taosheng|cclm|cosmiccare|mezu|twgbr|pctpress)
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

/**
 * 以琳 og:image 的「預設檔名」路徑是錯的:根目錄 /s{條碼}.jpg 一律 404,
 * 實際檔案在 /shop_images/{條碼}.jpg(大圖)與 /shop_images/s{條碼}.jpg(縮圖)
 * (2026-08-02 實測)。回傳依序嘗試的候選網址:大圖 → 縮圖 → 原網址。
 */
function url_candidates(string $url): array
{
    if (preg_match('#^(https?://www\.elimbookstore\.com\.tw)/s?([^/]+\.jpe?g)$#i', $url, $m)) {
        return [
            $m[1] . '/shop_images/' . $m[2],        // 大圖(無 s 前綴)
            $m[1] . '/shop_images/s' . $m[2],       // 縮圖
            $url,                                    // 原網址(保底)
        ];
    }
    // 衛理書房(methodistbookroom):og:image 偶為 /image/cache/...-420x420.jpg
    // 縮圖網址;優先試改寫回原圖路徑(爬蟲已改寫,此處保底處理歷史資料)
    if (preg_match('#^(https?://methodistbookroom\.com)/image/cache/(.+)-\d+x\d+(\.[a-z]+)$#i', $url, $m)) {
        return [$m[1] . '/image/' . $m[2] . $m[3], $url];
    }
    return [$url];
}

function fetch_image(string $url, ?string &$why = null): ?array
{
    // 以琳(elimbookstore)擋非瀏覽器請求:圖檔需瀏覽器 UA + Referer 才回 200
    // (2026-07-31 實測;瀏覽器直開同網址皆正常,bot UA 大面積失敗)。
    $ua      = 'CCNDA-BooksBot/1.0 (+https://books.zh.church; cover mirror)';
    $headers = ['From: cowork@ccnda.org'];
    $host    = (string) (parse_url($url, PHP_URL_HOST) ?: '');
    if (stripos($host, 'elimbookstore.com.tw') !== false) {
        $ua        = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                   . '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
        $headers[] = 'Referer: https://www.elimbookstore.com.tw/';
        $headers[] = 'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8';
        usleep(random_int(1000000, 1500000)); // 對以琳額外放慢,避免觸發 WAF
    }
    // 檔名可能帶空格/括號(如「01cover (1).png」),未編碼會讓 curl 連線失敗
    $url = str_replace(' ', '%20', $url);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ct   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($code !== 200 || !$body || strlen($body) < 500) {
        $why = "HTTP $code" . ($body === false || $body === '' ? '/無回應' : (strlen((string) $body) < 500 ? '/內容過小' : ''));
        return null;
    }
    if (!preg_match('#image/(jpe?g|png|webp|gif|avif|x-ms-bmp|bmp)#i', $ct, $m)) {
        // 部分主機回 octet-stream,以magic bytes判斷
        $sig = substr($body, 0, 12);
        if (str_starts_with($sig, "\xFF\xD8")) { $m = [1 => 'jpeg']; }
        elseif (str_starts_with($sig, "\x89PNG")) { $m = [1 => 'png']; }
        elseif (substr($sig, 4, 8) === 'ftypavif') { $m = [1 => 'avif']; }  // 以琳新圖用 avif
        elseif (str_starts_with($sig, 'BM')) { $m = [1 => 'bmp']; }          // 以琳偶見 bmp
        else { $why = "非圖片($ct)"; return null; }
    }
    $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : (strtolower($m[1]) === 'x-ms-bmp' ? 'bmp' : strtolower($m[1]));
    $ct2 = $ext === 'jpg' ? 'image/jpeg' : 'image/' . $ext;
    return [$body, $ext, $ct2];
}

// ── 主流程 ───────────────────────────────────────────────

$pdo = db();
$srcCond = '';
if ($srcFilter !== '') {
    if (!in_array($srcFilter, ['campus', 'logos', 'elim', 'grace', 'wdbook', 'methodist', 'osb', 'taosheng', 'cclm', 'cosmiccare', 'mezu', 'twgbr', 'pctpress'], true)) {
        exit("--source 只接受 campus|logos|elim|grace|wdbook|methodist|osb|taosheng|cclm|cosmiccare|mezu|twgbr|pctpress\n");
    }
    $srcCond = " AND e.source = " . $pdo->quote($srcFilter);
}
$sql = "SELECT t.book_id, t.media_id, m2.url_or_path AS src
        FROM (SELECT b.book_id, MIN(m.media_id) AS media_id
              FROM books b
              JOIN editions e ON e.book_id = b.book_id
              JOIN media m ON m.edition_id = e.edition_id AND m.media_type = 'cover'
              WHERE b.is_published = 1 AND b.cover_url IS NULL AND m.url_or_path LIKE 'http%'
              $srcCond
              GROUP BY b.book_id) t
        JOIN media m2 ON m2.media_id = t.media_id
        ORDER BY t.book_id";
$rows = $pdo->query($sql)->fetchAll();
echo '待轉存:' . count($rows) . ' 本' . ($srcFilter ? "(僅 $srcFilter)" : '') . "\n";

$ok = $fail = 0;
foreach ($rows as $i => $r) {
    if ($limit && ($ok + $fail) >= $limit) break;
    if ($dry) { echo "  [{$r['book_id']}] {$r['src']}\n"; $ok++; continue; }

    usleep(random_int(600000, 1200000));
    $img = null;
    foreach (url_candidates($r['src']) as $cand) {
        $img = fetch_image($cand, $why);
        if ($img) break;
    }
    if (!$img) { echo "  [下載失敗 " . ($why ?? '?') . "] book {$r['book_id']} {$r['src']}\n"; $fail++; continue; }
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
