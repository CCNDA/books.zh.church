# -*- coding: utf-8 -*-
"""台灣教會公報社網路書房(buy.pctpress.org)爬蟲——第十四個書目來源。

2026-08-27 偵察事實(熊哥存下的頁面 + 熊哥實測兩個網址):
- **WordPress 6.9.4 + WooCommerce 10.8.1**(Enfold 佈景)。第一個 WooCommerce 來源。
- **WooCommerce Store API 可用**(熊哥實測回傳 JSON):
  `/wp-json/wc/store/v1/products?per_page=100&page=N`
  `/wp-json/wc/store/v1/products/categories?per_page=100`
  → 商品 JSON 自帶 categories(一書多分類),不必逐分類走訪清單頁;
    分類 API 自帶 count,拿來對帳。**清單零猜測**。
- 商品頁 `/product/{中文 slug}/`、分類 `/cat/{父}/{子}/`、清單分頁 `/page/N/`(每頁 16)。
- **超界頁回 404**(熊哥實測 `/cat/new_arrival/new_books/page/999/`)——與福音書房(空頁+文案)、
  真哪噠(夾回末頁回 200)都不同。走 API 則以「空陣列或不足 per_page」為停止條件。
- 商品 JSON 的 `description` 是標籤行(\\r\\n 分隔),欄位齊全:
      定價：350元 / 作者：藍嘉祥 / ISBN：9786260168070 / 出版日期：2026/07
  另有 `sku`(店內貨號,如 X4-T)、`prices`(售價/定價)、`images[0].src`(封面)。

決議(熊哥 2026-08-27):
1. **全站抓入存證**,非書(文具禮品/教會用品/影音/程序單/春聯等)由對映表 unpublish 下架。
2. 台語、客語、白話字出版品在 `language` 標記(nan/hak)——這是本站最有價值、別站沒有的一塊。
3. 期刊類(《台灣教會公報》等)沿宇宙光歸「期刊雜誌」。

用法:
    python3 pctpress_crawler.py --probe     # 分類樹+件數對帳+抽樣欄位命中率
    python3 pctpress_crawler.py             # 全量
    python3 pctpress_crawler.py --limit 30
"""
from __future__ import annotations

import argparse
import html as _html
import json
import re
from datetime import datetime, timezone
from pathlib import Path

from common import JsonlWriter, make_session, polite_fetch

BASE = "https://buy.pctpress.org"
API = BASE + "/wp-json/wc/store/v1"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "pctpress"
DATA = HERE / "data"
THROTTLE = (2.0, 3.0)
PER_PAGE = 100
PAGE_CAP = 60           # 100×60 = 6,000,遠大於全站量

session = make_session()


def fetch_json(url: str, force: bool = False):
    """Store API 取 JSON;404/空陣列都視為「沒有更多」。"""
    try:
        txt = polite_fetch(session, url, CACHE, THROTTLE, force=force)
    except RuntimeError as e:
        print(f"  [抓取失敗] {url} → {e}", flush=True)
        return None
    try:
        return json.loads(txt)
    except json.JSONDecodeError:
        print(f"  [非 JSON 回應] {url}(前 120 字:{txt[:120]!r})", flush=True)
        return None


# ── 1. 分類(自帶 count,用來對帳)──────────────────────────

def fetch_categories() -> dict[int, dict]:
    cats: dict[int, dict] = {}
    for page in range(1, 10):
        data = fetch_json(f"{API}/products/categories?per_page={PER_PAGE}&page={page}")
        if not data:
            break
        for c in data:
            cats[int(c["id"])] = {
                "id": int(c["id"]),
                "name": c.get("name", ""),
                "slug": c.get("slug", ""),
                "parent": int(c.get("parent") or 0),
                "count": int(c.get("count") or 0),
                "permalink": c.get("permalink", ""),
            }
        if len(data) < PER_PAGE:
            break
    return cats


def cat_path(cats: dict[int, dict], cid: int) -> str:
    """「父 > 子」路徑(供 subjects.label 與人讀)。"""
    parts, seen = [], set()
    while cid and cid in cats and cid not in seen:
        seen.add(cid)
        parts.insert(0, cats[cid]["name"])
        cid = cats[cid]["parent"]
    return " > ".join(parts)


# ── 2. 商品解析 ─────────────────────────────────────────────

_COLONS = "\uff1a:\u2236"
_LINE_RE = re.compile(r"^\s*([^" + _COLONS + r"]{1,12})\s*[" + _COLONS + r"][ \t]*(.+?)\s*$")
_ISBN_RE = re.compile(r"(?<!\d)(97[89][-\s]?(?:\d[-\s]?){9}\d|\d{9}[\dXx])(?!\d)")
_TAG_RE = re.compile(r"<[^>]+>")
# 站方常填「不提供」當佔位值(formatted_dimensions/weight),不可當有值
_PLACEHOLDER = {"不提供", "無", "無資料", "未提供", "N/A", "n/a", "-", "—", "0"}
# 白名單鍵才允許長值;其餘長值多半是目錄行或經文(描述裡冒號很多)
_SPEC_LONGOK = {"作者", "著者", "編者", "編著", "譯者", "文", "圖", "繪者", "插畫",
                "出版", "出版社", "出版者", "系列", "叢書", "規格", "尺寸", "書度", "裝幀", "裝訂"}
_AMOUNT_RE = re.compile(r"[\d,]+")

# 語言標記(決議 2):依分類 slug 或書名判斷
LANG_BY_SLUG = {"taiwanese": "nan", "bible_taiwanese": "nan"}
LANG_BY_TITLE = [(re.compile(r"白話字|POJ|台語|臺語"), "nan"),
                 (re.compile(r"客語|客家話"), "hak"),
                 (re.compile(r"阿美|排灣|太魯閣|布農|泰雅|原住民族?語"), "map")]


def strip_html(s: str | None) -> str:
    if not s:
        return ""
    s = re.sub(r"<br\s*/?>", "\n", s, flags=re.I)
    s = _TAG_RE.sub("", s)
    s = _html.unescape(s)
    return re.sub(r"&nbsp;|\u00a0", " ", s).strip()


def valid_isbn(s: str) -> bool:
    d = re.sub(r"[^0-9Xx]", "", s)
    if len(d) == 13:
        return sum((1 if i % 2 == 0 else 3) * int(c) for i, c in enumerate(d)) % 10 == 0
    if len(d) == 10:
        return sum((10 - i) * (10 if c in "Xx" else int(c)) for i, c in enumerate(d)) % 11 == 0
    return False


def norm_isbn(s: str | None) -> str | None:
    if not s:
        return None
    m = _ISBN_RE.search(s)
    if not m or not valid_isbn(m.group(1)):
        return None
    return re.sub(r"[-\s]", "", m.group(1)).upper()


def _money(v) -> str | None:
    """Store API 的 prices 是「最小單位字串」+ currency_minor_unit(通常 0 或 2)。"""
    if v in (None, "", "0"):
        return None
    m = _AMOUNT_RE.search(str(v))
    return m.group(0).replace(",", "") if m else None


def parse_product(p: dict, cats: dict[int, dict]) -> dict | None:
    name = strip_html(p.get("name"))
    if not name:
        return None
    rec: dict = {
        "pid": str(p.get("id")),
        "source": "pctpress",
        "source_url": p.get("permalink") or f"{BASE}/product/{p.get('slug','')}/",
        "title": name,
        "publisher": "台灣教會公報社",   # 站方多為自家出版;描述若有「出版社」以描述為準
        "currency": "TWD",
        "is_ebook": False,
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }
    if p.get("sku"):
        rec["item_no"] = str(p["sku"])[:30]

    # 價格:prices.price=售價、regular_price=定價(單位依 currency_minor_unit)
    pr = p.get("prices") or {}
    minor = int(pr.get("currency_minor_unit") or 0)
    def scale(v):
        s = _money(v)
        if s is None:
            return None
        return str(int(s) // (10 ** minor)) if minor else s
    price_sale, price_list = scale(pr.get("price")), scale(pr.get("regular_price"))
    if price_list:
        rec["price_list"] = price_list
        if price_sale and price_sale != price_list:
            rec["price_sale"] = price_sale
    elif price_sale:
        rec["price_list"] = price_sale

    # 描述:標籤行(定價/作者/ISBN/出版日期…)+ 其餘散文當簡介
    desc = strip_html(p.get("description"))
    short = strip_html(p.get("short_description"))
    spec: dict[str, str] = {}
    prose: list[str] = []
    for line in (short + "\n" + desc).split("\n"):
        line = line.strip()
        if not line:
            continue
        m = _LINE_RE.match(line)
        if m:
            k, v = m.group(1).strip(), m.group(2).strip()
            if len(v) <= 120 and (len(v) <= 40 or k in _SPEC_LONGOK):
                spec.setdefault(k, v)
            else:
                prose.append(line)     # 目錄行/經文回歸簡介
        else:
            prose.append(line)
    for k in list(spec):
        if spec[k] in _PLACEHOLDER:
            spec.pop(k)
    if spec:
        rec["spec_raw"] = " / ".join(f"{k}:{v}" for k, v in spec.items())[:1500]

    for keys, dst in [(("作者", "著者", "編著", "編者", "文"), "authors_raw"),
                      (("譯者", "翻譯"), "translators_raw"),
                      (("繪者", "插畫", "圖", "繪圖"), "illustrators_raw"),
                      (("出版社", "出版者", "出版"), "publisher"),
                      (("頁數",), "page_count"),
                      (("尺寸", "開本", "規格", "書度"), "dimensions"),
                      (("裝訂", "裝幀"), "binding"),
                      (("語言", "語文"), "language"),
                      (("系列", "叢書"), "series")]:
        for k in keys:
            if spec.get(k):
                rec[dst] = spec[k][:200]
                break
    if rec.get("page_count"):
        m = re.search(r"\d+", rec["page_count"])
        rec["page_count"] = m.group(0) if m else None
        if not rec["page_count"]:
            rec.pop("page_count")

    if rec.get("publisher") and re.fullmatch(r"\s*\d{4}\s*年?\s*\d{0,2}\s*月?\s*", rec["publisher"]):
        spec.setdefault("出版日期", rec.pop("publisher"))
        rec["publisher"] = "台灣教會公報社"
    for k in ("出版日期", "出版日", "出版年月", "初版", "出版"):
        if spec.get(k):
            m = re.search(r"(\d{4})\D{0,3}(\d{1,2})?\D{0,3}(\d{1,2})?", spec[k])
            if m:
                rec["publish_date"] = (m.group(1)
                                       + (f"-{int(m.group(2)):02d}" if m.group(2) else "")
                                       + (f"-{int(m.group(3)):02d}" if m.group(2) and m.group(3) else ""))
            break

    isbn = norm_isbn(spec.get("ISBN") or spec.get("國際書號")
                     or spec.get("國際條碼") or spec.get("條碼"))
    if isbn:
        rec["isbn"], rec["isbn_from"] = isbn, "標籤"
    else:                                   # 描述裸掃(嚴驗檢查碼,整段只能有一個)
        hits = {m.group(1) for m in _ISBN_RE.finditer(desc) if valid_isbn(m.group(1))}
        if len(hits) == 1:
            rec["isbn"], rec["isbn_from"] = norm_isbn(hits.pop()), "描述裸掃"

    if prose:
        rec["summary"] = "\n".join(prose)[:4000]

    # Store API 的結構化欄位(8/27 probe 發現):attributes 自訂屬性、brands、
    # formatted_dimensions/weight、庫存狀態。全部先存證,能對映的入平面欄。
    attrs: dict[str, str] = {}
    for a in p.get("attributes") or []:
        nm = strip_html(a.get("name"))
        terms = [strip_html(t.get("name")) for t in (a.get("terms") or []) if t.get("name")]
        if nm and terms:
            attrs[nm] = "; ".join(terms)[:200]
    if attrs:
        rec["attrs"] = attrs
        for keys, dst in [(("作者", "著者"), "authors_raw"), (("譯者",), "translators_raw"),
                          (("出版社", "出版者"), "publisher"), (("ISBN", "國際書號"), "_isbn_attr"),
                          (("系列", "叢書"), "series"), (("語言", "語文"), "language")]:
            for k in keys:
                if attrs.get(k) and not rec.get(dst):
                    rec[dst] = attrs[k]
                    break
        got = norm_isbn(rec.pop("_isbn_attr", None))
        if got and not rec.get("isbn"):
            rec["isbn"], rec["isbn_from"] = got, "屬性"

    brands = [strip_html(b.get("name")) for b in (p.get("brands") or []) if b.get("name")]
    if brands:
        rec["brands"] = "; ".join(brands)[:200]

    fd = strip_html(p.get("formatted_dimensions"))
    if fd and fd not in _PLACEHOLDER and not rec.get("dimensions"):
        rec["dimensions"] = fd[:100]
    fw = strip_html(p.get("formatted_weight"))
    if fw and fw not in _PLACEHOLDER:
        rec["weight"] = fw[:50]
    sa = (p.get("stock_availability") or {}).get("text")
    if p.get("is_in_stock") is False:
        rec["stock_status"] = strip_html(sa) or "缺貨/絕版(站方標示無庫存)"
    elif sa and strip_html(sa) not in ("尚有庫存", ""):
        rec["stock_status"] = strip_html(sa)[:60]
    if p.get("type") and p["type"] != "simple":
        rec["product_type"] = str(p["type"])[:30]

    imgs = p.get("images") or []
    if imgs and imgs[0].get("src"):
        rec["cover_url"] = imgs[0]["src"]

    # 分類:商品 JSON 自帶(一書多分類)
    cl, slugs_raw = [], []
    for c in p.get("categories") or []:
        cid = int(c.get("id") or 0)
        slug = c.get("slug") or (cats.get(cid, {}).get("slug", ""))
        # code = 分類 id(唯一且短);slug 是 percent-encoded 中文、截斷會撞
        # (8/27 全量實測:兩個同名「生命教育教材」子分類 slug 截 40 字後相同)
        cl.append({"code": str(cid), "path": cat_path(cats, cid) or c.get("name", "")})
        if slug:
            slugs_raw.append(slug)
    if slugs_raw:
        rec["cat_slugs"] = "; ".join(slugs_raw)[:500]
    if cl:
        rec["categories"] = cl
        rec["category_source"] = cl[0]["code"]
        rec["category_text"] = cl[0]["path"]

    # 語言標記(決議 2)
    lang = None
    slugs = set(slugs_raw)
    for s, v in LANG_BY_SLUG.items():
        if s in slugs:
            lang = v
            break
    if not lang:
        for rgx, v in LANG_BY_TITLE:
            if rgx.search(name):
                lang = v
                break
    if lang and not rec.get("language"):
        rec["language"] = lang
    return rec


# ── 3. 全量走訪 ─────────────────────────────────────────────

def walk_products(cats: dict[int, dict], limit: int = 0) -> list[dict]:
    out: list[dict] = []
    for page in range(1, PAGE_CAP + 1):
        data = fetch_json(f"{API}/products?per_page={PER_PAGE}&page={page}")
        if not data:
            break
        for p in data:
            rec = parse_product(p, cats)
            if rec:
                out.append(rec)
        print(f"  第 {page} 頁:{len(data)} 件(累計 {len(out)})", flush=True)
        if limit and len(out) >= limit:
            break
        if len(data) < PER_PAGE:
            break
    return out


def reconcile(recs: list[dict], cats: dict[int, dict]):
    tally: dict[str, int] = {}
    for r in recs:
        for c in r.get("categories", []):
            tally[c["code"]] = tally.get(c["code"], 0) + 1
    print("\n[分類件數對帳:抓到 vs 站方 count]", flush=True)
    print("  註:WooCommerce 父分類的 count 含子孫商品,而商品只列「實際指派」的分類,"
          "\n      因此父分類「抓到 < 站方」屬正常;要看的是葉分類是否吻合。", flush=True)
    bad, leaf_bad = 0, 0
    has_child = {c["parent"] for c in cats.values() if c["parent"]}
    for c in sorted(cats.values(), key=lambda x: -x["count"]):
        got = tally.get(str(c["id"]), 0)
        if c["count"] == 0 and got == 0:
            continue
        ok = "✓" if got == c["count"] else "✗"
        if got != c["count"]:
            bad += 1
            if c["id"] not in has_child:
                leaf_bad += 1
                ok = "✗葉"
        print(f"  {ok} {cat_path(cats, c['id'])[:34]:36s} 抓到 {got:5d} / 站方 {c['count']:5d}", flush=True)
    print(f"對不上的分類:{bad} 個(其中**葉分類** {leaf_bad} 個 ← 只有這個數字要為零)", flush=True)


# ── 4. probe ────────────────────────────────────────────────

def probe():
    print("== A. 分類樹與件數 ==", flush=True)
    cats = fetch_categories()
    if not cats:
        print("  取不到分類 API,請確認 Store API 是否可用", flush=True)
        return
    for c in sorted(cats.values(), key=lambda x: (x["parent"], -x["count"])):
        print(f"  id={c['id']:<6} {c['count']:5d}  {c['slug'][:26]:28s} {cat_path(cats, c['id'])}", flush=True)
    print(f"分類 {len(cats)} 個;count 合計 {sum(c['count'] for c in cats.values())}"
          f"(父子會重複計算)", flush=True)

    print("\n== B. 分頁邊界 ==", flush=True)
    first = fetch_json(f"{API}/products?per_page=5&page=1")
    over = fetch_json(f"{API}/products?per_page=5&page=9999")
    print(f"  page=1 → {len(first) if isinstance(first, list) else type(first).__name__} 件", flush=True)
    print(f"  page=9999 → {over if not isinstance(over, list) else f'{len(over)} 件'}", flush=True)

    print("\n== C. 抽樣欄位 ==", flush=True)
    # ★8/25 福音書房教訓:抽樣要抽「主題大類」,不要抽新品/促銷(那裡全是月曆、
    #   畫框等非書,會誤判成「這站沒有 ISBN/作者」)。這裡固定從「書籍」分類抽。
    books_id = next((c["id"] for c in cats.values() if c["slug"] == "books"), None)
    q = f"category={books_id}&" if books_id else ""
    print(f"  抽樣來源:{'書籍分類 id=' + str(books_id) if books_id else '全站(找不到 books 分類)'}", flush=True)
    sample = fetch_json(f"{API}/products?{q}per_page=8&page=1") or []
    hits: dict[str, int] = {}
    for p in sample:
        rec = parse_product(p, cats)
        if not rec:
            continue
        for k in ("title", "item_no", "authors_raw", "translators_raw", "publisher",
                  "price_list", "price_sale", "isbn", "publish_date", "summary", "cover_url",
                  "spec_raw", "language", "page_count", "dimensions", "attrs", "brands",
                  "stock_status", "categories"):
            if rec.get(k):
                hits[k] = hits.get(k, 0) + 1
        print("\n  ---", rec.get("title"), "---")
        print(json.dumps(rec, ensure_ascii=False, indent=1)[:1000], flush=True)
    if sample:
        print(f"\n  欄位命中率(樣本 {len(sample)} 件):", flush=True)
        for k, v in sorted(hits.items(), key=lambda x: -x[1]):
            print(f"    {k:16s} {v}/{len(sample)}", flush=True)

    print("\n== D. 原始 JSON 欄位一覽(供下一版對映)==", flush=True)
    if sample:
        p = sample[0]
        print("  keys:", list(p.keys()), flush=True)
        for f in ("attributes", "brands", "tags", "dimensions", "weight",
                  "formatted_dimensions", "formatted_weight", "type", "stock_availability"):
            v = p.get(f)
            if v not in (None, "", [], {}):
                print(f"  {f}: {json.dumps(v, ensure_ascii=False)[:200]}", flush=True)
        print("  prices:", json.dumps(p.get("prices"), ensure_ascii=False)[:300], flush=True)
        print("  description(前 300):", strip_html(p.get("description"))[:300], flush=True)




NEW_ENTRY_CAT = 194     # 「最近上架 > 書籍NEW」;禮品NEW(196)與食品NEW(345)不抓


def collect_new(max_pages: int = 3) -> list[dict]:
    """每日新品:Store API 直接取「書籍NEW」分類(一次就有完整欄位,不必開商品頁)。"""
    cats = fetch_categories()
    out: list[dict] = []
    for page in range(1, max_pages + 1):
        data = fetch_json(f"{API}/products?category={NEW_ENTRY_CAT}"
                          f"&per_page={PER_PAGE}&page={page}", force=True)
        if not data:
            break
        for pr in data:
            rec = parse_product(pr, cats)
            if rec:
                out.append(rec)
        if len(data) < PER_PAGE:
            break
    return out


# ── 對映表產生器(--emit-map-sql)────────────────────────────
# id → (站內分類名 或 None=僅存證, unpublish, sort_order, note)
# 決議:全站抓入存證,非書(文具禮品/教會用品/影音/食品/文創紀念品/月曆)下架;
#       促銷彙整(近期特賣、最近上架、加購專區)僅存證不歸類;
#       台語/客語/原民語已在爬蟲以 language 標記。
CAT_MAP: dict[int, tuple] = {
    # ── 書籍(父 500 兜底,子類優先)───────────────────────
    200: ("綜合其他", 0, 500, "書籍父類直接指派;有子類者以子類為 primary"),
    202: ("聖經研究", 0, 20, None), 203: ("神學", 0, 30, None),
    204: ("靈修", 0, 40, None),     205: ("青少年家庭", 0, 50, "人際生活;待覆核(或改心理)"),
    302: ("文學", 0, 60, "香港文藝"), 371: ("綜合其他", 0, 480, "本版書籍=自家出版,非主題"),
    273: ("兒童教材", 0, 70, None),  244: ("歷史", 0, 80, "教會歷史"),
    207: ("門徒造就", 0, 90, "事奉佈道;待覆核(佈道類或可歸福音)"),
    222: ("綜合其他", 0, 490, "其他書籍"), 243: ("文學", 0, 61, "基督教文學"),
    303: ("綜合其他", 0, 470, "香港漢語=出版社別,非主題;待覆核"),
    206: ("綜合其他", 0, 460, "台文系列;語言已標 nan,待覆核是否另立分類"),
    220: ("門徒造就", 0, 91, "小組教材"), 405: ("靈修", 0, 41, "禱告/靈修"),
    425: ("心理", 0, 100, "心靈/輔導"), 594: ("見證", 0, 110, "生命見證/傳記"),
    291: ("教會復興", 0, 120, "敬拜/崇拜;待覆核"),
    335: ("聖經研究", 0, 21, "INTERPRETATION 解經講道註釋"),
    704: ("神學", 0, 31, "宋泉盛牧師專區"), 221: ("期刊雜誌", 0, 130, "期刊類"),
    449: ("綜合其他", 0, 450, "洪溫柔牧師系列"), 358: ("綜合其他", 0, 451, "聚珍典藏"),
    596: ("聖經研究", 0, 22, "麥種聖經註釋"), 644: ("聖經研究", 0, 23, "聖經公會研讀本"),
    341: ("聖經研究", 0, 24, "天道聖經註釋書系列"), 656: ("歷史", 0, 81, "賴永祥文集"),
    693: ("神學", 0, 32, "陳南州/基督教信仰叢書"),
    # ── 聖經詩 ────────────────────────────────────────
    75: ("聖經", 0, 10, "聖經詩父類"), 76: ("聖經", 0, 11, None), 232: ("聖經", 0, 11, None),
    77: ("聖經", 0, 11, None), 78: ("聖經", 0, 11, None), 407: ("聖經", 0, 11, None),
    286: ("聖經", 0, 11, None), 403: ("聖經", 0, 11, "中英聖經"),
    79: ("聖經", 0, 12, "台語/客語聖經;language 已標"),
    82: ("聖經", 0, 12, "英語/原民/其他語言聖經;language 已標"),
    287: ("聖經", 0, 13, "兒童/注音聖經"), 436: ("聖經", 0, 14, "加工燙金"),
    404: ("聖經研究", 0, 25, "註釋/研讀聖經"), 281: ("詩本樂譜", 0, 140, "聖詩"),
    285: (None, 1, 610, "有聲聖經=影音,非書"),
    # ── 總會書籍/教材 ─────────────────────────────────
    597: ("門徒造就", 0, 470, "總會書籍/教材父類"),
    595: ("兒童主日學", 0, 150, "主日學/教材"), 600: ("青少年家庭", 0, 151, "青少年系列"),
    599: ("門徒造就", 0, 92, "成人培育"), 601: ("兒童主日學", 0, 152, "兒童(國小以下)"),
    609: ("兒童主日學", 0, 153, "輔助教材"), 608: ("兒童主日學", 0, 154, "季教材"),
    603: ("兒童主日學", 0, 155, "青少年生命教育教材"), 605: ("兒童教材", 0, 156, "兒童生命教育教材"),
    602: ("聖經研究", 0, 26, "互動研經"), 604: ("兒童主日學", 0, 157, "大手牽小手"),
    615: ("兒童教材", 0, 158, "歌謠系列"), 614: ("兒童教材", 0, 159, "聖經故事本"),
    598: ("門徒造就", 0, 93, "一領一/造就"), 606: ("福音", 0, 160, "一領一/領人"),
    607: ("靈修", 0, 42, "一領一/祈禱"),
    # ── 其他上架 ──────────────────────────────────────
    375: ("靈修", 0, 43, "手抄經文專區(手抄本)"), 366: ("靈修", 0, 44, "聖經充滿我"),
    384: ("兒童教材", 0, 71, "美好腳蹤系列繪本"),
    # ── 促銷/新品彙整:僅存證不歸類 ─────────────────────
    250: (None, 0, 900, "近期特賣"), 289: (None, 0, 901, "39折福利品"),
    376: (None, 0, 901, "71折福利品"), 658: (None, 0, 901, "66折福利品"),
    293: (None, 0, 902, "網路書房限定"), 431: (None, 0, 902, "聖經優惠"),
    313: (None, 0, 902, "5%台譯計畫"), 301: (None, 0, 902, "新眼光讀經推薦"),
    507: (None, 0, 902, "銅板價福利品"), 317: (None, 0, 902, "清潔的人有福了"),
    402: (None, 0, 902, "特價商品"), 385: ("聖經", 0, 15, "韓文聖經;language 標 ko"),
    193: (None, 0, 910, "最近上架"), 194: (None, 0, 911, "書籍NEW=每日新品入口"),
    196: (None, 0, 911, "禮品NEW"), 361: (None, 0, 912, "加購專區"),
    793: (None, 0, 913, "聖地之旅專區(書+紀念品混)"), 495: (None, 0, 914, "未分類商品"),
    # ── 非書下架 ──────────────────────────────────────
    159: (None, 1, 600, "文具禮品"), 160: (None, 1, 600, "擺飾掛飾"),
    165: (None, 1, 600, "其他文具/生活雜貨"), 173: (None, 1, 600, "經文紙品"),
    223: (None, 1, 600, "聚珍堂文創"), 224: (None, 1, 600, "桌遊/玩具"),
    226: (None, 1, 600, "PCT紀念商品"), 249: (None, 1, 600, "十字架"),
    344: (None, 1, 600, "公益貿易平台"), 456: (None, 1, 600, "卡片/明信片"),
    457: (None, 1, 600, "經文盒卡"), 458: (None, 1, 600, "書籤/便條紙"),
    465: (None, 1, 600, "筆記手札"), 466: (None, 1, 600, "禱文卡"),
    467: (None, 1, 600, "貼紙/封口貼"), 485: (None, 1, 600, "日本立體桌曆"),
    491: (None, 1, 600, "CVS印尼木雕"),
    178: (None, 1, 620, "教會用品"), 179: (None, 1, 620, "程序單"),
    181: (None, 1, 620, "春聯門聯紅包袋"), 182: (None, 1, 620, "聖餐用品"),
    184: (None, 1, 620, "其他教會用品"), 219: (None, 1, 620, "婚喪用品"),
    280: (None, 1, 620, "教牧服裝"), 266: (None, 1, 620, "婚用B4"), 267: (None, 1, 620, "喪用B4"),
    268: (None, 1, 620, "萬用B4"), 269: (None, 1, 620, "婚用A4"), 270: (None, 1, 620, "喪用A4"),
    271: (None, 1, 620, "萬用A4"), 462: (None, 1, 620, "紅包袋"), 463: (None, 1, 620, "大小春聯"),
    464: (None, 1, 620, "門聯"),
    154: (None, 1, 610, "影音商品"), 155: (None, 1, 610, "CD類"), 156: (None, 1, 610, "DVD"),
    158: ("詩本樂譜", 0, 141, "樂譜/歌本=紙本樂譜,上架"),
    450: ("詩本樂譜", 0, 142, "聖誕節樂譜"), 451: ("詩本樂譜", 0, 142, "復活節樂譜"),
    452: ("詩本樂譜", 0, 142, "前奏/伴奏曲"),
    455: (None, 1, 610, "兒童CD"), 468: (None, 1, 610, "其他影音"),
    157: (None, 1, 610, "聖經有聲CD"), 432: (None, 1, 600, "經文磁鐵便條貼"),
    345: (None, 1, 630, "食品NEW"), 715: (None, 1, 600, "種籽設計(文創)"),
    739: (None, 1, 600, "PCT 160 宣教紀念商品"), 800: (None, 1, 600, "2027週月曆專區"),
}


def emit_map_sql():
    cats = fetch_categories()
    rows, missing = [], []
    for c in sorted(cats.values(), key=lambda x: x["id"]):
        m = CAT_MAP.get(c["id"])
        if m is None:
            missing.append(c)
            m = (None, 0, 950, "★站方新增分類,請補對映")
        name, unp, srt, note = m
        path = cat_path(cats, c["id"]).replace("'", "''")
        nm = "NULL" if name is None else f"'{name}'"
        nt = "NULL" if not note else "'" + str(note).replace("'", "''") + "'"
        rows.append(f"({c['id']}, '{path[:80]}', '{c['slug'][:100]}', {nm}, {unp}, {srt}, {nt})")
    print("-- 自動產生:python3 pctpress_crawler.py --emit-map-sql > "
          "../database/migrations/2026-08-27_pctpress_category_map.sql")
    print(f"-- 分類 {len(cats)} 個;未對映 {len(missing)} 個")
    print("""
CREATE TABLE IF NOT EXISTS pctpress_category_map (
  pct_id        INT          NOT NULL COMMENT 'WooCommerce 分類 id(=subjects.code)',
  pct_path      VARCHAR(80)  NOT NULL COMMENT '站方分類路徑(父 > 子,僅供人讀)',
  pct_slug      VARCHAR(100) NULL     COMMENT '站方 slug(percent-encoded 中文,僅供人讀)',
  internal_name VARCHAR(50)  NULL     COMMENT '對映到的站內 categories.name;NULL=僅存證不歸類',
  unpublish     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=非書,pctpress-only 命中任一即下架',
  sort_order    INT          NOT NULL DEFAULT 500 COMMENT 'primary 優先序(小者優先)',
  note          VARCHAR(200) NULL,
  PRIMARY KEY (pct_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='台灣教會公報社分類對映(2026-08-27)';

INSERT INTO pctpress_category_map
  (pct_id, pct_path, pct_slug, internal_name, unpublish, sort_order, note) VALUES""")
    print(",\n".join(rows) + "\nON DUPLICATE KEY UPDATE pct_path = VALUES(pct_path), pct_slug = VALUES(pct_slug);")
    if missing:
        print("\n-- ★以下分類尚未對映(已填 NULL/僅存證),請補:")
        for c in missing:
            print(f"--   id={c['id']} {cat_path(cats, c['id'])}({c['count']} 件)")


# ── 5. main ─────────────────────────────────────────────────

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true")
    ap.add_argument("--emit-map-sql", action="store_true",
                    help="輸出分類對映表 SQL(129 列)到 stdout")
    ap.add_argument("--limit", type=int, default=0)
    args = ap.parse_args()

    if args.emit_map_sql:
        emit_map_sql()
        return
    if args.probe:
        probe()
        return

    DATA.mkdir(exist_ok=True)
    writer = JsonlWriter(DATA / "pctpress_books.jsonl", key_field="pid")

    print("== 第一階段:分類 ==", flush=True)
    cats = fetch_categories()
    print(f"分類 {len(cats)} 個", flush=True)

    print("== 第二階段:全站商品(Store API)==", flush=True)
    recs = walk_products(cats, args.limit)
    done = sum(1 for r in recs if writer.write(r))
    print(f"完成:本次入檔 {done} 件(檔案累計 {len(writer.seen)})", flush=True)

    reconcile(recs, cats)

    longest: dict[str, tuple[int, str]] = {}
    for r in recs:
        for k, v in r.items():
            if isinstance(v, str) and len(v) > longest.get(k, (0, ""))[0]:
                longest[k] = (len(v), v[:80])
    print("\n[最長欄位值檢查——對照 schema 上限]", flush=True)
    for k, (n, s) in sorted(longest.items(), key=lambda x: -x[1][0])[:12]:
        print(f"  {k:14s} {n:5d}  {s}", flush=True)


if __name__ == "__main__":
    main()
