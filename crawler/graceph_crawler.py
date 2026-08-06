# -*- coding: utf-8 -*-
"""天恩出版社 graceph.com 爬蟲(單執行緒、節流 2-3 秒、快取續跑)。

8/6 偵察結論(WebFetch 實測):
 1. WordPress + WooCommerce。**Store API 公開可用**:
    /wp-json/wc/store/products?per_page=100&page=N(逾末頁回空陣列 [])
    → 商品清單走 API(穩定 JSON:id/name/permalink/sku/prices/images/
    categories/tags/description),不解析列表 HTML。全站約 1,626 件。
 2. 書目欄位(作者/譯者/ISBN/頁數/初版/開數/定價/英文書名/類別)**不在
    Store API**(attributes 空、description 只有文案),只在商品頁 HTML 的
    「資訊」頁籤 → 每件商品仍需抓一次 HTML 解析(節流照舊)。
 3. robots.txt 僅擋 /wp-admin、/cart、/checkout、/my-account;/product 與
    /wp-json 未限制。
 4. 分類為平面多分類(一書多類,Store API categories 陣列),含促銷性分類
    (新書快報 4301、暢銷排行 3760)與格式分類(電子書 4399)。8/6 決議:
    全站抓入存證;非書(文創禮品/質選文創好物/專輯有聲/虛擬商品/年度日月曆)
    匯入後由 grace_category_map 下架;電子書照書上架、與紙本合併並
    另掛「天恩出版社(電子書)」購書連結。
 5. 電子書判定:商品名含「電子書」或 sku 以 eb 開頭(電子書分類不可靠——
    有紙本書誤掛,如 55H002)。電子書書名去尾綴「(電子書)」後入檔,
    利於與紙本同書模糊合併(原始名稱仍整包存 extra)。

分類雙軌保存(沿 7/31 以琳決議):每本書 categories 完整清單原樣入檔
(分類 id+名稱,一書多分類),匯入寫 subjects(scheme='grace') 永久存證;
站內瀏覽分類另由 grace_category_map 對映(apply_grace_categories.php)。

用法(主機;venv 沿用既有 requests/bs4/lxml):
  python3 graceph_crawler.py --probe          # 驗證 API+商品頁解析(先跑,貼回輸出)
  nohup python3 graceph_crawler.py > logs/grace.log 2>&1 &   # 全量(可中斷續跑)
  python3 graceph_crawler.py --limit 30       # 試跑 30 本
  python3 graceph_crawler.py --browser-ua     # 若機器人 UA 被擋改用瀏覽器式 UA
"""
from __future__ import annotations

import argparse
import json
import re
from datetime import datetime, timezone
from pathlib import Path

from bs4 import BeautifulSoup

from common import JsonlWriter, make_session, polite_fetch

BASE = "https://graceph.com"
API_PRODUCTS = BASE + "/wp-json/wc/store/products"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "grace"
DATA = HERE / "data"
THROTTLE = (2.0, 3.0)
PER_PAGE = 100

BROWSER_UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
              "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36")

session = make_session()
# Store API 回 JSON;Accept 帶 json 較合乎規矩(伺服器其實不挑)
session.headers["Accept"] = "application/json, text/html;q=0.9, */*;q=0.8"

# 促銷/格式分類:入檔存證但不作 category_source(平面後備欄)的 primary
NON_TOPICAL = {"新書快報", "暢銷排行", "電子書"}
# 分類顯示順序(愈前愈具體;與 grace_category_map.sort_order 一致)
CAT_ORDER_NAMES = [
    "聖經", "真理解經", "研經工具", "先知預言", "科學有神", "深度信仰",
    "聖經輔導", "醫治釋放", "關懷輔導", "婚姻家庭", "親子教育", "親密關係",
    "自我成長", "門徒訓練", "生命造就", "領導管理", "福音宣教", "禱告敬拜",
    "靈命成長", "其他",
    # 非書(匯入後下架)與促銷/格式類壓最後
    "專輯有聲", "文創禮品", "質選文創好物", "虛擬商品", "年度日月曆系列",
    "新書快報", "暢銷排行", "電子書",
]
CAT_ORDER = {n: i for i, n in enumerate(CAT_ORDER_NAMES)}


def fetch(url: str, force: bool = False) -> str:
    return polite_fetch(session, url, CACHE, THROTTLE, force=force)


# ── 1. Store API 商品清單 ────────────────────────────────────

def list_url(page: int) -> str:
    return f"{API_PRODUCTS}?per_page={PER_PAGE}&page={page}&orderby=date&order=desc"


def fetch_products_page(page: int, force: bool = False) -> list[dict] | None:
    """一頁商品(JSON 陣列);逾末頁回 [];解析失敗回 None。"""
    try:
        text = fetch(list_url(page), force=force)
    except RuntimeError as e:
        print(f"  [失敗] 第 {page} 頁:{e}", flush=True)
        return None
    try:
        data = json.loads(text)
    except json.JSONDecodeError:
        # 快取到錯誤頁(HTML)→ force 重抓一次
        if not force:
            return fetch_products_page(page, force=True)
        print(f"  [失敗] 第 {page} 頁非 JSON", flush=True)
        return None
    return data if isinstance(data, list) else None


def walk_products(force_lists: bool = False, max_pages: int = 0) -> dict[str, dict]:
    """走訪全站商品清單 → {pid: Store API 商品 dict}(日期倒序)。"""
    out: dict[str, dict] = {}
    page = 1
    while True:
        prods = fetch_products_page(page, force=force_lists)
        if prods is None:
            print(f"[清單] 第 {page} 頁抓取失敗,停止(已收 {len(out)})", flush=True)
            break
        if not prods:      # 空陣列 = 走完
            break
        for p in prods:
            out.setdefault(str(p.get("id")), p)
        print(f"[清單] 第 {page} 頁 {len(prods)} 件,累計 {len(out)}", flush=True)
        if len(prods) < PER_PAGE:
            break
        if max_pages and page >= max_pages:
            print(f"[清單] 達上限 {max_pages} 頁,停止", flush=True)
            break
        page += 1
    return out


# ── 2. 商品頁 HTML 解析(書目欄位)────────────────────────────

# 「資訊」頁籤逐行標籤(8/6 偵察:英文書名/編號/發行/開數/ISBN/作者/譯者/
# 類別/定價/頁數/初版;另備防出版社/裝訂/條碼變體)。行首錨定,標籤與值
# 之間容許全半形空白與冒號(教訓:全形冒號寫 [:：],值比對用 [ \t　] 不用 \s
# 以免空值吃到下一行;見 sandbox-mount-and-parsing-pitfalls)。
PAGE_LABELS: list[tuple[str, str]] = [
    ("title_en",        r"英\s*文\s*書\s*名"),
    ("item_no",         r"編\s*號|貨\s*號"),
    ("publisher_raw",   r"發\s*行|出\s*版\s*社"),
    ("dimensions",      r"開\s*數|尺\s*寸"),
    ("isbn",            r"ISBN|國際書號"),
    ("ean_upc",         r"條\s*碼|EAN"),
    ("authors_raw",     r"作\s*者"),
    ("translators_raw", r"譯\s*者"),
    ("grace_type",      r"類\s*別"),
    ("price_page",      r"定\s*價"),
    ("page_count",      r"頁\s*數"),
    ("publish_date",    r"初\s*版|出\s*版\s*日\s*期"),
    ("binding",         r"裝\s*訂"),
]

EBOOK_NAME = re.compile(r"\s*[((【\[]\s*電子書\s*[))】\]]\s*$")


def info_lines(soup: BeautifulSoup) -> list[str]:
    """取欄位比對用的文字行:先鎖 WooCommerce 頁籤面板(排除商品描述頁籤,
    描述文案可能含「作者:」字樣),找不到面板才退回整頁(仍先摘除描述)。"""
    panels = [p for p in soup.select(".woocommerce-Tabs-panel")
              if "description" not in (p.get("id") or "")]
    scope = panels or [soup]
    if not panels:
        for sel in ("#tab-description", ".woocommerce-product-details__short-description"):
            for el in soup.select(sel):
                el.extract()
    lines: list[str] = []
    for node in scope:
        for raw in node.get_text("\n").splitlines():
            line = raw.strip()
            if line:
                lines.append(line)
    return lines


def parse_fields(lines: list[str]) -> dict:
    out: dict = {}
    for line in lines:
        for key, label in PAGE_LABELS:
            if key in out:
                continue
            m = re.match(rf"(?:{label})[ \t　]*[:：∶]?[ \t　]*(.+)$", line, re.IGNORECASE)
            if m:
                val = m.group(1).strip(" \t　:：∶")
                if val and val not in ("無", "-", "—"):
                    out[key] = val
    return out


def _num(s: str | None) -> str | None:
    if not s:
        return None
    m = re.search(r"[\d,]+(?:\.\d+)?", s)
    return m.group(0).replace(",", "") if m else None


def _api_price(prices: dict) -> tuple[str | None, str | None]:
    """Store API prices → (定價, 特價);金額除以 10^currency_minor_unit。"""
    unit = int(prices.get("currency_minor_unit") or 0)
    def conv(v):
        if v in (None, ""):
            return None
        try:
            n = int(v) / (10 ** unit)
        except (TypeError, ValueError):
            return None
        return str(int(n)) if float(n).is_integer() else str(n)
    regular, cur = conv(prices.get("regular_price")), conv(prices.get("price"))
    sale = cur if (cur and regular and float(cur) < float(regular)) else None
    return regular or cur, sale


def parse_product(prod: dict, retried: bool = False) -> dict | None:
    """Store API 商品 dict + 商品頁 HTML → 入檔紀錄。"""
    pid = str(prod.get("id") or "")
    url = prod.get("permalink") or ""
    name = BeautifulSoup(prod.get("name") or "", "lxml").get_text().strip()
    if not pid or not url or not name:
        print(f"  [略過] 商品資料不完整 id={pid!r}", flush=True)
        return None

    sku = (prod.get("sku") or "").strip()
    is_ebook = bool(EBOOK_NAME.search(name)) or sku.lower().startswith("eb")
    title = EBOOK_NAME.sub("", name).strip() if is_ebook else name

    rec: dict = {
        "pid": pid,
        "item_no": sku or pid,   # → identifiers(STORE);商品頁「編號」再覆寫
        "source": "grace",
        "source_url": url,
        "title": title,
        "name_raw": name,
        "is_ebook": is_ebook,
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "currency": "TWD",
    }

    # Store API 直接可得:封面/價格/分類/標籤
    imgs = prod.get("images") or []
    if imgs and imgs[0].get("src"):
        rec["cover_url"] = imgs[0]["src"]
    price_list, price_sale = _api_price(prod.get("prices") or {})
    if price_list:
        rec["price_list"] = price_list
    if price_sale:
        rec["price_sale"] = price_sale
    cats = [{"code": str(c.get("id")), "path": (c.get("name") or "").strip()}
            for c in (prod.get("categories") or []) if c.get("name")]
    cats.sort(key=lambda c: CAT_ORDER.get(c["path"], 500))
    rec["categories"] = cats
    topical = [c for c in cats if c["path"] not in NON_TOPICAL]
    primary = (topical or cats)[:1]
    if primary:
        rec["category_source"] = primary[0]["code"]
        rec["category_text"] = primary[0]["path"]
    tags = [t.get("name", "").strip() for t in (prod.get("tags") or []) if t.get("name")]
    if tags:
        rec["tags"] = tags  # 促銷語/作者名混雜,只存 extra 不入 keywords

    # 商品頁 HTML:「資訊」頁籤書目欄位
    try:
        html = fetch(url, force=retried)
    except RuntimeError as e:
        print(f"  [跳過 {pid},下次重跑補抓] {e}", flush=True)
        return None
    soup = BeautifulSoup(html, "lxml")
    fields = parse_fields(info_lines(soup))
    if not fields and not retried:   # 可能快取到空殼頁 → force 重抓一次
        return parse_product(prod, retried=True)
    for k, v in fields.items():
        rec[k] = v

    # 欄位整理
    if rec.get("item_no"):
        rec["item_no"] = rec["item_no"].strip()
    rec["publisher"] = rec.get("publisher_raw") or "天恩出版社"
    if rec.get("page_count"):
        rec["page_count"] = _num(rec["page_count"])
    if not rec.get("price_list") and rec.get("price_page"):
        rec["price_list"] = _num(rec["price_page"])
    if rec.get("isbn"):
        m = re.search(r"97[89][\d\- ]{10,16}|\d{9}[\dXx]", rec["isbn"])
        rec["isbn"] = re.sub(r"[\- ]", "", m.group(0)) if m else None
        if not rec["isbn"]:
            del rec["isbn"]
    # 初版「2026年8月」/「2026.8」/「2026/08/01」→ YYYY-MM(-DD)
    if rec.get("publish_date"):
        m = re.search(r"(\d{4})\D{0,2}(\d{1,2})?\D{0,2}(\d{1,2})?", rec["publish_date"])
        if m:
            y, mo, d = m.group(1), m.group(2), m.group(3)
            rec["publish_date"] = y + (f"-{int(mo):02d}" if mo else "") + (f"-{int(d):02d}" if mo and d else "")
        else:
            del rec["publish_date"]

    # 描述:Store API description(HTML)→ 純文字
    desc = prod.get("description") or prod.get("short_description") or ""
    if desc:
        dsoup = BeautifulSoup(desc, "lxml")
        for tag in dsoup.select("style, script"):
            tag.decompose()
        summary = re.sub(r"\n{2,}", "\n", dsoup.get_text("\n")).strip()
        if summary:
            rec["summary"] = summary

    return rec


# ── 主流程 ───────────────────────────────────────────────────

def probe():
    print("=== 探測模式 ===", flush=True)
    prods = fetch_products_page(1, force=True)
    if not prods:
        print("Store API 第 1 頁抓取失敗!", flush=True)
        return
    print(f"[API] 第 1 頁 {len(prods)} 件;前 3 件:"
          + "、".join(str(p.get("id")) for p in prods[:3]), flush=True)
    # 分頁驗證:第 2 頁首件須與第 1 頁不同
    p2 = fetch_products_page(2, force=True)
    ok = bool(p2) and p2[0].get("id") != prods[0].get("id")
    print(f"[API] 第 2 頁 {len(p2 or [])} 件 → 分頁{'正常' if ok else '異常!'}", flush=True)
    # 解析樣本:1 本一般書 + 1 本電子書(找得到就驗)
    samples = [prods[0]]
    eb = next((p for p in prods if "電子書" in (p.get("name") or "")), None)
    if eb:
        samples.append(eb)
    for prod in samples:
        url = prod.get("permalink")
        print(f"\n--- 樣本 {prod.get('id')}:{prod.get('name')} ---", flush=True)
        try:
            html = fetch(url, force=True)
        except RuntimeError as e:
            print(f"商品頁抓取失敗:{e}", flush=True)
            continue
        lines = info_lines(BeautifulSoup(html, "lxml"))
        print("[資訊頁籤原始行,前 25 行]", flush=True)
        for ln in lines[:25]:
            print("  |", ln[:80], flush=True)
        rec = parse_product(prod)
        print(json.dumps(rec, ensure_ascii=False, indent=1)[:1800], flush=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--max-pages", type=int, default=0, help="清單頁上限(0=走到空頁)")
    ap.add_argument("--force-lists", action="store_true", help="清單頁不走快取(補抓新品時用)")
    ap.add_argument("--browser-ua", action="store_true", help="改用瀏覽器式 UA(仍帶 From 聯絡標頭)")
    args = ap.parse_args()

    if args.browser_ua:
        session.headers["User-Agent"] = BROWSER_UA

    if args.probe:
        probe()
        return

    DATA.mkdir(exist_ok=True)
    writer = JsonlWriter(DATA / "grace_books.jsonl", key_field="pid")

    print("== 第一階段:Store API 商品清單 ==", flush=True)
    prods = walk_products(force_lists=args.force_lists, max_pages=args.max_pages)
    todo = [pid for pid in prods if pid not in writer.seen]
    print(f"共 {len(prods)} 件,待抓 {len(todo)}", flush=True)

    print("== 第二階段:抓商品頁 ==", flush=True)
    done = 0
    for pid in todo:
        rec = parse_product(prods[pid])
        if rec:
            writer.write(rec)
            done += 1
            if done % 50 == 0:
                print(f"  已入檔 {done}/{len(todo)}", flush=True)
        if args.limit and done >= args.limit:
            print(f"到達 --limit {args.limit},停止", flush=True)
            break
    print(f"完成:本次入檔 {done} 本(檔案累計 {len(writer.seen)})", flush=True)


if __name__ == "__main__":
    main()
