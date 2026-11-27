<?php
// 書目網台灣聖經網 SSO 驗 token 單元測試：php tests/test_twsso.php <repo 根目錄>（自己產一把 P-256 測試金鑰，不碰網路）
require rtrim($argv[1] ?? dirname(__DIR__), '/') . '/app/lib/twsso.php';
$ok = $bad = 0;
function t($n, $c, $i = '') { global $ok, $bad; if ($c) { $ok++; echo "  ✓ $n\n"; } else { $bad++; echo "  ✗ $n $i\n"; } }
$k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$pub = openssl_pkey_get_details($k)['key'];
$b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
// DER → JOSE（r||s）
function der2jose($der) {
    $o = 2; $out = '';
    for ($i = 0; $i < 2; $i++) { $len = ord($der[$o + 1]); $v = ltrim(substr($der, $o + 2, $len), "\x00"); $out .= str_pad($v, 32, "\x00", STR_PAD_LEFT); $o += 2 + $len; }
    return $out;
}
$now = 1795700000;
$mk = function (array $claims, array $hdr = ['alg' => 'ES256', 'typ' => 'JWT'], ?callable $sigmod = null) use ($k, $b64) {
    $h = $b64(json_encode($hdr)); $p = $b64(json_encode($claims));
    openssl_sign("$h.$p", $der, $k, OPENSSL_ALGO_SHA256);
    $sig = der2jose($der); if ($sigmod) $sig = $sigmod($sig);
    return "$h.$p." . $b64($sig);
};
$base = ['ID' => 7, 'Username' => 'tester', 'Email' => 't@example.invalid', 'iss' => 'taiwanbible', 'iat' => $now - 10, 'exp' => $now + 86000, 'aud' => 6, 'ev' => 1];
$used = [];
$seen = function ($hash, $exp) use (&$used) { if (isset($used[$hash])) return false; $used[$hash] = $exp; return true; };
$v = fn($tok, $aud = 6) => twsso_verify($tok, $aud, $now, $seen, $pub);

$good = $mk($base);
$r = $v($good); t('正常 token 通過', $r['ok'] && $r['claims']['Username'] === 'tester', json_encode($r));
$r = $v($good); t('同一張第二次 → replay', !$r['ok'] && $r['why'] === 'replay', json_encode($r));
[$h, $p, $s] = explode('.', $mk($base));
$p2 = $b64(json_encode(['Username' => 'admin'] + $base));
$r = $v("$h.$p2.$s"); t('竄改 payload → signature', !$r['ok'] && $r['why'] === 'signature', json_encode($r));
$r = $v($mk(['exp' => $now - 1] + $base)); t('過期 → exp', !$r['ok'] && $r['why'] === 'exp', json_encode($r));
$r = $v($mk(['iat' => $now - 301] + $base)); t('iat 超過 5 分鐘 → iat', !$r['ok'] && $r['why'] === 'iat', json_encode($r));
$r = $v($mk(['iat' => $now + 120] + $base)); t('iat 在未來 → iat', !$r['ok'] && $r['why'] === 'iat', json_encode($r));
$r = $v($mk(['aud' => 2] + $base)); t('aud 是別站（2）→ aud', !$r['ok'] && $r['why'] === 'aud', json_encode($r));
$nb = $base; unset($nb['aud']); $r = $v($mk($nb)); t('沒有 aud（舊版 SSO）→ aud', !$r['ok'] && $r['why'] === 'aud', json_encode($r));
$r = $v($mk(['iss' => 'evil'] + $base)); t('iss 不對 → iss', !$r['ok'] && $r['why'] === 'iss', json_encode($r));
[$h, $p] = explode('.', $mk($base)); $hn = $b64(json_encode(['alg' => 'none']));
$r = $v("$hn.$p."); t('alg=none → alg', !$r['ok'] && in_array($r['why'], ['alg', 'decode'], true), json_encode($r));
$r = $v($mk($base, ['alg' => 'HS256'])); t('alg=HS256 → alg', !$r['ok'] && $r['why'] === 'alg', json_encode($r));
$r = $v($mk(['n' => 1] + $base, ['alg' => 'ES256'], fn($s) => substr($s, 0, 63))); t('簽章 63 bytes → siglen', !$r['ok'] && $r['why'] === 'siglen', json_encode($r));
$r = $v($mk(['n' => 2] + $base, ['alg' => 'ES256'], fn($s) => $s . "\x00")); t('簽章 65 bytes → siglen', !$r['ok'] && $r['why'] === 'siglen', json_encode($r));
$k2 = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$r = twsso_verify($good, 6, $now, fn() => true, openssl_pkey_get_details($k2)['key']); t('別把金鑰簽的 → signature', !$r['ok'] && $r['why'] === 'signature', json_encode($r));
$r = $v('a.b'); t('格式不對 → format', !$r['ok'] && $r['why'] === 'format');
t('client_id 對應', twsso_client_id('books.zh.church') === 6 && twsso_client_id('TEST-BOOKS.ps194.net:443') === 7 && twsso_client_id('evil.zh.church') === null && twsso_client_id('zh.church') === null);
$nb = $base; unset($nb['ID']); $r = $v($mk($nb)); t('沒有 ID → id', !$r['ok'] && $r['why'] === 'id', json_encode($r));
$r = $v($mk(['ID' => 0] + $base)); t('ID=0 → id', !$r['ok'] && $r['why'] === 'id', json_encode($r));
// 多跑幾次正常 token：DER 轉換要處理 r／s 最高位是 1、前導零（隨機簽章大約一半會碰到）
$fail = 0; for ($i = 0; $i < 200; $i++) { $r = $v($mk(['i' => $i] + $base)); if (!$r['ok']) $fail++; }
t('200 張隨機簽章全部驗得過（DER 轉換邊界）', $fail === 0, "失敗 $fail");
echo "\n" . ($bad ? "失敗 {$bad} 項" : "全綠：{$ok} 項通過") . "\n";
exit($bad ? 1 : 0);
