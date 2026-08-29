# -*- coding: utf-8 -*-
"""基道商品頁離線重解析 —— 補回 document.write 區塊的欄位(不發任何網路請求)。

背景(2026-08-29,Asana 1217967657916253):
  基道商品頁的 ISBN／出版社／出版日期／尺寸／頁數／重量是由 <script> 的 document.write
  動態寫出的,BeautifulSoup 不執行 JS,所以爬蟲那組純文字正則從建站以來一次都沒命中。
  實測 22,855 筆:publish_date 有值 0%;isbn 有值 62.1%,而「商品碼本身就是 ISBN」佔 62.9%
  —— 現有 ISBN 全靠商品碼撿來,解析器貢獻為零。

  修法已進 logos_crawler.parse_info_script();商品頁快取都還在(crawler/cache/logos),
  所以不必重抓網站,離線重跑解析即可。

本工具做兩件事:
  1. 逐筆讀 master jsonl 的 source_url → 找快取頁 → 重解析 → **只補空欄位,不覆蓋既有值**
  2. 有變動的紀錄寫出 data/logos_reparsed.jsonl(完整紀錄),交給
     tools/backfill_logos_fields.php 去補資料庫(import.php 會跳過已存在的 source_url,
     不能拿來補欄位,故另寫工具)

--update-master 會把補好的紀錄寫回 master jsonl(先寫暫存檔再 rename,原檔另存 .bak),
讓之後的每日流程也帶著這些欄位。

用法(主機):
  python3 reparse_logos.py --dry-run          # 只統計,不寫檔
  python3 reparse_logos.py                    # 產出 data/logos_reparsed.jsonl
  python3 reparse_logos.py --update-master    # 同時把 master jsonl 補好
  python3 reparse_logos.py --limit 200        # 試跑
"""
from __future__ import annotations

import argparse
import gzip
import json
import re
from pathlib import Path

import logos_crawler as logos
from common import _cache_path

HERE = Path(__file__).parent
DATA = HERE / "data"
MASTER = DATA / "logos_books.jsonl"
OUT = DATA / "logos_reparsed.jsonl"

# 站方標籤 → 紀錄欄位,沿用爬蟲那份對照,避免兩處各寫一份而走鐘
FIELD_MAP = logos._JS_FIELD_MAP


def cached_html(url: str) -> str | None:
    cp = _cache_path(logos.CACHE, url, None)
    if not cp.exists():
        return None
    try:
        return gzip.decompress(cp.read_bytes()).decode("utf-8", errors="replace")
    except OSError:
        return None


def enrich(rec: dict) -> tuple[dict, list[str]]:
    """回傳(補過的紀錄, 這次補了哪些欄位)。只補空欄位。"""
    url = rec.get("source_url")
    if not url:
        return rec, []
    html = cached_html(url)
    if html is None:
        return rec, ["__nocache__"]
    spec = logos.parse_info_script(html)
    if not spec:
        return rec, ["__nospec__"]

    filled: list[str] = []
    if rec.get("spec_all") != spec:
        rec["spec_all"] = spec
        filled.append("spec_all")
    for label, val in spec.items():
        key = FIELD_MAP.get(label)
        if not key or rec.get(key):
            continue
        if key == "page_count":
            m = re.search(r"\d+", val)
            if not m:
                continue
            val = m.group(0)
        elif key == "publish_date":
            val = logos.norm_date(val)
        elif key == "dimensions" and "mm" not in val.lower():
            val = f"{val} mm"
        rec[key] = val
        filled.append(key)
    return rec, filled


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry-run", action="store_true", help="只統計,不寫任何檔")
    ap.add_argument("--update-master", action="store_true",
                    help="把補好的紀錄寫回 master jsonl(原檔另存 .bak)")
    ap.add_argument("--limit", type=int, default=0, help="只處理前 N 筆(試跑)")
    ap.add_argument("--master", default=str(MASTER))
    ap.add_argument("--out", default=str(OUT))
    args = ap.parse_args()

    master = Path(args.master)
    if not master.exists():
        print(f"找不到 {master}")
        return

    stats = {"total": 0, "nocache": 0, "nospec": 0, "changed": 0}
    per_field: dict[str, int] = {}
    changed_recs: list[dict] = []
    all_recs: list[dict] = []

    with master.open(encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            try:
                rec = json.loads(line)
            except json.JSONDecodeError:
                continue
            stats["total"] += 1
            if args.limit and stats["total"] > args.limit:
                all_recs.append(rec)
                continue
            rec, filled = enrich(rec)
            all_recs.append(rec)
            if filled == ["__nocache__"]:
                stats["nocache"] += 1
                continue
            if filled == ["__nospec__"]:
                stats["nospec"] += 1
                continue
            real = [k for k in filled if k != "spec_all"]
            if real:
                stats["changed"] += 1
                changed_recs.append(rec)
                for k in real:
                    per_field[k] = per_field.get(k, 0) + 1
            if stats["total"] % 2000 == 0:
                print(f"  …已處理 {stats['total']} 筆(補到 {stats['changed']} 筆)", flush=True)

    print(f"\n總計 {stats['total']} 筆")
    print(f"  快取缺頁(需重抓):{stats['nocache']}")
    print(f"  頁面無產品資訊區塊:{stats['nospec']}")
    print(f"  補到欄位的紀錄:{stats['changed']}")
    print("  各欄位補回筆數:")
    for k, v in sorted(per_field.items(), key=lambda kv: -kv[1]):
        print(f"    {k:16} {v}")

    if args.dry_run:
        print("\ndry-run:未寫任何檔")
        return

    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", encoding="utf-8") as f:
        for rec in changed_recs:
            f.write(json.dumps(rec, ensure_ascii=False) + "\n")
    print(f"\n已寫出 {len(changed_recs)} 筆 → {out}")
    print(f"下一步:php tools/backfill_logos_fields.php --file={out.resolve()} --dry-run")

    if args.update_master:
        bak = master.with_suffix(master.suffix + ".bak")
        tmp = master.with_suffix(master.suffix + ".tmp")
        with tmp.open("w", encoding="utf-8") as f:
            for rec in all_recs:
                f.write(json.dumps(rec, ensure_ascii=False) + "\n")
        if not bak.exists():          # 只留第一次的原始備份,重跑不覆蓋
            master.replace(bak)
        else:
            master.unlink()
        tmp.replace(master)
        print(f"master jsonl 已更新({len(all_recs)} 筆);原檔備份:{bak}")


if __name__ == "__main__":
    main()
