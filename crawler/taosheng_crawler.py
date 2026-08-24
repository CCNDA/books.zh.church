# -*- coding: utf-8 -*-
"""道聲網路書房 taosheng.com.tw 爬蟲(單執行緒、節流 1.5-2.5 秒、快取續跑)。

8/19 偵察結論(Chrome live 實測):
 1. 道聲出版社(台灣),**Cyberbiz 商城,與格子外面(osb)同一套 API**,TWD,
    全站繁體 → 清單與商品 JSON 端點沿用 osb 架構(見 osb_crawler.py 註解)。
 2. 全站入口:/collections/all(1,637 件);清單一律走
    /zh-TW/collections/{handle}/search_products.json?page=N&per=100
    (products.json 分頁失效,是 Cyberbiz 通用陷阱)。
 3. 商品 JSON /products/{handle}.json 欄位同 osb,但**規格表格式不同**:
    位於 other_descriptions 之一(setting_name 常為 ..._shipping,
    但不可信賴——另一區塊放作者介紹),內容為
    「裝訂:平裝 頁數:192頁 規格:長250mm… 出版社:天音出版工作室
      ISBN:978-626-447-728-4 出版日期:2026-07-25 商品語言:繁體中文」。
    → 沿 osb 做法以「規格欄位命中 >=2」內容特徵辨識,標籤集另立。
    實測抽樣:規格命中 16/20、ISBN 12/20(月刊/月曆/文創本就無 ISBN)。
 4. 出版社:vendor(道聲站代銷多家出版社——道聲 568、香港道聲 185、
    高示 97、格子外面 68、橄欖華宣 67、南與北 62、上誼 25…8/19 決議全收,
    同 ISBN 自動跨站合併,多一個購書管道)。
 5. tags 可辨非書:文創/桌遊/福音春聯/月曆/刮刮卡等(8/19 決議:全站抓入
    存證,非書由 taosheng_category_map 下架,任一命中即下架,沿天恩規則)。
 6. 每日新品入口:/collections/新書上市(熊哥指定);惟該分類僅 7 件、
    由站方人工維護,故增量沿 osb 做法「重走全分類清單」較保險(新書上市
    仍列入走訪清單,排最前)。

用法(主機;venv 沿用既有):
  python3 taosheng_crawler.py --probe          # 驗證清單+商品頁解析(先跑,貼回輸出)
  nohup python3 taosheng_crawler.py > logs/taosheng.log 2>&1 &   # 全量(可中斷續跑)
  python3 taosheng_crawler.py --limit 30       # 試跑 30 件
"""
from __future__ import annotations

import argparse
import json
import re
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import quote

from common import JsonlWriter, make_session, polite_fetch

BASE = "https://www.taosheng.com.tw"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "taosheng"
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


# ── 1. 分類走訪清單(寫死;導覽選單 8/19 快照)────────────────
# code=collection handle(中文 handle 直接當 subjects.code,超過 20 字截斷);
# path=「選單群組 > 名稱」;promo=彙整/促銷型(選 primary 存證分類時排最後)。
# 全站範圍(8/19 決議):all 全抓,故所有分類 scope 一律 True。
CATEGORIES: list[dict] = []


def _cat(code: str, name: str, menu: str, promo: bool = False):
    CATEGORIES.append({"code": code[:20], "name": name, "path": f"{menu} > {name}",
                       "menu": menu, "promo": promo, "order": len(CATEGORIES)})


_M_NEW = "新書"
_M_LIFE = "信徒生活"
_M_CHURCH = "教會建造"
_M_WORK = "職場訓練"
_M_SOCIAL = "社會關懷"
_M_BIBLE = "聖經"
_M_KID = "童書繪本"
_M_DEVO = "復興每一天"
_M_TRAIN = "培訓教材"
_M_PUB = "出版社/系列"
_M_GOODS = "商品"

_cat("新書上市", "新書上市", _M_NEW, promo=True)
_cat("all", "全站商品", _M_NEW, promo=True)
# 信徒生活
for _c, _n in [("信徒生活365靈修", "365 靈修"), ("信徒生活自我成長", "自我成長"),
               ("信徒生活女性成長", "女性成長"), ("信徒生活婚姻家庭", "婚姻家庭"),
               ("信徒生活生活信息", "生活信息"), ("信徒生活人物傳記", "人物傳記"),
               ("生活理財", "生活理財")]:
    _cat(_c, _n, _M_LIFE)
# 教會建造
for _c, _n in [("教會建造信心禱告", "信心禱告"), ("教會建造研經造就", "研經造就"),
               ("教會建造神學教義", "神學教義"), ("教會建造牧養裝備", "牧養裝備"),
               ("書籍分類教會建造宣教佈道", "宣教佈道"),
               ("書籍分類教會建造福音神學", "福音神學"),
               ("書籍分類其他", "禮儀崇拜")]:
    _cat(_c, _n, _M_CHURCH)
# 職場/社會
_cat("職場訓練領袖訓練", "領導力訓練", _M_WORK)
_cat("職場訓練職場裝備", "職場裝備", _M_WORK)
for _c, _n in [("社會關懷生命關懷", "生命關懷"), ("社會關懷樂齡關懷", "樂齡關懷"),
               ("社會關懷社會議題", "社會議題")]:
    _cat(_c, _n, _M_SOCIAL)
# 聖經(含非書周邊,對映表下架)
for _c, _n in [("聖經", "紙本聖經"), ("兒童聖經", "兒童聖經"),
               ("高示聖經", "高示聖經圖表"), ("聖經之美系列", "聖經之美系列")]:
    _cat(_c, _n, _M_BIBLE)
# 童書繪本
for _c, _n in [("道聲童書", "全部繪本"), ("親子繪本館年度新書", "年度新書"),
               ("得獎繪本", "得獎繪本"), ("暢銷繪本top", "暢銷繪本"),
               ("幼兒啟蒙讀物", "幼兒啟蒙讀物"), ("分齡適讀3-6歲", "3-6歲適讀"),
               ("分齡適讀7-12歲", "7-12歲適讀"),
               ("分齡適讀青少年讀物", "青少年讀物"), ("熟齡繪本", "熟齡繪本"),
               ("繪本館自我形象", "自我形象"), ("繪本館自我成長", "繪本自我成長"),
               ("繪本館珍愛接納", "珍愛接納"), ("繪本館價值選擇", "價值選擇"),
               ("繪本館分享幸福", "分享幸福"), ("繪本館環境守護", "環境守護"),
               ("繪本館信仰啟蒙", "信仰啟蒙"), ("繪本館生命信念", "生命信念")]:
    _cat(_c, _n, _M_KID)
# 復興每一天(靈修月刊)
for _c, _n in [("復興每一天靈修月刊", "全部經卷"), ("復興每一天-舊約", "舊約"),
               ("復興每一天-新約", "新約")]:
    _cat(_c, _n, _M_DEVO)
# 培訓教材
for _c, _n in [("幸福門訓", "幸福門訓"),
               ("培訓教材門徒訓練愛的教會", "愛的教會/門徒訓練"),
               ("教會建造人生全壘打夏忠堅", "生命全壘打")]:
    _cat(_c, _n, _M_TRAIN)
# 出版社/系列(彙整型)
for _c, _n in [("香港道聲出版社", "香港道聲"), ("中華信義神學院", "中華信義神學院"),
               ("好消息產品", "好消息"), ("舉手網絡", "舉手網絡"),
               ("南與北文化出版社", "南與北文化"),
               ("台灣冠冕真道理財協會", "台灣冠冕真道理財"),
               ("基石文化", "基石文化"), ("基督使者協會", "基督使者協會"),
               ("格子外面產品", "格子外面"), ("上誼文化", "上誼文化"),
               ("文林出版", "文林出版"), ("榮星教會", "榮星教會"),
               ("小果文創", "小果文創"), ("勁取文化", "勁取文化")]:
    _cat(_c, _n, _M_PUB, promo=True)
# 商品(非書為主,對映表下架)
for _c, _n in [("影音", "影音"), ("全部福音月曆", "福音月曆"),
               ("2027刮刮卡", "祝福經文刮刮卡"), ("新春禮品", "新春福音禮品"),
               ("不要再滑了來玩桌遊吧", "桌遊")]:
    _cat(_c, _n, _M_GOODS, promo=True)


# ── 2. 清單 API(同 osb)──────────────────────────────────────

def list_url(handle: str, page: int) -> str:
    return (f"{BASE}/zh-TW/collections/{quote(handle, safe='')}"
            f"/search_products.json?page={page}&per=100")


def walk_collection(handle: str, force: bool = False, max_pages: int = 60) -> list[dict]:
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
        if page >= int(j.get("total_pages") or 1):
            break
        page += 1
    return items


def collect_memberships(force_lists: bool = False) -> dict[str, dict]:
    """走訪全部分類清單 → handle → {'cats': [分類...]}。
    全站範圍(all 已涵蓋所有商品),cats 排序:非 promo 先、選單順序。"""
    members: dict[str, dict] = {}
    for c in CATEGORIES:
        items = walk_collection(c["code"] if c["code"] != "all" else "all",
                                force=force_lists)
        if items:
            print(f"[清單] {c['path']}:{len(items)} 件", flush=True)
        for p in items:
            h = p["handle"]
            d = members.setdefault(h, {"cats": []})
            if c["code"] not in [x["code"] for x in d["cats"]]:
                d["cats"].append(c)
    for d in members.values():
        d["cats"].sort(key=lambda c: (c["promo"], c["order"]))
    return members


# ── 3. 商品頁解析 ────────────────────────────────────────────

_COLONS = "\uff1a:\u2236"  # 全形冒號 U+FF1A、半形、比號(寫死 escape,防生成器改字)
_TAG_RE = re.compile(r"<br\s*/?>|</p>|</div>|</li>|</h[1-6]>|</tr>", re.I)

# 道聲規格表標籤 → rec 欄位(與 osb 不同:無「原書號/排版方式/分類」,
# 多「規格/商品語言/系列」;出版日期須排在出版社之前)
SPEC_LABELS: list[tuple[str, str]] = [
    ("isbn",            "ISBN"),
    ("ean",             "EAN"),
    ("authors_raw",     "作者"),
    ("translators_raw", "譯者"),
    ("illustrators_raw", "繪者"),
    ("publish_date",    "出版日期"),
    ("publisher_page",  "出版社"),
    ("page_count",      "頁數"),
    ("dimensions",      "規格"),
    ("dimensions2",     "尺寸"),
    ("binding",         "裝訂"),
    ("language",        "商品語言"),
    ("language2",       "語言"),
    ("series",          "系列"),
    ("weight",          "重量"),
]
_SPEC_LINE = re.compile(
    r"^(ISBN|EAN|作者|譯者|繪者|出版日期|出版社|頁數|規格|尺寸|裝訂|"
    r"商品語言|語言|系列|重量)\s*[" + _COLONS + r"]\s*(.*)$")
_SPEC_SPLIT = re.compile(
    r"(ISBN|EAN|出版日期|出版社|頁數|規格|尺寸|裝訂|商品語言|語言|系列|重量)"
    r"\s*[" + _COLONS + r"]")


def strip_html(html: str | None) -> str:
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
    """規格表文字 → 欄位 dict(先逐行,命中不足 2 欄再退標籤切割)。"""
    label_key = {lab: key for key, lab in SPEC_LABELS}
    fields: dict = {}
    for line in text.split("\n"):
        m = _SPEC_LINE.match(line.strip())
        if m:
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
    if not s:
        return None
    s = re.sub(r"^\s*(作者|譯者|繪者)\s*[" + _COLONS + r"]\s*", "", s.strip())
    return re.sub(r"\s{2,}", " ", s) or None


# 8/19 probe 教訓:道聲規格表**沒有「作者」欄**(異於格子外面),作者資訊在
# 另一個 other_descriptions 區塊(慣例:第一行為作者名,其後為簡歷)。
# → 由該區塊首行保守推得,並標記 author_guess=True 供內容面查核;
# 全文照舊存 sections(不丟資料)。誤判代價低:模糊合併鍵不中頂多不合併,
# 不會錯併(書名仍須相同)。
_ORG_WORDS = re.compile(r"出版|書房|文化|工作室|協會|機構|公司|事業|教會|神學院|"
                        r"編輯部|中心|基金會")
_AUTHOR_LINE_BAD = re.compile(r"[" + _COLONS + r"。,、!?;()【】\[\]]|推薦|簡介|目錄|"
                              r"譯者|導讀|序$")


def guess_author(sections: list[dict], vendor: str | None) -> str | None:
    """由「作者介紹」型區塊首行推作者名(保守:短、無標點、非機構名)。"""
    for sec in sections:
        first = (sec.get("text") or "").split("\n", 1)[0].strip()
        if not first or len(first) > 20:
            continue
        if _AUTHOR_LINE_BAD.search(first) or _ORG_WORDS.search(first):
            continue
        if vendor and first == vendor.strip():
            continue
        return first
    return None


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
    j = fetch_json(product_url(handle) + ".json", force=retried)
    if not isinstance(j, dict) or not j.get("title"):
        if not retried:
            return parse_product(handle, cats, retried=True)
        print(f"  [略過 {handle}] 商品 JSON 無效", flush=True)
        return None

    rec: dict = {
        "pid": handle,
        "source": "taosheng",
        "source_url": product_url(handle),
        "cyberbiz_id": j.get("id"),
        "title": re.sub(r"\s+", " ", j["title"]).strip(),
        "is_ebook": False,
        "currency": "TWD",
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }
    if j.get("tags"):
        rec["tags"] = j["tags"]          # 文創/桌遊/月曆… → 非書判定佐證
    if j.get("available") is not None:
        rec["available"] = bool(j["available"])
        if not j["available"]:
            rec["availability"] = "售完/未供貨"

    publisher = (j.get("vendor") or "").strip() or None
    slogan = (j.get("slogan") or "").strip()          # 道聲多放副標/經卷範圍
    if slogan:
        rec["slogan"] = slogan
    brief = (j.get("brief") or "").strip()
    if brief:
        if re.fullmatch(r"[\x20-\x7E]+", brief):
            rec["title_en"] = brief
        else:
            rec["brief"] = brief

    variants = j.get("variants") or []
    if variants:
        v = variants[0]
        if v.get("sku"):
            rec["item_no"] = str(v["sku"])[:30]
        price = v.get("price")
        cap = v.get("compare_at_price")
        if cap and price and float(cap) > float(price):
            rec["price_list"] = str(cap)
            rec["price_sale"] = str(price)
        elif price is not None:
            rec["price_list"] = str(price)

    summary = strip_html(j.get("body_html"))
    spec: dict = {}
    sections: list[dict] = []
    for od in (j.get("other_descriptions") or []):
        txt = strip_html(od.get("body_html") or "")
        if not txt:
            continue
        f = parse_spec(txt)
        if len(f) >= 2 and not spec:     # 內容特徵:規格欄位命中 >=2
            spec = f
            continue
        sections.append({"setting_name": od.get("setting_name"),
                         "title": od.get("title") or "", "text": txt[:4000]})
    if not spec and summary:
        f = parse_spec(summary)
        if len(f) >= 2:
            spec = f
    if sections:
        rec["sections"] = sections

    isbn = norm_isbn(spec.get("isbn")) or norm_isbn(spec.get("ean"))
    if isbn:
        rec["isbn"] = isbn
    authors = clean_person(spec.get("authors_raw"))
    if not authors:                       # 規格表無作者欄 → 由作者介紹區塊推得
        authors = guess_author(sections, publisher)
        if authors:
            rec["author_guess"] = True
    if authors:
        rec["authors_raw"] = authors
    for key, out_key in [("translators_raw", "translators_raw"),
                         ("illustrators_raw", "illustrators_raw")]:
        val = clean_person(spec.get(key))
        if val:
            rec[out_key] = val
    rec["publisher"] = publisher or (spec.get("publisher_page") or "").strip() or None
    if spec.get("page_count"):
        m = re.search(r"[\d,]+", spec["page_count"])
        if m:
            rec["page_count"] = m.group(0).replace(",", "")
    if spec.get("publish_date"):
        m = re.search(r"(\d{4})\D?(\d{1,2})?\D?(\d{1,2})?", spec["publish_date"])
        if m:
            rec["publish_date"] = (m.group(1)
                                   + (f"-{int(m.group(2)):02d}" if m.group(2) else "")
                                   + (f"-{int(m.group(3)):02d}" if m.group(2) and m.group(3) else ""))
    dims = spec.get("dimensions") or spec.get("dimensions2")
    if dims:
        rec["dimensions"] = dims
    lang = spec.get("language") or spec.get("language2")
    if lang:
        rec["language"] = lang
    for key in ("binding", "series", "weight"):
        if spec.get(key):
            rec[key] = spec[key]
    if summary:
        rec["summary"] = summary

    photos: list[str] = []
    for ph in (j.get("photo_urls") or []):
        u = ph.get("original") or ph.get("maximum") or ph.get("grande")
        if u:
            photos.append("https:" + u if u.startswith("//") else u)
    if photos:
        rec["cover_url"] = photos[0]
        if len(photos) > 1:
            rec["photos"] = photos[1:]

    rec["categories"] = [{"code": c["code"], "path": c["path"]} for c in cats]
    if cats:
        rec["category_source"] = cats[0]["code"]
        rec["category_text"] = cats[0]["path"]

    return rec


# ── 主流程 ───────────────────────────────────────────────────

def probe():
    print("=== 探測模式 ===", flush=True)
    for handle in ["all", "新書上市", "教會建造研經造就", "道聲童書", "聖經"]:
        j = fetch_json(list_url(handle, 1), force=True)
        n = j.get("total_count") if isinstance(j, dict) else "?"
        pages = j.get("total_pages") if isinstance(j, dict) else "?"
        print(f"[範圍] {handle}:{n} 件、{pages} 頁", flush=True)
    p1 = fetch_json(list_url("all", 1), force=True)
    p2 = fetch_json(list_url("all", 2), force=True)
    h1 = p1["products"][0]["handle"] if p1 and p1.get("products") else "?"
    h2 = p2["products"][0]["handle"] if p2 and p2.get("products") else "?"
    print(f"[分頁] 第1頁首件={h1}、第2頁首件={h2}"
          f"({'不同 → 分頁有效' if h1 != h2 else '相同,分頁失效,請人工確認!'})", flush=True)

    cat_all = next(c for c in CATEGORIES if c["code"] == "all")
    samples = []
    for coll, label in [("教會建造研經造就", "書(規格齊)"), ("道聲童書", "繪本"),
                        ("影音", "非書(影音)"), ("復興每一天靈修月刊", "靈修月刊")]:
        items = walk_collection(coll, force=True)
        if items:
            samples.append((items[0]["handle"], f"{label}:{coll}"))
    for handle, label in samples:
        rec = parse_product(handle, [cat_all])
        print(f"\n--- 樣本({label}) ---", flush=True)
        if rec:
            slim = {k: v for k, v in rec.items() if k not in ("sections", "photos")}
            slim["sections_n"] = len(rec.get("sections", []))
            slim["summary"] = (rec.get("summary") or "")[:150]
            print(json.dumps(slim, ensure_ascii=False, indent=1)[:2000], flush=True)
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
    writer = JsonlWriter(DATA / "taosheng_books.jsonl", key_field="pid")

    print("== 第一階段:全分類清單走訪 ==", flush=True)
    members = collect_memberships(force_lists=args.force_lists)
    todo = [(h, d) for h, d in members.items() if h not in writer.seen]
    print(f"共 {len(members)} 件,待抓 {len(todo)}", flush=True)

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
