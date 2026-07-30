# -*- coding: utf-8 -*-
"""校園快取離線重解析:不重爬,從 cache/campus 重建 campus_books.jsonl。

用途:爬蟲解析器修正後(尾端錨定 meta keywords、跨行誤抓、全形冒號等),
既有 JSONL 的髒資料不會自動更正——本工具把「已入庫的每個 product_id」
用**目前的解析器**從快取重新解析,輸出全新 JSONL(原檔備份為 .bak)。

用法(爬蟲停止時執行;Windows):
  python -X utf8 reparse_campus.py
"""
from __future__ import annotations

import json
import shutil
from pathlib import Path

import campus_crawler as cc

DATA = Path(__file__).parent / "data"
SRC = DATA / "campus_books.jsonl"


def main():
    if not SRC.exists():
        print("找不到 campus_books.jsonl")
        return
    # 舊檔:取 pid → category_source(重解析時保留分類出處)
    pids: dict[str, str | None] = {}
    with SRC.open(encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            try:
                r = json.loads(line)
                pids[str(r["product_id"])] = r.get("category_source")
            except (json.JSONDecodeError, KeyError):
                pass
    print(f"舊檔 {len(pids)} 筆,開始以現行解析器重解析(全走快取,無網路請求)...")

    bak = SRC.with_suffix(".jsonl.bak")
    shutil.copy2(SRC, bak)
    out = SRC.with_suffix(".jsonl.new")
    ok = skip = 0
    with out.open("w", encoding="utf-8") as f:
        for i, (pid, cat) in enumerate(pids.items(), 1):
            try:
                rec = cc.parse_product(pid, cat)  # 快取命中,不發請求
            except Exception as ex:
                print(f"  [跳過 {pid}] {ex}")
                skip += 1
                continue
            if rec:
                f.write(json.dumps(rec, ensure_ascii=False) + "\n")
                ok += 1
            else:
                skip += 1
            if i % 500 == 0:
                print(f"  {i}/{len(pids)}")
    out.replace(SRC)
    print(f"完成:{ok} 筆重建、{skip} 筆跳過;原檔備份 {bak.name}")


if __name__ == "__main__":
    main()
