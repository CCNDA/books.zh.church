# -*- coding: utf-8 -*-
"""基道 logos 非書籍偵測(離線)。

logos 資料無分類欄位,故以「標題結尾格式標記」為主訊號分三層:

  A. 確定非書(影音/周邊):標題括號含 CD/DVD/VCD/MP3/MP4/USB/藍光/NFC/下載卡碼,
     且非「附贈光碟」、非歌書/樂譜/手冊/繪本 → 可自動下架。
  B. 待人工複核:標題含 拼圖/貼紙/文具/月曆/禮盒… 等周邊關鍵字但無影音標記
     (假陽性多:婚禮聖經禮盒其實是聖經、耶穌拼圖是書) → 逐筆看。
  C. 其餘 → 書。

輸出:
  - 主控台:三層筆數 + 樣本 + B 層完整清單(供複核)
  - data/logos_downpublish.sql :A 層 store_code 精確下架 SQL(直接餵 Navicat)
  - data/logos_review.tsv       :B 層候選(store_code<TAB>title),複核後自行增補 SQL

用法(Windows):  python -X utf8 analyze_logos_nonbooks.py
不改動 logos_books.jsonl,只讀取。
"""
from __future__ import annotations
import json, re
from pathlib import Path

DATA = Path(__file__).parent / "data"
SRC = DATA / "logos_books.jsonl"

# 括號內出現影音/載體標記(全形或半形括號)
AV = re.compile(r"[（(][^）)]*(CD|VCD|DVD|MP4|MP3|藍光|Blu-?ray|USB|卡拉OK|NFC|下載[卡碼])[^）)]*[）)]", re.I)
# 「附CD/附DVD…」= 書的加贈光碟,保留
BONUS = re.compile(r"附[^）)]{0,8}(CD|VCD|DVD|MP4|MP3|USB|光碟)", re.I)
# 書類覆蓋:歌書/樂譜/手冊/繪本等即使附光碟也算書
KEEP = re.compile(r"(歌書|樂譜|歌譜|曲集|詩集|手冊|研習|課本|學員本|導師本|繪本|故事書|讀本)")
# B 層周邊/文具/曆(需人工複核)
MERCH = re.compile(
    r"(拼圖|貼紙|文具|筆記本|記事本|原子筆|鋼筆|海報|明信片|賀卡|書籤|"
    r"襟章|磁貼|磁石|保溫|水樽|環保袋|布袋|背包|擺設|飾物|公仔|模型|"
    r"禮盒|禮品|禮物套|文件夾|紙牌遊戲|手工咭|月曆|年曆|桌曆|掛曆|檯曆|杯)"
)


def main():
    if not SRC.exists():
        print("找不到", SRC); return
    A = []   # 確定非書
    B = []   # 待複核
    total = 0
    for line in SRC.open(encoding="utf-8"):
        line = line.strip()
        if not line:
            continue
        try:
            r = json.loads(line)
        except json.JSONDecodeError:
            continue
        total += 1
        title = r.get("title") or ""
        code = str(r.get("code") or "")
        if AV.search(title) and not BONUS.search(title) and not KEEP.search(title):
            A.append((code, title))
        elif MERCH.search(title) and not KEEP.search(title):
            B.append((code, title))

    print(f"總筆數:{total}")
    print(f"A 確定非書(影音/載體,可自動下架):{len(A)}")
    print(f"B 待人工複核(周邊/文具/曆):        {len(B)}")
    print(f"C 書(其餘):                        {total - len(A) - len(B)}")

    print("\n=== A 樣本(前 25)===")
    for code, t in A[:25]:
        print(f"  {code} | {t[:44]}")

    print(f"\n=== B 待複核完整清單({len(B)} 筆;逐筆判斷是否下架)===")
    for code, t in B:
        print(f"  {code} | {t[:52]}")

    codes = sorted({c for c, _ in A if c})
    sqlpath = DATA / "logos_downpublish.sql"
    with sqlpath.open("w", encoding="utf-8") as f:
        f.write("-- 基道非書籍(A 層:影音/載體)自動下架\n")
        f.write("-- 於重建流程步驟 5b、校園下架之後執行\n")
        f.write(f"-- 產生自 analyze_logos_nonbooks.py,共 {len(codes)} 個商品代碼\n")
        f.write("-- 註:商品代碼存於 identifiers(id_type='STORE',掛 edition_id),故三表 join\n")
        if codes:
            inlist = ",".join("'" + c.replace("'", "''") + "'" for c in codes)
            f.write("UPDATE books b\n")
            f.write("JOIN editions e   ON e.book_id = b.book_id\n")
            f.write("JOIN identifiers i ON i.edition_id = e.edition_id\n")
            f.write("SET b.is_published = 0\n")
            f.write("WHERE b.source = 'logos' AND i.id_type = 'STORE'\n")
            f.write(f"  AND i.id_value IN ({inlist});\n")
        else:
            f.write("-- (無 A 層項目)\n")

    revpath = DATA / "logos_review.tsv"
    with revpath.open("w", encoding="utf-8") as f:
        f.write("store_code\ttitle\n")
        for code, t in B:
            f.write(f"{code}\t{t}\n")

    print(f"\n已產出:\n  {sqlpath}  (A 層下架 SQL,可直接餵 Navicat)\n  {revpath}  (B 層複核清單)")


if __name__ == "__main__":
    main()
