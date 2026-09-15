# -*- coding: utf-8 -*-
"""麥種傳道會(akow.org)爬蟲——第十七個書目來源(v1.13.0)。

2026-09-13 偵察 + 主機 probe 實測事實(不是推測):
- **WordPress 6.9.7 + WooCommerce**。票上原寫「網路訂書是訂購頁不是購物車 →
  只收書目、不掛購書連結」是**錯的**:那就是 WooCommerce shop 頁(Add to cart),
  USD 結帳 → **akow 要掛購書連結**。
- 票上寫的「JS 重定向牆 429」**不存在**:主機 curl Store API 回 **200**,純 HTTP 可抓。
- **Store API**:/wp-json/wc/store/v1/products?per_page=100&page=N,x-wp-total = 144。
  超界 page=9999 回**空陣列**(不是夾回首頁),所以「空或不足 per_page 即停」是安全的。
- **權威清單三方一致且零差集**:sitemap 144 = Store API 144 = shop 頁 144。
- **幣別 USD**(currency_minor_unit = 2 → API 的 "7900" 是 $79.00)。
  ★ 必須在 tools/import.php 的 SOURCE_CURRENCY 登錄 akow → USD,漏登會靜默落回 TWD。
- 欄位全在簡介自由文字裡(商品頁 Additional information 只有重量尺寸)。
  144 本全量覆蓋率:封面 100%、簡介 100%、定價 99%、作者/譯者各 46%、
  書系 43%、頁數 34%、出版日期 33%、ISBN(修正後)約 45 本、店內碼 1 本。

熊哥決議(2026-09-13):
1. 抓取範圍:**.org 為主**;台幣定價與台灣購書連結日後由 akow.tw(138 本)以書名比對補上。
2. 欄位解析深度:**正則抽關鍵欄位 + 全文存 extra**,抽不到留空不猜。
3. 來源分類依據:**主題對 CategoryV11、書系進 series 表**。

★★★ 本站五個陷阱(全部由 9/13 probe 實測發現,已寫進本檔)★★★

1. **原書號是「ISBN-13 去掉 978」,不是 ISBN-10**(天道同款陷阱)
   站方印「原書號:1-939251-01-5」,以 ISBN-10 驗檢查碼**算不過**;
   但補回 978 之後 `9781939251015` 檢查碼**剛好吻合** → 可確定性還原,不必重算。
   實測 17 本走這條路。處理順序:13 碼直用 → 合法 ISBN-10 轉 13(重算檢查碼)
   → 否則補 978 驗 13 碼;三條都不過才留 isbn_raw。

2. **站方 ISBN 本身就重複**(不是解析錯,是站方抄錯)
   `9781951456115` 同時在《真正的快樂》與《現代神學精髓》;
   `9781939251176` 同時在《以西結書註釋(上下)》與《主耶穌的畫像》;
   `9781939251015` 同時在《舊約歷史書手冊》與《主耶穌的比喻》。
   加上簡繁同號(`9781939251367` = 麥種基督教要義 繁/简),
   **akow 的 ISBN 不具唯一性 → import 絕不可單以 ISBN 合併**。
   爬蟲照實記,並輸出 script(hant/hans);--probe 會列出所有同號組。

3. **出版日期混了民國年**:`98040101`→民國 98 年 4 月 1 日(2009-04-01)、
   `96100101`→2007-10-01、`96010101`→2007-01-01(尾碼多打兩位)。
   另有 `20250431`(四月無 31 日)、`202201031`(九位)、`：：20230810`(雙冒號)。
   西元解不出才試民國,且一律標記 publish_date_from 供人複核。

4. **頁數多值**:`864+696`、`上774、下1724`、`上982 下858`(上下冊)。
   依多值鐵律**不拆不加總**,非純數字不寫 page_count,原值留 page_count_raw。
   (`192頁` 只是帶單位,去單位後可入 page_count。)

5. **簡介裡有比 product_cat 細的站方分類**:38 本有「分類:聖經論叢／詩篇」
   「分類:神學類／救恩論」這種兩層分類,遠比 15 個 product_cat 精確 →
   收進 subject_raw,供日後對映表使用(★ 不要只看 product_cat 就做對映)。

用法:
    python3 akow_crawler.py --probe          # 分類/分頁邊界/抽樣欄位/五陷阱全量檢查
    python3 akow_crawler.py                  # 全量(144 本)
    python3 akow_crawler.py --limit 20
    python3 akow_crawler.py --emit-map-sql   # 分類對映表 SQL
"""
from __future__ import annotations

import argparse
import html as _html
import json
import re
from datetime import datetime, timezone
from pathlib import Path

from common import JsonlWriter, make_session, polite_fetch

BASE = "https://akow.org"
API = BASE + "/wp-json/wc/store/v1"
SITEMAP = BASE + "/wp-sitemap-posts-product-1.xml"
SOURCE = "akow"
PUBLISHER = "麥種傳道會"
CURRENCY = "USD"

HERE = Path(__file__).parent
CACHE = HERE / "cache" / "akow"
DATA = HERE / "data"
THROTTLE = (2.0, 3.0)
PER_PAGE = 100
PAGE_CAP = 20           # 100×20 = 2,000,遠大於全站 144

# 決議 3:書系不是主題,不進 subjects,改寫 series 欄
SERIES_CATS = {37: "光照系列", 41: "成長系列", 42: "焦點系列",
               43: "種子系列", 76: "根基系列"}
NON_SUBJECT_CATS = set(SERIES_CATS) | {100}     # 100 = Uncategorized
# 簡介裡的站方細分類(陷阱 5)以此前綴寫成獨立 subjects 列,與 product_cat 的數字碼區分
SUB_PREFIX = "sub:"

session = make_session()


def fetch_json(url: str, force: bool = False):
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
    for page in range(1, 6):
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
    parts, seen = [], set()
    while cid and cid in cats and cid not in seen:
        seen.add(cid)
        parts.insert(0, cats[cid]["name"])
        cid = cats[cid]["parent"]
    return " > ".join(parts)


# ── 2. 文字工具 ─────────────────────────────────────────────
# ★ 冒號字元類別一律用 unicode escape 寫,不要打字面全形冒號:
#   7/12 曾發生 [:：] 在生成時退化成兩個半形冒號 [::],全形冒號整組失效。
_COLONS = "\uff1a:\u2236"
_LABEL_RE = re.compile(r"([^\s" + _COLONS + r"]{1,8})\s*[" + _COLONS + r"]+[ \t]*([^\n]*)")
# 10 碼分支也要容忍連字號/空白(站方寫 1-939251-01-5)
_ISBN_RE = re.compile(r"(?<!\d)(97[89][-\s]?(?:\d[-\s]?){9}\d|\d(?:[-\s]?\d){8}[-\s]?[\dXx])(?!\d)")
_TAG_RE = re.compile(r"<[^>]+>")
_PLACEHOLDER = {"不提供", "無", "無資料", "未提供", "N/A", "n/a", "-", "—", "", "0"}
_DAYS = (31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31)
# 簡體字偵測用的常見簡化字(只做標記,不做任何字形轉換)
# ★ 繁體來源不可用 s2tw(9/1 教訓):這裡只標記 script,絕不轉換文本。
_HANS_HINT = re.compile(r"[简书讲学总经义证灵长门爱贵传圣与体录变]")
# spec_raw 白名單:站方簡介夾雜大量目錄行(「壹、背景:以西結所處的世界」),
# 那些冒號會被誤當欄位標籤。只留真正的規格鍵,目錄行本來就在 summary 裡。
_SPEC_KEYS = {
    "作者", "著者", "編著", "编著", "編者", "编者", "譯者", "译者", "翻譯", "翻译",
    "繪圖", "绘图", "繪者", "绘者", "插畫", "插画", "總校訂", "總教訂", "总校订", "校訂", "校订",
    "書系", "书系", "系列", "叢書", "丛书", "原書號", "原书号", "ISBN", "isbn", "國際書號",
    "出版日期", "出版日", "出版年月", "出版時間", "出版时间", "初版", "出版社", "出版者",
    "頁數", "页数", "尺寸", "開本", "开本", "規格", "规格", "重量", "裝訂方式", "装订方式",
    "裝訂", "装订", "排版方式", "印刷方式", "語言", "语言", "定價", "定价", "分類", "分类",
}


def strip_html(s: str | None) -> str:
    if not s:
        return ""
    s = re.sub(r"<br\s*/?>", "\n", s, flags=re.I)
    s = re.sub(r"</(p|div|li|h[1-6])>", "\n", s, flags=re.I)
    s = _TAG_RE.sub(" ", s)
    s = _html.unescape(s)
    s = re.sub(r"[\u00a0\u3000]", " ", s)
    return re.sub(r"[ \t]{2,}", " ", s).strip()


def _ck13(d12: str) -> str:
    s = sum((1 if i % 2 == 0 else 3) * int(c) for i, c in enumerate(d12))
    return str((10 - s % 10) % 10)


def valid13(d: str) -> bool:
    return len(d) == 13 and d.isdigit() and _ck13(d[:12]) == d[12]


def valid10(d: str) -> bool:
    if len(d) != 10:
        return False
    try:
        return sum((10 - i) * (10 if c in "Xx" else int(c))
                   for i, c in enumerate(d)) % 11 == 0
    except ValueError:
        return False


def isbn13_from(raw: str | None) -> tuple[str | None, str | None]:
    """回傳 (ISBN13, 來源說明)。★ 陷阱 1:
    站方的「原書號」是 ISBN-13 去掉 978 前綴(檢查碼仍是 13 碼版),
    所以 ISBN-10 驗不過時,補 978 再驗 13 碼——吻合就是確定性還原,不是猜。"""
    if not raw:
        return None, None
    m = _ISBN_RE.search(raw)
    if not m:
        return None, None
    d = re.sub(r"[^0-9Xx]", "", m.group(1)).upper()
    if valid13(d):
        return d, "十三碼"
    if valid10(d):
        core = "978" + d[:9]
        return core + _ck13(core), "ISBN10 轉 13(重算檢查碼)"
    if len(d) == 10 and d.isdigit() and valid13("978" + d):
        return "978" + d, "原書號補 978 還原"
    return None, None


def parse_pubdate(raw: str) -> tuple[str | None, str | None]:
    """回傳 (日期, 說明)。★ 陷阱 3:
    西元 YYYYMMDD 為主;日不合法退到 YYYY-MM;西元解不出才試民國年。
    一律回說明字串,讓 audit 可以列出來給人複核——不要靜默推定。"""
    d = re.sub(r"\D", "", raw or "")
    if len(d) < 6:
        return None, None

    def pack(y: int, mo: int, day: int | None, how: str):
        ym = f"{y:04d}-{mo:02d}"
        if day and 1 <= day <= _DAYS[mo - 1]:
            return f"{ym}-{day:02d}", how
        return ym, how + "(日不合法,只到月)" if day else (ym, how)

    y, mo = int(d[:4]), int(d[4:6])
    if 1900 <= y <= 2100 and 1 <= mo <= 12:
        day = int(d[6:8]) if len(d) >= 8 else None
        if len(d) != 8:
            return f"{y:04d}-{mo:02d}", f"西元(原值 {len(d)} 位,只取到月)"
        return pack(y, mo, day, "西元")

    for n in (2, 3):                       # 民國年可能 2 或 3 位
        if len(d) >= n + 4:
            roc, mo2 = int(d[:n]), int(d[n:n + 2])
            day2 = int(d[n + 2:n + 4])
            if 1 <= roc <= 140 and 1 <= mo2 <= 12 and 1 <= day2 <= _DAYS[mo2 - 1]:
                got, _ = pack(roc + 1911, mo2, day2, "民國年推定")
                return got, f"民國年推定(原值 {d})"
    return None, None


def guess_script(title: str, text: str) -> str:
    if re.search(r"[（(]\s*[简簡]\s*[體体]?\s*[)）]|[简簡][體体]版?", title):
        return "hans" if "简" in title or "体" in title else "hant"
    if _HANS_HINT.search(title):
        return "hans"
    return "hans" if len(_HANS_HINT.findall(text[:400])) >= 3 else "hant"


def norm_lang(raw: str | None) -> str | None:
    if not raw:
        return None
    if re.search(r"繁體|正體|繁体", raw):
        return "zh-Hant"
    if re.search(r"簡體|简体", raw):
        return "zh-Hans"
    if re.search(r"英文|English", raw, re.I):
        return "en"
    return None


# ── 3. 商品解析 ─────────────────────────────────────────────

def parse_product(p: dict, cats: dict[int, dict]) -> dict | None:
    name = strip_html(p.get("name"))
    if not name:
        return None

    short = strip_html(p.get("short_description"))
    desc = strip_html(p.get("description"))
    text = (short + "\n" + desc).strip()

    rec: dict = {
        "pid": str(p.get("id")),
        "source": SOURCE,
        "source_url": p.get("permalink") or f"{BASE}/product/{p.get('slug', '')}/",
        "title": name,
        "publisher": PUBLISHER,
        "currency": CURRENCY,
        "is_ebook": False,
        "script": guess_script(name, text),
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }
    if p.get("sku"):
        rec["item_no"] = str(p["sku"])[:30]

    # 價格:USD,currency_minor_unit=2 → "7900" 是 $79.00
    pr = p.get("prices") or {}
    minor = int(pr.get("currency_minor_unit") or 0)

    def scale(v):
        if v in (None, "", "0"):
            return None
        m = re.search(r"\d+", str(v))
        if not m:
            return None
        n = int(m.group(0))
        return f"{n / (10 ** minor):.2f}".rstrip("0").rstrip(".") if minor else str(n)

    price_sale, price_list = scale(pr.get("price")), scale(pr.get("regular_price"))
    if price_list:
        rec["price_list"] = price_list
        if price_sale and price_sale != price_list:
            rec["price_sale"] = price_sale
    elif price_sale:
        rec["price_list"] = price_sale

    # ── 決議 2:標籤抽取(標籤可能在行首,也可能夾在段落中)──
    # ★ 只收白名單鍵:站方簡介有大量目錄行,那些冒號不是欄位。
    spec: dict[str, str] = {}
    for m in _LABEL_RE.finditer(text):
        k, v = m.group(1).strip(), m.group(2).strip()
        if k not in _SPEC_KEYS or not v or len(v) > 120:
            continue
        spec.setdefault(k, v)
    for k in [k for k, v in spec.items() if v in _PLACEHOLDER]:
        spec.pop(k)
    if spec:
        rec["spec_raw"] = " / ".join(f"{k}:{v}" for k, v in spec.items())[:1500]

    for keys, dst in [(("作者", "著者", "編著", "编著", "編者", "编者"), "authors_raw"),
                      (("譯者", "译者", "翻譯", "翻译"), "translators_raw"),
                      (("繪圖", "绘图", "繪者", "绘者", "插畫", "插画"), "illustrators_raw"),
                      (("總校訂", "總教訂", "总校订", "校訂", "校订"), "editors_raw"),
                      (("書系", "书系", "系列", "叢書", "丛书"), "series"),
                      (("頁數", "页数"), "page_count_raw"),
                      (("尺寸", "開本", "开本", "規格", "规格"), "dimensions"),
                      (("裝訂方式", "装订方式", "裝訂", "装订"), "binding"),
                      (("分類", "分类"), "subject_raw"),      # 陷阱 5:站方細分類
                      (("重量",), "weight_raw"),
                      (("語言", "语言"), "language_raw")]:
        for k in keys:
            if spec.get(k):
                rec[dst] = spec[k][:200]
                break

    lang = norm_lang(rec.get("language_raw"))
    if lang:
        rec["language"] = lang

    # 陷阱 4:頁數多值不拆不加總;純數字(或僅帶「頁」單位)才入 page_count
    if rec.get("page_count_raw"):
        cand = re.sub(r"\s*[頁页]\s*$", "", rec["page_count_raw"].strip())
        if re.fullmatch(r"\d{1,5}", cand):
            rec["page_count"] = cand
            rec.pop("page_count_raw")

    # 陷阱 3:出版日期(含民國年)
    for k in ("出版日期", "出版日", "出版年月", "出版時間", "出版时间", "初版"):
        if spec.get(k):
            got, how = parse_pubdate(spec[k])
            if got:
                rec["publish_date"] = got
                if how and how != "西元":
                    rec["publish_date_from"] = how[:60]
            else:
                rec["publish_date_bad"] = spec[k][:40]
            break

    # 陷阱 1:ISBN。標籤(ISBN / 原書號)優先,全文裸掃只當最後手段
    for k in ("ISBN", "isbn", "國際書號", "原書號", "原书号"):
        if spec.get(k):
            got, how = isbn13_from(spec[k])
            if got:
                rec["isbn"], rec["isbn_from"] = got, f"{k}:{how}"
            else:
                rec["isbn_raw"] = spec[k][:40]     # 解不出就留原值,不丟棄
            break
    if not rec.get("isbn"):
        hits = set()
        for m in _ISBN_RE.finditer(text):
            got, _ = isbn13_from(m.group(1))
            if got:
                hits.add(got)
        if len(hits) == 1:
            rec["isbn"], rec["isbn_from"] = hits.pop(), "全文裸掃"
        elif len(hits) > 1:
            # 簡介常引用同系列其他書的書號 → 多於一個就不猜,全部留著
            rec["isbn_candidates"] = "; ".join(sorted(hits))[:120]

    if text:
        rec["summary"] = text[:4000]
        if len(text) > 4000:
            rec["desc_full"] = text[:60000]

    # 重量/尺寸:站方文字是公制(克/cm),Woo 欄位是美制(lbs/in),兩個都留、不換算
    fw = strip_html(p.get("formatted_weight"))
    if fw and fw not in _PLACEHOLDER:
        rec["weight"] = fw[:50]
    fd = strip_html(p.get("formatted_dimensions"))
    if fd and fd not in _PLACEHOLDER and not rec.get("dimensions"):
        rec["dimensions"] = fd[:100]

    if p.get("is_in_stock") is False:
        sa = strip_html((p.get("stock_availability") or {}).get("text"))
        rec["stock_status"] = sa or "缺貨(站方標示無庫存)"
    if p.get("is_purchasable") is False:
        rec["stock_status"] = (rec.get("stock_status") or "") + "(不可線上購買)"
    if p.get("type") and p["type"] != "simple":
        rec["product_type"] = str(p["type"])[:30]

    imgs = p.get("images") or []
    if imgs and imgs[0].get("src"):
        rec["cover_url"] = imgs[0]["src"]
        if len(imgs) > 1:
            rec["cover_urls_extra"] = "; ".join(i["src"] for i in imgs[1:6] if i.get("src"))[:900]

    # ── 決議 3:分類 ──
    cl, slugs_raw, series_hit = [], [], []
    for c in p.get("categories") or []:
        cid = int(c.get("id") or 0)
        cl.append({"code": str(cid), "path": cat_path(cats, cid) or c.get("name", "")})
        if c.get("slug"):
            slugs_raw.append(c["slug"])
        if cid in SERIES_CATS:
            series_hit.append(SERIES_CATS[cid])
    if slugs_raw:
        rec["cat_slugs"] = "; ".join(slugs_raw)[:500]

    # ★ 熊哥決議(9/13):兩層並用——簡介裡的站方細分類優先,沒有的才落回 product_cat。
    #   細分類以 "sub:" 前綴成為獨立的 subjects 列,對映表用 sort_order 讓它贏過 product_cat。
    #   簡繁兩種寫法(神學類／教義 vs 神学类／教义)各自成列,不做字形轉換——
    #   utf8mb4_unicode_ci 不會把簡繁視為相等,對映表兩種都要登錄。
    fine = None
    if rec.get("subject_raw"):
        fine = {"code": SUB_PREFIX + rec["subject_raw"][:36],
                "path": "站方細分類 > " + rec["subject_raw"]}
        cl.insert(0, fine)
    if cl:
        rec["categories"] = cl          # 全部照留存證(含書系),對映表決定歸不歸類
        if fine:
            primary = fine
        else:
            subject = [c for c in cl
                       if c["code"].isdigit() and int(c["code"]) not in NON_SUBJECT_CATS]
            primary = (subject or cl)[0]
        rec["category_source"] = primary["code"]
        rec["category_text"] = primary["path"]
    if series_hit and not rec.get("series"):
        rec["series"] = "; ".join(series_hit)[:200]
    return rec


# ── 4. 全量走訪與對帳 ───────────────────────────────────────

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


def sitemap_urls(force: bool = False) -> list[str]:
    """站方權威清單。9/13 實測 sitemap 144 = API 144,零差集。"""
    try:
        xml = polite_fetch(session, SITEMAP, CACHE, THROTTLE, force=force)
    except RuntimeError as e:
        print(f"  [sitemap 抓取失敗] {e}", flush=True)
        return []
    return re.findall(r"<loc>([^<]+)</loc>", xml)


def reconcile(recs: list[dict], cats: dict[int, dict]):
    print("\n[對帳一:站方權威清單 sitemap vs 抓到]", flush=True)
    smn = {u.rstrip("/") for u in sitemap_urls(force=True)}
    got = {r["source_url"].rstrip("/") for r in recs}
    only_sm, only_got = smn - got, got - smn
    print(f"  sitemap {len(smn)} 筆 / 抓到 {len(got)} 筆", flush=True)
    print(f"  sitemap 有但沒抓到:{len(only_sm)}", flush=True)
    for u in list(only_sm)[:10]:
        print(f"    - {u}", flush=True)
    print(f"  抓到但不在 sitemap:{len(only_got)}", flush=True)
    for u in list(only_got)[:10]:
        print(f"    + {u}", flush=True)
    if only_sm:
        print("  ★ 差集不為 0 → 補抓後再 import,不要當沒看到", flush=True)

    print("\n[對帳二:分類件數 抓到 vs 站方 count]", flush=True)
    tally: dict[str, int] = {}
    for r in recs:
        for c in r.get("categories", []):
            tally[c["code"]] = tally.get(c["code"], 0) + 1
    bad = 0
    for c in sorted(cats.values(), key=lambda x: -x["count"]):
        g = tally.get(str(c["id"]), 0)
        if c["count"] == 0 and g == 0:
            continue
        ok = "✓" if g == c["count"] else "✗"
        if g != c["count"]:
            bad += 1
        tag = "(書系)" if c["id"] in SERIES_CATS else ""
        print(f"  {ok} {(c['name'] + tag)[:24]:26s} 抓到 {g:4d} / 站方 {c['count']:4d}", flush=True)
    print(f"對不上的分類:{bad} 個", flush=True)


def audit(recs: list[dict]):
    """五陷阱實測報表。★ 不要拿工具自印的數字當證據——這裡一律列到可逐筆查。"""
    print("\n[陷阱 1+2:ISBN 還原方式與站內同號組]", flush=True)
    how: dict[str, int] = {}
    for r in recs:
        if r.get("isbn_from"):
            how[r["isbn_from"]] = how.get(r["isbn_from"], 0) + 1
    for k, v in sorted(how.items(), key=lambda x: -x[1]):
        print(f"  {k:34s} {v:4d} 本", flush=True)
    raws = [r for r in recs if r.get("isbn_raw")]
    print(f"  標籤有值但還原不出:{len(raws)} 本", flush=True)
    for r in raws[:10]:
        print(f"    - {r['title'][:22]:24s} {r['isbn_raw']}", flush=True)
    cand = [r for r in recs if r.get("isbn_candidates")]
    print(f"  全文有多個候選(不猜,全留):{len(cand)} 本", flush=True)

    by: dict[str, list[dict]] = {}
    for r in recs:
        if r.get("isbn"):
            by.setdefault(r["isbn"], []).append(r)
    dup = {k: v for k, v in by.items() if len(v) > 1}
    print(f"\n  ★ 站內同 ISBN 組數:{len(dup)}(站方資料本身重複,ISBN 不可單獨當合併鍵)", flush=True)
    for k, v in dup.items():
        same = "簡繁同書" if len({x["script"] for x in v}) > 1 else "★不同書,站方抄錯"
        print(f"  {k}  [{same}]", flush=True)
        for r in v:
            print(f"    - [{r['script']}] {r['title']}(pid {r['pid']})", flush=True)

    print("\n[陷阱 3:出版日期非標準格式(逐筆複核)]", flush=True)
    for r in [r for r in recs if r.get("publish_date_from")][:20]:
        print(f"  {r['title'][:22]:24s} → {r['publish_date']}  {r['publish_date_from']}", flush=True)
    bads = [r for r in recs if r.get("publish_date_bad")]
    print(f"  完全解析不出:{len(bads)}", flush=True)
    for r in bads[:10]:
        print(f"    - {r['title'][:22]:24s} 原值 {r['publish_date_bad']}", flush=True)

    print("\n[陷阱 4:頁數多值(未寫 page_count,原值留 page_count_raw)]", flush=True)
    for r in [r for r in recs if r.get("page_count_raw")][:20]:
        print(f"  {r['title'][:22]:24s} {r['page_count_raw']}", flush=True)

    print("\n[陷阱 5:站方細分類 subject_raw(比 product_cat 精確,供對映表使用)]", flush=True)
    sub: dict[str, int] = {}
    for r in recs:
        if r.get("subject_raw"):
            sub[r["subject_raw"]] = sub.get(r["subject_raw"], 0) + 1
    for k, v in sorted(sub.items(), key=lambda x: -x[1]):
        print(f"  {v:3d}  {k}", flush=True)
    print(f"  有細分類的書:{sum(sub.values())}/{len(recs)};相異值 {len(sub)} 種", flush=True)

    print("\n[欄位覆蓋率(全量,不是抽樣)]", flush=True)
    n = len(recs)
    for k in ("title", "cover_url", "summary", "price_list", "authors_raw", "translators_raw",
              "editors_raw", "series", "subject_raw", "page_count", "publish_date",
              "isbn", "language", "item_no", "categories"):
        c = sum(1 for r in recs if r.get(k))
        print(f"  {k:16s} {c:4d}/{n}  {c * 100 // max(n, 1):3d}%", flush=True)


# ── 5. probe ────────────────────────────────────────────────

def probe():
    print("== A. 分類與件數 ==", flush=True)
    cats = fetch_categories()
    if not cats:
        print("  取不到分類 API,請確認 Store API 是否可用", flush=True)
        return
    for c in sorted(cats.values(), key=lambda x: -x["count"]):
        tag = "  ← 書系,不當主題" if c["id"] in SERIES_CATS else ""
        print(f"  id={c['id']:<5} {c['count']:4d}  {c['slug'][:22]:24s} {c['name']}{tag}", flush=True)

    print("\n== B. 分頁邊界與全站量 ==", flush=True)
    first = fetch_json(f"{API}/products?per_page=5&page=1")
    over = fetch_json(f"{API}/products?per_page=5&page=9999")
    print(f"  page=1    → {len(first) if isinstance(first, list) else type(first).__name__} 件", flush=True)
    print(f"  page=9999 → {over if not isinstance(over, list) else f'{len(over)} 件'}"
          "(9/13 實測:空陣列,不是夾回首頁)", flush=True)
    print(f"  sitemap 商品數:{len(sitemap_urls())}", flush=True)

    print("\n== C. 抽樣欄位 ==", flush=True)
    # ★ 8/25 福音書房教訓:抽樣要抽主題大類,不要抽新品/促銷。這裡固定抽「聖經註釋」。
    cid = next((c["id"] for c in cats.values() if c["slug"] == "commentary"), None)
    q = f"category={cid}&" if cid else ""
    print(f"  抽樣來源:{'聖經註釋 id=' + str(cid) if cid else '全站'}", flush=True)
    sample = fetch_json(f"{API}/products?{q}per_page=8&page=1") or []
    hits: dict[str, int] = {}
    for p in sample:
        rec = parse_product(p, cats)
        if not rec:
            continue
        for k in ("title", "authors_raw", "translators_raw", "editors_raw", "series",
                  "subject_raw", "price_list", "isbn", "publish_date", "page_count",
                  "summary", "cover_url", "spec_raw", "categories", "language"):
            if rec.get(k):
                hits[k] = hits.get(k, 0) + 1
        print("\n  ---", rec.get("title"), "---", flush=True)
        print(json.dumps({k: v for k, v in rec.items() if k not in ("summary", "desc_full")},
                         ensure_ascii=False, indent=1)[:900], flush=True)
    if sample:
        print(f"\n  欄位命中率(樣本 {len(sample)} 件):", flush=True)
        for k, v in sorted(hits.items(), key=lambda x: -x[1]):
            print(f"    {k:16s} {v}/{len(sample)}", flush=True)

    print("\n== D. 五陷阱全量檢查 ==", flush=True)
    audit(walk_products(cats))


# ── 6. 每日新品 ─────────────────────────────────────────────
# 本站沒有「新品」分類,改用 orderby=date。★ 列表頁一律 force(基道教訓:
# 沒 force 會讀到永久快取,六週零新書而 log 全綠)。

def collect_new(max_pages: int = 2, per_page: int = 20) -> list[dict]:
    cats = fetch_categories()
    out: list[dict] = []
    for page in range(1, max_pages + 1):
        data = fetch_json(f"{API}/products?orderby=date&order=desc"
                          f"&per_page={per_page}&page={page}", force=True)
        if not data:
            break
        for p in data:
            rec = parse_product(p, cats)
            if rec:
                out.append(rec)
        if len(data) < per_page:
            break
    return out


# ── 7. 分類對映表 ───────────────────────────────────────────
# id → (站內分類名 或 None=僅存證, unpublish, sort_order, note)
# 決議 3:書系一律 None(僅存證),由爬蟲寫進 series 欄;主題才對映 CategoryV11。
# ★ 熊哥決議(9/13)兩層並用:簡介裡的 subject_raw(聖經論叢／詩篇…)比 15 個
#   product_cat 精確,故以 sort_order 110 優先;沒有細分類的書落回 product_cat(5xx)。
# 鍵是字串:product_cat 用數字碼,站方細分類用 "sub:" 前綴。
# ★ internal_name 必須是**站內 categories.name 實際存在的值**;填錯不會報錯、
#   只會靜默不歸類 → 產出的 SQL 末尾附了一段驗證查詢,務必跑。
CAT_MAP: dict[str, tuple] = {
    # ── product_cat(粗,143/144 有值,當後備)──
    # 站內分類名以 2026-09-13 熊哥貼回的 categories 實表為準(26 類,code 01–18/H–ZZ)。
    "48": ("聖經研究", 0, 510, "聖經註釋"),
    "47": ("神學", 0, 520, "聖經神學"),
    "45": ("聖經研究", 0, 530, "聖經原文(原文教材歸聖經研究,「聖經」類留給聖經本身)"),
    "50": ("教會復興", 0, 540, "解經講道=講道法,屬教會事工 → 站內最近的是教會復興"),
    "46": ("門徒造就", 0, 550, "聖經教育=教導裝備"),
    "49": ("靈修", 0, 560, "聖經靈修"),
    "44": ("聖經研究", 0, 570, "聖經人物=人物研究"),
    "51": ("心理", 0, 580, "輔導協談 → 站內「心理」"),
    "39": ("青少年家庭", 0, 590, "婚姻家庭 → 站內「青少年家庭」"),
    "37": (None, 0, 900, "光照系列=書系,僅存證(已寫入 series)"),
    "41": (None, 0, 900, "成長系列=書系,僅存證(已寫入 series)"),
    "42": (None, 0, 900, "焦點系列=書系,僅存證(已寫入 series)"),
    "43": (None, 0, 900, "種子系列=書系,僅存證(已寫入 series)"),
    "76": (None, 0, 900, "根基系列=書系,僅存證(已寫入 series)"),
    "100": (None, 0, 950, "Uncategorized,僅存證"),
    # ── 站方細分類(精確,42/144 有值,sort_order 小 → 贏過 product_cat)──
    # ★ 簡繁兩種寫法各自成列:utf8mb4_unicode_ci 不把簡繁視為相等,少登一種就漏。
    # 這裡只放 9/13 全量實測到的 32 種;新值由 --emit-map-sql 自動列在末尾待補。
}
# 細分類 → 站內分類名。左鍵不含 "sub:" 前綴,emit 時自動補上。
SUB_MAP: dict[str, str | None] = {
    "聖經論叢／詩篇": "聖經研究", "聖經論叢／歷史書": "聖經研究",
    "聖經論叢／腓立比書": "聖經研究", "聖經論叢／耶穌生平與教訓": "聖經研究",
    "聖經論叢／聖經神學": "聖經研究", "聖經論叢／哥林多前後": "聖經研究",
    "聖經論叢／教牧書信": "聖經研究", "聖經論叢／使徒行傳": "聖經研究",
    "聖經論叢／歌羅西書": "聖經研究", "聖經論叢／箴言": "聖經研究",
    "聖經論叢／以弗所書": "聖經研究", "聖經論叢／希伯來書": "聖經研究",
    "聖經論叢／約伯記": "聖經研究", "聖經論叢／以賽亞書": "聖經研究",
    "聖經論叢／約翰壹、貳、參書": "聖經研究", "聖經論叢／加拉太書": "聖經研究",
    "聖經論叢／路得記": "聖經研究", "聖經論叢／羅馬書": "聖經研究",
    "聖經論叢／新約引用舊約": "聖經研究", "聖經論叢／啟示錄": "聖經研究",
    "聖經論叢／提摩太前後": "聖經研究", "聖經論叢／約翰福音": "聖經研究",
    "聖經論叢／馬太福音": "聖經研究", "聖經註釋": "聖經研究",
    "神學類／救恩論": "神學", "神學類／神論": "神學",
    "神學類／聖靈論": "神學", "神學類／教義": "神學",
    "神學類／系統神學概論": "神學",
    "神学类／教义": "神學", "神学类／系统神学概论": "神學",   # 簡體寫法
    "生命造就": "門徒造就",
}


def emit_map_sql():
    cats = fetch_categories()
    recs = walk_products(cats)
    seen: dict[str, tuple[str, str, int]] = {}      # code → (path, slug, 件數)
    for r in recs:
        for c in r.get("categories", []):
            code, path = c["code"], c["path"]
            n = seen.get(code, ("", "", 0))[2]
            slug = "" if code.startswith(SUB_PREFIX) else next(
                (x["slug"] for x in cats.values() if str(x["id"]) == code), "")
            seen[code] = (path, slug, n + 1)

    rows, missing = [], []
    for code, (path, slug, n) in sorted(seen.items()):
        if code.startswith(SUB_PREFIX):
            key = code[len(SUB_PREFIX):]
            if key in SUB_MAP:
                m = (SUB_MAP[key], 0, 110, f"站方細分類({n} 件)")
            else:
                missing.append((code, path, n))
                m = (None, 0, 120, f"★站方新增細分類,請補對映({n} 件)")
        else:
            m = CAT_MAP.get(code)
            if m is None:
                missing.append((code, path, n))
                m = (None, 0, 950, f"★站方新增分類,請補對映({n} 件)")
        name, unp, srt, note = m
        nm = "NULL" if name is None else "'" + name.replace("'", "''") + "'"
        nt = "NULL" if not note else "'" + str(note).replace("'", "''") + "'"
        rows.append(f"('{code[:40]}', '{path[:80].replace(chr(39), chr(39) * 2)}', "
                    f"'{slug[:100]}', {nm}, {unp}, {srt}, {nt})")

    print("-- 自動產生:python3 akow_crawler.py --emit-map-sql > "
          "../database/migrations/2026-09-13_akow_category_map.sql")
    print(f"-- 代碼 {len(seen)} 個(product_cat + 站方細分類);未對映 {len(missing)} 個")
    print("""
CREATE TABLE IF NOT EXISTS akow_category_map (
  akow_code     VARCHAR(40)  NOT NULL COMMENT '=subjects.code;數字=WooCommerce 分類 id,sub:xxx=簡介裡的站方細分類',
  akow_path     VARCHAR(80)  NOT NULL COMMENT '站方分類路徑(僅供人讀)',
  akow_slug     VARCHAR(100) NULL     COMMENT 'product_cat 的 slug(細分類無)',
  internal_name VARCHAR(50)  NULL     COMMENT '對映到的站內 categories.name;NULL=僅存證不歸類',
  unpublish     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=非書,akow-only 命中任一即下架',
  sort_order    INT          NOT NULL DEFAULT 500 COMMENT 'primary 優先序(小者優先;細分類 110 贏過 product_cat 5xx)',
  note          VARCHAR(200) NULL,
  PRIMARY KEY (akow_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='麥種傳道會分類對映(2026-09-13);書系一律 NULL 改寫 series,細分類優先於 product_cat';

INSERT INTO akow_category_map
  (akow_code, akow_path, akow_slug, internal_name, unpublish, sort_order, note) VALUES""")
    print(",\n".join(rows) + "\nON DUPLICATE KEY UPDATE akow_path = VALUES(akow_path), akow_slug = VALUES(akow_slug);")
    print("""
-- ★★ 必跑的驗證:對映到「站內不存在的分類名」會靜默不歸類,不會報錯。
--    下面這段應該回 0 列;有回列就是 internal_name 打錯或站內分類改名了。
SELECT DISTINCT m.internal_name AS 對不到的分類名
  FROM akow_category_map m
  LEFT JOIN categories c ON c.name = m.internal_name
 WHERE m.internal_name IS NOT NULL AND c.category_id IS NULL;""")
    if missing:
        print("\n-- ★以下代碼尚未對映(已填 NULL/僅存證),請補:")
        for code, path, n in missing:
            print(f"--   {code}  {path}({n} 件)")


# ── 8. main ─────────────────────────────────────────────────

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true")
    ap.add_argument("--emit-map-sql", action="store_true")
    ap.add_argument("--limit", type=int, default=0)
    args = ap.parse_args()

    if args.emit_map_sql:
        emit_map_sql()
        return
    if args.probe:
        probe()
        return

    DATA.mkdir(exist_ok=True)
    writer = JsonlWriter(DATA / "akow_books.jsonl", key_field="pid")

    print("== 第一階段:分類 ==", flush=True)
    cats = fetch_categories()
    print(f"分類 {len(cats)} 個(其中書系 {len(SERIES_CATS)} 個不當主題)", flush=True)

    print("== 第二階段:全站商品(Store API)==", flush=True)
    recs = walk_products(cats, args.limit)
    done = sum(1 for r in recs if writer.write(r))
    print(f"完成:本次入檔 {done} 件(檔案累計 {len(writer.seen)})", flush=True)

    reconcile(recs, cats)
    audit(recs)

    longest: dict[str, tuple[int, str]] = {}
    for r in recs:
        for k, v in r.items():
            if isinstance(v, str) and len(v) > longest.get(k, (0, ""))[0]:
                longest[k] = (len(v), v[:80])
    print("\n[最長欄位值檢查——對照 schema 上限(匯入前必看)]", flush=True)
    for k, (n, s) in sorted(longest.items(), key=lambda x: -x[1][0])[:12]:
        print(f"  {k:16s} {n:6d}  {s}", flush=True)
    print("  ★ source_url 9/13 實測最長 180(percent-encoded 中文 slug);"
          "desc_full 可達 29K,對照 books.summary 型別", flush=True)


if __name__ == "__main__":
    main()
