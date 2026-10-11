<?php
declare(strict_types=1);

/**
 * 後台：非書籍審核（Asana 1219387164843657）。
 *
 * 熊哥 2026-10-11：「可以先做一個後台處理這些有問題的資訊」「後台只能允許協會 google 帳號」
 *   「後台需要能看比較完整的資訊和連結到原始來源確認」。
 *
 * 登入：Cloudflare Access（只允許 @ccnda.org Google 帳號）＋ 本頁再驗 JWT（app/lib/cf_access.php）。
 * 資料：nonbook_guard_log 裡 hidden（A 級，已先下架）／flagged（B 級，沒動）且還沒審的書。
 * 動作：「是書，放行」→ is_published = 1、記 restored；「非書籍，下架」→ is_published = 0、記 confirmed。
 *   原本那筆寫上 reviewed_at／reviewed_by。已審的在「已審」分頁，可以改判。
 * 日後併入 M2-4 管理後台（1218124616097280）。
 */

require dirname(__DIR__, 2) . '/app/lib/db.php';
require dirname(__DIR__, 2) . '/app/lib/cf_access.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

const SOURCE_LABELS = ['campus' => '校園書房', 'logos' => '基道', 'elim' => '以琳書房', 'grace' => '天恩',
                       'wdbook' => '微讀書城', 'methodist' => '衛理書房', 'osb' => '格子外面', 'taosheng' => '道聲',
                       'cclm' => '橄欖華宣', 'cosmiccare' => '宇宙光', 'mezu' => '真哪噠', 'twgbr' => '福音書房',
                       'pctpress' => '教會公報社', 'tiendao' => '天道書樓', 'btproduct' => '突破機構',
                       'bappress' => '浸信會出版社', 'akow' => '麥種傳道會'];
const PAGE_SIZE = 30;

function e(mixed $s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function src_label(?string $code): string
{
    return SOURCE_LABELS[$code ?? ''] ?? (string) $code;
}

/** 只放行 http(s) 連結，其他（javascript: 之類）一律不輸出成連結。 */
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

// ── 登入 ─────────────────────────────────────────────
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
$hasLog = (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'nonbook_guard_log'"
)->fetchColumn();
if (!$hasLog) {
    deny_page(503, '找不到 nonbook_guard_log，請先執行 migration 2026-10-11_nonbook_guard_log.sql');
}

// ── 動作（POST → 處理完導回，避免重新整理又送一次）──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))
        || ($origin !== '' && !in_array($origin, ["https://{$host}", "http://{$host}"], true))) {
        deny_page(400, '表單已過期，請回上一頁重新整理後再按一次');
    }
    $bookId = (int) ($_POST['book_id'] ?? 0);
    $decision = (string) ($_POST['decision'] ?? '');
    $map = ['release' => [1, 'restored'], 'delist' => [0, 'confirmed']];
    if ($bookId <= 0 || !isset($map[$decision])) {
        deny_page(400, '參數不對');
    }
    [$pub, $action] = $map[$decision];

    $pdo->beginTransaction();
    $orig = $pdo->prepare(
        "SELECT log_id, tier, word, title_snapshot, source FROM nonbook_guard_log
          WHERE book_id = :id AND action IN ('hidden','flagged') ORDER BY log_id LIMIT 1 FOR UPDATE"
    );
    $orig->execute([':id' => $bookId]);
    $o = $orig->fetch();
    if (!$o) {
        $pdo->rollBack();
        deny_page(404, "書 {$bookId} 不在待審紀錄裡");
    }
    $pdo->prepare('UPDATE books SET is_published = :p WHERE book_id = :id')->execute([':p' => $pub, ':id' => $bookId]);
    // 改判時把另一種結果刪掉不好（要留紀錄），所以兩種結果都保留，各自記最後一次的時間與人。
    $pdo->prepare(
        'INSERT INTO nonbook_guard_log (book_id, tier, word, action, title_snapshot, source, reviewed_at, reviewed_by)
         VALUES (:id, :tier, :word, :action, :title, :src, NOW(), :by)
         ON DUPLICATE KEY UPDATE reviewed_at = NOW(), reviewed_by = VALUES(reviewed_by)'
    )->execute([':id' => $bookId, ':tier' => $o['tier'], ':word' => $o['word'], ':action' => $action,
                ':title' => $o['title_snapshot'], ':src' => $o['source'], ':by' => $me]);
    $pdo->prepare('UPDATE nonbook_guard_log SET reviewed_at = NOW(), reviewed_by = :by WHERE log_id = :lid')
        ->execute([':by' => $me, ':lid' => $o['log_id']]);
    $pdo->commit();

    $back = $_POST['back'] ?? 'pending';
    header('Location: ?tab=' . ($back === 'done' ? 'done' : 'pending') . '&ok=' . $bookId . '&d=' . $decision, true, 303);
    exit;
}

// ── 列表 ─────────────────────────────────────────────
$tab = ($_GET['tab'] ?? '') === 'done' ? 'done' : 'pending';
$page = max(1, (int) ($_GET['p'] ?? 1));

$counts = $pdo->query(
    "SELECT SUM(reviewed_at IS NULL) AS pending, SUM(reviewed_at IS NOT NULL) AS done
       FROM nonbook_guard_log WHERE action IN ('hidden','flagged')"
)->fetch();
$total = (int) ($tab === 'pending' ? $counts['pending'] : $counts['done']);

$st = $pdo->prepare(
    "SELECT g.book_id, g.tier, g.word, g.action, g.created_at, g.reviewed_at, g.reviewed_by, g.title_snapshot,
            (SELECT r.action FROM nonbook_guard_log r WHERE r.book_id = g.book_id AND r.action IN ('restored','confirmed')
              ORDER BY r.reviewed_at DESC, r.log_id DESC LIMIT 1) AS result,
            b.title, b.subtitle, b.author, b.translator, b.publisher, b.publish_date, b.isbn13, b.isbn10,
            b.page_count, b.binding, b.series, b.cover_url, b.summary, b.buy_links, b.source, b.is_published,
            c.name AS category
       FROM nonbook_guard_log g
       LEFT JOIN books b ON b.book_id = g.book_id
       LEFT JOIN categories c ON c.category_id = b.category_id
      WHERE g.action IN ('hidden','flagged') AND g.reviewed_at IS " . ($tab === 'pending' ? 'NULL' : 'NOT NULL') . "
      ORDER BY " . ($tab === 'pending' ? "g.tier, g.word, g.book_id" : "g.reviewed_at DESC, g.book_id") . "
      LIMIT " . PAGE_SIZE . " OFFSET " . (($page - 1) * PAGE_SIZE)
);
$st->execute();
$rows = $st->fetchAll();

$eds = [];
if ($rows) {
    $ids = implode(',', array_map('intval', array_column($rows, 'book_id')));
    foreach ($pdo->query("SELECT book_id, source, source_url, publish_date, edition_statement FROM editions
                           WHERE book_id IN ({$ids}) ORDER BY book_id, edition_id") as $ed) {
        $eds[(int) $ed['book_id']][] = $ed;
    }
}

$flash = null;
if (isset($_GET['ok'])) {
    $flash = '書 ' . (int) $_GET['ok'] . ' 已' . (($_GET['d'] ?? '') === 'release' ? '放行（上架）' : '確認下架');
}
$pages = max(1, (int) ceil($total / PAGE_SIZE));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>非書籍審核｜屬靈共同書目後台</title>
<style>
:root { --bg:#f6f5f2; --card:#fff; --ink:#222; --muted:#666; --line:#ddd; --a:#b42318; --b:#b54708; --ok:#067647; --link:#175cd3; }
@media (prefers-color-scheme: dark) { :root { --bg:#161616; --card:#222; --ink:#eee; --muted:#aaa; --line:#3a3a3a; --a:#f97066; --b:#fdb022; --ok:#47cd89; --link:#84adff; } }
* { box-sizing: border-box; }
body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.6 system-ui, "Noto Sans TC", "PingFang TC", sans-serif; }
header { padding:12px 16px; border-bottom:1px solid var(--line); display:flex; flex-wrap:wrap; gap:8px 16px; align-items:center; }
header h1 { font-size:18px; margin:0; flex:1 1 auto; }
header .me { color:var(--muted); font-size:13px; }
nav a { margin-right:12px; color:var(--link); text-decoration:none; }
nav a.on { color:var(--ink); font-weight:700; text-decoration:underline; }
main { max-width:980px; margin:0 auto; padding:16px; }
.note { color:var(--muted); font-size:14px; margin:0 0 12px; }
.flash { background:var(--card); border-left:4px solid var(--ok); padding:8px 12px; margin-bottom:12px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:8px; padding:14px; margin-bottom:14px; display:grid; grid-template-columns:96px 1fr; gap:14px; }
.card img { width:96px; height:auto; border-radius:4px; background:var(--line); }
.noimg { width:96px; height:128px; border-radius:4px; background:var(--line); }
h2 { font-size:17px; margin:0 0 4px; }
h2 small { font-weight:400; color:var(--muted); }
.tag { display:inline-block; font-size:12px; padding:1px 8px; border-radius:10px; border:1px solid currentColor; margin-right:6px; }
.tA { color:var(--a); } .tB { color:var(--b); } .pub1 { color:var(--ok); } .pub0 { color:var(--muted); }
dl { display:grid; grid-template-columns:auto 1fr; gap:2px 12px; margin:8px 0; font-size:14px; }
dt { color:var(--muted); } dd { margin:0; overflow-wrap:anywhere; }
details { font-size:14px; margin:6px 0; } summary { cursor:pointer; color:var(--link); }
.sum { white-space:pre-line; max-height:24em; overflow:auto; border-left:3px solid var(--line); padding-left:10px; }
a { color:var(--link); }
.links a { margin-right:12px; }
.act { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
.act button { font:inherit; padding:6px 14px; border-radius:6px; border:1px solid var(--line); background:var(--card); color:var(--ink); cursor:pointer; }
.act .yes { border-color:var(--ok); color:var(--ok); } .act .no { border-color:var(--a); color:var(--a); }
.pager a, .pager span { margin-right:8px; }
@media (max-width:560px) { .card { grid-template-columns:64px 1fr; } .card img, .noimg { width:64px; } .noimg { height:86px; } }
</style>
</head>
<body>
<header>
  <h1>屬靈共同書目後台・非書籍審核</h1>
  <span class="me"><?= e($me) ?></span>
</header>
<main>
<nav>
  <a href="?tab=pending" class="<?= $tab === 'pending' ? 'on' : '' ?>">待審（<?= (int) $counts['pending'] ?>）</a>
  <a href="?tab=done" class="<?= $tab === 'done' ? 'on' : '' ?>">已審（<?= (int) $counts['done'] ?>）</a>
</nav>
<p class="note">每日新品的書名命中非書籍關鍵字時會進來這裡。<b class="tA">A 級</b>已先自動下架；<b class="tB">B 級</b>還在上架，只是標記出來。
請點「原始來源」看商品頁確認，再按按鈕。</p>
<?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
<?php if (!$rows): ?><p><?= $tab === 'pending' ? '沒有待審的書 🎉' : '還沒有審過的書' ?></p><?php endif; ?>

<?php foreach ($rows as $r):
    $id = (int) $r['book_id'];
    $title = $r['title'] ?? $r['title_snapshot'];
    $isbn = trim(($r['isbn13'] ?? '') . ' ' . ($r['isbn10'] ?? ''));
    $cover = safe_url($r['cover_url']);
    $buy = json_decode((string) ($r['buy_links'] ?? ''), true);
?>
<article class="card" id="b<?= $id ?>">
  <div><?php if ($cover): ?><img src="<?= e($cover) ?>" alt="" loading="lazy"><?php else: ?><div class="noimg"></div><?php endif; ?></div>
  <div>
    <h2><?= e($title) ?><?php if ($r['subtitle']): ?> <small><?= e($r['subtitle']) ?></small><?php endif; ?></h2>
    <span class="tag t<?= e($r['tier']) ?>"><?= e($r['tier']) ?> 級・「<?= e($r['word']) ?>」</span>
    <span class="tag pub<?= (int) $r['is_published'] ?>"><?= $r['is_published'] ? '上架中' : '已下架' ?></span>
    <?php if ($r['result']): ?><span class="tag"><?= $r['result'] === 'restored' ? '判定：是書' : '判定：非書籍' ?></span><?php endif; ?>
    <dl>
      <dt>編號</dt><dd><?= $id ?></dd>
      <?php if ($r['author']): ?><dt>作者</dt><dd><?= e($r['author']) ?><?= $r['translator'] ? '；譯者 ' . e($r['translator']) : '' ?></dd><?php endif; ?>
      <?php if ($r['publisher']): ?><dt>出版</dt><dd><?= e($r['publisher']) ?><?= $r['publish_date'] ? '・' . e($r['publish_date']) : '' ?></dd><?php endif; ?>
      <dt>ISBN</dt><dd><?= $isbn !== '' ? e($isbn) : '<span class="pub0">沒有（商品常沒有 ISBN，可以當線索）</span>' ?></dd>
      <?php if ($r['page_count'] || $r['binding']): ?><dt>頁數／裝訂</dt><dd><?= e($r['page_count'] ? $r['page_count'] . ' 頁' : '') ?> <?= e($r['binding']) ?></dd><?php endif; ?>
      <?php if ($r['series']): ?><dt>叢書</dt><dd><?= e($r['series']) ?></dd><?php endif; ?>
      <dt>分類</dt><dd><?= e($r['category'] ?? '未分類') ?></dd>
      <dt>來源</dt><dd><?= e(src_label($r['source'])) ?>・<?= e(substr((string) $r['created_at'], 0, 10)) ?> 標記</dd>
      <?php if ($r['reviewed_at']): ?><dt>審核</dt><dd><?= e($r['reviewed_by']) ?>・<?= e($r['reviewed_at']) ?></dd><?php endif; ?>
    </dl>
    <div class="links">
      <?php foreach ($eds[$id] ?? [] as $ed): if ($u = safe_url($ed['source_url'])): ?>
        <a href="<?= e($u) ?>" target="_blank" rel="noopener noreferrer">原始來源：<?= e(src_label($ed['source'])) ?> ↗</a>
      <?php endif; endforeach; ?>
      <?php foreach (is_array($buy) ? $buy : [] as $bl): if (is_array($bl) && ($u = safe_url($bl['url'] ?? null)) && !in_array($u, array_column($eds[$id] ?? [], 'source_url'), true)): ?>
        <a href="<?= e($u) ?>" target="_blank" rel="noopener noreferrer">購書：<?= e(src_label($bl['platform'] ?? '')) ?> ↗</a>
      <?php endif; endforeach; ?>
      <?php if ($r['is_published']): ?><a href="/book/<?= $id ?>" target="_blank" rel="noopener">站上書目頁 ↗</a><?php endif; ?>
    </div>
    <?php if ($r['summary']): ?>
    <details><summary>簡介</summary><div class="sum"><?= e($r['summary']) ?></div></details>
    <?php endif; ?>
    <form method="post" class="act">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
      <input type="hidden" name="book_id" value="<?= $id ?>">
      <input type="hidden" name="back" value="<?= $tab ?>">
      <?php if ($r['result'] !== 'restored'): ?><button class="yes" name="decision" value="release">是書，放行（上架）</button><?php endif; ?>
      <?php if ($r['result'] !== 'confirmed'): ?><button class="no" name="decision" value="delist">非書籍，下架</button><?php endif; ?>
    </form>
  </div>
</article>
<?php endforeach; ?>

<?php if ($pages > 1): ?>
<p class="pager">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <?= $i === $page ? '<span>' . $i . '</span>' : '<a href="?tab=' . $tab . '&p=' . $i . '">' . $i . '</a>' ?>
  <?php endfor; ?>
</p>
<?php endif; ?>
</main>
</body>
</html>
