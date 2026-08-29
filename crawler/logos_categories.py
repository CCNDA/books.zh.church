# -*- coding: utf-8 -*-
"""基道 BookFinder 分類 listing 爬蟲 —— 建立「商品碼 → 官方分類」對照。

背景(2026-07-17):
  logos_crawler.py 以「出版年檢索」列舉全站商品,**無分類欄位**,故 22,855 本
  基道書匯入後 category_id 只能靠 classify_categories.php 關鍵字猜測。熊哥要求
  改以基道官網「分類」選單的權威歸類重判每本書的類別。

本工具只抓**分類 listing 頁**(不重抓商品頁):
  - 官網頂部選單有 16 個主題主分類(神學／教義 … 其他),部分帶子分類(▾)。
  - 每個分類(含子分類)= content.asp?...&field=Category&text=<路徑>,商品以
    /link/?code=XXX 或 op=show&code=XXX 列出,分頁沿用 &page=N,頁首顯示
    「在『分類:X』找到 N 項」。
  - 子分類 text 值為階層字串「父/子」,如「聖經/硬面聖經」;exact-match 使
    父分類頁**不含**子分類商品,故父分類與所有子分類都要抓、再依 code 去重合併。

產出 data/:
  - logos_categories.json      每個分類的宣稱總數與 code 清單(可續跑的進度檔)
  - logos_code_categories.jsonl 每個 code 一列:{code, paths:[階層路徑…], tops:[主分類…]}

用法(Windows,主機亦同;host 端建議 nohup):
  python -X utf8 logos_categories.py --discover     # 只印出探索到的分類清單
  python -X utf8 logos_categories.py --probe        # 抓第一個分類首頁驗證解析
  python -X utf8 logos_categories.py                # 全量(可 Ctrl+C 續跑)
  python -X utf8 logos_categories.py --only 聖經     # 只抓指定主分類(含其子分類)

節流沿用 common.polite_fetch(2–3 秒 + 快取續跑);robots 無限制,公益小站保守抓。
"""
from __future__ import annotations

import argparse
import json
import re
import time
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import quote, unquote

from common import make_session, polite_fetch

BASE = "https://www.logos.com.hk"
CONTENT = BASE + "/bf/acms/content.asp?site=logosbf"
# 探索分類選單的入口頁(任一分類頁都帶完整選單)
MENU_SEED = (CONTENT + "&op=search&type=product&match=exact"
             "&field=Category&text=" + quote("聖經"))
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "logos"     # 與 logos_crawler 共用快取
DATA = HERE / "data"
THROTTLE = (2.0, 3.0)

# 16 個主題主分類(排除促銷/格式類:二手書、暢銷榜、電子書、Top50、得獎推介、禮品專區)。
# 只保留「主題」分類作為書籍歸類依據。
TOPICAL = [
    "神學／教義", "讀經／研經", "聖經", "信仰入門", "教會歷史", "靈修／禱告",
    "信徒生活", "教會事工", "分齡牧養", "社會／倫理", "哲學／宗教比較",
    "見證／傳記", "文藝／勵志", "童書", "精選影音", "其他",
]
TOPICAL_SET = set(TOPICAL)

# 官網分類選單為 JavaScript 動態產生,requests 抓到的靜態 HTML 不含選單連結,
# 故子分類無法從頁面自動探索。以下為 2026-07-17 由瀏覽器實查抓下的完整權威清單
# (16 主題主分類 + 48 子分類);listing 頁本身是靜態 HTML,requests 可正常抓。
# 若日後官網增修子分類,更新此清單即可(--discover 只是印出本清單供核對)。
CATEGORIES = [
    "神學／教義",
    "讀經／研經",
    "聖經",
    "聖經/皮面／拉鍊／磁扣聖經", "聖經/硬面聖經", "聖經/中英對照聖經",
    "聖經/兒童聖經／聖經故事", "聖經/靈修版／應用版聖經", "聖經/大字版聖經",
    "聖經/聆聽版聖經", "聖經/研讀本聖經", "聖經/並排版聖經", "聖經/和合本",
    "聖經/和合本修訂版", "聖經/新漢語譯本", "聖經/新普及譯本", "聖經/環球聖經譯本",
    "聖經/新譯本", "聖經/英文聖經", "聖經/其他譯本／其他語言", "聖經/聖經周邊用品",
    "信仰入門",
    "教會歷史",
    "靈修／禱告",
    "信徒生活",
    "信徒生活/生活教導", "信徒生活/心理／情緒", "信徒生活/人際關係",
    "信徒生活/婚姻戀愛", "信徒生活/親子／家庭", "信徒生活/職場／領導",
    "教會事工",
    "教會事工/主日學／宗教教育", "教會事工/門徒訓練", "教會事工/團契／小組",
    "教會事工/男性事工", "教會事工/女性事工", "教會事工/差傳／佈道",
    "教會事工/講道／事奉", "教會事工/崇拜／聖樂", "教會事工/教牧輔導", "教會事工/教會管理",
    "分齡牧養",
    "分齡牧養/兒童事工", "分齡牧養/青少年事工", "分齡牧養/長者事工",
    "社會／倫理",
    "哲學／宗教比較",
    "見證／傳記",
    "文藝／勵志",
    "童書",
    "精選影音",
    "精選影音/音樂", "精選影音/電影", "精選影音/講座", "精選影音/電腦軟件",
    "其他",
    "其他/期刊／主日學教材", "其他/工具書", "其他/保健", "其他/小冊子",
    "其他/單張", "其他/歌書", "其他/教會用品",
]

session = make_session()


def fetch(url: str, force: bool = False) -> str:
    return polite_fetch(session, url, CACHE, THROTTLE, force=force)


def cjk(label: str) -> str:
    return r"\s*".join(map(re.escape, label))


# ── 解析:商品碼、總數 ───────────────────────────────────────

def extract_codes(html: str) -> list[str]:
    codes = re.findall(r"op=show&(?:amp;)?type=product&(?:amp;)?code=([A-Za-z0-9\-]+)", html)
    codes += re.findall(r"/link/\?code=([A-Za-z0-9\-]+)", html)
    return list(dict.fromkeys(codes))


def extract_total(html: str) -> int | None:
    m = re.search(cjk("找到") + r"\s*([\d,]+)\s*" + cjk("項"), html)
    return int(m.group(1).replace(",", "")) if m else None


# ── 探索:從選單抽出所有 field=Category 分類路徑 ────────────────

def discover_categories(html: str) -> list[str]:
    """從選單 HTML 抽出所有 field=Category 的 text 值(主分類 + 子分類),
    只保留主題主分類(TOPICAL)其下者。回傳去重、保序清單。"""
    raw = re.findall(r"field=Category&(?:amp;)?text=([^&\"'<>\s]+)", html)
    out: list[str] = []
    seen: set[str] = set()
    for r in raw:
        text = unquote(r).replace("+", " ").strip()
        if not text:
            continue
        top = text.split("/", 1)[0]          # 階層以 ASCII '/' 分隔;段內全形 ／ 不受影響
        if top not in TOPICAL_SET:
            continue
        if text not in seen:
            seen.add(text)
            out.append(text)
    return out


def cat_url(text: str, page: int = 1) -> str:
    # 保留階層分隔 '/' 為字面,CJK 逐字編碼(與瀏覽器送出方式一致)
    # sort=Code:分類清單同樣以 offset 分頁,排序鍵有並列就會相鄰頁互相重複、
    # 群組內有些列從不顯示(2026-08-28 於年份清單實測涵蓋率只有 77.8%)。
    # 商品碼是唯一鍵,可讓分頁確定性。
    url = (CONTENT + "&op=search&type=product&match=exact&field=Category&text="
           + quote(text, safe="/") + "&sort=Code&order=ASC")
    return url if page == 1 else f"{url}&page={page}"


def is_valid_list_page(html: str) -> bool:
    return bool(extract_total(html) or extract_codes(html))


def fetch_list(url: str, force: bool = False) -> str | None:
    """force=True:繞過磁碟快取。--refresh 重爬時必用,否則會讀到上次抓的分類頁,
    新書永遠不會出現在對照表裡(2026-08-27 基道每日新品同款陷阱)。"""
    try:
        html = fetch(url, force=force)
        if not is_valid_list_page(html):
            html = fetch(url, force=True)
        return html if is_valid_list_page(html) else None
    except RuntimeError as e:
        print(f"  [失敗] {e}")
        return None


# ── 抓單一分類(翻頁到底)───────────────────────────────────

def crawl_category(text: str, force: bool = False) -> dict:
    first = fetch_list(cat_url(text), force=force)
    if first is None:
        print(f"  [略過] 分類首頁抓取失敗:{text}")
        return {"total": None, "codes": []}
    total = extract_total(first)
    codes: dict[str, None] = {}
    for c in extract_codes(first):
        codes.setdefault(c)
    per_page = max(len(codes), 1)
    pages = -(-total // per_page) if total else 1
    bad = 0
    for p in range(2, pages + 1):
        html = fetch_list(cat_url(text, p), force=force)
        got = extract_codes(html) if html else []
        if not got:
            bad += 1
            if bad >= 3:
                break
            continue
        bad = 0
        for c in got:
            codes.setdefault(c)
    return {"total": total, "codes": list(codes)}


# ── 主流程 ───────────────────────────────────────────────────

def load_progress() -> dict:
    p = DATA / "logos_categories.json"
    if p.exists():
        try:
            return json.loads(p.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            print("[警告] 進度檔損毀,重新開始")
    return {"generated_at": None, "categories": {}}


def save_progress(prog: dict):
    prog["generated_at"] = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    p = DATA / "logos_categories.json"
    p.parent.mkdir(parents=True, exist_ok=True)
    tmp = p.with_suffix(".tmp")
    tmp.write_text(json.dumps(prog, ensure_ascii=False, indent=1), encoding="utf-8")
    tmp.replace(p)


def emit_code_map(prog: dict):
    """由 categories→codes 反轉出 code→paths,寫 logos_code_categories.jsonl。"""
    code_paths: dict[str, list[str]] = {}
    for path, info in prog["categories"].items():
        for c in info.get("codes", []):
            code_paths.setdefault(c, [])
            if path not in code_paths[c]:
                code_paths[c].append(path)
    out = DATA / "logos_code_categories.jsonl"
    with out.open("w", encoding="utf-8") as f:
        for code, paths in code_paths.items():
            tops = list(dict.fromkeys(p.split("/", 1)[0] for p in paths))
            f.write(json.dumps({"code": code, "paths": paths, "tops": tops},
                               ensure_ascii=False) + "\n")
    print(f"→ 反轉輸出 {len(code_paths)} 個不重複商品碼 → {out.name}")
    return len(code_paths)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--discover", action="store_true", help="只印探索到的分類清單")
    ap.add_argument("--probe", action="store_true", help="抓第一個分類首頁驗證解析")
    ap.add_argument("--only", default=None, help="只抓指定主分類(含子分類)")
    ap.add_argument("--refresh", action="store_true",
                    help="忽略進度,重抓所有分類(同時繞過頁面快取,才看得到新書)")
    args = ap.parse_args()

    # 選單為 JS 動態產生,靜態 HTML 無連結 → 用內建權威清單(見 CATEGORIES 說明)
    cats = list(CATEGORIES)
    if args.only:
        cats = [c for c in cats if c.split("/", 1)[0] == args.only]
        if not cats:
            print(f"找不到主分類「{args.only}」,可用:{'、'.join(TOPICAL)}")
            return

    if args.discover:
        print(f"探索到 {len(cats)} 個分類(主題主分類 + 子分類):")
        for c in cats:
            print("  ", c)
        return

    if args.probe:
        text = cats[0]
        html = fetch_list(cat_url(text))
        print(f"分類「{text}」:總數 {extract_total(html)},首頁 code 前 5 {extract_codes(html)[:5]}")
        return

    prog = load_progress()
    if args.refresh:
        prog["categories"] = {}

    start = time.time()
    try:
        for i, text in enumerate(cats, 1):
            if text in prog["categories"] and not args.refresh:
                print(f"[{i}/{len(cats)}] 已完成,略過:{text}")
                continue
            print(f"[{i}/{len(cats)}] 抓取:{text}")
            res = crawl_category(text, force=args.refresh)
            prog["categories"][text] = {"total": res["total"], "count": len(res["codes"]),
                                        "codes": res["codes"]}
            save_progress(prog)
            print(f"    宣稱 {res['total']} 項,實得 {len(res['codes'])} 碼"
                  f"(累計 {len(prog['categories'])}/{len(cats)} 類)")
    except KeyboardInterrupt:
        print("\n[中斷] 進度與頁面快取已保存,重跑同指令即續抓")
    finally:
        save_progress(prog)
        n = emit_code_map(prog)
        dur = time.time() - start
        print(f"完成 {len(prog['categories'])} 類、{n} 個商品碼,耗時 {dur/60:.1f} 分")


if __name__ == "__main__":
    main()
