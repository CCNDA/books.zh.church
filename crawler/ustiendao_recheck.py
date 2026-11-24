# -*- coding: utf-8 -*-
"""天道北美每週對照：改價、下架、有沒有貨（Asana 1220374760596931；11/10 兩面審查 (a) 的跟進）。

第一步只「比對、出報告」，不寫資料庫、不寫爬蟲快取、不改 data/ustiendao_books.jsonl：
- 讀上一次的書目快照（data/ustiendao_books.jsonl，同一個 product_id 取最後一筆）
- 重抓 sitemap：快照裡有、sitemap 沒有的 → 「sitemap 不見了」（多半是下架或改網址）
- 逐頁重抓書頁（不用快取，間隔 3～4 秒，照 robots Crawl-delay）：
    404／410 → 下架；JSON-LD 價格不同 → 改價；availability 不同 → 有沒有貨變了；
    ISBN 變了 → 網址換了別本書（要人看）
- 書頁變成 Cloudflare 驗證頁 → 立刻停、回報（不繞過任何驗證，跟 ustiendao_crawler 同一條規則）
- 寫一份報告 data/ustiendao_recheck_YYYYMMDD.json，印摘要
寫回 formats_prices／links 是下一步，報告看過、送審後才做。

用法：
    crawler/venv/bin/python crawler/ustiendao_recheck.py --limit 100      # 隨機抽 100 頁（約 6 分鐘）
    crawler/venv/bin/python crawler/ustiendao_recheck.py                  # 全部（約 1.2 萬頁，約 12 小時，排每週一次夜間）
"""
from __future__ import annotations

import argparse
import json
import random
import time
from datetime import datetime, timezone
from pathlib import Path

import requests

from common import make_session
from ustiendao_crawler import CHALLENGE, SITEMAP, THROTTLE, parse_product

HERE = Path(__file__).parent
DATA = HERE / "data"
SNAPSHOT = DATA / "ustiendao_books.jsonl"


def load_snapshot(path: Path) -> dict[str, dict]:
    """product_id → 最後一筆紀錄（快照可能重抓過同一本，取最新）。"""
    out: dict[str, dict] = {}
    with path.open(encoding="utf-8") as f:
        for line in f:
            try:
                r = json.loads(line)
            except json.JSONDecodeError:
                continue
            if isinstance(r, dict) and r.get("product_id") and r.get("source_url"):
                out[r["product_id"]] = r
    return out


def fetch(session, url: str) -> tuple[str, str | None]:
    """不用快取、自己抓一次。回 (狀態, 內容)：ok／gone（404、410）／challenge／error。"""
    time.sleep(random.uniform(*THROTTLE))
    try:
        r = session.get(url, timeout=60)
    except requests.RequestException:
        return "error", None
    if r.status_code in (403, 503) and CHALLENGE in r.text[:5000]:
        return "challenge", None
    if r.status_code in (404, 410):
        return "gone", None
    if r.status_code != 200:
        return "error", None
    if not r.encoding or r.encoding.lower() == "iso-8859-1":
        r.encoding = r.apparent_encoding
    return "ok", r.text


def compare(old: dict, page: str, url: str) -> list[dict]:
    """同一本書的舊紀錄 vs 新書頁 → 變動清單（沒變回空清單）。"""
    rec, why = parse_product(page, url)
    if rec is None:
        return [{"kind": "unparsable", "why": why}]
    out = []
    if rec["isbn"] != old.get("isbn"):
        out.append({"kind": "isbn_changed", "old": old.get("isbn"), "new": rec["isbn"]})
        return out   # 換成別本書了，其他欄位比了也沒意義
    if (rec.get("price_list") or None) != (old.get("price_list") or None):
        out.append({"kind": "price", "old": old.get("price_list"), "new": rec.get("price_list")})
    if (rec.get("availability") or None) != (old.get("availability") or None):
        out.append({"kind": "availability", "old": old.get("availability"), "new": rec.get("availability")})
    return out


def run(snapshot: dict[str, dict], sitemap: list[str], fetcher, limit: int = 0, seed: int | None = None) -> dict:
    in_map = {u.rsplit("/", 1)[-1][:-5] for u in sitemap}
    not_in_sitemap = sorted(pid for pid in snapshot if pid not in in_map)
    todo = sorted(snapshot)
    if limit:
        rnd = random.Random(seed)
        todo = rnd.sample(todo, min(limit, len(todo)))
    rows, counts, stopped = [], {"checked": 0, "same": 0, "error": 0}, None
    for pid in todo:
        old = snapshot[pid]
        status, page = fetcher(old["source_url"])
        if status == "challenge":
            stopped = f"書頁變成驗證頁（{old['source_url']}），停下來不繞過"
            break
        counts["checked"] += 1
        if status == "error":
            counts["error"] += 1
            continue
        changes = [{"kind": "gone"}] if status == "gone" else compare(old, page, old["source_url"])
        if not changes:
            counts["same"] += 1
            continue
        for c in changes:
            counts[c["kind"]] = counts.get(c["kind"], 0) + 1
            rows.append({"product_id": pid, "isbn": old.get("isbn"), "title": old.get("title"), "url": old["source_url"], **c})
    return {"counts": counts, "not_in_sitemap": not_in_sitemap, "changes": rows, "stopped": stopped,
            "snapshot_size": len(snapshot), "sitemap_size": len(in_map)}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=0, help="隨機抽幾頁（0＝全部）")
    ap.add_argument("--snapshot", default=str(SNAPSHOT))
    ap.add_argument("--out", default="")
    a = ap.parse_args()
    snap = load_snapshot(Path(a.snapshot))
    session = make_session()
    status, xml = fetch(session, SITEMAP)
    if status != "ok" or not xml:
        print(f"sitemap 抓不到（{status}），不做比對")
        return 2
    import re
    sitemap = re.findall(r"<loc>(https://www\.ustiendao\.com/[^/<]+?\.html)</loc>", xml)
    if not sitemap:
        print("sitemap 0 個書頁：站方可能改版或擋了，不做比對（不能當成全部下架）")
        return 2
    rep = run(snap, sitemap, lambda u: fetch(session, u), limit=a.limit)
    rep["checked_at"] = datetime.now(timezone.utc).isoformat(timespec="seconds")
    rep["limit"] = a.limit
    out = Path(a.out) if a.out else DATA / f"ustiendao_recheck_{datetime.now():%Y%m%d}.json"
    out.write_text(json.dumps(rep, ensure_ascii=False, indent=1), encoding="utf-8")
    c = rep["counts"]
    print(f"快照 {rep['snapshot_size']} 本、sitemap {rep['sitemap_size']} 頁；sitemap 不見了 {len(rep['not_in_sitemap'])} 本")
    print(f"抽查 {c['checked']} 頁：沒變 {c['same']}、改價 {c.get('price', 0)}、有沒有貨變了 {c.get('availability', 0)}、"
          f"下架 {c.get('gone', 0)}、換成別本 {c.get('isbn_changed', 0)}、讀不出 {c.get('unparsable', 0)}、抓不到 {c['error']}")
    if rep["stopped"]:
        print("🛑 " + rep["stopped"])
        return 3
    print(f"報告：{out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
