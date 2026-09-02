# -*- coding: utf-8 -*-
"""每日新品增量檢查(伺服器 cron 用):校園 IsNewBook.aspx + 基道年度新品(日期倒序)
+ 以琳分類列表增量(2026-08-03 新增,最新在前、無新品即停)
+ 天恩出版社 Store API 日期倒序增量(2026-08-06 新增)
+ 微讀書城 所有書籍列表增量(2026-08-09 新增,SSR 上架新→舊、無新品即停)
+ 衛理書房 導覽全分類日期倒序增量(2026-08-17 新增,無新品即停)
+ 格子外面 全分類走訪增量(2026-08-18 新增,範圍=osb+聖經+★新書到)
+ 道聲 全分類走訪增量(2026-08-19 新增,Cyberbiz 全站)
+ 橄欖華宣 全分類增量(2026-08-19 新增,清單 id 遞減、整頁已見即停)
+ 宇宙光 大類彙整清單增量(2026-08-21 新增,五大類聯集=全站;有新品才補走 Tag)
+ 真哪噠 新品上架/注目優惠清單增量(2026-08-22 新增,有新品才補走 119 個分類取歸屬)。

設計:重用既有 campus_crawler / logos_crawler 的解析邏輯,只新增
 (1)「新品列表」入口(校園全館新書、基道年份檢索日期倒序);
 (2)「見到既有書即停」的增量翻頁——清單若按**上架日期**排序,新品必在最前面,
     某頁完全沒有新書即代表其後皆已在庫,可停止翻頁,每日只抓真正新增的部分。
     **例外:基道按「出版日期」倒序**,新上架的舊書會落在清單深處而非最前面,
     早停必漏,故基道預設全掃列表(2026-08-28 改;省時模式見 --min-pages)。
     新來源接入時,務必先確認站方排序依據是上架日期還是出版日期。
新書寫入既有 master jsonl(campus_books.jsonl / logos_books.jsonl,JsonlWriter 天然去重),
並另存一份當日 delta 檔,交給 tools/import.php 匯入(import 以 source_url 去重、可重跑)。

用法(主機,先建 venv 裝 requests/bs4/lxml,見 deploy/cron-new-arrivals.md):
  python3 new_arrivals.py --source campus
  python3 new_arrivals.py --source logos --year 2021        # 全掃約 197 頁、約 8 分鐘
  python3 new_arrivals.py --source logos --min-pages 5      # 省時模式(會漏新上架的舊書)
  python3 new_arrivals.py --source logos --year 1           # 深掃全站(約 1,231 頁、約 50 分)
  python3 new_arrivals.py --source grace
  python3 new_arrivals.py --source wdbook
  python3 new_arrivals.py --source methodist
  python3 new_arrivals.py --source osb
  python3 new_arrivals.py --source taosheng
  python3 new_arrivals.py --source cclm
  python3 new_arrivals.py --source cosmiccare
  python3 new_arrivals.py --source mezu
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
import cclm_crawler as cclm
import cosmiccare_crawler as cosmiccare
import mezu_crawler as mezu
import twgbr_crawler as twgbr
import pctpress_crawler as pctpress
import methodist_crawler as methodist
import tiendao_crawler as tiendao
import osb_crawler as osb
import taosheng_crawler as taosheng
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

# 基道列表的預設排序鍵。**必須是唯一鍵**——站方以 offset 分頁,排序鍵若有並列
# (sort=FirstPublishDate 大量同日期),同一個並列群組每次查詢回傳的順序是任意的,
# 於是相鄰頁互相重複、群組內有些列從頭到尾不曾被顯示。2026-08-28 實測:
# 日期排序全掃 197 頁,3,934 格裡 808 個碼重複佔掉 873 格,涵蓋率只有 77.8%。
# 改用商品碼排序讓並列消失,分頁才是確定性的。
LOGOS_SORT = "Code"
LOGOS_ORDER = "ASC"


def collect_logos(writer, year: int, max_pages: int, dry_run: bool,
                  min_pages: int = 0, sort: str = LOGOS_SORT,
                  order: str = LOGOS_ORDER) -> list[dict] | None:
    """基道:field=year&text=<year> 依 FirstPublishDate 倒序,回傳本次首見的新書紀錄清單。

    **預設全掃列表,不做早停。** 其他站的清單是「按上架日期」排序,新品必在最前面,
    所以「整頁皆已見即停」是安全的;基道不是——它按**出版日期**倒序,站方若把一本
    出版日期較舊的書新上架(補書、再版鋪貨),它會落在清單深處而非最前面,任何早停
    都會漏掉它。列表頁只是 code 清單(每頁一次請求、不開商品頁),anchor 2021 約 197 頁、
    節流後約 8 分鐘,代價可接受,故改為全掃取精確解。

    商品頁仍沿用快取(新品必然未快取),所以真正的成本與新書數成正比、與清單長度無關。

    min_pages > 0 時退回舊的早停模式(掃滿 N 頁後遇整頁皆已見即停),供臨時省時用。
    列表頁一律 force 重抓(快取會讀到第一次抓的那頁而永遠看不到新品)。"""
    def list_url(page: int) -> str:
        u = f"{logos.SEARCH}&field=year&text={year}&sort={sort}&order={order}"
        return u if page == 1 else f"{u}&{logos.PAGE_PARAM}={page}"

    html = logos.fetch_list(list_url(1), force=True)
    if not html:
        print("基道列表首頁抓取失敗", flush=True)
        return None  # 供 main 判別失敗
    total = logos.extract_total(html)
    per_page = max(len(logos.extract_codes(html)), 1)
    pages = -(-total // per_page) if total else 1
    cap_pages = min(pages, max_pages) if max_pages else pages
    mode = f"掃滿 {min_pages} 頁後可早停" if min_pages else "全掃不早停"
    print(f"基道 {year} 年起:宣稱 {total} 項、每頁 {per_page}、約 {pages} 頁"
          f"(sort={sort}/{order},處理上限 {cap_pages} 頁,{mode})", flush=True)

    new_codes: list[str] = []
    seen_this_run: set[str] = set()
    missed_pages: list[int] = []
    last_p = 0
    for p in range(1, cap_pages + 1):
        page_html = html if p == 1 else logos.fetch_list(list_url(p), force=True)
        got = logos.extract_codes(page_html) if page_html else []
        if not got:
            missed_pages.append(p)
        page_new = [c for c in got if c not in writer.seen and c not in seen_this_run]
        seen_this_run.update(got)
        new_codes.extend(page_new)
        last_p = p
        if page_new:  # 只在有斬獲時逐頁印,避免全掃時洗版
            print(f"  第 {p} 頁:{len(got)} 項,新書 {len(page_new)}", flush=True)
        elif p % 25 == 0:
            print(f"  …第 {p}/{cap_pages} 頁(累計首見 {len(new_codes)} 本)", flush=True)
        if min_pages and not page_new and p >= min_pages:
            print(f"  第 {p} 頁整頁皆已見,早停(--min-pages {min_pages})", flush=True)
            break

    # 補抓:站方偶發以 HTTP 200 回空殼頁,fetch_list 當場已 force 重抓一次仍可能失敗。
    # sort 是唯一鍵、分頁確定性,同一頁稍後重抓會拿到同一批列,所以整趟掃完再補一次
    # 很划算——2026-08-28 實測 21 筆缺口裡有 20 筆就是單一頁(第 79 頁)整頁落空造成的。
    if missed_pages:
        print(f"  [補抓] {len(missed_pages)} 個取不到內容的頁面,整趟掃完後再試一次…", flush=True)
        still: list[int] = []
        for p in missed_pages:
            page_html = logos.fetch_list(list_url(p), force=True)
            got = logos.extract_codes(page_html) if page_html else []
            if not got:
                still.append(p)
                continue
            page_new = [c for c in got if c not in writer.seen and c not in seen_this_run]
            seen_this_run.update(got)
            new_codes.extend(page_new)
            print(f"    第 {p} 頁補抓成功:{len(got)} 項,新書 {len(page_new)}", flush=True)
        missed_pages = still

    # 涵蓋率:去重碼數應等於站方宣稱總數。少掉的就是「站方從頭到尾沒顯示過」的筆數,
    # 一定要出聲——這正是 2026-08-28 靠對帳才發現的 22% 黑洞。
    cover = f"{len(seen_this_run)}/{total}" if total else str(len(seen_this_run))
    print(f"基道列表掃描完成:{last_p} 頁、去重 {cover} 碼、首見 {len(new_codes)} 本", flush=True)
    if missed_pages:
        # 取不到內容的頁數要留痕,否則「漏書」會再次變成無聲失敗
        head = ", ".join(map(str, missed_pages[:10]))
        more = f" 等 {len(missed_pages)} 頁" if len(missed_pages) > 10 else ""
        print(f"  [警告] 補抓後仍取不到清單內容:第 {head}{more},該頁的書漏收"
              f"(每頁 {per_page} 筆);明天的排程會再遇到同一頁,連續多天就要人工查", flush=True)
    if total and not min_pages and len(seen_this_run) < total:
        gap = total - len(seen_this_run)
        pct = len(seen_this_run) / total * 100
        # 缺口若剛好等於漏頁筆數,就是抓取失敗而非分頁黑洞,兩者處置不同,分開講清楚
        by_pages = len(missed_pages) * per_page
        cause = ("以上漏頁即可解釋" if missed_pages and gap <= by_pages
                 else f"扣掉漏頁仍有 {gap - by_pages} 筆不明,懷疑排序鍵 {sort} 有並列 → 跑 logos_coverage.py 對帳")
        print(f"  [警告] 涵蓋率 {pct:.1f}%,{gap} 筆未收:{cause}", flush=True)

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



# ── 格子外面 全分類走訪增量(scope 內未見過即新品)────────────

def collect_osb(writer, max_pages: int, dry_run: bool) -> list[dict] | None:
    """格子外面:Cyberbiz search_products.json 無可靠日期排序參數,但走訪
    清單極便宜(每頁 100 件、全走訪約 85 請求)→ 每日直接重走全部分類
    (osb 12 頁 + ★新書到 3 頁 + 各主題分類多為 1 頁),範圍內(osb/聖經
    三分類/★新書到)未見過的 handle 即新品。不依賴排序假設,同時蒐集完整
    分類歸屬(雙軌分類直接可用)。清單頁一律 force 重抓(快取會讀到昨天
    的頁面而永遠看不到新品);商品 JSON 沿用快取(新品必然未快取)。"""
    members = osb.collect_memberships(force_lists=True)
    if not members:
        print("格子外面清單走訪失敗(全分類皆無回應)", flush=True)
        return None  # 供 main 判別失敗(結束碼 2)
    new = {h: d for h, d in members.items()
           if d["scope"] and h not in writer.seen}
    print(f"格子外面:走訪 {len(members)} 件,範圍內新品 {len(new)}", flush=True)

    def parse(handle: str):
        return osb.parse_product(handle, new[handle]["cats"])

    return _parse_new(list(new), parse, writer, dry_run)



# ── 道聲 全分類走訪增量(未見過即新品)────────────────────────

def collect_taosheng(writer, max_pages: int, dry_run: bool) -> list[dict] | None:
    """道聲:Cyberbiz 同 osb,無可靠日期排序參數 → 每日重走全部分類清單
    (all 17 頁 + 各主題分類多為 1 頁,約 90 請求),未見過的 handle 即新品。
    走全分類而非只走「新書上市」(該分類僅 7 件、站方人工維護會漏),
    同時蒐集完整分類歸屬(雙軌分類直接可用)。清單頁一律 force 重抓;
    商品 JSON 沿用快取(新品必然未快取)。"""
    members = taosheng.collect_memberships(force_lists=True)
    if not members:
        print("道聲清單走訪失敗(全分類皆無回應)", flush=True)
        return None  # 供 main 判別失敗(結束碼 2)
    new = {h: d for h, d in members.items() if h not in writer.seen}
    print(f"道聲:走訪 {len(members)} 件,新品 {len(new)}", flush=True)

    def parse(handle: str):
        return taosheng.parse_product(handle, new[handle]["cats"])

    return _parse_new(list(new), parse, writer, dry_run)


# ── 橄欖華宣 全分類增量(清單 id 遞減,整頁已見即停)──────────

def collect_cclm(writer, max_pages: int, dry_run: bool) -> list[dict] | None:
    """橄欖華宣:清單頁為商品 id 遞減(新→舊)→ 逐分類翻頁、整頁皆已在庫
    即停該分類(通常每分類只需第 1 頁,約 100 請求)。走全分類而非單一
    「書籍」彙整,是為同時蒐集完整分類歸屬。清單頁一律 force 重抓;
    商品頁沿用快取(新品必然未快取)。"""
    members = cclm.collect_memberships(force_lists=True, stop_on_seen=writer.seen)
    if not members:
        print("橄欖華宣清單走訪失敗(全分類皆無回應)", flush=True)
        return None  # 供 main 判別失敗(結束碼 2)
    new = {p: d for p, d in members.items() if p not in writer.seen}
    print(f"橄欖華宣:走訪 {len(members)} 件,新品 {len(new)}", flush=True)

    def parse(pid: str):
        return cclm.parse_product(pid, new[pid]["cats"])

    return _parse_new(list(new), parse, writer, dry_run)


# ── 宇宙光 大類彙整增量(未見過即新品)────────────────────────

def collect_cosmiccare(writer, max_pages: int, dry_run: bool) -> list[dict] | None:
    """宇宙光:先只走六個大類彙整清單(約 107 頁)——實測五大類聯集
    = /Product/List 全站 1,671 件,故便宜又完整;未見過的商品代碼即新品。
    有新品時才再走全部 Tag 清單(約 150 頁)補齊分類歸屬,讓新書直接由
    對映表歸類而非關鍵字猜測。清單頁一律 force 重抓(快取會讀到昨天的
    頁面而永遠看不到新品);商品頁沿用快取(新品必然未快取)。"""
    members = cosmiccare.collect_memberships(force_lists=True, only_promo=True)
    if not members:
        print("宇宙光清單走訪失敗(全分類皆無回應)", flush=True)
        return None  # 供 main 判別失敗(結束碼 2)
    new = {p: d for p, d in members.items() if p not in writer.seen}
    print(f"宇宙光:走訪 {len(members)} 件,新品 {len(new)}", flush=True)
    if new:
        full = cosmiccare.collect_memberships(force_lists=True)
        for pid in new:
            if pid in full:
                new[pid] = full[pid]

    def parse(pid: str):
        return cosmiccare.parse_product(pid, new[pid]["cats"])

    return _parse_new(list(new), parse, writer, dry_run)


# ── 真哪噠 新品上架清單增量(未見過即新品)────────────────────

def collect_pctpress(writer, max_pages: int, dry_run: bool) -> list[dict] | None:
    """教會公報社(WooCommerce Store API):取「書籍NEW」分類,API 一次給完整欄位。"""
    recs = pctpress.collect_new(max_pages=max(1, min(max_pages or 3, 3)))
    new = [r for r in recs if not writer.has(r["pid"])]
    if not new:
        print("[pctpress] 無新書", flush=True)
        return []
    print(f"[pctpress] 偵測到 {len(new)} 本新書", flush=True)
    if dry_run:
        return new
    return [r for r in new if writer.write(r)]


def collect_twgbr(writer, max_pages: int, dry_run: bool) -> list[dict] | None:
    """福音書房(Shopline):先只走「新品推介」;有新書才補走全部分類取完整歸屬。"""
    members = twgbr.collect_memberships_dict(only=twgbr.NEW_ENTRY)
    new = {h: d for h, d in members.items() if not writer.has(h)}
    if not new:
        print("[twgbr] 無新書", flush=True)
        return []
    print(f"[twgbr] 偵測到 {len(new)} 本新書,補走全部分類取歸屬", flush=True)
    full = twgbr.collect_memberships_dict()
    for h in list(new):
        if h in full:
            new[h] = full[h]
    if dry_run:
        return [{"handle": h} for h in new]
    out: list[dict] = []
    for h, d in new.items():
        rec = twgbr.parse_product(h, d["cats"])
        if rec and writer.write(rec):
            out.append(rec)
    return out


def collect_mezu(writer, max_pages: int, dry_run: bool) -> list[dict] | None:
    """真哪噠:先只走「新品上架/注目優惠」兩個清單(每頁 50 件,約 8 頁)——
    站方新品都會掛在這裡,便宜又即時;未見過的 handle 即新品。
    有新品時才再走全部 119 個分類清單(約 600 頁)補齊分類歸屬,讓新書直接由
    對映表歸類而非關鍵字猜測。清單頁一律 force 重抓(快取會讀到昨天的頁面
    而永遠看不到新品);商品頁沿用快取(新品必然未快取)。"""
    members = mezu.collect_memberships(force_lists=True, only=mezu.NEW_ENTRY)
    if not members:
        print("真哪噠清單走訪失敗(新品清單無回應)", flush=True)
        return None  # 供 main 判別失敗(結束碼 2)
    new = {h: cs for h, cs in members.items() if h not in writer.seen}
    print(f"真哪噠:走訪 {len(members)} 件,新品 {len(new)}", flush=True)
    if new:
        full = mezu.collect_memberships(force_lists=True)
        for h in new:
            if h in full:
                new[h] = full[h]

    def parse(h: str):
        return mezu.parse_product(h, new[h])

    return _parse_new(list(new), parse, writer, dry_run)


# ── 共用:逐筆解析新品 ──────────────────────────────────────

# ── 天道書樓 每日新品(105 快路徑 + 有新品才全掃取分類)────────────

def collect_tiendao(writer, max_pages: int, dry_run: bool,
                    full_scan: bool = False) -> list[dict] | None:
    """天道:每日只走 path=105「最新出版」(1 頁、64 件),偵測到新 pid 才走全分類。

    ═══ 為什麼是這個設計(2026-09-02 實測,不是猜的)═══

    diag【G】拿全量 1233 件離線檢定 105 的涵蓋率:
      全站出版日最新  30 本 → 100.0% 在 105 內
      全站出版日最新  60 本 →  88.3%
      全站出版日最新 120 本 →  49.2%
    105 只有 64 件卻橫跨 1999-2026(2026:5 / 2025:14 / 2024:12 / 2023:13 / 2022:14
    / 2020:1 / 1999:1)—— **它是站方手動維護的推薦位,不是自動新品列表**。
    但「最新 30 本 100% 命中」證明新書確實會被放進去,即時性足夠當快路徑。

    **為什麼不能只走 105:** 105 是 promo 分類(tiendao_category_map 裡
    internal_name=NULL 僅存證不歸類)。只走它的話,新書的分類歸屬只有「最新出版」
    一項,apply_tiendao_categories.php 無從歸類 → 那本書會沒有 primary。
    所以偵測到新品時要走完整 94 個分類,把主題分類歸屬一起收齊(雙軌分類直接可用)。

    成本:沒有新品的日子 **1 次請求**;有新品的日子 94 次清單 + N 次商品頁
    (約 4-5 分鐘,節流 2-3 秒)。新品不是每天有,所以平均極省 ——
    對比基道每天固定全掃 197 頁約 8 分鐘。

    **--full-scan 是每週對帳用**:105 漏掉的是「上架日新但出版日舊」的書
    (最新 60 本有 7 本不在 105 內),單靠 105 會永久漏掉它們,而且 log 全綠
    看不出漏 —— 這正是 8/27 基道那次的形狀。清單頁一律 force 由
    walk_category() 內建保證。"""
    try:
        cats = tiendao.parse_nav(force=True)
    except RuntimeError as e:
        print(f"天道首頁(導覽選單)抓取失敗:{e}", flush=True)
        return None  # 供 main 判別失敗(結束碼 2)
    if not cats:
        print("天道導覽選單解析不到分類(版型可能改版)", flush=True)
        return None

    if not full_scan:
        newest = next((c for c in cats if c["path_id"] == "105"), None)
        if newest is None:
            print("天道:導覽選單找不到 path=105「最新出版」——"
                  "站方可能改版,本次改走全分類", flush=True)
        else:
            pids = tiendao.walk_category(newest)
            unseen = [p for p in pids if not writer.has(p)]
            print(f"天道:105「最新出版」{len(pids)} 件,其中未見過 {len(unseen)} 件",
                  flush=True)
            if not unseen:
                return []          # 無新品 → 今天到此為止,只花了 1 次請求
            print("天道:偵測到新品 → 走全分類蒐集主題分類歸屬"
                  "(只走 105 會讓新書沒有 primary)", flush=True)

    members = tiendao.collect_memberships(cats)
    new_keys = [p for p in members if not writer.has(p)]
    print(f"天道:全站 {len(members)} 件,新品 {len(new_keys)} 件", flush=True)

    def parse(key: str):
        return tiendao.parse_product(key, members[key])

    return _parse_new(new_keys, parse, writer, dry_run)


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
    ap.add_argument("--source", required=True, choices=["campus", "logos", "elim", "grace", "wdbook", "methodist", "osb",
                             "taosheng", "cclm", "cosmiccare", "mezu", "twgbr", "pctpress",
                             "tiendao"])
    ap.add_argument("--year", type=int, default=2021,
                    help="基道年份錨(檢索該年以後,日期倒序);校園忽略。預設 2021")
    ap.add_argument("--max-pages", type=int, default=0, help="列表頁處理上限(0=自動,依站方宣稱頁數)")
    ap.add_argument("--sort", default=LOGOS_SORT,
                    help=f"基道列表排序鍵(預設 {LOGOS_SORT},必須唯一,否則分頁會漏)")
    ap.add_argument("--order", default=LOGOS_ORDER, help=f"基道列表排序方向(預設 {LOGOS_ORDER})")
    ap.add_argument("--min-pages", type=int, default=0,
                    help="基道專用:掃滿 N 頁後遇整頁皆已見即停(省時模式)。"
                         "0=預設,列表全掃不早停——基道按出版日期排序,早停會漏掉新上架的舊書")
    ap.add_argument("--full-scan", action="store_true",
                    help="天道專用:跳過 105 快路徑直接走全分類(每週對帳用;"
                         "105 是站方手動推薦位,會漏掉上架日新但出版日舊的書)")
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
    elif args.source == "tiendao":
        writer = _writer("tiendao_books.jsonl", "pid")
        fresh = collect_tiendao(writer, args.max_pages, args.dry_run,
                                full_scan=args.full_scan)
        if fresh is None:
            writer.close()
            sys.exit(2)
    elif args.source == "osb":
        writer = _writer("osb_books.jsonl", "pid")
        fresh = collect_osb(writer, args.max_pages, args.dry_run)
        if fresh is None:
            writer.close()
            sys.exit(2)
    elif args.source == "taosheng":
        writer = _writer("taosheng_books.jsonl", "pid")
        fresh = collect_taosheng(writer, args.max_pages, args.dry_run)
        if fresh is None:
            writer.close()
            sys.exit(2)
    elif args.source == "cclm":
        writer = _writer("cclm_books.jsonl", "pid")
        fresh = collect_cclm(writer, args.max_pages, args.dry_run)
    elif args.source == "cosmiccare":
        writer = _writer("cosmiccare_books.jsonl", "pid")
        fresh = collect_cosmiccare(writer, args.max_pages, args.dry_run)
        if fresh is None:
            writer.close()
            sys.exit(2)
    elif args.source == "pctpress":
        writer = _writer("pctpress_books.jsonl", "pid")
        fresh = collect_pctpress(writer, args.max_pages, args.dry_run)
    elif args.source == "twgbr":
        writer = _writer("twgbr_books.jsonl", "handle")
        fresh = collect_twgbr(writer, args.max_pages, args.dry_run)
    elif args.source == "mezu":
        writer = _writer("mezu_books.jsonl", "pid")
        fresh = collect_mezu(writer, args.max_pages, args.dry_run)
        if fresh is None:
            writer.close()
            sys.exit(2)
    else:
        writer = _writer("logos_books.jsonl", "code")
        fresh = collect_logos(writer, args.year, args.max_pages, args.dry_run,
                              args.min_pages, args.sort, args.order)
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
