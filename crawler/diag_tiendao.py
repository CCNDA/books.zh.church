# -*- coding: utf-8 -*-
"""天道對帳後的六項定點診斷(只讀 jsonl,不連網不連 DB)。

verify_tiendao.py 告訴我們「哪裡有問題」,這一支告訴我們「該怎麼修」。
一次問完六題,避免來回。

  cd ~/books/crawler && venv/bin/python diag_tiendao.py > /tmp/diag.txt 2>&1
  # 貼回 /tmp/diag.txt 全文(刻意控制在可貼回的長度)

六題:
  A  63 筆 ISBN 檢查碼不合 —— 條碼欄原值 vs 規格表 ISBN 欄原值,哪一邊才對?
     (爬蟲 ISBN13_RE 只檢格式不驗檢查碼 → 錯的條碼會搶在對的 ISBN 欄之前被採用)
  B  28 組站內重複 ISBN —— 是精裝/平裝同號,還是站方誤標?
  C  90 件無規格表 —— 是不是非書(影音/禮品/單張)?
  D  10 件封面仍帶 -WxH 尺寸尾巴 —— og_image 到底長什麼樣?
  E  94 個分類 code 清單 —— 供沙箱端比對 migration 對映表漏登
  F  language 臟值 —— 「瀪體中文」「含換行」的實際 pid 與原值
  G  105「最新出版」能不能當每日新品入口(熊哥 9/2 提問)?
"""
from __future__ import annotations

import json
import re
from collections import Counter, defaultdict
from pathlib import Path

JSONL = Path(__file__).resolve().parent / "data" / "tiendao_books.jsonl"
ISBN13_FMT = re.compile(r"^97[89]\d{10}$")

SPEC_ISBN_KEYS = ("國際標準圖書編號 (ISBN)", "國際標準圖書編號(ISBN)",
                  "國際標準圖書編號", "ISBN")


def ck_ok(s: str) -> bool:
    if not ISBN13_FMT.match(s or ""):
        return False
    t = sum(int(c) * (1 if i % 2 == 0 else 3) for i, c in enumerate(s[:12]))
    return (10 - t % 10) % 10 == int(s[12])


def spec_get(spec: dict, keys) -> str:
    for k in keys:
        if spec.get(k):
            return str(spec[k])
    # 容錯:label 可能有全形括號或多餘空白
    for k, v in (spec or {}).items():
        if "ISBN" in k or "國際標準圖書" in k:
            return str(v)
    return ""


def main() -> None:
    recs = [json.loads(l) for l in JSONL.open(encoding="utf-8") if l.strip()]
    print(f"讀入 {len(recs)} 筆\n")

    # ═══ A  ISBN 檢查碼不合的來源比對 ═══
    print("=" * 70)
    print("【A】63 筆檢查碼不合:條碼欄 vs 規格表 ISBN 欄")
    print("=" * 70)
    bad = [r for r in recs if r.get("isbn") and not ck_ok(r["isbn"])]
    print(f"檢查碼不合 {len(bad)} 筆\n")
    verdict = Counter()
    rows = []
    for r in bad:
        spec = r.get("spec_all") or {}
        bar = str(spec.get("條碼") or "").replace("-", "").strip()
        isb = spec_get(spec, SPEC_ISBN_KEYS).replace("-", "").strip()
        used = r["isbn"]
        if isb and ck_ok(isb):
            v = "★ISBN欄正確→可救回"
        elif isb and isb != bar:
            v = "兩邊都錯但不同值"
        elif not isb:
            v = "只有條碼欄,無ISBN欄"
        else:
            v = "兩邊同值且都錯"
        verdict[v] += 1
        rows.append((r.get("pid"), used, bar, isb, v, (r.get("title") or "")[:18]))
    print("判定分布:")
    for v, c in verdict.most_common():
        print(f"  {v:22s} {c:4d} 筆")
    print(f"\n{'pid':>6s}  {'採用值(錯)':<14s} {'條碼欄':<14s} {'ISBN欄':<16s} 判定 / 書名")
    print("-" * 70)
    for pid, used, bar, isb, v, title in rows[:40]:
        print(f"{pid:>6s}  {used:<14s} {bar or '(空)':<14s} {isb or '(空)':<16s} {v} / {title}")
    if len(rows) > 40:
        print(f"...(其餘 {len(rows) - 40} 筆同型,已計入上方分布)")

    # ═══ B  站內重複 ISBN ═══
    print("\n" + "=" * 70)
    print("【B】28 組站內重複 ISBN:精裝/平裝同號,還是誤標?")
    print("=" * 70)
    byisbn = defaultdict(list)
    for r in recs:
        if r.get("isbn"):
            byisbn[r["isbn"]].append(r)
    dups = {k: v for k, v in byisbn.items() if len(v) > 1}
    print(f"重複組數 {len(dups)}\n")
    for isbn, group in sorted(dups.items())[:30]:
        print(f"  {isbn}  ({len(group)} 本)")
        for r in group:
            print(f"      pid {str(r.get('pid')):>6s} | {str(r.get('item_no') or ''):<10s} | "
                  f"HK${str(r.get('price_list') or ''):>8s} | "
                  f"{str(r.get('edition_statement') or '')[:14]:<14s} | "
                  f"{(r.get('title') or '')[:26]}")

    # ═══ C  無規格表的 90 件 ═══
    print("\n" + "=" * 70)
    print("【C】90 件無規格表:是否為非書(影音/禮品/單張)?")
    print("=" * 70)
    nospec = [r for r in recs if not (r.get("spec_all") or {})]
    print(f"無 spec_all {len(nospec)} 筆")
    print(f"其中無 product_type {sum(1 for r in nospec if not r.get('product_type'))} 筆\n")
    print("這些書的 primary 分類分布:")
    for c, cnt in Counter(str(r.get("category_source")) for r in nospec).most_common(20):
        print(f"  {c:18s} {cnt:4d} 件")
    print("\n分類名稱(category_text)分布:")
    for c, cnt in Counter(str(r.get("category_text")) for r in nospec).most_common(20):
        print(f"  {c:18s} {cnt:4d} 件")
    print("\n樣本 12 件:")
    for r in nospec[:12]:
        print(f"  pid {str(r.get('pid')):>6s} | {str(r.get('language') or '-'):<8s} | "
              f"isbn {str(r.get('isbn') or '-'):<14s} | {(r.get('title') or '')[:34]}")
    print(f"\n無規格表者有 isbn 的 {sum(1 for r in nospec if r.get('isbn'))} 筆"
          f"、有 ean_upc 的 {sum(1 for r in nospec if r.get('ean_upc'))} 筆")

    # ═══ D  封面尺寸尾巴 ═══
    print("\n" + "=" * 70)
    print("【D】10 件封面仍帶 -WxH:og_image 與 cover_url 實值")
    print("=" * 70)
    sized = [r for r in recs
             if re.search(r"-\d+x\d+\w*\.", str(r.get("cover_url") or ""))]
    print(f"命中 {len(sized)} 筆\n")
    for r in sized:
        print(f"  pid {str(r.get('pid')):>6s}  item_no={str(r.get('item_no') or '-')}")
        print(f"     og_image : {r.get('og_image')}")
        print(f"     cover_url: {r.get('cover_url')}")

    # ═══ E  分類 code 清單 ═══
    print("\n" + "=" * 70)
    print("【E】實抓分類 code 清單(供沙箱比對 migration 漏登)")
    print("=" * 70)
    seen = Counter()
    for r in recs:
        for c in (r.get("categories") or []):
            seen[c.get("code")] += 1
    print(f"共 {len(seen)} 個 code:")
    print(",".join(sorted(seen)))
    print("\ncode=件數(全列):")
    print(" ".join(f"{c}={n}" for c, n in sorted(seen.items())))

    # ═══ F  language 臟值 ═══
    print("\n" + "=" * 70)
    print("【F】language 臟值")
    print("=" * 70)
    for r in recs:
        lang = str(r.get("language") or "")
        why = []
        if "瀪" in lang:
            why.append("錯字「瀪」")
        if "\n" in lang or "\r" in lang or "\t" in lang:
            why.append("含換行/tab")
        if lang != lang.strip():
            why.append("前後空白")
        if why:
            print(f"  pid {str(r.get('pid')):>6s}  {'/'.join(why):<20s} "
                  f"repr={lang!r}  is_hans={r.get('is_hans')}  {(r.get('title') or '')[:20]}")
    print("\n完整 language 值分布(含 repr,看得出隱形字元):")
    for lang, c in Counter(str(r.get("language") or "") for r in recs).most_common():
        print(f"  {c:5d}  {lang!r}")

    # ═══ G  105「最新出版」能不能當每日新品入口? ═══
    print("\n" + "=" * 70)
    print("【G】105「最新出版」能否當每日新品的唯一入口?")
    print("=" * 70)
    in105, rest = [], []
    for r in recs:
        codes = {c.get("code") for c in (r.get("categories") or [])}
        (in105 if "105" in codes else rest).append(r)
    print(f"掛在 105 的商品   {len(in105)} 件")
    print(f"不在 105 的商品   {len(rest)} 件")

    def ym(r):
        return str(r.get("publish_date") or "")

    print("\n105 內的出版年分布(判斷 105 是「近期上架」還是「站方手動推薦位」):")
    from collections import Counter as C
    for y, c in sorted(C(ym(r)[:4] or "(空)" for r in in105).items(), reverse=True)[:12]:
        print(f"  {y:8s} {c:4d} 件")

    # 關鍵檢定:全站最新出版的 N 本,有幾本落在 105 內?
    dated = sorted((r for r in recs if ym(r)), key=ym, reverse=True)
    for N in (30, 60, 120):
        top = dated[:N]
        hit = sum(1 for r in top
                  if "105" in {c.get("code") for c in (r.get("categories") or [])})
        print(f"\n  全站出版日最新 {N:3d} 本 → 落在 105 內 {hit:3d} 本  "
              f"({100.0*hit/N:5.1f}%)")
        if hit < N * 0.8:
            print(f"     ⚠ 命中率不足 → 105 不是新品的完整集合,"
                  f"不可當唯一入口,需保留定期全掃對帳")
    print("\n  全站出版日最新 15 本(★=在 105 內):")
    for r in dated[:15]:
        star = "★" if "105" in {c.get("code") for c in (r.get("categories") or [])} else "  "
        print(f"    {star} {ym(r):10s} pid {str(r.get('pid')):>6s} "
              f"{(r.get('title') or '')[:30]}")

    print("\n  105 內商品的其他分類交集(看 105 是否只收中文書、不含聖經/影音):")
    other = C()
    for r in in105:
        for c in (r.get("categories") or []):
            if c.get("code") != "105":
                other[f"{c.get('code')} {c.get('path')}"] += 1
    for k, v in other.most_common(15):
        print(f"    {k[:40]:42s} {v:4d}")


if __name__ == "__main__":
    main()
