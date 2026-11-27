<?php
declare(strict_types=1);

/**
 * 作者頁 person.php／出版社頁 publisher.php 共用的伺服器端輸出（2026-11-27 小芯，Asana 1220588345978224）。
 * 跟 book.php 一樣全部由 PHP 輸出（AI 爬蟲、報讀軟體不執行 JS 也看得到內容；熊哥 10/11 AIO SEO＋無障礙要求）。
 * 簡介沒有來源，先不放（熊叔叔 11/24 TG「甲」）。短網址 /person/{id}、/publisher/{id} 要改 nginx，先用 ?id=。
 * 書單只列最新 ENTITY_PAGE_BOOKS 本，「看全部」連到首頁篩選（有分頁、年份、書房篩選）。
 */

require_once __DIR__ . '/entity_detail.php';
require_once __DIR__ . '/release.php';

const ENTITY_ROLE_NAMES = ['author' => '作者', 'editor' => '編者', 'translator' => '譯者', 'illustrator' => '繪者',
                           'foreword' => '序文', 'advisor' => '顧問', 'proofreader' => '校對', 'contributor' => '合著'];

function ent_e(?string $s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function render_entity_page(string $type): void
{
    $isPerson = $type === 'person';
    $id = (int) ($_GET['id'] ?? 0);
    $d = null;
    $dbError = false;
    $books = [];
    if ($id > 0) {
        try {
            $d = $isPerson ? person_detail($id) : publisher_detail($id);
            if ($d) {
                $books = entity_books($type, $isPerson ? $id : (int) $d['publisher_id']);
            }
        } catch (Throwable $ex) {
            error_log('[' . $type . '.php] ' . $ex->getMessage());
            $d = null;
            $dbError = true;
        }
    }
    if (!$d) {
        // DB 異常回 503（暫時無法服務），不要回 404：404 會讓搜尋引擎把頁面移出索引
        http_response_code($dbError ? 503 : 404);
    }
    $kind  = $isPerson ? '作者' : '出版社';
    $self  = $isPerson ? 'person' : 'publisher';
    $canonId = $d ? ($isPerson ? $d['person_id'] : $d['publisher_id']) : $id;
    $url   = 'https://books.zh.church/' . $self . '.php?id=' . $canonId;
    $listUrl = '/?' . $self . '=' . $canonId;
    $name  = $d ? (string) $d['name'] : ($dbError ? '暫時無法載入' : '找不到這位' . $kind);
    if (!$isPerson && !$d && !$dbError) {
        $name = '找不到這家出版社';
    }

    // meta description：一句話說清楚是誰、有幾本
    $desc = '';
    if ($d && $isPerson) {
        $rs = implode('、', array_map(static fn(array $r): string =>
            (ENTITY_ROLE_NAMES[$r['role']] ?? $r['role']) . ' ' . $r['book_count'] . ' 本', $d['roles']));
        $desc = $d['name'] . ($d['name_en'] ? '（' . $d['name_en'] . '）' : '') . '在屬靈共同書目收錄 ' . $d['book_count'] . ' 本書' . ($rs !== '' ? '：' . $rs : '') . '。';
    } elseif ($d) {
        $yr = $d['year_from'] ? '，出版年份 ' . $d['year_from'] . '–' . $d['year_to'] : '';
        $cs = implode('、', array_column($d['top_categories'], 'name'));
        $desc = $d['name'] . '在屬靈共同書目收錄 ' . $d['book_count'] . ' 本書' . $yr . ($cs !== '' ? '；常出：' . $cs : '') . '。';
    }

    $ld = '';
    if ($d) {
        $ent = $isPerson
            ? ['@type' => 'Person', '@id' => $url . '#person', 'name' => $d['name'], 'url' => $url]
            : ['@type' => 'Organization', '@id' => $url . '#org', 'name' => $d['name'], 'url' => $url];
        if ($isPerson && $d['name_en']) { $ent['alternateName'] = $d['name_en']; }
        $crumbs = [['@type' => 'ListItem', 'position' => 1, 'name' => '書目', 'item' => 'https://books.zh.church/'],
                   ['@type' => 'ListItem', 'position' => 2, 'name' => $d['name'], 'item' => $url]];
        $ld = json_encode(['@context' => 'https://schema.org', '@graph' => [$ent, ['@type' => 'BreadcrumbList', 'itemListElement' => $crumbs]]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    }
    $ver = app_version();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<script async src="https://www.googletagmanager.com/gtag/js?id=G-58CPNJ5D6F"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-58CPNJ5D6F');</script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= ent_e($name) ?>｜<?= $kind ?>｜屬靈共同書目</title>
<meta name="description" content="<?= ent_e($desc) ?>">
<meta property="og:site_name" content="屬靈共同書目">
<meta property="og:type" content="<?= $isPerson ? 'profile' : 'website' ?>">
<meta property="og:title" content="<?= ent_e($name) ?>">
<meta property="og:description" content="<?= ent_e($desc) ?>">
<meta property="og:url" content="<?= ent_e($url) ?>">
<meta property="og:image" content="https://books.zh.church/assets/og-image.png">
<meta name="twitter:card" content="summary">
<?php if ($d): ?><link rel="canonical" href="<?= ent_e($url) ?>"><?php else: ?><meta name="robots" content="noindex"><?php endif; ?>
<link rel="icon" type="image/svg+xml" href="/assets/logo-books.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">
<link rel="shortcut icon" href="/assets/favicon.ico">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
<meta name="theme-color" content="#177da8">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+TC:wght@500;700&family=Noto+Sans+TC:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root{--paper:#f2f6f9;--card:#ffffff;--ink:#14303c;--ink-soft:#5c7482;--gold:#0e6d94;--gold-soft:#f0c04a;--line:#e2e8ee;--accent:#177da8}
*{box-sizing:border-box}
[hidden]{display:none!important}
body{margin:0;background:var(--paper);color:var(--ink);font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif;line-height:1.7}
a{color:inherit;text-decoration:none}
.serif{font-family:"Noto Serif TC","PMingLiU",serif}
header{border-bottom:3px double var(--gold-soft);background:var(--card)}
.hd-inner{max-width:1080px;margin:0 auto;padding:20px 20px 14px;display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap}
.hd-title{margin:0;font-size:1.35rem;letter-spacing:.12em;font-weight:700;line-height:normal}
.hd-title a{display:inline-flex;align-items:center;gap:.4em;color:var(--ink)}
.brand-logo{height:34px;width:34px;flex:0 0 auto}
.hd-nav{font-size:.88rem;color:var(--ink-soft)}
.hd-nav a:hover{color:var(--gold)}
.crumb{max-width:1080px;margin:16px auto 0;padding:0 20px;font-size:.85rem;color:var(--ink-soft)}
.crumb a:hover{color:var(--gold)}
.head{max-width:1080px;margin:18px auto 0;padding:0 20px}
.kind{display:inline-block;font-size:.8rem;color:var(--gold);border:1px solid var(--gold-soft);border-radius:999px;padding:2px 12px;background:rgba(240,192,74,.06)}
h1{margin:8px 0 2px;font-size:1.7rem;line-height:1.4}
.en{color:var(--ink-soft);font-size:1rem;font-style:italic}
.stats{margin:12px 0 0;display:flex;gap:10px;flex-wrap:wrap;padding:0;list-style:none}
.stats li{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:6px 14px;font-size:.92rem}
.stats b{font-size:1.15rem;color:var(--gold);margin-right:2px}
.sec{max-width:1080px;margin:30px auto 0;padding:0 20px}
.sec h2{font-size:1.15rem;margin:0 0 12px;border-left:4px solid var(--gold);padding-left:10px;letter-spacing:.08em}
.sec .body{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px 20px;font-size:.95rem}
.chips{display:flex;gap:8px;flex-wrap:wrap;margin:0;padding:0;list-style:none}
.chips a{display:inline-block;font-size:.88rem;color:var(--gold);border:1px solid var(--gold-soft);border-radius:999px;padding:3px 12px;background:rgba(240,192,74,.06)}
.chips a:hover{background:rgba(240,192,74,.16)}
.chips .n{color:var(--ink-soft);font-size:.8rem;margin-left:4px}
.books{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:16px}
.books a{display:block;color:var(--ink)}
.books .cv{aspect-ratio:5/7;border-radius:6px;overflow:hidden;background:linear-gradient(150deg,#2f8fb8,#14617f);display:flex;align-items:center;justify-content:center;color:#e9f4fa;font-size:2.2rem;font-family:"Noto Serif TC",serif;box-shadow:0 3px 10px rgba(20,48,60,.18)}
.books .cv img{width:100%;height:100%;object-fit:contain;background:#fff;display:block}
.books .t{margin-top:6px;font-size:.9rem;line-height:1.45;font-weight:500}
.books .m{font-size:.8rem;color:var(--ink-soft);line-height:1.4}
.books a:hover .t{color:var(--gold)}
.more{margin-top:16px}
.btn{display:inline-block;padding:9px 20px;border-radius:8px;font-size:.92rem;background:var(--accent);color:#fff}
.btn:hover{background:#14617f}
.msg{max-width:1080px;margin:70px auto;padding:0 20px;text-align:center;color:var(--ink-soft)}
.msg .big{font-size:2.4rem;margin-bottom:10px}
.msg a{color:var(--gold)}
footer{margin-top:60px;border-top:1px solid var(--line);background:var(--card)}
.ft{max-width:1080px;margin:0 auto;padding:22px 20px;font-size:.82rem;color:var(--ink-soft);display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.ft a{color:var(--gold)}
.ft-logo{width:26px;height:26px;border-radius:4px;flex:0 0 auto}
@media (max-width:680px){h1{font-size:1.4rem}.books{grid-template-columns:repeat(2,1fr)}}
.hd-nav a,.ft a,.msg a{text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;text-decoration-color:rgba(14,109,148,.4)}
.skip{position:absolute;left:-9999px;top:8px;z-index:100;background:#14303c;color:#fff;padding:8px 14px;border-radius:6px;text-decoration:none}
.skip:focus{left:12px}
:focus-visible{outline:3px solid #f0c04a;outline-offset:2px}
</style>
<?php if ($ld !== ''): ?><script type="application/ld+json"><?= $ld ?></script><?php endif; ?>
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
<?php if ($d): ?>
<nav class="crumb" aria-label="麵包屑"><a href="/">書目</a> › <?= ent_e($d['name']) ?></nav>
<div class="head">
  <span class="kind"><?= $kind ?></span>
  <h1 class="serif"><?= ent_e($d['name']) ?></h1>
<?php if ($isPerson && $d['name_en']): ?>  <div class="en" lang="en"><?= ent_e($d['name_en']) ?></div>
<?php endif; ?>
  <ul class="stats" aria-label="收錄統計">
    <li><b><?= (int) $d['book_count'] ?></b> 本書</li>
<?php if ($isPerson): foreach ($d['roles'] as $r): ?>
    <li><?= ent_e(ENTITY_ROLE_NAMES[$r['role']] ?? $r['role']) ?> <b><?= (int) $r['book_count'] ?></b> 本</li>
<?php endforeach; elseif ($d['year_from']): ?>
    <li>出版年份 <b><?= (int) $d['year_from'] ?></b>–<b><?= (int) $d['year_to'] ?></b></li>
<?php endif; ?>
  </ul>
</div>
<?php if ($isPerson && $d['collaborators']): ?>
<section class="sec" aria-labelledby="hCo"><h2 class="serif" id="hCo">合作過的人</h2><div class="body"><ul class="chips">
<?php foreach ($d['collaborators'] as $c): ?>
  <li><a href="/person.php?id=<?= (int) $c['person_id'] ?>"><?= ent_e($c['name']) ?><span class="n">（<?= (int) $c['shared_books'] ?> 本）</span></a></li>
<?php endforeach; ?>
</ul></div></section>
<?php endif; ?>
<?php if (!$isPerson && $d['top_categories']): ?>
<section class="sec" aria-labelledby="hCat"><h2 class="serif" id="hCat">常出的分類</h2><div class="body"><ul class="chips">
<?php foreach ($d['top_categories'] as $c): ?>
  <li><a href="<?= ent_e($listUrl) ?>&amp;category=<?= (int) $c['category_id'] ?>"><?= ent_e($c['name']) ?><span class="n">（<?= (int) $c['book_count'] ?> 本）</span></a></li>
<?php endforeach; ?>
</ul></div></section>
<?php endif; ?>
<section class="sec" aria-labelledby="hBooks"><h2 class="serif" id="hBooks"><?= $d['book_count'] > count($books) ? '最新 ' . count($books) . ' 本' : '收錄的書' ?></h2>
<ul class="books">
<?php foreach ($books as $bk): $t = (string) $bk['title']; ?>
  <li><a href="/book/<?= (int) $bk['book_id'] ?>"><div class="cv" data-ph="<?= ent_e(mb_substr($t, 0, 1, 'UTF-8')) ?>"><?php if ($bk['cover_url'] && preg_match('#^https?://#i', (string) $bk['cover_url'])): ?><img src="<?= ent_e($bk['cover_url']) ?>" alt="" loading="lazy" onerror="this.replaceWith(document.createTextNode(this.parentNode.dataset.ph||''))"><?php else: ?><?= ent_e(mb_substr($t, 0, 1, 'UTF-8')) ?><?php endif; ?></div><div class="t"><?= ent_e($t) ?></div><div class="m"><?= ent_e(implode('・', array_filter([$isPerson ? null : $bk['author'], $bk['publish_date']]))) ?></div></a></li>
<?php endforeach; ?>
</ul>
<?php if ($d['book_count'] > count($books)): ?>
<p class="more"><a class="btn" href="<?= ent_e($listUrl) ?>">看全部 <?= (int) $d['book_count'] ?> 本（可再用年份、書房篩選）</a></p>
<?php endif; ?>
</section>
<?php elseif ($dbError): ?>
<div class="msg"><div class="big">⏳</div><h1 style="font-size:1rem;font-weight:400;margin:0">暫時無法載入，請稍後再試。</h1><p><a href="/">← 回書目瀏覽</a></p></div>
<?php else: ?>
<div class="msg"><div class="big">📖</div><h1 style="font-size:1rem;font-weight:400;margin:0"><?= ent_e($name) ?>，可能網址有誤。</h1><p><a href="/">← 回書目瀏覽</a></p></div>
<?php endif; ?>
</main>
<footer><div class="ft">
  <span style="display:inline-flex;align-items:center;gap:8px"><img class="ft-logo" src="/assets/logo-ccnda.png" alt="CCNDA">© 2026 <a href="https://www.ccnda.org" target="_blank" rel="noopener">中華基督教網路發展協會(CCNDA)</a></span>
  <span><a href="/about.html">關於本站</a> ・ <a href="/api.html">開放 API</a> ・ <a href="/changelog.php" title="版本更新紀錄"><?= $ver !== '' ? 'v' . ent_e($ver) : '版本資訊' ?></a></span>
</div></footer>
<script src="/assets/lang.js"></script>
</body>
</html>
<?php
}
