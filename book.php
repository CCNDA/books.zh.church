<?php
declare(strict_types=1);

/**
 * 書目詳情頁(/book/{id} 短網址,nginx rewrite → book.php?id=N)
 * PHP 僅負責 og/meta(社群分享預覽)與 404 狀態碼;內容由前端呼叫 /api/books/{id} 渲染。
 */

$id   = (int) ($_GET['id'] ?? 0);
$book = null;
if ($id > 0) {
    try {
        require __DIR__ . '/api/lib/db.php';
        $stmt = db()->prepare(
            'SELECT v.book_id, v.title, v.subtitle, v.author, v.publisher, v.cover_url,
                    v.summary_short, b.summary
             FROM v_book_list v JOIN books b ON b.book_id = v.book_id
             WHERE v.book_id = :id AND v.is_published = 1'
        );
        $stmt->execute([':id' => $id]);
        $book = $stmt->fetch() ?: null;
    } catch (Throwable $ex) {
        $book = null; // DB 異常時仍出頁面,由前端重試
    }
}
if (!$book) {
    http_response_code(404);
}

function e(?string $s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
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
.hd-title{margin:0;font-size:1.35rem;letter-spacing:.12em}
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
</style>
</head>
<body>
<header>
  <div class="hd-inner">
    <h1 class="hd-title serif"><a href="/"><img class="brand-logo" src="/assets/logo-books.svg" alt="" aria-hidden="true">屬靈共同書目</a></h1>
    <nav class="hd-nav"><a href="/">書目瀏覽</a> ・ <a href="/about.html">關於本站</a></nav>
  </div>
</header>

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

<footer><div class="ft">
  <span style="display:inline-flex;align-items:center;gap:8px"><img class="ft-logo" src="/assets/logo-ccnda.png" alt="CCNDA">© 2026 <a href="https://www.ccnda.org" target="_blank" rel="noopener">中華基督教網路發展協會(CCNDA)</a></span>
  <span><a href="/about.html">關於本站</a> ・ <a href="/api/books">開放 API</a></span>
</div></footer>

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

  // 購書連結(buy_links JSON:容忍字串或物件)+ 複製連結
  const btns = [];
  for (const l of (Array.isArray(b.buy_links) ? b.buy_links : [])){
    const url = typeof l === "string" ? l : (l.url || "");
    if (!url) continue;
    const label = typeof l === "string" ? "購書連結" : (l.label || PLATFORM_NAMES[l.platform] || l.platform || l.name || "購書連結");
    btns.push(`<a class="btn primary" href="${esc(url)}" target="_blank" rel="noopener nofollow">🛒 ${esc(label)}</a>`);
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
  if (!bookId){ return fail(); }
  let r;
  try { r = await fetch(API + "/books/" + bookId); } catch(_){ return fail(); }
  if (!r.ok) return fail(r.status === 404);
  const j = aw