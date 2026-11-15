<?php
declare(strict_types=1);

/**
 * 後台：簡繁版本對照確認（Asana 1220314421365210 簡繁互連②；母票 1218475436576276）。
 *
 * 熊哥 2026-09-14：「合併，但是簡體繁體，在詳細內容頁可以註明有繁體和簡體版；如果 ISBN 編碼不一樣，應該就要拆開了」
 * 熊哥 2026-11-07 TG 選「甲 對照表＋後台確認」：自動比對約八成準，在後台一組一組確認，
 *   確認過（decision=same）的才在書目頁顯示「另有簡體／正體版」（簡繁互連③）。
 *
 * 畫面：左邊簡體那本、右邊正體那本，三個按鈕：是同一本的簡繁版／不是／看不出來；判過的可以在「已判」改判。
 * 另有「手動加一組」：自動規則配不到的（例如正體版書名多了副標），輸入兩本的編號直接記成「是」。
 * 資料：tools/load_edition_pairs.php 把自動比對結果匯進 edition_pairs（簡繁互連①）。
 * 登入、CSRF、版型、無障礙寫法照 public/admin/dup.php。
 */

require dirname(__DIR__, 2) . '/app/lib/db.php';
require dirname(__DIR__, 2) . '/app/lib/cf_access.php';
require dirname(__DIR__, 2) . '/app/lib/release.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');     // 理由見 index.php（no-referrer 會讓表單 Origin 變 "null"）
header("Content-Security-Policy: default-src 'none'; img-src 'self' https: data:; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

const ED_DECISION = ['same' => '是同一本的簡繁版', 'different' => '不是', 'unsure' => '看不出來'];
const PAGE_SIZE   = 20;

function e(mixed $s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** 只輸出 http(s) 連結。 */
function safe_url(?string $u): ?string
{
    return ($u !== null && preg_match('#^https?://#i', $u)) ? $u : null;
}

function deny_page(int $code, string $msg): never
{
    http_response_code($code);
    echo '<!DOCTYPE html><meta charset="utf-8"><title>後台</title><p style="font:16px sans-serif;margin:2em">'
        . e($msg) . '</p>';
    exit;
}

/** 判定完導回原分頁，帶一句結果（只帶白名單內的值）。 */
function back_to(string $tab, int $page, string $msgKey, int $id = 0, array $prefill = []): never
{
    $q = '?tab=' . $tab . '&p=' . max(1, $page) . '&ok=' . $msgKey . '&id=' . $id;
    if ($prefill) {
        $q .= '&ps=' . (int) $prefill[0] . '&pt=' . (int) $prefill[1];
    }
    header('Location: ' . $q, true, 303);
    exit;
}

/** 看起來是簡體版：language 寫簡體，或書名帶「簡體／简体」（跟 tools/load_edition_pairs.php 的第一道判斷一樣）。 */
function looks_simplified(?string $lang, ?string $title): bool
{
    return (bool) preg_match('/簡|简/u', (string) $lang) || (bool) preg_match('/簡體|简体/u', (string) $title);
}

// ── 登入（同 dup.php）─────────────────────────────────
$cfg = app_config()['admin'] ?? [];
$why = null;
$user = cf_access_user($cfg, $why);
if ($user === null) {
    deny_page(403, '無法進入後台：' . $why);
}
$me = $user['email'];

$https = ($_SERVER['HTTPS'] ?? '') === 'on' || str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https');
session_name('books_admin');
session_set_cookie_params(['path' => '/admin/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (($_SESSION['email'] ?? '') !== $me) {
    session_regenerate_id(true);
    $_SESSION = ['email' => $me, 'csrf' => bin2hex(random_bytes(32))];
}

$pdo = db();
$pdo->exec("SET time_zone = '+08:00'");      // 判定時間用台北時間寫入、顯示（同 dup.php）
$hasTable = (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'edition_pairs'"
)->fetchColumn();
if (!$hasTable) {
    deny_page(503, '找不到 edition_pairs，請先執行 migration 2026-11-07_edition_pairs.sql');
}

// ── 動作（POST → 導回，避免重新整理又送一次）──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))
        || ($origin !== '' && !in_array($origin, ["https://{$host}", "http://{$host}"], true))) {
        deny_page(400, '表單已過期，請回上一頁重新整理後再按一次');
    }
    $back = in_array($_POST['back'] ?? '', ['unsure', 'done'], true) ? $_POST['back'] : 'pending';
    $page = (int) ($_POST['p'] ?? 1);
    $action = (string) ($_POST['action'] ?? 'decide');

    if ($action === 'decide') {
        $pid = (int) ($_POST['pair_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        if ($pid <= 0 || !isset(ED_DECISION[$decision])) {
            deny_page(400, '參數不對');
        }
        $up = $pdo->prepare('UPDATE edition_pairs SET decision = :d, decided_by = :by, decided_at = NOW() WHERE pair_id = :id');
        $up->execute([':d' => $decision, ':by' => $me, ':id' => $pid]);
        $exists = $pdo->prepare('SELECT 1 FROM edition_pairs WHERE pair_id = ?');
        $exists->execute([$pid]);
        if (!$exists->fetchColumn()) {
            deny_page(404, "找不到第 {$pid} 組");
        }
        back_to($back, $page, 'd_' . $decision, $pid);
    }

    if ($action === 'add') {
        $s = (int) ($_POST['simp_book_id'] ?? 0);
        $t = (int) ($_POST['trad_book_id'] ?? 0);
        if ($s <= 0 || $t <= 0 || $s === $t) {
            back_to($back, $page, 'add_bad');
        }
        $chk = $pdo->prepare('SELECT book_id, language, title FROM books WHERE book_id IN (?, ?)');
        $chk->execute([$s, $t]);
        $bk = [];
        foreach ($chk->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bk[(int) $r['book_id']] = $r;
        }
        if (count($bk) !== 2) {
            back_to($back, $page, 'add_nobook');
        }
        // 兩個方向都查：同一組（或被填反的同一組）已經在表上就不重複加
        $dup = $pdo->prepare('SELECT pair_id FROM edition_pairs WHERE (simp_book_id = ? AND trad_book_id = ?) OR (simp_book_id = ? AND trad_book_id = ?)');
        $dup->execute([$s, $t, $t, $s]);
        if ($old = (int) $dup->fetchColumn()) {
            back_to($back, $page, 'add_exists', $old);
        }
        // 填反了會讓書目頁把「簡體版／正體版」標反（小布 11/15 審查）：看起來反了就先不寫，表單幫忙對調好
        if (empty($_POST['sure_order'])
            && looks_simplified($bk[$t]['language'], $bk[$t]['title'])
            && ! looks_simplified($bk[$s]['language'], $bk[$s]['title'])) {
            back_to($back, $page, 'add_reversed', 0, [$t, $s]);
        }
        $ins = $pdo->prepare("INSERT INTO edition_pairs (simp_book_id, trad_book_id, match_how, same_ratio, pub_how, decision, decided_by, decided_at)
                              VALUES (?, ?, '人工加入', NULL, NULL, 'same', ?, NOW())");
        $ins->execute([$s, $t, $me]);
        back_to('done', 1, 'add_ok', (int) $pdo->lastInsertId());
    }
    deny_page(400, '參數不對');
}

// ── 列表 ─────────────────────────────────────────────
$tab = in_array($_GET['tab'] ?? '', ['unsure', 'done'], true) ? $_GET['tab'] : 'pending';
$page = max(1, (int) ($_GET['p'] ?? 1));
$where = ['pending' => 'p.decision IS NULL', 'unsure' => "p.decision = 'unsure'", 'done' => "p.decision IN ('same','different')"][$tab];
$order = $tab === 'done' ? 'p.decided_at DESC, p.pair_id DESC' : 'p.same_ratio DESC, p.pair_id';

$counts = $pdo->query(
    "SELECT SUM(decision IS NULL) AS pending, SUM(decision = 'unsure') AS unsure,
            SUM(decision IN ('same','different')) AS done, SUM(decision = 'same') AS same FROM edition_pairs"
)->fetch();
$total = (int) $pdo->query("SELECT COUNT(*) FROM edition_pairs p WHERE {$where}")->fetchColumn();
$pages = max(1, (int) ceil($total / PAGE_SIZE));
$page = min($page, $pages);

$cols = 'book_id, title, subtitle, author, translator, publisher, publish_date, isbn13, language, cover_url, is_published';
$rows = $pdo->query(
    "SELECT p.*, " . implode(', ', array_map(fn($c) => "s.{$c} AS s_{$c}", explode(', ', $cols))) . ', '
    . implode(', ', array_map(fn($c) => "t.{$c} AS t_{$c}", explode(', ', $cols))) . "
       FROM edition_pairs p
       LEFT JOIN books s ON s.book_id = p.simp_book_id
       LEFT JOIN books t ON t.book_id = p.trad_book_id
      WHERE {$where} ORDER BY {$order} LIMIT " . PAGE_SIZE . ' OFFSET ' . (($page - 1) * PAGE_SIZE)
)->fetchAll(PDO::FETCH_ASSOC);

$flashText = [
    'd_same' => '已記錄：是同一本的簡繁版', 'd_different' => '已記錄：不是', 'd_unsure' => '已記錄：看不出來',
    'add_ok' => '已加入，記成「是同一本的簡繁版」', 'add_exists' => '這兩本已經在表上了（第 %d 組），請在列表裡判',
    'add_bad' => '兩個編號都要填，而且不能一樣', 'add_nobook' => '找不到這個書目編號，請再確認',
    'add_reversed' => '看起來填反了：正體欄那本像簡體版、簡體欄那本不像。下面已經對調好，確認後再按一次；方向其實沒錯的話，勾「方向沒錯」再送',
];
$flash = null;
$flashBad = false;
if (isset($_GET['ok'], $flashText[$_GET['ok']])) {
    $flash = sprintf($flashText[$_GET['ok']], (int) ($_GET['id'] ?? 0));
    $flashBad = in_array($_GET['ok'], ['add_bad', 'add_nobook', 'add_exists', 'add_reversed'], true);
    if (str_starts_with($_GET['ok'], 'd_')) {
        $flash = '第 ' . (int) ($_GET['id'] ?? 0) . ' 組' . $flash;
    }
}

/** 一本書的卡片（左簡右正共用）。 */
function book_side(array $r, string $p, string $label, string $tagHtml = ''): string
{
    $id = $p === 's' ? (int) $r['simp_book_id'] : (int) $r['trad_book_id'];
    $title = $r["{$p}_title"];
    $cover = safe_url($r["{$p}_cover_url"]);
    $pub = $r["{$p}_is_published"];
    ob_start(); ?>
    <div class="side">
      <div><?php if ($cover): ?><img src="<?= e($cover) ?>" alt="" loading="lazy"><?php else: ?><div class="noimg"></div><?php endif; ?></div>
      <div>
        <div class="lbl"><?= e($label) ?>・編號 <?= $id ?></div>
        <h3><?= e($title ?? '（這本已經不在站上）') ?><?php if ($r["{$p}_subtitle"]): ?> <small><?= e($r["{$p}_subtitle"]) ?></small><?php endif; ?></h3>
        <?= $tagHtml ?>
        <?php if ($pub !== null && (int) $pub === 0): ?><span class="tag pub0">已下架</span><?php endif; ?>
        <dl>
          <dt>作者</dt><dd><?= e($r["{$p}_author"] ?: '—') ?><?= $r["{$p}_translator"] ? '；譯者 ' . e($r["{$p}_translator"]) : '' ?></dd>
          <dt>出版</dt><dd><?= e($r["{$p}_publisher"] ?: '—') ?><?= $r["{$p}_publish_date"] ? '・' . e($r["{$p}_publish_date"]) : '' ?></dd>
          <dt>ISBN</dt><dd><?= e($r["{$p}_isbn13"] ?: '—') ?></dd>
          <dt>語言</dt><dd><?= e($r["{$p}_language"] ?: '—') ?></dd>
        </dl>
        <?php if ($title !== null && (int) $pub === 1): ?><a href="/book/<?= $id ?>" target="_blank" rel="noopener">站上書目頁 ↗</a><?php endif; ?>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>簡繁對照確認｜屬靈共同書目後台</title>
<link rel="icon" type="image/svg+xml" href="/assets/logo-books.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+TC:wght@500;700&family=Noto+Sans+TC:wght@400;500;700&display=swap" rel="stylesheet">
<style>
/* 色票與版型同 public/admin/dup.php；多「手動加一組」表單。 */
:root{
  --paper:#f2f6f9; --card:#ffffff; --ink:#14303c; --ink-soft:#5c7482;
  --gold:#0e6d94; --gold-soft:#f0c04a; --line:#e2e8ee; --accent:#177da8;
  --danger:#b42318; --warn:#b54708; --ok:#067647;
}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);
  font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif;line-height:1.8}
a{color:var(--gold);text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;text-decoration-color:rgba(14,109,148,.4)}
.serif{font-family:"Noto Serif TC","PMingLiU",serif}
header{border-bottom:3px double var(--gold-soft);background:var(--card)}
.hd-inner{max-width:1100px;margin:0 auto;padding:20px 20px 14px;
  display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap}
.hd-title{margin:0;font-size:1.35rem;letter-spacing:.12em}
.hd-title a{color:var(--ink);display:inline-flex;align-items:center;gap:.4em;text-decoration:none}
.hd-title small{font-size:.8rem;letter-spacing:.06em;color:var(--ink-soft);font-weight:500}
.brand-logo{height:34px;width:34px;flex:0 0 auto}
.hd-nav{font-size:.88rem;color:var(--ink-soft)}
main{max-width:1100px;margin:28px auto 0;padding:0 20px}
.tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px;border-bottom:1px solid var(--line)}
.tabs a{padding:6px 14px;border:1px solid transparent;border-bottom:none;border-radius:8px 8px 0 0;color:var(--ink-soft);text-decoration:none}
.tabs a.on{background:var(--card);border-color:var(--line);color:var(--ink);font-weight:700;margin-bottom:-1px}
.note{color:var(--ink-soft);font-size:.92rem;margin:0 0 14px}
.flash{background:var(--card);border:1px solid var(--line);border-left:4px solid var(--ok);border-radius:6px;padding:8px 12px;margin-bottom:14px}
.flash.bad{border-left-color:var(--danger)}
.group{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px 18px;margin-bottom:18px}
.pair{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.side{display:grid;grid-template-columns:72px 1fr;gap:12px}
.side img{width:72px;height:auto;border-radius:4px;background:var(--line)}
.noimg{width:72px;height:96px;border-radius:4px;background:var(--line)}
h3{font-size:.95rem;margin:0 0 4px;line-height:1.5}
.lbl{font-size:.78rem;color:var(--ink-soft);letter-spacing:.06em}
.tag{display:inline-block;font-size:.76rem;padding:0 9px;border-radius:10px;border:1px solid currentColor;margin:0 6px 2px 0}
.weak{color:var(--warn)} .strong{color:var(--ok)} .pub0{color:var(--ink-soft)}
dl{display:grid;grid-template-columns:auto 1fr;gap:0 12px;margin:6px 0;font-size:.88rem}
dt{color:var(--ink-soft)} dd{margin:0;overflow-wrap:anywhere}
.act{grid-column:1 / -1;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.act button,.addform button{font:inherit;font-size:.9rem;padding:5px 16px;border-radius:6px;border:1px solid var(--line);background:var(--card);color:var(--ink);cursor:pointer}
.act .same{border-color:var(--ok);color:var(--ok)} .act .diff{border-color:var(--danger);color:var(--danger)}
.act button:hover,.addform button:hover{background:var(--paper)}
.act button[aria-pressed="true"]{background:var(--paper);font-weight:700}
.decided{font-size:.82rem;color:var(--ink-soft)}
details.add{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:10px 18px;margin-bottom:18px}
details.add summary{cursor:pointer;font-weight:500}
.addform{display:flex;flex-wrap:wrap;gap:10px 16px;align-items:end;margin:10px 0 4px}
.addform label{display:flex;flex-direction:column;font-size:.85rem;color:var(--ink-soft)}
.addform input{font:inherit;width:9em;padding:4px 8px;border:1px solid var(--line);border-radius:6px;color:var(--ink)}
.pager a,.pager span{margin-right:10px}
footer{margin-top:64px;border-top:1px solid var(--line);background:var(--card)}
.ft{max-width:1100px;margin:0 auto;padding:22px 20px;font-size:.82rem;color:var(--ink-soft)}
@media (max-width:720px){ .pair{grid-template-columns:1fr} }
.skip{position:absolute;left:-9999px;top:8px;z-index:100;background:#14303c;color:#fff;padding:8px 14px;border-radius:6px;text-decoration:none}
.skip:focus{left:12px}
:focus-visible{outline:3px solid #f0c04a;outline-offset:2px}
</style>
</head>
<body>
<a class="skip" href="#main">跳到主要內容</a>
<header>
  <div class="hd-inner">
    <h1 class="hd-title serif"><a href="/admin/"><img class="brand-logo" src="/assets/logo-books.svg" alt="" aria-hidden="true">屬靈共同書目 <small>後台</small></a></h1>
    <nav class="hd-nav" aria-label="後台頁面"><a href="/admin/">書目審核</a> ・ <a href="/admin/dup.php">重複書複核</a> ・ <a href="/" target="_blank" rel="noopener">回前台 ↗</a> ・ <?= e($me) ?></nav>
  </div>
</header>
<main id="main">
<h2 class="serif" style="font-size:1.2rem;margin:0 0 10px">簡繁對照確認</h2>
<nav class="tabs" aria-label="確認分頁">
  <a href="?tab=pending" class="<?= $tab === 'pending' ? 'on' : '' ?>"<?= $tab === 'pending' ? ' aria-current="page"' : '' ?>>待判（<?= (int) $counts['pending'] ?>）</a>
  <a href="?tab=unsure" class="<?= $tab === 'unsure' ? 'on' : '' ?>"<?= $tab === 'unsure' ? ' aria-current="page"' : '' ?>>看不出來（<?= (int) $counts['unsure'] ?>）</a>
  <a href="?tab=done" class="<?= $tab === 'done' ? 'on' : '' ?>"<?= $tab === 'done' ? ' aria-current="page"' : '' ?>>已判（<?= (int) $counts['done'] ?>，其中「是」<?= (int) $counts['same'] ?>）</a>
</nav>
<?php if ($flash): ?><div class="flash<?= $flashBad ? ' bad' : '' ?>" role="status"><?= e($flash) ?></div><?php endif; ?>
<p class="note">左邊是簡體那本、右邊是正體那本（書名與出版社比對出來的，大約八成準）。按「是同一本的簡繁版」的，
書目頁才會顯示「本書另有簡體／正體版」；按「不是」的不會顯示。<span class="tag weak">書名部分相同</span>的請多看一眼。</p>

<details class="add"<?= in_array($_GET['ok'] ?? '', ['add_bad', 'add_nobook', 'add_exists', 'add_reversed'], true) ? ' open' : '' ?>>
  <summary>手動加一組（自動比對沒配到的）</summary>
  <form method="post" class="addform">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="back" value="<?= $tab ?>">
    <input type="hidden" name="p" value="<?= $page ?>">
    <label>簡體那本的編號<input name="simp_book_id" inputmode="numeric" pattern="[0-9]+" required<?= ($ps = (int) ($_GET['ps'] ?? 0)) > 0 ? ' value="' . $ps . '"' : '' ?>></label>
    <label>正體那本的編號<input name="trad_book_id" inputmode="numeric" pattern="[0-9]+" required<?= ($pt = (int) ($_GET['pt'] ?? 0)) > 0 ? ' value="' . $pt . '"' : '' ?>></label>
    <label style="flex-direction:row;align-items:center;gap:6px"><input type="checkbox" name="sure_order" value="1" style="width:auto">方向沒錯（不要檢查簡繁）</label>
    <button type="submit">加入，記成「是」</button>
  </form>
  <p class="note" style="margin:0">編號就是書目頁網址 /book/ 後面的數字。</p>
</details>

<?php if (!$rows): ?><p><?= $tab === 'pending' ? '沒有待判的了 🎉' : '這一頁沒有資料' ?></p><?php endif; ?>

<?php foreach ($rows as $r):
    $pid = (int) $r['pair_id'];
    $ratio = $r['same_ratio'] !== null ? (float) $r['same_ratio'] : null;
    $weak = $ratio !== null && $ratio < 1.0;
    $tags = '<span class="tag ' . ($weak ? 'weak' : 'strong') . '">' . e($r['match_how'] ?: '—') . '</span>'
        . ($r['pub_how'] ? '<span class="tag strong">' . e($r['pub_how']) . '</span>' : '');
?>
<section class="group" aria-label="第 <?= $pid ?> 組">
  <div class="pair" id="r<?= $pid ?>">
    <?= book_side($r, 's', '簡體') ?>
    <?= book_side($r, 't', '正體', $tags) ?>
    <form method="post" class="act" aria-label="判定第 <?= $pid ?> 組：<?= e($r['s_title'] ?? '') ?>／<?= e($r['t_title'] ?? '') ?>">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="decide">
      <input type="hidden" name="pair_id" value="<?= $pid ?>">
      <input type="hidden" name="back" value="<?= $tab ?>">
      <input type="hidden" name="p" value="<?= $page ?>">
      <?php foreach (ED_DECISION as $dv => $dl): ?>
        <button name="decision" value="<?= $dv ?>" class="<?= $dv === 'same' ? 'same' : ($dv === 'different' ? 'diff' : '') ?>"
          aria-pressed="<?= $r['decision'] === $dv ? 'true' : 'false' ?>"><?= e($dl) ?></button>
      <?php endforeach; ?>
      <?php if ($r['decided_at']): ?><span class="decided"><?= e(ED_DECISION[$r['decision']] ?? '') ?>・<?= e($r['decided_by']) ?>・<?= e($r['decided_at']) ?></span><?php endif; ?>
    </form>
  </div>
</section>
<?php endforeach; ?>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="分頁">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <?= $i === $page ? '<span aria-current="page">' . $i . '</span>' : '<a href="?tab=' . $tab . '&p=' . $i . '">' . $i . '</a>' ?>
  <?php endfor; ?>
</nav>
<?php endif; ?>
</main>
<footer><div class="ft"><a href="/changelog.php" target="_blank" rel="noopener"><?= ($v = app_version()) !== '' ? 'v' . e($v) : '版本資訊' ?></a></div></footer>
</body>
</html>
