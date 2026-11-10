# -*- coding: utf-8 -*-
"""天道北美網路書房(www.ustiendao.com)爬蟲——北美購書管道(熊叔叔 2026-11-10 TG 選「做，當北美購書管道」,
同日再選「乙 新書也收（先沒有分類）」:書目網沒有的中文書照一般流程收成新書,分類由 classify 關鍵字回填)。

2026-11-10 偵察實測(Asana 1220372458497220):
- **Lightspeed eCommerce**。首頁有 Cloudflare 驗證頁(curl 拿到「Just a moment…」),
  但 robots.txt 允許爬(只擋 /admin /account/ /cart/ /compare/ /checkout/,Crawl-delay 2)、
  sitemap.xml 讀得到、書頁直接回 200。**不繞過任何驗證**:只走 sitemap 和書頁;
  哪天書頁也變成驗證頁,就停下來回報,不要換 UA 或用瀏覽器硬闖。
- sitemap 14,497 個網址,商品頁(.html)12,966 個。
- 書頁 JSON-LD 有 Product:gtin13(＝ISBN)、name(前面黏著 brand)、brand(出版社,中英並列)、
  mpn(作者)、description(HTML)、image、offers.price/priceCurrency(USD)/availability。
  → 不解析畫面,只讀 JSON-LD;書名用 og:title(沒有黏 brand)。
- 隨機 100 頁:80 本是書(978／979 開頭)、20 個是書籤、提袋、飾品等禮品;
  80 本裡 72 本書目網已收(ISBN 比對),多為台灣出版社的書,跟香港天道書樓重疊只有 7 本。

收錄規則(寫死在程式、有測試):
1. 只收 gtin13 是合法 ISBN-13(978／979、檢查碼對)的商品 → 禮品自然排除
2. 書名沒有任何中文字 → 英文書,不收(書目網是華文書目)
3. 每筆照實記 availability、price、currency=USD;原始 JSON-LD 整包存 raw_ld

用法:
    python3 ustiendao_crawler.py --probe --limit 30   # 抽樣看欄位覆蓋率與收／不收原因
    python3 ustiendao_crawler.py                      # 全量(約 13k 頁,間隔 3～4 秒,約 12 小時;有快取可續跑)
    python3 ustiendao_crawler.py --new                # 重抓 sitemap 後,只做還沒見過的書頁
    每日新品走 new_arrivals.py --source ustiendao(collect_new,一輪最多 300 頁);匯入照一般流程
"""
from __future__ import annotations

import argparse
import html as _html
import json
import re
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

from common import JsonlWriter, make_session, polite_fetch

BASE = "https://www.ustiendao.com"
SITEMAP = BASE + "/sitemap.xml"
SOURCE = "ustiendao"
CURRENCY = "USD"

HERE = Path(__file__).parent
CACHE = HERE / "cache" / SOURCE
DATA = HERE / "data"
OUT = DATA / f"{SOURCE}_books.jsonl"
SKIPPED = DATA / f"{SOURCE}_skipped.jsonl"
THROTTLE = (3.0, 4.0)   # robots Crawl-delay 2,多給一點

CJK = re.compile(r"[㐀-鿿豈-﫿]")
CHALLENGE = "Just a moment..."


def valid_isbn13(s: str | None) -> bool:
    if not s or not re.fullmatch(r"97[89]\d{10}", s):
        return False
    tot = sum(int(c) * (1 if i % 2 == 0 else 3) for i, c in enumerate(s[:12]))
    return (10 - tot % 10) % 10 == int(s[12])


def strip_html(s: str | None) -> str:
    if not s:
        return ""
    # 站方寫法是「<br />\r\n」:換行跟 br 是同一個斷行,只算一次
    s = s.replace("\r\n", "\n").replace("\r", "\n")
    s = re.sub(r"<br\s*/?>[ \t]*\n?", "\n", s, flags=re.I)
    s = re.sub(r"<[^>]+>", "", s)
    s = _html.unescape(s)
    return "\n".join(line.strip() for line in s.splitlines()).strip()


def sitemap_urls(session, force: bool = False) -> list[str]:
    xml = polite_fetch(session, SITEMAP, CACHE, THROTTLE, force=force)
    # 11/10 實測 sitemap 裡有一筆「/.html」(沒有代號、404)→ 代號是空的不算書頁
    return re.findall(r"<loc>(https://www\.ustiendao\.com/[^/<]+?\.html)</loc>", xml)


def product_ld(page: str) -> dict | None:
    """書頁 JSON-LD 裡 @type=Product 的那一塊(整段 script 是陣列;解析失敗就回 None,不猜)。"""
    for m in re.finditer(r"<script[^>]*application/ld\+json[^>]*>(.*?)</script>", page, re.S):
        try:
            # strict=False:站方 description 字串裡夾著真的換行(\r\n),標準 JSON 不允許 → 11/10 第一次 probe 25 頁全被當成沒有 JSON-LD
            data = json.loads(m.group(1), strict=False)
        except json.JSONDecodeError:
            continue
        for item in data if isinstance(data, list) else [data]:
            if isinstance(item, dict) and item.get("@type") == "Product":
                return item
    return None


def og_title(page: str) -> str | None:
    m = re.search(r'<meta property="og:title" content="([^"]*)"', page)
    return _html.unescape(m.group(1)).strip() if m else None


def split_title(t: str) -> tuple[str, str | None]:
    """站方書名常是「中文 English」或「中文 | English」:從第一個「空白後接英文字母、而且後面再也沒有中文」的位置切開。
    「時代的星火 01」(後面不是英文字母)、「陶恕系列 05：受教的心」(後面還有中文)不切。
    切不出來就回 (原書名, None)。原書名另存 title_raw。"""
    for m in re.finditer(r"\s([A-Za-z])", t):
        i = m.start(1)
        head, tail = t[:i].rstrip(" \t|｜:：-–—"), t[i:].strip()
        if CJK.search(head) and not CJK.search(tail):
            return head, tail
    return t, None


def publisher_zh(brand: str | None) -> str | None:
    """brand 是「建道神學院 Alliance Bible Seminary」「宣道 China Alliance Press」「歸主 (TW)」這種中英／地區並列:
    取第一個英文字母或半形括號之前的部分;那部分沒有中文就照原樣(原值另存 publisher_raw)。"""
    if not brand:
        return None
    b = _html.unescape(brand).strip()
    head = re.split(r"[A-Za-z(]", b, maxsplit=1)[0].strip(" \t&/|,-、")
    return head if CJK.search(head) else b


def parse_product(page: str, url: str) -> tuple[dict | None, str]:
    """回 (紀錄, 原因)。紀錄是 None 時原因說明為什麼不收。"""
    if CHALLENGE in page[:3000]:
        return None, "challenge"          # 書頁也被擋 → 呼叫端要停下來
    ld = product_ld(page)
    if ld is None:
        return None, "no_ld"
    isbn = str(ld.get("gtin13") or "").strip()
    if not valid_isbn13(isbn):
        return None, "not_isbn"           # 禮品、文具,或站方 gtin 不是 ISBN
    title = og_title(page) or _html.unescape(str(ld.get("name") or "")).strip()
    if not CJK.search(title):
        return None, "english"
    offers = ld.get("offers") or {}
    if isinstance(offers, list):
        offers = offers[0] if offers else {}
    avail = str(offers.get("availability") or "").rsplit("/", 1)[-1] or None
    zh, en = split_title(title)
    rec = {
        "product_id": pid_of(url),
        "source_url": url,
        "title": zh,
        "title_en": en,          # import.php 對到 original_title
        "title_raw": title,
        "authors_raw": _html.unescape(str(ld.get("mpn") or "")).strip() or None,
        "publisher": publisher_zh(ld.get("brand")),
        "publisher_raw": _html.unescape(str(ld.get("brand") or "")).strip() or None,
        "isbn": isbn,
        "price_list": str(offers.get("price")) if offers.get("price") not in (None, "") else None,
        "currency": offers.get("priceCurrency") or CURRENCY,
        "availability": avail,
        "cover_url": ld.get("image") or None,
        "summary": strip_html(ld.get("description")) or None,
        "raw_ld": ld,
        "crawled_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
    }
    return rec, "ok"


def pid_of(url: str) -> str:
    return url.rsplit("/", 1)[-1][:-5]


def fetch_one(session, url: str, force: bool = False) -> tuple[dict | None, str]:
    """抓一頁並解析。單頁抓不到(下架 404、暫時故障)回 (None, "fetch_error"):不中斷整輪、
    也不寫進 skipped(下次會再試)。"""
    try:
        page = polite_fetch(session, url, CACHE, THROTTLE, force=force)
    except RuntimeError as e:
        print(f"  [抓不到] {url}:{str(e).splitlines()[-1][:120]}")
        return None, "fetch_error"
    return parse_product(page, url)


def collect_new(writer, cap: int = 300, dry_run: bool = False) -> list[dict] | None:
    """每日新品(new_arrivals.py --source ustiendao 呼叫):重抓 sitemap,只做主檔和 skipped 都沒見過的書頁。

    - 一輪最多 cap 頁(300 頁 × 3～4 秒 ≈ 20 分鐘,在 daily_new.sh 每來源 1 小時上限內);其餘下一輪接著做。
      第一次全量(約 13k 頁、12 小時)不要靠這裡:先在主機另外跑 `python3 ustiendao_crawler.py`。
    - 禮品、英文書也要記進 skipped,不然每天都會重抓那約兩成的頁面。
    - sitemap 0 頁 → 回 None(站方改版或被擋,不能當成「沒新書」)。
    - 書頁變成驗證頁 → 停在那裡、回已經寫好的;一本都沒有就回 None(讓排程記失敗、警報信會叫)。
      已寫進 master 的書都在 delta(writer tee)裡,不能回 None 讓它們被丟掉。
    """
    session = make_session()
    urls = sitemap_urls(session, force=True)
    if not urls:
        print("[ustiendao] sitemap 抓到 0 個書頁 —— 站方可能改版或擋了,視為失敗", flush=True)
        return None
    sk = JsonlWriter(SKIPPED, "product_id")
    fresh: list[dict] = []
    try:
        todo = [u for u in urls if not writer.has(pid_of(u)) and not sk.has(pid_of(u))]
        print(f"[ustiendao] sitemap {len(urls)} 頁、還沒看過 {len(todo)} 頁、這輪做 {min(cap, len(todo))} 頁", flush=True)
        for url in todo[:cap]:
            rec, why = fetch_one(session, url)
            if why == "challenge":
                print(f"[ustiendao][停] 書頁變成 Cloudflare 驗證頁:{url} —— 不繞過,請回報", flush=True)
                return fresh or None
            if rec is None:
                if why != "fetch_error" and not dry_run:
                    sk.write({"product_id": pid_of(url), "source_url": url, "reason": why})
                continue
            if dry_run:
                fresh.append(rec)
            elif writer.write(rec):
                fresh.append(rec)
    finally:
        sk.close()
    return fresh


def crawl(urls: list[str], limit: int = 0, probe: bool = False, force: bool = False) -> Counter:
    session = make_session()
    w = None if probe else JsonlWriter(OUT, "product_id")
    sk = None if probe else JsonlWriter(SKIPPED, "product_id")
    why: Counter = Counter()
    n = 0
    try:
        for url in urls:
            pid = pid_of(url)
            if w is not None and (w.has(pid) or sk.has(pid)):
                continue
            if limit and n >= limit:
                break
            n += 1
            rec, reason = fetch_one(session, url, force=force)
            why[reason] += 1
            if reason == "fetch_error":
                continue
            if reason == "challenge":
                print(f"[停] 書頁也變成 Cloudflare 驗證頁:{url} —— 不繞過,請回報")
                break
            if probe:
                if rec:
                    print(f"  ok  {rec['isbn']} {rec['title'][:30]}｜作者 {rec['authors_raw']}｜{rec['publisher']}｜{rec['price_list']} {rec['currency']}｜{rec['availability']}")
                else:
                    print(f"  --  {reason:9} {url}")
                continue
            if rec:
                w.write(rec)
            else:
                sk.write({"product_id": pid, "source_url": url, "reason": reason})
    finally:
        if w is not None:
            w.close(); sk.close()
    return why


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true", help="只印、不寫檔")
    ap.add_argument("--new", action="store_true", help="重抓 sitemap,只做還沒見過的書頁")
    ap.add_argument("--limit", type=int, default=0)
    a = ap.parse_args()
    session = make_session()
    urls = sitemap_urls(session, force=a.new)
    print(f"sitemap 書頁 {len(urls)} 個")
    why = crawl(urls, limit=a.limit, probe=a.probe)
    print("結果:" + "、".join(f"{k} {v}" for k, v in why.most_common()))


if __name__ == "__main__":
    main()
