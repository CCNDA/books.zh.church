<?php
declare(strict_types=1);

/**
 * 書目詳情頁(/book/{id} 短網址,nginx rewrite → book.php?id=N)
 * PHP 僅負責 og/meta(社群分享預覽)與 404 狀態碼;內容由前端呼叫 /api/books/{id} 渲染。
 */

$id   = (int) ($_GET['id'] ?? 0);
$book = null;
$dbError = false;
if ($id > 0) {
    try {
        // 和 API GET /api/books/{id} 用同一個函式（app/lib/book_detail.php），伺服器輸出與 API 內容不會分岔
        require dirname(__DIR__) . '/app/lib/book_detail.php';
        $book = book_detail($id);
    } catch (Throwable $ex) {
        $book = null; // DB 異常時仍出頁面,由前端重試
        $dbError = true;
    }
}
if (!$book) {
    // DB 異常回 503（暫時無法服務），不要回 404：404 會讓搜尋引擎把書目頁移出索引
    http_response_code($dbError ? 503 : 404);
}

function e(?string $s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/*
 * ── 伺服器端輸出（Asana 1219388521666079、1219388805512172）──────────────
 * 熊哥 2026-10-11：「屬靈共同書目要符合 AIO SEO 的最佳設定，另外還要符合視障無障礙使用」。
 * 原本內文全靠下方 JavaScript 呼叫 API 才畫出來 → 不執行 JS 的 AI 爬蟲（GPTBot、ClaudeBot、PerplexityBot…）
 * 與部分報讀情境只看得到空頁。現在伺服器先輸出同樣的 HTML（格式照著 JS 的 render() 寫，兩邊要一起改），
 * 資料也嵌在頁面裡（#bookData），JS 直接拿來用、不再多打一次 API。
 */
const SSR_ROLE_NAMES = ['author' => '作者', 'editor' => '編者', 'translator' => '譯者', 'illustrator' => '繪者',
                        'foreword' => '序文', 'advisor' => '顧問', 'proofreader' => '校對', 'contributor' => '合著'];
const SSR_PLATFORM_NAMES = ['campus' => '校園書房', 'logos' => '基道 BookFinder'];

function ssr_meta_row(string $label, ?string $value, bool $html = false): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return '<div><dt>' . e($label) . '</dt><dd>' . ($html ? $value : e($value)) . '</dd></div>';
}

function ssr_contributor_rows(array $b): string
{
    if (!empty($b['contributors'])) {
        $out = '';
        foreach ($b['contributors'] as $role => $ps) {
            $names = array_map(static function (array $p): string {
                $label = e($p['name']) . (($p['credit_text'] ?? '') !== '' && $p['credit_text'] !== $p['name'] ? '(' . e($p['credit_text']) . ')' : '');
                return $p['person_id'] ? '<a href="/?person=' . rawurlencode((string) $p['person_id']) . '">' . $label . '</a>' : $label;
            }, $ps);
            $out .= ssr_meta_row(SSR_ROLE_NAMES[$role] ?? $role, implode('、', $names), true);
        }
        return $out;
    }
    return ssr_meta_row('作者', $b['author'] ?? null) . ssr_meta_row('譯者', $b['translator'] ?? null) . ssr_meta_row('編者', $b['editors'] ?? null);
}

function ssr_publishers(array $b): array
{
    $seen = [];
    foreach ($b['editions'] ?? [] as $ed) {
        if (!empty($ed['publisher_id']) && !empty($ed['publisher_name']) && !isset($seen[$ed['publisher_id']])) {
            $seen[$ed['publisher_id']] = $ed['publisher_name'];
        }
    }
    return $seen;
}

function ssr_publisher_links(array $b): string
{
    $seen = ssr_publishers($b);
    if ($seen) {
        $out = [];
        foreach ($seen as $pid => $name) {
            $out[] = '<a href="/?publisher=' . rawurlencode((string) $pid) . '">' . e($name) . '</a>';
        }
        return implode('、', $out);
    }
    return ($b['publisher'] ?? '') !== '' ? e($b['publisher']) : '';
}

function ssr_series(array $b): string
{
    $s = implode('、', array_map(static fn(array $x): string =>
        $x['series_name'] . ($x['series_number'] ? ' #' . $x['series_number'] : ''), $b['series_list'] ?? []));
    return $s !== '' ? $s : (string) ($b['series'] ?? '');
}

/** schema.org JSON-LD：Book＋BreadcrumbList。只放資料庫真的有的欄位，沒有就不寫（不猜）。 */
function ssr_jsonld(array $b, string $url): string
{
    $people = static function (array $b, string $role, ?string $flat): array {
        $names = array_column($b['contributors'][$role] ?? [], 'name');
        if (!$names && $flat) {
            $names = preg_split('/\s*[、，,／\/]\s*/u', $flat, -1, PREG_SPLIT_NO_EMPTY);
        }
        return array_map(static fn(string $n): array => ['@type' => 'Person', 'name' => $n], $names);
    };
    $book = ['@type' => 'Book', '@id' => $url . '#book', 'url' => $url, 'name' => $b['title']];
    if (!empty($b['subtitle'])) { $book['alternativeHeadline'] = $b['subtitle']; }
    if ($a = $people($b, 'author', $b['author'] ?? null)) { $book['author'] = $a; }
    if ($t = $people($b, 'translator', $b['translator'] ?? null)) { $book['translator'] = $t; }
    $pubs = array_values(ssr_publishers($b)) ?: (($b['publisher'] ?? '') !== '' ? [$b['publisher']] : []);
    if ($pubs) { $book['publisher'] = ['@type' => 'Organization', 'name' => $pubs[0]]; }
    if (!empty($b['publish_date'])) { $book['datePublished'] = $b['publish_date']; }
    if ($isbn = ($b['isbn13'] ?: ($b['isbn10'] ?? ''))) { $book['isbn'] = $isbn; }
    if (!empty($b['page_count'])) { $book['numberOfPages'] = (int) $b['page_count']; }
    $bind = (string) ($b['binding'] ?? '');
    if (str_contains($bind, '精')) { $book['bookFormat'] = 'https://schema.org/Hardcover'; }
    elseif (str_contains($bind, '平')) { $book['bookFormat'] = 'https://schema.org/Paperback'; }
    elseif (str_contains($bind, '電子')) { $book['bookFormat'] = 'https://schema.org/EBook'; }
    $lang = (string) ($b['language'] ?? '');
    $book['inLanguage'] = str_contains($lang, '簡') ? 'zh-Hans' : (str_contains($lang, '英') ? 'en' : 'zh-Hant');
    if (!empty($b['cover_url'])) { $book['image'] = $b['cover_url']; }
    $desc = trim((string) ($b['summary_short'] ?: ($b['summary'] ?? '')));
    if ($desc !== '') { $book['description'] = mb_substr(preg_replace('/\s+/u', ' ', $desc), 0, 300, 'UTF-8'); }
    if (!empty($b['category_name'])) { $book['genre'] = $b['category_name']; }

    $crumbs = [['@type' => 'ListItem', 'position' => 1, 'name' => '書目', 'item' => 'https://books.zh.church/']];
    if (!empty($b['category_name'])) {
        $crumbs[] = ['@type' => 'ListItem', 'position' => 2, 'name' => $b['category_name'], 'item' => 'https://books.zh.church/?category=' . $b['category_id']];
    }
    $crumbs[] = ['@type' => 'ListItem', 'position' => count($crumbs) + 1, 'name' => $b['title'], 'item' => $url];

    $graph = ['@context' => 'https://schema.org', '@graph' => [$book, ['@type' => 'BreadcrumbList', 'itemListElement' => $crumbs]]];
    return json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
}

$title = $book ? $book['title'] : '找不到此書';
$desc  = '';
if ($book) {
    $desc = $book['summary_short'] ?: mb_substr((string) ($book['summary'] ?? ''), 0, 150, 'UTF-8');
    if ($book['author']) {
        $desc = $book['author'] . '著。' . $desc;
    }
}
$ogUrl = 'https://books.zh.church/book/' . $id;
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<!-- Google Analytics（GA4 G-58CPNJ5D6F，熊哥 2026-10-11；Asana 1219388348551239）。只裝前台，後台 /admin/ 不裝。 -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-58CPNJ5D6F"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-58CPNJ5D6F');</script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?>|屬靈共同書目</title>
<meta name="description" content="<?= e($desc) ?>">
<meta property="og:site_name" content="屬靈共同書目">
<meta property="og:type" content="book">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($ogUrl) ?>">
<?php if ($book && $book['cover_url']): ?>
<meta property="og:image" content="<?= e($book['cover_url']) ?>">
<?php else: ?>
<meta property="og:image" content="https://books.zh.church/assets/og-image.png">
<?php endif; ?>
<meta name="twitter:card" content="summary">
<link rel="canonical" href="<?= e($ogUrl) ?>">
<link rel="icon" type="image/svg+xml" href="/assets/logo-books.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16x16.png">
<link rel="shortcut icon" href="/assets/favicon.ico">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
<link rel="manifest" href="/assets/site.webmanifest">
<meta name="theme-color" content="#177da8">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+TC:wght@500;700&family=Noto+Sans+TC:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root{
  --paper:#f2f6f9; --card:#ffffff; --ink:#14303c; --ink-soft:#5c7482;
  --gold:#0e6d94; --gold-soft:#f0c04a; --line:#e2e8ee; --accent:#177da8;
}
*{box-sizing:border-box}
[hidden]{display:none!important}
body{margin:0;background:var(--paper);color:var(--ink);
  font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif;line-height:1.7}
a{color:inherit;text-decoration:none}
.serif{font-family:"Noto Serif TC","PMingLiU",serif}

header{border-bottom:3px double var(--gold-soft);background:var(--card)}
.hd-inner{max-width:1080px;margin:0 auto;padding:20px 20px 14px;
  display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap}
.hd-title{margin:0;font-size:1.35rem;letter-spacing:.12em;font-weight:700;line-height:normal}
.hd-title a{display:inline-flex;align-items:center;gap:.4em;color:var(--ink)}
.brand-logo{height:34px;width:34px;flex:0 0 auto}
.hd-nav{font-size:.88rem;color:var(--ink-soft)}
.hd-nav a:hover{color:var(--gold)}

.crumb{max-width:1080px;margin:16px auto 0;padding:0 20px;font-size:.85rem;color:var(--ink-soft)}
.crumb a:hover{color:var(--gold)}

.wrap{max-width:1080px;margin:18px auto 0;padding:0 20px;display:flex;gap:32px;align-items:flex-start}
.cover{flex:0 0 200px;height:280px;border-radius:8px;overflow:hidden;position:relative;
  background:linear-gradient(150deg,#2f8fb8,#14617f);box-shadow:0 6px 18px rgba(20,48,60,.25)}
.cover img{width:100%;height:100%;object-fit:contain;display:block;background:#fff}
.cover .ph{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  color:#e9f4fa;font-size:4rem;font-family:"Noto Serif TC",serif;text-shadow:0 1px 3px rgba(0,0,0,.3)}
.cover .ph::after{content:"";position:absolute;left:18px;top:0;bottom:0;width:1px;background:rgba(255,255,255,.25)}

.info{flex:1;min-width:0}
h1{margin:0 0 4px;font-size:1.7rem;line-height:1.4}
.subtitle{color:var(--ink-soft);font-size:1.05rem;margin-bottom:2px}
.original{color:var(--ink-soft);font-size:.88rem;font-style:italic;margin-bottom:12px}
.meta{margin:14px 0 0;border-top:1px solid var(--line)}
.meta div{display:flex;padding:7px 0;border-bottom:1px solid var(--line);font-size:.92rem}
.meta dt{flex:0 0 92px;color:var(--ink-soft)}
.meta dd{margin:0;flex:1}
.meta a{color:var(--gold);border-bottom:1px dotted var(--gold-soft)}
.tagrow{margin-top:14px;display:flex;gap:8px;flex-wrap:wrap}
.tag{font-size:.8rem;color:var(--gold);border:1px solid var(--gold-soft);
  border-radius:999px;padding:3px 12px;background:rgba(240,192,74,.06)}
.btns{margin-top:18px;display:flex;gap:10px;flex-wrap:wrap}
.btn{display:inline-block;padding:9px 20px;border-radius:8px;font-size:.92rem;cursor:pointer;
  border:1px solid var(--gold);color:var(--gold);background:none;font-family:inherit}
.btn:hover{background:rgba(240,192,74,.08)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
.btn.primary:hover{background:#14617f}

.sec{max-width:1080px;margin:34px auto 0;padding:0 20px}
.sec h2{font-size:1.15rem;margin:0 0 12px;border-left:4px solid var(--gold);padding-left:10px;letter-spacing:.08em}
.sec .body{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:18px 22px;font-size:.95rem}
.summary{white-space:pre-wrap}
.linklist{list-style:none;margin:0;padding:0}
.linklist li{padding:6px 0;border-bottom:1px dashed var(--line)}
.linklist li:last-child{border-bottom:0}
.linklist a{color:var(--gold)}
.linklist .note{color:var(--ink-soft);font-size:.85rem;margin-left:8px}

.msg{max-width:1080px;margin:70px auto;padding:0 20px;text-align:center;color:var(--ink-soft)}
.msg .big{font-size:2.4rem;margin-bottom:10px}
.msg a{color:var(--gold)}

footer{margin-top:60px;border-top:1px solid var(--line);background:var(--card)}
.ft{max-width:1080px;margin:0 auto;padding:22px 20px;font-size:.82rem;color:var(--ink-soft);
  display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.ft a{color:var(--gold)}
.ft-logo{width:26px;height:26px;border-radius:4px;flex:0 0 auto}

@media (max-width:680px){
  .wrap{flex-direction:column;align-items:center}
  .info{width:100%}
  h1{font-size:1.4rem}
}
/* 無障礙：連結不能只靠顏色區分（WCAG 1.4.1） */
.hd-nav a,.ft a,main p a,main li a{text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;text-decoration-color:rgba(14,109,148,.4)}
/* 無障礙：跳到主要內容（WCAG 2.4.1）、鍵盤焦點看得見（2.4.7） */
.skip{position:absolute;left:-9999px;top:8px;z-index:100;background:#14303c;color:#fff;padding:8px 14px;border-radius:6px;text-decoration:none}
.skip:focus{left:12px}
:focus-visible{outline:3px solid #f0c04a;outline-offset:2px}
</style>
<?php if ($book): ?>
<script type="application/ld+json"><?= ssr_jsonld($book, $ogUrl) ?></script>
<?php endif; ?>
</head>
<body>
<a class="skip" href="#main">跳到主要內容</a>
<header>
  <div class="hd-inner">
    <div class="hd-title serif"><a href="/"><img class="brand-logo" src="/assets/logo-books.svg" alt="" aria-hidden="true">屬靈共同書目</a></div>
    <nav class="hd-nav"><a href="/">書目瀏覽</a> ・ <a href="/about.html">關於本站</a> ・ <a href="#" id="langToggle" aria-label="簡繁體切換"></a></nav>
  </div>
</header>

<main id="main">
<?php if ($book): $b = $book; ?>
<nav class="crumb" id="crumb" aria-label="麵包屑"><a href="/">書目</a> › <?php if ($b['category_name']): ?><a href="/?category=<?= (int) $b['category_id'] ?>"><?= e($b['category_name']) ?></a> › <?php endif; ?><?= e($b['title']) ?></nav>
<article class="wrap" id="wrap">
  <div class="cover" id="cover"><?php if ($b['cover_url']): ?><img src="<?= e($b['cover_url']) ?>" alt="<?= e($b['title']) ?> 封面" onerror="this.outerHTML='<div class=ph><?= e(mb_substr($b['title'], 0, 1, 'UTF-8')) ?></div>'"><?php else: ?><div class="ph"><?= e(mb_substr($b['title'], 0, 1, 'UTF-8')) ?></div><?php endif; ?></div>
  <div class="info">
    <h1 class="serif" id="bTitle"><?= e($b['title']) ?></h1>
    <div class="subtitle" id="bSubtitle"<?= $b['subtitle'] ? '' : ' hidden' ?>><?= e($b['subtitle']) ?></div>
    <div class="original" id="bOriginal"<?= $b['original_title'] ? '' : ' hidden' ?>><?= e($b['original_title']) ?></div>
    <dl class="meta" id="bMeta"><?=
        ssr_contributor_rows($b)
      . ssr_meta_row('出版社', ssr_publisher_links($b), true)
      . ssr_meta_row('出版日期', $b['publish_date'])
      . ssr_meta_row('系列', ssr_series($b))
      . ssr_meta_row('頁數', $b['page_count'] !== null ? (string) $b['page_count'] : null)
      . ssr_meta_row('裝訂', $b['binding'])
      . ssr_meta_row('語言', $b['language'])
      . ssr_meta_row('ISBN', implode('、', array_filter([$b['isbn13'], $b['isbn10']])))
      . ssr_meta_row('版本說明', $b['edition_statement'])
    ?></dl>
    <div class="tagrow" id="bTags"><?php
      if ($b['category_name']) { echo '<a class="tag" href="/?category=' . (int) $b['category_id'] . '">' . e($b['category_name']) . '</a>'; }
      foreach ($b['subjects'] ?? [] as $su) { echo '<span class="tag">' . e($su['label'] ?: $su['code']) . '</span>'; }
    ?></div>
    <div class="btns" id="bBtns"><?php
      foreach ($b['buy_links'] ?? [] as $l) {
          $url = is_string($l) ? $l : (string) ($l['url'] ?? '');
          if ($url === '' || !preg_match('#^https?://#i', $url)) { continue; }
          $label = is_string($l) ? '購書連結' : ($l['label'] ?: (SSR_PLATFORM_NAMES[$l['platform'] ?? ''] ?? ($l['platform'] ?: '購書連結')));
          $note = is_string($l) ? '' : (string) ($l['note'] ?? '');
          echo '<a class="btn primary" href="' . e($url) . '"' . ($note !== '' ? ' title="' . e($note) . '"' : '') . ' target="_blank" rel="noopener nofollow">🛒 ' . e($label) . '</a>';
      }
    ?><button class="btn" id="copyBtn">🔗 複製本頁連結</button></div>
  </div>
</article>
<section class="sec" id="secSummary"<?= $b['summary'] ? '' : ' hidden' ?>><h2 class="serif">內容簡介</h2><div class="body summary" id="bSummary"><?= e($b['summary']) ?></div></section>
<?php
  $linkItems = '';
  foreach ($b['links'] ?? [] as $l) {
      if (!preg_match('#^https?://#i', (string) $l['url'])) { continue; }
      $linkItems .= '<li><a href="' . e($l['url']) . '" target="_blank" rel="noopener nofollow">' . e(SSR_PLATFORM_NAMES[$l['platform'] ?? ''] ?? ($l['platform'] ?: ($l['link_type'] ?: $l['url']))) . '</a>'
                  . ($l['note'] ? '<span class="note">' . e($l['note']) . '</span>' : '') . '</li>';
  }
  $edItems = '';
  foreach ($b['editions'] ?? [] as $ed) {
      $bits = implode('・', array_map('e', array_filter([$ed['publisher_name'], $ed['publish_date'], $ed['edition_statement'], $ed['binding'], $ed['page_count'] ? $ed['page_count'] . ' 頁' : null])));
      $ids = implode('、', array_map(static fn($i) => e($i['type']) . ' ' . e($i['value']), $ed['identifiers'] ?? []));
      $fps = implode('、', array_map(static fn($f) => implode(' ', array_map('e', array_filter([$f['media_type'], $f['price'] !== null ? trim(($f['currency'] ?? '') . ' ' . $f['price']) : null]))), $ed['formats'] ?? []));
      $edItems .= '<li>' . $bits . ($ids !== '' ? '<span class="note">' . $ids . '</span>' : '') . ($fps !== '' ? '<span class="note">' . $fps . '</span>' : '') . '</li>';
  }
?>
<section class="sec" id="secLinks"<?= $linkItems !== '' ? '' : ' hidden' ?>><h2 class="serif">延伸連結</h2><div class="body"><ul class="linklist" id="bLinks"><?= $linkItems ?></ul></div></section>
<section class="sec" id="secEditions"<?= $edItems !== '' ? '' : ' hidden' ?>><h2 class="serif">版本資訊</h2><div class="body"><ul class="linklist" id="bEditions"><?= $edItems ?></ul></div></section>
<div class="msg" id="msg" hidden></div>
<script type="application/json" id="bookData"><?= json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php elseif ($dbError): ?>
<?php /* DB 異常：留空殼給前端重試打 API（找不到書時不輸出空殼，頁面只留一個 h1） */ ?>
<div class="crumb" id="crumb" hidden></div>
<div class="wrap" id="wrap" hidden>
  <div class="cover" id="cover"></div>
  <div class="info">
    <h1 class="serif" id="bTitle"></h1>
    <div class="subtitle" id="bSubtitle" hidden></div>
    <div class="original" id="bOriginal" hidden></div>
    <dl class="meta" id="bMeta"></dl>
    <div class="tagrow" id="bTags"></div>
    <div class="btns" id="bBtns"></div>
  </div>
</div>
<div class="sec" id="secSummary" hidden><h2 class="serif">內容簡介</h2><div class="body summary" id="bSummary"></div></div>
<div class="sec" id="secLinks" hidden><h2 class="serif">延伸連結</h2><div class="body"><ul class="linklist" id="bLinks"></ul></div></div>
<div class="sec" id="secEditions" hidden><h2 class="serif">版本資訊</h2><div class="body"><ul class="linklist" id="bEditions"></ul></div></div>
<div class="msg" id="msg" hidden></div>
<?php else: ?>
<div class="msg" id="msg" data-notfound="1"><div class="big">📖</div><h1 style="font-size:1rem;font-weight:400;margin:0">找不到這本書,可能已下架或網址有誤。</h1><p><a href="/">← 回書目瀏覽</a></p></div>
<?php endif; ?>
</main>

<footer><div class="ft">
  <span style="display:inline-flex;align-items:center;gap:8px"><img class="ft-logo" src="/assets/logo-ccnda.png" alt="CCNDA">© 2026 <a href="https://www.ccnda.org" target="_blank" rel="noopener">中華基督教網路發展協會(CCNDA)</a></span>
  <span><a href="/about.html">關於本站</a> ・ <a href="/api/books">開放 API</a> ・ <a href="/changelog.php" title="版本更新紀錄"><?php require_once dirname(__DIR__) . '/app/lib/release.php'; $ver = app_version(); echo $ver !== '' ? 'v' . htmlspecialchars($ver, ENT_QUOTES, 'UTF-8') : '版本資訊'; ?></a></span>
</div></footer>

<script src="/assets/lang.js"></script>
<script>
"use strict";
const API = "/api";
const bookId = (location.pathname.match(/\/book\/(\d+)/) || [])[1]
            || new URLSearchParams(location.search).get("id");

const $ = id => document.getElementById(id);
const esc = s => String(s ?? "").replace(/[&<>"']/g,
  c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const show = (id, on) => { $(id).hidden = !on; };

function metaRow(label, value, html){
  if (value === null || value === undefined || value === "") return "";
  return `<div><dt>${esc(label)}</dt><dd>${html ? value : esc(value)}</dd></div>`;
}

/* 貢獻者:正規化 contributors 優先,平面欄位後備 */
const ROLE_NAMES = { author:"作者", editor:"編者", translator:"譯者", illustrator:"繪者",
  foreword:"序文", advisor:"顧問", proofreader:"校對", contributor:"合著" };
const PLATFORM_NAMES = { campus:"校園書房", logos:"基道 BookFinder" };
function contributorRows(b){
  if (b.contributors){
    return Object.entries(b.contributors).map(([role, ps]) =>
      metaRow(ROLE_NAMES[role] || role,
        ps.map(p => {
          const label = esc(p.name) + (p.credit_text && p.credit_text !== p.name ? "(" + esc(p.credit_text) + ")" : "");
          return p.person_id ? `<a href="/?person=${encodeURIComponent(p.person_id)}">${label}</a>` : label;
        }).join("、"),
        true)  // html:名稱已逐一 esc
    ).join("");
  }
  return metaRow("作者", b.author) + metaRow("譯者", b.translator) + metaRow("編者", b.editors);
}

/* 出版社:有 publisher_id 則做連結(可多版本多出版社),否則純文字後備 */
function publisherLinks(b){
  const seen = new Map();
  for (const ed of (b.editions || [])){
    if (ed.publisher_id && ed.publisher_name && !seen.has(ed.publisher_id))
      seen.set(ed.publisher_id, ed.publisher_name);
  }
  if (seen.size)
    return [...seen].map(([pid, name]) =>
      `<a href="/?publisher=${encodeURIComponent(pid)}">${esc(name)}</a>`).join("、");
  return b.publisher ? esc(b.publisher) : "";
}

function render(b){
  document.title = b.title + "|屬靈共同書目";
  $("crumb").innerHTML = `<a href="/">書目</a> › ` +
    (b.category_name ? `<a href="/?category=${b.category_id}">${esc(b.category_name)}</a> › ` : "") +
    esc(b.title);
  show("crumb", true);

  $("cover").innerHTML = b.cover_url
    ? `<img src="${esc(b.cover_url)}" alt="${esc(b.title)} 封面"
        onerror="this.outerHTML='<div class=ph>${esc(b.title.charAt(0))}</div>'">`
    : `<div class="ph">${esc(b.title.charAt(0))}</div>`;

  $("bTitle").textContent = b.title;
  if (b.subtitle){ $("bSubtitle").textContent = b.subtitle; show("bSubtitle", true); }
  if (b.original_title){ $("bOriginal").textContent = b.original_title; show("bOriginal", true); }

  const series = (b.series_list || []).map(s =>
    s.series_name + (s.series_number ? " #" + s.series_number : "")).join("、")
    || b.series || "";
  $("bMeta").innerHTML =
    contributorRows(b) +
    metaRow("出版社", publisherLinks(b), true) +
    metaRow("出版日期", b.publish_date) +
    metaRow("系列", series) +
    metaRow("頁數", b.page_count) +
    metaRow("裝訂", b.binding) +
    metaRow("語言", b.language) +
    metaRow("ISBN", [b.isbn13, b.isbn10].filter(Boolean).join("、")) +
    metaRow("版本說明", b.edition_statement);

  const tags = [];
  if (b.category_name) tags.push(`<a class="tag" href="/?category=${b.category_id}">${esc(b.category_name)}</a>`);
  for (const su of (b.subjects || [])) tags.push(`<span class="tag">${esc(su.label || su.code)}</span>`);
  $("bTags").innerHTML = tags.join("");

  // 購書連結:API 已彙整所有來源(校園/基道/未來新增,{platform,label,url,note});容忍舊格式字串
  const btns = [];
  for (const l of (Array.isArray(b.buy_links) ? b.buy_links : [])){
    const url = typeof l === "string" ? l : (l.url || "");
    if (!url) continue;
    const label = typeof l === "string" ? "購書連結" : (l.label || PLATFORM_NAMES[l.platform] || l.platform || l.name || "購書連結");
    const note = typeof l === "string" ? "" : (l.note || "");
    btns.push(`<a class="btn primary" href="${esc(url)}"${note ? ` title="${esc(note)}"` : ""} target="_blank" rel="noopener nofollow">🛒 ${esc(label)}</a>`);
  }
  btns.push(`<button class="btn" id="copyBtn">🔗 複製本頁連結</button>`);
  $("bBtns").innerHTML = btns.join("");
  $("copyBtn").addEventListener("click", async () => {
    try { await navigator.clipboard.writeText("https://books.zh.church/book/" + b.book_id);
          $("copyBtn").textContent = "✓ 已複製"; } catch(_){}
  });
  show("wrap", true);

  if (b.summary){ $("bSummary").textContent = b.summary; show("secSummary", true); }

  // 延伸連結(推薦影音/文章 + 版本層連結)
  const links = (b.links || []).map(l =>
    `<li><a href="${esc(l.url)}" target="_blank" rel="noopener nofollow">${esc(PLATFORM_NAMES[l.platform] || l.platform || l.link_type || l.url)}</a>` +
    (l.note ? `<span class="note">${esc(l.note)}</span>` : "") + `</li>`).join("");
  if (links){ $("bLinks").innerHTML = links; show("secLinks", true); }

  // 版本資訊
  const eds = (b.editions || []).map(ed => {
    const bits = [ed.publisher_name, ed.publish_date, ed.edition_statement, ed.binding,
      ed.page_count ? ed.page_count + " 頁" : null].filter(Boolean).map(esc).join("・");
    const ids = (ed.identifiers || []).map(i => `${esc(i.type)} ${esc(i.value)}`).join("、");
    const fps = (ed.formats || []).map(f =>
      [f.media_type, f.price !== null && f.price !== undefined ? (f.currency || "") + " " + f.price : null]
      .filter(Boolean).map(esc).join(" ")).join("、");
    return `<li>${bits}${ids ? `<span class="note">${ids}</span>` : ""}${fps ? `<span class="note">${esc(fps)}</span>` : ""}</li>`;
  }).join("");
  if (eds){ $("bEditions").innerHTML = eds; show("secEditions", true); }
}

async function main(){
  // 伺服器已輸出內容並嵌入資料（#bookData）：直接用，不再打 API；伺服器已判定找不到（data-notfound）就不用再查
  const embedded = $("bookData");
  if (embedded){
    render(JSON.parse(embedded.textContent));
  } else {
    if ($("msg").dataset.notfound) return;
    if (!bookId){ return fail(); }
    let r;
    try { r = await fetch(API + "/books/" + bookId); } catch(_){ return fail(); }
    if (!r.ok) return fail(r.status === 404);
    const j = await r.json();
    if (!j.data) return fail();
    render(j.data);
  }

  // 點閱遙測:僅站外直達/分享進入時記(站內卡片點擊已在清單頁記過)
  const ref = document.referrer;
  if (!ref || new URL(ref).host !== location.host){
    try { navigator.sendBeacon(API + "/books/" + bookId + "/click",
      new Blob([JSON.stringify({source:"detail"})], {type:"application/json"})); } catch(_){}
  }
}
function fail(notFound){
  $("msg").innerHTML = `<div class="big">📖</div>` +
    (notFound !== false ? `<div>找不到這本書,可能已下架或網址有誤。</div>` : `<div>資料載入失敗,請稍後再試。</div>`) +
    `<p><a href="/">← 回書目瀏覽</a></p>`;
  show("msg", true);
}
main();
</script>
</body>
</html>
