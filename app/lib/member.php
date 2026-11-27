<?php
declare(strict_types=1);

/**
 * 書目網會員（M2-1；Asana 1218124736678630）—— 2026-11-27 小芯
 * 熊叔叔 11/27 TG：登入「甲 台灣聖經網 SSO」；資料「甲 照建議」＝只存 SSO 編號、暱稱、頭像，不存 Email、密碼，
 * 公開只顯示暱稱和頭像，刪帳號時書庫和短評一起刪（書庫、短評表之後用外鍵 ON DELETE CASCADE 接 members）。
 *
 * session：名稱 books_member、path=/、HttpOnly、HTTPS 時 Secure、SameSite=Lax（台灣聖經網導回來是跨站的頂層 GET，Strict 會帶不到 cookie）。
 * 登入 CSRF：台灣聖經網回來的 state 是它自己的，本站比對不到 → 改成「只有本站 login.php 剛開始的登入（10 分鐘內）才收 token」，
 *   別人寄一個帶他自己 token 的回呼網址給你，你沒按過本站「登入」就不會被登成他。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/twsso.php';

const MEMBER_LOGIN_TTL = 600;   // 按「登入」後 10 分鐘內要回來

function member_https(): bool
{
    return ($_SERVER['HTTPS'] ?? '') === 'on' || str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https');
}

function member_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('books_member');
    session_set_cookie_params(['path' => '/', 'secure' => member_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

/** 本站網址（scheme＋host）；host 一定要在 TWSSO_CLIENTS 裡，不然回 null（不信任隨便的 Host 標頭） */
function member_origin(): ?string
{
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    return twsso_client_id($host) !== null ? 'https://' . $host : null;
}

/** 登入後要回去的本站路徑：只收 / 開頭、不是 // 開頭的相對路徑，其他一律回首頁 */
function member_safe_return(?string $r): string
{
    $r = (string) $r;
    return (preg_match('#^/(?![/\\\\])[^\s]*$#', $r) && strlen($r) <= 300) ? $r : '/';
}

function member_current(): ?array
{
    member_session();
    $id = (int) ($_SESSION['member_id'] ?? 0);
    if ($id <= 0) return null;
    $st = db()->prepare('SELECT member_id, name, avatar_url, created_at, last_login_at FROM members WHERE member_id = :id');
    $st->execute([':id' => $id]);
    $m = $st->fetch();
    if (!$m) {   // 帳號已刪
        unset($_SESSION['member_id']);
        return null;
    }
    return $m;
}

/** token 防重放：同一張只收一次（雜湊記到 exp，順手清過期的） */
function member_sso_seen(string $hash, int $exp): bool
{
    $pdo = db();
    $pdo->prepare('DELETE FROM sso_seen WHERE exp < :now')->execute([':now' => time()]);
    $st = $pdo->prepare('INSERT IGNORE INTO sso_seen (token_hash, exp) VALUES (:h, :e)');
    $st->execute([':h' => $hash, ':e' => $exp]);
    return $st->rowCount() === 1;
}

/** 頭像只收 https 網址，太長或怪的不存 */
function member_clean_avatar($u): ?string
{
    $u = is_string($u) ? trim($u) : '';
    return ($u !== '' && strlen($u) <= 500 && preg_match('#^https://[^\s"<>]+$#i', $u)) ? $u : null;
}

/**
 * 處理台灣聖經網回呼：成功回 member_id；失敗回 null，$why 寫原因（只進 log）。
 * $pubkey 只給測試用（正式一律用寫死的公鑰）。
 */
function member_handle_callback(string $token, int $clientId, int $now, ?string &$why = null, string $pubkey = TWSSO_PUBLIC_KEY): ?int
{
    $pending = (int) ($_SESSION['sso_pending_until'] ?? 0);
    unset($_SESSION['sso_pending_until']);
    if ($pending < $now) { $why = 'not-pending'; return null; }
    $r = twsso_verify($token, $clientId, $now, 'member_sso_seen', $pubkey);
    if (!$r['ok']) { $why = $r['why']; return null; }
    $c = $r['claims'];
    $name = mb_substr(trim((string) ($c['Name'] ?? '')), 0, 100, 'UTF-8');
    if ($name === '') $name = '會員';
    $st = db()->prepare(
        'INSERT INTO members (sso_id, name, avatar_url, created_at, last_login_at)
         VALUES (:sid, :n, :a, NOW(), NOW())
         ON DUPLICATE KEY UPDATE name = VALUES(name), avatar_url = VALUES(avatar_url), last_login_at = NOW()');
    $st->execute([':sid' => (int) $c['ID'], ':n' => $name, ':a' => member_clean_avatar($c['Avatar'] ?? null)]);
    $st = db()->prepare('SELECT member_id FROM members WHERE sso_id = :sid');
    $st->execute([':sid' => (int) $c['ID']]);
    $id = (int) $st->fetchColumn();
    session_regenerate_id(true);
    $_SESSION['member_id'] = $id;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $id;
}

function member_check_csrf(): bool
{
    member_session();
    $t = (string) ($_POST['csrf'] ?? '');
    return $t !== '' && hash_equals((string) ($_SESSION['csrf'] ?? ''), $t);
}

function member_logout(): void
{
    member_session();
    $_SESSION = [];
    session_regenerate_id(true);
}

/** 刪帳號：刪 members 那一列（書庫、短評之後用外鍵一起刪），再登出 */
function member_delete(int $id): bool
{
    $st = db()->prepare('DELETE FROM members WHERE member_id = :id');
    $st->execute([':id' => $id]);
    member_logout();
    return $st->rowCount() === 1;
}

function member_e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
