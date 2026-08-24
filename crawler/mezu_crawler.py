# -*- coding: utf-8 -*-
"""真哪噠買書網 mezu.com.tw 爬蟲(單執行緒、節流 1.5-2.5 秒、快取續跑)。

8/22 偵察結論(Chrome live 實測):
 1. 台灣「基督教真哪噠買書(MEZU)網」(浸信會特約書店,承襲恩膏書房),
    **EasyStore** 平台(store-themes.easystore.co / apps.easystore.co,
    封面 CDN cdn.store-assets.com),TWD,繁體介面(少數簡體品項)。
    robots.txt 僅擋 /a/ /account /api /apps/ /cart /checkout /search /tools/,
    商品與分類頁皆允許;無 Crawl-delay。
 2. **無任何 JSON API**:/products.json、/collections.json、?format=json
    全部回 HTML(200 但內容是首頁),/collections/{h}/products.json 回 404。
    → 只能解析 HTML。
 3. **sitemap 是權威全站清單**:/sitemap.xml → sitemap_products.xml(34 個
    分片,合計 10,027 件、無重複)+ sitemap_collections.xml(119 個分類)。
    抽查 95 件分類頁商品 100% 都在 sitemap 內 → 商品清單走 sitemap,
    分類頁只負責蒐集「分類歸屬」(一書多分類)。
    好處:不寫死導覽選單(站方「潮牌禮品」4 個子選單連結已 404)。
 4. 清單分頁固定 **每頁 50 件**,`?page=N`;**limit 參數無效**
    (limit=100/250 仍回 50 件)。
 5. ★陷阱一:**超出末頁不會空、也不會 404,而是回傳末頁內容(HTTP 200)**
    (各款聖經共 5 頁 240 件;page=6/7/20/99 都回第 5 頁那 40 件)。
    → 停止條件:該頁不足 50 件、或該頁 handle 集合與前一頁完全相同。
 6. ★陷阱二:分頁列只是「當前頁 ±5」的視窗,**看不出總頁數**
    (禱告靈修第 1 頁顯示到 6、第 9 頁顯示到 14)→ 不可用分頁列末頁當邊界。
 7. ★陷阱三:**404 頁面仍含 4 個商品連結**(推薦商品)→ 每個網址都要驗
    HTTP 狀態(Cool文具/Buy飾品/Song禮物/Love生活 選單連結皆 404)。
 8. ★陷阱四:商品規格是 **Froala 富文本自由文字**,同一欄位寫法不一:
    「出版社:」/「出 版 商」(字間以 &nbsp; 分隔、無冒號)、「作者:」/
    「作者 」,還有「系列別/語文別/頁數開本/印刷裝訂/EAN/類別」。
    → 8/22 決議:**全量抓原始描述存 extra,欄位解析留待後續版本**。
    本爬蟲只做:(a) 通用「標籤 值」採集 → extra.spec_all(供事後統計標籤
    分布再決定對映);(b) 白名單少數不會誤判的欄位入 rec(ISBN/EAN/出版社/
    作者/譯者/出版日期/頁數/尺寸/重量/系列/語文/裝訂);(c) 簡介原文全存。
 9. 分頁連結原始碼是 `&amp;page=2`(沿宇宙光陷阱二)→ 網址一律自行組裝,
    不從 HTML 解析分頁連結。
10. ★8/23 probe 後補強:站方描述只有約 17% 有 ISBN 標籤,但 **handle 本身就是
    ISBN 的有 1,937 件**(ISBN13 1,491 + ISBN10 446)→ ISBN 來源優先序改為
    「標籤 → 條碼欄(含「電腦條碼」)→ 描述裸掃 → handle」,後兩者**嚴驗檢查碼**
    且裸掃要求整段只有一個 ISBN(叢書列表會列一堆別本書的 ISBN),寧缺勿錯;
    來源記在 `isbn_from` 供事後稽核。handle 像店內貨號者存 `item_no`
    (排除英文書名 slug)。價格原始碼是 "2190.00" → 去小數尾零。
11. 範圍(8/22 決議):**全站 10,027 件抓入存證**,非書(影音/禮品/文具/
    客製化月曆)由 mezu_category_map 的 unpublish 下架(任一命中即下架,
    沿天恩規則,僅 mezu-only 書)。

用法(主機;venv 沿用既有):
  python3 mezu_crawler.py --probe          # 驗證 sitemap+分頁+商品頁解析(先跑,貼回輸出)
  python3 mezu_crawler.py --lists-only     # 只走分類清單(產生歸屬快取)
  nohup python3 mezu_crawler.py > logs/mezu.log 2>&1 &   # 全量(可中斷續跑)
  python3 mezu_crawler.py --limit 30       # 試跑 30 件
"""
from __future__ import annotations

import argparse
import html as html_mod
import json
import re
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import quote, unquote

from common import JsonlWriter, State, make_session, polite_fetch

BASE = "https://www.mezu.com.tw"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "mezu"
DATA = HERE / "data"
THROTTLE = (1.5, 2.5)
PER_PAGE = 50          # 站方固定每頁 50 件(limit 參數無效)
MAX_PAGES = 80         # 單一分類頁數上限(保險絲)

# 每日增量入口(新品上架 = 站方新品清單;注目優惠常一併掛新書)
NEW_ENTRY = ["新品上架", "注目優惠"]

session = make_session()


def fetch(url: str, force: bool = False) -> str | None:
    """抓一頁 HTML;失敗(含 404)回 None 並記錄,不中斷整體流程。"""
    try:
        return polite_fetch(session, url, CACHE, THROTTLE, force=force)
    except RuntimeError as e:
        print(f"  [抓取失敗] {url} → {e}", flush=True)
        return None


# ── 1. sitemap:全站商品清單 + 全部分類 ───────────────────────

_LOC_RE = re.compile(r"<loc>\s*([^<\s]+)\s*</loc>", re.I)


def sitemap_products(force: bool = False) -> list[str]:
    """→ 全站商品 handle 清單(sitemap_products.xml 各分片聯集,保持順序)。"""
    idx = fetch(f"{BASE}/sitemap_products.xml", force=force)
    shards = _LOC_RE.findall(idx or "")
    handles: list[str] = []
    seen: set[str] = set()
    for i, s in enumerate(shards, 1):
        xml = fetch(s, force=force)
        if not xml:
            print(f"  [警告] 商品 sitemap 分片抓不到:{s}", flush=True)
            continue
        for loc in _LOC_RE.findall(xml):
            m = re.search(r"/products/(.+)$", loc)
            if not m:
                continue
            h = unquote(m.group(1)).strip()
            if h and h not in seen:
                seen.add(h)
                handles.append(h)
        if i % 10 == 0:
            print(f"  sitemap 分片 {i}/{len(shards)}:累計 {len(handles)} 件", flush=True)
    print(f"[sitemap] 商品分片 {len(shards)} 個 → {len(handles)} 件", flush=True)
    return handles


def sitemap_collections(force: bool = False) -> list[str]:
    """→ 全部分類 handle(不寫死選單,站方新增分類自動納入)。"""
    xml = fetch(f"{BASE}/sitemap_collections.xml", force=force)
    out: list[str] = []
    for loc in _LOC_RE.findall(xml or ""):
        m = re.search(r"/collections/(.+)$", loc)
        if m:
            h = unquote(m.group(1)).strip()
            if h and h not in out:
                out.append(h)
    print(f"[sitemap] 分類 {len(out)} 個", flush=True)
    return out


# ── 2. 分類角色(8/22 選單快照;只影響 label 好讀與 primary 優先序)──
# 真正的「站內分類對映/下架」在 DB 的 mezu_category_map(可於 Navicat 調);
# 這裡只標:主題(topic,primary 排前)、非書(nonbook)、促銷彙整(promo,排最後),
# 未列入者一律 other(多為出版社/總代理分類,排在 promo 之前)。
MENU_GROUP: dict[str, str] = {}
COLL_ROLE: dict[str, str] = {}
COLL_ORDER: dict[str, int] = {}
_ROLE_BASE = {"topic": 0, "other": 300, "nonbook": 600, "promo": 900}


def _role(handles: list[str], role: str, group: str):
    for h in handles:
        COLL_ROLE[h] = role
        MENU_GROUP[h] = group
        COLL_ORDER[h] = _ROLE_BASE[role] + len(COLL_ORDER)


# 主題分類(站方「商品分類」選單,8/22 快照)
_role(["各款聖經", "和合本聖經", "浸信會聖經", "聖經資源中心聖經",
       "漢語聖經協會聖經", "聖經公會聖經", "環球聖經公會",
       "alabaster美感聖經", "客製化聖經"], "topic", "聖經神學")
_role(["神學工具", "各類教材", "系列叢書"], "topic", "聖經神學")
_role(["禱告靈修", "生命造就", "見證傳記", "福音佈道"], "topic", "信徒靈命")
_role(["婚姻兩性", "童書樂園", "健康生活", "親子教育"], "topic", "家庭生活")
_role(["讀力女性", "銀髮樂活", "詩本其他"], "topic", "特殊主題")
# 非書(影音/禮品/文具/客製化紙品;由對映表 unpublish=1 下架)
_role(["喜樂影音", "哪噠禮品", "潮牌禮品", "客製化月曆",
       "真哪噠客製化月桌曆"], "nonbook", "非書")
# 促銷/功能彙整(不參與分類)
_role(["新品上架", "注目優惠", "feature-on-homepage", "一頁式",
       "全選三件一千元", "2025年9月書訊特惠"], "promo", "促銷彙整")


def coll_role(h: str) -> str:
    return COLL_ROLE.get(h, "other")


def coll_label(h: str) -> str:
    g = MENU_GROUP.get(h)
    return f"{g} > {h}" if g else h


def coll_order(h: str) -> int:
    return COLL_ORDER.get(h, _ROLE_BASE["other"])


# ── 3. 分類清單走訪 ──────────────────────────────────────────

def list_url(handle: str, page: int) -> str:
    u = f"{BASE}/collections/{quote(handle, safe='')}"
    return u if page <= 1 else f"{u}?page={page}"


_PROD_HREF_RE = re.compile(r'href="[^"]*?/products/([^"?#/]+)', re.I)


def page_handles(html: str) -> list[str]:
    """清單頁 HTML → 商品 handle(出現順序、去重)。
    分類頁的商品連結是 /collections/{coll}/products/{handle};404 頁也有 4 個
    推薦商品連結,故呼叫端必須確定 HTTP 200(fetch 已保證,失敗回 None)。"""
    out: list[str] = []
    seen: set[str] = set()
    for m in _PROD_HREF_RE.finditer(html):
        h = unquote(html_mod.unescape(m.group(1))).strip()
        if h and h not in seen:
            seen.add(h)
            out.append(h)
    return out


def walk_collection(handle: str, force: bool = False) -> list[str]:
    """走完一個分類的所有分頁 → handle 清單。

    停止條件(★陷阱一/二):抓不到、0 件、不足 PER_PAGE 件(末頁)、
    或該頁 handle 集合與前一頁完全相同(站方把超出末頁的請求夾回末頁)。
    """
    all_h: list[str] = []
    seen: set[str] = set()
    prev_sig = ""
    page = 1
    while page <= MAX_PAGES:
        html = fetch(list_url(handle, page), force=force)
        if not html:
            break
        hs = page_handles(html)
        sig = "|".join(hs)
        if not hs or sig == prev_sig:
            break
        for h in hs:
            if h not in seen:
                seen.add(h)
                all_h.append(h)
        prev_sig = sig
        if len(hs) < PER_PAGE:
            break
        page += 1
    if page > MAX_PAGES:
        print(f"  [警告] {handle} 達頁數上限 {MAX_PAGES},可能未走完", flush=True)
    return all_h


def collect_memberships(force_lists: bool = False,
                        only: list[str] | None = None) -> dict[str, list[str]]:
    """走訪分類清單 → handle → [分類 handle...]
    (排序:主題 → 出版社/其他 → 非書 → 促銷;primary 取第一個)。"""
    colls = only if only is not None else sitemap_collections(force=force_lists)
    members: dict[str, list[str]] = {}
    for i, c in enumerate(colls, 1):
        hs = walk_collection(c, force=force_lists)
        print(f"[清單 {i}/{len(colls)}] {coll_label(c)}:{len(hs)} 件", flush=True)
        for h in hs:
            members.setdefault(h, [])
            if c not in members[h]:
                members[h].append(c)
    for h in members:
        members[h].sort(key=coll_order)
    return members


# ── 4. 商品頁解析 ────────────────────────────────────────────

_COLONS = "\uff1a:\u2236"   # 全形冒號 U+FF1A、半形、比號(寫死 escape)
_BLOCK_RE = re.compile(r"<br\s*/?>|</p>|</div>|</li>|</h[1-6]>|</tr>|</td>", re.I)


def extract_div(html: str, cls: str) -> str | None:
    """取出 class 含 cls 的第一個 <div> 內容(以 <div>/</div> 計數配對,
    比 regex lookahead 可靠;找不到回 None)。"""
    m = re.search(r'<div[^>]*class="[^"]*\b' + re.escape(cls) + r'\b[^"]*"[^>]*>',
                  html, re.I)
    if not m:
        return None
    start = m.end()
    depth = 1
    for t in re.finditer(r"</?div\b", html[start:], re.I):
        depth += -1 if t.group(0)[1] == "/" else 1
        if depth == 0:
            return html[start:start + t.start()]
    return html[start:]


def strip_html(frag: str | None) -> str:
    """HTML 片段 → 純文字(區塊/斷行標籤換行、實體解碼、&nbsp; 當空白)。"""
    if not frag:
        return ""
    t = re.sub(r"(?is)<(script|style)[^>]*>.*?</\1>", " ", frag)
    t = _BLOCK_RE.sub("\n", t)
    t = re.sub(r"<[^>]+>", "", t)
    t = html_mod.unescape(t).replace("\u00a0", " ").replace("\u3000", " ")
    lines = [re.sub(r"[ \t]{2,}", "  ", ln).strip() for ln in t.split("\n")]
    return re.sub(r"\n{3,}", "\n\n", "\n".join(lines)).strip()


def _first(pattern: str, html: str, flags=re.I | re.S) -> str | None:
    m = re.search(pattern, html, flags)
    return m.group(1) if m else None


# 白名單欄位:標籤字樣(容許字間空白)→ rec 欄位。
SPEC_LABELS: list[tuple[str, tuple[str, ...]]] = [
    ("isbn",            ("ISBN", "國際書號")),
    ("ean",             ("EAN", "Barcode", "條碼", "國際條碼", "電腦條碼", "商品條碼")),
    ("publisher",       ("出版社", "出版商", "出版者", "出版單位")),
    ("authors_raw",     ("作者", "著者")),
    ("translators_raw", ("譯者", "翻譯者")),
    ("publish_date",    ("出版日期", "出版年月", "初版日期", "出版時間")),
    ("page_count",      ("頁數",)),
    ("dimensions",      ("尺寸", "開本", "開數")),
    ("weight",          ("重量",)),
    ("series_text",     ("系列別", "系列", "叢書系列")),
    ("language",        ("語文別", "語言", "語文")),
    ("binding",         ("裝訂", "裝訂方式")),
]
# 這些標籤後面若緊接「簡介/介紹」等字,是內文標題不是欄位 → 不可當值
_NOT_FIELD_TAIL = r"(?!\s*(?:簡介|介紹|的話|群|序))"

# 通用「標籤 值」行:標籤 ≤10 字(容許字間空白;英文標籤如 Barcode 有 7 字),
# 分隔為冒號或 2 個以上空白。★量詞必須惰性 {0,11}?:標籤字元集含空白,貪婪
# 會吃過第一個分隔點(「頁數開本  尺寸:150*…」會誤取成鍵「頁數開本尺寸」)。
_GENERIC_LINE = re.compile(
    r"^([\u4e00-\u9fffA-Za-z][\u4e00-\u9fffA-Za-z0-9 ]{0,11}?)"
    r"(?:\s*[" + _COLONS + r"]\s*|\s{2,})(.+)$")
# 簡介起始標記(站方多用 ■/【】 標題;可與正文同一行)
_SUMMARY_MARK = re.compile(
    r"^[\s■◆●▍▎★☆\[【（(]*"
    r"(?:本書簡介|內容簡介|書籍簡介|商品簡介|產品資訊|商品描述|內容介紹|關於本書|簡介)"
    r"[\s】\]）)]*[" + _COLONS + r"]?\s*(.*)$")


def harvest_specs(text: str) -> tuple[dict[str, str], str | None]:
    """描述純文字 → (所有「標籤→值」對, 簡介正文)。

    通用採集(不預設站方欄位表):任何「標籤:值」或「標籤␣␣值」的行都收,
    標籤去空白後當鍵,同鍵只取第一次(站方常在文末重複)。
    遇到簡介標記後的內容一律當正文,不再採欄位。
    """
    specs: dict[str, str] = {}
    body: list[str] = []
    pre_body: list[str] = []
    marked = False
    for raw in text.split("\n"):
        ln = raw.strip()
        if marked:
            body.append(ln)
            continue
        if not ln:
            pre_body.append("")
            continue
        m = _SUMMARY_MARK.match(ln)
        if m:
            marked = True
            if m.group(1).strip():
                body.append(m.group(1).strip())
            continue
        g = _GENERIC_LINE.match(ln)
        if g:
            key = re.sub(r"\s+", "", g.group(1))
            val = g.group(2).strip(" " + _COLONS)
            # 目錄行(第十二課/第8日/第三章…)與單字鍵不是欄位,別污染 spec_all
            junk = (len(key) < 2
                    or re.fullmatch(r"第[0-9零一二三四五六七八九十廿百]{1,4}[課日章講週天篇]?", key))
            if key and val and len(key) <= 10 and key not in specs and not junk:
                specs[key] = val[:300]
                continue
        pre_body.append(ln)
    lines = body if marked else pre_body
    txt = re.sub(r"\n{3,}", "\n\n", "\n".join(lines)).strip()
    return specs, (txt or None)


_COMBO_KEYS = ("頁數開本", "開本頁數", "規格", "書籍規格")


def pick_combo(specs: dict[str, str]) -> dict[str, str]:
    """站方常把多欄擠在一格(「頁數開本  尺寸:150*210 mm 頁數:204頁」)→
    從複合值裡把尺寸/頁數/重量再撈出來(只補,不覆蓋既有欄位)。"""
    out: dict[str, str] = {}
    for k in _COMBO_KEYS:
        v = specs.get(k)
        if not v:
            continue
        for key, lab in (("dimensions", "尺寸"), ("page_count", "頁數"), ("weight", "重量")):
            m = re.search(lab + r"\s*[" + _COLONS + r"]?\s*([^\s" + _COLONS + r"]{1,40})", v)
            if m and key not in out:
                out[key] = m.group(1)
        if "dimensions" not in out:      # 「384頁／14.8*21CM」這種沒標籤的
            m = re.search(r"(\d+(?:\.\d+)?\s*[*xX×]\s*\d+(?:\.\d+)?\s*(?:[*xX×]\s*\d+(?:\.\d+)?\s*)?(?:cm|CM|mm|MM)?)", v)
            if m:
                out["dimensions"] = m.group(1).strip()
        if "page_count" not in out:
            m = re.search(r"(\d[\d,]*)\s*頁", v)
            if m:
                out["page_count"] = m.group(1)
    return out


def pick_specs(text: str, specs: dict[str, str]) -> dict[str, str]:
    """白名單欄位:先查通用採集結果,再退回「行首標籤」比對
    (標籤字間可有空白;站方有「出 版 商␣␣宣道傳意」這種寫法)。"""
    out: dict[str, str] = {}
    for key, labels in SPEC_LABELS:
        for lab in labels:
            nospace = re.sub(r"\s+", "", lab)
            if specs.get(nospace):
                out[key] = specs[nospace]
                break
        if key in out:
            continue
        for lab in labels:
            pat = (r"(?m)^\s*" + r"\s*".join(re.escape(c) for c in lab)
                   + _NOT_FIELD_TAIL + r"\s*[" + _COLONS + r"]?[ \t]*([^\n]{1,120})$")
            m = re.search(pat, text)
            if m:
                v = m.group(1).strip(" " + _COLONS)
                if v:
                    out[key] = v[:300]
                    break
    return out


_ISBN_RE = re.compile(r"97[89][-\s]?(?:\d[-\s]?){9}\d")


def isbn13_valid(d: str) -> bool:
    """13 碼 ISBN 檢查碼(mod 10)。"""
    if len(d) != 13 or not d.isdigit():
        return False
    tot = sum(int(c) * (3 if i % 2 else 1) for i, c in enumerate(d[:12]))
    return (10 - tot % 10) % 10 == int(d[12])


def isbn10_valid(d: str) -> bool:
    """10 碼 ISBN 檢查碼(mod 11,末碼可為 X)。"""
    if len(d) != 10 or not d[:9].isdigit() or d[9] not in "0123456789Xx":
        return False
    tot = sum((10 - i) * int(c) for i, c in enumerate(d[:9]))
    tot += 10 if d[9] in "Xx" else int(d[9])
    return tot % 11 == 0


def norm_isbn(s: str | None) -> str | None:
    """→ 13 碼 ISBN(只認 978/979 開頭;期刊 ISSN 條碼 977… 不會命中)。
    有標籤的欄位值不驗檢查碼(沿他站作法,站方打錯也照存);
    「猜」來的來源(裸掃描/handle)另由 infer_isbn 嚴格驗檢查碼。"""
    if not s:
        return None
    m = _ISBN_RE.search(s)
    if not m:
        return None
    d = re.sub(r"[^0-9]", "", m.group(0))
    return d if len(d) == 13 else None


def infer_isbn(text: str) -> str | None:
    """描述內文沒有 ISBN 標籤時的兜底:整段掃裸 ISBN13。
    **必須恰好只有一個**(叢書列表會列一堆別本書的 ISBN)且**檢查碼正確**,
    否則寧缺勿錯——ISBN 錯了會把兩本不同的書合併成一本。"""
    found = {re.sub(r"[^0-9]", "", m.group(0)) for m in _ISBN_RE.finditer(text)}
    ok = [d for d in found if isbn13_valid(d)]
    return ok[0] if len(ok) == 1 else None


def isbn_from_handle(handle: str) -> str | None:
    """★8/23 發現:站方 1,937 件商品的 handle 本身就是 ISBN
    (ISBN13 1,491 件、ISBN10 446 件)→ 免費的 ISBN 來源。
    嚴格驗檢查碼,避免把 10 碼店內貨號誤認成 ISBN10。"""
    h = handle.strip()
    if re.fullmatch(r"97[89]\d{10}", h) and isbn13_valid(h):
        return h
    if re.fullmatch(r"\d{9}[\dXx]", h) and isbn10_valid(h):
        return h.upper()          # 交 import.php 換算為 ISBN13(它認 10 碼)
    return None


def looks_store_code(handle: str) -> bool:
    """handle 是否像店內貨號(→ item_no):需含數字、長度 ≤30,
    且不是英文書名 slug(以 - . _ 切開後,長度 ≥3 的純字母段有 2 個以上就是 slug,
    例:alabaster-book-of-acts-nlt--1)。"""
    h = handle.strip()
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{1,29}", h):
        return False
    if not re.search(r"\d", h):
        return False
    alpha = [t for t in re.split(r"[-._]+", h) if re.fullmatch(r"[A-Za-z]{3,}", t)]
    return len(alpha) < 2


def clean_person(s: str | None) -> str | None:
    if not s:
        return None
    s = re.sub(r"^\s*(作者|著者|譯者|翻譯者)\s*[" + _COLONS + r"]?\s*", "", s.strip())
    s = re.sub(r"\s{2,}", " ", s)
    return s or None


def norm_date(s: str | None) -> str | None:
    """2024.10.04 / 2025-3-1 / 2020年11月 / 20240715 → YYYY[-MM[-DD]]。"""
    if not s:
        return None
    d = re.sub(r"\D", "", s)
    if len(d) >= 8 and 1900 <= int(d[:4]) <= 2100 and 1 <= int(d[4:6]) <= 12:
        return f"{d[:4]}-{d[4:6]}-{d[6:8]}"
    m = re.search(r"((?:19|20)\d{2})\D{0,2}(\d{1,2})?", s)
    if not m:
        return None
    y, mo = m.group(1), m.group(2)
    return f"{y}-{int(mo):02d}" if mo and 1 <= int(mo) <= 12 else y


def product_url(handle: str) -> str:
    return f"{BASE}/products/{quote(handle, safe='')}"


_PRICE_RE = re.compile(r"[\d,]+(?:\.\d+)?")


def _price(frag: str | None) -> str | None:
    if not frag:
        return None
    m = _PRICE_RE.search(strip_html(frag))
    if not m:
        return None
    v = m.group(0).replace(",", "")
    if "." in v:                       # 站方原始碼是 "2190.00" → 去小數尾零
        v = v.rstrip("0").rstrip(".")
    return v or None


def parse_product(handle: str, colls: list[str], retried: bool = False) -> dict | None:
    url = product_url(handle)
    html = fetch(url, force=retried)
    if not html:
        return None

    # ★結構檢查(沿宇宙光陷阱四):失效 handle 可能被導回首頁並回 200,
    # 首頁也有 og:title/og:image → 沒有商品區塊就不當商品頁。
    if "product-single" not in html or not re.search(r"<h1[^>]*>", html, re.I):
        if not retried:
            return parse_product(handle, colls, retried=True)
        print(f"  [略過 {handle}] 非商品頁(可能已下架/導回首頁)", flush=True)
        return None

    # 商品主區(排除頁尾「我猜你喜歡」推薦區,避免抓到別本書的價格/圖)
    hero = extract_div(html, "product-single__hero") or extract_div(html, "product-single") or html
    hero = re.split(r"我猜你喜歡|相關商品|你可能也喜歡", hero)[0]

    title = re.sub(r"\s+", " ", strip_html(_first(r"<h1[^>]*>(.*?)</h1>", hero) or
                                          _first(r"<h1[^>]*>(.*?)</h1>", html))).strip()
    if not title:
        print(f"  [略過 {handle}] 無書名", flush=True)
        return None

    rec: dict = {
        "pid": handle,
        "source": "mezu",
        "source_url": url,
        "title": title,
        "currency": "TWD",
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }

    # 價格:product-single__price=現價、product-single__sale-price=原價(定價)
    # \b 很重要:容器 class 是 product-single__prices,不加 \b 會先命中容器。
    now_p = _price(_first(r'class="[^"]*\bproduct-single__price\b[^"]*"[^>]*>(.*?)</', hero))
    list_p = _price(_first(r'class="[^"]*\bproduct-single__sale-price\b[^"]*"[^>]*>(.*?)</', hero))
    if list_p and now_p and list_p != now_p:
        rec["price_list"] = list_p      # 定價(站方以刪除線顯示)
        rec["price_sale"] = now_p       # 售價
    elif now_p:
        rec["price_list"] = now_p

    if re.search(r"售完|補貨中|缺貨|sold\s*out", hero, re.I):
        rec["availability"] = "售完/補貨中"

    # 封面:og:image(cdn.store-assets.com);主區其餘圖存 photos
    og_img = (_first(r'<meta[^>]+property="og:image"[^>]+content="([^"]+)"', html)
              or _first(r'<meta[^>]+content="([^"]+)"[^>]+property="og:image"', html))
    if og_img:
        rec["cover_url"] = html_mod.unescape(og_img)
    photos: list[str] = []
    for m in re.finditer(r"(https://cdn\.store-assets\.com/[^\"'\s)]+)", hero):
        u = html_mod.unescape(m.group(1))
        if u != rec.get("cover_url") and u not in photos and not u.endswith((".css", ".js")):
            photos.append(u)
    if photos:
        rec["photos"] = photos[:8]

    # 描述區(Froala 富文本):原文全存,只解析白名單欄位(8/22 決議)
    desc_text = strip_html(extract_div(html, "product-description"))
    if desc_text:
        rec["desc_raw"] = desc_text[:20000]          # 原文整段(入 extra)
        specs, body = harvest_specs(desc_text)
        if specs:
            rec["spec_all"] = specs                  # 通用標籤採集(入 extra)
        picked = pick_specs(desc_text, specs)
        for k, v in pick_combo(specs).items():   # 複合值補欄(不覆蓋)
            picked.setdefault(k, v)
        # ISBN 來源優先序:標籤 → 條碼欄 → 描述裸掃(嚴驗) → handle(嚴驗)
        isbn = norm_isbn(picked.get("isbn"))
        src = "標籤" if isbn else None
        if not isbn:
            isbn = norm_isbn(picked.get("ean"))
            src = "條碼欄" if isbn else None
        if not isbn:
            isbn = infer_isbn(desc_text)
            src = "描述內文" if isbn else None
        if isbn:
            rec["isbn"] = isbn
            rec["isbn_from"] = src
        for key in ("publisher", "series_text", "language", "binding", "dimensions"):
            if picked.get(key):
                rec[key] = re.sub(r"\s{2,}", " ", picked[key])[:200]
        for key in ("authors_raw", "translators_raw"):
            v = clean_person(picked.get(key))
            if v:
                rec[key] = v[:200]
        d = norm_date(picked.get("publish_date"))
        if d:
            rec["publish_date"] = d
        if picked.get("page_count"):
            m = re.search(r"\d[\d,]*", picked["page_count"])
            if m:
                rec["page_count"] = m.group(0).replace(",", "")
        if picked.get("weight"):
            rec["weight"] = picked["weight"]
        rec["summary"] = (body or desc_text)[:8000]
        rec["summary_from"] = "簡介標記" if body else "整段描述"

    # ISBN 最後兜底:handle 本身就是 ISBN(站方 1,937 件如此)
    if not rec.get("isbn"):
        h_isbn = isbn_from_handle(handle)
        if h_isbn:
            rec["isbn"] = h_isbn
            rec["isbn_from"] = "handle"
    # 店內貨號:handle 像貨號就存(→ identifiers STORE);是 ISBN 的不重複存
    if not isbn_from_handle(handle) and looks_store_code(handle):
        rec["item_no"] = handle[:30]

    # 分類歸屬(清單走訪蒐集;subjects.code 上限 20 字)
    cats = []
    for c in colls:
        if len(c) > 20:
            print(f"  [注意] 分類 handle 超過 20 字已截斷:{c}", flush=True)
        cats.append({"code": c[:20], "path": coll_label(c)})
    if cats:
        rec["categories"] = cats
        rec["category_source"] = cats[0]["code"]
        rec["category_text"] = cats[0]["path"]

    return rec


# ── 5. 主流程 ───────────────────────────────────────────────

def probe():
    print("=== 探測模式(真哪噠 mezu.com.tw)===", flush=True)
    prods = sitemap_products(force=True)
    colls = sitemap_collections(force=True)
    print(f"[範圍] 全站商品 {len(prods)} 件、分類 {len(colls)} 個", flush=True)
    long_h = [c for c in colls if len(c) > 20]
    print(f"[檢查] 分類 handle 超過 20 字者:{long_h or '無'}", flush=True)
    unknown = [c for c in colls if coll_role(c) == "other"]
    print(f"[檢查] 未列入 8/22 選單快照的分類 {len(unknown)} 個(視為出版社/其他):"
          f"{'、'.join(unknown[:12])}{'…' if len(unknown) > 12 else ''}", flush=True)

    # 分頁陷阱驗證:各款聖經 應為 5 頁 240 件,page=99 會夾回末頁
    print("\n--- 分頁驗證(各款聖經)---", flush=True)
    hs = walk_collection("各款聖經", force=True)
    print(f"走完共 {len(hs)} 件(8/22 實測 240;首件={hs[0] if hs else '?'})", flush=True)
    p99 = fetch(list_url("各款聖經", 99), force=True)
    n99 = len(page_handles(p99)) if p99 else 0
    print(f"page=99 回 {n99} 件"
          f"({'已被夾回末頁 → 停止條件正確' if 0 < n99 < PER_PAGE else '請人工確認!'})",
          flush=True)
    pset = set(prods)
    miss = [h for h in hs if h not in pset]
    print(f"[對帳] 各款聖經 有 {len(miss)} 件不在 sitemap 內"
          f"({'sitemap 覆蓋完整' if not miss else '需改走清單聯集!'})", flush=True)

    # 商品頁樣本
    samples = [
        ("19克超薄和合本皮面聖經-藍色-新標點浸神版-", "聖經(標籤帶冒號)"),
        ("傳道宣教的人生", "代理書(「出 版 商」字間空格)"),
        ("不平凡的抉擇-改變了平凡人物-馨香講道集-二-", "本版書(系列別/語文別)"),
    ]
    if hs:
        samples.append((hs[0], "各款聖經首件"))
    for handle, label in samples:
        rec = parse_product(handle, ["各款聖經"])
        print(f"\n--- 樣本({label})---", flush=True)
        if not rec:
            print("  解析失敗", flush=True)
            continue
        slim = {k: v for k, v in rec.items() if k not in ("photos", "desc_raw", "summary")}
        slim["summary"] = (rec.get("summary") or "")[:120]
        slim["desc_raw_len"] = len(rec.get("desc_raw") or "")
        slim["photos_n"] = len(rec.get("photos") or [])
        print(json.dumps(slim, ensure_ascii=False, indent=1)[:2600], flush=True)

    # 欄位命中率(抽樣 40 件,散佈全站)
    print("\n--- 欄位命中率(抽樣 40 件)---", flush=True)
    step = max(1, len(prods) // 40)
    hit: dict[str, int] = {}
    labels: dict[str, int] = {}
    n = 0
    for h in prods[::step][:40]:
        rec = parse_product(h, [])
        if not rec:
            continue
        n += 1
        for k in ("isbn", "authors_raw", "publisher", "publish_date", "page_count",
                  "cover_url", "price_list", "summary", "spec_all", "item_no",
                  "dimensions"):
            if rec.get(k):
                hit[k] = hit.get(k, 0) + 1
        if rec.get("isbn_from"):
            hit["isbn←" + rec["isbn_from"]] = hit.get("isbn←" + rec["isbn_from"], 0) + 1
        for lab in (rec.get("spec_all") or {}):
            labels[lab] = labels.get(lab, 0) + 1
    print(f"樣本 {n} 件:", flush=True)
    for k, v in sorted(hit.items(), key=lambda x: -x[1]):
        print(f"  {k:<14} {v}/{n}  ({100 * v // max(n, 1)}%)", flush=True)
    print("  描述區出現的標籤(次數):", flush=True)
    for lab, c in sorted(labels.items(), key=lambda x: -x[1])[:30]:
        print(f"    {lab} × {c}", flush=True)
    print("\n(請把以上輸出貼回,確認欄位/ISBN/價格/封面/分頁對帳無誤後再開全量)",
          flush=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true")
    ap.add_argument("--lists-only", action="store_true", help="只走分類清單(存歸屬快取)")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--force-lists", action="store_true", help="清單頁不走快取")
    args = ap.parse_args()

    if args.probe:
        probe()
        return

    DATA.mkdir(exist_ok=True)
    memb_path = DATA / "mezu_memberships.json"
    state = State(DATA / "mezu_state.json")

    print("== 第一階段:全分類清單走訪(蒐集分類歸屬)==", flush=True)
    if memb_path.exists() and not args.force_lists:
        members = json.loads(memb_path.read_text(encoding="utf-8"))
        print(f"沿用既有歸屬快取:{len(members)} 件(要重走請加 --force-lists)", flush=True)
    else:
        members = collect_memberships(force_lists=args.force_lists)
        memb_path.write_text(json.dumps(members, ensure_ascii=False), encoding="utf-8")
        print(f"歸屬已存 {memb_path.name}:{len(members)} 件", flush=True)
    state.data["memberships"] = len(members)
    state.save()
    if args.lists_only:
        return

    print("== 第二階段:sitemap 全站商品清單 ==", flush=True)
    handles = sitemap_products()
    hset = set(handles)
    extra = [h for h in members if h not in hset]
    if extra:
        print(f"清單有、sitemap 沒有的商品 {len(extra)} 件 → 一併納入", flush=True)
        handles += extra
    writer = JsonlWriter(DATA / "mezu_books.jsonl", key_field="pid")
    todo = [h for h in handles if not writer.has(h)]
    print(f"全站 {len(handles)} 件,待抓 {len(todo)}", flush=True)

    print("== 第三階段:抓商品頁 ==", flush=True)
    done = skipped = 0
    for h in todo:
        rec = parse_product(h, members.get(h, []))
        if rec is None:
            skipped += 1
        elif writer.write(rec):
            done += 1
            if done % 100 == 0:
                print(f"  已入檔 {done}/{len(todo)}(略過 {skipped})", flush=True)
        if args.limit and done >= args.limit:
            print(f"到達 --limit {args.limit},停止", flush=True)
            break
    writer.close()
    no_cat = sum(1 for h in handles if not members.get(h))
    print(f"完成:本次入檔 {done} 件、略過 {skipped} 件(檔案累計 {len(writer.seen)})", flush=True)
    print(f"提醒:全站有 {no_cat} 件不屬於任何分類(無來源分類存證,"
          f"分類將交 classify 關鍵字回填)", flush=True)


if __name__ == "__main__":
    main()
