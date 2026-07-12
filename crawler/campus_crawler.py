# -*- coding: utf-8 -*-
"""校園書房 shop.campus.org.tw 爬蟲(單執行緒、節流 3-5 秒、快取續跑)。

流程:
 1. 首頁選單抽完整分類樹 → 取葉分類(最長碼)
 2. 逐分類列舉 ProductsList.aspx 各頁 → 收 ProductID(WebForms postback 分頁)
 3. 逐商品抓 ProductDetails.aspx → 解析欄位 → data/campus_books.jsonl

用法(Windows,先 pip install -r requirements.txt):
  python -X utf8 campus_crawler.py --probe          # 探測:抓 1 分類頁 + 1 商品頁,印解析結果
  python -X utf8 campus_crawler.py                  # 全量(可隨時 Ctrl+C,重跑自動續)
  python -X utf8 campus_crawler.py --category 0402  # 只抓指定分類
  python -X utf8 campus_crawler.py --limit 50       # 最多抓 50 本(試跑)

robots.txt:允許 ProductsList/ProductDetails;禁 SearchResults(不使用)。
"""
from __future__ import annotations

import argparse
import json
import re
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

from bs4 import BeautifulSoup

from common import JsonlWriter, State, make_session, polite_fetch

BASE = "https://shop.campus.org.tw"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "campus"
DATA = HERE / "data"
THROTTLE = (3.0, 5.0)

session = make_session()


def fetch(url: str, post_data: dict | None = None, force: bool = False) -> str:
    return polite_fetch(session, url, CACHE, THROTTLE, post_data=post_data, force=force)


# ── 1. 分類樹 ────────────────────────────────────────────────

def get_leaf_categories() -> list[str]:
    """從首頁選單收所有 CategoryID;回傳葉分類(沒有更長前綴延伸者)。"""
    html = fetch(BASE + "/")
    ids = set(re.findall(r"ProductsList\.aspx\?CategoryID=(\d+)", html, re.I))
    if not ids:
        sys.exit("找不到任何 CategoryID —— 首頁結構可能已改,請跑 --probe 檢查")
    leaves = sorted(i for i in ids if not any(j != i and j.startswith(i) for j in ids))
    print(f"分類:共 {len(ids)} 個,葉分類 {len(leaves)} 個")
    return leaves


# ── 2. 分類列表頁(WebForms postback 分頁) ──────────────────

def extract_product_ids(html: str) -> list[str]:
    return list(dict.fromkeys(re.findall(r"ProductDetails\.aspx\?ProductID=(\d+)", html, re.I)))


def extract_pager(html: str) -> tuple[str | None, int]:
    """回傳 (postback 控制項名, 最大頁碼)。找不到分頁=單頁。"""
    targets = re.findall(r"__doPostBack\('([^']+)','Page\$(\d+)'\)", html)
    if not targets:
        return None, 1
    control = targets[0][0]
    max_page = max(int(p) for _, p in targets)
    # 「...」尾頁連結可能藏更大頁碼;抓頁面上「共 N 頁 / 共 N 筆」佐證
    m = re.search(r"共\s*(\d+)\s*頁", html)
    if m:
        max_page = max(max_page, int(m.group(1)))
    return control, max_page


def build_postback(html: str, control: str, page: int) -> dict:
    soup = BeautifulSoup(html, "lxml")
    data = {}
    for name in ("__VIEWSTATE", "__VIEWSTATEGENERATOR", "__EVENTVALIDATION", "__VIEWSTATEENCRYPTED"):
        el = soup.find("input", {"name": name})
        if el is not None:
            data[name] = el.get("value", "")
    data["__EVENTTARGET"] = control
    data["__EVENTARGUMENT"] = f"Page${page}"
    return data


def crawl_category(cat_id: str, writer_ids: set[str]) -> list[str]:
    """列舉一個分類所有頁,回傳 (product_id, cat_id) 尚未入庫者。"""
    url = f"{BASE}/ProductsList.aspx?CategoryID={cat_id}"
    html = fetch(url)
    pids = extract_product_ids(html)
    control, max_page = extract_pager(html)
    page = 2
    cur_html = html
    while control and page <= max_page:
        post = build_postback(cur_html, control, page)
        cur_html = fetch(url, post_data=post)
        got = extract_product_ids(cur_html)
        if not got:
            break
        pids.extend(got)
        nc, nm = extract_pager(cur_html)
        control = nc or control
        max_page = max(max_page, nm)
        page += 1
    pids = list(dict.fromkeys(pids))
    print(f"  分類 {cat_id}:{len(pids)} 本,{page - 1} 頁")
    return [p for p in pids if p not in writer_ids]


# ── 3. 商品頁解析 ────────────────────────────────────────────

DETAIL_FIELDS = {
    "item_no": r"原書號[::]\s*([^<\s]+)",
    "isbn": r"ISBN[::]\s*([0-9Xx\-]+)",
    "publish_date": r"出版日期[::]\s*([\d/.\-年月日]+)",
    "page_count": r"頁數[::]\s*(\d+)",
    "dimensions": r"尺寸[::]\s*([^<]+?)(?:<|$)",
    "language": r"語言[::]\s*([^<\s]+)",
    "binding": r"裝訂[::]\s*([^<\s]+)",
    "audience": r"適用對象[::]\s*([^<]+?)(?:<|$)",
}


def parse_product(pid: str, cat_id: str | None) -> dict | None:
    url = f"{BASE}/ProductDetails.aspx?ProductID={pid}"
    html = fetch(url)
    soup = BeautifulSoup(html, "lxml")

    rec: dict = {
        "product_id": pid,
        "source": "campus",
        "source_url": url,
        "category_source": cat_id,
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }

    # meta keywords = 商品ID,書名,英文書名,出版社,作者,ISBN,1
    mk = soup.find("meta", attrs={"name": "keywords"})
    if mk and mk.get("content"):
        parts = [p.strip() for p in mk["content"].split(",")]
        if len(parts) >= 6:
            rec.update({
                "title": parts[1] or None,
                "title_en": parts[2] or None,
                "publisher": parts[3] or None,
                "authors_raw": parts[4] or None,
                "isbn_meta": parts[5] or None,
            })

    for prop, key in (("og:title", "og_title"), ("og:description", "summary"), ("og:image", "cover_url")):
        el = soup.find("meta", attrs={"property": prop})
        if el and el.get("content"):
            rec[key] = el["content"].strip()

    if not rec.get("title") and rec.get("og_title"):
        rec["title"] = rec["og_title"]
    if not rec.get("title"):
        print(f"  [略過] {pid} 無書名(可能下架/非書籍)")
        return None

    body = html
    for key, pattern in DETAIL_FIELDS.items():
        m = re.search(pattern, body)
        if m:
            rec[key] = m.group(1).strip()

    # 詳細資料的「分類」與麵包屑(保留原值,映射 CategoryV11 於匯入階段處理)
    m = re.search(r"分類[::]\s*([^<]+?)(?:<|$)", body)
    if m:
        rec["category_text"] = m.group(1).strip()
    crumbs = re.findall(r"ProductsList\.aspx\?CategoryID=\d+[^>]*>([^<]+)<", html)
    if crumbs:
        rec["breadcrumb"] = ">".join(c.strip() for c in crumbs[:5])

    # 價格(頁面常見:定價 NT$xxx / 優惠價)
    m = re.search(r"定價[::]?\s*(?:NT\$|NT|\$)?\s*([\d,]+)", body)
    if m:
        rec["price_list"] = m.group(1).replace(",", "")
    m = re.search(r"(?:優惠價|特價)[::]?\s*(?:NT\$|NT|\$)?\s*([\d,]+)", body)
    if m:
        rec["price_sale"] = m.group(1).replace(",", "")

    return rec


# ── 主流程 ───────────────────────────────────────────────────

def probe():
    print("=== 探測模式 ===")
    leaves = get_leaf_categories()
    cat = leaves[0]
    url = f"{BASE}/ProductsList.aspx?CategoryID={cat}"
    html = fetch(url)
    pids = extract_product_ids(html)
    control, max_page = extract_pager(html)
    print(f"分類 {cat}:本頁 {len(pids)} 本;分頁控制項={control!r},最大頁碼={max_page}")
    if pids:
        rec = parse_product(pids[0], cat)
        print("商品頁解析結果:")
        print(json.dumps(rec, ensure_ascii=False, indent=2))
    print("\n請把以上輸出貼回給 Claude 檢查解析是否正確。")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true", help="只探測一頁並印解析結果")
    ap.add_argument("--category", help="只抓指定 CategoryID")
    ap.add_argument("--limit", type=int, default=0, help="最多抓 N 本(0=不限)")
    args = ap.parse_args()

    if args.probe:
        probe()
        return

    writer = JsonlWriter(DATA / "campus_books.jsonl", "product_id")
    state = State(DATA / "campus_state.json")
    cats = [args.category] if args.category else get_leaf_categories()

    total_new = 0
    start = time.time()
    try:
        for cat in cats:
            if state.is_done(f"cat:{cat}") and not args.category:
                continue
            todo = crawl_category(cat, writer.seen)
            for pid in todo:
                rec = parse_product(pid, cat)
                if rec and writer.write(rec):
                    total_new += 1
                    if total_new % 20 == 0:
                        rate = total_new / max(time.time() - start, 1) * 3600
                        print(f"  進度:+{total_new} 本(約 {rate:.0f} 本/小時),累計 {len(writer.seen)}")
                if args.limit and total_new >= args.limit:
                    print(f"達 --limit {args.limit},停止")
                    return
            state.mark_done(f"cat:{cat}")
    except KeyboardInterrupt:
        print("\n[中斷] 進度已保存,重跑同指令即續抓")
    finally:
        writer.close()
        print(f"本次新增 {total_new} 本;campus_books.jsonl 累計 {len(writer.seen)} 本")


if __name__ == "__main__":
    main()
