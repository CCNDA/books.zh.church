# -*- coding: utf-8 -*-
"""校園書房 shop.campus.org.tw 爬蟲(單執行緒、節流 5-8 秒、快取續跑)。

流程:
 1. 首頁選單抽分類 → 遞迴走訪分類頁發現下層 → 取葉分類
 2. 逐分類列舉 productslist.aspx 各頁 → 收 ProductID
    分頁=rptCounter$ctlNN$LinkButton1 postback,EVENTARGUMENT 空字串(7/12 瀏覽器實測)
    大分類頁(2 碼)是櫥窗頁無完整清單,靠遞迴取葉分類補齊
 3. 逐商品抓 ProductDetails.aspx → 解析欄位 → data/campus_books.jsonl
    詳細資料格式(7/12 原始 HTML 實測):原書號：A1091<br/>ISBN：9789575878580<br/>
    出版日期：20040819<br/>頁數：400<br/>尺寸：14.8 x 21cm<br/>重量：440克<br/>...全形冒號

容錯(7/13):校園主機較弱、偶發 500——
 - 重試中 500/503 視為過載,等 60 秒×次數再試(common.polite_fetch)
 - 單一分類/商品重試耗盡僅跳過並記錄,不中斷全量;未完成分類不標 done,重跑自動補

用法(Windows,先 pip install -r requirements.txt):
  python -X utf8 campus_crawler.py --probe --category 0501  # 探測(0501=47 本 2 頁,可驗分頁)
  python -X utf8 campus_crawler.py --probe --fresh          # 忽略快取重抓(排除壞快取)
  python -X utf8 campus_crawler.py                          # 全量(可 Ctrl+C,重跑自動續)
  python -X utf8 campus_crawler.py --limit 50               # 試跑

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
THROTTLE = (5.0, 8.0)  # 校園主機較弱,放慢(7/13 前為 3-5 秒仍見 500)
MAX_PAGES = 500  # 安全上限

session = make_session()

# 站方 WAF 若擋機器人 UA,以 --browser-ua 換用瀏覽器式 UA(From 標頭仍留聯絡方式)
BROWSER_UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
              "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36")


def warmup(reset: bool = False):
    """強制重抓首頁一次,取得 ASP.NET session cookies(快取命中不會發請求、拿不到 cookie)。
    reset=True 先清空 cookies 換一個全新 session(列表頁被站方 session 級封鎖時用)。"""
    try:
        if reset:
            session.cookies.clear()
            print("清空 cookies,換新 session...", flush=True)
        polite_fetch(session, BASE + "/", CACHE, (1.0, 2.0), force=True)
        ck = list(session.cookies.keys())
        print(f"暖身完成,cookies:{ck if ck else '無'}", flush=True)
    except RuntimeError as e:
        print(f"[暖身失敗,續行] {e}", flush=True)


def fetch(url: str, post_data: dict | None = None, force: bool = False) -> str:
    return polite_fetch(session, url, CACHE, THROTTLE, post_data=post_data, force=force)


def cjk(label: str) -> str:
    """中文標籤容忍字間空白(站內選單有「頁 數」式排版)。"""
    return r"\s*".join(map(re.escape, label))


# ── 1. 分類樹 ────────────────────────────────────────────────

def get_leaf_categories() -> list[str]:
    """首頁 CategoryID 起步,遞迴走訪分類頁發現下層分類(頁面有快取,不浪費)。
    回傳葉分類(沒有更長前綴延伸者);大分類櫥窗頁不列舉商品。
    走訪失敗的分類頁只警告跳過(其下層可能因此漏抓,重跑可補)。"""
    html = fetch(BASE + "/")
    ids = set(re.findall(r"[Pp]roducts[Ll]ist\.aspx\?CategoryID=(\d+)", html))
    if not ids:
        sys.exit("找不到任何 CategoryID —— 首頁結構可能已改,請跑 --probe 檢查")
    print(f"首頁發現 {len(ids)} 個分類,開始遞迴走訪(每頁 5-8 秒,已抓過的走快取)...", flush=True)
    frontier = sorted(ids)
    visited = 0
    failed = 0
    while frontier:
        cid = frontier.pop(0)
        try:
            page = fetch(f"{BASE}/productslist.aspx?CategoryID={cid}")
        except RuntimeError as e:
            failed += 1
            print(f"  [跳過分類頁 {cid}] {e}", flush=True)
            continue
        visited += 1
        new = set(re.findall(r"[Pp]roducts[Ll]ist\.aspx\?CategoryID=(\d+)", page)) - ids
        if new:
            ids |= new
            frontier.extend(sorted(new))
        if visited % 10 == 0:
            print(f"  已走訪 {visited} 個分類頁,累計發現 {len(ids)} 個分類,待訪 {len(frontier)}", flush=True)
    leaves = sorted(i for i in ids if not any(j != i and j.startswith(i) for j in ids))
    note = f"(走訪失敗 {failed} 頁,重跑可補)" if failed else ""
    print(f"分類:共 {len(ids)} 個,葉分類 {len(leaves)} 個{note}")
    return leaves


# ── 2. 分類列表頁(rptCounter postback 分頁) ────────────────

def extract_product_ids(html: str) -> list[str]:
    return list(dict.fromkeys(re.findall(r"ProductDetails\.aspx\?ProductID=(\d+)", html, re.I)))


def extract_counter_pager(html: str) -> tuple[dict[int, str], int, int | None]:
    """回傳 (頁碼→控制項名 對照, 總頁數, 總件數)。
    分頁控制項:rptCounter$ctlNN$LinkButton1,NN=頁碼-1(7/12 實測)。"""
    pairs = re.findall(
        r"__doPostBack\((?:'|&#39;|&quot;)([^'&]*rptCounter\$ctl(\d+)\$LinkButton1)(?:'|&#39;|&quot;)",
        html)
    mapping = {int(idx) + 1: name for name, idx in pairs}
    text = BeautifulSoup(html, "lxml").get_text("\n")
    m = re.search(cjk("共") + r"\s*(\d+)\s*" + cjk("頁"), text)
    total_pages = int(m.group(1)) if m else (max(mapping) if mapping else 1)
    m = re.search(cjk("有") + r"\s*(\d+)\s*" + cjk("個"), text)
    total_items = int(m.group(1)) if m else None
    return mapping, total_pages, total_items


def build_postback(html: str, target: str, argument: str = "") -> dict:
    soup = BeautifulSoup(html, "lxml")
    data = {}
    for name in ("__VIEWSTATE", "__VIEWSTATEGENERATOR", "__EVENTVALIDATION", "__VIEWSTATEENCRYPTED"):
        el = soup.find("input", {"name": name})
        if el is not None:
            data[name] = el.get("value", "")
    data["__EVENTTARGET"] = target
    data["__EVENTARGUMENT"] = argument
    return data


def crawl_category(cat_id: str, writer_ids: set[str]) -> list[str]:
    """列舉一個分類所有頁,回傳尚未入庫的 product_id。"""
    url = f"{BASE}/productslist.aspx?CategoryID={cat_id}"
    html = fetch(url)
    pids = extract_product_ids(html)
    mapping, total_pages, total_items = extract_counter_pager(html)
    pages_done = 1

    page = 2
    cur_html = html
    while page <= min(total_pages, MAX_PAGES):
        target = mapping.get(page)
        if not target:
            print(f"  [警告] 分類 {cat_id} 第 {page} 頁找不到分頁控制項,提前結束")
            break
        cur_html = fetch(url, post_data=build_postback(cur_html, target))
        got = extract_product_ids(cur_html)
        new = [x for x in got if x not in pids]
        if not new:
            break
        pids.extend(new)
        mapping, nt, _ = extract_counter_pager(cur_html)
        total_pages = max(total_pages, nt)
        pages_done = page
        page += 1

    pids = list(dict.fromkeys(pids))
    note = f"(站方宣稱 {total_items} 件)" if total_items else ""
    print(f"  分類 {cat_id}:{len(pids)} 本,{pages_done}/{total_pages} 頁{note}")
    return [p for p in pids if p not in writer_ids]


# ── 3. 商品頁解析 ────────────────────────────────────────────

# 詳細資料實測格式:全形冒號、<br/> 分隔;在「去標籤純文字」上比對,標籤容忍字間空白
DETAIL_FIELDS = {
    "item_no": cjk("原書號") + r"[:：][ \t]*(\S+)",
    "isbn": r"ISBN[:：][ \t]*([0-9Xx\-]+)",
    "publish_date": cjk("出版日期") + r"[:：][ \t]*([\d/.\-年月日]+)",
    "page_count": cjk("頁數") + r"[:：][ \t]*(\d+)",
    "dimensions": cjk("尺寸") + r"[:：][ \t]*([^\n]+)",
    "weight": cjk("重量") + r"[:：][ \t]*([^\n]+)",
    "layout": cjk("排版方式") + r"[:：][ \t]*([^\n]+)",
    "language": cjk("語言") + r"[:：][ \t]*([^\n]+)",
    "binding": cjk("裝訂方式") + r"[:：][ \t]*([^\n]+)",
    "printing": cjk("印刷方式") + r"[:：][ \t]*([^\n]+)",
    "audience": cjk("適用對象") + r"[:：][ \t]*([^\n]+)",
    "series_text": cjk("書系") + r"[:：][ \t]*([^\n]+)",
}


def parse_product(pid: str, cat_id: str | None, force: bool = False) -> dict | None:
    url = f"{BASE}/ProductDetails.aspx?ProductID={pid}"
    html = fetch(url, force=force)
    soup = BeautifulSoup(html, "lxml")

    rec: dict = {
        "product_id": pid,
        "source": "campus",
        "source_url": url,
        "category_source": cat_id,
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }

    # meta keywords = 商品ID,書名,英文書名,出版社,作者,ISBN,1(聖經類常缺,og 後備)
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

    # 作者/出版社後備(meta keywords 缺時,詳細資料區有 作者：/出版社：)
    if not rec.get("authors_raw"):
        m = re.search(cjk("作者") + r"[:：][ \t]*([^\n]+)", text)
        if m:
            rec["authors_raw"] = m.group(1).strip()
    if not rec.get("publisher"):
        m = re.search(cjk("出版社") + r"[:：][ \t]*([^\n]+)", text)
        if m:
            rec["publisher"] = m.group(1).strip()

    # 詳細資料的「分類」(保留原值,映射 CategoryV11 於匯入階段處理)
    m = re.search(cjk("分類") + r"[:：][ \t]*([^\n]+)", text)
    if m:
        rec["category_text"] = m.group(1).strip()

    # 價格:定價/特價(頁面顯示「特價 NT 411」+ 刪除線原價)
    m = re.search(cjk("定價") + r"[:：]?[ \t]*(?:NT\$?|\$)?[ \t]*([\d,]+)", text)
    if m:
        rec["price_list"] = m.group(1).replace(",", "")
    m = re.search(rf"(?:{cjk('優惠價')}|{cjk('特價')})[:：]?[ \t]*(?:NT\$?|\$)?[ \t]*([\d,]+)", text)
    if m:
        rec["price_sale"] = m.group(1).replace(",", "")

    return rec


# ── 主流程 ───────────────────────────────────────────────────

def probe(cat: str | None = None, fresh: bool = False):
    print("=== 探測模式 ===")
    cat = cat or "0501"  # 已知 47 本 2 頁,可驗證翻頁
    url = f"{BASE}/productslist.aspx?CategoryID={cat}"
    html = fetch(url, force=fresh)
    pids = extract_product_ids(html)
    mapping, total_pages, total_items = extract_counter_pager(html)
    print(f"分類 {cat}:第 1 頁 {len(pids)} 本;總頁數={total_pages},站方宣稱 {total_items} 件")
    print(f"分頁控制項對照(前 5):{dict(list(mapping.items())[:5])}")

    if 2 in mapping:
        html2 = fetch(url, post_data=build_postback(html, mapping[2]))
        pids2 = extract_product_ids(html2)
        overlap = len(set(pids2) & set(pids))
        print(f"第 2 頁 postback 測試:{len(pids2)} 本,與第 1 頁重疊 {overlap} 本(重疊少=翻頁成功)")

    if pids:
        rec = parse_product(pids[0], cat, force=fresh)
        print("商品頁解析結果:")
        print(json.dumps(rec, ensure_ascii=False, indent=2))
        # 欄位診斷:印出商品頁純文字中含關鍵標籤的行
        text = BeautifulSoup(fetch(f"{BASE}/ProductDetails.aspx?ProductID={pids[0]}"),
                             "lxml").get_text("\n")
        hits = [ln.strip() for ln in text.splitlines()
                if ln.strip() and re.search(r"(ISBN|原書號|出版日期|頁數|裝訂|定價|特價)", ln)]
        print(f"欄位相關原始文字:{json.dumps(hits[:12], ensure_ascii=False, indent=1)}")
    print("\n請把以上輸出貼回給 Claude 檢查解析是否正確。")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true", help="只探測一分類並印解析結果")
    ap.add_argument("--category", help="只抓指定 CategoryID(配 --probe 則探測該分類)")
    ap.add_argument("--fresh", action="store_true", help="probe 時忽略快取重抓")
    ap.add_argument("--limit", type=int, default=0, help="最多抓 N 本(0=不限)")
    ap.add_argument("--browser-ua", action="store_true",
                    help="改用瀏覽器式 UA(站方擋機器人 UA 時用;節流照舊)")
    args = ap.parse_args()

    if args.browser_ua:
        session.headers["User-Agent"] = BROWSER_UA
        print("改用瀏覽器式 UA")
    warmup()

    if args.probe:
        probe(args.category, args.fresh)
        return

    writer = JsonlWriter(DATA / "campus_books.jsonl", "product_id")
    state = State(DATA / "campus_state.json")
    cats = [args.category] if args.category else get_leaf_categories()

    total_new = 0
    failed_cats: list[str] = []
    start = time.time()
    try:
        for cat in cats:
            if state.is_done(f"cat:{cat}") and not args.category:
                continue
            try:
                todo = crawl_category(cat, writer.seen)
            except RuntimeError:
                print(f"  [分類 {cat} 失敗] 換新 session 冷卻 120 秒後重試一次...")
                time.sleep(120)
                warmup(reset=True)
                try:
                    todo = crawl_category(cat, writer.seen)
                except RuntimeError as e:
                    print(f"  [跳過分類 {cat},下次重跑補抓] {e}")
                    failed_cats.append(cat)
                    continue
            cat_ok = True
            for pid in todo:
                try:
                    rec = parse_product(pid, cat)
                except RuntimeError as e:
                    print(f"  [跳過商品 {pid},下次重跑補抓] {e}")
                    cat_ok = False
                    continue
                if rec and writer.write(rec):
                    total_new += 1
                    if total_new % 20 == 0:
                        rate = total_new / max(time.time() - start, 1) * 3600
                        print(f"  進度:+{total_new} 本(約 {rate:.0f} 本/小時),累計 {len(writer.seen)}")
                if args.limit and total_new >= args.limit:
                    print(f"達 --limit {args.limit},停止")
                    return
            if cat_ok:
                state.mark_done(f"cat:{cat}")
            else:
                failed_cats.append(cat)
    except KeyboardInterrupt:
        print("\n[中斷] 進度已保存,重跑同指令即續抓")
    finally:
        writer.close()
        print(f"本次新增 {total_new} 本;campus_books.jsonl 累計 {len(writer.seen)} 本")
        if failed_cats:
            print(f"[注意] {len(failed_cats)} 個分類未完抓(未標記完成,重跑同指令會自動補):"
                  f"{', '.join(failed_cats)}")


if __name__ == "__main__":
    main()
