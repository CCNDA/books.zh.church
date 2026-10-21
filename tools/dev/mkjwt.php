<?php
// 開發站後台自動測試用：用 ~/.config/books-admin-test/key.pem 簽 Cf-Access 測試 JWT（iss=test-team、aud=test-aud）。只印 JWT。
$key = openssl_pkey_get_private('file://' . getenv('HOME') . '/.config/books-admin-test/key.pem');
$b = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$h = $b(json_encode(['alg' => 'RS256', 'kid' => 'cora1021', 'typ' => 'JWT']));
$p = $b(json_encode(['iss' => 'https://test-team.cloudflareaccess.com', 'aud' => ['test-aud'], 'email' => $argv[1] ?? 'cora@ccnda.org',
                     'iat' => time(), 'exp' => time() + 600, 'sub' => 'test']));
openssl_sign("$h.$p", $sig, $key, OPENSSL_ALGO_SHA256);
echo "$h.$p." . $b($sig);
