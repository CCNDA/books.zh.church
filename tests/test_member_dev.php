<?php
declare(strict_types=1);
// 書目網會員登入流程測試（books_dev；用測試金鑰簽 token，不碰台灣聖經網）。用法：php tests/test_member_dev.php
// 只動 sso_id 900000001～900000003 這幾個測試會員，跑完刪掉。
ob_start();   // 先緩衝輸出，session_regenerate_id 才不會因為「已送出標頭」失敗
require dirname(__DIR__) . '/app/lib/member.php';
$ok = $bad = 0;
function t(string $n, bool $c, string $i = ''): void { global $ok, $bad; if ($c) { $ok++; echo "  ✓ {$n}\n"; } else { $bad++; echo "  ✗ {$n} {$i}\n"; } }
$db = (string) db()->query('SELECT DATABASE()')->fetchColumn();
if ($db !== 'books_dev') { echo "不是 books_dev（{$db}），停\n"; exit(2); }
$cleanup = fn () => db()->exec('DELETE FROM members WHERE sso_id BETWEEN 900000001 AND 900000003');
$cleanup();

$k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$pub = openssl_pkey_get_details($k)['key'];
$b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
function der2jose(string $der): string { $o = 2; $out = ''; for ($i = 0; $i < 2; $i++) { $len = ord($der[$o + 1]); $v = ltrim(substr($der, $o + 2, $len), "\x00"); $out .= str_pad($v, 32, "\x00", STR_PAD_LEFT); $o += 2 + $len; } return $out; }
$now = time();
$mk = function (array $c) use ($k, $b64): string { $h = $b64(json_encode(['alg' => 'ES256', 'typ' => 'JWT'])); $p = $b64(json_encode($c)); openssl_sign("$h.$p", $der, $k, OPENSSL_ALGO_SHA256); return "$h.$p." . $b64(der2jose($der)); };
$claims = fn (array $o = []) => $o + ['ID' => 900000001, 'Username' => 'u1', 'Name' => '測試會員甲', 'Avatar' => 'https://example.invalid/a.png', 'iss' => 'taiwanbible', 'iat' => $now - 5, 'exp' => $now + 3600, 'aud' => 7, 'ev' => 1];

session_id('test' . bin2hex(random_bytes(8)));
member_session();

// 1 沒按過「登入」就回呼 → 擋
$why = null; $id = member_handle_callback($mk($claims(['n' => 1])), 7, $now, $why, $pub);
t('沒有登入進行中 → 擋', $id === null && $why === 'not-pending', (string) $why);

// 2 正常登入：建會員、session 記 member_id、存的欄位對
$_SESSION['sso_pending_until'] = $now + 600;
$tok = $mk($claims());
$id = member_handle_callback($tok, 7, $now, $why, $pub);
$row = $id ? db()->query("SELECT * FROM members WHERE member_id = {$id}")->fetch() : null;
t('正常登入建會員', $id !== null && (int) ($_SESSION['member_id'] ?? 0) === $id, (string) $why);
t('只存編號、暱稱、頭像', $row && (int) $row['sso_id'] === 900000001 && $row['name'] === '測試會員甲' && $row['avatar_url'] === 'https://example.invalid/a.png' && !array_key_exists('email', $row));
t('登入進行中用掉了', !isset($_SESSION['sso_pending_until']));

// 3 同一張 token 再來一次 → replay
$_SESSION['sso_pending_until'] = $now + 600;
$id2 = member_handle_callback($tok, 7, $now, $why, $pub);
t('同一張 token 第二次 → replay', $id2 === null && $why === 'replay', (string) $why);

// 4 給別站（aud 6＝正式）的 token 拿到測試站 → aud
$_SESSION['sso_pending_until'] = $now + 600;
$id3 = member_handle_callback($mk($claims(['aud' => 6, 'n' => 2])), 7, $now, $why, $pub);
t('aud 不是本站 → 擋', $id3 === null && $why === 'aud', (string) $why);

// 5 同一人再登入：不重複建、暱稱更新、頭像非 https 不存
$_SESSION['sso_pending_until'] = $now + 600;
$id4 = member_handle_callback($mk($claims(['Name' => '改名了', 'Avatar' => 'javascript:alert(1)', 'n' => 3])), 7, $now, $why, $pub);
$row = db()->query('SELECT COUNT(*) c, MAX(name) n, MAX(avatar_url) a FROM members WHERE sso_id = 900000001')->fetch();
t('同一人不重複建、暱稱更新', $id4 === $id && (int) $row['c'] === 1 && $row['n'] === '改名了', json_encode($row, JSON_UNESCAPED_UNICODE));
t('頭像不是 https 不存', $row['a'] === null);

// 6 登入進行中過期 → 擋
$_SESSION['sso_pending_until'] = $now - 1;
$id5 = member_handle_callback($mk($claims(['ID' => 900000002, 'n' => 4])), 7, $now, $why, $pub);
t('登入進行中過期 → 擋', $id5 === null && $why === 'not-pending', (string) $why);

// 7 回去的網址只收站內路徑
t('safe_return 擋外站', member_safe_return('https://evil.example/') === '/' && member_safe_return('//evil.example') === '/' && member_safe_return('/\\evil') === '/' && member_safe_return('/me.php') === '/me.php');

// 8 member_current、刪帳號
$_SESSION['member_id'] = $id;
$cur = member_current();
t('member_current 讀得到', $cur && (int) $cur['member_id'] === $id);
t('刪帳號', member_delete($id) && (int) db()->query('SELECT COUNT(*) FROM members WHERE sso_id = 900000001')->fetchColumn() === 0 && empty($_SESSION['member_id']));

$cleanup();
db()->exec("DELETE FROM sso_seen WHERE exp <= " . ($now + 3600));
echo "\n" . ($bad ? "失敗 {$bad} 項" : "全綠：{$ok} 項通過") . "\n";
exit($bad ? 1 : 0);
