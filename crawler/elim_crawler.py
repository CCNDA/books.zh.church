# -*- coding: utf-8 -*-
"""以琳書房 www.elimbookstore.com.tw 爬蟲(單執行緒、節流 2-3 秒、快取續跑)。

7/31 偵察結論(Chrome 實測):
 1. 傳統 PHP 商城、伺服器端渲染:列表 shop.php?html=list_goods&type=<路徑>
    &setid=<偏移量>(24/頁;setpage 參數無作用,分頁器宣稱頁數也不可信,
    一律走到空頁為止);商品頁 shop.php?html=showgoods&gid=<數字>。
    robots.txt 404(無限制)。
 2. 分類選單為 JS 動態產生(靜態 HTML 不含),故分類樹以 7/31 偵察結果
    **寫死**於 CATEGORIES(路徑碼→名稱);父分類頁不必然涵蓋子分類商品
    (聖經父頁僅 3 頁、其 22 個子分類另計),父+子都要走、依 gid 去重。
 3. 商品頁**不顯示 ISBN 文字**(搜尋選單雖有「商品條碼」欄位但頁面不輸出),
    ISBN 多藏於商品圖檔名(shop_images/.../9786267136492_bc.jpg)。
    僅當候選唯一時才認定 isbn(寧缺勿錯);候選>1 全存 isbn_candidates,
    交 import 模糊合併(書名+第一作者)。
 4. 欄位在 .information 面板:作者/譯者/出版社/出版日期/頁數/裝訂/商品語言/
    貨品尺寸/規格;價格「NT$288 NT$320」= 特價+定價。描述在 #product-detail。
 5. 依 7/31 決議只抓「書籍 10000/10052」「聖經 10000/10053」兩大類
    (影音/禮品不抓;書籍/日誌月曆照抓、匯入後由 elim_category_map 下架)。

分類雙軌保存(熊哥 7/31 要求):每本書的 **categories 完整清單原樣入檔**
(路徑碼+名稱路徑,一書可屬多分類),匯入時寫 subjects(scheme='elim') 永久
存證;站內瀏覽分類另由 elim_category_map 對映(apply_elim_categories.php),
兩軌並存、日後可互相查照。

用法(主機;先 python3 -m venv venv && venv/bin/pip install -r requirements.txt):
  python3 elim_crawler.py --probe          # 驗證列表+商品頁解析(先跑,貼回輸出)
  nohup python3 elim_crawler.py > logs/elim.log 2>&1 &   # 全量(可中斷續跑)
  python3 elim_crawler.py --limit 50       # 試跑 50 本
  python3 elim_crawler.py --only 神學研經   # 只走指定分類
  python3 elim_crawler.py --browser-ua     # 若機器人 UA 被擋改用瀏覽器式 UA
"""
from __future__ import annotations

import argparse
import json
import re
from datetime import datetime, timezone
from pathlib import Path

from bs4 import BeautifulSoup

from common import JsonlWriter, make_session, polite_fetch

BASE = "https://www.elimbookstore.com.tw"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "elim"
DATA = HERE / "data"
THROTTLE = (2.0, 3.0)

BROWSER_UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
              "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36")

# 7/31 Chrome 偵察的分類樹(選單 JS 動態產生,靜態頁抓不到,故寫死)。
# 順序即 primary 優先序(選單順序)。只含書籍+聖經(7/31 決議)。
CATEGORIES: list[tuple[str, str]] = [
    ("10000/10052",       "書籍"),
    ("10000/10052/10056", "書籍/神學研經"),
    ("10000/10052/10062", "書籍/教會事奉"),
    ("10000/10052/10072", "書籍/禱告靈修"),
    ("10000/10052/10077", "書籍/醫治輔導"),
    ("10000/10052/10081", "書籍/福音見證"),
    ("10000/10052/10086", "書籍/生活家庭"),
    ("10000/10052/10093", "書籍/童書系列"),
    ("10000/10052/10098", "書籍/教材系列"),
    ("10000/10052/10105", "書籍/休閒藝文"),
    ("10000/10052/10277", "書籍/日誌月曆"),
    ("10000/10052/10293", "書籍/外文書"),
    ("10000/10053",       "聖經"),
    ("10000/10053/10202", "聖經/和合本"),
    ("10000/10053/10208", "聖經/新標點和合本"),
    ("10000/10053/10212", "聖經/新譯本"),
    ("10000/10053/10217", "聖經/現代中文譯本"),
    ("10000/10053/10221", "聖經/中英對照"),
    ("10000/10053/10222", "聖經/兒童聖經"),
    ("10000/10053/10223", "聖經/多功能聖經"),
    ("10000/10053/10339", "聖經/新約全書"),
    ("10000/10053/10341", "聖經/英文聖經"),
    ("10000/10053/10345", "聖經/恢復本"),
    ("10000/10053/10347", "聖經/外文聖經"),
    ("10000/10053/10353", "聖經/和合本修訂版"),
    ("10000/10053/10371", "聖經/新標研讀本"),
    ("10000/10053/10374", "聖經/客語聖經"),
    ("10000/10053/10376", "聖經/標準本"),
    ("10000/10053/10377", "聖經/大字版"),
    ("10000/10053/10378", "聖經/注音聖經"),
    ("10000/10053/10383", "聖經/新普及譯本"),
    ("10000/10053/10401", "聖經/簡體聖經"),
    ("10000/10053/10425", "聖經/台語聖經"),
    ("10000/10053/10429", "聖經/當代譯本"),
    ("10000/10053/10432", "聖經/原住民語聖經"),
]
CAT_ORDER = {code: i for i, (code, _) in enumerate(CATEGORIES)}
# 父分類壓到最後:同掛父+子分類的書,primary 應取較具體的子分類
CAT_ORDER["10000/10052"] = 900  # 書籍
CAT_ORDER["10000/10053"] = 901  # 聖經
CAT_NAME = dict(CATEGORIES)

session = make_session()


def fetch(url: str, force: bool = False) -> str:
    return polite_fetch(session, url, CACHE, THROTTLE, force=force)


MAX_PAGES = 400  # 每分類保險上限(400×24=9,600 件)

def list_url(path: str, page: int = 1) -> str:
    """分頁參數是 setid=偏移量(24/頁);setpage 無作用(7/31 實測)。"""
    url = f"{BASE}/shop.php?html=list_goods&lefthtml=left_type&type={path}"
    return url if page == 1 else f"{url}&setid={(page - 1) * 24}"


def product_url(gid: str) -> str:
    return f"{BASE}/shop.php?html=showgoods&gid={gid}"


# ── 1. 列表頁 ────────────────────────────────────────────────

def extract_gids(html: str) -> list[str]:
    """只取主商品列表(.view-product-list)內的 gid,排除側欄/輪播推薦。"""
    soup = BeautifulSoup(html, "lxml")
    out: dict[str, None] = {}
    for box in soup.select(".view-product-list"):
        for a in box.select('a[href*="gid="]'):
            m = re.search(r"gid=(\d+)", a.get("href", ""))
            if m:
                out.setdefault(m.group(1))
    return list(out)


def extract_last_page(html: str) -> int:
    """分頁器宣稱的頁數——僅供參考顯示。7/31 實測不可信(和合本宣稱 1 頁、
    setid=24 仍有下一頁),實際走頁以「空頁即止」為準。"""
    pages = [int(m) for m in re.findall(r"setpage=(\d+)", html)]
    return max(pages) if pages else 1


def is_valid_list_page(html: str) -> bool:
    """空殼頁防護(比照基道):主列表容器都不在就視為無效。"""
    return "view-product-list" in html or "list_goods" in html


def fetch_list(path: str, page: int) -> str | None:
    url = list_url(path, page)
    try:
        html = fetch(url)
        if not is_valid_list_page(html):
            print(f"  [空殼頁] 重抓 {url}", flush=True)
            html = fetch(url, force=True)
        return html if is_valid_list_page(html) else None
    except RuntimeError as e:
        print(f"  [失敗] {e}", flush=True)
        return None


def walk_categories(only: str | None = None) -> dict[str, list[str]]:
    """走訪所有分類列表 → gid: [分類路徑碼…](保序去重)。"""
    gid_cats: dict[str, list[str]] = {}
    for path, name in CATEGORIES:
        if only and only not in name:
            continue
        html = fetch_list(path, 1)
        if html is None:
            print(f"[{name}] 首頁抓取失敗,跳過", flush=True)
            continue
        print(f"[{name}] 分頁器宣稱 {extract_last_page(html)} 頁(僅參考,走到空頁為止)", flush=True)
        page = 1
        pages_done = 0
        prev_first: str | None = None
        while True:
            gids = extract_gids(html) if html else []
            if not gids:            # 超出範圍回空頁 → 此分類走完
                break
            if gids[0] == prev_first:  # 伺服器忽略偏移量的保險(理論上不會發生)
                print(f"  [警告] 第 {page} 頁與前頁相同,提前停止", flush=True)
                break
            prev_first = gids[0]
            for g in gids:
                cats = gid_cats.setdefault(g, [])
                if path not in cats:
                    cats.append(path)
            pages_done += 1
            if pages_done >= MAX_PAGES:
                print(f"  [警告] 達每分類上限 {MAX_PAGES} 頁,停止", flush=True)
                break
            page += 1
            html = fetch_list(path, page)
        print(f"[{name}] 走了 {pages_done} 頁,累計 gid {len(gid_cats)}", flush=True)
    return gid_cats


# ── 2. 商品頁解析 ────────────────────────────────────────────

LABELS = {
    "authors_raw":     r"作者",
    "translators_raw": r"譯者",
    "editors_raw":     r"(?:編者|主編)",
    "illustrators_raw": r"(?:繪者|插畫)",
    "publisher":       r"出版社",
    "publish_date":    r"出版日期",
    "page_count":      r"頁數",
    "binding":         r"裝訂",
    "language":        r"商品語言",
    "dimensions":      r"貨品尺寸/?規格",
}

SITE_SUFFIX = re.compile(r"\s*以琳書房\s*-\s*屬靈甘泉．來自以琳\s*$")


def parse_product(gid: str, cats: list[str], retried: bool = False) -> dict | None:
    url = product_url(gid)
    html = fetch(url, force=retried)
    soup = BeautifulSoup(html, "lxml")

    rec: dict = {
        "gid": gid,
        "item_no": gid,  # → identifiers(STORE)
        "source": "elim",
        "source_url": url,
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }

    # 書名/封面:og meta(og:title 帶站名尾綴)
    for prop, key in (("og:title", "title"), ("og:image", "cover_url")):
        el = soup.find("meta", attrs={"property": prop})
        if el and el.get("content"):
            rec[key] = el["content"].strip()
    if rec.get("title"):
        rec["title"] = SITE_SUFFIX.sub("", rec["title"]).strip() or None
    if not rec.get("title"):
        if not retried:  # 可能快取到空殼頁 → force 重抓一次
            return parse_product(gid, cats, retried=True)
        print(f"  [略過] gid={gid} 無書名", flush=True)
        return None

    # 描述區先從樹上摘離:內文可能含「作者:」等字樣,不得混入欄位比對
    # (摘離後的節點仍可讀,最後拿來取 summary)
    detail = soup.select_one("#product-detail")
    if detail is not None:
        detail.extract()

    # 標籤欄位:逐 <p> 段落比對(一段一欄位)。值常包在 <a> 內
    # (<p>作者 : <a>雷亞蘭</a></p>),整區 get_text("\n") 會把值切到下一行;
    # 而空值欄位(<p>作者 :</p>)若用 \s 跨行比對又會誤抓下一欄。
    # 逐段落取單行文字兩者兼顧:有值同行、空值無 match。
    info = soup.select_one("#center_column") or soup
    lines = []
    for p in info.find_all("p"):
        line = p.get_text(" ", strip=True)
        if line:
            lines.append(line)
    text = "\n".join(lines) + "\n" + info.get_text("\n")  # 後半作非 <p> 欄位的後備
    for key, label in LABELS.items():
        m = re.search(rf"{label}[ \t　]*[:：][ \t　]*([^\n]+)", text)
        if m:
            val = m.group(1).strip()
            if val and val not in ("無", "-"):
                rec[key] = val
    if rec.get("page_count") and not re.fullmatch(r"\d+", rec["page_count"]):
        m = re.search(r"\d+", rec["page_count"])
        rec["page_count"] = m.group(0) if m else None

    # 價格:「NT$288 NT$320」= 特價+定價;僅一個則為定價。
    # 先鎖 .product-price-group 避免誤抓相關商品輪播的價格。
    price_box = soup.select_one(".product-price-group") or info
    prices = re.findall(r"NT\$\s*([\d,]+)", price_box.get_text(" "))
    nums = [p.replace(",", "") for p in prices[:2]]
    if len(nums) >= 2:
        rec["price_sale"], rec["price_list"] = nums[0], nums[1]
    elif len(nums) == 1:
        rec["price_list"] = nums[0]
    rec["currency"] = "TWD"

    # ISBN:商品圖檔名(gallery img#product-zoom 與 og:image);唯一候選才認定
    candidates: dict[str, None] = {}
    for img in soup.select("img#product-zoom"):
        for m in re.findall(r"(97[89]\d{10})", img.get("src", "")):
            candidates.setdefault(m)
    if rec.get("cover_url"):
        for m in re.findall(r"(97[89]\d{10})", rec["cover_url"]):
            candidates.setdefault(m)
    cand = list(candidates)
    if len(cand) == 1:
        rec["isbn"] = cand[0]
    elif len(cand) > 1:
        rec["isbn_candidates"] = cand  # 寧缺勿錯,交 import 模糊合併

    # 描述:#product-detail(前面已摘離的節點;去 style/script)
    if detail is not None:
        for tag in detail.select("style, script"):
            tag.decompose()
        summary = re.sub(r"\n{2,}", "\n", detail.get_text("\n")).strip()
        if summary:
            rec["summary"] = summary

    # 分類雙軌:完整清單原樣保存(路徑碼+名稱路徑,依選單順序)
    cats_sorted = sorted(cats, key=lambda c: CAT_ORDER.get(c, 999))
    rec["categories"] = [{"code": c, "path": CAT_NAME.get(c, c)} for c in cats_sorted]
    if cats_sorted:  # 平面後備欄(import 舊欄位相容)
        rec["category_source"] = cats_sorted[0]
        rec["category_text"] = CAT_NAME.get(cats_sorted[0], cats_sorted[0])

    return rec


# ── 主流程 ───────────────────────────────────────────────────

def probe():
    print("=== 探測模式 ===", flush=True)
    for path, name in (("10000/10052/10056", "書籍/神學研經"), ("10000/10053/10202", "聖經/和合本")):
        html = fetch_list(path, 1)
        if html is None:
            print(f"[{name}] 首頁抓取失敗!", flush=True)
            continue
        gids = extract_gids(html)
        print(f"[{name}] 宣稱 {extract_last_page(html)} 頁,第1頁 gid {len(gids)} 個:{gids[:5]}", flush=True)
        # 分頁驗證:第 2 頁(setid=24)首件必須與第 1 頁不同
        html2 = fetch_list(path, 2)
        gids2 = extract_gids(html2) if html2 else []
        ok = bool(gids2) and gids2[0] != gids[0]
        print(f"[{name}] 第2頁 gid {len(gids2)} 個,首件 {gids2[:1]} → 分頁{'正常' if ok else '異常!'}", flush=True)
        if gids:
            rec = parse_product(gids[0], [path])
            print(json.dumps(rec, ensure_ascii=False, indent=1)[:1500], flush=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--only", help="只走名稱含此字串的分類")
    ap.add_argument("--browser-ua", action="store_true", help="改用瀏覽器式 UA(仍帶 From 聯絡標頭)")
    args = ap.parse_args()

    if args.browser_ua:
        session.headers["User-Agent"] = BROWSER_UA

    if args.probe:
        probe()
        return

    DATA.mkdir(exist_ok=True)
    writer = JsonlWriter(DATA / "elim_books.jsonl", key_field="gid")

    print("== 第一階段:走訪分類列表 ==", flush=True)
    gid_cats = walk_categories(args.only)
    todo = [g for g in gid_cats if g not in writer.seen]
    print(f"共 {len(gid_cats)} 個 gid,待抓 {len(todo)}", flush=True)

    print("== 第二階段:抓商品頁 ==", flush=True)
    done = 0
    for gid in todo:
        rec = parse_product(gid, gid_cats[gid])
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
