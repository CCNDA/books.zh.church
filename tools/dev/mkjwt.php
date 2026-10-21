<?php
// 開發站後台自動測試用：用 ~/.config/books-admin-test/key.pem 簽 Cf-Access 測試 JWT（iss=test-team、aud=test-aud）。只印 JWT。
$key = openssl_pkey_get_private('file://' . getenv('HOME') . '/.config/books-admin-test/key.pem');
$b = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$h = $b(json_encode(['alg' => 'RS256', 'kid' => 'cora1021', 'typ' => 'JWT']));
// 用法：php tools/dev/mkjwt.php [email]　或　php tools/dev/mkjwt.php --svc=<Client ID>（模擬服務權杖：沒有 email、只有 common_name）
$svc = (isset($argv[1]) && str_starts_with($argv[1], '--svc=')) ? substr($argv[1], 6) : null;
$claims = ['iss' => 'https://test-team.cloudflareaccess.com', 'aud' => ['test-aud'], 'iat' => time(), 'exp' => time() + 600, 'sub' => ''];
if ($svc !== null) {
    $claims['common_name'] = $svc;
} else {
    $claims['email'] = $argv[1] ?? 'cora@ccnda.org';
    $claims['sub'] = 'test';
}
$p = $b(json_encode($claims));
openssl_sign("$h.$p", $sig, $key, OPENSSL_ALGO_SHA256);
echo "$h.$p." . $b($sig);
