# -*- coding: utf-8 -*-
"""橄欖華宣網路商城 cclm.com.tw 爬蟲(單執行緒、節流 2-3 秒、快取續跑)。

8/19 偵察結論(Chrome live 實測):
 1. 橄欖華宣(台灣,橄欖/華宣/白象等品牌),**自建 SSR 商城**(OpenCart 系,
    網址為中文 slug 或 /product-category/{id}),TWD,全站繁體。
 2. 清單頁:/{中文分類} 或 /product-category/{id},?page=N 分頁(每頁 15,
    聖經枝 11);商品連結 /product/{數字 id}。**排序為 id 遞減(新→舊)**
    → 每日增量可「見到既有即停」。
    量:【書籍】294 頁約 4,400 件、【聖經】10 頁約 110 件。
 3. 商品頁(SSR,可直接 requests+bs4):
    - h1 書名;#description 內容簡介;
    - **#additionalinformation 規格區**:商品貨號/出版社/作者/ISBN/頁數/
      尺寸/重量(標籤與值以全形冒號分隔,標籤與值可能被 inline 標籤拆開
      → 以「標籤字樣+冒號」切割整段,不依賴斷行)
    - .new-price / .old-price(NT$ 現價/原價);og:image 封面。
 4. 範圍(8/19 決議,沿以琳「只抓書籍+聖經」):【書籍】全枝 + 【聖經】全枝;
    文創/客製印刷/節期禮品不抓。聖經周邊(書衣/籤條等非書)抓入存證,
    由 cclm_category_map 下架(任一命中即下架,沿天恩規則)。
 5. 分類歸屬由「清單走訪」蒐集(一書多分類,同以琳/衛理/格子外面);
    分類樹 8/19 快照寫死(站方改選單時需重新偵察)。

用法(主機;venv 沿用既有):
  python3 cclm_crawler.py --probe          # 驗證清單+商品頁解析(先跑,貼回輸出)
  nohup python3 cclm_crawler.py > logs/cclm.log 2>&1 &   # 全量(可中斷續跑)
  python3 cclm_crawler.py --limit 30       # 試跑 30 件
"""
from __future__ import annotations

import argparse
import json
import re
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import quote, urljoin

from bs4 import BeautifulSoup

from common import JsonlWriter, make_session, polite_fetch

BASE = "https://www.cclm.com.tw"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "cclm"
DATA = HERE / "data"
THROTTLE = (2.0, 3.0)

session = make_session()


def fetch(url: str, force: bool = False) -> str | None:
    try:
        return polite_fetch(session, url, CACHE, THROTTLE, force=force)
    except RuntimeError as e:
        print(f"  [抓取失敗] {url} → {e}", flush=True)
        return None


# ── 1. 分類走訪清單(寫死;導覽選單 8/19 快照)────────────────
# code=分類路徑末段(中文 slug 或 pc{id},截 20 字);path=「群組 > 名稱」;
# promo=彙整/出版社型(選 primary 存證分類時排最後)。
CATEGORIES: list[dict] = []


def _cat(path_url: str, name: str, menu: str, promo: bool = False):
    """path_url:網站路徑(如 /神學概論 或 /product-category/426)。"""
    seg = path_url.rstrip("/").rsplit("/", 1)[-1]
    code = ("pc" + seg if path_url.startswith("/product-category/") else seg)[:20]
    CATEGORIES.append({"code": code, "url": path_url, "name": name,
                       "path": f"{menu} > {name}", "menu": menu,
                       "promo": promo, "order": len(CATEGORIES)})


_M_BOOK = "書籍"
_M_BIBLE = "聖經"
_M_PUB = "出版社"

# 書籍(彙整)
_cat("/書籍", "全部書籍", _M_BOOK, promo=True)
# 神學/研經
for _u, _n in [("/神學概論", "神學概論"), ("/系統神學", "系統神學"),
               ("/衛道與護教", "衛道與護教"), ("/基督教派別教義", "基督教派別教義"),
               ("/真理", "真理"), ("/新約", "新約"), ("/舊約", "舊約"),
               ("/新舊約", "新舊約"), ("/註釋書", "註釋書"),
               ("/專題論述", "專題論述")]:
    _cat(_u, _n, _M_BOOK)
# 生活/家庭
for _u, _n in [("/戀愛交友", "交友戀愛"), ("/婚姻家庭", "婚姻家庭"),
               ("/女性成長", "女性成長"), ("/男性成長", "男性成長"),
               ("/生活教導", "生活教導"), ("/職場管理", "職場管理"),
               ("/兒童圖書", "兒童圖書"), ("/親子教育", "親子教育"),
               ("/青少年教育", "青少年教育"), ("/醫學保健", "醫學保健"),
               ("/飲食生活", "飲食生活"), ("/product-category/279", "銀髮生活")]:
    _cat(_u, _n, _M_BOOK)
# 心靈/造就
for _u, _n in [("/product-category/80", "醫治輔導"),
               ("/product-category/79", "心靈勵志"),
               ("/product-category/81", "門徒造就"),
               ("/product-category/83", "休閒藝文"),
               ("/product-category/82", "心靈保健"),
               ("/初信慕道", "初信慕道"), ("/靈命造就", "靈命造就"),
               ("/屬靈爭戰", "屬靈爭戰"), ("/傳記見證", "傳記見證")]:
    _cat(_u, _n, _M_BOOK)
# 教會事工
for _u, _n in [("/福音佈道", "福音佈道"), ("/團契小組", "團契小組"),
               ("/教會增長", "教會增長"), ("/兒童事工", "兒童事工"),
               ("/青少年事工", "青少年事工"), ("/音樂事工", "音樂事工"),
               ("/各項事工", "各項事工"), ("/教會歷史", "教會歷史"),
               ("/講道信息", "講道信息"), ("/豐盛人生", "豐盛人生"),
               ("/奇異恩典", "奇異恩典")]:
    _cat(_u, _n, _M_BOOK)
# 教材
for _u, _n in [("/慕道課程", "慕道課程"), ("/門徒訓練", "門徒訓練"),
               ("/查經教材", "查經教材"), ("/小組教材", "小組教材"),
               ("/兒童教材", "兒童教材"), ("/青少年教材", "青少年教材")]:
    _cat(_u, _n, _M_BOOK)
# 版本別(簡體/電子書)
_cat("/product-category/426", "簡體版", _M_BOOK)
_cat("/product-category/350", "電子書", _M_BOOK)
# 聖經枝
_cat("/product-category/73", "全部聖經", _M_BIBLE, promo=True)
for _u, _n in [("/和合本", "和合本"), ("/新譯本", "新譯本"),
               ("/當代譯本", "當代譯本"), ("/台語聖經", "台語聖經"),
               ("/注音聖經", "注音聖經"), ("/新普及譯本", "新普及譯本"),
               ("/product-category/444", "原住民語"),
               ("/bible/GNT", "GNT"), ("/KJV", "KJV"), ("/ESV", "ESV"),
               ("/NLT", "NLT"), ("/KJV／和合本", "KJV/和合本"),
               ("/NIV／和合本", "NIV/和合本"), ("/NIV／當代譯本", "NIV/當代譯本"),
               ("/NLT／新普及譯本", "NLT/新普及譯本"),
               ("/NIV／新譯本", "NIV/新譯本"), ("/兒童聖經", "兒童聖經"),
               ("/注釋/研讀", "註釋研讀"), ("/外語聖經", "外語聖經"),
               ("/其他譯本", "其他譯本"), ("/聖經周邊", "聖經周邊"),
               ("/product-category/337", "聖經資源中心")]:
    _cat(_u, _n, _M_BIBLE)
# 出版社(彙整;僅蒐集歸屬存證)
for _u, _n in [("/product-category/297", "橄欖"), ("/product-category/324", "主流"),
               ("/product-category/298", "華神"), ("/product-category/427", "賢理出版"),
               ("/product-category/314", "啟示"), ("/product-category/300", "青橄欖"),
               ("/product-category/507", "譚文英"), ("/product-category/401", "聖資以利亞"),
               ("/product-category/301", "歸主"), ("/product-category/299", "真光機構"),
               ("/聖資圖書", "聖資圖書"), ("/product-category/302", "台灣文藝"),
               ("/product-category/506", "大可文化"), ("/product-category/480", "中主"),
               ("/product-category/501", "永望"), ("/product-category/497", "天恩"),
               ("/product-category/492", "上智"), ("/product-category/498", "出色文化"),
               ("/product-category/481", "校園"), ("/product-category/288", "商周"),
               ("/product-category/486", "道聲"), ("/product-category/487", "保羅"),
               ("/product-category/493", "宇宙光"), ("/product-category/483", "榮益"),
               ("/product-category/494", "改革宗"), ("/product-category/508", "森日禮")]:
    _cat(_u, _n, _M_PUB, promo=True)


# ── 2. 清單頁 ────────────────────────────────────────────────

PROD_RE = re.compile(r"/product/(\d+)")


def list_url(path_url: str, page: int) -> str:
    u = BASE + quote(path_url, safe="/")
    return u if page <= 1 else f"{u}?page={page}"


def extract_ids(html: str) -> list[str]:
    """清單頁 → 商品 id 清單(保持頁面順序,去重)。"""
    out: list[str] = []
    seen: set[str] = set()
    for m in PROD_RE.finditer(html):
        pid = m.group(1)
        if pid not in seen:
            seen.add(pid)
            out.append(pid)
    return out


# 分頁終止條件(8/20 兩次修正)
# ------------------------------------------------------------------
# 原本「首次空頁/無新項即停」→「書籍」只走到第 3 頁(42 件)、全站僅 1,595 件。
# 實測 cclm 的分頁序列布滿「空洞」:每頁上限 15 件但常只有 7~12 件,
# page=120、150 完全是空頁,page=200/250/294 卻仍有商品
# (下架/隱藏商品先佔了分頁位、再於輸出時被濾掉所致)。
# 空洞長度不可預測,任何「連續 N 頁沒東西就停」都會提前收工,
# 因此改以「分頁列宣告的最大頁碼」為主要邊界,空頁一律略過續走;
# 另加 PAGE_MARGIN 滾動延伸,萬一實際頁數超過宣告值也能追上去。
PAGE_MARGIN = 6                 # 最後一次抓到新項之後,至少再往下走幾頁
_PAGE_HINT_RE = re.compile(r"[?&]page=(\d+)")


def max_page_hint(html: str) -> int:
    """由清單頁的分頁連結取最大頁碼(取不到視為 1 頁)。"""
    nums = [int(x) for x in _PAGE_HINT_RE.findall(html or "")]
    return max(nums) if nums else 1


def walk_category(path_url: str, force: bool = False, max_pages: int = 400,
                  stop_on_seen: set[str] | None = None) -> list[str]:
    """走訪一個分類的所有清單頁(空洞頁略過,走到分頁列宣告的末頁為止)。
    stop_on_seen 給每日增量用:清單為 id 遞減(新→舊),整頁皆已見即停。"""
    ids: list[str] = []
    seen: set[str] = set()
    limit = 1                          # 目前已知需走到第幾頁(邊走邊延伸)
    page = 1
    while page <= max_pages:
        html = fetch(list_url(path_url, page), force=force)
        if html is None:
            break
        if page == 1:                  # 分頁列宣告的末頁 = 主要邊界
            limit = max(limit, min(max_page_hint(html), max_pages))
        got = extract_ids(html)
        fresh = [p for p in got if p not in seen]
        if fresh:
            seen.update(got)
            ids.extend(fresh)
            limit = max(limit, min(page + PAGE_MARGIN, max_pages))
            # 增量:整頁皆已在庫 → 之後更舊,停
            if stop_on_seen is not None and all(p in stop_on_seen for p in got):
                break
        if page >= limit:              # 走到邊界且近幾頁都沒新項 = 真的走完
            break
        page += 1
    return ids


def collect_memberships(force_lists: bool = False,
                        stop_on_seen: set[str] | None = None) -> dict[str, dict]:
    """走訪全部分類 → pid → {'cats': [分類...]}(非 promo 先、選單順序)。"""
    members: dict[str, dict] = {}
    for c in CATEGORIES:
        ids = walk_category(c["url"], force=force_lists, stop_on_seen=stop_on_seen)
        if ids:
            print(f"[清單] {c['path']}:{len(ids)} 件", flush=True)
        for pid in ids:
            d = members.setdefault(pid, {"cats": []})
            if c["code"] not in [x["code"] for x in d["cats"]]:
                d["cats"].append(c)
    for d in members.values():
        d["cats"].sort(key=lambda c: (c["promo"], c["order"]))
    return members


# ── 3. 商品頁解析 ────────────────────────────────────────────

_COLONS = "\uff1a:\u2236"  # 全形冒號 U+FF1A、半形、比號(寫死 escape,防生成器改字)

# 規格區標籤 → rec 欄位(出版日期須排在出版社之前)
SPEC_LABELS: list[tuple[str, str]] = [
    ("item_no_page",    "商品貨號"),
    ("isbn",            "ISBN"),
    ("ean",             "EAN"),
    ("authors_raw",     "作者"),
    ("translators_raw", "譯者"),
    ("illustrators_raw", "繪者"),
    ("publish_date",    "出版日期"),
    ("publisher_page",  "出版社"),
    ("page_count",      "頁數"),
    ("dimensions",      "尺寸"),
    ("weight",          "重量"),
    ("binding",         "裝訂"),
    ("language",        "語言"),
    ("series",          "系列"),
]
# 值可能被 inline 標籤與值分離(如「出版社」「:」「那好牧人出版社」三段)
# → 一律以「標籤字樣+冒號」切割整段正規化文字,不依賴斷行(沿 8/17 衛理教訓)
_SPEC_SPLIT = re.compile(
    r"(商品貨號|ISBN|EAN|作者|譯者|繪者|出版日期|出版社|頁數|尺寸|重量|裝訂|語言|系列)"
    r"\s*[" + _COLONS + r"]")
_NOISE = re.compile(r"【如何退貨】|【注意事項】|客服信箱|退換貨")
# 8/19 probe 教訓:規格區可能出現未在 SPEC_LABELS 的標籤(如「性質:本版」),
# 切割後會黏在前一欄值尾(dimensions="17.5 x 23cm 性質 :本版")。
# → 值一律再截斷於「2-5 個中文字 + 冒號」樣式;規格原文另存 rec.spec_raw
# (不丟資料鐵律),日後要新增欄位可從 extra 回溯。
_STRAY_LABEL = re.compile(r"\s*[\u4e00-\u9fff]{2,5}\s*[" + _COLONS + r"].*$")


def parse_spec(text: str) -> dict:
    label_key = {lab: key for key, lab in SPEC_LABELS}
    text = _NOISE.split(text)[0]              # 規格區後接退貨條款 → 切掉
    parts = _SPEC_SPLIT.split(re.sub(r"\s+", " ", text))
    fields: dict = {}
    for i in range(1, len(parts) - 1, 2):
        key = label_key.get(parts[i])
        val = _STRAY_LABEL.sub("", parts[i + 1]).strip()
        if key and key not in fields and val:
            fields[key] = val
    return fields


def clean_person(s: str | None) -> str | None:
    if not s:
        return None
    s = re.sub(r"^\s*(作者|譯者|繪者)\s*[" + _COLONS + r"]\s*", "", s.strip())
    return re.sub(r"\s{2,}", " ", s) or None


_ISBN_RE = re.compile(r"97[89][-\s]?(?:\d[-\s]?){9}\d")
_AMOUNT_RE = re.compile(r"[\d,]+(?:\.\d+)?")


def norm_isbn(s: str | None) -> str | None:
    if not s:
        return None
    m = _ISBN_RE.search(s)
    if not m:
        return None
    digits = re.sub(r"[^0-9]", "", m.group(0))
    return digits if len(digits) == 13 else None


def _amount(text: str | None) -> str | None:
    if not text:
        return None
    m = _AMOUNT_RE.search(text)
    return m.group(0).replace(",", "") if m else None


def product_url(pid: str) -> str:
    return f"{BASE}/product/{pid}"


def _meta(soup: BeautifulSoup, prop: str) -> str | None:
    el = soup.select_one(f'meta[property="{prop}"]') or soup.select_one(f'meta[name="{prop}"]')
    v = (el.get("content") or "").strip() if el else ""
    return v or None


def parse_product(pid: str, cats: list[dict], retried: bool = False) -> dict | None:
    html = fetch(product_url(pid), force=retried)
    if html is None:
        return None
    soup = BeautifulSoup(html, "lxml")

    h1 = soup.select_one("h1")
    name = (h1.get_text(" ", strip=True) if h1 else None) or _meta(soup, "og:title")
    if not name:
        if not retried:                       # 可能快取到空殼頁 → force 重抓
            return parse_product(pid, cats, retried=True)
        print(f"  [略過 {pid}] 找不到書名", flush=True)
        return None

    rec: dict = {
        "pid": pid,
        "source": "cclm",
        "source_url": product_url(pid),
        "title": re.sub(r"\s+", " ", name).strip(),
        "is_ebook": False,
        "currency": "TWD",
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }

    # 規格區(#additionalinformation);缺時退整頁文字
    spec_el = soup.select_one("#additionalinformation")
    spec_txt = spec_el.get_text("\n", strip=True) if spec_el else ""
    spec = parse_spec(spec_txt) if spec_txt else {}
    if not spec:
        body = soup.select_one("#tab-content, .product-detail, body")
        if body is not None:
            spec_txt = body.get_text("\n", strip=True)[:6000]
            spec = parse_spec(spec_txt)
    if spec_txt:                              # 規格原文存證(含未映射標籤)
        rec_spec_raw = _NOISE.split(re.sub(r"\n{2,}", "\n", spec_txt))[0].strip()[:1500]
    else:
        rec_spec_raw = None

    # 簡介(#description)
    desc_el = soup.select_one("#description")
    summary = desc_el.get_text("\n", strip=True) if desc_el else None
    if summary:
        summary = re.sub(r"\n{3,}", "\n\n", summary).strip()
    if not summary:
        summary = _meta(soup, "og:description")

    # 價格(.new-price 現價、.old-price 原價;無折扣時僅一個)
    new_el = soup.select_one(".new-price, .p-new-price")
    old_el = soup.select_one(".old-price, .p-old-price")
    cur = _amount(new_el.get_text() if new_el else None)
    old = _amount(old_el.get_text() if old_el else None)
    price_list = old or cur
    if price_list is not None:
        rec["price_list"] = price_list
        try:
            if cur is not None and old is not None and float(cur) < float(old):
                rec["price_sale"] = cur
        except ValueError:
            pass

    isbn = norm_isbn(spec.get("isbn")) or norm_isbn(spec.get("ean"))
    if isbn:
        rec["isbn"] = isbn
    if spec.get("item_no_page"):
        rec["item_no"] = spec["item_no_page"][:30]        # → identifiers(STORE)
    authors = clean_person(spec.get("authors_raw"))
    if authors:
        rec["authors_raw"] = authors
    for key in ("translators_raw", "illustrators_raw"):
        val = clean_person(spec.get(key))
        if val:
            rec[key] = val
    if spec.get("publisher_page"):
        rec["publisher"] = spec["publisher_page"]
    if spec.get("page_count"):
        m = re.search(r"[\d,]+", spec["page_count"])
        if m:
            rec["page_count"] = m.group(0).replace(",", "")
    if spec.get("publish_date"):
        m = re.search(r"(\d{4})\D?(\d{1,2})?\D?(\d{1,2})?", spec["publish_date"])
        if m:
            rec["publish_date"] = (m.group(1)
                                   + (f"-{int(m.group(2)):02d}" if m.group(2) else "")
                                   + (f"-{int(m.group(3)):02d}" if m.group(2) and m.group(3) else ""))
    for key in ("dimensions", "weight", "binding", "language", "series"):
        if spec.get(key):
            rec[key] = spec[key]
    if rec_spec_raw:
        rec["spec_raw"] = rec_spec_raw
    if summary:
        rec["summary"] = summary

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
    for url, label in [("/書籍", "書籍"), ("/product-category/73", "聖經"),
                       ("/註釋書", "註釋書"), ("/兒童圖書", "兒童圖書")]:
        h1 = fetch(list_url(url, 1), force=True)
        ids1 = extract_ids(h1) if h1 else []
        pages = sorted({int(x) for x in re.findall(r"page=(\d+)", h1 or "")}, reverse=True)
        print(f"[範圍] {label}:每頁 {len(ids1)} 件、最大分頁 {pages[0] if pages else 1}",
              flush=True)
    h1 = fetch(list_url("/書籍", 1), force=True)
    h2 = fetch(list_url("/書籍", 2), force=True)
    i1 = extract_ids(h1)[:3] if h1 else []
    i2 = extract_ids(h2)[:3] if h2 else []
    print(f"[分頁] 第1頁={i1}、第2頁={i2}"
          f"({'不同 → 分頁有效' if i1 and i2 and i1[0] != i2[0] else '相同,分頁失效,請人工確認!'})",
          flush=True)

    cat_book = CATEGORIES[0]
    for url, label in [("/註釋書", "書(規格齊)"), ("/和合本", "聖經"),
                       ("/聖經周邊", "非書(聖經周邊)"), ("/兒童圖書", "童書")]:
        ids = walk_category(url, force=True, max_pages=1)
        if not ids:
            print(f"\n--- 樣本({label}):清單無商品 ---", flush=True)
            continue
        rec = parse_product(ids[0], [cat_book])
        print(f"\n--- 樣本({label}) ---", flush=True)
        if rec:
            slim = dict(rec)
            slim["summary"] = (rec.get("summary") or "")[:150]
            print(json.dumps(slim, ensure_ascii=False, indent=1)[:2000], flush=True)
    print("\n(請把以上輸出貼回,確認欄位/ISBN/價格/封面無誤後再開全量)", flush=True)


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
    writer = JsonlWriter(DATA / "cclm_books.jsonl", key_field="pid")

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
