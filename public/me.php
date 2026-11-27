<?php
declare(strict_types=1);
// 我的帳號：暱稱、頭像、登出、刪除帳號（M2-1）。登出、刪除都只收 POST＋CSRF。
require dirname(__DIR__) . '/app/lib/member.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header("Content-Security-Policy: default-src 'none'; img-src 'self' https: data:; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
member_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!member_check_csrf()) {
        http_response_code(400);
        echo '表單過期了，請重新整理再試一次';
        exit;
    }
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'logout') {
        member_logout();
        header('Location: /', true, 303);
        exit;
    }
    if ($act === 'delete') {
        $m = member_current();
        if ($m && ($_POST['confirm'] ?? '') === 'yes') {
            member_delete((int) $m['member_id']);
            header('Location: /?deleted=1', true, 303);
            exit;
        }
        header('Location: /me.php?need_confirm=1', true, 303);
        exit;
    }
    header('Location: /me.php', true, 303);
    exit;
}

$m = member_current();
if (!$m) {
    header('Location: /login.php?r=' . rawurlencode('/me.php'), true, 302);
    exit;
}
$csrf = (string) $_SESSION['csrf'];
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>我的帳號｜屬靈共同書目</title>
<style>
:root{--paper:#f2f6f9;--card:#fff;--ink:#14303c;--ink-soft:#5c7482;--gold:#0e6d94;--line:#e2e8ee;--accent:#177da8;--danger:#b42318}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif;line-height:1.7}
main{max-width:640px;margin:40px auto;padding:0 20px}
a{color:var(--gold)}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:20px 22px;margin-top:16px}
.who{display:flex;align-items:center;gap:14px}
.who img,.who .ph{width:56px;height:56px;border-radius:50%;object-fit:cover;background:#2f8fb8;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.4rem}
h1{font-size:1.4rem;margin:0}
.note{color:var(--ink-soft);font-size:.9rem}
button{font:inherit;padding:8px 18px;border-radius:8px;cursor:pointer;border:1px solid var(--accent);background:var(--accent);color:#fff}
button.danger{background:#fff;color:var(--danger);border-color:var(--danger)}
label{display:block;margin:10px 0}
:focus-visible{outline:3px solid #f0c04a;outline-offset:2px}
.warn{color:var(--danger)}
</style>
</head>
<body>
<main>
<p><a href="/">← 回書目瀏覽</a></p>
<section class="card who" aria-label="我的資料">
  <?php if ($m['avatar_url']): ?><img src="<?= member_e($m['avatar_url']) ?>" alt=""><?php else: ?><div class="ph" aria-hidden="true"><?= member_e(mb_substr((string) $m['name'], 0, 1, 'UTF-8')) ?></div><?php endif; ?>
  <div><h1><?= member_e($m['name']) ?></h1><div class="note">用台灣聖經網帳號登入。暱稱和頭像跟著台灣聖經網，下次登入時更新。</div></div>
</section>
<section class="card" aria-labelledby="hPriv">
  <h2 id="hPriv" style="font-size:1.05rem;margin:0 0 6px">本站存了什麼</h2>
  <p class="note" style="margin:0">只存台灣聖經網給的會員編號、暱稱、頭像網址，以及第一次和最近一次登入時間；不存 Email、不存密碼。</p>
</section>
<section class="card">
  <form method="post" action="/me.php"><input type="hidden" name="csrf" value="<?= member_e($csrf) ?>"><input type="hidden" name="act" value="logout"><button type="submit">登出</button></form>
</section>
<section class="card" aria-labelledby="hDel">
  <h2 id="hDel" style="font-size:1.05rem;margin:0 0 6px">刪除帳號</h2>
  <p class="note" style="margin:0">刪除後，你在本站的資料（之後的「我的書庫」、評分和短評）會一起刪掉，無法復原。台灣聖經網的帳號不受影響。</p>
  <?php if (isset($_GET['need_confirm'])): ?><p class="warn" role="alert">要先勾選「我確定要刪除」。</p><?php endif; ?>
  <form method="post" action="/me.php">
    <input type="hidden" name="csrf" value="<?= member_e($csrf) ?>"><input type="hidden" name="act" value="delete">
    <label><input type="checkbox" name="confirm" value="yes"> 我確定要刪除</label>
    <button type="submit" class="danger">刪除我的帳號</button>
  </form>
</section>
</main>
</body>
</html>
