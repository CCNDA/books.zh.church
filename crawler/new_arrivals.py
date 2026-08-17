# -*- coding: utf-8 -*-
"""每日新品增量檢查(伺服器 cron 用):校園 IsNewBook.aspx + 基道年度新品(日期倒序)
+ 以琳分類列表增量(2026-08-03 新增,最新在前、無新品即停)
+ 天恩出版社 Store API 日期倒序增量(2026-08-06 新增)
+ 微讀書城 所有書籍列表增量(2026-08-09 新增,SSR 上架新→舊、無新品即停)
+ 衛理書房 導覽全分類日期倒序增量(2026-08-17 新增,無新品即停)。

設計:重用既有 campus_crawler / logos_crawler 的解析邏輯,只新增
 (1)「新品列表」入口(校園全館新書、基道年份檢索日期倒序);
 (2)「見到既有書即停」的增量翻頁——兩站列表都是最新在前,故某頁完全沒有新書時,
     其後皆更舊、必已在庫,即可停止翻頁,每日只抓真正新增的部分。
新書寫入既有 master jsonl(campus_books.jsonl / logos_books.jsonl,JsonlWriter 天然去重),
並另存一份當日 delta 檔,交給 tools/import.php 匯入(import 以 source_url 去重、可重跑)。

用法(主機,先建 venv 裝 requests/bs4/lxml,見 deploy/cron-new-arrivals.md):
  python3 new_arrivals.py --source campus
  python3 new_arrivals.py --source logos --year 2021
  python3 new_arrivals.py --source grace
  python3 new_arrivals.py --source wdbook
  python3 new_arrivals.py --source methodist
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
import graceph_crawler as grace
import logos_crawler as logos
import methodist_crawler as methodist
import wdbook_crawler as wdbook

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


# ── 天恩 Store API 增量(日期倒序,無新品即停)────────────────

def collect_grace(writer, max_pages: int, dry_run: bool) -> list[dict] | None:
    """天恩:WooCommerce Store API orderby=date&order=desc,最新在前,
    本頁完全沒有新品即停。不用官網「新書快報」分類(4301)是因為它是人工
    精選(僅十餘件、可能滯後或遺漏),日期倒序全清單必然涵蓋所有新上架商品,
    且紀錄自帶完整分類清單(雙軌分類直接可用)。
    清單頁一律 force 重抓(快取會讀到昨天的第 1 頁而永遠看不到新品);
    商品頁沿用快取(新品必然未快取)。"""
    new_prods: dict[str, dict] = {}
    page = 1
    while True:
        prods = grace.fetch_products_page(page, force=True)
        if prods is None:
            if page == 1:
                print("天恩 Store API 第 1 頁抓取失敗", flush=True)
                return None  # 供 main 判別失敗(結束碼 2)
            break
        if not prods:      # 空陣列 = 走完全站
            break
        page_new = [p for p in prods if str(p.get("id")) not in writer.seen]
        for p in page_new:
            new_prods.setdefault(str(p.get("id")), p)
        print(f"  第 {page} 頁:{len(prods)} 件,新品 {len(page_new)}", flush=True)
        if not page_new:   # 最新在前,本頁全是既有商品 → 之後更舊,停
            break
        if max_pages and page >= max_pages:
            break
        page += 1
    print(f"天恩:共 {len(new_prods)} 個新品", flush=True)

    return _parse_new(list(new_prods), lambda pid: grace.parse_product(new_prods[pid]),
                      writer, dry_run)


# ── 微讀書城 所有書籍列表增量(SSR 上架新→舊,無新品即停)────

def collect_wdbook(writer, max_pages: int, dry_run: bool) -> list[dict] | None:
    """微讀:/store/category/0(所有書籍)伺服器直出、預設上架由新到舊,
    本頁完全沒有新品即停。不用「近期上架」頁(/recommended/recent)是因為
    它靠 JS widget API 載入(非公開介面、格式變動風險高),而其內容與
    category/0 前段完全一致(8/9 Chrome 實測)。
    清單頁一律 force 重抓(快取會讀到昨天的第 1 頁而永遠看不到新品);
    商品頁沿用快取(新品必然未快取)。"""
    new_ids: list[str] = []
    seen_this_run: set[str] = set()
    page = 1
    while True:
        try:
            html = wdbook.fetch(wdbook.list_url(page), force=True)
        except RuntimeError as e:
            if page == 1:
                print(f"微讀清單第 1 頁抓取失敗:{e}", flush=True)
                return None  # 供 main 判別失敗(結束碼 2)
            print(f"  第 {page} 頁抓取失敗:{e}", flush=True)
            break
        got = wdbook.extract_ids(html)
        if not got:        # 空頁 = 走完
            break
        page_new = [p for p in got if p not in writer.seen and p not in seen_this_run]
        seen_this_run.update(got)
        new_ids.extend(page_new)
        print(f"  第 {page} 頁:{len(got)} 件,新品 {len(page_new)}", flush=True)
        if not page_new:   # 最新在前,本頁全是既有書 → 之後更舊,停
            break
        if max_pages and page >= max_pages:
            break
        page += 1
    print(f"微讀:共 {len(new_ids)} 個新品", flush=True)

    return _parse_new(new_ids, wdbook.parse_product, writer, dry_run)


# ── 衛理書房 導覽全分類增量(各分類依上架日倒序,無新品即停)────

def collect_methodist(writer, max_pages: int, dry_run: bool) -> list[dict] | None:
    """衛理:導覽選單全分類(含「新品上架」各月份子分類)逐一以
    ?sort=p.date_added&order=DESC 依上架日倒序走訪,本頁無新品即停
    (通常每分類只需第 1 頁,約 180 次請求)。不只走「新品上架」分類,
    是因為站方可能只把商品掛主題分類;走全分類同時蒐集完整分類歸屬
    (雙軌分類直接可用)。清單頁一律 force 重抓;商品頁沿用快取
    (新品必然未快取)。max_pages 由各分類「無新品即停」自然節制,不另設限。"""
    try:
        cats = methodist.parse_nav(force=True)
    except RuntimeError as e:
        print(f"衛理首頁(導覽選單)抓取失敗:{e}", flush=True)
        return None  # 供 main 判別失敗(結束碼 2)
    if not cats:
        print("衛理導覽選單解析不到分類(版型可能改版)", flush=True)
        return None
    members = methodist.collect_memberships(cats, by_date=True, stop_on_seen=writer.seen)
    print(f"衛理:共 {len(members)} 個新品", flush=True)

    def parse(key: str):
        d = members[key]
        return methodist.parse_product(d["pid"], d["cats"], href=d["href"])

    return _parse_new(list(members), parse, writer, dry_run)


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
    ap.add_argument("--source", required=True, choices=["campus", "logos", "elim", "grace", "wdbook", "methodist"])
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
    elif args.source == "grace":
        writer = _writer("grace_books.jsonl", "pid")
        fresh = collect_grace(writer, args.max_pages, args.dry_run)
        if fresh is None:
            writer.close()
            sys.exit(2)
    elif args.source == "wdbook":
        writer = _writer("wdbook_books.jsonl", "pid")
        fresh = collect_wdbook(writer, args.max_pages, args.dry_run)
        if fresh is None:
            writer.close()
            sys.exit(2)
    elif args.source == "methodist":
        writer = _writer("methodist_books.jsonl", "pid")
        fresh = collect_methodist(writer, args.max_pages, args.dry_run)
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
