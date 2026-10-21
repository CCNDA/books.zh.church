<?php
declare(strict_types=1);

/**
 * 後台：重複書複核（Asana 1219668289655146）。
 *
 * 熊哥 2026-10-20 TG「應該是用後台處理才對」：bappress 疑似重複清單在後台一筆一筆判，不用 TSV。
 * 熊哥 2026-10-21 裁示：
 *   dup-import-A：按下去**只記錄**，不匯入、不合併；之後小芯用工具在開發庫演練、熊哥一次補匯入。
 *   dup-who-A：先只開給熊哥——沿用 /admin/ 同一組 Cloudflare Access＋JWT（app/lib/cf_access.php）。
 *   dup-scope-A：先做 bappress；dup_review.list_name 可以再加別的清單。
 *
 * 畫面：左邊「來源那筆」（還沒匯進站上的書房商品），右邊「站上那本」（書名比對到的候選），
 *   三個按鈕：同一本（不匯入）／不同書（之後補匯入）／看不出來（先跳過）。判過的可以在「已判」改判。
 * 資料：tools/load_dup_review.php 把 check_new_books.php 的對照表匯進 dup_review。
 * 版型、登入、CSRF、無障礙寫法照 public/admin/index.php。
 */

require dirname(__DIR__, 2) . '/app/lib/db.php';
require dirname(__DIR__, 2) . '/app/lib/cf_access.php';
require dirname(__DIR__, 2) . '/app/lib/release.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');     // 理由見 index.php（no-referrer 會讓表單 Origin 變 "null"）
header("Content-Security-Policy: default-src 'none'; img-src 'self' https: data:; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

const DUP_SOURCES  = ['bappress' => '浸信會出版社'];
const DUP_DECISION = ['same' => '同一本（不匯入）', 'different' => '不同書（之後補匯入）', 'unsure' => '看不出來'];
const GROUP_SIZE   = 20;

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

// ── 登入（同 index.php）─────────────────────────────────
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
$pdo->exec("SET time_zone = '+08:00'");      // 判定時間用台北時間寫入、顯示（同 index.php）
$hasTable = (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'dup_review'"
)->fetchColumn();
if (!$hasTable) {
    deny_page(503, '找不到 dup_review，請先執行 migration 2026-10-21_dup_review.sql');
}

// ── 動作：記錄判定（POST → 導回，避免重新整理又送一次）──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))
        || ($origin !== '' && !in_array($origin, ["https://{$host}", "http://{$host}"], true))) {
        deny_page(400, '表單已過期，請回上一頁重新整理後再按一次');
    }
    $rid = (int) ($_POST['review_id'] ?? 0);
    $decision = (string) ($_POST['decision'] ?? '');
    if ($rid <= 0 || !isset(DUP_DECISION[$decision])) {
        deny_page(400, '參數不對');
    }
    $up = $pdo->prepare('UPDATE dup_review SET decision = :d, decided_by = :by, decided_at = NOW() WHERE review_id = :id');
    $up->execute([':d' => $decision, ':by' => $me, ':id' => $rid]);
    if ($up->rowCount() === 0 && !$pdo->query('SELECT 1 FROM dup_review WHERE review_id = ' . $rid)->fetchColumn()) {
        deny_page(404, "找不到第 {$rid} 筆");
    }
    $back = in_array($_POST['back'] ?? '', ['unsure', 'done'], true) ? $_POST['back'] : 'pending';
    header('Location: ?tab=' . $back . '&p=' . max(1, (int) ($_POST['p'] ?? 1)) . '&ok=' . $rid . '&d=' . $decision, true, 303);
    exit;
}

// ── 列表：以「來源那一筆」為一組，一組可能有 1～3 本站上候選 ──────────
$tab = in_array($_GET['tab'] ?? '', ['unsure', 'done'], true) ? $_GET['tab'] : 'pending';
$page = max(1, (int) ($_GET['p'] ?? 1));
$where = ['pending' => 'decision IS NULL', 'unsure' => "decision = 'unsure'", 'done' => "decision IN ('same','different')"][$tab];

$counts = $pdo->query(
    "SELECT SUM(decision IS NULL) AS pending, SUM(decision = 'unsure') AS unsure,
            SUM(decision IN ('same','different')) AS done, COUNT(*) AS total FROM dup_review"
)->fetch();
$groups = (int) $pdo->query("SELECT COUNT(DISTINCT list_name, source, source_key) FROM dup_review WHERE {$where}")->fetchColumn();
$pages = max(1, (int) ceil($groups / GROUP_SIZE));
$page = min($page, $pages);

$keys = $pdo->query(
    "SELECT list_name, source, source_key, MIN(review_id) AS first_id FROM dup_review WHERE {$where}
      GROUP BY list_name, source, source_key ORDER BY first_id LIMIT " . GROUP_SIZE . ' OFFSET ' . (($page - 1) * GROUP_SIZE)
)->fetchAll(PDO::FETCH_ASSOC);

$rows = [];
if ($keys) {
    $st = $pdo->prepare(
        "SELECT d.*, b.title, b.subtitle, b.author, b.translator, b.publisher, b.publish_date, b.isbn13,
                b.cover_url, b.is_published
           FROM dup_review d LEFT JOIN books b ON b.book_id = d.candidate_book_id
          WHERE d.list_name = :l AND d.source = :s AND d.source_key = :k AND {$where}
          ORDER BY d.review_id");
    foreach ($keys as $k) {
        $st->execute([':l' => $k['list_name'], ':s' => $k['source'], ':k' => $k['source_key']]);
        $rows[] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

$flash = null;
if (isset($_GET['ok'])) {
    $flash = '第 ' . (int) $_GET['ok'] . ' 筆已記錄：' . (DUP_DECISION[$_GET['d'] ?? ''] ?? '');
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>重複書複核｜屬靈共同書目後台</title>
<link rel="icon" type="image/svg+xml" href="/assets/logo-books.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+TC:wght@500;700&family=Noto+Sans+TC:wght@400;500;700&display=swap" rel="stylesheet">
<style>
/* 色票與版型同 public/admin/index.php（前後台一致，熊哥 2026-10-11）；只多「左右並排」那一段。 */
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
.group{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px 18px;margin-bottom:18px}
.pair{display:grid;grid-template-columns:1fr 1fr;gap:18px;border-top:1px solid var(--line);padding-top:12px;margin-top:12px}
.pair:first-of-type{border-top:none;margin-top:0;padding-top:0}
.side{display:grid;grid-template-columns:72px 1fr;gap:12px}
.side img{width:72px;height:auto;border-radius:4px;background:var(--line)}
.noimg{width:72px;height:96px;border-radius:4px;background:var(--line)}
h2{font-size:1.05rem;margin:0 0 4px;line-height:1.5}
h3{font-size:.95rem;margin:0 0 4px;line-height:1.5}
.lbl{font-size:.78rem;color:var(--ink-soft);letter-spacing:.06em}
.tag{display:inline-block;font-size:.76rem;padding:0 9px;border-radius:10px;border:1px solid currentColor;margin:0 6px 2px 0}
.weak{color:var(--warn)} .strong{color:var(--ok)} .pub0{color:var(--ink-soft)}
dl{display:grid;grid-template-columns:auto 1fr;gap:0 12px;margin:6px 0;font-size:.88rem}
dt{color:var(--ink-soft)} dd{margin:0;overflow-wrap:anywhere}
.act{grid-column:1 / -1;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.act button{font:inherit;font-size:.9rem;padding:5px 16px;border-radius:6px;border:1px solid var(--line);background:var(--card);color:var(--ink);cursor:pointer}
.act .same{border-color:var(--ok);color:var(--ok)} .act .diff{border-color:var(--danger);color:var(--danger)}
.act button:hover{background:var(--paper)}
.act button[aria-pressed="true"]{background:var(--paper);font-weight:700}
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
    <nav class="hd-nav" aria-label="後台頁面"><a href="/admin/">書目審核</a> ・ <a href="/" target="_blank" rel="noopener">回前台 ↗</a> ・ <?= e($me) ?></nav>
  </div>
</header>
<main id="main">
<h2 class="serif" style="font-size:1.2rem;margin:0 0 10px">重複書複核</h2>
<nav class="tabs" aria-label="複核分頁">
  <a href="?tab=pending" class="<?= $tab === 'pending' ? 'on' : '' ?>"<?= $tab === 'pending' ? ' aria-current="page"' : '' ?>>待判（<?= (int) $counts['pending'] ?>）</a>
  <a href="?tab=unsure" class="<?= $tab === 'unsure' ? 'on' : '' ?>"<?= $tab === 'unsure' ? ' aria-current="page"' : '' ?>>看不出來（<?= (int) $counts['unsure'] ?>）</a>
  <a href="?tab=done" class="<?= $tab === 'done' ? 'on' : '' ?>"<?= $tab === 'done' ? ' aria-current="page"' : '' ?>>已判（<?= (int) $counts['done'] ?>）</a>
</nav>
<?php if ($flash): ?><div class="flash" role="status"><?= e($flash) ?></div><?php endif; ?>
<p class="note">左邊是書房商品（還沒匯進站上），右邊是書名比對到的站上那本。按下去<b>只記錄</b>，不會匯入或合併；
「不同書」的之後統一補匯入。<span class="tag weak">可信度較低</span>的是只有書名開頭相同，系列書常會誤中，請多看一眼。</p>
<?php if (!$rows): ?><p><?= $tab === 'pending' ? '沒有待判的了 🎉' : '這一頁沒有資料' ?></p><?php endif; ?>

<?php foreach ($rows as $g):
    $s = $g[0];
    $srcCover = safe_url($s['src_cover']);
    $srcUrl = safe_url($s['src_url']);
?>
<section class="group" aria-labelledby="g<?= (int) $s['review_id'] ?>">
<?php foreach ($g as $n => $r):
    $bid = (int) $r['candidate_book_id'];
    $cover = safe_url($r['cover_url']);
    $weak = str_contains((string) $r['match_how'], '可信度較低');
?>
  <div class="pair" id="r<?= (int) $r['review_id'] ?>">
    <div class="side">
      <div><?php if ($srcCover): ?><img src="<?= e($srcCover) ?>" alt="" loading="lazy"><?php else: ?><div class="noimg"></div><?php endif; ?></div>
      <div>
        <div class="lbl">書房商品・<?= e(DUP_SOURCES[$s['source']] ?? $s['source']) ?></div>
        <?php if ($n === 0): ?><h2 id="g<?= (int) $s['review_id'] ?>"><?= e($s['src_title']) ?></h2><?php else: ?><h3><?= e($s['src_title']) ?></h3><?php endif; ?>
        <dl>
          <dt>作者</dt><dd><?= e($s['src_author'] ?: '—') ?></dd>
          <dt>出版</dt><dd><?= e($s['src_publisher'] ?: '—') ?></dd>
          <dt>ISBN</dt><dd><?= e($s['src_isbn'] ?: '—') ?></dd>
          <dt>貨號</dt><dd><?= e($s['source_key']) ?></dd>
        </dl>
        <?php if ($srcUrl): ?><a href="<?= e($srcUrl) ?>" target="_blank" rel="noopener noreferrer">商品頁 ↗</a><?php endif; ?>
      </div>
    </div>
    <div class="side">
      <div><?php if ($cover): ?><img src="<?= e($cover) ?>" alt="" loading="lazy"><?php else: ?><div class="noimg"></div><?php endif; ?></div>
      <div>
        <div class="lbl">站上那本・編號 <?= $bid ?></div>
        <h3><?= e($r['title'] ?? '（這本已經不在站上）') ?><?php if ($r['subtitle']): ?> <small><?= e($r['subtitle']) ?></small><?php endif; ?></h3>
        <span class="tag <?= $weak ? 'weak' : 'strong' ?>"><?= e($r['match_how']) ?></span>
        <?php if ($r['is_published'] !== null && (int) $r['is_published'] === 0): ?><span class="tag pub0">已下架</span><?php endif; ?>
        <dl>
          <dt>作者</dt><dd><?= e($r['author'] ?: '—') ?><?= $r['translator'] ? '；譯者 ' . e($r['translator']) : '' ?></dd>
          <dt>出版</dt><dd><?= e($r['publisher'] ?: '—') ?><?= $r['publish_date'] ? '・' . e($r['publish_date']) : '' ?></dd>
          <dt>ISBN</dt><dd><?= e($r['isbn13'] ?: '—') ?></dd>
          <?php if ($r['decided_at']): ?><dt>判定</dt><dd><?= e(DUP_DECISION[$r['decision']] ?? '') ?>・<?= e($r['decided_by']) ?>・<?= e($r['decided_at']) ?></dd><?php endif; ?>
        </dl>
        <?php if ($r['title'] !== null && (int) $r['is_published'] === 1): ?><a href="/book/<?= $bid ?>" target="_blank" rel="noopener">站上書目頁 ↗</a><?php endif; ?>
      </div>
    </div>
    <form method="post" class="act" aria-label="判定：<?= e($s['src_title']) ?> 與站上編號 <?= $bid ?>">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
      <input type="hidden" name="review_id" value="<?= (int) $r['review_id'] ?>">
      <input type="hidden" name="back" value="<?= $tab ?>">
      <input type="hidden" name="p" value="<?= $page ?>">
      <?php foreach (DUP_DECISION as $dv => $dl): ?>
        <button name="decision" value="<?= $dv ?>" class="<?= $dv === 'same' ? 'same' : ($dv === 'different' ? 'diff' : '') ?>"
          aria-pressed="<?= $r['decision'] === $dv ? 'true' : 'false' ?>"><?= e($dl) ?></button>
      <?php endforeach; ?>
    </form>
  </div>
<?php endforeach; ?>
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
