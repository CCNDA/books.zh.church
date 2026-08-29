# -*- coding: utf-8 -*-
"""臺灣福音書房(twgbr.org.tw)爬蟲——第十三個書目來源,Shopline 平台。

2026-08-24 偵察事實(WebFetch 實測,robots.txt 允許):
- Shopline 商城,網址一律走 locale 前綴:商品 /zh-hant/products/{handle}、
  分類 /zh-hant/categories/{handle 或 24 碼 ID};總覽 /zh-hant/products。
- 分類頁是 SSR,`?limit=72` 生效(「生命讀經」67 件一次全出),分類名旁有件數。
- 全站約 1,443 件:書籍 ≈1,111、非書 ≈332(影音 206、電子 66、用品 60)。

熊哥決議(2026-08-24):
1. 全站抓入存證,非書(影音/電子/用品,含「維修費付款區」)由對映表 unpublish。
2. 本站線上不放 ISBN → 跨站合併走「書名完全相同 + 出版社相同」的嚴格比對,
   寧缺勿錯(見 import.php 的 --strict-title 需求;合併明細一定要看 dry-run)。
3. 外文書(英文書報/英文聖經詩歌/英文特價書/日語/其他語言,共 79 件)
   抓入但下架,沿衛理書房做法。

三個陷阱(務必保留):
1. **sitemap 不是權威清單**(與真哪噠相反):sitemap.xml 是 en/zh-hant/default
   三分片的索引檔,zh-hant 分片只有約 30 個 products → 全站 1,400+ 件沒進
   sitemap。清單只能走「分類聯集」,並用分類頁自報的件數對帳。
2. **超界頁 = 空頁 + 文案「抱歉,這個商品類別沒有相關商品」**(不是 404,
   也不是像真哪噠那樣夾回末頁)→ 停止條件用該文案或「本頁 0 個新商品」。
3. **中文 handle 編碼不對時站方回首頁內容而且 HTTP 200**(實測
   /products/1322-26-3 正常、/products/4063-5-新約總論 回首頁)→ 每個商品頁
   都要先驗「這真的是商品頁」,否則會把首頁當商品解析、憑空生出假書。

用法:
    python3 twgbr_crawler.py --probe            # 偵察:分類樹+件數+欄位命中率
    python3 twgbr_crawler.py                    # 全量(約 1,443 件)
    python3 twgbr_crawler.py --limit 30         # 只抓 30 件試水溫
    python3 twgbr_crawler.py --force-lists      # 清單頁不走快取(每日新品用)
"""
from __future__ import annotations

import argparse
import json
import re
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import quote, unquote, urljoin

from bs4 import BeautifulSoup

from common import JsonlWriter, make_session, polite_fetch

BASE = "https://www.twgbr.org.tw"
LOC = "/zh-hant"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "twgbr"
DATA = HERE / "data"
THROTTLE = (2.0, 3.0)
PER_PAGE = 72          # ?limit=72 實測生效
PAGE_CAP = 40          # 單一分類最多翻幾頁(72×40 = 2,880,遠大於全站量)
EMPTY_MARK = "沒有相關商品"   # 超界/空分類的站方文案

session = make_session()


def fetch(url: str, force: bool = False) -> str | None:
    try:
        return polite_fetch(session, url, CACHE, THROTTLE, force=force)
    except RuntimeError as e:
        print(f"  [抓取失敗] {url} → {e}", flush=True)
        return None


# ── 網址與正則 ───────────────────────────────────────────────

PROD_RE = re.compile(r"/(?:zh-hant/)?products/([^\"'?#\s>]+)")
CAT_RE = re.compile(r"/(?:zh-hant/)?categories/([^\"'?#\s>]+)")


def cat_url(handle: str, page: int = 1) -> str:
    return f"{BASE}{LOC}/categories/{quote(handle, safe='')}?limit={PER_PAGE}&page={page}"


def product_url(handle: str) -> str:
    return f"{BASE}{LOC}/products/{quote(handle, safe='')}"


# 8/24 probe 實測:每個分類清單頁都固定多 1 個非清單商品連結(推薦/樣板),
# 連「超界空頁」也有 1 筆 → 以超界頁的商品連結集合當雜訊基準,每頁一律扣除
# (同宇宙光 topsection 假商品格,但這站用超界頁就能自動測出,不必寫死選擇器)。
NOISE: set[str] = set()


def learn_noise(handle: str) -> set[str]:
    html = fetch(cat_url(handle, 999))
    if html is None:
        return set()
    n = {unquote(h) for h in PROD_RE.findall(html)}
    print(f"[雜訊基準] 超界頁固定夾帶 {len(n)} 個非清單商品連結:{sorted(n)}", flush=True)
    return n


def _uniq(seq):
    out, seen = [], set()
    for x in seq:
        if x not in seen:
            seen.add(x)
            out.append(x)
    return out


# ── 1. 分類發現(不寫死選單:從導覽列抓)────────────────────

SEED_PAGES = [f"{BASE}{LOC}/", f"{BASE}{LOC}/products"]

# 非書大類與外文大類(對映表會 unpublish;此處僅標記供 probe 對帳與統計)
NONBOOK_HINT = ("影音", "電子", "用品", "播放器", "維修費", "月曆", "日誌",
                "福音單張", "DVD", "VCD", "有聲書", "詩歌", "職事信息")
FOREIGN_HINT = ("英文", "日語", "其他語言", "外語")


def discover_categories() -> list[dict]:
    """從導覽列蒐集所有 /categories/{handle},再逐一開頁取名稱與件數。"""
    handles: list[str] = []
    for url in SEED_PAGES:
        html = fetch(url)
        if html:
            handles += [unquote(h) for h in CAT_RE.findall(html)]
    handles = [h for h in _uniq(handles) if h and not h.startswith("http")]
    print(f"導覽列共發現 {len(handles)} 個分類 handle", flush=True)

    global NOISE
    if handles and not NOISE:
        NOISE = learn_noise(handles[0])

    nav_counts = _nav_counts(SEED_PAGES)
    cats: list[dict] = []
    for i, h in enumerate(handles, 1):
        html = fetch(cat_url(h, 1))
        if html is None:
            print(f"  [略過分類] {h}(抓取失敗)", flush=True)
            continue
        soup = BeautifulSoup(html, "lxml")
        name = _cat_name(soup) or h
        count = nav_counts.get(name) or _cat_count(soup)
        n_items = len({unquote(x) for x in PROD_RE.findall(html)} - NOISE)
        cats.append({
            "code": h[:40],
            "handle": h,
            "name": name,
            "count": count,          # 站方自報件數(可能為 None)
            "first_page": n_items,
            "nonbook": any(k in name or k in h for k in NONBOOK_HINT),
            "foreign": any(k in name or k in h for k in FOREIGN_HINT),
            "order": i,
        })
        print(f"  [{i}/{len(handles)}] {name}(站方 {count} 件,首頁 {n_items} 筆)", flush=True)
    return cats


_NAV_RE = re.compile(r"([\u4e00-\u9fffA-Za-z0-9．／/\u3001+&\-]{2,20})\s*[\(（]?\s*(\d{1,4})\s*(?:件|個)?\s*[\)）]?")


def _nav_counts(urls: list[str]) -> dict[str, int]:
    """從導覽列文字取「分類名 → 件數」。站方格式未經原始 HTML 確認,
    因此為 best-effort:對不上就退回 None,不影響抓取(停止條件靠空頁文案)。"""
    out: dict[str, int] = {}
    for u in urls:
        html = fetch(u)
        if not html:
            continue
        text = BeautifulSoup(html, "lxml").get_text("\n", strip=True)
        for line in text.split("\n"):
            m = _NAV_RE.fullmatch(line.strip())
            if m:
                out.setdefault(m.group(1).strip(), int(m.group(2)))
    if out:
        print(f"[導覽列件數] 解析到 {len(out)} 個分類的件數", flush=True)
    return out


def _cat_name(soup: BeautifulSoup) -> str | None:
    for sel in ("h1", ".category-title", ".breadcrumb li:last-child", "title"):
        el = soup.select_one(sel)
        if el:
            t = re.sub(r"\s+", " ", el.get_text(" ", strip=True))
            t = re.sub(r"\s*[-|–—]\s*臺灣福音書房.*$", "", t).strip()
            if t:
                return t[:60]
    return None


_COUNT_RE = re.compile(r"(\d{1,5})\s*(?:件|個商品|products?)", re.I)


def _cat_count(soup: BeautifulSoup) -> int | None:
    """分類頁自報件數:先找常見容器,再退回全頁文字比對。"""
    for sel in (".category-count", ".products-count", ".product-count", ".summary"):
        el = soup.select_one(sel)
        if el:
            m = _COUNT_RE.search(el.get_text(" ", strip=True))
            if m:
                return int(m.group(1))
    m = _COUNT_RE.search(soup.get_text(" ", strip=True))
    return int(m.group(1)) if m else None


# ── 2. 分類走訪 ──────────────────────────────────────────────

def walk_category(cat: dict, force: bool = False) -> list[str]:
    """翻頁收 handle。停止條件(三者任一):站方空頁文案、本頁 0 個新 handle、
    已達站方自報件數。不可用「首次無新項即停」以外的猜測——本站空分類會直接
    給文案,超界頁同理(陷阱 2)。"""
    got: list[str] = []
    for page in range(1, PAGE_CAP + 1):
        html = fetch(cat_url(cat["handle"], page), force=force)
        if html is None:
            break
        if EMPTY_MARK in html:
            break
        found = [h for h in _uniq(unquote(x) for x in PROD_RE.findall(html))
                 if h not in NOISE]
        new = [h for h in found if h not in got]
        if not new:
            break
        got += new
        if cat["count"] and len(got) >= cat["count"]:
            break
    return got


def collect_memberships(force_lists: bool = False) -> tuple[dict, list[dict]]:
    """回傳 (handle → {cats: [...]}, 分類清單含對帳結果)。一書可屬多分類。"""
    cats = discover_categories()
    members: dict[str, dict] = {}
    for cat in cats:
        items = walk_category(cat, force=force_lists)
        cat["collected"] = len(items)
        ok = "✓" if (cat["count"] is None or len(items) == cat["count"]) else "✗"
        print(f"  {ok} {cat['name']}:收 {len(items)} 件(站方 {cat['count']})", flush=True)
        for h in items:
            rec = members.setdefault(h, {"cats": []})
            if cat["code"] not in [c["code"] for c in rec["cats"]]:
                rec["cats"].append({"code": cat["code"], "path": cat["name"]})
    return members, cats


NEW_ENTRY = ["新品推介"]   # 每日新品入口(分類 handle);站方另有「編輯推薦」但更新較慢


def collect_memberships_dict(force_lists: bool = True,
                             only: list[str] | None = None) -> dict:
    """給 new_arrivals.py 用:只走指定分類(預設全部),回傳 handle → {cats}。
    每日新品先只走 NEW_ENTRY,發現新書才補走全部分類取完整歸屬(省請求)。"""
    cats = discover_categories()
    if only:
        cats = [c for c in cats if c["handle"] in only or c["name"] in only]
    members: dict[str, dict] = {}
    for cat in cats:
        for h in walk_category(cat, force=force_lists):
            rec = members.setdefault(h, {"cats": []})
            if cat["code"] not in [c["code"] for c in rec["cats"]]:
                rec["cats"].append({"code": cat["code"], "path": cat["name"]})
    return members


# ── 3. 商品頁解析 ────────────────────────────────────────────

_COLONS = "\uff1a:\u2236"
_SPLIT_RE = re.compile(r"^\s*([^" + _COLONS + r"]{1,12})\s*[" + _COLONS + r"][ \t]*(.+)$")
_AMOUNT_RE = re.compile(r"[\d,]+(?:\.\d+)?")
_ISBN_RE = re.compile(r"(?<!\d)(97[89][-\s]?(?:\d[-\s]?){9}\d|\d{9}[\dXx])(?!\d)")
# 商品編號:四位數字起頭,後接字母或 -/. 區段(1322-26-3、4110A、9016-14a)
# 8/24 probe2:英文版商品編號以字母起頭(E4351-1),原本只認四位數字 → item_no 6/8
_ITEMNO_RE = re.compile(r"^([A-Za-z]{0,2}\d{3,4}[0-9A-Za-z]*(?:[-.][0-9A-Za-z]+)*)\s+(.+)$")
# 頁尾雜訊:規格採集會撈到店家地址與客服電話,一律排除
_SPEC_SKIP = ("地址", "客服", "專線", "電話", "傳真", "統一編號", "營業", "門市")


_ADS_RE = re.compile(r"\s*立即按讚[，,]?\s*加入臺灣福音書房粉絲專頁\s*$")


def _strip_ads(t: str | None) -> str | None:
    if not t:
        return None
    t = _ADS_RE.sub("", t).strip()
    return t or None


def split_summary(text: str) -> tuple[str | None, str | None, str | None]:
    """本站簡介是一整塊自由文字:「著者：X ■ 簡介… ■ 目錄…」
    → 拆成 (著者, 簡介, 目錄),不要整塊塞進 summary(8/24 probe2 決定)。"""
    author = None
    m = re.match(r"^\s*(?:著者|作者)\s*[:\uff1a]\s*([^\n\u25a0]{1,60})", text)
    if m:
        author = m.group(1).strip()
    body = re.sub(r"^\s*(?:著者|作者)\s*[:\uff1a][^\u25a0]*", "", text, count=1)
    intro = toc = None
    for part in re.split(r"\u25a0\s*", body):
        part = part.strip()
        if part.startswith("簡介"):
            intro = part[2:].strip() or None
        elif part.startswith("目錄"):
            toc = part[2:].strip() or None
    return author, _strip_ads(intro or body.strip()), _strip_ads(toc)


def _meta(soup: BeautifulSoup, prop: str) -> str | None:
    el = soup.find("meta", property=prop) or soup.find("meta", attrs={"name": prop})
    v = (el.get("content") or "").strip() if el else ""
    return v or None


def _amount(text: str | None) -> str | None:
    if not text:
        return None
    m = _AMOUNT_RE.search(text.replace("NT$", ""))
    return m.group(0).replace(",", "").rstrip("0").rstrip(".") if m and "." in m.group(0) else (
        m.group(0).replace(",", "") if m else None)


def valid_isbn(s: str) -> bool:
    d = re.sub(r"[^0-9Xx]", "", s)
    if len(d) == 13:
        return sum((1 if i % 2 == 0 else 3) * int(c) for i, c in enumerate(d)) % 10 == 0
    if len(d) == 10:
        tot = sum((10 - i) * (10 if c in "Xx" else int(c)) for i, c in enumerate(d))
        return tot % 11 == 0
    return False


def norm_isbn(s: str | None) -> str | None:
    if not s:
        return None
    m = _ISBN_RE.search(s)
    if not m or not valid_isbn(m.group(1)):
        return None
    return re.sub(r"[-\s]", "", m.group(1)).upper()


def json_ld(soup: BeautifulSoup) -> list[dict]:
    out = []
    for tag in soup.find_all("script", type="application/ld+json"):
        try:
            data = json.loads(tag.string or "{}")
        except (json.JSONDecodeError, TypeError):
            continue
        out += data if isinstance(data, list) else [data]
    return out


def is_product_page(soup: BeautifulSoup, html: str) -> bool:
    """陷阱 3:handle 不對時站方回首頁(HTTP 200)。三個獨立訊號任一成立才算商品頁。"""
    if _meta(soup, "og:type") == "product":
        return True
    if any(str(d.get("@type", "")).lower() == "product" for d in json_ld(soup)):
        return True
    return bool(soup.select_one(".product-detail, .product-info, [itemtype*='Product']"))


def parse_product(handle: str, cats: list[dict], retried: bool = False) -> dict | None:
    html = fetch(product_url(handle), force=retried)
    if html is None:
        return None
    soup = BeautifulSoup(html, "lxml")

    if not is_product_page(soup, html):
        print(f"  [略過 {handle}] 非商品頁(站方回首頁/失效 handle)", flush=True)
        return None

    ld = next((d for d in json_ld(soup)
               if str(d.get("@type", "")).lower() == "product"), {})

    raw_title = (ld.get("name")
                 or (soup.select_one("h1").get_text(" ", strip=True) if soup.select_one("h1") else None)
                 or _meta(soup, "og:title"))
    if raw_title:
        raw_title = re.sub(r"\s*[-|–—]\s*臺灣福音書房.*$", "", re.sub(r"\s+", " ", raw_title)).strip()
    if not raw_title:
        if not retried:
            return parse_product(handle, cats, retried=True)
        print(f"  [略過 {handle}] 找不到書名", flush=True)
        return None

    # 書名前面帶商品編號(「1322-26-3 二○二六年國殤節特會信息記錄」)→ 切出 item_no
    item_no, title = None, raw_title
    m = _ITEMNO_RE.match(raw_title)
    if m:
        item_no, title = m.group(1), m.group(2).strip()
    elif re.fullmatch(r"\d{4}[0-9A-Za-z]*(?:[-.][0-9A-Za-z]+)*", handle):
        item_no = handle.upper()

    rec: dict = {
        "handle": handle,
        "source": "twgbr",
        "source_url": product_url(handle),
        "title": title,
        "title_raw": raw_title if raw_title != title else None,
        "publisher": "臺灣福音書房",     # 全站自家出版(probe 若見例外要改成逐本解析)
        "currency": "TWD",
        "is_ebook": False,
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }
    if item_no:
        rec["item_no"] = item_no
    rec = {k: v for k, v in rec.items() if v is not None}

    # 價格:JSON-LD offers 優先,退回頁面文字「NT$」對
    offers = ld.get("offers") or {}
    if isinstance(offers, list):
        offers = offers[0] if offers else {}
    price = _amount(str(offers.get("price"))) if offers.get("price") else None
    prices = [p.replace(",", "") for p in re.findall(r"NT\$\s*([\d,]+)", html)][:2]
    if len(prices) >= 2 and prices[0] != prices[1]:
        rec["price_list"], rec["price_sale"] = prices[0], prices[1]
    elif price:
        rec["price_list"] = price
    elif prices:
        rec["price_list"] = prices[0]

    # 規格自由文字:標籤:值 逐行採集(本站商品頁沒有固定規格表)
    text = soup.get_text("\n", strip=True)
    spec: dict[str, str] = {}
    spec_raw: list[str] = []
    for line in text.split("\n"):
        mm = _SPLIT_RE.match(line)
        if not mm:
            continue
        k, v = mm.group(1).strip(), mm.group(2).strip()
        if len(v) > 100 or not v or any(x in k for x in _SPEC_SKIP):
            continue
        spec.setdefault(k, v)
        spec_raw.append(f"{k}:{v}")
    if spec_raw:
        rec["spec_raw"] = " / ".join(spec_raw)[:1500]

    for keys, dst in [(("著者", "作者", "編者", "作者/編者"), "authors_raw"),
                      (("譯者", "繙譯", "翻譯"), "translators_raw"),
                      (("繪者", "插畫"), "illustrators_raw"),
                      (("出版社", "出版者"), "publisher"),
                      (("頁數",), "page_count"),
                      (("尺寸", "開數", "規格"), "dimensions"),
                      (("裝訂",), "binding"),
                      (("語言", "語文"), "language"),
                      (("系列", "叢書"), "series")]:
        for k in keys:
            if spec.get(k):
                rec[dst] = spec[k][:200]
                break
    if rec.get("page_count"):
        mm = re.search(r"[\d,]+", rec["page_count"])
        rec["page_count"] = mm.group(0).replace(",", "") if mm else None
        if not rec["page_count"]:
            rec.pop("page_count")

    for k in ("出版日期", "出版年月", "初版", "出版"):
        if spec.get(k):
            mm = re.search(r"(\d{4})\D{0,3}(\d{1,2})?\D{0,3}(\d{1,2})?", spec[k])
            if mm:
                rec["publish_date"] = (mm.group(1)
                                       + (f"-{int(mm.group(2)):02d}" if mm.group(2) else "")
                                       + (f"-{int(mm.group(3)):02d}" if mm.group(2) and mm.group(3) else ""))
                break

    # 簡介:JSON-LD description → og:description → 內文
    summary = (ld.get("description") or _meta(soup, "og:description") or "").strip()
    if summary:
        author, intro, toc = split_summary(summary)
        if intro:
            rec["summary"] = intro[:4000]
        if toc:
            rec["tabs"] = {"目錄": toc[:4000]}
        if author and not rec.get("authors_raw"):
            rec["authors_raw"] = author[:200]
        rec["summary_raw_len"] = len(summary)

    # 外文品項標記(對映表/classify 下架用):書名帶「英文版/日文版」或編號 E 開頭
    if re.search(r"[（(](?:英文|日文|英語|日語)版[）)]", rec["title"]) or \
            re.match(r"^[Ee]\d", rec.get("item_no", "")):
        rec["language"] = rec.get("language") or "en"

    # ISBN:本站線上多半沒有;簡介裡撈到的一律驗檢查碼(寧缺勿錯)
    for src, label in ((spec.get("ISBN") or spec.get("國際書號"), "標籤"),
                       (summary, "簡介裸掃")):
        got = norm_isbn(src) if src else None
        if got:
            rec["isbn"], rec["isbn_from"] = got, label
            break

    cover = ld.get("image") or _meta(soup, "og:image")
    if isinstance(cover, list):
        cover = cover[0] if cover else None
    if cover:
        rec["cover_url"] = urljoin(BASE, cover)

    crumb = soup.select_one(".breadcrumb, nav[aria-label='breadcrumb']")
    if crumb:
        parts = [x for x in (t.strip() for t in crumb.get_text("\n").split("\n")) if x]
        if len(parts) >= 2:
            rec["breadcrumb"] = " > ".join(parts)[:200]

    rec["categories"] = [{"code": c["code"], "path": c["path"]} for c in cats]
    if cats:
        rec["category_source"] = cats[0]["code"]
        rec["category_text"] = cats[0]["path"]
    return rec


# ── 4. probe ────────────────────────────────────────────────

def probe():
    print("== A. 分類發現與件數對帳 ==", flush=True)
    cats = discover_categories()
    # 8/24 probe2:站方頁面與導覽列都不顯示件數 → 對帳改用「自行計數」,
    # 首頁筆數 = min(該分類件數, 72);滿 72 的分類代表還要翻頁。
    total = sum(c["count"] or c["first_page"] for c in cats)
    nb = sum(c["count"] or c["first_page"] for c in cats if c["nonbook"])
    fg = sum(c["count"] or c["first_page"] for c in cats if c["foreign"])
    full = [c["name"] for c in cats if c["first_page"] >= PER_PAGE]
    print(f"\n分類 {len(cats)} 個;首頁筆數合計 {total}"
          f"(其中非書 {nb}、外文 {fg}) — 推薦類與大類會重複計算", flush=True)
    print(f"滿 {PER_PAGE} 筆(需翻頁)的分類 {len(full)} 個:{full}", flush=True)

    print("\n== B. 超界頁行為(陷阱 2)==", flush=True)
    big = max(cats, key=lambda c: c["count"] or 0) if cats else None
    if big:
        html = fetch(cat_url(big["handle"], 999))
        if html is None:
            print("  抓取失敗")
        else:
            n = len(_uniq(PROD_RE.findall(html)))
            print(f"  {big['name']} ?page=999 → 商品 {n} 個;"
                  f"含空頁文案:{EMPTY_MARK in html}", flush=True)

    print("\n== C. 抽樣商品欄位 ==", flush=True)
    prefer = ("造就類", "福音類", "兒童類", "事奉類")
    sample_cat = (next((c for c in cats if c["name"] in prefer), None)
                  or next((c for c in cats if not c["nonbook"]
                           and (c["count"] or c["first_page"]) >= 10), None))
    handles = walk_category(sample_cat)[:8] if sample_cat else []
    hits: dict[str, int] = {}
    for h in handles:
        rec = parse_product(h, [{"code": sample_cat["code"], "path": sample_cat["name"]}])
        if not rec:
            continue
        for k in ("title", "item_no", "authors_raw", "publisher", "price_list",
                  "price_sale", "summary", "cover_url", "isbn", "publish_date",
                  "page_count", "spec_raw", "breadcrumb"):
            if rec.get(k):
                hits[k] = hits.get(k, 0) + 1
        print(f"\n  --- {h} ---")
        print(json.dumps(rec, ensure_ascii=False, indent=1)[:1200], flush=True)
    if handles:
        print("\n  欄位命中率(樣本 %d 件):" % len(handles), flush=True)
        for k, v in sorted(hits.items(), key=lambda x: -x[1]):
            print(f"    {k:16s} {v}/{len(handles)}", flush=True)

    print("\n== C2. 導覽列原文片段(校對件數解析用)==", flush=True)
    seed = fetch(SEED_PAGES[0])
    if seed:
        t = BeautifulSoup(seed, "lxml").get_text("\n", strip=True)
        i = t.find("造就")
        print(t[max(0, i - 300): i + 500] if i > 0 else t[:800], flush=True)

    print("\n== D. 規格區塊原文(供下一版欄位對映)==", flush=True)
    if handles:
        html = fetch(product_url(handles[0]))
        if html:
            soup = BeautifulSoup(html, "lxml")
            print(soup.get_text("\n", strip=True)[:1500], flush=True)
            print("\n  JSON-LD 區塊:", flush=True)
            print(json.dumps(json_ld(soup), ensure_ascii=False, indent=1)[:1500], flush=True)


# ── 5. main ─────────────────────────────────────────────────

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--force-lists", action="store_true", help="清單頁不走快取")
    args = ap.parse_args()

    if args.probe:
        probe()
        return

    DATA.mkdir(exist_ok=True)
    writer = JsonlWriter(DATA / "twgbr_books.jsonl", key_field="handle")

    print("== 第一階段:全分類清單走訪 ==", flush=True)
    members, cats = collect_memberships(force_lists=args.force_lists)
    bad = [c for c in cats if c["count"] and c["collected"] != c["count"]]
    if bad:
        print("\n[對帳不符的分類——先查再繼續]", flush=True)
        for c in bad:
            print(f"  {c['name']}:收 {c['collected']} / 站方 {c['count']}", flush=True)
    todo = [(h, d) for h, d in members.items() if h not in writer.seen]
    print(f"\n不重複商品 {len(members)} 件,待抓 {len(todo)}", flush=True)

    print("== 第二階段:抓商品頁 ==", flush=True)
    done = 0
    for h, d in todo:
        rec = parse_product(h, d["cats"])
        if rec and writer.write(rec):
            done += 1
            if done % 50 == 0:
                print(f"  已入檔 {done}/{len(todo)}", flush=True)
        if args.limit and done >= args.limit:
            print(f"到達 --limit {args.limit},停止", flush=True)
            break
    print(f"完成:本次入檔 {done} 件(檔案累計 {len(writer.seen)})", flush=True)

    # 匯入前必做:量最長欄位值,對照 schema 上限(真哪噠 1406 教訓)
    longest = {}
    for line in (DATA / "twgbr_books.jsonl").open(encoding="utf-8"):
        try:
            r = json.loads(line)
        except json.JSONDecodeError:
            continue
        for k, v in r.items():
            if isinstance(v, str) and len(v) > longest.get(k, (0, ""))[0]:
                longest[k] = (len(v), v[:80])
    print("\n[最長欄位值檢查——對照 schema 上限]", flush=True)
    for k, (n, sample) in sorted(longest.items(), key=lambda x: -x[1][0])[:12]:
        print(f"  {k:14s} {n:5d}  {sample}", flush=True)


if __name__ == "__main__":
    main()
