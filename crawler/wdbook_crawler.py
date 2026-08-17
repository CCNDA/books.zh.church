# -*- coding: utf-8 -*-
"""微讀書城 wdbook.com 爬蟲(單執行緒、節流 2-3 秒、快取續跑)。

8/9 偵察結論(WebFetch + Chrome 實測):
 1. WeDevote 純電子書店,全站約 2,022 件(30 件/頁)。頁面為 SSR
    (伺服器直出 HTML),資料不靠 JS API:
    - 清單:/store/category/0?page=N(「所有書籍」,預設上架由新到舊,
      內容與「近期上架」頁一致 → 全量與每日增量都走這裡)
    - 商品:/dp/{id},.info-area 內含 .title(#product-title)/.author/
      .translator/.publisher/.basic-info(格式/頁數/字數/語言/檔案大小/
      出版日期)/價格區(一般書 .price-area、掛促銷活動書 .events-area;
      #product-currentPrice 售價、.originalPrice 定價、.currencyPrice
      約合台幣僅顯示用)
    - 分類:.navigation-area 麵包屑只帶一個子分類(id 穩定;名稱隨語系
      浮動且常為簡體)→ 分類樹寫死於 CATEGORY_TREE,以 id 對照出
      「頂層 > 子分類」繁體完整路徑
 2. robots.txt 僅擋 /mine/;促銷分類頁(近期上架/免費電子書/套裝優惠等
    88xxx 系列)為 JS widget 不 SSR,但其書全數涵蓋於 category/0。
 3. 價格一律 USD($);免費書 $0.00(8/9 決議:免費書與套裝書都收)。
 4. 全站商品頁皆無 ISBN → 跨站合併靠「書名+第一作者」模糊鍵;因此
    **簡體欄位一律以 OpenCC s2t 轉繁體入主欄位**(8/9 決議),原始
    簡體字串保留於 hans 物件(整包進 books.extra)。書名尾綴
    「(繁體版)/(簡體版)」去除後入 title(利於同書合併),原名存 name_raw。
 5. 全站皆電子書:is_ebook=True,價格 media_type='ebook',購書連結標
    「微讀書城」(簡體書標「微讀書城(簡體)」,見 import.php)。

用法(主機;venv 需加裝 OpenCC:pip install "opencc-python-reimplemented>=0.1.7"):
  python3 wdbook_crawler.py --probe          # 驗證清單+商品頁解析(先跑,貼回輸出)
  nohup python3 wdbook_crawler.py > logs/wdbook.log 2>&1 &   # 全量(可中斷續跑)
  python3 wdbook_crawler.py --limit 30       # 試跑 30 本
"""
from __future__ import annotations

import argparse
import json
import re
from datetime import datetime, timezone
from pathlib import Path

from bs4 import BeautifulSoup

from common import JsonlWriter, make_session, polite_fetch

BASE = "https://wdbook.com"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "wdbook"
DATA = HERE / "data"
THROTTLE = (2.0, 3.0)
PER_PAGE = 30  # category/0 每頁 30 件(僅供顯示,停止條件不依賴)

# Accept-Language zh-TW(common.make_session 預設)→ 站方回繁體介面;
# 但商品麵包屑分類名與部分書目內容仍可能是簡體,一律過 s2t。
session = make_session()

try:
    from opencc import OpenCC
    _CC = OpenCC("s2tw")  # 逐字轉台灣標準字形(為/啟/恆,不做詞彙改寫)——8/10 probe 後由 s2t 改換
except ImportError:  # 主機未裝時仍可跑,惟簡體欄位原樣入庫(probe 會提醒)
    _CC = None


def s2t(s: str | None) -> str | None:
    if not s or _CC is None:
        return s
    return _CC.convert(s)


# ── 分類樹(8/9 自 /store/category/0 導覽列 SSR 抓取;繁體)────
# 子分類 id → (頂層名, 子分類名)。麵包屑只給子分類 id,路徑由此組出。
CATEGORY_TREE: dict[str, tuple[str, str]] = {
    # 研經與註釋 23548367036417
    "42264625246513": ("研經與註釋", "註釋"),
    "42264625246497": ("研經與註釋", "聖經語言"),
    "42264625246481": ("研經與註釋", "聖經神學"),
    "42264625246465": ("研經與註釋", "釋經"),
    "42264625246449": ("研經與註釋", "綜覽"),
    # 神學 23230587502593
    "42264625246289": ("神學", "世界基督教與宣教研究"),
    "42264625246273": ("神學", "護教與文化研究"),
    "42264625246257": ("神學", "倫理學"),
    "42264625246241": ("神學", "教牧神學"),
    "42264625246225": ("神學", "系統神學"),
    "42264625246209": ("神學", "歷史神學"),
    # 歷史與傳記 23269375737857
    "42264625246433": ("歷史與傳記", "傳記"),
    "42264625246417": ("歷史與傳記", "主題研究"),
    "42264625246401": ("歷史與傳記", "華人教會史"),
    "42264625246385": ("歷史與傳記", "現當代"),
    "42264625246369": ("歷史與傳記", "通史"),
    "42264625246353": ("歷史與傳記", "宗教改革"),
    "42264625246337": ("歷史與傳記", "中世紀"),
    # 基督徒生活 30556833497089
    "42264625246593": ("基督徒生活", "見證"),
    "42264625246577": ("基督徒生活", "社會責任"),
    "42264625246561": ("基督徒生活", "關係與家庭"),
    "42264625246545": ("基督徒生活", "工作"),
    "42264625246529": ("基督徒生活", "靈命塑造"),
    # 事工 32070174339073
    "42264625246801": ("事工", "崇拜、音樂與藝術"),
    "42264625246785": ("事工", "輔導"),
    "42264625246769": ("事工", "教會治理"),
    "42264625246753": ("事工", "講道"),
    "42264625246737": ("事工", "基督教教育"),
    "42264625246721": ("事工", "小組與團契"),
    "42264625246705": ("事工", "門徒造就"),
    "42264625246689": ("事工", "福音佈道"),
    # 文藝類 31538735616001
    "42264625246673": ("文藝類", "兒童"),
    "42264625246657": ("文藝類", "青少年"),
    "42264625246641": ("文藝類", "詩歌"),
    "42264625246625": ("文藝類", "散文"),
    "42264625246609": ("文藝類", "小說"),
    # 工具書 23230608318465
    "42264625246305": ("工具書", "字典與詞典"),
}
TOP_CATEGORIES: dict[str, str] = {
    "23548367036417": "研經與註釋",
    "23230587502593": "神學",
    "23269375737857": "歷史與傳記",
    "30556833497089": "基督徒生活",
    "32070174339073": "事工",
    "31538735616001": "文藝類",
    "23230608318465": "工具書",
}

DP_RE = re.compile(r'href="/dp/(\d+)"')
# 書名尾綴的版式標記:去除後入 title(利於同書跨站/跨版合併),原名存 name_raw
EDITION_SUFFIX = re.compile(r"\s*[（(]\s*(?:繁體版|繁体版|簡體版|简体版|繁體|繁体|簡體|简体)\s*[)）]\s*$")


def fetch(url: str, force: bool = False) -> str:
    return polite_fetch(session, url, CACHE, THROTTLE, force=force)


# ── 1. 清單:/store/category/0(所有書籍,上架由新到舊)─────────

def list_url(page: int) -> str:
    return f"{BASE}/store/category/0?page={page}" if page > 1 else f"{BASE}/store/category/0"


def extract_ids(html: str) -> list[str]:
    """一頁清單的商品 id(去重、保持頁面順序=最新在前)。"""
    seen: set[str] = set()
    out: list[str] = []
    for m in DP_RE.finditer(html):
        pid = m.group(1)
        if pid not in seen:
            seen.add(pid)
            out.append(pid)
    return out


def walk_list(force_lists: bool = False, max_pages: int = 0) -> list[str]:
    """走訪全站清單 → 依上架新→舊排序的商品 id。
    停止條件:空頁,或整頁 id 皆已見(逾末頁時站方可能回最後一頁)。"""
    ids: list[str] = []
    seen: set[str] = set()
    page = 1
    while True:
        try:
            html = fetch(list_url(page), force=force_lists)
        except RuntimeError as e:
            print(f"[清單] 第 {page} 頁抓取失敗,停止(已收 {len(ids)}):{e}", flush=True)
            break
        got = extract_ids(html)
        fresh = [i for i in got if i not in seen]
        if not got or not fresh:   # 空頁或整頁重複 = 走完
            break
        seen.update(got)
        ids.extend(fresh)
        print(f"[清單] 第 {page} 頁 {len(got)} 件,累計 {len(ids)}", flush=True)
        if max_pages and page >= max_pages:
            print(f"[清單] 達上限 {max_pages} 頁,停止", flush=True)
            break
        page += 1
    return ids


# ── 2. 商品頁解析 ────────────────────────────────────────────

def _names(block, label: str) -> str | None:
    """.author/.translator 區塊 → 多人名(頓號分隔;label 字樣剔除)。"""
    if block is None:
        return None
    links = [a.get_text(strip=True) for a in block.select("a") if a.get_text(strip=True)]
    raw = "、".join(links) if links else block.get_text(" ", strip=True)
    raw = re.sub(rf"^\s*{label}\s*", "", raw)
    raw = re.sub(r"\s*[,，/;;]\s*", "、", raw).strip("、 　")
    return raw or None


def _amount(el) -> str | None:
    if el is None:
        return None
    m = re.search(r"[\d,]+(?:\.\d+)?", el.get_text())
    return m.group(0).replace(",", "") if m else None


def _meta(soup: BeautifulSoup, prop: str) -> str | None:
    el = soup.select_one(f'meta[property="{prop}"]') or soup.select_one(f'meta[name="{prop}"]')
    v = (el.get("content") or "").strip() if el else ""
    return v or None


# .basic-info 標籤 → 欄位(標籤取 s2t 後比對,兩岸字形皆可中)
BASIC_LABELS: list[tuple[str, str]] = [
    ("format_text", "格式"),
    ("page_count",  "頁數"),
    ("word_count",  "字數"),
    ("language",    "語言"),
    ("file_size",   "檔案大小"),
    ("publish_date","出版日期"),
]


def parse_summary(soup: BeautifulSoup) -> str | None:
    """.book-description-area = 頁籤標頭 + 面板(8/10 主機 probe 實測:
    子節點為 [標頭(無類名), article(簡介), article hidden(目錄)])。
    只取第一個未 hidden 的 article 面板(=簡介,目錄不入庫);找不到
    面板才退回整區並剔除頁籤標頭字樣;再退 og:description。"""
    area = soup.select_one(".book-description-area")
    if area is not None:
        panels = [el for el in area.find_all(recursive=False)
                  if "article" in (el.get("class") or [])]
        visible = [el for el in panels if "hidden" not in (el.get("class") or [])]
        node = visible[0] if visible else (panels[0] if panels else area)
        txt = re.sub(r"\n{2,}", "\n", node.get_text("\n")).strip()
        txt = re.sub(r"^(書籍簡介|書籍目錄|书籍简介|书籍目录)\s*", "", txt).strip()
        if txt:
            return txt
    return _meta(soup, "og:description")


def parse_product(pid: str, retried: bool = False) -> dict | None:
    url = f"{BASE}/dp/{pid}"
    try:
        html = fetch(url, force=retried)
    except RuntimeError as e:
        print(f"  [跳過 {pid},下次重跑補抓] {e}", flush=True)
        return None
    soup = BeautifulSoup(html, "lxml")
    info = soup.select_one(".info-area")
    title_el = soup.select_one("#product-title") or (info.select_one(".title") if info else None)
    if info is None or title_el is None:
        if not retried:   # 可能快取到空殼頁 → force 重抓一次
            return parse_product(pid, retried=True)
        print(f"  [略過 {pid}] 找不到 .info-area/.title", flush=True)
        return None

    name_raw = title_el.get_text().strip()
    title = EDITION_SUFFIX.sub("", name_raw).strip() or name_raw

    rec: dict = {
        "pid": pid,
        "item_no": pid,          # → identifiers(STORE)
        "source": "wdbook",
        "source_url": url,
        "name_raw": name_raw,
        "is_ebook": True,
        "currency": "USD",
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }

    authors = _names(info.select_one(".author"), "作者")
    translators = _names(info.select_one(".translator"), "譯者|译者")
    pub_el = info.select_one('.publisher a[href^="/p/"]') or info.select_one(".publisher a")
    publisher = pub_el.get_text(strip=True) if pub_el else None
    if pub_el and pub_el.get("href"):
        rec["publisher_code"] = pub_el["href"].rstrip("/").rsplit("/", 1)[-1]

    # .basic-info 欄位
    fields: dict = {}
    for item in info.select(".basic-info-item"):
        lab_el = item.select_one(".label")
        val_el = item.select_one(".content")
        if lab_el is None or val_el is None:
            continue
        lab = s2t(lab_el.get_text(strip=True)) or ""
        val = val_el.get_text(" ", strip=True)
        for key, want in BASIC_LABELS:
            if key not in fields and want in lab:
                fields[key] = val
                break
    if fields.get("page_count"):
        m = re.search(r"[\d,]+", fields["page_count"])
        fields["page_count"] = m.group(0).replace(",", "") if m else None
    if fields.get("publish_date"):   # 「2026年7月」/「2026-07-01」→ YYYY-MM(-DD)
        m = re.search(r"(\d{4})\D{0,2}(\d{1,2})?\D{0,2}(\d{1,2})?", fields["publish_date"])
        fields["publish_date"] = (
            m.group(1) + (f"-{int(m.group(2)):02d}" if m.group(2) else "")
            + (f"-{int(m.group(3)):02d}" if m.group(2) and m.group(3) else "")
        ) if m else None
    for k, v in fields.items():
        if v:
            rec[k] = v

    # 價格(USD;#product-currentPrice=售價、.originalPrice=定價;免費書皆 0)。
    # 8/10 probe 修正:掛促銷活動的書價格區塊是 .events-area 而非 .price-area,
    # 兩者的價格 span 類名相同 → 直接在 .info-area 範圍內找,不鎖定容器。
    cur = _amount(info.select_one("#product-currentPrice") or info.select_one(".currentPrice"))
    orig = _amount(info.select_one(".originalPrice"))
    price_list = orig or cur
    if price_list is not None:
        rec["price_list"] = price_list
        try:
            if cur is not None and float(cur) < float(price_list):
                rec["price_sale"] = cur
            rec["is_free"] = float(cur if cur is not None else price_list) == 0.0
        except ValueError:
            pass

    # 分類:麵包屑子分類 id → CATEGORY_TREE 繁體完整路徑(id 穩定,名稱不可靠)
    cats: list[dict] = []
    nav = soup.select_one(".navigation-area")
    for a in (nav.select('a[href^="/store/category/"]') if nav else []):
        cid = a["href"].rstrip("/").rsplit("/", 1)[-1].split("?")[0]
        if cid in ("0", "1") or not cid.isdigit():
            continue
        if cid in CATEGORY_TREE:
            top, sub = CATEGORY_TREE[cid]
            path = f"{top} > {sub}"
        elif cid in TOP_CATEGORIES:
            path = TOP_CATEGORIES[cid]
        else:   # 站方日後新增分類:先以麵包屑文字(轉繁)存證,對映表再補
            path = s2t(a.get_text(strip=True)) or cid
        if not any(c["code"] == cid for c in cats):
            cats.append({"code": cid, "path": path})
    rec["categories"] = cats
    if cats:
        rec["category_source"] = cats[0]["code"]
        rec["category_text"] = cats[0]["path"]

    # 簡介與封面
    summary = parse_summary(soup)
    cover = _meta(soup, "og:image")
    if cover:
        rec["cover_url"] = cover

    # 簡體 → 繁體(主欄位轉繁入庫;原始簡體字串整組存 hans,隨 extra 入庫)
    hans: dict = {}
    for key, val in [("title", title), ("authors_raw", authors),
                     ("translators_raw", translators), ("publisher", publisher),
                     ("summary", summary), ("language", rec.get("language"))]:
        if val is None:
            continue
        conv = s2t(val)
        if conv != val:
            hans[key] = val
        rec[key] = conv
    if hans:
        rec["hans"] = hans
    # 8/12 修正:繁體書經 s2tw 正規化(恒→恆、祢→禰等)也會產生 hans,
    # 不可據此判簡體 → 以「語言」欄位為準,缺語言欄位時才退回 hans 判斷。
    _lang = rec.get("language") or ""
    rec["is_hans"] = ("簡體" in _lang) if _lang else bool(hans)

    return rec


# ── 主流程 ───────────────────────────────────────────────────

def probe():
    print("=== 探測模式 ===", flush=True)
    if _CC is None:
        print("[警告] 未安裝 OpenCC!請先 pip install \"opencc-python-reimplemented>=0.1.7\"", flush=True)
    p1 = extract_ids(fetch(list_url(1), force=True))
    p2 = extract_ids(fetch(list_url(2), force=True))
    ok = bool(p1) and bool(p2) and p1[0] != p2[0]
    print(f"[清單] 第 1 頁 {len(p1)} 件、第 2 頁 {len(p2)} 件 → 分頁{'正常' if ok else '異常!'}", flush=True)
    if not p1:
        print("清單第 1 頁抓不到商品,中止", flush=True)
        return
    # 樣本:第 1 件 + 掃最前面 11 件找 1 本簡體書 + 1 本免費書
    samples: list[str] = [p1[0]]
    hans_found = free_found = None
    for pid in (p1 + p2)[1:12]:
        if hans_found and free_found:
            break
        rec = parse_product(pid)
        if not rec:
            continue
        if not hans_found and rec.get("is_hans"):
            hans_found = pid
        if not free_found and rec.get("is_free"):
            free_found = pid
    samples += [p for p in (hans_found, free_found) if p and p not in samples]
    for pid in samples:
        print(f"\n--- 樣本 /dp/{pid} ---", flush=True)
        # 顯示簡介區塊的子節點類名(供 parse_summary 類名關鍵字校正)
        soup = BeautifulSoup(fetch(f"{BASE}/dp/{pid}"), "lxml")
        area = soup.select_one(".book-description-area")
        kids = [c.get("class") for c in (area.find_all(recursive=False) if area else [])]
        print(f"[.book-description-area 子節點類名] {kids}", flush=True)
        rec = parse_product(pid)
        print(json.dumps(rec, ensure_ascii=False, indent=1)[:2000], flush=True)
    print("\n(請把以上輸出貼回,確認欄位/簡介/簡繁轉換無誤後再開全量)", flush=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--max-pages", type=int, default=0, help="清單頁上限(0=走到空頁)")
    ap.add_argument("--force-lists", action="store_true", help="清單頁不走快取(補抓新品時用)")
    args = ap.parse_args()

    if args.probe:
        probe()
        return
    if _CC is None:
        print("[中止] 未安裝 OpenCC,簡體書將原樣入庫而無法與繁體書合併。"
              "請先:pip install \"opencc-python-reimplemented>=0.1.7\"", flush=True)
        return

    DATA.mkdir(exist_ok=True)
    writer = JsonlWriter(DATA / "wdbook_books.jsonl", key_field="pid")

    print("== 第一階段:清單(所有書籍,上架新→舊)==", flush=True)
    ids = walk_list(force_lists=args.force_lists, max_pages=args.max_pages)
    todo = [pid for pid in ids if pid not in writer.seen]
    print(f"共 {len(ids)} 件,待抓 {len(todo)}", flush=True)

    print("== 第二階段:抓商品頁 ==", flush=True)
    done = 0
    for pid in todo:
        rec = parse_product(pid)
        if rec:
            writer.write(rec)
            done += 1
            if done % 50 == 0:
                print(f"  已入檔 {done}/{len(todo)}", flush=True)
        if args.limit and done >= args.limit:
            print(f"到達 --limit {args.limit},停止", flush=True)
            break
    print(f"完成:本次入檔 {done} 本(檔案累計 {len(writer.seen)})", flush=True)


if __name__ == "__main__":
    main()
