<?php
declare(strict_types=1);
// 台灣聖經網 SSO 回呼：驗 token → 登入 → 302 到乾淨網址（token 不留在網址列、不進 Referer）（M2-1）
require dirname(__DIR__) . '/app/lib/member.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');
member_session();
$host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
$cid = twsso_client_id($host);
$token = (string) ($_GET['token'] ?? '');
$why = null;
$id = ($cid !== null && $token !== '' && strlen($token) < 4096) ? member_handle_callback($token, $cid, time(), $why) : null;
if ($id !== null) {
    $r = member_safe_return($_SESSION['login_return'] ?? '/');
    unset($_SESSION['login_return']);
    header('Location: ' . $r, true, 302);
    exit;
}
error_log('[sso_callback] 登入失敗：' . ($why ?? ($cid === null ? 'host' : 'no-token')));
http_response_code(400);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>登入沒有成功｜屬靈共同書目</title>
<style>body{margin:0;background:#f2f6f9;color:#14303c;font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif;line-height:1.7}main{max-width:560px;margin:80px auto;padding:0 20px;text-align:center}a{color:#0e6d94}</style>
</head>
<body><main>
<h1 style="font-size:1.3rem">登入沒有成功</h1>
<p>可能是登入頁停留太久，或連結已經用過了。請再試一次。</p>
<p><a href="/login.php">重新登入</a>　・　<a href="/">回書目瀏覽</a></p>
</main></body>
</html>
