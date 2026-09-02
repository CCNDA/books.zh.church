# -*- coding: utf-8 -*-
"""天道書樓抓取結果對帳(只讀 jsonl,不連網、不連 DB)。

用途:全量抓完之後、import dry-run 之前跑這一支。
沙箱連不到主機也讀不到主機上的 jsonl,所以對帳必須在主機跑、輸出貼回。

  cd ~/books/crawler && venv/bin/python verify_tiendao.py

為什麼要有這一支(專案鐵律七):爬蟲自印「完成:本次入檔 1233 件」只證明
迴圈跑完,不證明欄位有解析到。8/29 基道那次就是 log 全綠、出版日期覆蓋率 0%。
這支專門查「跑完了」與「抓對了」之間的落差。

檢查項:
  1  件數與 pid 唯一性(對照走訪階段自印的 1233)
  2  欄位覆蓋率(對照 9/1 偵察對本站的品質預期)
  3  黃金樣本價格實測(9/1 probe 人工確認過的兩本:905=330、163=125)
  4  ISBN13 檢查碼與站內重複
  5  分類覆蓋 + **對映表漏登檢查**(讀 migration sql,不需連 DB)
  6  publish_date 補零與格式分布
  7  language / is_hans
  8  佔位符與描述噪音殘留
  9  **最長欄位值 vs schema 上限**(匯入前必量)
 10  封面 URL 形態(-600x315w 殘留 = 縮圖當原圖)
"""
from __future__ import annotations

import json
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

HERE = Path(__file__).resolve().parent
JSONL = HERE / "data" / "tiendao_books.jsonl"
MAP_SQL = HERE.parent / "database" / "migrations" / "2026-09-01_tiendao_category_map.sql"

EXPECTED_UNIQUE = 1233          # 走訪階段自印的去重後件數
GOLDEN_PRICES = {"905": "330", "163": "125"}   # 9/1 probe 人工確認

# schema 上限(database/migrations/001 + 004,匯入前對照用)
LIMITS = {
    "title": 255, "title_en": 255, "authors_raw": 255, "translators_raw": 255,
    "publisher": 100, "publish_date": 10, "isbn": 13, "cover_url": 500,
    "source_url": 500, "source": 50, "edition_statement": 100,
    "item_no": 30, "availability": 50, "series_text": 150,
    "category_source": 20, "category_text": 150, "language": 50,
}
# 這些是 TEXT/MEDIUMTEXT,無 VARCHAR 上限,只報最長值供估 extra 大小
TEXTISH = ("summary", "desc_raw", "toc", "author_intro", "translator_intro")

ISBN13_RE = re.compile(r"^97[89]\d{10}$")
PLACEHOLDERS = {"-", "–", "—", "‒", "－", "─", "n/a", "N/A", "na", "無", "沒有", "不適用", "."}
NOISE = ("發表點評", "請先 登錄", "更多來自這個品牌", "加入購物車", "產品規格")


def isbn13_ok(s: str) -> bool:
    if not ISBN13_RE.match(s):
        return False
    tot = sum(int(c) * (1 if i % 2 == 0 else 3) for i, c in enumerate(s[:12]))
    return (10 - tot % 10) % 10 == int(s[12])


def pct(n: int, d: int) -> str:
    return f"{n:5d}/{d}  {100.0 * n / d:5.1f}%" if d else "n/a"


def main() -> int:
    if not JSONL.exists():
        print(f"找不到 {JSONL}", file=sys.stderr)
        return 1

    recs, bad = [], 0
    with JSONL.open(encoding="utf-8") as fh:
        for ln, line in enumerate(fh, 1):
            line = line.strip()
            if not line:
                continue
            try:
                recs.append(json.loads(line))
            except json.JSONDecodeError as e:
                bad += 1
                print(f"  ⚠ 第 {ln} 行 JSON 壞掉:{e}")
    n = len(recs)
    print("=" * 66)
    print(f"天道書樓抓取對帳  {JSONL}")
    print("=" * 66)

    # ── 1 件數與 pid 唯一性 ──
    pids = [r.get("pid") for r in recs]
    dup_pid = [p for p, c in Counter(pids).items() if c > 1]
    print(f"\n【1】件數與唯一性")
    print(f"  jsonl 行數(可解析)  {n}")
    print(f"  JSON 壞行            {bad}")
    print(f"  唯一 pid             {len(set(pids))}")
    print(f"  重複 pid             {len(dup_pid)}" + (f"  例:{dup_pid[:5]}" if dup_pid else ""))
    diff = n - EXPECTED_UNIQUE
    flag = "✓ 相符" if diff == 0 else f"⚠ 與走訪階段自印的 {EXPECTED_UNIQUE} 差 {diff:+d}"
    print(f"  對照走訪去重數字     {flag}")

    # ── 2 欄位覆蓋率 ──
    print(f"\n【2】欄位覆蓋率(分母 {n})")
    fields = ["title", "title_en", "authors_raw", "translators_raw", "publisher",
              "publish_date", "first_edition_raw", "edition_statement", "isbn",
              "ean_upc", "page_count", "series_text", "language", "price_list",
              "item_no", "availability", "cover_url", "summary", "toc",
              "author_intro", "spec_all", "categories", "category_source",
              "product_type", "desc_sections"]
    for f in fields:
        have = sum(1 for r in recs if r.get(f) not in (None, "", [], {}))
        mark = ""
        if f in ("title", "cover_url", "categories", "spec_all") and have < n:
            mark = "  ⚠ 應接近 100%"
        if f in ("isbn", "publisher", "authors_raw", "publish_date") and have < n * 0.85:
            mark = "  ⚠ 偵察預期本站此欄近乎全滿"
        if f == "price_list" and have < n * 0.9:
            mark = "  ⚠ 價格是本站已知陷阱,覆蓋率低要查"
        print(f"  {f:20s} {pct(have, n)}{mark}")

    # ── 3 黃金樣本價格 ──
    print(f"\n【3】黃金樣本價格(9/1 probe 人工確認,爬蟲曾把關聯商品價當本書價)")
    by_pid = {r.get("pid"): r for r in recs}
    for pid, want in GOLDEN_PRICES.items():
        r = by_pid.get(pid)
        if r is None:
            print(f"  pid {pid:>6s}  ✗ 不在檔案裡(走訪漏掉?)")
            continue
        got = str(r.get("price_list") or "")
        ok = got.split(".")[0] == want
        print(f"  pid {pid:>6s}  期望 HK${want:>5s}  實得 HK${got or '(空)':>5s}  "
              f"{'✓' if ok else '✗ 價格解析仍有誤,不可 import'}   {(r.get('title') or '')[:24]}")

    # ── 4 ISBN ──
    print(f"\n【4】ISBN13")
    isbns = [r["isbn"] for r in recs if r.get("isbn")]
    badsum = [i for i in isbns if not isbn13_ok(i)]
    dup_isbn = {i: c for i, c in Counter(isbns).items() if c > 1}
    print(f"  有 isbn              {pct(len(isbns), n)}")
    print(f"  檢查碼不合           {len(badsum)}" + (f"  例:{badsum[:5]}" if badsum else "  ✓"))
    print(f"  站內重複 ISBN 組數   {len(dup_isbn)}"
          + (f"  例:{list(dup_isbn.items())[:5]}" if dup_isbn else "  ✓"))
    print(f"  ean_upc(非 ISBN 條碼) {sum(1 for r in recs if r.get('ean_upc'))}")

    # ── 5 分類覆蓋 + 對映表漏登 ──
    print(f"\n【5】分類覆蓋與對映表漏登")
    seen = Counter()
    nocat = 0
    for r in recs:
        cs = r.get("categories") or []
        if not cs:
            nocat += 1
        for c in cs:
            seen[c.get("code")] += 1
    print(f"  出現過的分類 code    {len(seen)}")
    print(f"  完全無分類的書       {nocat}" + ("  ⚠ 這些書 apply 後會無處可歸" if nocat else "  ✓"))
    mapped = set()
    if MAP_SQL.exists():
        mapped = set(re.findall(r"\('([0-9_]+)'\s*,", MAP_SQL.read_text(encoding="utf-8")))
        print(f"  對映表登錄 code      {len(mapped)}  ({MAP_SQL.name})")
        missing = [(c, seen[c]) for c in seen if c not in mapped]
        missing.sort(key=lambda x: -x[1])
        if missing:
            print(f"  ⚠ 實抓但**未登錄**    {len(missing)} 個 —— apply 會漏掉這些書:")
            for c, cnt in missing[:20]:
                print(f"      {c:18s} {cnt:5d} 件")
        else:
            print(f"  實抓但未登錄         0  ✓ 對映表完整")
        unused = sorted(c for c in mapped if c not in seen)
        if unused:
            print(f"  登錄但無商品         {len(unused)} 個(空分類,正常):{unused[:12]}")
    else:
        print(f"  ⚠ 找不到 {MAP_SQL},跳過漏登檢查")
    print(f"  件數最多的 8 個分類:")
    for c, cnt in seen.most_common(8):
        print(f"      {c:18s} {cnt:5d} 件")

    # ── 6 publish_date ──
    print(f"\n【6】publish_date 格式(補零地雷,8/29 基道踩過)")
    shapes = Counter()
    unpadded = []
    for r in recs:
        d = r.get("publish_date")
        if not d:
            shapes["(空)"] += 1
            continue
        shapes[re.sub(r"\d", "N", str(d))] += 1
        if re.match(r"^\d{4}-\d$", str(d)):
            unpadded.append((r.get("pid"), d))
    for s, c in shapes.most_common():
        print(f"  {s:14s} {c:5d}")
    print(f"  未補零(YYYY-M)     {len(unpadded)}"
          + (f"  ⚠ 例:{unpadded[:5]}" if unpadded else "  ✓"))

    # ── 7 語言 / is_hans ──
    print(f"\n【7】語言欄與 is_hans")
    for lang, c in Counter(str(r.get("language") or "(空)") for r in recs).most_common(10):
        print(f"  {lang:16s} {c:5d}")
    print(f"  is_hans=True         {sum(1 for r in recs if r.get('is_hans'))}")

    # ── 8 佔位符與噪音 ──
    print(f"\n【8】佔位符與描述噪音殘留")
    ph = defaultdict(list)
    for r in recs:
        for f in ("authors_raw", "translators_raw", "publisher", "series_text", "language"):
            v = (r.get(f) or "").strip()
            if v in PLACEHOLDERS:
                ph[f].append(r.get("pid"))
    if ph:
        for f, ps in ph.items():
            print(f"  ⚠ {f:18s} {len(ps)} 筆是佔位符,會被當人名/社名匯入:{ps[:5]}")
    else:
        print(f"  佔位符殘留           0  ✓")
    for kw in NOISE:
        hit = [r.get("pid") for r in recs
               if any(kw in str(r.get(f) or "") for f in ("summary", "toc", "desc_raw"))]
        mark = "  ⚠ 會被搜尋索引到" if hit else "  ✓"
        print(f"  「{kw}」{'':<{max(0, 10 - len(kw))}} {len(hit):5d}{mark}")

    # ── 9 最長值 vs schema 上限 ──
    print(f"\n【9】最長欄位值 vs schema 上限(超界則 import 會截斷或報錯)")
    for f, lim in LIMITS.items():
        vals = [(len(str(r[f])), r.get("pid")) for r in recs if r.get(f)]
        if not vals:
            print(f"  {f:18s} 無值")
            continue
        mx, pid = max(vals)
        over = sum(1 for L, _ in vals if L > lim)
        mark = f"  ⚠ 超界 {over} 筆(最長 pid {pid})" if over else "  ✓"
        print(f"  {f:18s} 最長 {mx:6d} / 上限 {lim:4d}{mark}")
    print(f"  ── 以下為 TEXT 欄,無 VARCHAR 上限,列最長值供估 extra 大小 ──")
    for f in TEXTISH:
        vals = [len(str(r[f])) for r in recs if r.get(f)]
        if vals:
            print(f"  {f:18s} 最長 {max(vals):6d}  平均 {sum(vals) // len(vals):5d}")
    ex = [len(json.dumps(r.get("spec_all") or {}, ensure_ascii=False)) for r in recs]
    print(f"  spec_all(JSON)     最長 {max(ex) if ex else 0:6d}  "
          f"(extra 為 MEDIUMTEXT,16MB,無虞)")

    # ── 10 封面形態 ──
    print(f"\n【10】封面 URL 形態")
    thumb = [r.get("pid") for r in recs if "/image/cache/" in str(r.get("cover_url") or "")]
    sized = [r.get("pid") for r in recs if re.search(r"-\d+x\d+\w*\.", str(r.get("cover_url") or ""))]
    nocov = [r.get("pid") for r in recs if not r.get("cover_url")]
    print(f"  仍指向 /image/cache/ {len(thumb)}" + (f"  ⚠ 縮圖當原圖:{thumb[:5]}" if thumb else "  ✓"))
    print(f"  仍帶 -WxH 尺寸尾巴   {len(sized)}" + (f"  ⚠ 例:{sized[:5]}" if sized else "  ✓"))
    print(f"  無封面               {len(nocov)}" + (f"  例:{nocov[:5]}" if nocov else "  ✓"))

    print("\n" + "=" * 66)
    print("對帳完成。上面每一個 ⚠ 都要先解掉或明確接受,才可以跑 import。")
    print("=" * 66)
    return 0


if __name__ == "__main__":
    sys.exit(main())
