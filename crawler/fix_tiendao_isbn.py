# -*- coding: utf-8 -*-
"""天道 jsonl 離線修正:ISBN 三段式 + 福音單張假號清空 + language 正規化。

**不發任何網路請求、不連 DB。** 條碼欄與 ISBN 欄原值都已存在 rec["spec_all"] 裡,
所以不必重解析 HTML(更不必重抓 1233 頁)—— 直接對 jsonl 重算即可。

規則的單一實作在 tiendao_isbn.py,爬蟲本體共用同一份,避免兩套邏輯漂移。

═══ 背景(2026-09-02,Asana 1217984797715279)═══

verify 對帳擋下 import 的兩項:
  1. 63 筆 ISBN 檢查碼不合 —— 站方把舊書 ISBN10 加 978 前綴卻沒重算檢查碼
  2. 福音單張 TGP001-031 帶著天註書的 ISBN(站方在 ISBN 欄貼了一串連號)

兩項都**必須在 import 之前**處理:import.php:359 只要 ISBN13 相同就合併成同一個
book(Work),而流程是 import → apply → classify,下架發生在合併之後,事後拆不開。

用法(主機 ~/books/crawler):
  venv/bin/python fix_tiendao_isbn.py --dry-run    # 只出報表,不動檔案
  venv/bin/python fix_tiendao_isbn.py             # 寫回(原檔另存 .bak)
"""
from __future__ import annotations

import argparse
import json
import shutil
import sys
from datetime import datetime
from collections import Counter, defaultdict
from pathlib import Path

from tiendao_isbn import (is_fake_isbn_leaflet, mislabeled_reason,
                          normalize_language, resolve)

HERE = Path(__file__).resolve().parent
JSONL = HERE / "data" / "tiendao_books.jsonl"

SPEC_ISBN_KEYS = ("國際標準圖書編號 (ISBN)", "國際標準圖書編號(ISBN)",
                  "國際標準圖書編號", "ISBN")


def spec_isbn(spec: dict) -> str:
    for k in SPEC_ISBN_KEYS:
        if spec.get(k):
            return str(spec[k])
    for k, v in (spec or {}).items():
        if "ISBN" in k or "國際標準圖書" in k:
            return str(v)
    return ""


def dup_groups(recs: list[dict], field: str = "isbn") -> dict:
    by = defaultdict(list)
    for r in recs:
        if r.get(field):
            by[r[field]].append(r.get("pid"))
    return {k: v for k, v in by.items() if len(v) > 1}


def main() -> int:
    ap = argparse.ArgumentParser(description="天道 ISBN / language 離線修正")
    ap.add_argument("--dry-run", action="store_true", help="只出報表,不寫檔")
    ap.add_argument("--file", default=str(JSONL))
    args = ap.parse_args()

    path = Path(args.file)
    if not path.exists():
        print(f"找不到 {path}", file=sys.stderr)
        return 1
    recs = [json.loads(l) for l in path.open(encoding="utf-8") if l.strip()]
    print(f"讀入 {len(recs)} 筆  {path}\n")

    before_dups = dup_groups(recs)
    before_isbn = sum(1 for r in recs if r.get("isbn"))

    stat = Counter()
    restored, need_review, conflicts, invalids, leaflets, langfix = [], [], [], [], [], []
    mislabeled = []

    for r in recs:
        spec = r.get("spec_all") or {}
        extra = r.setdefault("extra", {}) if isinstance(r.get("extra"), dict) else {}
        if not isinstance(r.get("extra"), dict):
            r["extra"] = extra

        # ── 1  福音單張:站方假號,清空並存證 ──
        if is_fake_isbn_leaflet(r):
            if r.get("isbn"):
                extra["isbn_fake_leaflet"] = r["isbn"]
                leaflets.append((r.get("pid"), r["isbn"], r.get("item_no"),
                                 (r.get("title") or "")[:26]))
                r["isbn"] = None
            stat["單張假號清空"] += 1
            r.pop("ean_upc", None)

        # ── 1.5  已知站方誤標(同 ISBN 掛兩本不相干的書)──
        elif (why := mislabeled_reason(r)) is not None:
            if r.get("isbn"):
                extra["isbn_mislabeled"] = {"value": r["isbn"], "why": why}
                mislabeled.append((r.get("pid"), r["isbn"],
                                   (r.get("title") or "")[:30]))
                r["isbn"] = None
            stat["已知誤標清空"] += 1

        # ── 2  ISBN 三段式 ──
        else:
            res = resolve(spec.get("條碼"), spec_isbn(spec))
            old = r.get("isbn")
            stat[f"ISBN:{res['status']}"] += 1
            d = res["detail"]
            if res["status"] == "ok":
                r["isbn"] = res["isbn"]
                if res["isbn"] != old:
                    restored.append((r.get("pid"), old, res["isbn"],
                                     d.get("via_barcode"), d.get("via_isbn_field"),
                                     (r.get("title") or "")[:24]))
                    extra["isbn_source_raw"] = {k: v for k, v in
                                                (d.get("raw") or {}).items() if v}
                # 只有一路通過驗證 → 建議人工複核(另一路的值與它不同)
                if not d.get("agreed") and (d.get("raw") or {}).get("barcode") \
                        and (d.get("raw") or {}).get("isbn_field"):
                    need_review.append((r.get("pid"), res["isbn"],
                                        d["raw"].get("barcode"),
                                        d["raw"].get("isbn_field"),
                                        (r.get("title") or "")[:24]))
            elif res["status"] == "conflict":
                r["isbn"] = None
                extra["isbn_conflict"] = d.get("candidates")
                extra["isbn_source_raw"] = {k: v for k, v in
                                            (d.get("raw") or {}).items() if v}
                conflicts.append((r.get("pid"), d.get("candidates"),
                                  (r.get("title") or "")[:26]))
            elif res["status"] == "invalid":
                r["isbn"] = None
                extra["isbn_invalid"] = {k: v for k, v in
                                         (d.get("raw") or {}).items() if v}
                invalids.append((r.get("pid"),
                                 (d.get("raw") or {}).get("barcode"),
                                 (d.get("raw") or {}).get("isbn_field"),
                                 (r.get("title") or "")[:26]))

        # ── 3  language 正規化 ──
        newlang, orig = normalize_language(r.get("language"))
        if orig is not None:
            r["language"] = newlang
            extra["language_source_raw"] = orig
            langfix.append((r.get("pid"), orig, newlang))
        if not r["extra"]:
            r.pop("extra", None)

    after_isbn = sum(1 for r in recs if r.get("isbn"))
    after_dups = dup_groups(recs)

    # ══════════ 報表 ══════════
    print("=" * 72)
    print("處理統計")
    print("=" * 72)
    for k, v in sorted(stat.items()):
        print(f"  {k:22s} {v:5d}")
    print(f"\n  有 isbn:{before_isbn} → {after_isbn}  ({after_isbn - before_isbn:+d})")

    print(f"\n{'=' * 72}\n★ ISBN 有變動 {len(restored)} 筆(還原/轉換)\n{'=' * 72}")
    print(f"{'pid':>6s}  {'原值(錯)':<14s} {'修正後':<14s} {'條碼路':<20s} {'ISBN欄路':<20s} 書名")
    print("-" * 72)
    for pid, old, new, vb, vf, title in restored[:50]:
        print(f"{str(pid):>6s}  {str(old or '-'):<14s} {new:<14s} "
              f"{str(vb or '-'):<20s} {str(vf or '-'):<20s} {title}")
    if len(restored) > 50:
        print(f"...(其餘 {len(restored) - 50} 筆)")

    print(f"\n{'=' * 72}\n⚠ 建議人工複核 {len(need_review)} 筆"
          f"(兩欄不同值,只有一路通過驗證)\n{'=' * 72}")
    for pid, used, bar, fld, title in need_review:
        print(f"  pid {str(pid):>6s} 採用 {used}  條碼={bar}  ISBN欄={fld}  {title}")

    print(f"\n{'=' * 72}\n⛔ conflict {len(conflicts)} 筆(兩路皆通過但答案不同,不寫號)"
          f"\n{'=' * 72}")
    for pid, cands, title in conflicts:
        print(f"  pid {str(pid):>6s} 候選 {cands}  {title}")

    print(f"\n{'=' * 72}\n⛔ invalid {len(invalids)} 筆(無法驗證,不寫號,存 extra.isbn_invalid)"
          f"\n{'=' * 72}")
    for pid, bar, fld, title in invalids[:40]:
        print(f"  pid {str(pid):>6s} 條碼={str(bar or '-'):<14s} "
              f"ISBN欄={str(fld or '-'):<14s} {title}")
    if len(invalids) > 40:
        print(f"...(其餘 {len(invalids) - 40} 筆)")

    print(f"\n{'=' * 72}\n福音單張假號清空 {len(leaflets)} 筆\n{'=' * 72}")
    for pid, isbn, item, title in leaflets[:40]:
        print(f"  pid {str(pid):>6s} {isbn} ({item})  {title}")

    print(f"\n{'=' * 72}\n已知站方誤標清空 {len(mislabeled)} 筆\n{'=' * 72}")
    for pid, isbn, title in mislabeled:
        print(f"  pid {str(pid):>6s} {isbn}  {title}")
        print(f"         {mislabeled_reason({'pid': pid})}")

    print(f"\n{'=' * 72}\nlanguage 正規化 {len(langfix)} 筆\n{'=' * 72}")
    for pid, orig, new in langfix:
        print(f"  pid {str(pid):>6s} {orig!r} → {new!r}")

    # ══ 最關鍵的一項:還原後有沒有製造出新的撞號 ══
    print(f"\n{'=' * 72}")
    print("★★ 站內重複 ISBN:修正前後對照(還原若製造新撞號,必須人工看過才可 import)")
    print("=" * 72)
    print(f"  修正前 {len(before_dups)} 組 → 修正後 {len(after_dups)} 組")
    new_dups = {k: v for k, v in after_dups.items() if k not in before_dups}
    gone = {k: v for k, v in before_dups.items() if k not in after_dups}
    print(f"  消失 {len(gone)} 組(多為福音單張清空所致)")
    print(f"  **新增 {len(new_dups)} 組**"
          + ("  ⚠ 以下需人工判是否真為同書:" if new_dups else "  ✓ 還原未製造新撞號"))
    bypid = {r.get("pid"): r for r in recs}
    for isbn, pids in new_dups.items():
        print(f"    {isbn}")
        for p in pids:
            r = bypid.get(p, {})
            print(f"        pid {str(p):>6s} | {str(r.get('item_no') or ''):<10s} | "
                  f"HK${str(r.get('price_list') or ''):>8s} | {(r.get('title') or '')[:30]}")

    # ══ 寫檔 ══
    if args.dry_run:
        print(f"\n[--dry-run] 未寫檔。確認上方無誤後移除 --dry-run 再跑一次。")
        return 0
    # ★ .bak 已存在就改用時間戳。第一次跑產生的 .bak 是**修正前的原始檔**,
    #   第二次跑若直接覆蓋,原始資料就永久消失了(而 jsonl 重抓要一小時)。
    bak = path.with_suffix(path.suffix + ".bak")
    if bak.exists():
        bak = path.with_suffix(path.suffix + "." +
                               datetime.now().strftime("%Y%m%d-%H%M%S") + ".bak")
    shutil.copy2(path, bak)
    tmp = path.with_suffix(path.suffix + ".tmp")
    with tmp.open("w", encoding="utf-8") as fh:
        for r in recs:
            fh.write(json.dumps(r, ensure_ascii=False) + "\n")
    tmp.replace(path)
    print(f"\n已寫回 {path}(原檔備份 {bak.name})")
    print("下一步:重跑 verify_tiendao.py 確認【4】ISBN 檢查碼不合降為 0,再跑 import dry-run。")
    return 0


if __name__ == "__main__":
    sys.exit(main())
