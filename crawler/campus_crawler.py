# -*- coding: utf-8 -*-
"""校園書房 shop.campus.org.tw 爬蟲(單執行緒、節流 3-5 秒、快取續跑)。

流程:
 1. 首頁選單抽分類 → 遞迴走訪分類頁發現下層 → 取葉分類
 2. 逐分類列舉 ProductsList.aspx 各頁 → 收 ProductID(postback 或 GET 分頁,自動偵測)
 3. 逐商品抓 ProductDetails.aspx → 解析欄位 → data/campus_books.jsonl

用法(Windows,先 pip install -r requirements.txt):
  python -X utf8 campus_crawler.py --probe                # 探測(含分頁診斷)
  python -X utf8 campus_crawler.py --probe --category 05  # 探測指定分類的分頁機制
  python -X utf8 campus_crawler.py                        # 全量(可隨時 Ctrl+C,重跑自動續)
  python -X utf8 campus_crawler.py --category 0402        # 只抓指定分類
  python -X utf8 campus_crawler.py --limit 50             # 最多抓 50 本(試跑)

robots.txt:允許 ProductsList/ProductDetails;禁 SearchResults(不使用)。
(7/12 二修:中文標籤容忍字間空白「頁 數」、GET 分頁自動偵測、移除誤抓側欄的 breadcrumb)
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


def cjk(label: str) -> str:
    """中文標籤容忍字間空白:「頁數」→「頁\\s*數」(7/12 probe 證實站方排版夾空白)。"""
    return r"\s*".join(map(re.escape, label))


# ── 1. 分類樹 ────────────────────────────────────────────────

def get_leaf_categories() -> list[str]:
    """首頁 CategoryID 起步,遞迴走訪分類頁發現下層分類(頁面有快取,不浪費)。
    回傳葉分類(沒有更長前綴延伸者)。"""
    html = fetch(BASE + "/")
    ids = set(re.findall(r"ProductsList\.aspx\?CategoryID=(\d+)", html, re.I))
    if not ids:
        sys.exit("找不到任何 CategoryID —— 首頁結構可能已改,請跑 --probe 檢查")
    frontier = sorted(ids)
    while frontier:
        cid = frontier.pop(0)
        page = fetch(f"{BASE}/ProductsList.aspx?CategoryID={cid}")
        new = set(re.findall(r"ProductsList\.aspx\?CategoryID=(\d+)", page, re.I)) - ids
        if new:
            ids |= new
            frontier.extend(sorted(new))
    leaves = sorted(i for i in ids if not any(j != i and j.startswith(i) for j in ids))
    print(f"分類:共 {len(ids)} 個,葉分類 {len(leaves)} 個")
    return leaves


# ── 2. 分類列表頁(postback 或 GET 分頁) ────────────────────

def extract_product_ids(html: str) -> list[str]:
    return list(dict.fromkeys(re.findall(r"ProductDetails\.aspx\?ProductID=(\d+)", html, re.I)))


def extract_pager(html: str) -> tuple[str | None, int]:
    """回傳 (postback 控制項名, 最大頁碼)。找不到分頁=單頁或 GET 分頁。"""
    targets = re.findall(r"__doPostBack\('([^']+)','Page\$(\d+)'\)", html)
    # href 內的引號常被編成 &#39; 或 &quot;
    targets += re.findall(r"__doPostBack\(&#39;([^&]+)&#39;,&#39;Page\$(\d+)&#39;\)", html)
    targets += re.findall(r"__doPostBack\(&quot;([^&]+)&quot;,&quot;Page\$(\d+)&quot;\)", html)
    if not targets:
        return None, 1
    control = targets[0][0]
    max_page = max(int(p) for _, p in targets)
    m = re.search(cjk("共") + r"\s*(\d+)\s*" + cjk("頁"), html)
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


GET_PAGE_PARAMS = ("Page", "page", "PageIndex", "PageNo", "pg")
MAX_PAGES = 500  # 安全上限


def detect_get_page_param(url: str, first_ids: list[str]) -> str | None:
    """試探 GET 分頁參數:第 2 頁需非空且異於第 1 頁。"""
    for p in GET_PAGE_PARAMS:
        ids2 = extract_product_ids(fetch(f"{url}&{p}=2"))
        if ids2 and set(ids2) != set(first_ids):
            print(f"  GET 分頁參數偵測成功:&{p}=N")
            return p
    return None


def crawl_category(cat_id: str, writer_ids: set[str], state: State) -> list[str]:
    """列舉一個分類所有頁,回傳尚未入庫的 product_id。"""
    url = f"{BASE}/ProductsList.aspx?CategoryID={cat_id}"
    html = fetch(url)
    pids = extract_product_ids(html)
    control, max_page = extract_pager(html)
    pages_done = 1

    if control:  # WebForms postback 分頁
        page = 2
        cur_html = html
        while page <= max_page and page <= MAX_PAGES:
            post = build_postback(cur_html, control, page)
            cur_html = fetch(url, post_data=post)
            got = extract_product_ids(cur_html)
            if not got or not set(got) - set(pids):
                break
            pids.extend(got)
            nc, nm = extract_pager(cur_html)
            control = nc or control
            max_page = max(max_page, nm)
            pages_done = page
            page += 1
    elif len(pids) >= 24:  # 頁面近滿=可能有下頁 → 試 GET 分頁
        param = state.data.get("get_page_param") or detect_get_page_param(url, pids)
        if param:
            if state.data.get("get_page_param") != param:
                state.data["get_page_param"] = param
                state.save()
            page = 2
            while page <= MAX_PAGES:
                got = extract_product_ids(fetch(f"{url}&{param}={page}"))
                new = [x for x in got if x not in pids]
                if not new:
                    break
                pids.extend(new)
                pages_done = page
                page += 1

    pids = list(dict.fromkeys(pids))
    print(f"  分類 {cat_id}:{len(pids)} 本,{pages_done} 頁")
    return [p for p in pids if p not in writer_ids]


# ── 3. 商品頁解析 ────────────────────────────────────────────

# 在「去標籤純文字」上比對;中文標籤容忍字間空白
DETAIL_FIELDS = {
    "item_no": cjk("原書號") + r"[::]\s*(\S+)",
    "isbn": r"ISBN[::]\s*([0-9Xx\-]+)",
    "publish_date": cjk("出版日期") + r"[::]\s*([\d/.\-年月日]+)",
    "page_count": cjk("頁數") + r"[::]\s*(\d+)",
    "dimensions": cjk("尺寸") + r"[::]\s*([^\n]+)",
    "language": cjk("語言") + r"[::]\s*([^\n]+)",
    "binding": cjk("裝訂") + r"[::]\s*([^\n]+)",
    "audience": cjk("適用對象") + r"[::]\s*([^\n]+)",
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

    # meta keywords = 商品ID,書名,英文書名,出版社,作者,ISBN,1(name 大小寫不定;聖經類常缺)
    mk = soup.find("meta", attrs={"name": re.compile(r"^keywords$", re.I)})
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

    text = soup.get_text("\n")
    for key, pattern in DETAIL_FIELDS.items():
        m = re.search(pattern, text)
        if m:
            rec[key] = m.group(1).strip()

    # 詳細資料的「分類」(保留原值,映射 CategoryV11 於匯入階段處理)
    m = re.search(cjk("分類") + r"[::]\s*([^\n]+)", text)
    if m:
        rec["category_text"] = m.group(1).strip()

    # 價格:定價/優惠價
    m = re.search(cjk("定價") + r"[::]?\s*(?:NT\$|NT|\$)?\s*([\d,]+)", text)
    if m:
        rec["price_list"] = m.group(1).replace(",", "")
    m = re.search(rf"(?:{cjk('優惠價')}|{cjk('特價')})[::]?\s*(?:NT\$|NT|\$)?\s*([\d,]+)", text)
    if m:
        rec["price_sale"] = m.group(1).replace(",", "")

    return rec


# ── 主流程 ───────────────────────────────────────────────────

def probe(cat: str | None = None):
    print("=== 探測模式 ===")
    if not cat:
        html = fetch(BASE + "/")
        ids = sorted(set(re.findall(r"ProductsList\.aspx\?CategoryID=(\d+)", html, re.I)))
        print(f"首頁分類 {len(ids)} 個(全量時會再遞迴發現下層)")
        cat = ids[0]
    url = f"{BASE}/ProductsList.aspx?CategoryID={cat}"
    html = fetch(url)
    pids = extract_product_ids(html)
    control, max_page = extract_pager(html)
    print(f"分類 {cat}:本頁 {len(pids)} 本;postback 分頁控制項={control!r},最大頁碼={max_page}")

    if control is None:
        # 分頁診斷:列出頁面所有 postback 目標與含「頁/筆+數字」的文字
        targets = sorted(set(re.findall(r"__doPostBack\('([^']+)'", html)))
        print(f"頁面 postback 目標({len(targets)} 個):{targets[:15]}")
        text = BeautifulSoup(html, "lxml").get_text("\n")
        hits = [ln.strip() for ln in text.splitlines()
                if re.search(r"\d", ln) and re.search(r"[頁筆]", ln)]
        print(f"含「頁/筆+數字」的文字:{hits[:10]}")
        p = detect_get_page_param(url, pids)
        print(f"GET 分頁參數:{p!r}")
    elif max_page > 1:
        post = build_postback(html, control, 2)
        pids2 = extract_product_ids(fetch(url, post_data=post))
        print(f"第 2 頁 postback 測試:{len(pids2)} 本;與第 1 頁相同={set(pids2) == set(pids)}")

    if pids:
        rec = parse_product(pids[0], cat)
        print("商品頁解析結果:")
        print(json.dumps(rec, ensure_ascii=False, indent=2))
    print("\n請把以上輸出貼回給 Claude 檢查解析是否正確。")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true", help="只探測一頁並印解析結果")
    ap.add_argument("--category", help="只抓指定 CategoryID(配 --probe 則探測該分類)")
    ap.add_argument("--limit", type=int, default=0, help="最多抓 N 本(0=不限)")
    args = ap.parse_args()

    if args.probe:
        probe(args.category)
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
            todo = crawl_category(cat, writer.seen, state)
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
