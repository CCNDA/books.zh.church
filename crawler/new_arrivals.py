# -*- coding: utf-8 -*-
"""每日新品增量檢查(伺服器 cron 用):校園 IsNewBook.aspx + 基道年度新品(日期倒序)
+ 以琳分類列表增量(2026-08-03 新增,最新在前、無新品即停)。

設計:重用既有 campus_crawler / logos_crawler 的解析邏輯,只新增
 (1)「新品列表」入口(校園全館新書、基道年份檢索日期倒序);
 (2)「見到既有書即停」的增量翻頁——兩站列表都是最新在前,故某頁完全沒有新書時,
     其後皆更舊、必已在庫,即可停止翻頁,每日只抓真正新增的部分。
新書寫入既有 master jsonl(campus_books.jsonl / logos_books.jsonl,JsonlWriter 天然去重),
並另存一份當日 delta 檔,交給 tools/import.php 匯入(import 以 source_url 去重、可重跑)。

用法(主機,先建 venv 裝 requests/bs4/lxml,見 deploy/cron-new-arrivals.md):
  python3 new_arrivals.py --source campus
  python3 new_arrivals.py --source logos --year 2021
  python3 new_arrivals.py --source campus --dry-run          # 只列出新品、不寫任何檔
  python3 new_arrivals.py --source logos --out data/new/x.jsonl
  python3 new_arrivals.py --source campus --max-pages 3       # 保險上限(0=自動)

輸出:--out 指定的 delta jsonl(僅在有新書時產生/寫入);未指定則自動命名
      data/new/<source>_YYYYMMDD-HHMM.jsonl。
結束碼:0=正常執行完成(不論有無新書);2=列表首頁抓取失敗(供 cron 判別)。

節流與禮貌沿用 common.polite_fetch(校園 5-8 秒、基道 2-3 秒),UA 註明 CCNDA 身分與聯絡方式。
"""
from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime
from pathlib import Path

import campus_crawler as campus
import elim_crawler as elim
import logos_crawler as logos

HERE = Path(__file__).parent
DATA = HERE / "data"
NEW_DIR = DATA / "new"


def _apply_browser_ua():
    """校園站偶爾對機器人 UA 較敏感;需要時改用瀏覽器式 UA(節流與 From 聯絡標頭照舊)。"""
    campus.session.headers.update({
        "User-Agent": campus.BROWSER_UA,
        "Referer": campus.BASE + "/",
        "Upgrade-Insecure-Requests": "1",
        "Sec-Fetch-Site": "same-origin",
        "Sec-Fetch-Mode": "navigate",
        "Sec-Fetch-Dest": "document",
        "Sec-Fetch-User": "?1",
        "sec-ch-ua": '"Not/A)Brand";v="8", "Chromium";v="126", "Google Chrome";v="126"',
        "sec-ch-ua-mobile": "?0",
        "sec-ch-ua-platform": '"Windows"',
    })


# ── 校園全館新書(IsNewBook.aspx)────────────────────────────

def collect_campus(writer, max_pages: int, dry_run: bool) -> list[dict]:
    """走訪 IsNewBook.aspx(rptCounter postback 分頁),回傳本次首見的新書紀錄清單。"""
    campus.warmup()
    url = campus.BASE + "/IsNewBook.aspx"
    # 第 1 頁強制重抓:快取的 __VIEWSTATE 綁舊 session,拿去 postback 會被 ASP.NET 打 500。
    html = campus.fetch(url, force=True)
    mapping, total_pages, total_items = campus.extract_counter_pager(html)
    cap_pages = min(total_pages, max_pages) if max_pages else total_pages
    print(f"校園全館新書:宣稱 {total_items} 件、{total_pages} 頁(處理上限 {cap_pages} 頁)", flush=True)

    new_ids: list[str] = []
    seen_this_run: set[str] = set()
    page = 1
    cur = html
    while True:
        pids = campus.extract_product_ids(cur)
        page_new = [p for p in pids if p not in writer.seen and p not in seen_this_run]
        seen_this_run.update(pids)
        new_ids.extend(page_new)
        print(f"  第 {page} 頁:{len(pids)} 件,新書 {len(page_new)}", flush=True)
        if not page_new:  # 最新在前,本頁全是既有書 → 之後更舊、必已在庫,停止翻頁
            break
        if page >= cap_pages:
            break
        page += 1
        target = mapping.get(page)
        if not target:
            print(f"  第 {page} 頁找不到分頁控制項,提前結束", flush=True)
            break
        cur = campus.fetch(url, post_data=campus.build_postback(cur, target))
        m2, tp2, _ = campus.extract_counter_pager(cur)
        if m2:
            mapping = m2
        total_pages = max(total_pages, tp2)

    return _parse_new(new_ids, lambda pid: campus.parse_product(pid, None), writer, dry_run)


# ── 基道年度新品(日期倒序累積檢索)──────────────────────────

def collect_logos(writer, year: int, max_pages: int, dry_run: bool) -> list[dict]:
    """基道:field=year&text=<year> 依 FirstPublishDate 倒序,回傳本次首見的新書紀錄清單。"""
    def list_url(page: int) -> str:
        u = f"{logos.SEARCH}&field=year&text={year}&sort=FirstPublishDate&order=DESC"
        return u if page == 1 else f"{u}&{logos.PAGE_PARAM}={page}"

    html = logos.fetch_list(list_url(1))
    if not html:
        print("基道列表首頁抓取失敗", flush=True)
        return None  # 供 main 判別失敗
    total = logos.extract_total(html)
    per_page = max(len(logos.extract_codes(html)), 1)
    pages = -(-total // per_page) if total else 1
    cap_pages = min(pages, max_pages) if max_pages else pages
    print(f"基道 {year} 年起新品:宣稱 {total} 項、每頁 {per_page}、約 {pages} 頁(日期倒序,處理上限 {cap_pages} 頁)", flush=True)

    new_codes: list[str] = []
    seen_this_run: set[str] = set()
    for p in range(1, cap_pages + 1):
        page_html = html if p == 1 else logos.fetch_list(list_url(p))
        got = logos.extract_codes(page_html) if page_html else []
        page_new = [c for c in got if c not in writer.seen and c not in seen_this_run]
        seen_this_run.update(got)
        new_codes.extend(page_new)
        print(f"  第 {p} 頁:{len(got)} 項,新書 {len(page_new)}", flush=True)
        if not page_new:  # 最新在前,本頁全是既有書 → 停止翻頁
            break

    return _parse_new(new_codes, logos.parse_product, writer, dry_run)


# ── 以琳分類增量(各分類列表最新在前,無新品即停)──────────────

def collect_elim(writer, max_pages: int, dry_run: bool) -> list[dict] | None:
    """以琳:走 elim_crawler.CATEGORIES(書籍+聖經各分類),列表最新在前
    (gid 遞減),逐分類增量翻頁、本頁無新品即停。不用官網「新書頁」
    (category2_1)是因為它只有大類、拿不到子分類 → 雙軌分類會全落綜合其他;
    新書必然同時出現在其分類列表最前面,逐分類走不會漏且路徑完整。
    列表頁一律 force 重抓(快取會讀到昨天的第 1 頁而永遠看不到新品);
    商品頁沿用快取(新品必然未快取)。"""
    gid_cats: dict[str, list[str]] = {}
    first_page_fail = 0
    for path, name in elim.CATEGORIES:
        page, found = 1, 0
        while True:
            try:
                html = elim.fetch(elim.list_url(path, page), force=True)
            except RuntimeError as e:
                print(f"  [{name}] 第 {page} 頁抓取失敗:{e}", flush=True)
                if page == 1:
                    first_page_fail += 1
                break
            gids = elim.extract_gids(html)
            if not gids:          # 空頁 = 分類走完
                break
            page_new = [g for g in gids if g not in writer.seen]
            for g in page_new:
                cats = gid_cats.setdefault(g, [])
                if path not in cats:
                    cats.append(path)
            found += len(page_new)
            if not page_new:      # 最新在前,本頁全是既有書 → 之後更舊,停
                break
            if max_pages and page >= max_pages:
                break
            page += 1
        if found:
            print(f"  [{name}] 新品 {found}", flush=True)
    if first_page_fail >= len(elim.CATEGORIES):
        print("以琳所有分類首頁抓取失敗", flush=True)
        return None  # 供 main 判別失敗(結束碼 2)
    print(f"以琳:共 {len(gid_cats)} 個新品 gid", flush=True)

    def parse(gid: str):
        cats = sorted(gid_cats[gid], key=lambda c: elim.CAT_ORDER.get(c, 999))
        return elim.parse_product(gid, cats)

    return _parse_new(list(gid_cats), parse, writer, dry_run)


# ── 共用:逐筆解析新品 ──────────────────────────────────────

def _parse_new(new_keys: list[str], parse_fn, writer, dry_run: bool) -> list[dict]:
    """逐筆抓商品頁解析;dry-run 只列印,否則寫入 master jsonl(去重)並回傳首見紀錄。"""
    fresh: list[dict] = []
    for key in new_keys:
        try:
            rec = parse_fn(key)
        except RuntimeError as e:
            print(f"  [跳過 {key},下次重跑補抓] {e}", flush=True)
            continue
        if not rec:
            continue
        if dry_run:
            print(f"  [新書] {rec.get('title')} | {rec.get('source_url')}", flush=True)
            fresh.append(rec)
            continue
        if writer.write(rec):  # 首次寫入 master → 列入本次 delta
            print(f"  +新書:{rec.get('title')}", flush=True)
            fresh.append(rec)
    return fresh


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--source", required=True, choices=["campus", "logos", "elim"])
    ap.add_argument("--year", type=int, default=2021,
                    help="基道年份錨(檢索該年以後,日期倒序);校園忽略。預設 2021")
    ap.add_argument("--max-pages", type=int, default=0, help="列表頁處理上限(0=自動,依站方宣稱頁數)")
    ap.add_argument("--out", help="delta jsonl 輸出路徑;未指定則自動命名 data/new/<source>_<ts>.jsonl")
    ap.add_argument("--dry-run", action="store_true", help="只列出新品、不寫 master 也不寫 delta")
    ap.add_argument("--browser-ua", action="store_true", help="校園改用瀏覽器式 UA(遇 500/封鎖時用)")
    args = ap.parse_args()

    if args.source == "campus":
        if args.browser_ua:
            _apply_browser_ua()
            print("校園改用瀏覽器式 UA", flush=True)
        writer = _writer("campus_books.jsonl", "product_id")
        fresh = collect_campus(writer, args.max_pages, args.dry_run)
    elif args.source == "elim":
        writer = _writer("elim_books.jsonl", "gid")
        fresh = collect_elim(writer, args.max_pages, args.dry_run)
        if fresh is None:
            writer.close()
            sys.exit(2)
    else:
        writer = _writer("logos_books.jsonl", "code")
        fresh = collect_logos(writer, args.year, args.max_pages, args.dry_run)
        if fresh is None:
            writer.close()
            sys.exit(2)

    writer.close()

    if not fresh:
        print(f"[{args.source}] 無新書")
        return
    if args.dry_run:
        print(f"[{args.source}] dry-run:偵測到 {len(fresh)} 本新書(未寫檔)")
        return

    out = Path(args.out) if args.out else (
        NEW_DIR / f"{args.source}_{datetime.now().strftime('%Y%m%d-%H%M')}.jsonl")
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", encoding="utf-8") as f:
        for rec in fresh:
            f.write(json.dumps(rec, ensure_ascii=False) + "\n")
    print(f"[{args.source}] 新增 {len(fresh)} 本 → delta:{out}")


def _writer(name: str, key: str):
    from common import JsonlWriter
    return JsonlWriter(DATA / name, key)


if __name__ == "__main__":
    main()
