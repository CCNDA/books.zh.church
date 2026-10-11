<?php
declare(strict_types=1);

/**
 * Cloudflare Access 登入驗證（後台 /admin/ 用；Asana 1219387164843657）。
 *
 * 流程：使用者在 Cloudflare 那邊用協會 Google 帳號登入 → Cloudflare 轉送請求時帶上
 *   Cf-Access-Jwt-Assertion（RS256 簽章的 JWT）→ 這裡驗簽章、iss、aud、到期時間、email 網域。
 *
 * ★ 為什麼 PHP 還要再驗一次：主機 IP 是公開的，有人可以繞過 Cloudflare 直接連主機，
 *   自己塞一個 Cf-Access-Jwt-Assertion 標頭。只看「有沒有這個標頭」等於沒擋，一定要驗簽章。
 * ★ 任何一項不對都回 null（不放行）；沒設定也不放行。
 *
 * 公鑰：https://<team>/cdn-cgi/access/certs 的 public_certs（PEM），快取 1 小時；
 *   遇到沒看過的 kid（Cloudflare 換金鑰）才重抓，最快 60 秒一次。
 */

/**
 * @param array{access_team?: string, access_aud?: list<string>, email_domain?: string, emails?: list<string>, certs_file?: string} $cfg
 * @return array{email: string, claims: array}|null
 */
function cf_access_user(array $cfg, ?string &$why = null): ?array
{
    $team = (string) ($cfg['access_team'] ?? '');
    $auds = array_values(array_filter((array) ($cfg['access_aud'] ?? []), 'is_string'));
    $domain = strtolower((string) ($cfg['email_domain'] ?? ''));
    if ($team === '' || $auds === [] || $domain === '') {
        $why = '後台登入尚未設定（config admin.access_team／access_aud／email_domain）';
        return null;
    }

    $jwt = (string) ($_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? '');
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        $why = '沒有 Cloudflare 登入憑證';
        return null;
    }
    [$h64, $p64, $s64] = $parts;
    $header = json_decode((string) cf_b64url($h64), true);
    $claims = json_decode((string) cf_b64url($p64), true);
    $sig = cf_b64url($s64);
    if (!is_array($header) || !is_array($claims) || $sig === null
        || ($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null)) {
        $why = '登入憑證格式不對';
        return null;
    }

    $pem = cf_access_cert($team, $header['kid'], $cfg['certs_file'] ?? null);
    if ($pem === null || openssl_verify("{$h64}.{$p64}", $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) {
        $why = '登入憑證簽章不對';
        return null;
    }

    $now = time();
    $tokenAud = (array) ($claims['aud'] ?? []);
    if (($claims['iss'] ?? '') !== "https://{$team}") {
        $why = '登入憑證不是這個團隊發的';
        return null;
    }
    if (array_intersect($auds, $tokenAud) === []) {
        $why = '登入憑證不是給這個應用程式的';
        error_log('[admin] Cloudflare Access aud 不符，收到：' . implode(',', array_map('strval', $tokenAud)));
        return null;
    }
    if (!is_int($claims['exp'] ?? null) || $claims['exp'] < $now - 30
        || (is_int($claims['nbf'] ?? null) && $claims['nbf'] > $now + 60)) {
        $why = '登入已過期，請重新整理頁面';
        return null;
    }

    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    if (!str_ends_with($email, '@' . $domain)) {
        $why = '只有 @' . $domain . ' 的帳號可以使用後台';
        return null;
    }
    $allow = array_map('strtolower', (array) ($cfg['emails'] ?? []));
    if ($allow !== [] && !in_array($email, $allow, true)) {
        $why = '這個帳號沒有後台權限';
        return null;
    }
    return ['email' => $email, 'claims' => $claims];
}

function cf_b64url(string $s): ?string
{
    $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return $d === false ? null : $d;
}

/** 依 kid 取 PEM 公鑰；certs_file 只給開發站測試用（正式站設定不要有這一項）。 */
function cf_access_cert(string $team, string $kid, ?string $certsFile): ?string
{
    $cache = sys_get_temp_dir() . '/cf_access_certs_' . md5($team) . '.json';
    $load = static function (string $json): array {
        $out = [];
        foreach ((array) (json_decode($json, true)['public_certs'] ?? []) as $c) {
            if (is_string($c['kid'] ?? null) && is_string($c['cert'] ?? null)) {
                $out[$c['kid']] = $c['cert'];
            }
        }
        return $out;
    };

    if ($certsFile !== null) {
        return $load((string) @file_get_contents($certsFile))[$kid] ?? null;
    }

    $age = is_file($cache) ? time() - (int) filemtime($cache) : PHP_INT_MAX;
    $certs = $age < 3600 ? $load((string) @file_get_contents($cache)) : [];
    if (isset($certs[$kid])) {
        return $certs[$kid];
    }
    if ($age < 60) {
        return null; // 剛抓過還是沒有這個 kid，不要每個請求都去打 Cloudflare
    }

    $json = cf_http_get("https://{$team}/cdn-cgi/access/certs");
    if ($json === null || $load($json) === []) {
        @touch($cache); // 抓不到也記時間，60 秒內不重試
        return null;
    }
    @file_put_contents($cache, $json, LOCK_EX);
    return $load($json)[$kid] ?? null;
}

function cf_http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return (is_string($body) && $code === 200) ? $body : null;
    }
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
    return is_string($body) ? $body : null;
}
