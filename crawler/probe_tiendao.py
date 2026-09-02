# -*- coding: utf-8 -*-
"""天道書樓 tiendao.org.hk 偵察腳本 v2(步驟 1,只讀不寫,不入庫)。

v1(9/1 上午)已跑過一輪,確認的事實:
  - OpenCart(route=product/ 721 處、.product-layout 商品磚、image/cache 縮圖)
  - / 導向 /store/;不存在的 path / product_id 回真 404(不是夾回首頁)
  - 分頁超界(page=9999)回空頁 → 可用「空頁即停」
  - 導覽選單 SSR、可解析,86 個分類、深達 4 層
  - /store/robots.txt HTTP 200 但只有 26 bytes;/store/sitemap.xml 是 0 bytes 空檔;
    /store/index.php?route=information/sitemap 有 276KB

v1 沒測到的、以及 v1 自己的 bug(v2 修正):
  1. 總數 regex 漏了「個」——站方字串是「顯示 1 到 2 個 (共 2 個) - (共 1 頁)」
  2. 價格抓到頁首購物車的 HK$0.00,不是商品價 → v2 限定在商品資訊區找
  3. 規格標籤用「行首 標籤:」比對,站方是表格 → 全數落空。
     v2 不再猜,**直接把規格區原始文字倒出來**讓人讀
  4. 封面縮圖尾巴是 -600x315w(多一個 w),原圖改寫 regex 沒中
  5. 受測分類挑「最深」→ 挑到只有 2 本的「學生版聖經」,等於沒測。
     v2 改為**逐一走訪全部分類、印出每類件數**,順便得到全站總量與對帳基準

v2 的取向:**少推論、多倒原始資料**。能貼原文的就貼原文,由人判讀後再寫爬蟲。

用法(主機 ~/books/crawler):
    venv/bin/python probe_tiendao.py 2>&1 | tee logs/tiendao-probe2.log

    venv/bin/python probe_tiendao.py --skip-counts   # 略過逐類清點(省 4 分鐘)
    venv/bin/python probe_tiendao.py --dump 3        # 商品頁原始傾印筆數(預設 2)
"""
from __future__ import annotations

import argparse
import re
import sys
from collections import Counter
from pathlib import Path
from urllib.parse import urljoin, urlparse, parse_qs

import requests
from bs4 import BeautifulSoup

from common import make_session, polite_fetch

BASE = "https://www.tiendao.org.hk"
STORE = BASE + "/store/"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "tiendao_probe"
THROTTLE = (2.0, 3.0)

session = make_session()


def rule(title: str) -> None:
    print("\n" + "=" * 70)
    print(title)
    print("=" * 70)


def get(url: str, force: bool = False) -> str | None:
    try:
        return polite_fetch(session, url, CACHE, THROTTLE, force=force)
    except Exception as e:  # noqa: BLE001 偵察腳本要跑完其餘項目
        print(f"  [抓取失敗] {url} → {e}")
        return None


def clip(s: str, n: int) -> str:
    s = re.sub(r"\n{3,}", "\n\n", s.strip())
    return s if len(s) <= n else s[:n] + f"\n…(截斷,原長 {len(s):,})"


# ── 1. robots.txt 全文(抓之前一定要讀)────────────────────────
def probe_robots() -> None:
    rule("1. robots.txt 全文(26 bytes,直接看內容決定能不能抓)")
    for path in ("/store/robots.txt", "/robots.txt"):
        try:
            r = session.get(urljoin(BASE, path), timeout=45)
            print(f"  {path}  HTTP {r.status_code}  {len(r.content)} bytes")
            if r.status_code == 200 and r.content:
                print("  ┌─ 全文 " + "─" * 50)
                for line in r.text.splitlines():
                    print(f"  │ {line}")
                print("  └" + "─" * 57)
        except requests.RequestException as e:
            print(f"  {path}  連線失敗:{e}")


# ── 2. HTML sitemap:是不是完整分類清單 ─────────────────────
def probe_sitemap() -> set[str]:
    rule("2. information/sitemap 的分類清單(對照導覽選單是否有遺漏)")
    html = get(STORE + "index.php?route=information/sitemap", force=True)
    if html is None:
        return set()
    soup = BeautifulSoup(html, "lxml")
    paths: set[str] = set()
    for a in soup.select('a[href*="route=product/category"]'):
        p = parse_qs(urlparse(a["href"]).query).get("path", [""])[0]
        if p:
            paths.add(p)
    print(f"  sitemap 頁列出分類 {len(paths)} 個")
    print(f"  深度分布:{dict(sorted(Counter(p.count('_') + 1 for p in paths).items()))}")
    return paths


# ── 3. 導覽選單分類樹(含名稱)────────────────────────────────
def probe_nav() -> list[dict]:
    rule("3. 導覽選單分類樹")
    html = get(STORE, force=True)
    if html is None:
        return []
    soup = BeautifulSoup(html, "lxml")
    cats: list[dict] = []
    seen: set[str] = set()
    for a in soup.select('a[href*="route=product/category"]'):
        p = parse_qs(urlparse(urljoin(STORE, a.get("href", ""))).query).get("path", [""])[0]
        name = a.get_text(strip=True)
        if not p or not name or p in seen:
            continue
        seen.add(p)
        cats.append({"path": p, "name": name, "depth": p.count("_") + 1})
    print(f"  導覽選單分類 {len(cats)} 個")
    return cats


def cat_url(path: str, page: int = 1, limit: int = 100) -> str:
    return f"{STORE}index.php?route=product/category&path={path}&limit={limit}&page={page}"


# ── 4. 逐類清點:每類幾件 + 全站總量(對帳基準)──────────────
TOTAL_RE = re.compile(r"共\s*([\d,]+)\s*個")          # v1 漏了「個」
PAGES_RE = re.compile(r"共\s*([\d,]+)\s*頁")


def probe_counts(cats: list[dict], sitemap_paths: set[str]) -> list[str]:
    rule("4. 逐類清點(每類件數 / 全站總量 / 哪些類值得抓)")
    all_paths = {c["path"] for c in cats} | sitemap_paths
    names = {c["path"]: c["name"] for c in cats}
    print(f"  合併導覽 + sitemap 共 {len(all_paths)} 個分類,逐一取第 1 頁讀站方總數…\n")

    rows: list[tuple[str, str, int, int]] = []
    sample_pool: list[tuple[int, str]] = []
    for p in sorted(all_paths, key=lambda x: (x.count("_"), x)):
        html = get(cat_url(p), force=True)   # 清單頁一律 force(基道快取教訓)
        if html is None:
            continue
        m, mp = TOTAL_RE.search(html), PAGES_RE.search(html)
        n = int(m.group(1).replace(",", "")) if m else -1
        pages = int(mp.group(1).replace(",", "")) if mp else -1
        rows.append((p, names.get(p, "(sitemap 才有)"), n, pages))
        if n >= 20:
            sample_pool.append((n, p))

    print(f"  {'path':<20}{'件數':>7}{'頁數':>6}  名稱")
    for p, name, n, pages in rows:
        flag = "  ← 讀不到總數" if n < 0 else ""
        print(f"  {p:<20}{n:>7}{pages:>6}  {'　' * p.count('_')}{name}{flag}")

    leaf_total = sum(n for _, _, n, _ in rows if n > 0)
    print(f"\n  ★ 各類件數加總 = {leaf_total:,}(含父子重複計算,非全站唯一件數)")
    print(f"  ★ 讀不到總數的分類:{sum(1 for _, _, n, _ in rows if n < 0)} 個")

    # 抽樣分類:選件數中等偏大的「主題」類,不選最新/禮品/最深
    sample_pool.sort(reverse=True)
    picked = [p for _, p in sample_pool[:6]]
    print(f"  ★ 後續抽樣將用:{picked}")
    return picked


# ── 5. 清單頁:limit 參數是否生效、每頁件數、pid 抽取 ───────
def probe_listing(paths: list[str]) -> list[str]:
    rule("5. 清單頁:limit 參數是否生效 / 每頁件數 / pid")
    if not paths:
        print("  無可測分類")
        return []
    target = paths[0]
    print(f"  受測分類 path={target}(件數最多者)")

    for limit in (20, 100):
        html = get(cat_url(target, 1, limit), force=True)
        if html is None:
            continue
        soup = BeautifulSoup(html, "lxml")
        tiles = soup.select(".product-layout")
        m = TOTAL_RE.search(html)
        print(f"    limit={limit:<4} 商品磚 {len(tiles):>3} 件   站方總數 "
              f"{m.group(1) if m else '?'}   "
              f"{'✓ limit 生效' if len(tiles) == limit else '⚠ limit 未生效或不足一頁'}")

    pids: list[str] = []
    for page in (1, 2):
        html = get(cat_url(target, page), force=True)
        if html is None:
            continue
        soup = BeautifulSoup(html, "lxml")
        for tile in soup.select(".product-layout"):
            a = tile.select_one('a[href*="product_id="]')
            if a:
                pid = parse_qs(urlparse(a["href"]).query).get("product_id", [""])[0]
                if pid:
                    pids.append(pid)
    uniq = list(dict.fromkeys(pids))
    print(f"  兩頁以「每磚取一個 pid」抽出 {len(pids)} 個、去重 {len(uniq)} 個"
          f"{'  ⚠ 跨頁重複,分頁可能不穩' if len(pids) != len(uniq) else '  ✓ 無重複'}")
    print("  (v1 的 6→2 是同一磚裡圖片/標題/按鈕各帶一次 pid,誤報,已修)")
    return uniq


# ── 6. 商品頁:原始傾印,不猜結構 ─────────────────────────────
def probe_products(pids: list[str], dump: int) -> None:
    rule(f"6. 商品頁原始傾印(前 {dump} 筆完整倒出,由人判讀後再寫解析)")
    for pid in pids[:dump]:
        url = f"{STORE}index.php?route=product/product&product_id={pid}"
        html = get(url)
        if html is None:
            continue
        soup = BeautifulSoup(html, "lxml")
        for tag in soup(["script", "style", "noscript"]):
            tag.decompose()

        print(f"\n{'─' * 70}\n■ product_id={pid}\n  {url}\n{'─' * 70}")

        h1 = soup.select_one("h1")
        print(f"[h1] {h1.get_text(strip=True) if h1 else '(無 h1)'}")

        # 價格:限定商品資訊區,避開頁首購物車(v1 就是被它騙的)
        info = (soup.select_one("#content .product-info")
                or soup.select_one("#content") or soup)
        for sel in (".price", ".price-new", ".price-old", "[class*=price]"):
            els = info.select(sel)
            if els:
                print(f"[價格 {sel}] " + " | ".join(
                    e.get_text(" ", strip=True)[:40] for e in els[:4]))
                break
        else:
            print("[價格] 商品區找不到 price 類名")

        # 規格區:把 #content 內所有 table / ul 原文倒出來
        tables = info.select("table")
        print(f"[table 數] {len(tables)}")
        for i, t in enumerate(tables[:3]):
            print(f"  ── table[{i}] 原文 ──")
            print(clip("\n".join(
                " | ".join(td.get_text(" ", strip=True) for td in tr.select("td, th"))
                for tr in t.select("tr")), 1200))

        # 頁籤/描述區
        for sel in ("#tab-description", ".tab-content", "#tab-specification"):
            el = info.select_one(sel)
            if el:
                print(f"  ── {sel} 純文字 ──")
                print(clip(el.get_text("\n", strip=True), 1500))

        print("  ── #content 全區純文字(前 2500 字,看還有什麼欄位)──")
        print(clip(info.get_text("\n", strip=True), 2500))

        og = soup.select_one('meta[property="og:image"]')
        cover = og.get("content") if og else ""
        print(f"[og:image] {cover}")
        if cover:
            # v1 的 regex 沒吃到 -600x315w 的尾巴 w
            orig = re.sub(r"/image/cache/(.+?)-\d+x\d+\w*(\.\w+)$", r"/image/\1\2", cover)
            print(f"[推測原圖] {orig}")
            if orig != cover:
                try:
                    r = session.get(orig, timeout=45)
                    print(f"[原圖驗證] HTTP {r.status_code}  {len(r.content):,} bytes"
                          f"  {'✓ 可用' if r.status_code == 200 else '✗ 改寫規則不成立'}")
                except requests.RequestException as e:
                    print(f"[原圖驗證] 連線失敗:{e}")


def main() -> int:
    ap = argparse.ArgumentParser(description="天道書樓偵察 v2(只讀)")
    ap.add_argument("--dump", type=int, default=2, help="商品頁原始傾印筆數(預設 2)")
    ap.add_argument("--skip-counts", action="store_true", help="略過逐類清點")
    args = ap.parse_args()

    print("天道書樓(tiendao)偵察 v2 —— 只讀,不入庫、不寫 data/")
    probe_robots()
    sm = probe_sitemap()
    cats = probe_nav()
    picked = [] if args.skip_counts else probe_counts(cats, sm)
    if args.skip_counts:
        picked = [c["path"] for c in cats if c["depth"] == 2][:6]
    pids = probe_listing(picked)
    probe_products(pids, args.dump)
    rule("偵察 v2 完畢 —— 請把整份 log 貼回")
    return 0


if __name__ == "__main__":
    sys.exit(main())
