<?php
declare(strict_types=1);
/**
 * 版本更新紀錄頁(footer 版號點進來)。
 * 版本清單來自 CHANGELOG.md、讀者版說明來自 release-notes/,都在網站根目錄之外,由 PHP 讀取。
 * 樣式與 header/footer 沿用 about.html。
 */
require dirname(__DIR__) . '/app/lib/release.php';

$version  = app_version();
$releases = release_list();
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<!-- Google Analytics（GA4 G-58CPNJ5D6F，熊哥 2026-10-11；Asana 1219388348551239）。只裝前台，後台 /admin/ 不裝。 -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-58CPNJ5D6F"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-58CPNJ5D6F');</script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>版本更新紀錄|屬靈共同書目</title>
<meta name="description" content="屬靈共同書目各版本的更新紀錄:新增的書目來源、功能與修正。">
<link rel="canonical" href="https://books.zh.church/changelog.php">
<meta property="og:site_name" content="屬靈共同書目">
<meta property="og:type" content="website">
<meta property="og:title" content="版本更新紀錄|屬靈共同書目">
<meta property="og:description" content="屬靈共同書目各版本的更新紀錄:新增的書目來源、功能與修正。">
<meta property="og:url" content="https://books.zh.church/changelog.php">
<meta property="og:image" content="https://books.zh.church/assets/og-image.png">
<meta name="twitter:card" content="summary">
<link rel="icon" type="image/svg+xml" href="/assets/logo-books.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16x16.png">
<link rel="shortcut icon" href="/assets/favicon.ico">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
<link rel="manifest" href="/assets/site.webmanifest">
<style>
:root{
  --paper:#f2f6f9; --card:#ffffff; --ink:#14303c; --ink-soft:#5c7482;
  --gold:#0e6d94; --gold-soft:#f0c04a; --line:#e2e8ee; --accent:#177da8;
}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);
  font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif;line-height:1.9}
a{color:var(--gold);text-decoration:none}
.serif{font-family:"Noto Serif TC","PMingLiU",serif}
header{border-bottom:3px double var(--gold-soft);background:var(--card)}
.hd-inner{max-width:860px;margin:0 auto;padding:20px 20px 14px;
  display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap}
.hd-title{margin:0;font-size:1.35rem;letter-spacing:.12em;font-weight:700;line-height:normal}
.hd-title a{color:var(--ink);display:inline-flex;align-items:center;gap:.4em}
.brand-logo{height:34px;width:34px;flex:0 0 auto}
.ft-logo{width:26px;height:26px;border-radius:4px;flex:0 0 auto}
.hd-nav{font-size:.88rem}
main{max-width:860px;margin:36px auto 0;padding:0 20px}
h1{font-size:1.6rem;letter-spacing:.1em;margin:0 0 10px}
.now{color:var(--ink-soft);margin:0 0 24px}
.rel{background:var(--card);border:1px solid var(--line);border-radius:10px;margin:0 0 12px;padding:4px 18px}
.rel summary{cursor:pointer;padding:10px 0;list-style:none;display:flex;gap:12px;align-items:baseline;flex-wrap:wrap}
.rel summary::-webkit-details-marker{display:none}
.rel summary::before{content:"▸";color:var(--ink-soft);transition:transform .15s}
.rel[open] summary::before{transform:rotate(90deg)}
.ver{font-weight:700;font-variant-numeric:tabular-nums;color:var(--gold)}
.date{color:var(--ink-soft);font-size:.86rem;font-variant-numeric:tabular-nums}
.ttl{flex:1 1 16em}
.rel.plain{padding:10px 18px}
.rel.plain .row{display:flex;gap:12px;align-items:baseline;flex-wrap:wrap}
.note{border-top:1px solid var(--line);padding:6px 0 10px;font-size:.95rem}
.rh{display:flex;gap:12px;align-items:baseline;flex-wrap:wrap;margin:0;font-size:inherit;font-weight:inherit;flex:1 1 auto}
.note h3,.note h4{font-size:1rem;margin:16px 0 6px;letter-spacing:.04em}
.note p{margin:0 0 10px}
.note ul{margin:0 0 10px;padding-left:1.4em}
.empty{color:var(--ink-soft)}
footer{margin-top:64px;border-top:1px solid var(--line);background:var(--card)}
.ft{max-width:860px;margin:0 auto;padding:22px 20px;font-size:.82rem;color:var(--ink-soft);
  display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
/* 無障礙：連結不能只靠顏色區分（WCAG 1.4.1） */
.hd-nav a,.ft a,main p a,main li a{text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;text-decoration-color:rgba(14,109,148,.4)}
/* 無障礙：跳到主要內容（WCAG 2.4.1）、鍵盤焦點看得見（2.4.7） */
.skip{position:absolute;left:-9999px;top:8px;z-index:100;background:#14303c;color:#fff;padding:8px 14px;border-radius:6px;text-decoration:none}
.skip:focus{left:12px}
:focus-visible{outline:3px solid #f0c04a;outline-offset:2px}
</style>
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
  <h1 class="serif">版本更新紀錄</h1>
  <p class="now">目前版本:<strong><?= $version !== '' ? 'v' . $h($version) : '(讀不到版本)' ?></strong></p>
<?php if (!$releases): ?>
  <p class="empty">暫時讀不到更新紀錄。</p>
<?php endif; ?>
<?php foreach ($releases as $i => $r): ?>
<?php if ($r['note'] !== null): ?>
  <details class="rel"<?= $i === 0 ? ' open' : '' ?>>
    <summary><h2 class="rh"><span class="ver">v<?= $h($r['version']) ?></span><span class="date"><?= $h($r['date'] ?? '') ?></span><span class="ttl"><?= $h($r['title']) ?></span></h2></summary>
    <div class="note"><?= release_note_html($r['note']) ?></div>
  </details>
<?php else: ?>
  <div class="rel plain"><h2 class="rh row"><span class="ver">v<?= $h($r['version']) ?></span><span class="date"><?= $h($r['date'] ?? '') ?></span><span class="ttl"><?= $h($r['title']) ?></span></h2></div>
<?php endif; ?>
<?php endforeach; ?>
</main>
<footer><div class="ft">
  <span style="display:inline-flex;align-items:center;gap:8px"><img class="ft-logo" src="/assets/logo-ccnda.png" alt="CCNDA">© 2026 <a href="https://www.ccnda.org" target="_blank" rel="noopener">中華基督教網路發展協會(CCNDA)</a></span>
  <span><a href="/about.html">關於本站</a> ・ <a href="/api.html">開放 API</a> ・ <span><?= $version !== '' ? 'v' . $h($version) : '版本資訊' ?></span></span>
</div></footer>
<script src="/assets/lang.js"></script>
</body>
</html>
