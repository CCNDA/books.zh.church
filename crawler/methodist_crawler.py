# -*- coding: utf-8 -*-
"""衛理書房 methodistbookroom.com 爬蟲(單執行緒、節流 2-3 秒、快取續跑)。

8/17 偵察結論(Chrome DOM 實測 + WebFetch):
 1. 馬來西亞衛理公會書局(砂拉越),OpenCart 商城,全站 SSR、幣別 MYR(RM)。
    書名/簡介多為簡體中文 → 沿 8/9 微讀決議:主欄位 OpenCC s2tw 轉繁入庫、
    原始簡體存 rec.hans;is_hans 以「語言」欄位為準(詳細資料面板)。
 2. 導覽選單(SSR、兩層)即完整分類樹,約 27 頂層 + 150 餘子分類 →
    parse_nav() 動態解析,不寫死(站方每兩月新增「新品上架」月份子分類)。
    部分子分類無自己的 SEO 網址(href 同父分類,如「伊班圣经」)→ 略過。
 3. 清單頁:分類網址(SEO slug 或 index.php?route=product/category&path=ID)
    + ?page=N 分頁(每頁 15);?sort=p.date_added&order=DESC 可依上架日倒序
    (8/17 實測有效,每日增量靠它)。商品磚 .module-product .frame:
    .product-name a(名稱+連結)、.price-new/.price-old、addToCart('pid')。
 4. 商品頁(以 index.php?route=product/product&product_id=N 正規網址抓,
    避免同商品多分類多網址重複快取):
    - .product-infos .title 書名;table.product-details 規格列
      (Price / Product SKU / Brand / Availability);.price-new/.price-old
    - Product SKU 多數直接是 ISBN13(97x 開頭才認定;禮品為一般條碼)
    - Brand = 出版社(簡體,如「校园书房」→ s2tw)
    - #tab-description 內或有子頁籤(.prod-desc-tab-content):
      内容简介/作者介绍/目录/详细资料;「详细资料」含 作者/译者/出版日期/
      页数/尺寸/排版方式/语言/装订方式/印刷方式/分类(出版社原始分類)。
      禮品與部分外文書無子頁籤 → 整區文字當簡介。
      **8/17 probe 發現:子頁籤結構是前端 JS 生成,raw HTML 為平鋪
      (h2/文字標題行 + 段落)→ 解析改以「標題行切段」,不依賴頁籤類名;
      詳細資料的 label/value 會被 inline 標籤拆行,以 <br> sentinel 併回。**
    - og:image 封面;/image/cache/...-420x420.jpg 改寫回 /image/... 原圖。
 5. robots.txt 404(無限制);分類歸屬由「清單走訪」蒐集(一書多分類,
    商品頁麵包屑只帶進入路徑,不可靠)——同以琳做法。
 6. 範圍(8/17 決議):全站抓入存證;非書(禮品/詩歌CD/桌遊等)與
    外文書(英文/馬來文/印尼文)由 methodist_category_map 下架
    (任一命中即下架,沿天恩規則)。

用法(主機;venv 已含 OpenCC):
  python3 methodist_crawler.py --probe          # 驗證選單+清單+商品頁解析(先跑,貼回輸出)
  nohup python3 methodist_crawler.py > logs/methodist.log 2>&1 &   # 全量(可中斷續跑)
  python3 methodist_crawler.py --limit 30       # 試跑 30 件
"""
from __future__ import annotations

import argparse
import json
import re
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urljoin, urlparse, parse_qs

from bs4 import BeautifulSoup

from common import JsonlWriter, make_session, polite_fetch

BASE = "https://methodistbookroom.com"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "methodist"
DATA = HERE / "data"
THROTTLE = (2.0, 3.0)

session = make_session()

try:
    from opencc import OpenCC
    _CC = OpenCC("s2tw")  # 逐字轉台灣標準字形(沿 8/10 微讀決議,不做詞彙改寫)
except ImportError:
    _CC = None


def s2t(s: str | None) -> str | None:
    if not s or _CC is None:
        return s
    return _CC.convert(s)


def fetch(url: str, force: bool = False) -> str:
    return polite_fetch(session, url, CACHE, THROTTLE, force=force)


# ── 1. 導覽選單 → 分類樹(動態解析,不寫死)─────────────────

# 促銷/彙整型分類:仍走訪存證,但選 primary 存證分類時排最後
PROMO_PREFIXES = ("new-arrivals", "specialdeal", "2025-", "bestselling-authors",
                  "new-node")


def _cat_code(url: str) -> str:
    """分類網址 → 穩定短代碼(subjects.code 上限 20 字):
    route 網址取 path 參數(如 539、152_146);SEO 網址取最後一段 slug
    (OpenCart SEO keyword 全站唯一),超過 20 字截斷。"""
    u = urlparse(url)
    if "route=" in (u.query or ""):
        path = parse_qs(u.query).get("path", [""])[0]
        return path[:20] if path else u.path.strip("/")[:20]
    return (u.path.rstrip("/").rsplit("/", 1)[-1] or u.path.strip("/"))[:20]


def parse_nav(force: bool = False) -> list[dict]:
    """首頁導覽選單 → 分類清單(nav 順序)。
    回傳 [{code, name, path, url, order, promo}];path=「頂層 > 子分類」繁體。
    以 a[href*='route=product/category'] 錨點定位選單根 ul(SSR 直出)。"""
    soup = BeautifulSoup(fetch(BASE + "/", force=force), "lxml")
    anchor = soup.select_one('a[href*="route=product/category"]')
    if anchor is None:
        return []
    ul = anchor.find_parent("ul")
    while ul is not None and ul.find_parent("ul") is not None:
        ul = ul.find_parent("ul")
    if ul is None:
        return []

    cats: list[dict] = []
    seen_urls: set[str] = set()

    def clean_url(href: str) -> str:
        u = urlparse(urljoin(BASE + "/", href))
        q = ""
        if "route=" in (u.query or ""):  # 保留 route/path,去除追蹤參數
            qs = parse_qs(u.query)
            path = qs.get("path", [""])[0]
            q = f"?route=product/category&path={path}" if path else ""
        return f"{BASE}{u.path}{q}"

    def walk(node, parents: list[str], depth: int):
        for li in node.find_all("li", recursive=False):
            a = li.find("a")  # 首個錨點即本分類(子選單的 a 在其後)
            name = url = None
            if a is not None and a.get("href"):
                name = s2t(re.sub(r"\s+", " ", a.get_text(" ", strip=True)))
                url = clean_url(a["href"])
            sub = li.find("ul")  # 子選單(可能隔一層 div 包裹)
            if name and url and url != BASE + "/" and url not in seen_urls:
                seen_urls.add(url)
                path = " > ".join(parents + [name]) if parents else name
                top = (parents or [name])[0]
                cats.append({"code": _cat_code(url), "name": name, "path": path,
                             "url": url, "order": len(cats), "top": top})
            if sub is not None and depth < 3:
                walk(sub, parents + [name] if name else parents, depth + 1)

    walk(ul, [], 1)

    # promo 旗標:促銷/彙整型頂層分類,整枝皆 promo(選 primary 存證時排最後)
    promo_tops = {c["name"] for c in cats
                  if " > " not in c["path"]
                  and any(c["code"].startswith(p) for p in PROMO_PREFIXES)}
    for c in cats:
        c["promo"] = c["top"] in promo_tops
    return cats


# ── 2. 清單頁 ────────────────────────────────────────────────

ADD_CART_RE = re.compile(r"addTo(?:Cart|WishList)\('?(\d+)'?")
PID_INPUT_RE = re.compile(r'name="product_id"\s+value="(\d+)"|product_id["\' ]*[:=]["\' ]*(\d+)')


def list_url(cat_url: str, page: int, by_date: bool = False) -> str:
    sep = "&" if "?" in cat_url else "?"
    u = cat_url
    if by_date:
        u += f"{sep}sort=p.date_added&order=DESC"
        sep = "&"
    if page > 1:
        u += f"{sep}page={page}"
    return u


def extract_tiles(html: str) -> list[tuple[str | None, str | None]]:
    """一頁清單 → [(pid, href)](保持頁面順序;pid 取自 addToCart,
    偶缺時留 None、以 href 補抓)。"""
    soup = BeautifulSoup(html, "lxml")
    out: list[tuple[str | None, str | None]] = []
    seen: set[str] = set()
    for frame in soup.select(".module-product .frame"):
        a = frame.select_one(".product-name a") or frame.select_one(".item-img a")
        href = urljoin(BASE + "/", a["href"]) if a and a.get("href") else None
        pid = None
        m = ADD_CART_RE.search(str(frame))
        if m:
            pid = m.group(1)
        elif href and "product_id=" in href:
            pid = parse_qs(urlparse(href).query).get("product_id", [None])[0]
        key = pid or href
        if key and key not in seen:
            seen.add(key)
            out.append((pid, href))
    return out


def walk_category(cat_url: str, force: bool = False, by_date: bool = False,
                  max_pages: int = 200) -> list[tuple[str | None, str | None]]:
    """走訪一個分類的所有清單頁(空頁或整頁重複即停)。"""
    tiles: list[tuple[str | None, str | None]] = []
    seen: set[str] = set()
    page = 1
    while page <= max_pages:
        try:
            html = fetch(list_url(cat_url, page, by_date), force=force)
        except RuntimeError as e:
            print(f"  [清單] {cat_url} 第 {page} 頁抓取失敗:{e}", flush=True)
            break
        got = extract_tiles(html)
        fresh = [(p, h) for p, h in got if (p or h) not in seen]
        if not got or not fresh:  # 空頁/逾末頁回同頁 = 走完
            break
        seen.update(p or h for p, h in got)
        tiles.extend(fresh)
        page += 1
    return tiles


# ── 3. 商品頁解析 ────────────────────────────────────────────

def product_url(pid: str) -> str:
    return f"{BASE}/index.php?route=product/product&product_id={pid}"


def _meta(soup: BeautifulSoup, prop: str) -> str | None:
    el = soup.select_one(f'meta[property="{prop}"]') or soup.select_one(f'meta[name="{prop}"]')
    v = (el.get("content") or "").strip() if el else ""
    return v or None


def _amount(text: str | None) -> str | None:
    if not text:
        return None
    m = re.search(r"[\d,]+(?:\.\d+)?", text)
    return m.group(0).replace(",", "") if m else None


# 详细资料面板標籤 → 欄位(標籤先 s2tw 再比對;冒號兼容全形/半形,
# 8/6 全形冒號生成 bug 教訓:比對用 unicode-escape 寫死,交付後 hex 驗證)
DETAIL_LABELS: list[tuple[str, str]] = [
    ("authors_raw",     "作者"),
    ("translators_raw", "譯者"),
    ("publisher_page",  "出版社"),
    ("publish_date",    "出版日期"),
    ("page_count",      "頁數"),
    ("dimensions",      "尺寸"),
    ("typeset",         "排版方式"),
    ("language",        "語言"),
    ("binding",         "裝訂方式"),
    ("print_method",    "印刷方式"),
    ("pub_category",    "分類"),
]
_COLONS = "\uff1a:\u2236"  # :、:、∶
_BR = "\x0b"  # <br> 真斷行 sentinel(get_text 的 tag 斷行不可信)
# 详细资料以「標籤字樣+冒號」切割(8/17 probe v3:部分商品整段無 <br>,
# 欄位間只有 inline 標籤邊界,不能依賴斷行)。出版日期須排在出版社之前。
_DETAIL_SPLIT = re.compile(
    r"(作者|譯者|译者|出版日期|出版社|頁數|页数|尺寸|排版方式|語言|语言|"
    r"裝訂方式|装订方式|印刷方式|分類|分类)\s*[" + _COLONS + r"]"
)

# 描述區標題行 → 欄位(先 s2tw 再比對;英文站/禮品只有 Description)
SECTION_HEADS = {
    "內容簡介": "summary", "作者介紹": "author_intro", "目錄": "toc",
    "詳細資料": "detail",
    "Description": "summary", "Product Description": "summary",
}


def parse_detail_panel(text: str) -> dict:
    """详细资料面板文字 → 欄位 dict(值保留原文,轉繁由主流程統一處理)。
    以標籤字樣切割整段文字,不依賴斷行(部分商品欄位間無 <br>)。"""
    label_key = {want: key for key, want in DETAIL_LABELS}
    parts = _DETAIL_SPLIT.split(re.sub(r"\s+", " ", text))
    fields: dict = {}
    for i in range(1, len(parts) - 1, 2):
        lab = s2t(parts[i]) or ""
        val = parts[i + 1].strip()
        # inline 標籤併回造成的空隙:括號內縮、CJK 人名間的點號正規化
        val = re.sub(r"([(\uff08])\s+", r"\1", val)   # 含全形((U+FF08)
        val = re.sub(r"\s+([)\uff09])", r"\1", val)   # 含全形)(U+FF09)
        val = re.sub(r"(?<=[\u4e00-\u9fff])\s*[.·•]\s*(?=[\u4e00-\u9fff])", "·", val)
        key = label_key.get(lab)
        if key and key not in fields and val:
            fields[key] = val
    return fields


def parse_product(pid: str | None, cats: list[dict], href: str | None = None,
                  retried: bool = False) -> dict | None:
    """cats:清單走訪蒐集的分類 [{code, path, promo}](nav 順序、promo 靠後)。"""
    url = product_url(pid) if pid else href
    if not url:
        return None
    try:
        html = fetch(url, force=retried)
    except RuntimeError as e:
        print(f"  [跳過 {pid or href},下次重跑補抓] {e}", flush=True)
        return None
    soup = BeautifulSoup(html, "lxml")

    if pid is None:  # 清單磚無 pid → 從商品頁補
        m = PID_INPUT_RE.search(html)
        pid = (m.group(1) or m.group(2)) if m else None
        if pid is None:
            print(f"  [略過 {href}] 找不到 product_id", flush=True)
            return None
        url = product_url(pid)

    title_el = soup.select_one(".product-infos .title") or soup.select_one("h1")
    name_raw = (title_el.get_text(strip=True) if title_el else None) \
        or _meta(soup, "og:title") or (soup.title.get_text(strip=True) if soup.title else None)
    if not name_raw:
        if not retried:  # 可能快取到空殼頁 → force 重抓一次
            return parse_product(pid, cats, href, retried=True)
        print(f"  [略過 {pid}] 找不到書名", flush=True)
        return None

    rec: dict = {
        "pid": pid,
        "source": "methodist",
        "source_url": url,
        "name_raw": name_raw,
        "is_ebook": False,
        "currency": "MYR",
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }

    # 規格表(Price / Product SKU / Brand / Availability)
    spec: dict[str, str] = {}
    for tr in soup.select("table.product-details tr"):
        cells = [c.get_text(" ", strip=True) for c in tr.find_all(["td", "th"])]
        if len(cells) >= 2 and cells[0]:
            spec.setdefault(cells[0], cells[1])
    sku = spec.get("Product SKU") or ""
    if sku:
        rec["item_no"] = sku[:30]           # → identifiers(STORE)
    if re.fullmatch(r"97[89]\d{10}", sku):
        rec["isbn"] = sku                    # SKU 多數即 ISBN13(禮品為一般條碼)
    publisher = spec.get("Brand") or None
    if spec.get("Availability"):
        rec["availability"] = spec["Availability"]

    # 價格(RM;.price-new 現價、.price-old 原價;鎖定商品資訊區,
    # 避免撈到「相關商品」磚的價格;退規格表 Price 列保底)
    info = soup.select_one(".product-infos") or soup
    new_el = info.select_one(".price-new")
    old_el = info.select_one(".price-old")
    cur = _amount(new_el.get_text() if new_el else None)
    old = _amount(old_el.get_text() if old_el else None)
    if cur is None:
        cur = _amount(spec.get("Price"))
    price_list = old or cur
    if price_list is not None:
        rec["price_list"] = price_list
        try:
            if cur is not None and float(cur) < float(price_list):
                rec["price_sale"] = cur
        except ValueError:
            pass

    # 描述區:raw HTML 為平鋪結構(子頁籤是前端 JS 生成),以標題行切段。
    # <br> 先換成 sentinel 保留「真斷行」;get_text("\n") 的 tag 斷行僅用於
    # 找標題行,各段落再把碎行併回。
    summary = author_intro = toc = None
    detail: dict = {}
    area = soup.select_one("#tab-description")
    if area is not None:
        for br in area.find_all("br"):
            br.replace_with(_BR)
        sections: dict[str, list[str]] = {}
        cur = "summary"
        for line in area.get_text("\n").split("\n"):
            plain = line.replace(_BR, "").strip()
            if not plain:
                continue
            head = SECTION_HEADS.get(s2t(plain) or plain)
            if head is not None:
                cur = head
                continue
            sections.setdefault(cur, []).append(line.strip())

        def _joined(name: str) -> str | None:
            if name not in sections:
                return None
            txt = "\n".join(sections[name]).replace(_BR, "\n")
            txt = "\n".join(l.strip() for l in txt.split("\n"))
            txt = re.sub(r"\n{2,}", "\n", txt).strip()
            return txt or None

        summary = _joined("summary")
        author_intro = _joined("author_intro")
        toc = _joined("toc")
        if "detail" in sections:
            # 整段併回單一字串(斷行不可靠),交由標籤切割
            merged = " ".join(sections["detail"]).replace(_BR, " ")
            detail = parse_detail_panel(merged)
    if summary is None:
        summary = _meta(soup, "og:description")

    # 详细资料欄位入 rec
    authors = detail.get("authors_raw")
    translators = detail.get("translators_raw")
    publisher = publisher or detail.get("publisher_page")
    if detail.get("page_count"):
        m = re.search(r"[\d,]+", detail["page_count"])
        if m:
            rec["page_count"] = m.group(0).replace(",", "")
    if detail.get("publish_date"):  # 20230310 / 2023-03-10 / 2023年3月 → ISO
        m = re.search(r"(\d{4})\D?(\d{1,2})?\D?(\d{1,2})?", detail["publish_date"])
        if m:
            rec["publish_date"] = (m.group(1)
                                   + (f"-{int(m.group(2)):02d}" if m.group(2) else "")
                                   + (f"-{int(m.group(3)):02d}" if m.group(2) and m.group(3) else ""))
    for key in ("dimensions", "binding", "language", "typeset", "print_method",
                "pub_category"):
        if detail.get(key):
            rec[key] = detail[key]
    if not rec.get("language") and spec.get("Language"):
        rec["language"] = spec["Language"]  # 部分商品語言在規格表而非詳細資料

    # 封面:og:image;快取縮圖網址改寫回原圖(原網址保留供保底)
    cover = _meta(soup, "og:image")
    if cover:
        rec["og_image"] = cover
        m = re.match(r"^(.*)/image/cache/(.+)-\d+x\d+(\.[A-Za-z]+)$", cover)
        rec["cover_url"] = f"{m.group(1)}/image/{m.group(2)}{m.group(3)}" if m else cover

    # 分類(清單走訪蒐集;promo 靠後、nav 順序)
    rec["categories"] = [{"code": c["code"], "path": c["path"]} for c in cats]
    if cats:
        rec["category_source"] = cats[0]["code"]
        rec["category_text"] = cats[0]["path"]

    # 簡體 → 繁體(主欄位轉繁入庫;原文存 hans 隨 extra 入庫)
    hans: dict = {}
    for key, val in [("title", name_raw), ("authors_raw", authors),
                     ("translators_raw", translators), ("publisher", publisher),
                     ("summary", summary), ("binding", rec.get("binding")),
                     ("language", rec.get("language")),
                     ("author_intro", author_intro), ("toc", toc)]:
        if val is None:
            continue
        conv = s2t(val)
        if conv != val and key in ("title", "authors_raw", "translators_raw",
                                   "publisher", "summary", "language"):
            hans[key] = val
        rec[key] = conv
    if hans:
        rec["hans"] = hans
    # is_hans 僅在「語言」欄位明說簡體才 True(8/17 probe 決定,異於微讀:
    # 該站介面/書名全為簡體字,hans 不能當版本證據——道聲等台版繁體書的
    # 站上書名也是簡體;未標語言一律視為非簡體,寧漏勿誤標)
    _lang = rec.get("language") or ""
    rec["is_hans"] = "簡體" in _lang

    return rec


# ── 主流程 ───────────────────────────────────────────────────

def collect_memberships(cats: list[dict], force_lists: bool = False,
                        by_date: bool = False, stop_on_seen: set[str] | None = None
                        ) -> dict[str, dict]:
    """走訪所有分類清單 → pid/href → {'cats': [分類...], 'href': 首見連結}。
    stop_on_seen 給每日增量用:分類頁依日期倒序,整頁皆已見即停該分類。"""
    members: dict[str, dict] = {}
    for c in cats:
        if by_date and stop_on_seen is not None:
            # 增量模式:逐頁走,整頁無新品即停(最新在前)
            page = 1
            while True:
                try:
                    html = fetch(list_url(c["url"], page, True), force=True)
                except RuntimeError as e:
                    print(f"  [{c['name']}] 第 {page} 頁抓取失敗:{e}", flush=True)
                    break
                got = extract_tiles(html)
                if not got:
                    break
                new = [(p, h) for p, h in got
                       if (p or h) and p not in stop_on_seen
                       and (p or h) not in members]
                for p, h in got:
                    key = p or h
                    if p in stop_on_seen or key is None:
                        continue
                    d = members.setdefault(key, {"cats": [], "href": h, "pid": p})
                    if c["code"] not in [x["code"] for x in d["cats"]]:
                        d["cats"].append(c)
                if not new:
                    break
                page += 1
        else:
            tiles = walk_category(c["url"], force=force_lists, by_date=by_date)
            if tiles:
                print(f"[清單] {c['path']}:{len(tiles)} 件", flush=True)
            for p, h in tiles:
                key = p or h
                if key is None:
                    continue
                d = members.setdefault(key, {"cats": [], "href": h, "pid": p})
                if c["code"] not in [x["code"] for x in d["cats"]]:
                    d["cats"].append(c)
    # 每件商品的分類排序:非 promo 先、nav 順序
    for d in members.values():
        d["cats"].sort(key=lambda c: (c.get("promo", False), c["order"]))
    return members


def probe():
    print("=== 探測模式 ===", flush=True)
    if _CC is None:
        print("[警告] 未安裝 OpenCC!請先 pip install \"opencc-python-reimplemented>=0.1.7\"", flush=True)
    cats = parse_nav(force=True)
    tops = [c for c in cats if " > " not in c["path"]]
    print(f"[選單] 共 {len(cats)} 個分類(頂層 {len(tops)});前 8:", flush=True)
    for c in cats[:8]:
        print(f"   {c['code']:22s} {'[促]' if c['promo'] else '    '} {c['path']}", flush=True)
    over20 = [c for c in cats if len(c["code"]) >= 20]
    if over20:
        print(f"[提醒] code 截斷至 20 字者 {len(over20)} 個:"
              + "、".join(c["code"] for c in over20[:8]), flush=True)
    dup = len(cats) - len({c["code"] for c in cats})
    print(f"[檢查] code 重複:{dup}(應為 0)", flush=True)

    # 清單 + 排序參數驗證(取聖經分類:已知 19 件、2 頁)
    test = next((c for c in cats if c["code"] == "bible-alkitab"), cats[0])
    p1 = extract_tiles(fetch(list_url(test["url"], 1), force=True))
    p2 = extract_tiles(fetch(list_url(test["url"], 2), force=True))
    d1 = extract_tiles(fetch(list_url(test["url"], 1, by_date=True), force=True))
    print(f"[清單] {test['name']}:第 1 頁 {len(p1)} 件、第 2 頁 {len(p2)} 件;"
          f"日期倒序首件 pid={d1[0][0] if d1 else '?'}(預設排序首件 {p1[0][0] if p1 else '?'};"
          f"兩者{'不同 → 排序參數有效' if d1 and p1 and d1[0][0] != p1[0][0] else '相同,請人工確認排序參數!'})", flush=True)

    # 樣本:書(有详细资料)、無面板商品(禮品)、外文書
    for code, label in [("new-arrivals-2", "書籍"), ("christiangift", "禮品"),
                        ("english-books", "外文書")]:
        c = next((x for x in cats if x["code"] == code), None)
        if c is None:
            print(f"\n--- 樣本({label}):選單找不到分類 {code},略過 ---", flush=True)
            continue
        tiles = extract_tiles(fetch(list_url(c["url"], 1), force=True))
        if not tiles:
            print(f"\n--- 樣本({label}):{c['name']} 清單無商品 ---", flush=True)
            continue
        pid, href = tiles[0]
        rec = parse_product(pid, [c], href=href)
        print(f"\n--- 樣本({label}:{c['name']}) ---", flush=True)
        print(json.dumps(rec, ensure_ascii=False, indent=1)[:2200], flush=True)
    print("\n(請把以上輸出貼回,確認選單/欄位/簡繁/價格無誤後再開全量)", flush=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--force-lists", action="store_true", help="清單頁不走快取")
    args = ap.parse_args()

    if args.probe:
        probe()
        return
    if _CC is None:
        print("[中止] 未安裝 OpenCC,簡體書將原樣入庫而無法與繁體書合併。"
              "請先:pip install \"opencc-python-reimplemented>=0.1.7\"", flush=True)
        return

    DATA.mkdir(exist_ok=True)
    writer = JsonlWriter(DATA / "methodist_books.jsonl", key_field="pid")

    print("== 第一階段:選單 + 全分類清單走訪 ==", flush=True)
    cats = parse_nav(force=args.force_lists)
    print(f"選單分類 {len(cats)} 個", flush=True)
    members = collect_memberships(cats, force_lists=args.force_lists)
    todo = [(k, d) for k, d in members.items()
            if (d["pid"] or "") not in writer.seen]
    print(f"共 {len(members)} 件,待抓 {len(todo)}", flush=True)

    print("== 第二階段:抓商品頁 ==", flush=True)
    done = 0
    for key, d in todo:
        rec = parse_product(d["pid"], d["cats"], href=d["href"])
        if rec and not writer.has(rec["pid"]):
            writer.write(rec)
            done += 1
            if done % 50 == 0:
                print(f"  已入檔 {done}/{len(todo)}", flush=True)
        if args.limit and done >= args.limit:
            print(f"到達 --limit {args.limit},停止", flush=True)
            break
    print(f"完成:本次入檔 {done} 件(檔案累計 {len(writer.seen)})", flush=True)


if __name__ == "__main__":
    main()
