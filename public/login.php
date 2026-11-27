<?php
declare(strict_types=1);
// 會員登入：記「登入進行中」→ 導到台灣聖經網 SSO（M2-1；app/lib/member.php 有流程說明）
require dirname(__DIR__) . '/app/lib/member.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
member_session();
$origin = member_origin();
$host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
$cid = twsso_client_id($host);
if ($origin === null || $cid === null) {
    http_response_code(400);
    echo '這個網址不能登入';
    exit;
}
$_SESSION['sso_pending_until'] = time() + MEMBER_LOGIN_TTL;
$_SESSION['login_return'] = member_safe_return($_GET['r'] ?? '/');
header('Location: ' . TWSSO_INIT_URL . '?' . http_build_query(['client_id' => $cid, 'back' => $origin . '/sso_callback.php']), true, 302);
