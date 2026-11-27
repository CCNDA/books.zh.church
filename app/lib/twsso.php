<?php
declare(strict_types=1);
/**
 * 台灣聖經網 SSO（書目網端）—— 2026-11-27 小芯照搬名錄 inc/twsso.php（Asana 1218124736678630 M2-1；熊叔叔 11/27 TG「甲 台灣聖經網 SSO」）
 * 文件：https://docs.ccnda.org/docs/twbible-sso
 *
 * 不用官方 SDK：SDK 自己產 state、存在瀏覽器 localStorage，伺服器比對不到（登入 CSRF）。
 * 流程：login.php 在本站 session 記「登入進行中」（10 分鐘）→ 導到 init.php（client_id／back）→ 台灣聖經網登入 →
 *       回到 back?token=…&state=…（state 是台灣聖經網自己的，本站比對不到）→ 本站確認 session 有「登入進行中」、驗 token →
 *       登入 → 302 到乾淨網址（token 不留在網址）。back 不能帶 ?（auth.php 直接接 ?token=）。
 *
 * 驗 token（全部在伺服器端，公鑰寫死、不從網路抓）：
 *   header alg 只收 ES256；簽章剛好 64 bytes（JOSE r||s）再轉 DER；iss=taiwanbible；exp 未過；
 *   iat 在 TWSSO_MAX_AGE 秒內、不在未來（容許 60 秒時鐘差）；aud＝本站 client_id；同一張只收一次（用過的雜湊記到 exp）。
 */

const TWSSO_INIT_URL = 'https://api.taiwanbible.com/sso/init.php';
const TWSSO_MAX_AGE = 300;     // 只收 5 分鐘內發的 token
const TWSSO_SKEW = 60;
const TWSSO_PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----\n"
    . "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEiaMqkVpKS9Fc+zM7o+BkhUNRO2E/\n"
    . "iFic+aXN+vVoIGPc6/0htZ7k5jq2OWZaezq3/FazJxDZ5PSai+LuwlFsrQ==\n"
    . "-----END PUBLIC KEY-----\n";
// 網域 → sso_clients.client_id（小布 11/27 14:34 在 jackpu_main 加；填完整主機名，不開整個 zh.church）
const TWSSO_CLIENTS = ['books.zh.church' => 6, 'test-books.ps194.net' => 7];

function twsso_client_id(string $host): ?int
{
    $host = strtolower(preg_replace('/:\d+$/', '', $host));
    return TWSSO_CLIENTS[$host] ?? null;
}

function twsso_b64url_decode(string $s)
{
    if ($s === '' || preg_match('/[^A-Za-z0-9_-]/', $s)) return false;
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

/** JOSE ES256 簽章（r||s 各 32 bytes）→ DER；長度不是 64 回 null */
function twsso_jose_to_der(string $sig): ?string
{
    if (strlen($sig) !== 64) return null;
    $int = static function (string $x): string {
        $x = ltrim($x, "\x00");
        if ($x === '' ) $x = "\x00";
        if (ord($x[0]) & 0x80) $x = "\x00" . $x;
        return "\x02" . chr(strlen($x)) . $x;
    };
    $seq = $int(substr($sig, 0, 32)) . $int(substr($sig, 32, 32));
    return "\x30" . chr(strlen($seq)) . $seq;
}

/**
 * 驗 token：成功回 ['ok'=>true,'claims'=>[...]]；失敗回 ['ok'=>false,'why'=>'…']（why 只寫進 log，不給使用者看）
 * $seen：callable(string $hash, int $exp): bool —— 回 true 表示「這張第一次用、已記下」；false 表示用過
 */
function twsso_verify(string $token, int $expect_aud, int $now, callable $seen, string $pubkey = TWSSO_PUBLIC_KEY): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return ['ok' => false, 'why' => 'format'];
    [$h64, $p64, $s64] = $parts;
    $h = json_decode((string) twsso_b64url_decode($h64), true);
    $p = json_decode((string) twsso_b64url_decode($p64), true);
    $sig = twsso_b64url_decode($s64);
    if (!is_array($h) || !is_array($p) || $sig === false) return ['ok' => false, 'why' => 'decode'];
    if (($h['alg'] ?? null) !== 'ES256') return ['ok' => false, 'why' => 'alg'];
    $der = twsso_jose_to_der($sig);
    if ($der === null) return ['ok' => false, 'why' => 'siglen'];
    $key = openssl_pkey_get_public($pubkey);
    if ($key === false || openssl_verify("$h64.$p64", $der, $key, OPENSSL_ALGO_SHA256) !== 1) return ['ok' => false, 'why' => 'signature'];
    if (($p['iss'] ?? null) !== 'taiwanbible') return ['ok' => false, 'why' => 'iss'];
    $exp = filter_var($p['exp'] ?? null, FILTER_VALIDATE_INT);
    $iat = filter_var($p['iat'] ?? null, FILTER_VALIDATE_INT);
    if ($exp === false || $exp <= $now) return ['ok' => false, 'why' => 'exp'];
    if ($iat === false || $iat > $now + TWSSO_SKEW || $now - $iat > TWSSO_MAX_AGE) return ['ok' => false, 'why' => 'iat'];
    if (filter_var($p['aud'] ?? null, FILTER_VALIDATE_INT) !== $expect_aud) return ['ok' => false, 'why' => 'aud'];
    // 書目網用 SSO 的 ID 當會員鍵（熊叔叔 11/27 甲：只存編號、暱稱、頭像）
    if (filter_var($p['ID'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) return ['ok' => false, 'why' => 'id'];
    if (!$seen(hash('sha256', $token), $exp)) return ['ok' => false, 'why' => 'replay'];
    return ['ok' => true, 'claims' => $p];
}
