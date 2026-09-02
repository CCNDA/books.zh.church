# -*- coding: utf-8 -*-
"""天道書樓 tiendao.org.hk 爬蟲(單執行緒、節流 2-3 秒、快取續跑)。

第十五個來源。香港環球天道機構,OpenCart,HK$、繁體。來源代碼 `tiendao`。
架構沿用 methodist_crawler.py(同為 OpenCart),差異見下。

═══ 9/1 兩輪偵察實測結論(probe_tiendao.py,不是推測)═══

 1. **robots**:根目錄 /robots.txt 為 404(未宣告限制);/store/robots.txt 有一份
    `Disallow: /`,但 robots.txt 僅在網域根目錄具效力(RFC 9309),該檔按標準不被讀取,
    內容亦像 OpenCart 安裝預設。熊哥 9/1 裁示採此解釋、照計畫抓。
    → 仍維持:單執行緒、節流 2-3 秒、UA 具名附聯絡信箱、只讀不下單。
      **若出現 403/429 連續發生,視為站方明示拒絕,立即停手回報**(見 fetch())。

 2. **分頁**:`&limit=100` 確認生效(要 100 給 100);超界 page 回**空頁**
    → 可用「空頁即停」,不必像基道那樣推算頁數。

 3. **不存在的 path / product_id 回真 404**,不是夾回首頁 → 可信任狀態碼。

 4. **分類樹 95 個(導覽選單)**,sitemap 頁只有 86 個 → **以導覽為準**。
    真正的樹根是 `73 網上購物`(下轄 中文書75 / 聖經82 / 單張小冊77 / 影音78 /
    禮品140 / 環球聖經公會106 / 環球天道傳基協會139);105、106、140、62 等
    同時也掛在頂層當捷徑 → **同一批書會被重複計數**,務必以 product_id 去重。
    父分類**不含**子分類商品(OpenCart filter_sub_category=false),所以必須走完每一個葉節點。
    各類件數加總 5,734 含父子重複,**不是全站量**;唯一商品估 1,300-1,600。
    `73_75_91 期刊` 第 1 頁讀不到「共 N 個」→ 單獨留意(見 walk_category 的容錯)。

 5. **商品頁規格表 #tab-specification 是乾淨的 label|value 表**,這是本站最大優點:
      英文名稱 / 作者 / 譯者 / 語言 / 出版商 / 第一版 / 最新一版 / 頁數 /
      書籍系列 / 條碼 / 國際標準圖書編號 (ISBN) / 備註
    - 表格**第一列是商品類型**(書籍為 `Books`)→ 比衛理「分類命中即下架」精確,
      直接存 product_type 供 apply 階段判非書。
    - **條碼 = 無連字號 ISBN13**(9789622087095);ISBN 欄是有連字號版 → 取條碼。
    - 第一版(2007-12)= 作品首版;最新一版(2024-1(第二版第3次印刷))= 本版
      → 正好對上 Work/Edition:publish_date 給作品、edition_note 存本版敘述。
    - 語言欄直書「繁體中文」/「簡體」→ is_hans 有依據,不必用字形猜。

 6. **Model = 店內貨號,且封面檔名就是貨號**:Model TD1406 →
    image/cache/catalog/cover/TD1406-600x315w.jpg。
    原圖改寫 `-600x315w` → `image/catalog/cover/TD1406.jpg` **實測 HTTP 200 通過**。
    注意尾巴多一個 `w`(v1 的 regex 就是漏了它)。

 7. **價格陷阱**:商品頁 `.price` 會先命中頁尾「更多來自這個品牌」的關聯商品
    (probe 印出 HK$180/115/150/150,本書實價其實是 HK$330)。
    → parse_product() **先把關聯商品區整塊拆掉**再找價格,並保留文字流保底。
    規格表「備註」寫明「定價僅供參考,確實售價以書室及批發報價為準」。

═══ 三項決議(9/1,沿既有來源慣例)═══
 1. 抓取範圍:全站抓入存證(含禮品/影音/單張小冊),非書由 tiendao_category_map
    於 apply 階段下架 —— 沿天恩/衛理「任一命中即下架」。
    ※ 原訂「用商品頁 product_type 判非書」**已作廢**:9/1 probe 實測一張心意卡的
      product_type 也是 "Books",它是 OpenCart 屬性群組名稱,不是商品類型。
 2. 欄位解析深度:規格表夠乾淨 → **全欄位解析入平面欄**,同時整表存 extra.spec_all
    (不同於真哪噠只存 raw)。
 3. 來源分類依據:**清單走訪蒐集**(一書多分類,商品頁麵包屑只帶進入路徑),
    分類 code 用平台數字 path(如 73_75_62_92),不用中文 slug(教會公報社撞碼教訓)。

用法(主機 ~/books/crawler):
  venv/bin/python tiendao_crawler.py --probe        # 先跑,貼回輸出確認
  venv/bin/python tiendao_crawler.py --limit 30     # 試跑 30 件
  nohup venv/bin/python tiendao_crawler.py > logs/tiendao.log 2>&1 &   # 全量(可續跑)
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urljoin, urlparse, parse_qs

from bs4 import BeautifulSoup

from common import JsonlWriter, make_session, polite_fetch
from tiendao_isbn import (is_fake_isbn_leaflet, mislabeled_reason,
                          normalize_language, resolve as resolve_isbn)

# 版本戳記:每次改動就往上加,啟動時印出來。
# 9/1 為了確認「主機上跑的到底是哪一版」翻了半天輸出差異才判斷出檔案沒換 ——
# 一行戳記就能省掉這種考古。改程式時**務必**同步更新這裡。
CRAWLER_REV = ("2026-09-02b  ISBN 三段式(檢查碼驗證+ISBN10還原含X末碼)、"
               "福音單張假號清空、已知誤標清空、language 正規化")

BASE = "https://www.tiendao.org.hk"
STORE = BASE + "/store/"
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "tiendao"
DATA = HERE / "data"
THROTTLE = (2.0, 3.0)
PAGE_LIMIT = 100          # 實測 limit=100 生效

session = make_session()

# ═══ 繁簡處理:本站與微讀/衛理**不同**,不可照抄 s2tw ═══
#
# 微讀、衛理的原文是簡體,用 s2tw(簡→繁台灣)正確。天道是香港站,原文本來就是繁體;
# 對繁體文本套 s2tw,OpenCC 會把它當簡體讀,於是做出語意錯誤的轉換。9/1 實測:
#
#   原文(正確)          s2tw 後(錯誤)        t2tw 後(正確)
#   以斯帖登上后位        以斯帖登上後位  ✗      以斯帖登上后位
#   選立新后             選立新後      ✗      選立新后
#   米母干的回答          米母乾的回答    ✗      米母干的回答
#   這裏                這裡                 這裡
#
# 「后」是王后的后、「米母干」是和合本的譯名,s2tw 一律當成「後」「乾」的簡化字。
# 正解:繁體來源用 **t2tw**(繁→台灣字形),只做 裏→裡 這類字形正規化,不動語意;
# 真正的簡體品項(語言欄寫「簡體」,本站只有「中型裝（簡體）」2 件)才用 s2tw。
try:
    from opencc import OpenCC
    _CC_HANS = OpenCC("s2tw")   # 簡體來源:簡→繁(台灣字形)
    _CC_HANT = OpenCC("t2tw")   # 繁體來源:僅字形正規化,不做簡繁語意映射
except ImportError:
    _CC_HANS = _CC_HANT = None


def zh_norm(s: str | None, is_hans: bool) -> str | None:
    """依來源字體選轉換器。繁體文本誤用 s2tw 會改壞語意(見上方對照表)。"""
    cc = _CC_HANS if is_hans else _CC_HANT
    return cc.convert(s) if (s and cc is not None) else s


def s2t(s: str | None) -> str | None:
    """分類名等站台介面文字:一律視為繁體,只做字形正規化。"""
    return zh_norm(s, is_hans=False)


class SiteRefused(RuntimeError):
    """站方以 403/429 明示拒絕 —— 不重試、不繞道,直接停手回報。"""


def fetch(url: str, force: bool = False) -> str:
    """polite_fetch 之上再加一層:403/429 視為站方拒絕,立刻中止整個作業。

    這站的 robots 情況是「根目錄未宣告、店面目錄有一份不具效力的 Disallow」,
    我們依標準解釋照抓;但如果站方用狀態碼明白擋人,那就是另一回事,
    不該繼續敲門。共用的 polite_fetch 會把 429 當「主機忙」重試,這裡先攔下來。"""
    try:
        return polite_fetch(session, url, CACHE, THROTTLE, force=force)
    except RuntimeError as e:
        if re.search(r"\b(403|429)\b", str(e)):
            raise SiteRefused(
                f"站方回 403/429,判定為明示拒絕,已停止抓取。\n  網址:{url}\n  原訊息:{e}"
            ) from e
        raise


# ── 1. 導覽選單 → 分類樹 ─────────────────────────────────────

# 促銷/彙整型分類:仍走訪存證,但選 primary 分類時排最後
PROMO_PATHS = {"105", "165", "141", "62", "148"}   # 最新出版/最新禮品/查看全部×2/熱門系列


def parse_nav(force: bool = False) -> list[dict]:
    """首頁導覽選單 → 分類清單(nav 順序)。實測 95 個、深達 4 層,SSR 直出。

    回傳 [{code, name, path_id, depth, url, order, promo}];code 即平台數字 path。"""
    soup = BeautifulSoup(fetch(STORE, force=force), "lxml")
    cats: list[dict] = []
    seen: set[str] = set()
    for a in soup.select('a[href*="route=product/category"]'):
        href = urljoin(STORE, a.get("href", ""))
        pid = parse_qs(urlparse(href).query).get("path", [""])[0]
        name = s2t(a.get_text(strip=True))
        if not pid or not name or pid in seen:
            continue
        seen.add(pid)
        top = pid.split("_")[0]
        cats.append({
            "code": pid[:40],                 # subjects.code(40)
            "name": name,
            "path_id": pid,
            "depth": pid.count("_") + 1,
            "url": f"{STORE}index.php?route=product/category&path={pid}",
            "order": len(cats),
            "promo": pid in PROMO_PATHS or top in PROMO_PATHS,
        })
    return cats


def list_url(path_id: str, page: int = 1) -> str:
    return (f"{STORE}index.php?route=product/category&path={path_id}"
            f"&limit={PAGE_LIMIT}&page={page}")


TOTAL_RE = re.compile(r"共\s*([\d,]+)\s*個")   # 站方字串:顯示 1 到 2 個 (共 2 個) - (共 1 頁)


def extract_tiles(html: str) -> list[str]:
    """清單頁 → product_id 清單(每張商品磚只取一個;磚內圖片/標題/按鈕各帶一次)。"""
    soup = BeautifulSoup(html, "lxml")
    out: list[str] = []
    for tile in soup.select(".product-layout"):
        a = tile.select_one('a[href*="product_id="]')
        if a is None:
            continue
        pid = parse_qs(urlparse(a["href"]).query).get("product_id", [""])[0]
        if pid and pid not in out:
            out.append(pid)
    return out


def walk_category(cat: dict, force: bool = False) -> list[str]:
    """走完一個分類的所有分頁。超界回空頁(實測),故以「空頁即停」為終止條件。

    另比對站方自印的「共 N 個」:數不合就出聲——分類清單走漏是最難察覺的錯,
    寧可在 log 裡吵一句,也不要事後靠對帳才發現(基道 77.8% 涵蓋率教訓)。"""
    pids: list[str] = []
    declared: int | None = None
    page = 1
    while True:
        html = fetch(list_url(cat["path_id"], page), force=True)  # 清單頁一律 force
        if page == 1:
            m = TOTAL_RE.search(html)
            declared = int(m.group(1).replace(",", "")) if m else None
        got = extract_tiles(html)
        if not got:
            break
        pids += [p for p in got if p not in pids]
        if len(got) < PAGE_LIMIT:   # 未滿頁即最後一頁,省一次請求
            break
        page += 1
    if declared is None:
        print(f"  [提醒] {cat['path_id']} {cat['name']}:讀不到「共 N 個」,"
              f"實收 {len(pids)} 件,請人工確認", flush=True)
    elif declared != len(pids):
        print(f"  [不符] {cat['path_id']} {cat['name']}:站方 {declared} 件、"
              f"實收 {len(pids)} 件", flush=True)
    return pids


# ── 2. 商品頁 ────────────────────────────────────────────────

def product_url(pid: str) -> str:
    return f"{STORE}index.php?route=product/product&product_id={pid}"


# 規格表 label → rec 欄位(未列入者仍全數存 extra.spec_all)
SPEC_MAP = {
    "英文名稱": "title_en",
    "作者": "authors_raw",
    "譯者": "translators_raw",
    "語言": "language",
    "出版商": "publisher",
    "第一版": "_first_edition",
    "最新一版": "_latest_edition",
    "頁數": "page_count",
    "書籍系列": "series_text",
    "條碼": "_barcode",
    "備註": "_spec_note",
}
# ISBN 格式/檢查碼判定已移到 tiendao_isbn.py(9/2)。原本這裡有一個
# ISBN13_RE = r"97[89]\\d{10}" ——它只檢格式不驗檢查碼,是 63 筆錯號的成因。
# 刻意不留這個常數,避免日後有人再拿它當「是不是 ISBN」的判準。

# 佔位符:站方在「沒有這一項」時填一個破折號,而且**全形半形混用**
#   9/1 probe 實測:譯者欄一本是 "–"(EN DASH U+2013)、另一本是 "-"(HYPHEN)。
#   不濾掉的話 import 的 split_names() 會把它當人名,persons 表就會多出
#   一個叫「–」的譯者、還跟其他書的「-」分屬兩個人。
_PLACEHOLDERS = {"-", "–", "—", "‒", "－", "─", "n/a", "N/A", "na", "無", "沒有", "不適用", "."}


def _clean(v: str | None) -> str | None:
    """去空白;佔位符與空字串一律當作「沒有這個值」回 None。"""
    if v is None:
        return None
    s = v.strip()
    return None if (not s or s in _PLACEHOLDERS or s.lower() in _PLACEHOLDERS) else s
MONEY_RE = re.compile(r"HK\$\s*([\d,]+(?:\.\d+)?)")
# 本書價格落在「發表點評」與「數量」之間(實測文字流),當選擇器失手時的保底
PRICE_FLOW_RE = re.compile(r"發表點評.*?HK\$\s*([\d,]+(?:\.\d+)?)", re.S)


def _strip_noise(soup: BeautifulSoup) -> None:
    """拆掉關聯商品/輪播/頁首購物車 —— 價格與欄位都被它們汙染過(v1、v2 各中一次)。"""
    for tag in soup(["script", "style", "noscript"]):
        tag.decompose()
    for sel in (".related", "#related", ".swiper", ".owl-carousel", ".carousel",
                "#cart", ".cart", "header", "footer", ".product-related"):
        for el in soup.select(sel):
            el.decompose()
    # 沒有統一類名時,靠標題文字定位關聯商品區。
    # 只拆一層(標題的直接父節點)—— 9/1 probe 實測這樣就足以讓價格取對
    # (拆前取到 180/115/150/150 關聯價,拆後取到本書價 165/20/10)。
    # 不往上多拆,是因為再往上很可能連本書價格區一起刪掉。
    for el in soup.find_all(string=re.compile("更多來自這個品牌")):
        node = el.parent
        if node is not None and node.name not in ("body", "html"):
            node.decompose()


# 點評小工具:整段固定文字,永遠在最尾端 → 切到結尾。
# ★ 必須錨定「發表點評 + 請先 + 登錄」這串完整序列,不能只認「發表點評」四個字:
#   第一版我把「電子書平臺（請按圖示或連結以取得）」也當成切到結尾的標記,結果
#   樣本 1 的版面是「目錄 → 電子書平臺… → [內容簡介] → [作者介紹]」,
#   一刀下去把真正的書介整段刪掉,自己的離線測試才抓到。
_DESC_TAIL_RE = re.compile(r"\n?\s*發表點評\s*\n\s*請先\s*\n\s*登錄[\s\S]*$")
# 電子書導購句是「行內雜訊」,不是結尾標記 → 只刪那一行
_DESC_LINE_NOISE_RE = re.compile(r"(?m)^\s*電子書平[臺台]（請按圖示或連結以取得）\s*$\n?")
# 站方用方括號當段落標題:[內容簡介]、[作者介紹]…(全形半形都見過)
_DESC_HEAD_RE = re.compile(r"^[\[［【]\s*(.{2,12}?)\s*[\]］】]\s*$", re.M)


def _strip_desc_tail(body: str) -> str:
    """砍掉描述區尾端的點評小工具/導購句。

    不砍的話每本書的簡介都會以「發表點評 請先 登錄 或 註冊 再發表點評」收尾,
    而且這串會一起被搜尋索引到 —— 搜「登錄」就命中全站每一本天道書。"""
    return _DESC_LINE_NOISE_RE.sub("", _DESC_TAIL_RE.sub("", body)).strip()


def _split_desc_sections(body: str) -> tuple[str | None, dict[str, str]]:
    """描述區 → (前導段, {段落標題: 內文})。

    樣本 1 實測是「目錄(無標題)→ [內容簡介] → [作者介紹]」黏成一大塊,
    不切開的話書目頁開頭會是幾百行目錄,而不是這本書在講什麼。"""
    heads = list(_DESC_HEAD_RE.finditer(body))
    if not heads:
        return (body.strip() or None), {}
    lead = body[:heads[0].start()].strip() or None
    sections: dict[str, str] = {}
    for i, h in enumerate(heads):
        end = heads[i + 1].start() if i + 1 < len(heads) else len(body)
        txt = body[h.end():end].strip()
        if txt:
            sections.setdefault(h.group(1), txt)
    return lead, sections


def _iso_date(raw: str | None) -> str | None:
    """2007-12 / 2024-1（第二版第3次印刷）/ 2005-09 → YYYY-MM(補零)。

    ★ 補零地雷:8/29 基道那次就是沒補零,2024-1 排序排到 2024-10 後面。"""
    if not raw:
        return None
    m = re.search(r"(\d{4})\D{0,3}(\d{1,2})?\D{0,3}(\d{1,2})?", raw)
    if not m:
        return None
    out = m.group(1)
    if m.group(2):
        out += f"-{int(m.group(2)):02d}"
        if m.group(3):
            out += f"-{int(m.group(3)):02d}"
    return out


def parse_product(pid: str, cats: list[dict]) -> dict | None:
    url = product_url(pid)
    try:
        html = fetch(url)
    except SiteRefused:
        raise
    except RuntimeError as e:
        print(f"  [跳過 {pid},下次重跑補抓] {e}", flush=True)
        return None

    soup = BeautifulSoup(html, "lxml")
    og_image = (soup.select_one('meta[property="og:image"]') or {}).get("content") \
        if soup.select_one('meta[property="og:image"]') else None
    _strip_noise(soup)

    h1 = soup.select_one("h1")
    title_raw = h1.get_text(strip=True) if h1 else None
    if not title_raw:
        print(f"  [略過 {pid}] 找不到書名", flush=True)
        return None

    rec: dict = {
        "pid": pid,
        "source": "tiendao",
        "source_url": url,
        "currency": "HKD",
        "is_ebook": False,
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }

    # ── 規格表:第一列是商品類型,其餘為 label|value ──
    spec: dict[str, str] = {}
    product_type = None
    table = soup.select_one("#tab-specification table") or soup.select_one("#content table")
    if table is not None:
        for tr in table.select("tr"):
            cells = [c.get_text(" ", strip=True) for c in tr.select("td, th")]
            cells = [c for c in cells if c]
            if len(cells) == 1 and product_type is None:
                product_type = cells[0]          # 例:Books
            elif len(cells) >= 2:
                spec.setdefault(cells[0], cells[1])
    if product_type:
        # ⚠ 9/1 probe 實測:**這個值不能拿來判非書**。
        # 一張「心意卡 (約14:27)」的 product_type 也是 "Books" —— 它其實是 OpenCart
        # 屬性群組(attribute group)的名稱,不是商品類型。原本三項決議之一
        # 「用 product_type 判非書」據此作廢,改回分類 map 下架(沿天恩/衛理)。
        # 仍照存,當作日後如果站方改用它時的線索。
        rec["product_type"] = product_type
    if spec:
        rec["spec_all"] = spec                   # 整表存證(多重呈現原則)

    for label, field in SPEC_MAP.items():
        val = _clean(spec.get(label))
        if val:
            rec[field] = val

    # ── ISBN:三段式判定(規則在 tiendao_isbn.py,與 fix_tiendao_isbn.py 共用)──
    #
    # 9/2 對帳實測 63 筆檢查碼不合。舊寫法 `if ISBN13_RE.fullmatch(cand)` 只檢格式
    # 不驗檢查碼,條碼欄一旦「像」ISBN13 就 break —— ISBN 欄的 fallback 從未被試過,
    # 而站方把舊書 ISBN10 加 978 前綴時沒重算檢查碼,錯號因此全數被採用。
    # 錯一碼的 ISBN 若撞到別站某本書的正確 ISBN,import.php:359 會把兩本不同的書
    # 合併成同一個 Work —— 比漏收嚴重得多,所以驗不過的一律不寫號、只存證。
    barcode = rec.pop("_barcode", "") or ""
    isbn_field = (spec.get("國際標準圖書編號 (ISBN)")
                  or spec.get("國際標準圖書編號(ISBN)") or "")
    res = resolve_isbn(barcode, isbn_field)
    if res["status"] == "ok":
        rec["isbn"] = res["isbn"]
        raw = (res["detail"].get("raw") or {})
        if res["isbn"] not in raw.values():       # 經還原/轉換 → 留站方原值存證
            rec["isbn_source_raw"] = {k: v for k, v in raw.items() if v}
    elif res["status"] == "conflict":
        rec["isbn_conflict"] = res["detail"].get("candidates")
        rec["isbn_source_raw"] = {k: v for k, v in
                                  (res["detail"].get("raw") or {}).items() if v}
    elif res["status"] == "invalid":
        rec["isbn_invalid"] = {k: v for k, v in
                               (res["detail"].get("raw") or {}).items() if v}
    bar_n = (barcode or "").replace("-", "").strip()
    if bar_n and bar_n != rec.get("isbn") and not rec.get("isbn_invalid"):
        rec["ean_upc"] = bar_n                   # 非 ISBN 的條碼(禮品)存 extra

    # 版次:第一版=作品首版(給 publish_date)、最新一版=本版敘述
    first_ed = rec.pop("_first_edition", None)
    latest_ed = rec.pop("_latest_edition", None)
    if first_ed:
        rec["publish_date"] = _iso_date(first_ed)
        rec["first_edition_raw"] = first_ed
    if latest_ed:
        rec["edition_statement"] = latest_ed     # 例:2024-1（第二版第3次印刷）
        if not rec.get("publish_date"):
            rec["publish_date"] = _iso_date(latest_ed)
    if rec.get("page_count"):
        m = re.search(r"[\d,]+", str(rec["page_count"]))
        rec["page_count"] = m.group(0).replace(",", "") if m else None
    if rec.pop("_spec_note", None):
        rec.setdefault("spec_all", {})           # 備註已在 spec_all,不另建平面欄

    # ── Model(店內貨號)/ 庫存:在 #content 文字流,label 與值分行 ──
    text = soup.get_text("\n", strip=True)
    m = re.search(r"(?m)^Model:?\s*\n\s*(\S+)", text)
    if m:
        rec["item_no"] = m.group(1)[:30]         # → identifiers(STORE)
    m = re.search(r"(?m)^Stock:?\s*\n\s*(.+)$", text)
    if m:
        rec["availability"] = m.group(1).strip()[:50]

    # ── 價格:雜訊已拆,選擇器優先,文字流保底 ──
    price = None
    for sel in (".product-price", ".price-new", "#price-special", "#price-old",
                ".price", "h2"):
        for el in soup.select(sel):
            mm = MONEY_RE.search(el.get_text(" ", strip=True))
            if mm:
                price = mm.group(1).replace(",", "")
                break
        if price:
            break
    if price is None:
        mm = PRICE_FLOW_RE.search(text)
        if mm:
            price = mm.group(1).replace(",", "")
    if price is not None:
        rec["price_list"] = price

    # ── 簡介 ──
    # 9/1 修正:第一輪我斷言「本站整站沒有簡介」是錯的 —— 簡介不在 #tab-description,
    # 而在 .tab-content。撈到之後才看見真正的問題:那一整塊是「目錄 + [內容簡介] +
    # [作者介紹] + 電子書連結 + 點評小工具」全部黏在一起,直接當 summary 會讓
    # 書目頁開頭是一長串目錄,而不是這本書在講什麼。
    body = None
    for sel in ("#tab-description", "#tab-desc", ".tab-content", "#description"):
        el = soup.select_one(sel)
        if el is not None:
            txt = re.sub(r"\n{3,}", "\n\n", el.get_text("\n", strip=True))
            if txt and "產品規格" not in txt[:20] and len(txt) > 30:
                body = txt
                break
    if body is None:
        for attr, key in (("property", "og:description"), ("name", "description")):
            el = soup.select_one(f'meta[{attr}="{key}"]')
            if el and (el.get("content") or "").strip():
                body = el["content"].strip()
                break
    if body:
        body = _strip_desc_tail(body)
        lead, sections = _split_desc_sections(body)
        summary = (sections.get("內容簡介") or sections.get("簡介")
                   or sections.get("內容介紹") or lead)
        if summary:
            rec["summary"] = summary
        for key, field in (("作者介紹", "author_intro"), ("作者簡介", "author_intro"),
                           ("譯者介紹", "translator_intro"), ("目錄", "toc")):
            if sections.get(key) and not rec.get(field):
                rec[field] = sections[key]
        # 前導段在有 [內容簡介] 標記時就是目錄(樣本 1 實測),不要丟掉
        if sections and lead and not rec.get("toc") and summary is not lead:
            rec["toc"] = lead
        if sections:
            rec["desc_sections"] = sections
        # 整塊原文只在「與 summary 不同」時另存,避免每本書把同一段存兩份
        if body != rec.get("summary"):
            rec["desc_raw"] = body

    # ── 封面:og:image → 原圖(注意尾巴的 w,已實測驗證)──
    if og_image:
        rec["og_image"] = og_image
        m = re.match(r"^(.*)/image/cache/(.+?)-\d+x\d+\w*(\.[A-Za-z]+)$", og_image)
        rec["cover_url"] = f"{m.group(1)}/image/{m.group(2)}{m.group(3)}" if m else og_image

    # ── 分類(清單走訪蒐集;primary 取最深的非促銷分類)──
    ordered = sorted(cats, key=lambda c: (c["promo"], -c["depth"], c["order"]))
    rec["categories"] = [{"code": c["code"], "path": c["name"]} for c in ordered]
    if ordered:
        rec["category_source"] = ordered[0]["code"]
        rec["category_text"] = ordered[0]["name"]

    # ── 簡繁:全部長短文欄位都過 OpenCC,但只有「關鍵短欄位」留原文到 hans ──
    #
    # 9/1 比對第二、三輪輸出才發現 toc / author_intro 漏了轉換:
    #   站方原文        s2tw 後
    #   以斯帖登上后位   以斯帖登上後位
    #   米母干的回答     米母乾的回答
    #   台北中華福因…    臺北中華福因…
    # 也就是說天道的資料本身帶著「由簡轉繁沒轉乾淨」的痕跡,OpenCC 反而是在修它。
    # 漏轉的欄位會讓同一本書的 summary 是「後位」、toc 是「后位」,兩種字形並存。
    # hans 只留短欄位:目錄/作者介紹動輒數千字,兩份存進 extra 會把 JSON 撐大,
    # 而它們也不是拿來做跨站比對的鍵。
    # is_hans 必須在轉換**之前**判定:語言欄位是選轉換器的依據
    # (沿衛理:只認「語言」欄明說簡體,不用字形猜,寧漏勿誤標)
    is_hans = "簡體" in (rec.get("language") or "")
    rec["is_hans"] = is_hans

    # ── language 主欄位正規化(9/2:「瀪體中文」站方錯字 9 筆、含換行 1 筆)──
    # 放在 is_hans 之後:is_hans 只認「簡體」,而正規化不會讓「簡體」出現或消失,
    # 但順序寫死在這裡才不會有人日後把正規化搬到前面去而改變判定依據。
    _lang, _lang_raw = normalize_language(rec.get("language"))
    if _lang_raw is not None:
        rec["language"] = _lang
        rec["language_source_raw"] = _lang_raw

    # ── 福音單張 TGP0xx:站方在 ISBN 欄貼了一串連號,與天註書逐一撞號 ──
    # 每日新品也會走到這裡,所以必須在爬蟲階段就清掉,不能只靠離線修正工具。
    if is_fake_isbn_leaflet(rec):
        if rec.get("isbn"):
            rec["isbn_fake_leaflet"] = rec.pop("isbn")
        rec.pop("ean_upc", None)

    # ── 已知站方誤標(同 ISBN 掛兩本不相干的書,見 tiendao_isbn 清單)──
    _why = mislabeled_reason(rec)
    if _why is not None and rec.get("isbn"):
        rec["isbn_mislabeled"] = {"value": rec.pop("isbn"), "why": _why}

    HANS_KEYS = {"title", "authors_raw", "translators_raw", "publisher",
                 "language", "series_text"}
    hans: dict = {}
    for key, val in [("title", title_raw), ("authors_raw", rec.get("authors_raw")),
                     ("translators_raw", rec.get("translators_raw")),
                     ("publisher", rec.get("publisher")), ("summary", rec.get("summary")),
                     ("language", rec.get("language")),
                     ("series_text", rec.get("series_text")),
                     ("title_en", rec.get("title_en")),
                     ("toc", rec.get("toc")),
                     ("author_intro", rec.get("author_intro")),
                     ("translator_intro", rec.get("translator_intro")),
                     ("desc_raw", rec.get("desc_raw"))]:
        if val is None:
            continue
        conv = zh_norm(val, is_hans)
        # hans 存的是「簡體原文」,只有簡體品項才有意義;
        # 繁體品項的 t2tw 只是 裏→裡 之類字形正規化,不值得再存一份
        if is_hans and conv != val and key in HANS_KEYS:
            hans[key] = val
        rec[key] = conv
    if rec.get("desc_sections"):
        rec["desc_sections"] = {zh_norm(k, is_hans): zh_norm(v, is_hans)
                                for k, v in rec["desc_sections"].items()}
    if hans:
        rec["hans"] = hans

    return rec


# ── 3. 主流程 ────────────────────────────────────────────────

def collect_memberships(cats: list[dict]) -> dict[str, list[dict]]:
    """走訪所有分類 → {product_id: [所屬分類...]}。一書多分類,以 pid 去重。"""
    members: dict[str, list[dict]] = {}
    for i, c in enumerate(cats, 1):
        pids = walk_category(c)
        print(f"[清單 {i}/{len(cats)}] {c['path_id']} {c['name']}:{len(pids)} 件",
              flush=True)
        for p in pids:
            lst = members.setdefault(p, [])
            if c["code"] not in [x["code"] for x in lst]:
                lst.append(c)
    return members


def probe() -> None:
    print(f"=== 天道 探測模式 ===  [程式版本 {CRAWLER_REV}]", flush=True)
    if _CC_HANT is None:
        print("[警告] 未安裝 OpenCC,字形不做正規化", flush=True)

    cats = parse_nav(force=True)
    print(f"[選單] 分類 {len(cats)} 個(預期 95);深度分布:"
          f"{ {d: sum(1 for c in cats if c['depth'] == d) for d in sorted({c['depth'] for c in cats})} }",
          flush=True)
    dup = len(cats) - len({c["code"] for c in cats})
    print(f"[檢查] code 重複 {dup}(應為 0);超過 40 字 "
          f"{sum(1 for c in cats if len(c['code']) >= 40)}(應為 0)", flush=True)

    # 三個代表樣本:一般書(天道聖經註釋)、聖經、禮品
    for path_id, label in [("73_75_62_92", "書籍"), ("73_82_81", "聖經"),
                           ("73_140_153", "禮品")]:
        c = next((x for x in cats if x["path_id"] == path_id), None)
        if c is None:
            print(f"\n--- 樣本({label}):選單找不到 path={path_id},略過 ---", flush=True)
            continue
        pids = extract_tiles(fetch(list_url(path_id), force=True))
        print(f"\n--- 樣本({label}:{c['name']},清單 {len(pids)} 件)---", flush=True)
        if not pids:
            continue
        rec = parse_product(pids[0], [c])
        if rec:
            print(json.dumps(rec, ensure_ascii=False, indent=1)[:2600], flush=True)
            # 逐項自我檢查,免得又要靠肉眼在 JSON 裡找
            checks = [
                ("價格", rec.get("price_list"), "本書價,不該是關聯商品那組"),
                ("ISBN13", rec.get("isbn"), "書應該有;禮品沒有屬正常"),
                # 9/2 起 isbn 可能被三段式擋下 → 分辨「站方沒給」與「驗不過」
                ("ISBN擋下", rec.get("isbn_invalid") or rec.get("isbn_conflict")
                             or rec.get("isbn_fake_leaflet"),
                 "有值代表檢查碼驗不過/兩欄衝突/單張假號,已存證不寫號"),
                ("ISBN原值", rec.get("isbn_source_raw"), "有值代表經還原或 ISBN10 轉換"),
                ("貨號", rec.get("item_no"), ""),
                ("封面原圖", rec.get("cover_url"), "不該含 /image/cache/"),
                ("首版日期", rec.get("publish_date"), ""),
                ("簡介", (rec.get("summary") or "")[:24] or None,
                 "★ 應是書介本文,不是目錄開頭"),
                ("目錄", (rec.get("toc") or "")[:16] or None, "有 [內容簡介] 標記的書才會分出來"),
                ("作者介紹", (rec.get("author_intro") or "")[:16] or None, ""),
                ("譯者", rec.get("translators_raw"), "破折號佔位符應已濾成 None"),
            ]
            print("  自我檢查:", flush=True)
            for name, val, note in checks:
                mark = "✓" if val else "—"
                print(f"    {mark} {name:<6} {str(val)[:60]:<62}{note}", flush=True)
            if "/image/cache/" in (rec.get("cover_url") or ""):
                print("    ⚠ 封面仍是快取縮圖,原圖改寫沒中", flush=True)
            for bad in ("發表點評", "電子書平"):
                if bad in (rec.get("summary") or ""):
                    print(f"    ⚠ 簡介殘留雜訊「{bad}」,清理 regex 要再修", flush=True)
            # 殘留簡體字檢查。
            # 舊版是比對「後/后、乾/干」同時出現就告警 —— 那是誤設計:
            # 「王后」與「之後」本來就會共存於同一段文字,只會一直誤報。
            # 改成直接找**只在簡體才用的字**:出現了就代表這筆該走 s2tw 卻沒走
            # (語言欄位標錯,或站方混入簡體品項)。
            blob = " ".join(str(rec.get(k) or "") for k in
                            ("title", "summary", "toc", "author_intro", "authors_raw"))
            simp = {c for c in "们这说时国经书车东马丽义习专门长阅读写学观点属灵祷" if c in blob}
            if simp:
                print(f"    ⚠ 出現簡體字 {''.join(sorted(simp))},"
                      f"is_hans={rec.get('is_hans')} —— 語言欄位可能標錯", flush=True)

    print("\n(請把以上輸出貼回,確認欄位/價格/ISBN/封面/簡繁無誤後再開全量)", flush=True)


def main() -> int:
    ap = argparse.ArgumentParser(description="天道書樓爬蟲")
    ap.add_argument("--probe", action="store_true", help="探測:選單 + 三個樣本")
    ap.add_argument("--limit", type=int, default=0, help="只抓 N 件(試跑)")
    args = ap.parse_args()

    try:
        if args.probe:
            probe()
            return 0

        print(f"[程式版本 {CRAWLER_REV}]", flush=True)
        DATA.mkdir(exist_ok=True)
        writer = JsonlWriter(DATA / "tiendao_books.jsonl", key_field="pid")

        print("== 第一階段:選單 + 全分類清單走訪 ==", flush=True)
        cats = parse_nav(force=True)
        print(f"選單分類 {len(cats)} 個", flush=True)
        members = collect_memberships(cats)
        print(f"★ 去重後唯一商品 {len(members)} 件"
              f"(各類加總含父子重複,不可當全站量)", flush=True)

        todo = [p for p in members if p not in writer.seen]
        print(f"待抓 {len(todo)} 件(檔案已有 {len(writer.seen)})", flush=True)

        print("== 第二階段:抓商品頁 ==", flush=True)
        done = 0
        for p in todo:
            rec = parse_product(p, members[p])
            if rec and not writer.has(rec["pid"]):
                writer.write(rec)
                done += 1
                if done % 50 == 0:
                    print(f"  已入檔 {done}/{len(todo)}", flush=True)
            if args.limit and done >= args.limit:
                print(f"到達 --limit {args.limit},停止", flush=True)
                break
        print(f"完成:本次入檔 {done} 件(檔案累計 {len(writer.seen)})", flush=True)
        return 0

    except SiteRefused as e:
        print(f"\n⛔ {e}\n請回報熊哥,不要重跑。", flush=True)
        return 2


if __name__ == "__main__":
    sys.exit(main())
