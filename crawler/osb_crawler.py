# -*- coding: utf-8 -*-
"""格子外面網路商城 osb.com.tw 爬蟲(單執行緒、節流 1.5-2.5 秒、快取續跑)。

8/18 偵察結論(Chrome live 實測):
 1. 台灣「格子外面」書房(前程文化),Cyberbiz 商城(cybassets CDN),TWD,
    全站繁體中文(無簡繁問題,不需 OpenCC)。robots.txt 僅擋 /admin//cart/account。
 2. 清單 API:/zh-TW/collections/{handle}/search_products.json?page=N&per=100
    → {total_count, total_pages, current_page, products:[{handle,title,vendor,
      first_variant_sku, first_variant_price, ...}]}。
    注意:/collections/{handle}/products.json 固定回 24 件且 page 參數無效,
    不可用;一定要走 search_products.json。
 3. 商品 JSON:/products/{handle}.json → title/vendor(出版社)/brief(多為
    英文原書名)/slogan(多為作者,格式不一:「作者: 蓋瑞.湯瑪斯」或裸名)/
    body_html(簡介)/tags(「本版書」「外版書」「外版本」)/photo_urls(各尺寸
    含 original)/variants(sku=店內貨號、price 現價、compare_at_price 原價;
    無 barcode)/other_descriptions(數個區塊,setting_name 不可靠——同名區塊
    在不同商品放 規格表/作者介紹/目錄,必須以內容特徵辨識)。
    規格表特徵:「原書號:A1864 ISBN:9786267764893 出版日期:20260710 …
    分類:生活教導/職業」;ISBN 常帶連字號(978-986-…),部分書缺 ISBN。
 4. handle 可為中文(如 /products/陽光與陰霾-路得記和哀歌釋義)→ 請求時
    quote();source_url 一律存 percent-encoded 正規網址。
 5. 商品 JSON 無所屬分類清單(limit_collections 皆空)→ 分類歸屬由
    「清單走訪」蒐集(一書多分類,同以琳/衛理)。導覽「書籍類型」選單
    43 個分類 + 幸福門訓 18 + 前程學習彙整(cncgp)為走訪清單(寫死;
    站方改選單時需重新偵察)。
 6. 範圍(8/18 決議,沿以琳「只抓書籍+聖經」):/collections/osb(全部書籍,
    1,106 件)+ 聖經三分類(和合本/當代譯本/活頁筆記-聖經,18 件,多數不在
    osb 內)+ ★新書到(263 件,每日增量入口)。文創禮品/前程教材/回頭書
    不抓;非屬靈品項若經新書到混入,由 osb_category_map 下架(任一命中
    即下架,沿天恩規則)+ classify 關鍵字保底。

用法(主機;venv 沿用既有):
  python3 osb_crawler.py --probe          # 驗證清單+商品頁解析(先跑,貼回輸出)
  nohup python3 osb_crawler.py > logs/osb.log 2>&1 &   # 全量(可中斷續跑)
  python3 osb_crawler.py --limit 30       # 試跑 30 件
"""
from __future__ import annotations

import argparse
import json
import re
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import quote

from common import JsonlWriter, make_session, polite_fetch

BASE = "https://www.osb.com.tw"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "osb"
DATA = HERE / "data"
THROTTLE = (1.5, 2.5)

session = make_session()
session.headers["Accept"] = "application/json, text/html;q=0.9, */*;q=0.8"


def fetch_json(url: str, force: bool = False) -> dict | list | None:
    try:
        txt = polite_fetch(session, url, CACHE, THROTTLE, force=force)
    except RuntimeError as e:
        print(f"  [抓取失敗] {url} → {e}", flush=True)
        return None
    try:
        return json.loads(txt)
    except json.JSONDecodeError:
        print(f"  [非 JSON,略過] {url}", flush=True)
        return None


# ── 1. 分類走訪清單(寫死;導覽選單 8/18 快照)────────────────
# code=collection handle(中文 handle 直接當 subjects.code,皆 ≤20 字);
# path=「選單群組 > 名稱」;promo=彙整/促銷型(選 primary 存證分類時排最後);
# scope=商品入庫範圍(osb+聖經三分類+新書到);其餘僅蒐集歸屬存證。
_BOOK_MENU = "書籍類型"
_HDTS_MENU = "幸福門訓系統"

CATEGORIES: list[dict] = []


def _cat(code: str, name: str, menu: str, scope: bool = False, promo: bool = False):
    CATEGORIES.append({"code": code, "name": name, "path": f"{menu} > {name}",
                       "menu": menu, "scope": scope, "promo": promo,
                       "order": len(CATEGORIES)})


# 書籍類型(43;順序照選單)
_cat("★新書到", "★新書到", _BOOK_MENU, scope=True, promo=True)
_cat("osb", "全部書籍", _BOOK_MENU, scope=True, promo=True)
for _c, _n in [
    ("初信造就", "初信造就"), ("信心禱告", "信心禱告"), ("福音宣教", "福音宣教"),
    ("生活靈修", "生活靈修"), ("生活教導", "生活教導"),
    ("生命見證／傳記", "生命見證/傳記"), ("靈命造就", "靈命造就"),
    ("神學研經", "神學研經/查經應用"), ("教會建造", "教會建造"),
    ("生命勵志", "生命勵志"), ("個人成長", "個人成長"),
    ("心理學與輔導", "心理學與輔導"), ("感情婚姻", "感情婚姻"),
    ("生活教養", "生活教養"), ("教育現場", "教育現場"), ("職場裝備", "職場裝備"),
    ("領導力訓練", "領導力訓練"), ("社會議題", "社會議題"),
    ("人文史地", "人文史地"), ("散文", "散文/詩"), ("小說", "小說"),
    ("藝術", "藝術"), ("兒童繪本", "兒童繪本"), ("青少年讀物", "青少年讀物"),
    ("兒童故事小說", "兒童故事/小說"),
]:
    _cat(_c, _n, _BOOK_MENU)
_cat("和合本", "和合本", _BOOK_MENU, scope=True)
_cat("當代譯本", "當代譯本", _BOOK_MENU, scope=True)
_cat("活頁筆記-聖經", "活頁/筆記 聖經", _BOOK_MENU, scope=True)
_cat("格子外面", "格子外面出版品", _BOOK_MENU, promo=True)
for _c, _n in [
    ("回頭書全部", "回頭書-全部"), ("回頭書-學前幼兒", "回頭書-學前幼兒"),
    ("回頭書-國小輔助學習", "回頭書-國小輔助學習"),
    ("回頭書-國中輔助學習", "回頭書-國中輔助學習"),
    ("回頭書-信仰生活", "回頭書-信仰生活"), ("回頭書-心理勵志", "回頭書-心理勵志"),
    ("回頭書-親子教養", "回頭書-親子教養"), ("回頭書-其他", "回頭書-其他"),
]:
    _cat(_c, _n, _BOOK_MENU, promo=True)
_cat("宣教培訓", "宣教培訓", _BOOK_MENU)
_cat("月刊", "靈修月刊", _BOOK_MENU)
_cat("影音3c", "影音3C", _BOOK_MENU)      # 非書(對映表 unpublish)
_cat("工具用尺", "工具用尺", _BOOK_MENU)  # 非書(對映表 unpublish)
# 幸福門訓系統(18)
for _c, _n in [
    ("hdts", "門訓全部書籍"), ("全人更新營會", "全人更新營會"),
    ("門上課程教材", "門上課程教材"), ("門上必讀書籍", "門上必讀書籍"),
    ("門上靈修推薦", "門上靈修推薦"), ("門下課程教材", "門下課程教材"),
    ("門下必讀書籍", "門下必讀書籍"), ("門下靈修推薦", "門下靈修推薦"),
    ("福上課程教材", "福上課程教材"), ("福上必讀書籍", "福上必讀書籍"),
    ("福上靈修推薦", "福上靈修推薦"), ("福下課程教材", "福下課程教材"),
    ("福下必讀書籍", "福下必讀書籍"), ("福下靈修推薦", "福下靈修推薦"),
    ("好牧人營會", "好牧人營會"), ("幸福門訓隨身碟", "幸福門訓隨身碟"),
    ("同工建造", "同工建造"), ("幸福小組相關書籍", "幸福小組相關書籍"),
]:
    _cat(_c, _n, _HDTS_MENU)
# 前程學習彙整(非屬靈教材參考書,僅供 unpublish 佐證)
_cat("cncgp", "前程全部書籍", "前程學習", promo=True)


# ── 2. 清單 API ──────────────────────────────────────────────

def list_url(handle: str, page: int) -> str:
    return (f"{BASE}/zh-TW/collections/{quote(handle, safe='')}"
            f"/search_products.json?page={page}&per=100")


def walk_collection(handle: str, force: bool = False, max_pages: int = 60) -> list[dict]:
    """走訪一個 collection 的所有清單頁 → 商品輕量紀錄清單(頁面順序)。"""
    items: list[dict] = []
    seen: set[str] = set()
    page = 1
    while page <= max_pages:
        j = fetch_json(list_url(handle, page), force=force)
        if not isinstance(j, dict) or not j.get("products"):
            break
        for p in j["products"]:
            h = p.get("handle")
            if h and h not in seen:
                seen.add(h)
                items.append(p)
        total_pages = int(j.get("total_pages") or 1)
        if page >= total_pages:
            break
        page += 1
    return items


def collect_memberships(force_lists: bool = False) -> dict[str, dict]:
    """走訪全部分類清單 → handle → {'cats': [分類...], 'scope': bool}。
    scope=商品出現在 osb/聖經三分類/★新書到 任一(才入庫);
    cats 排序:非 promo 先、選單順序(primary 存證分類取最前)。"""
    members: dict[str, dict] = {}
    for c in CATEGORIES:
        items = walk_collection(c["code"], force=force_lists)
        if items:
            print(f"[清單] {c['path']}:{len(items)} 件", flush=True)
        for p in items:
            h = p["handle"]
            d = members.setdefault(h, {"cats": [], "scope": False})
            if c["scope"]:
                d["scope"] = True
            if c["code"] not in [x["code"] for x in d["cats"]]:
                d["cats"].append(c)
    for d in members.values():
        d["cats"].sort(key=lambda c: (c["promo"], c["order"]))
    return members


# ── 3. 商品頁解析 ────────────────────────────────────────────

_COLONS = "\uff1a:\u2236"  # 全形冒號 U+FF1A、半形、比號(寫死 escape,防生成器改字)
_TAG_RE = re.compile(r"<br\s*/?>|</p>|</div>|</li>|</h[1-6]>|</tr>", re.I)

# 規格表標籤 → rec 欄位(出版日期須排在出版社之前,沿 8/17 衛理教訓)
SPEC_LABELS: list[tuple[str, str]] = [
    ("item_no_page",    "原書號"),
    ("isbn",            "ISBN"),
    ("ean",             "EAN"),
    ("authors_raw",     "作者"),
    ("translators_raw", "譯者"),
    ("publish_date",    "出版日期"),
    ("publisher_page",  "出版社"),
    ("page_count",      "頁數"),
    ("dimensions",      "尺寸"),
    ("typeset",         "排版方式"),
    ("language",        "語言"),
    ("binding",         "裝訂方式"),
    ("print_method",    "印刷方式"),
    ("pub_category",    "分類"),
    ("audience",        "適用對象"),
]
_SPEC_LINE = re.compile(
    r"^(原書號|ISBN|EAN|作者|譯者|出版日期|出版社|頁數|尺寸|排版方式|語言|"
    r"裝訂方式|印刷方式|分類|適用對象)\s*[" + _COLONS + r"]\s*(.*)$")
_SPEC_SPLIT = re.compile(
    r"(原書號|ISBN|EAN|出版日期|出版社|頁數|尺寸|排版方式|語言|"
    r"裝訂方式|印刷方式|分類|適用對象)\s*[" + _COLONS + r"]")


def strip_html(html: str | None) -> str:
    """HTML → 純文字(區塊/斷行標籤換行,其餘去除,實體解碼常用者)。"""
    if not html:
        return ""
    t = _TAG_RE.sub("\n", html)
    t = re.sub(r"<[^>]+>", "", t)
    for ent, ch in [("&nbsp;", " "), ("&amp;", "&"), ("&lt;", "<"),
                    ("&gt;", ">"), ("&quot;", '"'), ("&#39;", "'")]:
        t = t.replace(ent, ch)
    t = "\n".join(line.strip() for line in t.split("\n"))
    return re.sub(r"\n{3,}", "\n\n", t).strip()


def parse_spec(text: str) -> dict:
    """規格表文字 → 欄位 dict。先逐行比對(Cyberbiz 多為 <p> 分行);
    命中不足 2 欄再退回「標籤字樣切割」整段(防站方改排版無斷行)。"""
    label_key = {lab: key for key, lab in SPEC_LABELS}
    fields: dict = {}
    for line in text.split("\n"):
        m = _SPEC_LINE.match(line.strip())
        if m and label_key.get(m.group(1)) not in fields:
            key = label_key[m.group(1)]
            val = m.group(2).strip()
            if key not in fields and val:
                fields[key] = val
    if len(fields) >= 2:
        return fields
    parts = _SPEC_SPLIT.split(re.sub(r"\s+", " ", text))
    for i in range(1, len(parts) - 1, 2):
        key = label_key.get(parts[i])
        val = parts[i + 1].strip()
        if key and key not in fields and val:
            fields[key] = val
    return fields


def clean_person(s: str | None) -> str | None:
    """人名欄位:去「作者:」「譯者:」前綴與多餘空白。"""
    if not s:
        return None
    s = re.sub(r"^\s*(作者|譯者)\s*[" + _COLONS + r"]\s*", "", s.strip())
    s = re.sub(r"\s{2,}", " ", s)
    return s or None


_ISBN_RE = re.compile(r"97[89][-\s]?(?:\d[-\s]?){9}\d")


def norm_isbn(s: str | None) -> str | None:
    if not s:
        return None
    m = _ISBN_RE.search(s)
    if not m:
        return None
    digits = re.sub(r"[^0-9]", "", m.group(0))
    return digits if len(digits) == 13 else None


def product_url(handle: str) -> str:
    return f"{BASE}/products/{quote(handle, safe='')}"


def parse_product(handle: str, cats: list[dict], retried: bool = False) -> dict | None:
    """cats:清單走訪蒐集的分類(promo 靠後、選單順序)。"""
    j = fetch_json(product_url(handle) + ".json", force=retried)
    if not isinstance(j, dict) or not j.get("title"):
        if not retried:  # 可能快取到錯誤頁 → force 重抓一次
            return parse_product(handle, cats, retried=True)
        print(f"  [略過 {handle}] 商品 JSON 無效", flush=True)
        return None

    rec: dict = {
        "pid": handle,
        "source": "osb",
        "source_url": product_url(handle),
        "cyberbiz_id": j.get("id"),
        "title": re.sub(r"\s+", " ", j["title"]).strip(),
        "is_ebook": False,
        "currency": "TWD",
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }
    if j.get("tags"):
        rec["tags"] = j["tags"]          # 本版書/外版書/外版本
    if j.get("available") is not None:
        rec["available"] = bool(j["available"])
        if not j["available"]:
            rec["availability"] = "售完/未供貨"

    # 出版社=vendor;作者候選=slogan(規格表作者優先,見下)
    publisher = (j.get("vendor") or "").strip() or None
    slogan_author = clean_person(j.get("slogan"))

    # brief:多為英文原書名 → title_en;非 ASCII 則存 brief 供人工
    brief = (j.get("brief") or "").strip()
    if brief:
        if re.fullmatch(r"[\x20-\x7E]+", brief):
            rec["title_en"] = brief
        else:
            rec["brief"] = brief

    # 價格:variants[0](單一變體為主);compare_at=原價、price=現價
    variants = j.get("variants") or []
    if variants:
        v = variants[0]
        if v.get("sku"):
            rec["item_no"] = str(v["sku"])[:30]     # → identifiers(STORE)
        price = v.get("price")
        cap = v.get("compare_at_price")
        if cap and price and float(cap) > float(price):
            rec["price_list"] = str(cap)
            rec["price_sale"] = str(price)
        elif price is not None:
            rec["price_list"] = str(price)

    # 描述區:body_html=簡介;other_descriptions 依內容特徵辨識規格表,
    # 其餘區塊(作者介紹/目錄/各界推薦…)原文存 sections(入 extra 保全)
    summary = strip_html(j.get("body_html"))
    spec: dict = {}
    sections: list[dict] = []
    for od in (j.get("other_descriptions") or []):
        txt = strip_html(od.get("body_html") or "")
        if not txt:
            continue
        f = parse_spec(txt)
        if len(f) >= 2 and not spec:     # 內容特徵:規格欄位命中 ≥2
            spec = f
            continue
        sections.append({"setting_name": od.get("setting_name"),
                         "title": od.get("title") or "", "text": txt[:4000]})
    if not spec and summary:             # 部分商品把規格塞在 body_html
        f = parse_spec(summary)
        if len(f) >= 2:
            spec = f
    if sections:
        rec["sections"] = sections

    # 規格表欄位入 rec
    isbn = norm_isbn(spec.get("isbn")) or norm_isbn(spec.get("ean"))
    if isbn:
        rec["isbn"] = isbn
    authors = clean_person(spec.get("authors_raw")) or slogan_author
    if authors:
        rec["authors_raw"] = authors
    translators = clean_person(spec.get("translators_raw"))
    if translators:
        rec["translators_raw"] = translators
    rec["publisher"] = publisher or (spec.get("publisher_page") or "").strip() or None
    if spec.get("page_count"):
        m = re.search(r"[\d,]+", spec["page_count"])
        if m:
            rec["page_count"] = m.group(0).replace(",", "")
    if spec.get("publish_date"):  # 20260710 / 2026-07-10 / 2026年7月 → ISO
        m = re.search(r"(\d{4})\D?(\d{1,2})?\D?(\d{1,2})?", spec["publish_date"])
        if m:
            rec["publish_date"] = (m.group(1)
                                   + (f"-{int(m.group(2)):02d}" if m.group(2) else "")
                                   + (f"-{int(m.group(3)):02d}" if m.group(2) and m.group(3) else ""))
    for key in ("dimensions", "binding", "language", "typeset", "print_method",
                "pub_category", "audience", "item_no_page"):
        if spec.get(key):
            rec[key] = spec[key]
    if summary:
        rec["summary"] = summary

    # 封面:photo_urls[].original(protocol-relative → https:);全數存 photos
    photos: list[str] = []
    for ph in (j.get("photo_urls") or []):
        u = ph.get("original") or ph.get("maximum") or ph.get("grande")
        if u:
            if u.startswith("//"):
                u = "https:" + u
            photos.append(u)
    if photos:
        rec["cover_url"] = photos[0]
        if len(photos) > 1:
            rec["photos"] = photos[1:]

    # 分類(清單走訪蒐集;promo 靠後、選單順序)
    rec["categories"] = [{"code": c["code"], "path": c["path"]} for c in cats]
    if cats:
        rec["category_source"] = cats[0]["code"]
        rec["category_text"] = cats[0]["path"]

    return rec


# ── 主流程 ───────────────────────────────────────────────────

def probe():
    print("=== 探測模式 ===", flush=True)
    # 1. 範圍統計
    for handle in ["osb", "★新書到", "和合本", "當代譯本", "活頁筆記-聖經"]:
        j = fetch_json(list_url(handle, 1), force=True)
        n = j.get("total_count") if isinstance(j, dict) else "?"
        pages = j.get("total_pages") if isinstance(j, dict) else "?"
        print(f"[範圍] {handle}:{n} 件、{pages} 頁", flush=True)
    # 2. 分頁驗證(osb 第 1/2 頁首件應不同)
    p1 = fetch_json(list_url("osb", 1), force=True)
    p2 = fetch_json(list_url("osb", 2), force=True)
    h1 = p1["products"][0]["handle"] if p1 and p1.get("products") else "?"
    h2 = p2["products"][0]["handle"] if p2 and p2.get("products") else "?"
    print(f"[分頁] 第1頁首件={h1}、第2頁首件={h2}"
          f"({'不同 → 分頁有效' if h1 != h2 else '相同,分頁失效,請人工確認!'})", flush=True)
    # 3. 樣本:本版書(spec 齊)、外版書、聖經、中文 handle
    samples = [("p004a1864", "本版書(校園)"), ("p004a1174a", "外版書"),
               ("陽光與陰霾-路得記和哀歌釋義", "中文 handle(道聲)")]
    heho = walk_collection("和合本", force=True)
    if heho:
        samples.append((heho[0]["handle"], "聖經(和合本)"))
    cat_osb = next(c for c in CATEGORIES if c["code"] == "osb")
    for handle, label in samples:
        rec = parse_product(handle, [cat_osb])
        print(f"\n--- 樣本({label}) ---", flush=True)
        if rec:
            slim = {k: v for k, v in rec.items() if k not in ("sections", "photos")}
            slim["sections_n"] = len(rec.get("sections", []))
            slim["summary"] = (rec.get("summary") or "")[:150]
            print(json.dumps(slim, ensure_ascii=False, indent=1)[:2200], flush=True)
    print("\n(請把以上輸出貼回,確認欄位/ISBN/價格/封面無誤後再開全量)", flush=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--force-lists", action="store_true", help="清單頁不走快取")
    args = ap.parse_args()

    if args.probe:
        probe()
        return

    DATA.mkdir(exist_ok=True)
    writer = JsonlWriter(DATA / "osb_books.jsonl", key_field="pid")

    print("== 第一階段:全分類清單走訪 ==", flush=True)
    members = collect_memberships(force_lists=args.force_lists)
    in_scope = {h: d for h, d in members.items() if d["scope"]}
    todo = [(h, d) for h, d in in_scope.items() if h not in writer.seen]
    print(f"走訪共 {len(members)} 件;範圍內 {len(in_scope)},待抓 {len(todo)}", flush=True)

    print("== 第二階段:抓商品 JSON ==", flush=True)
    done = 0
    for handle, d in todo:
        rec = parse_product(handle, d["cats"])
        if rec and writer.write(rec):
            done += 1
            if done % 50 == 0:
                print(f"  已入檔 {done}/{len(todo)}", flush=True)
        if args.limit and done >= args.limit:
            print(f"到達 --limit {args.limit},停止", flush=True)
            break
    print(f"完成:本次入檔 {done} 件(檔案累計 {len(writer.seen)})", flush=True)


if __name__ == "__main__":
    main()
