# -*- coding: utf-8 -*-
r"""海天書樓(rockhouse,香港,Java 自建站)爬蟲 —— 第十九來源,v1.19.0(Asana 1217984724611028)。

用法(在 crawler/ 底下):
  venv/bin/python rockhouse_crawler.py --probe          # 偵察:清單第 1 頁 + 3 個樣本解析結果,不寫檔
  venv/bin/python rockhouse_crawler.py --sample 12      # 等距抽樣 N 件解析結果,不寫檔
  venv/bin/python rockhouse_crawler.py --limit 30       # 試跑 30 件(寫 master)
  nohup venv/bin/python -u rockhouse_crawler.py > logs/rockhouse.log 2>&1 &   # 全量(可續跑,快取在 cache/rockhouse)
  venv/bin/python rockhouse_crawler.py --refresh-list  # 每週(daily_new.sh 星期一):清單不用快取重讀,只抓新商品

━━━ 站台(2026-10-11 偵察,見票)━━━
・全程 http;index.do 是 frameset,內容在 showroom/model/T0135/*.do,固定帶 webId、editCurrentLanguage
・分類頁只列分類、不列商品 → 不能用分類列舉
・★ 權威清單:空白關鍵字搜尋 templateProductTypeList.do?module=SearchProduct&keyWords=&currentPage=N
  每頁 10 件,第 35 頁 0 件 → 全站 338 件。讀到 0 件就停;某頁跟上一頁完全一樣也停(防超界退化,突破機構踩過)
・商品頁 templateProductDetails.do?ParentId=…&productId=…:
  麵包屑(主目錄 > 第一層 > … > span.NavigationSwap 品名)、TitleArea「編號 : 品名」、
  簡單介紹(<br> 分行,作者／譯者／裝訂／頁數都在這段文字裡)、價格「HKD 98.00 / set」、
  封面 PicArea 小圖 s_*.jpg → 大圖 b_*.jpg
・★ 連結 href 裡的「&currentPage」會被 HTML 解析器當成「&curren」(¤)實體吃掉 → 商品網址一律用 ParentId＋productId 自己組
・⚠️ 沒有 ISBN、沒有出版社欄(就是海天自己)

━━━ 決議 ━━━
・沒有 ISBN 的合併:熊哥 2026-10-11 16:03「B」= 寬鬆比對(import.php 的 fuzzy_key 書名+第一作者),
  比到的先用 import.php --dry-run 列清單給熊哥看過再正式匯入
・幣別 HKD;有購物車 → 購書連結照常(不是 NO_BUY_SOURCES)
・非書籍(收錄判準一):第一層分類是 NONBOOK_TOP 的不寫進 master,另列 data/rockhouse_nonbook.tsv;
  不認得的第一層分類照收,但列進報表給人看
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path
from urllib.parse import urljoin, parse_qs, urlparse

from bs4 import BeautifulSoup

sys.path.insert(0, str(Path(__file__).resolve().parent))
from common import JsonlWriter, make_session, polite_fetch  # noqa: E402

HERE = Path(__file__).resolve().parent
CACHE = HERE / "cache" / "rockhouse"
DATA = HERE / "data"
SITE = "http://www.rockhousebooks.com"
BASE = SITE + "/showroom/model/T0135/"
Q = "webId=1250305224750005496&editCurrentLanguage=1250305224750005497"
MAX_PAGES = 80  # 338 件 ≈ 34 頁;保險上限,超過就是站方改版了

# 第一層分類 → 非書籍(票上偵察：月曆／日曆／秩序單／吉儀封／音樂光碟)。實際名稱以 --probe／全量報表為準再補。
NONBOOK_TOP = {"秩序單", "月曆", "日曆", "海天日月曆", "吉儀封", "音樂光碟", "光碟", "禮品", "卡"}
# 2026-10-12 全量實測的書籍第一層分類（不在這裡的照收，但報表會提醒「新分類請人看過」）
BOOK_TOP_HINT = {"聖經", "恆用日曆系列", "靈修‧勵志", "兒童與少年讀物", "證道‧普及神學", "新聞‧傳播專著", "歷史‧傳記", "幼兒與兒童",
                 "少年文學‧童話", "家庭‧生活", "小說", "世界文學名著", "禮品書", "學好英文的書", None}
# 灰色地帶：照收但報表另列，等熊哥決定。
# 恆用日曆系列（每天翻一頁、有內容的桌曆，例「365聖經問題天天答」）熊哥 2026-10-12 06:04「5本收」→ 已移到 BOOK_TOP_HINT
GRAY_TOP: set[str] = set()
# 沒有麵包屑時依書名補分類（站方分類代碼與名稱照抄同一套書其他版本的麵包屑）
TITLE_CATEGORY_FALLBACK = [
    ("啟導本", [{"code": "1250582624703004926", "name": "聖經"},
                {"code": "1250582693484004975", "name": "中文聖經啟導本増訂新版"}]),
]
# ★ None＝站方頁面就沒有分類麵包屑（例《中文聖經啟導本》9 件，ParentId 指向的分類頁也沒有名稱）。
#   不在爬蟲裡猜分類，匯入後由 tools/classify_categories.php 依書名關鍵字歸類。

SESSION = make_session()


def fetch(url: str, force: bool = False) -> str:
    return polite_fetch(SESSION, url, CACHE, throttle=(3.0, 5.0), force=force)


def list_url(page: int) -> str:
    return f"{BASE}templateProductTypeList.do?{Q}&module=SearchProduct&keyWords=&currentPage={page}"


def product_url(parent_id: str, pid: str) -> str:
    return f"{BASE}templateProductDetails.do?{Q}&ParentId={parent_id}&productId={pid}"


def _ids_on_page(html: str) -> list[tuple[str, str]]:
    """回傳 [(productId, ParentId)],照頁面順序去重。直接對原始 HTML 用 regex,避開 &curren 實體問題。"""
    out: list[tuple[str, str]] = []
    for m in re.finditer(r'templateProductDetails\.do\?[^"\']*?ParentId=(\d+)[^"\']*?productId=(\d+)', html):
        item = (m.group(2), m.group(1))
        if item not in out:
            out.append(item)
    return out


def list_all(force: bool = False) -> list[tuple[str, str]]:
    allids: list[tuple[str, str]] = []
    prev: list[tuple[str, str]] | None = None
    for page in range(1, MAX_PAGES + 1):
        ids = _ids_on_page(fetch(list_url(page), force=force))
        if not ids:
            print(f"  第 {page} 頁 0 件 → 清單結束", flush=True)
            break
        if prev is not None and ids == prev:
            print(f"  ⚠️ 第 {page} 頁和上一頁完全一樣 → 視為超界退化,停止(請人工檢查)", flush=True)
            break
        new = [x for x in ids if x[0] not in {a for a, _ in allids}]
        allids += new
        print(f"  第 {page} 頁 {len(ids)} 件(新 {len(new)},累計 {len(allids)})", flush=True)
        prev = ids
    else:
        print(f"  ⚠️ 讀到 {MAX_PAGES} 頁還沒結束,站方可能改版", flush=True)
    return allids


def _text(el) -> str:
    return re.sub(r"[ \t　]+", " ", el.get_text("\n", strip=True)).strip() if el else ""


def parse_intro(lines: list[str]) -> dict:
    """簡單介紹 → 作者／譯者／編者／裝訂／頁數；其餘當簡介。例：「路易‧紀里歌（Louie Giglio）著／林靜文譯／軟面精裝／全書240面」"""
    rec: dict = {"authors_raw": None, "translators_raw": None, "editors_raw": None, "binding": None, "page_count": None}
    rest: list[str] = []
    for line in lines:
        parts = [p.strip() for p in re.split(r"[／/｜|]", line) if p.strip()]
        leftover = []
        for p in parts:
            # 「尼克．皮基著，崔妙姍譯」「卓思達著‧陳恩明牧師譯」：作者和譯者寫在同一段 → 一次拆開
            # （不能直接用「‧」切：外國人名裡也有，例「路易‧紀里歌」）
            if m := re.fullmatch(r"(.+?)\s*(?:原著|合著|編著|著)[\s，,、‧・．]*(.+?)\s*(?:翻譯|譯)", p):
                rec["authors_raw"] = (rec["authors_raw"] + "、" if rec["authors_raw"] else "") + m.group(1)
                rec["translators_raw"] = (rec["translators_raw"] + "、" if rec["translators_raw"] else "") + m.group(2)
            elif m := re.fullmatch(r"(.+?)\s*(?:原著|合著|編著|著)", p):
                rec["authors_raw"] = (rec["authors_raw"] + "、" if rec["authors_raw"] else "") + m.group(1)
            elif m := re.fullmatch(r"(.+?)\s*(?:主編|編)", p):
                rec["editors_raw"] = (rec["editors_raw"] + "、" if rec["editors_raw"] else "") + m.group(1)
            elif m := re.fullmatch(r"(.+?)\s*(?:翻譯|譯)", p):
                rec["translators_raw"] = (rec["translators_raw"] + "、" if rec["translators_raw"] else "") + m.group(1)
            elif re.search(r"精裝|平裝|軟皮|軟面|硬面|硬皮|線裝|膠裝|騎馬釘", p) and len(p) <= 12:
                rec["binding"] = p
            elif m := re.search(r"(?:全書)?\s*(\d{2,4})\s*(?:面|頁|页)", p):
                rec["page_count"] = int(m.group(1))
            else:
                leftover.append(p)
        if leftover:
            rest.append("／".join(leftover))
    rec["summary"] = "\n".join(rest) or None
    return rec


def parse_product(pid: str, parent_id: str, force: bool = False) -> dict:
    url = product_url(parent_id, pid)
    soup = BeautifulSoup(fetch(url, force=force), "html.parser")

    crumbs: list[dict] = []
    nav = soup.find("a", class_="CategoryNavigation")
    if nav:
        for a in nav.find_parent().find_all("a"):
            if "CategoryNavigation" in (a.get("class") or []):
                continue
            q = parse_qs(urlparse(a.get("href", "")).query)
            crumbs.append({"code": (q.get("ParentId") or [None])[0], "name": a.get_text(strip=True)})

    # 站方頁面沒有分類麵包屑時（《中文聖經啟導本》9 件），依書名補回同一套書的站方分類，並標 category_inferred。
    # 2026-10-12：這 9 件交 classify_categories.php 會落到「綜合其他」（它的「聖經」類只認校園來源代碼），
    #   而同一套書的其他版本在站方就掛在「聖經 > 中文聖經啟導本増訂新版」底下。
    inferred = False
    if not crumbs:
        swap = soup.find("span", class_="NavigationSwap")
        name = swap.get_text(strip=True) if swap else ""
        for kw, path in TITLE_CATEGORY_FALLBACK:
            if kw in name:
                crumbs, inferred = [dict(x) for x in path], True
                break

    title_td = soup.find("td", class_="TitleArea")
    raw_title = _text(title_td).replace("\n", " ")
    code, title = (raw_title.split(" : ", 1) + [""])[:2] if " : " in raw_title else ("", raw_title)
    if not title:
        swap = soup.find("span", class_="NavigationSwap")
        title = swap.get_text(strip=True) if swap else ""

    intro_lines: list[str] = []
    price_raw = None
    for sub in soup.find_all("td", class_="SubTitleArea"):
        label = sub.get_text(strip=True)
        body = sub.find_parent("tr").find_next_sibling("tr")
        if not body:
            continue
        if label == "簡單介紹":
            intro_lines = [x.strip() for x in body.get_text("\n").split("\n") if x.strip()]
        elif label == "價格":
            p = body.find("td", class_="Price")
            price_raw = p.get_text(" ", strip=True) if p else body.get_text(" ", strip=True)

    price, currency = None, "HKD"
    if price_raw and (m := re.search(r"([A-Z]{3})\s*([\d,]+(?:\.\d+)?)", price_raw)):
        currency, price = m.group(1), m.group(2).replace(",", "")

    cover = None
    pic = soup.find("td", class_="PicArea")
    if pic and (img := pic.find("img", src=True)):
        cover = urljoin(url, img["src"]).replace("/gallery/s_", "/gallery/b_")
        if "nopic" in cover.lower() or "noimage" in cover.lower():
            cover = None

    rec = {
        "product_id": pid,
        "parent_id": parent_id,
        "source": "rockhouse",
        "source_url": url,
        "item_no": code.strip() or None,
        "title": title.strip(),
        "publisher": "海天書樓",
        "price_list": price,
        "currency": currency,
        "price_raw": price_raw,
        "cover_url": cover,
        "categories": [{"code": c["code"], "path": " > ".join(x["name"] for x in crumbs[: i + 1])} for i, c in enumerate(crumbs)],
        "category_source": crumbs[-1]["code"] if crumbs else None,
        "category_text": " > ".join(c["name"] for c in crumbs) or None,
        "top_category": crumbs[0]["name"] if crumbs else None,
        "category_inferred": inferred,
        "intro_raw": "\n".join(intro_lines) or None,
    }
    rec.update(parse_intro(intro_lines))
    rec["is_nonbook"] = rec["top_category"] in NONBOOK_TOP
    return rec


def show(rec: dict) -> None:
    keys = ("product_id", "item_no", "title", "top_category", "category_text", "authors_raw", "translators_raw",
            "editors_raw", "binding", "page_count", "price_list", "currency", "cover_url", "is_nonbook")
    print("  " + " ｜ ".join(f"{k}={rec.get(k)}" for k in keys))
    if rec.get("summary"):
        print("     簡介：" + rec["summary"][:80].replace("\n", " ⏎ "))


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true")
    ap.add_argument("--sample", type=int, default=0)
    ap.add_argument("--limit", type=int, default=0)
    # 每週重抓(熊哥 2026-10-12:海天很小,不進每日新品,改每週全量):清單頁一律重抓才看得到新商品;
    # 已抓過的商品頁沿用快取、master 已有的不重寫(JsonlWriter 以 product_id 去重),新商品才發請求。
    ap.add_argument("--refresh-list", action="store_true")
    args = ap.parse_args()

    if args.probe:
        ids = _ids_on_page(fetch(list_url(1)))
        print(f"清單第 1 頁 {len(ids)} 件")
        for pid, par in ids[:3]:
            show(parse_product(pid, par))
        return 0

    print("讀取全站清單(空白關鍵字搜尋)…", flush=True)
    ids = list_all(force=args.refresh_list)
    print(f"全站 {len(ids)} 件", flush=True)

    if args.sample:
        step = max(1, len(ids) // args.sample)
        for pid, par in ids[::step][: args.sample]:
            show(parse_product(pid, par))
        return 0

    DATA.mkdir(parents=True, exist_ok=True)
    writer = JsonlWriter(DATA / "rockhouse_books.jsonl", key_field="product_id")
    nonbook_rows, unknown_top, gray = [], {}, []
    todo = ids[: args.limit] if args.limit else ids
    for n, (pid, par) in enumerate(todo, 1):
        rec = parse_product(pid, par)
        if rec["is_nonbook"]:
            nonbook_rows.append(rec)
        else:
            if rec["top_category"] not in BOOK_TOP_HINT:
                unknown_top.setdefault(rec["top_category"], []).append(rec["title"])
            if rec["top_category"] in GRAY_TOP:
                gray.append(rec)
            writer.write(rec)
        if n % 25 == 0:
            print(f"  {n}/{len(todo)}", flush=True)
    writer.close()

    with (DATA / "rockhouse_nonbook.tsv").open("w", encoding="utf-8", newline="\n") as f:
        f.write("product_id\titem_no\ttop_category\tcategory_text\ttitle\n")
        for r in nonbook_rows:
            f.write("\t".join(str(r.get(k) or "") for k in ("product_id", "item_no", "top_category", "category_text", "title")) + "\n")

    books = len(todo) - len(nonbook_rows)
    print(f"\n完成:{len(todo)} 件 → 書 {books} 件寫入 data/rockhouse_books.jsonl,非書籍 {len(nonbook_rows)} 件列在 data/rockhouse_nonbook.tsv", flush=True)
    if unknown_top:
        print("⚠️ 第一層分類不在已知書籍清單、照收但請人看過:", flush=True)
        for top, titles in unknown_top.items():
            print(f"   {top}:{len(titles)} 件,例 {titles[:3]}", flush=True)
    if gray:
        print(f"⚠️ 灰色地帶 {len(gray)} 件（照收，等熊哥決定是不是書）:", flush=True)
        for r in gray:
            print(f"   {r['product_id']}　{r['item_no']}　{r['title']}（{r['category_text']}）", flush=True)
    print(f"下一步(先 dry-run 看合併清單給熊哥):\n   php tools/import.php --file={DATA / 'rockhouse_books.jsonl'} --source=rockhouse --dry-run", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
