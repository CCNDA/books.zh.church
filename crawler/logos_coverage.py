# -*- coding: utf-8 -*-
"""基道列表分頁涵蓋率體檢(離線,不發任何網路請求)。

背景(2026-08-28):new_arrivals.py 改全掃後,實測 197 頁、宣稱 3,934 項,
但去重後只收到 3,061 個商品碼 —— 少了約 22%。懷疑站方分頁不穩定
(sort=FirstPublishDate 有大量同日期並列,ASP 以 offset 取頁時列序會浮動,
造成有些列重複出現在多頁、有些列從頭到尾沒被顯示過)。

本工具**只讀 crawler/cache/logos 裡上一次掃描留下的頁面快取**,重算:
  - 每頁的商品碼數(找出不足 20 筆的頁)
  - 去重後的總碼數 vs 站方宣稱總數
  - 重複碼:同一個 code 出現在幾頁、重複佔掉多少格
  - 缺頁:快取裡找不到的頁(代表上次掃描時就沒抓到)

有了重複格數,就能判斷「少掉的 873 筆」是不是分頁重複造成的;
若是,解法是多做一趟不同排序(例如 order=ASC)取聯集,而非再加掃描頁數。

用法(主機,先跑過一次 new_arrivals.py --source logos 讓快取是新的):
  python3 logos_coverage.py                                    # 預設 year=2021、197 頁、sort=Code/ASC
  python3 logos_coverage.py --sort FirstPublishDate --order DESC   # 對帳舊的日期排序
  python3 logos_coverage.py --dump-dup dup.txt                 # 把重複碼與其頁碼寫檔

2026-08-28 實測(sort=FirstPublishDate/DESC):3,934 格 = 去重 3,061 + 重複 873,對帳差 0,
涵蓋率 77.8%。重複碼集中在**相鄰頁**(如頁 62-65、頁 29-31),正是同一個並列群組
每次查詢回傳任意順序的特徵 → 改用唯一鍵 sort=Code 排序。
"""
from __future__ import annotations

import argparse
from collections import defaultdict
from pathlib import Path

import logos_crawler as logos
from common import _cache_path


def list_url(year: int, page: int, sort: str, order: str) -> str:
    u = f"{logos.SEARCH}&field=year&text={year}&sort={sort}&order={order}"
    return u if page == 1 else f"{u}&{logos.PAGE_PARAM}={page}"


def read_cached(url: str) -> str | None:
    import gzip
    cp = _cache_path(logos.CACHE, url, None)
    if not cp.exists():
        return None
    return gzip.decompress(cp.read_bytes()).decode("utf-8", errors="replace")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--year", type=int, default=2021)
    ap.add_argument("--pages", type=int, default=197)
    ap.add_argument("--sort", default="Code", help="要對帳的排序鍵(需與掃描時相同,預設 Code)")
    ap.add_argument("--order", default="ASC", help="排序方向(需與掃描時相同,預設 ASC)")
    ap.add_argument("--dump-dup", default=None, help="把重複碼與頁碼寫入指定檔案")
    args = ap.parse_args()

    where: dict[str, list[int]] = defaultdict(list)
    slots = 0
    missing_pages: list[int] = []
    short_pages: list[tuple[int, int]] = []
    claimed = None

    for p in range(1, args.pages + 1):
        html = read_cached(list_url(args.year, p, args.sort, args.order))
        if html is None:
            missing_pages.append(p)
            continue
        if claimed is None:
            claimed = logos.extract_total(html)
        codes = logos.extract_codes(html)
        slots += len(codes)
        if len(codes) < 20 and p != args.pages:
            short_pages.append((p, len(codes)))
        for c in codes:
            where[c].append(p)

    dups = {c: ps for c, ps in where.items() if len(ps) > 1}
    dup_slots = sum(len(ps) - 1 for ps in dups.values())

    print(f"站方宣稱:{claimed} 項")
    print(f"讀到頁面:{args.pages - len(missing_pages)}/{args.pages} 頁")
    print(f"格位合計:{slots}(每頁碼數加總,含重複)")
    print(f"去重碼數:{len(where)}")
    print(f"重複碼數:{len(dups)} 個,佔掉 {dup_slots} 格")
    if claimed:
        print(f"對帳:{len(where)} 去重 + {dup_slots} 重複 = {len(where) + dup_slots}"
              f"  vs  宣稱 {claimed}(差 {claimed - len(where) - dup_slots})")
        print(f"涵蓋率:{len(where) / claimed * 100:.1f}%,推估未曾顯示 {claimed - len(where)} 筆")
    if missing_pages:
        print(f"[警告] 快取缺頁:{missing_pages[:20]}{' …' if len(missing_pages) > 20 else ''}")
    if short_pages:
        print(f"[注意] 不足 20 筆的頁:{short_pages[:20]}{' …' if len(short_pages) > 20 else ''}")

    top = sorted(dups.items(), key=lambda kv: -len(kv[1]))[:10]
    if top:
        print("重複最多的 10 個碼:")
        for c, ps in top:
            print(f"  {c}:{len(ps)} 次,頁 {ps[:12]}{' …' if len(ps) > 12 else ''}")

    if args.dump_dup:
        with open(args.dump_dup, "w", encoding="utf-8") as f:
            for c, ps in sorted(dups.items(), key=lambda kv: -len(kv[1])):
                f.write(f"{c}\t{len(ps)}\t{ps}\n")
        print(f"重複明細已寫入 {args.dump_dup}")


if __name__ == "__main__":
    main()
