#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""基道(logos)補抓 —— 依商品碼清單逐本抓商品頁,產出可直接匯入的 delta jsonl。

搭配 tools/reconcile_logos_codes.php 使用:對帳工具找出「站方分類清單有、資料庫沒有」的
商品碼(2026-09-04 首次對帳 8,236 個),本程式把它們抓回來。

★ 兩本帳的鐵律(2026-08-28 事故的教訓):
  master jsonl 記的是「抓過沒」,資料庫記的是「上架沒」。本程式**同時**寫 delta 與 master,
  但只有 delta 餵給 import.php 才會真的進資料庫 —— 所以結尾一定會印出 import 指令,
  跑完務必再跑一次對帳工具確認差集下降。

用法:
  python3 -u recover_logos.py --codes-file=data/logos_recover_codes.txt
  python3 -u recover_logos.py --codes-file=... --limit=50      # 先抓 50 本試水溫
  python3 -u recover_logos.py --codes-file=... --no-master     # 只寫 delta,不動 master
"""
from __future__ import annotations

import argparse
import sys
import time
from datetime import datetime
from pathlib import Path

from common import JsonlWriter
from logos_crawler import DATA, parse_product   # 共用同一支解析器,避免兩套邏輯漂移

try:                       # nohup/cron 重導向時 print 會被塊緩衝,整場看不到進度
    sys.stdout.reconfigure(line_buffering=True)
except Exception:
    pass


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--codes-file", required=True,
                    help="一行一個商品碼(tools/reconcile_logos_codes.php 產出的 logos_recover_codes.txt)")
    ap.add_argument("--limit", type=int, default=0, help="只抓前 N 本(試水溫用)")
    ap.add_argument("--no-master", action="store_true",
                    help="只寫 delta,不寫入 logos_books.jsonl(預設兩邊都寫)")
    ap.add_argument("--out", default=None, help="delta 檔路徑,預設 data/new/logos_recover_<日期>.jsonl")
    args = ap.parse_args()

    src = Path(args.codes_file)
    if not src.is_absolute():
        src = Path(__file__).resolve().parent / src
    if not src.is_file():
        sys.exit(f"找不到商品碼清單:{src}")

    codes = [ln.strip() for ln in src.read_text(encoding="utf-8").splitlines() if ln.strip()]
    codes = list(dict.fromkeys(codes))           # 去重保序
    if args.limit:
        codes = codes[: args.limit]
    print(f"待補抓商品碼:{len(codes)} 個(來源 {src.name})")

    out = Path(args.out) if args.out else DATA / "new" / f"logos_recover_{datetime.now():%Y%m%d}.jsonl"
    if not out.is_absolute():
        out = Path(__file__).resolve().parent / out
    out.parent.mkdir(parents=True, exist_ok=True)

    delta = JsonlWriter(out, "code")
    master = None if args.no_master else JsonlWriter(DATA / "logos_books.jsonl", "code")

    ok = fail = skipped = 0
    start = time.time()
    try:
        for i, code in enumerate(codes, 1):
            if delta.has(code):
                skipped += 1
                continue
            try:
                rec = parse_product(code)
            except Exception as e:                # 單本失敗不中斷整批
                rec = None
                print(f"  [失敗] {code}:{e}")
            if not rec or not rec.get("title"):
                fail += 1
                print(f"  [略過] {code}:解析不到書名")
                continue
            delta.write(rec)
            if master is not None:
                master.write(rec)
            ok += 1
            if ok % 25 == 0:
                rate = ok / max(time.time() - start, 1) * 3600
                print(f"  進度 {i}/{len(codes)}:成功 {ok}、失敗 {fail}(約 {rate:.0f} 本/小時)")
    except KeyboardInterrupt:
        print("\n[中斷] 已寫入的 delta 與頁面快取都在,重跑同指令即續抓")
    finally:
        delta.close()
        if master is not None:
            master.close()
        dur = (time.time() - start) / 60
        print(f"\n完成:成功 {ok}、解析失敗 {fail}、已在 delta 內略過 {skipped},耗時 {dur:.1f} 分")
        print(f"delta:{out}")
        print("\n★ 還沒進資料庫!接著跑:")
        print(f"  php {Path(__file__).resolve().parents[1] / 'tools' / 'import.php'} "
              f"--file={out} --source=logos")
        print("  匯入後再跑一次 php tools/reconcile_logos_codes.php,差集應大幅下降。")


if __name__ == "__main__":
    main()
