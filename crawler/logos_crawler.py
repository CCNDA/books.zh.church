# -*- coding: utf-8 -*-
"""基道 BookFinder www.logos.com.hk 爬蟲(單執行緒、節流 2-3 秒、快取續跑)。

流程:
 1. 逐年檢索(field=year)列舉商品 code;分頁參數 &page=N(7/12 probe 證實)
 2. 逐商品抓 op=show 頁 → 解析欄位 → data/logos_books.jsonl

用法(Windows):
  python -X utf8 logos_crawler.py --probe             # 探測:抓 1 年列表 + 1 商品頁
  python -X utf8 logos_crawler.py                     # 全量 1950-2026(可 Ctrl+C 續跑)
  python -X utf8 logos_crawler.py --years 2024,2025   # 只抓指定年份
  python -X utf8 logos_crawler.py --limit 50          # 試跑 50 本

robots.txt 無限制;公益小站,保守節流。
(7/12 二修:中文標籤容忍字間空白、probe 加欄位文字診斷;
 已證實:標題去站名前綴、封面補協定、出版社取自 keywords、code 即 ISBN13)
"""
from __future__ import annotations

import argparse
import json
import re
import time
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import quote

from bs4 import BeautifulSoup

from common import JsonlWriter, State, make_session, polite_fetch

BASE = "https://www.logos.com.hk"
SEARCH = BASE + "/bf/acms/content.asp?site=logosbf&op=search&type=product&match=like"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "logos"
DATA = HERE / "data"
THROTTLE = (2.0, 3.0)

session = make_session()


def fetch(url: str, force: bool = False) -> str:
    return polite_fetch(session, url, CACHE, THROTTLE, force=force)


def cjk(label: str) -> str:
    """中文標籤容忍字間空白。"""
    return r"\s*".join(map(re.escape, label))


# ── 1. 年度列表與分頁 ────────────────────────────────────────

def extract_codes(html: str) -> list[str]:
    codes = re.findall(r"op=show&(?:amp;)?type=product&(?:amp;)?code=([A-Za-z0-9\-]+)", html)
    codes += re.findall(r"/link/\?code=([A-Za-z0-9\-]+)", html)
    return list(dict.fromkeys(codes))


def extract_total(html: str) -> int | None:
    m = re.search(cjk("找到") + r"\s*([\d,]+)\s*" + cjk("項"), html)
    return int(m.group(1).replace(",", "")) if m else None


def year_url(year: int) -> str:
    return f"{SEARCH}&field=year&text={year}"


PAGE_PARAM_CANDIDATES = ("page", "pageno", "start", "offset", "curpage")


def detect_page_param(year: int, first_html: str, first_codes: list[str]) -> str | None:
    """探測分頁參數:第 2 頁 code 集合需非空且異於第 1 頁。"""
    for param in PAGE_PARAM_CANDIDATES:
        html2 = fetch(year_url(year) + f"&{param}=2")
        codes2 = extract_codes(html2)
        if codes2 and codes2 != first_codes:
            print(f"  分頁參數偵測成功:&{param}=N")
            return param
    return None


def crawl_year(year: int, page_param: str | None, seen: set[str]) -> tuple[list[str], str | None]:
    html = fetch(year_url(year))
    codes = extract_codes(html)
    total = extract_total(html)
    print(f"年份 {year}:首頁 {len(codes)} 項,宣稱共 {total} 項")
    if total and total > len(codes):
        if page_param is None:
            page_param = detect_page_param(year, html, codes)
            if page_param is None:
                print(f"  [警告] 找不到分頁參數,{year} 只收到首頁 {len(codes)} 項")
        if page_param:
            per_page = max(len(codes), 1)
            pages = -(-total // per_page)
            for p in range(2, pages + 1):
                got = extract_codes(fetch(year_url(year) + f"&{page_param}={p}"))
                if not got:
                    break
                before = len(codes)
                codes = list(dict.fromkeys(codes + got))
                if len(codes) == before:  # 重複頁=到底了
                    break
    return [c for c in codes if c not in seen], page_param


# ── 2. 商品頁解析 ────────────────────────────────────────────

def parse_product(code: str) -> dict | None:
    url = f"{BASE}/bf/acms/content.asp?site=logosbf&op=show&type=product&code={quote(code)}"
    html = fetch(url)
    soup = BeautifulSoup(html, "lxml")

    rec: dict = {
        "code": code,
        "source": "logos",
        "source_url": url,
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }

    for name, key in (("author", "authors_raw"), ("description", "summary"), ("keywords", "keywords")):
        el = soup.find("meta", attrs={"name": re.compile(rf"^{name}$", re.I)})
        if el and el.get("content"):
            rec[key] = el["content"].strip()

    for prop, key in (("og:title", "title"), ("og:image", "cover_url")):
        el = soup.find("meta", attrs={"property": prop})
        if el and el.get("content"):
            rec[key] = el["content"].strip()

    if not rec.get("title"):
        t = soup.find("title")
        if t:
            rec["title"] = t.get_text(strip=True) or None
    # 去站名前綴「基道 BOOKFINDER - 」(7/12 probe 證實 og:title 帶前綴)
    if rec.get("title"):
        rec["title"] = re.sub(r"^基道\s*BOOKFINDER\s*[-–—|]\s*", "", rec["title"]).strip() or None
    if not rec.get("title"):
        print(f"  [略過] {code} 無書名")
        return None

    # 封面:協定相對網址補 https:
    if rec.get("cover_url", "").startswith("//"):
        rec["cover_url"] = "https:" + rec["cover_url"]

    # meta keywords = 書名,作者,出版社(7/12 probe 證實)→ 取出版社
    if rec.get("keywords"):
        parts = [p.strip() for p in rec["keywords"].split(",")]
        if len(parts) >= 3 and parts[2]:
            rec["publisher"] = parts[2]

    # 標籤欄位在「去標籤純文字」上比對;中文標籤容忍字間空白
    text = soup.get_text("\n")
    patterns = {
        "title_en": rf"(?:{cjk('英文書名')}|English\s*Title)[:：]\s*([^\n]+)",
        "publisher": cjk("出版") + r"[社商]?[:：]\s*([^\n]+)",
        "publish_date": cjk("出版日期") + r"[:：]\s*([\d/.\-年月日]+)",
        "isbn": r"ISBN[:：]\s*([0-9Xx\-]+)",
        "page_count": cjk("頁數") + r"[:：]\s*(\d+)",
        "binding": cjk("裝訂") + r"[:：]\s*([^\n]+)",
        "stock": rf"(?:{cjk('庫存')}|{cjk('供應狀態')})[:：]\s*([^\n]+)",
    }
    for key, pat in patterns.items():
        if key in rec:
            continue
        m = re.search(pat, text)
        if m:
            rec[key] = m.group(1).strip()

    # 書碼本身常是 ISBN13
    if "isbn" not in rec and re.fullmatch(r"97[89]\d{10}", code):
        rec["isbn"] = code

    # 價格:現價/原價(HK$)
    m = re.search(rf"(?:{cjk('原價')}|{cjk('定價')})[:：]?\s*(?:HK\$|\$)?\s*([\d,.]+)", text)
    if m:
        rec["price_list"] = m.group(1).replace(",", "")
    m = re.search(rf"(?:{cjk('現價')}|{cjk('售價')}|{cjk('特價')})[:：]?\s*(?:HK\$|\$)?\s*([\d,.]+)", text)
    if m:
        rec["price_sale"] = m.group(1).replace(",", "")
    rec.setdefault("currency", "HKD")

    return rec


# ── 主流程 ───────────────────────────────────────────────────

def probe():
    print("=== 探測模式 ===")
    year = 2021  # 偵察時已知 2021 約 3,800 筆
    html = fetch(year_url(year))
    codes = extract_codes(html)
    total = extract_total(html)
    print(f"年份 {year}:首頁 {len(codes)} 項,宣稱共 {total} 項")
    param = detect_page_param(year, html, codes) if codes else None
    print(f"分頁參數:{param!r}")
    if codes:
        rec = parse_product(codes[0])
        print("商品頁解析結果:")
        print(json.dumps(rec, ensure_ascii=False, indent=2))
        # 欄位診斷:印出商品頁上與出版/頁數/裝訂/價格相關的原始文字
        url = f"{BASE}/bf/acms/content.asp?site=logosbf&op=show&type=product&code={quote(codes[0])}"
        text = BeautifulSoup(fetch(url), "lxml").get_text("\n")
        hits = [ln.strip() for ln in text.splitlines()
                if ln.strip() and re.search(r"(出\s*版|頁|ISBN|裝|價|庫\s*存)", ln)]
        print(f"欄位相關原始文字(前 15 行):{json.dumps(hits[:15], ensure_ascii=False, indent=1)}")
    print("\n請把以上輸出貼回給 Claude 檢查解析是否正確。")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true")
    ap.add_argument("--years", help="逗號分隔年份,如 2024,2025;預設 2026 倒抓到 1950")
    ap.add_argument("--limit", type=int, default=0)
    args = ap.parse_args()

    if args.probe:
        probe()
        return

    writer = JsonlWriter(DATA / "logos_books.jsonl", "code")
    state = State(DATA / "logos_state.json")
    years = ([int(y) for y in args.years.split(",")] if args.years
             else list(range(2026, 1949, -1)))
    page_param = state.data.get("page_param")

    total_new = 0
    start = time.time()
    try:
        for year in years:
            if state.is_done(f"year:{year}") and not args.years:
                continue
            todo, page_param = crawl_year(year, page_param, writer.seen)
            if page_param and state.data.get("page_param") != page_param:
                state.data["page_param"] = page_param
                state.save()
            for code in todo:
                rec = parse_product(code)
                if rec and writer.write(rec):
                    total_new += 1
                    if total_new % 20 == 0:
                        rate = total_new / max(time.time() - start, 1) * 3600
                        print(f"  進度:+{total_new} 本(約 {rate:.0f} 本/小時),累計 {len(writer.seen)}")
                if args.limit and total_new >= args.limit:
                    print(f"達 --limit {args.limit},停止")
                    return
            state.mark_done(f"year:{year}")
    except KeyboardInterrupt:
        print("\n[中斷] 進度已保存,重跑同指令即續抓")
    finally:
        writer.close()
        print(f"本次新增 {total_new} 本;logos_books.jsonl 累計 {len(writer.seen)} 本")


if __name__ == "__main__":
    main()
