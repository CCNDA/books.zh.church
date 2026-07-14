# -*- coding: utf-8 -*-
"""基道 BookFinder www.logos.com.hk 爬蟲(單執行緒、節流 2-3 秒、快取續跑)。

7/14 三修 —— 檢查 7/12 快取後的重要發現:
 1. 年份檢索 field=year&text=Y 並非「只列該年」,而是「Y 年(含)以後」的
    累積結果、按日期倒序:各年首頁都是同一批最新書;宣稱總數隨年份遞減而
    遞增(2001→21115、1968→24298≈全站),相鄰年份差值才是該年出版量。
    → 逐年迴圈完全多餘,改以最早錨定年(--anchor,預設 1)單次檢索
      列舉全站,分頁走到底。7/14 probe 實測:總數隨 anchor 遞減而遞增,
      至 anchor 1 = 1900 = 24,609 飽和(≈全站量;1950=24,359 仍未到底,
      推測含年份空值/預設值書),故預設壓到 1 確保無漏。
      約 24,609 項 ÷ 20/頁 ≈ 1,231 頁。
 2. 伺服器偶發以 HTTP 200 回「空殼頁」(僅網站框架、無檢索結果)且被永久
    快取,造成「首頁 0 項,宣稱共 None 項」→ 列表頁與商品頁現在都驗證
    內容,無效即 force 重抓一次。
 3. 啟動時先從既有快取離線收割書碼(18,000+ 頁是先前逐年重走留下的),
    不浪費已抓流量。
 4. 不再使用 logos_state.json(舊檔已因非原子寫入損毀;斷點續跑由頁面
    快取 + jsonl 既有 code 天然達成)。

用法(Windows):
  python -X utf8 logos_crawler.py --probe        # 驗證累積假設 + 解析 1 本
  python -X utf8 logos_crawler.py                # 全量 anchor 1(可 Ctrl+C 續跑)
  python -X utf8 logos_crawler.py --limit 50     # 試跑 50 本
  python -X utf8 logos_crawler.py --anchor 2020  # 只補近年新書(增量)

robots.txt 無限制;公益小站,保守節流。
(7/12 已證實:標題去站名前綴、封面補協定、出版社取自 keywords、code 即 ISBN13)
"""
from __future__ import annotations

import argparse
import gzip
import json
import re
import time
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import quote

from bs4 import BeautifulSoup

from common import JsonlWriter, make_session, polite_fetch

BASE = "https://www.logos.com.hk"
SEARCH = BASE + "/bf/acms/content.asp?site=logosbf&op=search&type=product&match=like"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "logos"
DATA = HERE / "data"
THROTTLE = (2.0, 3.0)
PAGE_PARAM = "page"  # 7/12 probe 證實

session = make_session()


def fetch(url: str, force: bool = False) -> str:
    return polite_fetch(session, url, CACHE, THROTTLE, force=force)


def cjk(label: str) -> str:
    """中文標籤容忍字間空白。"""
    return r"\s*".join(map(re.escape, label))


def tidy(s: str) -> str:
    """收斂 meta 文字裡的跳格/多重空白為可讀段落(不破壞內容,僅正規化空白)。"""
    s = re.sub(r"[ \t　]*\t[ \t　]*", "\n", s)  # 跳格區塊 → 段落換行
    s = re.sub(r"\n{2,}", "\n", s)                        # 收斂多重換行
    s = re.sub(r"[ 　]{2,}", " ", s)                  # 收斂多重空白
    return s.strip()


# ── 1. 列表頁(累積檢索)──────────────────────────────────────

def extract_codes(html: str) -> list[str]:
    codes = re.findall(r"op=show&(?:amp;)?type=product&(?:amp;)?code=([A-Za-z0-9\-]+)", html)
    codes += re.findall(r"/link/\?code=([A-Za-z0-9\-]+)", html)
    return list(dict.fromkeys(codes))


def extract_total(html: str) -> int | None:
    m = re.search(cjk("找到") + r"\s*([\d,]+)\s*" + cjk("項"), html)
    return int(m.group(1).replace(",", "")) if m else None


def year_url(year: int, page: int = 1) -> str:
    url = f"{SEARCH}&field=year&text={year}"
    return url if page == 1 else f"{url}&{PAGE_PARAM}={page}"


def is_valid_list_page(html: str) -> bool:
    """空殼頁 = 只有網站框架、無檢索結果區塊(7/12 快取中 1998/1994 等即此)。"""
    return bool(extract_total(html) or extract_codes(html))


def fetch_list(url: str) -> str | None:
    """抓列表頁;快取到空殼頁則 force 重抓一次,仍無效回 None(不信任壞快取)。"""
    try:
        html = fetch(url)
        if not is_valid_list_page(html):
            print(f"  [空殼頁] 重抓 {url}")
            html = fetch(url, force=True)
        return html if is_valid_list_page(html) else None
    except RuntimeError as e:
        print(f"  [失敗] {e}")
        return None


# ── 2. 商品頁解析 ────────────────────────────────────────────

def parse_product(code: str, retried: bool = False) -> dict | None:
    url = f"{BASE}/bf/acms/content.asp?site=logosbf&op=show&type=product&code={quote(code)}"
    html = fetch(url, force=retried)
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
            rec[key] = tidy(el["content"]) if key == "summary" else el["content"].strip()

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
        if not retried:  # 可能是快取到的空殼頁 → force 重抓一次
            return parse_product(code, retried=True)
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
        "title_en": rf"(?:{cjk('英文書名')}|English\s*Title)[:：][ \t]*([^\n]+)",
        "publisher": cjk("出版") + r"[社商]?[:：][ \t]*([^\n]+)",
        "publish_date": cjk("出版日期") + r"[:：][ \t]*([\d/.\-年月日]+)",
        "isbn": r"ISBN[:：][ \t]*([0-9Xx\-]+)",
        "page_count": cjk("頁數") + r"[:：][ \t]*(\d+)",
        "binding": cjk("裝訂") + r"[:：][ \t]*([^\n]+)",
        "stock": rf"(?:{cjk('庫存')}|{cjk('供應狀態')})[:：][ \t]*([^\n]+)",
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
    m = re.search(rf"(?:{cjk('原價')}|{cjk('定價')})[:：]?[ \t]*(?:HK\$|\$)?\s*([\d,.]+)", text)
    if m:
        rec["price_list"] = m.group(1).replace(",", "")
    m = re.search(rf"(?:{cjk('現價')}|{cjk('售價')}|{cjk('特價')})[:：]?[ \t]*(?:HK\$|\$)?\s*([\d,.]+)", text)
    if m:
        rec["price_sale"] = m.group(1).replace(",", "")
    rec.setdefault("currency", "HKD")

    return rec


# ── 3. 快取離線收割 ──────────────────────────────────────────

def harvest_cached_codes() -> list[str]:
    """從既有快取頁離線抽出所有書碼(先前逐年重走留下 18,000+ 頁,不浪費)。"""
    codes: dict[str, None] = {}
    n = 0
    for gz in CACHE.rglob("*.html.gz"):
        try:
            html = gzip.decompress(gz.read_bytes()).decode("utf-8", errors="replace")
        except OSError:
            continue
        n += 1
        for c in extract_codes(html):
            codes.setdefault(c)
    print(f"快取收割:掃描 {n} 頁,得 {len(codes)} 個不重複書碼")
    return list(codes)


# ── 主流程 ───────────────────────────────────────────────────

def probe(anchor: int):
    print("=== 探測模式:驗證『年份檢索=該年以後累積』假設 ===")
    results = {}
    for y in (2021, 2001, anchor):
        html = fetch_list(year_url(y))
        total = extract_total(html) if html else None
        first = extract_codes(html)[:3] if html else []
        results[y] = (total, first)
        print(f"text={y}:宣稱總數 {total},首頁前 3 code {first}")
    print("判讀:若三者首頁 code 幾乎相同、總數隨年份遞減而遞增,即證實累積;")
    print(f"錨定年 {anchor} 總數應為最大(≈全站量)。若否,改 --anchor 更早年份。")
    _, first = results[anchor]
    if first:
        rec = parse_product(first[0])
        print("商品頁解析結果:")
        print(json.dumps(rec, ensure_ascii=False, indent=2))
    print("\n請把以上輸出貼回給 Claude 檢查。")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true")
    ap.add_argument("--anchor", type=int, default=1,
                    help="錨定年:檢索『該年以後』;越低涵蓋越全。7/14 probe 實測 "
                         "anchor 1 = 1900 = 24,609 已飽和(全站量),故預設 1")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--no-harvest", action="store_true", help="跳過快取離線收割")
    args = ap.parse_args()

    if args.probe:
        probe(args.anchor)
        return

    writer = JsonlWriter(DATA / "logos_books.jsonl", "code")
    total_new = 0
    start = time.time()

    def do(code: str) -> bool:
        """解析一本;達 --limit 回 True。"""
        nonlocal total_new
        rec = parse_product(code)
        if rec and writer.write(rec):
            total_new += 1
            if total_new % 20 == 0:
                rate = total_new / max(time.time() - start, 1) * 3600
                print(f"  進度:+{total_new} 本(約 {rate:.0f} 本/小時),累計 {len(writer.seen)}")
        return bool(args.limit and total_new >= args.limit)

    try:
        # 第一步:離線收割既有快取的書碼
        if not args.no_harvest:
            for code in harvest_cached_codes():
                if code not in writer.seen and do(code):
                    print(f"達 --limit {args.limit},停止")
                    return

        # 第二步:錨定年單次全站掃描
        html = fetch_list(year_url(args.anchor))
        if html is None:
            print("錨定年首頁抓取失敗,中止(重跑即續)")
            return
        total = extract_total(html)
        per_page = max(len(extract_codes(html)), 1)
        if not total:
            print("讀不到宣稱總數,中止")
            return
        pages = -(-total // per_page)
        print(f"錨定年 {args.anchor}:宣稱共 {total} 項(≈全站),每頁 {per_page} 項,約 {pages} 頁")

        bad_streak = 0
        for p in range(1, pages + 1):
            page_html = html if p == 1 else fetch_list(year_url(args.anchor, p))
            got = extract_codes(page_html) if page_html else []
            if not got:
                bad_streak += 1
                print(f"  [警告] 第 {p} 頁無資料({bad_streak}/3)")
                if bad_streak >= 3:
                    print("連續 3 頁無資料,視為到底")
                    break
                continue
            bad_streak = 0
            for code in got:
                if code not in writer.seen and do(code):
                    print(f"達 --limit {args.limit},停止")
                    return
            if p % 25 == 0:
                print(f"  列表頁 {p}/{pages},累計 {len(writer.seen)} 本")
    except KeyboardInterrupt:
        print("\n[中斷] 已寫入的資料與頁面快取都在,重跑同指令即續抓")
    finally:
        writer.close()
        print(f"本次新增 {total_new} 本;logos_books.jsonl 累計 {len(writer.seen)} 本")


if __name__ == "__main__":
    main()
