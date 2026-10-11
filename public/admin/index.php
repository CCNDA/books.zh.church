<?php
declare(strict_types=1);

/**
 * 後台：非書籍審核＋依編號下架（Asana 1219387164843657）。
 *
 * 熊哥 2026-10-11：「可以先做一個後台處理這些有問題的資訊」「後台只能允許協會 google 帳號」
 *   「後台需要能看比較完整的資訊和連結到原始來源確認」。
 *
 * 登入：Cloudflare Access（只允許 @ccnda.org Google 帳號）＋ 本頁再驗 JWT（app/lib/cf_access.php）。
 * 資料：nonbook_guard_log 裡 hidden（A 級，已先下架）／flagged（B 級，沒動）且還沒審的書。
 * 動作：「上架」→ is_published = 1、記 restored；「下架」→ is_published = 0、記 confirmed。
 *   用詞只用「上架／下架」（熊哥 2026-10-11：「要注意用詞避免誤會」）。
 * 「已下架」分頁：所有 is_published = 0 的書（含分類對映下架、v1.16.0 批次下架），可以搜尋、按「上架」恢復。
 *   恢復的書會被 app/lib/publish_lock.php 保護，每日的分類工具不會再把它下架。
 *   原本那筆寫上 reviewed_at／reviewed_by。已審的在「已審」分頁，可以改判。
 * 日後併入 M2-4 管理後台（1218124616097280）。
 */

require dirname(__DIR__, 2) . '/app/lib/db.php';
require dirname(__DIR__, 2) . '/app/lib/cf_access.php';
require dirname(__DIR__, 2) . '/app/lib/release.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; img-src 'self' https: data:; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

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

/** 只輸出 http(s) 連結，其他（javascript: 之類）一律不輸出成連結。 */
function safe_url(?string $u): ?string
{
    return ($u !== null && preg_match('#^https?://#i', $u)) ? $u : null;
}

/**
 * 手動下架的原因（存在 nonbook_guard_log.word VARCHAR(20)；tier 記 M）。熊哥 2026-10-11：
 *   「有些是一般商務書籍，也需要下架」「一些系列套組不是單本書或是合併本，也需要下架」
 */
const MANUAL_REASONS = ['一般書籍（非屬靈）', '套組／合輯（非單本）', '非書籍', '其他'];
const MANUAL_MAX = 50;

/**
 * 從貼上的文字取出書的編號：純數字，或書目頁網址裡 /book/ 後面的數字（book.php?id= 也可以）；去重、最多 MANUAL_MAX 個。
 * ★ 不能把文字裡所有數字都抓出來：測試站網址 books.ps194.net/book/123 會多抓到 194，預覽出一本不相干的書。
 */
function parse_book_ids(string $raw): array
{
    $ids = [];
    foreach (preg_split('/[\s,，、;；]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) as $tok) {
        if (preg_match('#^\d{1,9}$#', $tok)) {
            $ids[] = (int) $tok;
        } elseif (preg_match('#/book/(\d{1,9})(?:\D|$)#', $tok, $m) || preg_match('#[?&]id=(\d{1,9})(?:\D|$)#', $tok, $m)) {
            $ids[] = (int) $m[1];
        }
    }
    $ids = array_values(array_unique(array_filter($ids, fn($n) => $n > 0)));
    return array_slice($ids, 0, MANUAL_MAX);
}

/**
 * 審核一本在紀錄裡的書：release → 上架、記 restored；delist → 下架、記 confirmed。
 * 原本那筆（hidden／flagged）寫上 reviewed_at／reviewed_by。回 false＝這本不在紀錄裡。
 */
function decide(PDO $pdo, int $bookId, string $decision, string $me): bool
{
    [$pub, $action] = $decision === 'release' ? [1, 'restored'] : [0, 'confirmed'];
    $pdo->beginTransaction();
    $orig = $pdo->prepare(
        "SELECT log_id, tier, word, title_snapshot, source FROM nonbook_guard_log
          WHERE book_id = :id AND action IN ('hidden','flagged') ORDER BY log_id LIMIT 1 FOR UPDATE"
    );
    $orig->execute([':id' => $bookId]);
    $o = $orig->fetch();
    if (!$o) {
        $pdo->rollBack();
        return false;
    }
    $pdo->prepare('UPDATE books SET is_published = :p WHERE book_id = :id')->execute([':p' => $pub, ':id' => $bookId]);
    // 改判時兩種結果都保留（要留紀錄），各自記最後一次的時間與人。
    $pdo->prepare(
        'INSERT INTO nonbook_guard_log (book_id, tier, word, action, title_snapshot, source, reviewed_at, reviewed_by)
         VALUES (:id, :tier, :word, :action, :title, :src, NOW(), :by)
         ON DUPLICATE KEY UPDATE reviewed_at = NOW(), reviewed_by = VALUES(reviewed_by)'
    )->execute([':id' => $bookId, ':tier' => $o['tier'], ':word' => $o['word'], ':action' => $action,
                ':title' => $o['title_snapshot'], ':src' => $o['source'], ':by' => $me]);
    $pdo->prepare('UPDATE nonbook_guard_log SET reviewed_at = NOW(), reviewed_by = :by WHERE log_id = :lid')
        ->execute([':by' => $me, ':lid' => $o['log_id']]);
    $pdo->commit();
    return true;
}

/**
 * 依編號下架一本書。已經在紀錄裡的（守門標記過）照一般審核走；
 * 不在紀錄裡的新增一筆 tier M（手動）的 hidden（已審）＋ confirmed，之後在「已審」頁一樣可以上架。
 * 回 false＝書不存在或原本就已下架（不動）。
 */
function manual_delist(PDO $pdo, int $bookId, string $reason, string $me): bool
{
    $b = $pdo->prepare('SELECT is_published FROM books WHERE book_id = :id');
    $b->execute([':id' => $bookId]);
    $pub = $b->fetchColumn();
    if ($pub === false || (int) $pub === 0) {
        return false;
    }
    return set_published($pdo, $bookId, 'delist', $reason, $me);
}

/**
 * 上架／下架任何一本書。在紀錄裡的照 decide() 走；不在紀錄裡的（例：分類對映下架、v1.16.0 批次下架的書）
 * 補一筆 tier M 的 hidden（記原因、已審）＋ restored／confirmed，之後在「已審」頁看得到、也能改。
 * 回 false＝書不存在。
 */
function set_published(PDO $pdo, int $bookId, string $decision, string $reason, string $me): bool
{
    if (decide($pdo, $bookId, $decision, $me)) {
        return true;
    }
    $b = $pdo->prepare('SELECT title, source FROM books WHERE book_id = :id');
    $b->execute([':id' => $bookId]);
    $book = $b->fetch();
    if (!$book) {
        return false;
    }
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE books SET is_published = :p WHERE book_id = :id')
        ->execute([':p' => $decision === 'release' ? 1 : 0, ':id' => $bookId]);
    $ins = $pdo->prepare(
        "INSERT INTO nonbook_guard_log (book_id, tier, word, action, title_snapshot, source, reviewed_at, reviewed_by)
         VALUES (:id, 'M', :word, :action, :title, :src, NOW(), :by)
         ON DUPLICATE KEY UPDATE reviewed_at = NOW(), reviewed_by = VALUES(reviewed_by)"
    );
    foreach (['hidden', $decision === 'release' ? 'restored' : 'confirmed'] as $action) {
        $ins->execute([':id' => $bookId, ':word' => $reason, ':action' => $action,
                       ':title' => mb_substr((string) $book['title'], 0, 500), ':src' => $book['source'], ':by' => $me]);
    }
    $pdo->commit();
    return true;
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
    $op = (string) ($_POST['op'] ?? 'decide');

    // 依編號下架：第一步只預覽（不改資料），第二步 manual_confirm 才真的下架
    if ($op === 'manual_preview') {
        $manualIds = parse_book_ids((string) ($_POST['ids'] ?? ''));
        $manualReason = in_array($_POST['reason'] ?? '', MANUAL_REASONS, true) ? $_POST['reason'] : MANUAL_REASONS[0];
        $manualRaw = (string) ($_POST['ids'] ?? '');
        // 往下走到頁面輸出，顯示預覽
    } elseif ($op === 'manual_confirm') {
        $ids = parse_book_ids((string) ($_POST['ids'] ?? ''));
        $reason = (string) ($_POST['reason'] ?? '');
        if ($ids === [] || !in_array($reason, MANUAL_REASONS, true)) {
            deny_page(400, '參數不對');
        }
        $done = 0;
        foreach ($ids as $id) {
            $done += manual_delist($pdo, $id, $reason, $me) ? 1 : 0;
        }
        header('Location: ?tab=done&manual=' . $done, true, 303);
        exit;
    } else {
        $bookId = (int) ($_POST['book_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        if ($bookId <= 0 || !in_array($decision, ['release', 'delist'], true)) {
            deny_page(400, '參數不對');
        }
        $back = in_array($_POST['back'] ?? '', ['done', 'off'], true) ? $_POST['back'] : 'pending';
        $ok = $back === 'off'
            ? set_published($pdo, $bookId, $decision, '既有下架', $me)
            : decide($pdo, $bookId, $decision, $me);
        if (!$ok) {
            deny_page(404, "找不到書 {$bookId}");
        }
        $keep = $back === 'off' ? '&q=' . rawurlencode((string) ($_POST['q'] ?? '')) . '&p=' . (int) ($_POST['p'] ?? 1) : '';
        header('Location: ?tab=' . $back . $keep . '&ok=' . $bookId . '&d=' . $decision, true, 303);
        exit;
    }
}

// ── 列表 ─────────────────────────────────────────────
$tab = isset($manualIds) ? 'manual' : (in_array($_GET['tab'] ?? '', ['done', 'manual', 'off'], true) ? $_GET['tab'] : 'pending');
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['p'] ?? 1));

$counts = $pdo->query(
    "SELECT SUM(reviewed_at IS NULL) AS pending, SUM(reviewed_at IS NOT NULL) AS done
       FROM nonbook_guard_log WHERE action IN ('hidden','flagged')"
)->fetch();
$total = (int) ($tab === 'pending' ? $counts['pending'] : $counts['done']);

$manualMissing = [];
if ($tab === 'manual') {
    // 依編號下架的預覽：直接讀 books（欄位名稱和下面的列表一樣，共用卡片）
    $rows = [];
    if (!empty($manualIds)) {
        $in = implode(',', array_map('intval', $manualIds));
        $rows = $pdo->query(
            "SELECT b.book_id, NULL AS tier, NULL AS word, NULL AS action, NULL AS created_at, NULL AS reviewed_at,
                    NULL AS reviewed_by, b.title AS title_snapshot, NULL AS result,
                    b.title, b.subtitle, b.author, b.translator, b.publisher, b.publish_date, b.isbn13, b.isbn10,
                    b.page_count, b.binding, b.series, b.cover_url, b.summary, b.buy_links, b.source, b.is_published,
                    c.name AS category
               FROM books b LEFT JOIN categories c ON c.category_id = b.category_id
              WHERE b.book_id IN ({$in}) ORDER BY FIELD(b.book_id, {$in})"
        )->fetchAll();
        $manualMissing = array_values(array_diff($manualIds, array_map('intval', array_column($rows, 'book_id'))));
    }
    $total = 0;
} elseif ($tab === 'off') {
    // 所有下架中的書；可用編號或書名搜尋。原因：在紀錄裡就顯示紀錄，不在就是分類對映或之前批次下架
    $where = 'b.is_published = 0';
    $args = [];
    if ($q !== '') {
        if (preg_match('/^\d{1,9}$/', $q)) {
            $where .= ' AND b.book_id = :id';
            $args[':id'] = (int) $q;
        } else {
            $where .= ' AND b.title LIKE :q';
            $args[':q'] = '%' . addcslashes($q, '%_\\') . '%';
        }
    }
    $c = $pdo->prepare("SELECT COUNT(*) FROM books b WHERE {$where}");
    $c->execute($args);
    $total = (int) $c->fetchColumn();
    $st = $pdo->prepare(
        "SELECT b.book_id, g.tier, g.word, g.action, g.created_at, g.reviewed_at, g.reviewed_by, b.title AS title_snapshot,
                (SELECT r.action FROM nonbook_guard_log r WHERE r.book_id = b.book_id AND r.action IN ('restored','confirmed')
                  ORDER BY r.reviewed_at DESC, r.log_id DESC LIMIT 1) AS result,
                b.title, b.subtitle, b.author, b.translator, b.publisher, b.publish_date, b.isbn13, b.isbn10,
                b.page_count, b.binding, b.series, b.cover_url, b.summary, b.buy_links, b.source, b.is_published,
                c.name AS category
           FROM books b
           LEFT JOIN nonbook_guard_log g ON g.log_id = (SELECT MIN(x.log_id) FROM nonbook_guard_log x
                                                         WHERE x.book_id = b.book_id AND x.action IN ('hidden','flagged'))
           LEFT JOIN categories c ON c.category_id = b.category_id
          WHERE {$where}
          ORDER BY b.book_id DESC
          LIMIT " . PAGE_SIZE . " OFFSET " . (($page - 1) * PAGE_SIZE)
    );
    $st->execute($args);
    $rows = $st->fetchAll();
} else {
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
}

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
    $flash = '書 ' . (int) $_GET['ok'] . (($_GET['d'] ?? '') === 'release' ? ' 已上架' : ' 已下架');
} elseif (isset($_GET['manual'])) {
    $flash = '依編號下架：已下架 ' . (int) $_GET['manual'] . ' 本（原本就下架或找不到的不會動）';
}
$pages = max(1, (int) ceil($total / PAGE_SIZE));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>書目審核｜屬靈共同書目後台</title>
<link rel="icon" type="image/svg+xml" href="/assets/logo-books.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+TC:wght@500;700&family=Noto+Sans+TC:wght@400;500;700&display=swap" rel="stylesheet">
<style>
/* 版型沿用前台（public/changelog.php、book.php）：同一組色票、標頭雙金線、logo、footer。
   熊哥 2026-10-11：「前後台我希望能有相同的版型能一致」。前台沒有深色模式，後台也不做，兩邊才一致。 */
:root{
  --paper:#f2f6f9; --card:#ffffff; --ink:#14303c; --ink-soft:#5c7482;
  --gold:#0e6d94; --gold-soft:#f0c04a; --line:#e2e8ee; --accent:#177da8;
  --danger:#b42318; --warn:#b54708; --ok:#067647;
}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);
  font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif;line-height:1.8}
a{color:var(--gold);text-decoration:none}
a:hover{text-decoration:underline}
.serif{font-family:"Noto Serif TC","PMingLiU",serif}
header{border-bottom:3px double var(--gold-soft);background:var(--card)}
.hd-inner{max-width:980px;margin:0 auto;padding:20px 20px 14px;
  display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap}
.hd-title{margin:0;font-size:1.35rem;letter-spacing:.12em}
.hd-title a{color:var(--ink);display:inline-flex;align-items:center;gap:.4em}
.hd-title small{font-size:.8rem;letter-spacing:.06em;color:var(--ink-soft);font-weight:500}
.brand-logo{height:34px;width:34px;flex:0 0 auto}
.hd-nav{font-size:.88rem;color:var(--ink-soft)}
.ft-logo{width:26px;height:26px;border-radius:4px;flex:0 0 auto}
main{max-width:980px;margin:28px auto 0;padding:0 20px}
.tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px;border-bottom:1px solid var(--line)}
.tabs a{padding:6px 14px;border:1px solid transparent;border-bottom:none;border-radius:8px 8px 0 0;color:var(--ink-soft)}
.tabs a.on{background:var(--card);border-color:var(--line);color:var(--ink);font-weight:700;margin-bottom:-1px}
.note{color:var(--ink-soft);font-size:.92rem;margin:0 0 14px}
.flash{background:var(--card);border:1px solid var(--line);border-left:4px solid var(--ok);border-radius:6px;padding:8px 12px;margin-bottom:14px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px 18px;margin-bottom:14px;display:grid;grid-template-columns:96px 1fr;gap:16px}
.card img{width:96px;height:auto;border-radius:4px;background:var(--line)}
.noimg{width:96px;height:128px;border-radius:4px;background:var(--line)}
h2{font-size:1.1rem;margin:0 0 6px;line-height:1.5}
h2 small{font-weight:400;color:var(--ink-soft)}
.tag{display:inline-block;font-size:.76rem;padding:0 9px;border-radius:10px;border:1px solid currentColor;margin:0 6px 2px 0}
.tA{color:var(--danger)} .tB{color:var(--warn)} .tM{color:var(--accent)} .pub1{color:var(--ok)} .pub0{color:var(--ink-soft)}
dl{display:grid;grid-template-columns:auto 1fr;gap:0 14px;margin:8px 0;font-size:.9rem}
dt{color:var(--ink-soft)} dd{margin:0;overflow-wrap:anywhere}
details{font-size:.9rem;margin:6px 0} summary{cursor:pointer;color:var(--gold)}
.sum{white-space:pre-line;max-height:24em;overflow:auto;border-left:3px solid var(--line);padding-left:10px}
.links{font-size:.9rem}
.links a{margin-right:14px;display:inline-block}
.act{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;align-items:center}
.act button{font:inherit;font-size:.9rem;padding:5px 16px;border-radius:6px;border:1px solid var(--line);background:var(--card);color:var(--ink);cursor:pointer}
.act .yes{border-color:var(--ok);color:var(--ok)} .act .no{border-color:var(--danger);color:var(--danger)}
.act button:hover{background:var(--paper)}
textarea,select{font:inherit;width:100%;max-width:560px;padding:6px 8px;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--ink)}
.box{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px 18px;margin-bottom:14px}
.box label{display:block;margin:8px 0 4px;font-weight:500}
.pager a,.pager span{margin-right:10px}
footer{margin-top:64px;border-top:1px solid var(--line);background:var(--card)}
.ft{max-width:980px;margin:0 auto;padding:22px 20px;font-size:.82rem;color:var(--ink-soft);
  display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
@media (max-width:560px){ .card{grid-template-columns:64px 1fr;padding:14px} .card img,.noimg{width:64px} .noimg{height:86px} }
</style>
</head>
<body>
<header>
  <div class="hd-inner">
    <h1 class="hd-title serif"><a href="/admin/"><img class="brand-logo" src="/assets/logo-books.svg" alt="" aria-hidden="true">屬靈共同書目 <small>後台</small></a></h1>
    <nav class="hd-nav"><a href="/" target="_blank" rel="noopener">回前台 ↗</a> ・ <?= e($me) ?></nav>
  </div>
</header>
<main>
<nav class="tabs">
  <a href="?tab=pending" class="<?= $tab === 'pending' ? 'on' : '' ?>">待審（<?= (int) $counts['pending'] ?>）</a>
  <a href="?tab=done" class="<?= $tab === 'done' ? 'on' : '' ?>">已審（<?= (int) $counts['done'] ?>）</a>
  <a href="?tab=off" class="<?= $tab === 'off' ? 'on' : '' ?>">已下架</a>
  <a href="?tab=manual" class="<?= $tab === 'manual' ? 'on' : '' ?>">依編號下架</a>
</nav>
<?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
<?php if ($tab === 'manual'): ?>
<form method="post" class="box">
  <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
  <input type="hidden" name="op" value="manual_preview">
  <label for="ids">書的編號（可以一次貼很多個；也可以直接貼書目頁網址，例 https://books.zh.church/book/12345）</label>
  <textarea id="ids" name="ids" rows="4" placeholder="12345, 23456&#10;https://books.zh.church/book/34567"><?= e($manualRaw ?? '') ?></textarea>
  <label for="reason">下架原因</label>
  <select id="reason" name="reason">
    <?php foreach (MANUAL_REASONS as $rs): ?><option<?= ($manualReason ?? '') === $rs ? ' selected' : '' ?>><?= e($rs) ?></option><?php endforeach; ?>
  </select>
  <div class="act"><button>先預覽（還不會下架）</button></div>
  <p class="note">一次最多 <?= MANUAL_MAX ?> 本。下架不會刪資料，在「已下架」頁可以再上架。</p>
</form>
<?php if (isset($manualIds)): ?>
  <?php if ($manualMissing): ?><p class="note">找不到這些編號：<?= e(implode('、', $manualMissing)) ?></p><?php endif; ?>
  <?php if ($rows): ?>
  <form method="post" class="box">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <input type="hidden" name="op" value="manual_confirm">
    <input type="hidden" name="ids" value="<?= e(implode(',', array_column($rows, 'book_id'))) ?>">
    <input type="hidden" name="reason" value="<?= e($manualReason) ?>">
    <b>確認要以「<?= e($manualReason) ?>」下架下面 <?= count(array_filter($rows, fn($x) => (int) $x['is_published'] === 1)) ?> 本嗎？</b>
    （已經下架的 <?= count(array_filter($rows, fn($x) => (int) $x['is_published'] === 0)) ?> 本不會動）
    <div class="act"><button class="no">確認下架</button> <a href="?tab=manual">取消</a></div>
  </form>
  <?php else: ?><p>沒有可以下架的書。</p><?php endif; ?>
<?php endif; ?>
<?php elseif ($tab === 'off'): ?>
<form method="get" class="box">
  <input type="hidden" name="tab" value="off">
  <label for="q">搜尋下架中的書（輸入編號或書名的一部分）</label>
  <input id="q" name="q" value="<?= e($q) ?>" style="font:inherit;width:100%;max-width:560px;padding:6px 8px;border:1px solid var(--line);border-radius:6px">
  <div class="act"><button>搜尋</button><?php if ($q !== ''): ?> <a href="?tab=off">清除</a><?php endif; ?></div>
  <p class="note">共 <?= number_format($total) ?> 本下架中。包含每日分類對映自動下架的商品（禮品、CD、文具等）和之前批次下架的書。
  按「上架」恢復後，每日的程式不會再把它下架。</p>
</form>
<?php if (!$rows): ?><p>找不到符合的書。</p><?php endif; ?>
<?php else: ?>
<p class="note">每日新品的書名命中非書籍關鍵字時會進來這裡。<b class="tA">A 級</b>已先自動下架；<b class="tB">B 級</b>還在上架，只是標記出來；<b class="tM">手動</b>是在「依編號下架」下架的。
請點「原始來源」看商品頁確認，再按按鈕。</p>
<?php if (!$rows): ?><p><?= $tab === 'pending' ? '沒有待審的書 🎉' : '還沒有審過的書' ?></p><?php endif; ?>
<?php endif; ?>

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
    <?php if ($r['tier'] === 'M'): ?><span class="tag tM">手動下架・<?= e($r['word']) ?></span>
    <?php elseif ($r['tier'] === null && $tab === 'off'): ?><span class="tag pub0">分類對映或批次下架</span>
    <?php elseif ($r['tier']): ?><span class="tag t<?= e($r['tier']) ?>"><?= e($r['tier']) ?> 級・「<?= e($r['word']) ?>」</span><?php endif; ?>
    <span class="tag pub<?= (int) $r['is_published'] ?>"><?= $r['is_published'] ? '上架中' : '已下架' ?></span>
    <?php if ($r['result']): ?><span class="tag"><?= $r['result'] === 'restored' ? '審核結果：上架' : '審核結果：下架' ?></span><?php endif; ?>
    <dl>
      <dt>編號</dt><dd><?= $id ?></dd>
      <?php if ($r['author']): ?><dt>作者</dt><dd><?= e($r['author']) ?><?= $r['translator'] ? '；譯者 ' . e($r['translator']) : '' ?></dd><?php endif; ?>
      <?php if ($r['publisher']): ?><dt>出版</dt><dd><?= e($r['publisher']) ?><?= $r['publish_date'] ? '・' . e($r['publish_date']) : '' ?></dd><?php endif; ?>
      <dt>ISBN</dt><dd><?= $isbn !== '' ? e($isbn) : '<span class="pub0">沒有（商品常沒有 ISBN，可以當線索）</span>' ?></dd>
      <?php if ($r['page_count'] || $r['binding']): ?><dt>頁數／裝訂</dt><dd><?= e($r['page_count'] ? $r['page_count'] . ' 頁' : '') ?> <?= e($r['binding']) ?></dd><?php endif; ?>
      <?php if ($r['series']): ?><dt>叢書</dt><dd><?= e($r['series']) ?></dd><?php endif; ?>
      <dt>分類</dt><dd><?= e($r['category'] ?? '未分類') ?></dd>
      <dt>來源</dt><dd><?= e(src_label($r['source'])) ?><?= $r['created_at'] && $r['tier'] !== 'M' ? '・' . e(substr((string) $r['created_at'], 0, 10)) . ' 標記' : '' ?></dd>
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
    <?php if ($tab !== 'manual'): ?>
    <form method="post" class="act">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
      <input type="hidden" name="book_id" value="<?= $id ?>">
      <input type="hidden" name="back" value="<?= $tab ?>">
      <?php if ($tab === 'off'): ?><input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="p" value="<?= $page ?>"><?php endif; ?>
      <?php if ($tab === 'off' || $r['result'] !== 'restored'): ?><button class="yes" name="decision" value="release">上架</button><?php endif; ?>
      <?php if ($tab !== 'off' && $r['result'] !== 'confirmed'): ?><button class="no" name="decision" value="delist">下架</button><?php endif; ?>
    </form>
    <?php endif; ?>
  </div>
</article>
<?php endforeach; ?>

<?php if ($pages > 1): ?>
<p class="pager">
  <?php // 已下架有上萬本：只列第一頁、最後一頁和目前頁前後 3 頁
  $prev = 0;
  for ($i = 1; $i <= $pages; $i++):
      if ($i !== 1 && $i !== $pages && abs($i - $page) > 3) { continue; }
      if ($prev && $i > $prev + 1) { echo '<span>…</span>'; }
      $prev = $i; ?>
    <?= $i === $page ? '<span>' . $i . '</span>' : '<a href="?tab=' . $tab . ($q !== '' ? '&q=' . rawurlencode($q) : '') . '&p=' . $i . '">' . $i . '</a>' ?>
  <?php endfor; ?>
</p>
<?php endif; ?>
</main>
<footer><div class="ft">
  <span style="display:inline-flex;align-items:center;gap:8px"><img class="ft-logo" src="/assets/logo-ccnda.png" alt="CCNDA">© 2026 <a href="https://www.ccnda.org" target="_blank" rel="noopener">中華基督教網路發展協會(CCNDA)</a></span>
  <span><a href="/changelog.php" target="_blank" rel="noopener"><?= ($v = app_version()) !== '' ? 'v' . e($v) : '版本資訊' ?></a></span>
</div></footer>
</body>
</html>
