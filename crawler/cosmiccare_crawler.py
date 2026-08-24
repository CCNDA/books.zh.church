# -*- coding: utf-8 -*-
"""宇宙光全人關懷機構 線上商城 bookstore.cosmiccare.org 爬蟲
(單執行緒、節流 2-3 秒、快取續跑)。

8/21 偵察結論(Chrome live 實測):
 1. 宇宙光全人關懷機構(台北)自營商城,自建 SSR(ASP.NET MVC 系),TWD、
    全站繁體。requests+bs4 可直接解析,無 JS 依賴。
 2. 清單頁 `/Product/List?Category={大類}[&Tag={子類}][&page=N]`:
    每頁 16 件,商品連結 `/Product/Detail/{商品代碼}`(代碼如 MA628、
    DS060-3,非數字 id)。分頁列末頁由 `>>` 連結宣告 → max_page_hint()。
    **實測全站 105 頁 1,671 件、無空洞頁、無重複**(8/21 全站走訪驗證);
    五個大類清單(書籍 89 頁/繪本 4/雜誌 8/影音 2/禮品 4)的聯集
    = 1,671 件,與 `/Product/List` 全站清單完全一致 → 走訪 5 大類即涵蓋全站。
 3. 商品頁(SSR):
    - 書名在 `h2.product-title`(**沒有 h1**);`.breadcrumb` 麵包屑
      (首頁 > 大類 > 書名);
    - **`.detailsp` 逐欄規格**(每欄一個 div,「標籤 : 值」):售價/優惠價/
      作者/出版社/ISBN/出版日期/尺寸/重量/頁數/裝訂。**注意:尺寸/重量/
      頁數/裝訂 位於頁籤區之後**(DOM 上與前段 .detailsp 不相鄰),故一律
      掃全頁所有 .detailsp,不可只取第一群。
    - 頁籤區 `.horizontal-tab .tab-content .tab-pane`,每個 pane 自帶
      `h3.tabcont-title`(商品介紹/作者介紹/目錄/章節試閱/詳細資料)與
      `.tabcont-intro` 內容。**頁籤列(.nav-tabs)只有 4 項而 pane 有 5 個**
      (末項「其他人也買了」無頁籤)→ 名稱一律取 pane 自帶標題,不可用索引
      對位。頁籤名稱與內容語意亦非固定對應(DT010 的「詳細資料」實為內文
      試閱)→ 「商品介紹」入 summary,其餘以「頁籤名 → 內容」存 extra.tabs,
      不臆測語意(不丟資料鐵律)。
    - 封面 og:image → /api/Resource/Image/{guid}(無副檔名)。
 4. 範圍(8/21 決議):**全站抓入存證**,非書(影音/禮品/雜誌訂閱/海外運費)
    由 cosmiccare_category_map 下架(任一命中即下架,沿天恩規則)。
    代銷他社書(明光社等)全收,同 ISBN 自動跨站合併。
 5. 分類雙軌:站方分類(大類+Tag,一書多 Tag)由清單走訪蒐集 →
    subjects(scheme='cosmiccare') 存證;站內瀏覽分類由對映表換算。
    **作者系列 Tag(林治平/黃小石/魏外揚/諾曼．萊特/張德健長老作品)與
    ★福利書 不參與分類**(8/21 決議),對映表 internal_name=NULL 僅存證。
 6. 每日新品另有 `/Product/LatestList`(4 頁),但增量仍走五大類清單
    (成本相近且保證完整),見 new_arrivals.collect_cosmiccare()。

用法(主機;venv 沿用既有):
  python3 cosmiccare_crawler.py --probe        # 驗證清單+商品頁解析(先跑,貼回輸出)
  nohup python3 cosmiccare_crawler.py > logs/cosmiccare.log 2>&1 &   # 全量(可中斷續跑)
  python3 cosmiccare_crawler.py --limit 30     # 試跑 30 件
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

BASE = "https://bookstore.cosmiccare.org"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "cosmiccare"
DATA = HERE / "data"
THROTTLE = (2.0, 3.0)

session = make_session()


def fetch(url: str, force: bool = False) -> str | None:
    try:
        return polite_fetch(session, url, CACHE, THROTTLE, force=force)
    except RuntimeError as e:
        print(f"  [抓取失敗] {url} → {e}", flush=True)
        return None


# ── 1. 分類走訪清單(導覽選單 8/21 快照)──────────────────────
# code = 「大類/子類」(截 20 字,= subjects.code);path = 「大類 > 子類」;
# promo=大類彙整(選 primary 存證分類時排最後)。
# tag_url = Tag 查詢字串「原值」——★注意「 真實故事」開頭有一個半形空格,
#           站方資料如此,少一格會 0 件(見陷阱 3:0 件分類不會印 log)。
CATEGORIES: list[dict] = []


def _cat(menu: str, name: str, tag: str | None = None, promo: bool = False):
    code = (menu if tag is None else f"{menu}/{name}")[:20]
    CATEGORIES.append({
        "code": code,
        "menu": menu,
        "name": name if tag is not None else "全部" + menu,
        "tag_url": tag,
        "path": f"{menu} > {name}" if tag is not None else f"{menu} > 全部{menu}",
        "promo": promo,
        "order": len(CATEGORIES),
    })


# 書籍(23 個 Tag)
for _n in ["家庭．婚姻", "生命．關懷", "輔導．諮商", "品格．教育", "兒童．青少年",
           "文學．藝術", "生活．勵志", "信仰．靈修", "神學．研經"]:
    _cat("書籍", _n, _n)
_cat("書籍", "真實故事", " 真實故事")          # ← 站方 Tag 值前有一個空格
for _n in ["文化與信仰", "教會事奉", "科學與信仰", "時代與社會", "教會歷史",
           "基督教與中國", "宣教士故事", "林治平作品", "黃小石作品",
           "魏外揚作品", "諾曼．萊特作品", "★ 福利書", "張德健長老作品"]:
    _cat("書籍", _n, _n)
# 繪本(8)
for _n in ["兒童．青少年", "信仰．靈修", "聖經故事", "宣教士故事", "品格．教育",
           "生活．勵志", "魏外揚作品", "★福利書"]:
    _cat("繪本", _n, _n)
# 雜誌(2)
for _n in ["雜誌", "訂閱"]:
    _cat("雜誌", _n, _n)
# 影音(4)
for _n in ["糖果姐姐說故事", "福音音樂CD", "影音課程", "講道"]:
    _cat("影音", _n, _n)
# 禮品(7)
for _n in ["文具&筆記本", "項鍊", "領帶", "米糠皂", "包包類", "生活雜貨", "衣服"]:
    _cat("禮品", _n, _n)
# 海外運費(1;非商品,存證用)
_cat("海外運費", "海外運費", "海外運費")
# 大類彙整(promo;五大類聯集=全站,是完整性的保證)
for _m in ["書籍", "繪本", "雜誌", "影音", "禮品", "海外運費"]:
    _cat(_m, _m, None, promo=True)


# ── 2. 清單頁 ────────────────────────────────────────────────

# 商品代碼可能含空格(雜誌 MA 623 → 網址寫成 /Product/Detail/MA%20623),
# 故字元集必須含 %,取出後 unquote 還原;product_url() 再 quote 回去(可逆)。
# 8/21 probe 教訓:原本不收 % → 「MA%20623」只截到「MA」,四期雜誌併成一筆假 pid。
PROD_RE = re.compile(r"/Product/Detail/([A-Za-z0-9%][A-Za-z0-9._%-]*)")
# 分頁連結在**原始 HTML** 裡是 `&amp;page=2`(瀏覽器 getAttribute 才看得到 `&`),
# 因此比對必須容許 `amp;`,否則末頁一律取到 1(8/21 probe 教訓)。
_PAGE_HINT_RE = re.compile(r"[?&](?:amp;)?page=(\d+)")
PAGE_MARGIN = 1          # 末頁宣告值之後仍多走 1 頁(站方在走訪途中上架的緩衝)
#   實測分頁列宣告的末頁可信、且無空洞頁,故不需要橄欖華宣那種 6 頁滾動延伸;
#   margin 每個分類固定多一次請求(51 個分類共 51 次),再大就是浪費站方頻寬。


def list_url(cat: dict, page: int) -> str:
    u = f"{BASE}/Product/List?Category=" + quote(cat["menu"], safe="")
    if cat["tag_url"] is not None:
        u += "&Tag=" + quote(cat["tag_url"], safe="")
    return u if page <= 1 else f"{u}&page={page}"


def extract_ids(html: str) -> list[str]:
    """清單頁 → 商品代碼清單(保持頁面順序,去重)。

    只取商品格 `div.product:not(.topsection)` 內的連結,兩個陷阱:
      1. 側欄「熱門排行 TOP」也是 /Product/Detail/ 連結 → 整頁 regex 會把
         同樣 10 件混進每一頁(假資料 + 分類歸屬錯亂)。
      2. Tag 清單頁最上方有兩格「焦點」推薦 `div.product.topsection`,
         **每一頁都重複出現且不屬於該頁商品格**(8/21 實測:家庭．婚姻
         第 1 頁 18 格 = 16 商品 + 2 焦點,焦點 FD006/DA019 不在商品格中)
         → 不排除會讓每個 Tag 硬多兩本、且該書分類歸屬被灌到每個 Tag。"""
    soup = BeautifulSoup(html, "lxml")
    out: list[str] = []
    seen: set[str] = set()
    for block in soup.select("div.product:not(.topsection)"):
        for a in block.select('a[href*="/Product/Detail/"]'):
            m = PROD_RE.search(a.get("href") or "")
            if not m:
                continue
            # MA%20623 → "MA 623";尾端空白一定要去掉——站方有代碼寫成
            # 「DS058-29   」(/Product/Detail/DS058-29%20%20%20),帶著空白抓必 404。
            pid = unquote(m.group(1)).strip()
            if pid and pid not in seen:
                seen.add(pid)
                out.append(pid)
    return out


def max_page_hint(html: str) -> int:
    """由分頁列連結取最大頁碼(取不到視為 1 頁)。"""
    nums = [int(x) for x in _PAGE_HINT_RE.findall(html or "")]
    return max(nums) if nums else 1


def walk_category(cat: dict, force: bool = False, max_pages: int = 200) -> list[str]:
    """走訪一個分類的所有清單頁。

    邊界取「分頁列宣告的末頁 + PAGE_MARGIN」,空頁略過續走(沿 8/19 橄欖
    華宣教訓:任何『首次無新項即停』都可能提前收工)。實測宇宙光分頁
    連續無空洞,但仍不以此為前提。"""
    ids: list[str] = []
    seen: set[str] = set()
    limit = 1
    page = 1
    while page <= max_pages:
        html = fetch(list_url(cat, page), force=force)
        if html is None:
            break
        if page == 1:
            limit = max(limit, min(max_page_hint(html), max_pages))
        fresh = [p for p in extract_ids(html) if p not in seen]
        if fresh:
            seen.update(fresh)
            ids.extend(fresh)
            limit = max(limit, min(page + PAGE_MARGIN, max_pages))
        if page >= limit:
            break
        page += 1
    return ids


def collect_memberships(force_lists: bool = False,
                        only_promo: bool = False) -> dict[str, dict]:
    """走訪分類清單 → pid → {'cats': [分類...]}(非 promo 先、選單順序)。

    only_promo=True 只走六個大類彙整(107 頁,聯集即全站)——每日增量的
    第一階段用,便宜且完整;偵測到新品後再走全部 Tag 補分類歸屬。"""
    members: dict[str, dict] = {}
    todo = [c for c in CATEGORIES if c["promo"]] if only_promo else CATEGORIES
    for c in todo:
        ids = walk_category(c, force=force_lists)
        print(f"[清單] {c['path']}:{len(ids)} 件", flush=True)
        for pid in ids:
            d = members.setdefault(pid, {"cats": []})
            if c["code"] not in [x["code"] for x in d["cats"]]:
                d["cats"].append(c)
    for d in members.values():
        d["cats"].sort(key=lambda c: (c["promo"], c["order"]))
    return members


# ── 3. 商品頁解析 ────────────────────────────────────────────

_COLONS = "\uff1a:\u2236"   # 全形冒號 U+FF1A、半形、比號(寫死 escape)

# .detailsp 標籤 → rec 欄位
SPEC_LABELS: dict[str, str] = {
    "售價": "price_list",
    "優惠價": "price_sale",
    "作者": "authors_raw",
    "譯者": "translators_raw",
    "繪者": "illustrators_raw",
    "編者": "editors_raw",
    "出版社": "publisher",
    "ISBN": "isbn",
    "EAN": "ean",
    "出版日期": "publish_date",
    "尺寸": "dimensions",
    "重量": "weight",
    "頁數": "page_count",
    "裝訂": "binding",
    "語言": "language",
    "系列": "series",
}
_SPLIT_RE = re.compile(r"^\s*([^" + _COLONS + r"]{1,10})\s*[" + _COLONS + r"]\s*(.+)$",
                       re.S)
_AMOUNT_RE = re.compile(r"[\d,]+(?:\.\d+)?")
_ISBN_RE = re.compile(r"(?<!\d)(97[89][-\s]?(?:\d[-\s]?){9}\d|\d{9}[\dXx])(?!\d)")


def parse_spec(soup: BeautifulSoup) -> tuple[dict, list[str]]:
    """掃全頁 .detailsp(頁籤前後都有)→ {欄位: 值} 與原文清單。"""
    fields: dict = {}
    raw: list[str] = []
    for el in soup.select(".detailsp"):
        text = re.sub(r"\s+", " ", el.get_text(" ", strip=True)).strip()
        if not text:
            continue
        raw.append(text)
        m = _SPLIT_RE.match(text)
        if not m:
            continue
        key = SPEC_LABELS.get(m.group(1).strip())
        val = m.group(2).strip()
        if key and key not in fields and val:
            fields[key] = val
    return fields, raw


def _amount(text: str | None) -> str | None:
    if not text:
        return None
    m = _AMOUNT_RE.search(text)
    return m.group(0).replace(",", "") if m else None


def norm_isbn(s: str | None) -> str | None:
    """回傳 ISBN13 或 ISBN10 原字串(交 import.php 正規化);雜誌的 ISSN
    條碼(977…)不符 97[89] 開頭,會落到 None → 不會與書籍 ISBN 誤合併。"""
    if not s:
        return None
    m = _ISBN_RE.search(s.replace(" ", ""))
    if not m:
        return None
    digits = re.sub(r"[^0-9Xx]", "", m.group(0))
    return digits if len(digits) in (10, 13) else None


def clean_person(s: str | None) -> str | None:
    if not s:
        return None
    s = re.sub(r"^\s*(作者|譯者|繪者|編者)\s*[" + _COLONS + r"]\s*", "", s.strip())
    return re.sub(r"\s{2,}", " ", s) or None


def product_url(pid: str) -> str:
    return f"{BASE}/Product/Detail/{quote(pid, safe='')}"


def _meta(soup: BeautifulSoup, prop: str) -> str | None:
    el = soup.select_one(f'meta[property="{prop}"]') or soup.select_one(f'meta[name="{prop}"]')
    v = (el.get("content") or "").strip() if el else ""
    return v or None


_SKIP_TABS = {"其他人也買了", "相關商品"}


def parse_tabs(soup: BeautifulSoup) -> dict[str, str]:
    """頁籤名 → 內容(名稱與內容語意不保證對應,原樣存證)。

    名稱一律取區塊自帶的 `h3.tabcont-title`,**不用 .nav-tabs 的索引對位**:
    頁籤列只有 4 項但 .tab-pane 有 5 個(末項「其他人也買了」無頁籤),
    照索引配對會整批錯位(8/21 probe 發現)。"""
    out: dict[str, str] = {}
    for i, pane in enumerate(soup.select(".horizontal-tab .tab-content .tab-pane")):
        name = pane.select_one(".tabcont-title")
        name = name.get_text(" ", strip=True) if name else f"頁籤{i + 1}"
        if name in _SKIP_TABS:
            continue
        body = pane.select_one(".tabcont-intro")
        if body is None:
            continue
        for junk in body.select(".tabcont-backtop"):   # 「TOP」回頂連結在內容區塊裡
            junk.decompose()
        text = re.sub(r"\n{3,}", "\n\n", body.get_text("\n", strip=True)).strip()
        text = re.sub(r"\s*\bTOP\s*$", "", text).strip()
        if not text:
            continue
        if name in out:                       # 同名區塊保留兩者,不覆蓋
            name = f"{name}({i + 1})"
        out[name] = text
    return out


def _excerpt(text: str, limit: int) -> str:
    """截為節錄:盡量切在 limit 之前最後一個句末標點,切不到就硬切。"""
    head = text[:limit]
    cut = max(head.rfind(c) for c in "。！？!?…")
    if cut >= limit // 4:
        head = head[:cut + 1]
    return head.rstrip() + "……"


def parse_product(pid: str, cats: list[dict], retried: bool = False) -> dict | None:
    html = fetch(product_url(pid), force=retried)
    if html is None:
        return None
    soup = BeautifulSoup(html, "lxml")

    # 先確認這真的是商品頁:失效的商品代碼會被站方**302 導回首頁並回 200**
    # (8/21 全量實測:DS058-29 兩種寫法一個 404、一個導回首頁)。首頁沒有
    # .products-inpdetail 但**有 og:title「宇宙光全人關懷網」與 og:image**,
    # 若放它走到下面的 og 備援,就會憑空生出一筆假書。→ 結構不對就直接放棄,
    # 且不重抓(重抓還是首頁,只是白費請求)。
    if soup.select_one(".products-inpdetail") is None and soup.select_one(".product-summary") is None:
        print(f"  [略過 {pid}] 非商品頁(站方失效代碼,已被導回首頁或 404)", flush=True)
        return None

    # 書名在 h2.product-title(**本站商品頁沒有 h1**,8/21 probe 確認);
    # og:title 帶「宇宙光線上商城-」前綴,退而求其次時要切掉。
    h = soup.select_one(".product-summary .product-title, h2.product-title")
    name = (h.get_text(" ", strip=True) if h else None) or _meta(soup, "og:title")
    if name:
        name = re.sub(r"^宇宙光線上商城\s*[-−–—]\s*", "", re.sub(r"\s+", " ", name)).strip()
    if not name:
        if not retried:                      # 可能快取到空殼頁 → force 重抓
            return parse_product(pid, cats, retried=True)
        print(f"  [略過 {pid}] 找不到書名", flush=True)
        return None

    rec: dict = {
        "pid": pid,
        "source": "cosmiccare",
        "source_url": product_url(pid),
        "title": name,
        "item_no": pid,                       # 站方商品代碼 → identifiers(STORE)
        "is_ebook": False,
        "currency": "TWD",
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }

    spec, spec_raw = parse_spec(soup)

    # 價格:售價=定價、優惠價=特價(僅在低於定價時才記 price_sale)
    price_list = _amount(spec.get("price_list"))
    price_sale = _amount(spec.get("price_sale"))
    if price_list is not None:
        rec["price_list"] = price_list
    elif price_sale is not None:
        rec["price_list"] = price_sale
        price_sale = None
    try:
        if price_sale is not None and price_list is not None and float(price_sale) < float(price_list):
            rec["price_sale"] = price_sale
    except ValueError:
        pass

    isbn = norm_isbn(spec.get("isbn")) or norm_isbn(spec.get("ean"))
    if isbn:
        rec["isbn"] = isbn
    elif spec.get("isbn"):
        # ISSN 條碼(雜誌 977…)等非 ISBN 值:不進 isbn 欄,但完整存證
        rec["isbn_raw"] = spec["isbn"][:30]

    for src_key, dst_key in [("authors_raw", "authors_raw"),
                             ("translators_raw", "translators_raw"),
                             ("illustrators_raw", "illustrators_raw"),
                             ("editors_raw", "editors_raw")]:
        val = clean_person(spec.get(src_key))
        if val:
            rec[dst_key] = val
    if spec.get("publisher"):
        rec["publisher"] = spec["publisher"]
    if spec.get("page_count"):
        m = re.search(r"[\d,]+", spec["page_count"])
        if m:
            rec["page_count"] = m.group(0).replace(",", "")
    if spec.get("publish_date"):
        m = re.search(r"(\d{4})\D{0,3}(\d{1,2})?\D{0,3}(\d{1,2})?", spec["publish_date"])
        if m:
            rec["publish_date"] = (m.group(1)
                                   + (f"-{int(m.group(2)):02d}" if m.group(2) else "")
                                   + (f"-{int(m.group(3)):02d}" if m.group(2) and m.group(3) else ""))
    for key in ("dimensions", "weight", "binding", "language", "series"):
        if spec.get(key):
            rec[key] = spec[key]
    if spec_raw:
        rec["spec_raw"] = " / ".join(spec_raw)[:1500]

    tabs = parse_tabs(soup)
    summary = tabs.get("商品介紹") or _meta(soup, "og:description")
    if not summary:
        # 部分商品(繪本居多)沒有「商品介紹」頁籤,簡介寫在「詳細資料」
        # (8/21 probe:繪本「聖經的孩童」即是,不回退就整本沒有簡介)。
        # 但「詳細資料」在有些書是數千字的內文試閱 → 超過 1,500 字只取節錄
        # (切在句末標點),並以 summary_from 標記來源供內容面日後查核;
        # 完整內容照樣留在 tabs → extra,不丟資料。
        alt = tabs.get("詳細資料")
        if alt:
            if len(alt) <= 1500:
                summary, rec["summary_from"] = alt, "詳細資料"
            else:
                summary = _excerpt(alt, 500)
                rec["summary_from"] = "詳細資料(節錄)"
    if summary:
        rec["summary"] = summary.strip()
    other = {k: v for k, v in tabs.items() if k != "商品介紹"}
    if other:
        rec["tabs"] = other                  # 作者介紹/目錄/章節試閱… 原樣存 extra

    crumb = soup.select_one(".breadcrumb")
    if crumb:
        parts = [x for x in (t.strip() for t in crumb.get_text("\n").split("\n")) if x]
        if len(parts) >= 2:
            rec["breadcrumb"] = " > ".join(parts)

    cover = _meta(soup, "og:image")
    if cover:
        rec["cover_url"] = urljoin(BASE, cover)

    rec["categories"] = [{"code": c["code"], "path": c["path"]} for c in cats]
    if cats:
        rec["category_source"] = cats[0]["code"]
        rec["category_text"] = cats[0]["path"]

    return rec


# ── 主流程 ───────────────────────────────────────────────────

def probe():
    print("=== 探測模式 ===", flush=True)

    # (1) 全部走訪網址的 HTTP 狀態與規模(0 件的分類不會印 log,必須逐一驗)
    print("\n-- 分類清單規模 --", flush=True)
    empty: list[str] = []
    for c in CATEGORIES:
        html = fetch(list_url(c, 1), force=True)
        if html is None:
            print(f"  [失敗] {c['path']}", flush=True)
            empty.append(c["path"] + "(抓取失敗)")
            continue
        n = len(extract_ids(html))
        mx = max_page_hint(html)
        print(f"  {c['path']:<24} 首頁 {n:>2} 件、宣告末頁 {mx}", flush=True)
        if n == 0:
            empty.append(c["path"])
    if empty:
        print(f"  [注意] 0 件分類:{'、'.join(empty)}", flush=True)

    # (2) 分頁有效性
    c0 = next(c for c in CATEGORIES if c["promo"] and c["menu"] == "書籍")
    i1 = extract_ids(fetch(list_url(c0, 1), force=True) or "")[:3]
    i2 = extract_ids(fetch(list_url(c0, 2), force=True) or "")[:3]
    print(f"\n-- 分頁 -- 第1頁={i1}、第2頁={i2}"
          f"({'不同 → 分頁有效' if i1 and i2 and i1[0] != i2[0] else '相同,分頁失效,請人工確認!'})",
          flush=True)

    # (3) 各型態樣本
    samples = [("書籍(規格齊)", "書籍", "神學．研經"), ("繪本", "繪本", "聖經故事"),
               ("雜誌", "雜誌", "雜誌"), ("非書(禮品)", "禮品", "項鍊")]
    for label, menu, tag in samples:
        cat = next((c for c in CATEGORIES if c["menu"] == menu and c["name"] == tag), None)
        if cat is None:
            continue
        ids = extract_ids(fetch(list_url(cat, 1), force=True) or "")
        if not ids:
            print(f"\n--- 樣本({label}):清單無商品 ---", flush=True)
            continue
        rec = parse_product(ids[0], [cat], retried=True)
        print(f"\n--- 樣本({label}) ---", flush=True)
        if rec:
            slim = dict(rec)
            slim["summary"] = (rec.get("summary") or "")[:150]
            slim["tabs"] = {k: v[:60] for k, v in (rec.get("tabs") or {}).items()}
            print(json.dumps(slim, ensure_ascii=False, indent=1)[:2200], flush=True)
    print("\n(請把以上輸出貼回,確認欄位/ISBN/價格/封面/分類無誤後再開全量)", flush=True)


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
    writer = JsonlWriter(DATA / "cosmiccare_books.jsonl", key_field="pid")

    print("== 第一階段:全分類清單走訪 ==", flush=True)
    members = collect_memberships(force_lists=args.force_lists)
    todo = [(p, d) for p, d in members.items() if p not in writer.seen]
    print(f"共 {len(members)} 件,待抓 {len(todo)}", flush=True)

    print("== 第二階段:抓商品頁 ==", flush=True)
    done = 0
    for pid, d in todo:
        rec = parse_product(pid, d["cats"])
        if rec and writer.write(rec):
            done += 1
            if done % 50 == 0:
                print(f"  已入檔 {done}/{len(todo)}", flush=True)
        if args.limit and done >= args.limit:
            print(f"到達 --limit {args.limit},停止", flush=True)
            break
    print(f"完成:本次入檔 {done} 件(檔案累計 {len(writer.seen)})", flush=True)


if __name__ == "__main__":
    main()
