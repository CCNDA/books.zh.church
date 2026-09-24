# -*- coding: utf-8 -*-
"""浸信會出版社(國際)網上書店 shop.bappress.org 爬蟲——第十八個書目來源(v1.15.0)。

版本戳記寫在下方程式區的 CRAWLER_REV 常數,--probe 與全量都會印在第一行;
FTP 沒蓋到時靠它當場抓出來(grep 只會命中那一行,本段刻意不寫成賦值式)。

════════ 2026-09-21 瀏覽器實測(不是推測,票上與 9/20 的數字都要以本節為準)════════

**票上前提全部推翻(9/20 已驗一次,今日再確認)**
  票上寫              9/21 實測
  靜態 PHP        →   Yii 框架 SSR 網上書店 shop.bappress.org
  無購物車        →   有(addtocart / 會員 / 運費計算)→ **要掛購書連結**
  只收書目        →   作廢
  約 7,200 本次   →   **9,305 本次 / 3,845 種 unique 貨號**

**★★ 分類選單不等於全站分類。** 首頁選單只有 75 個 /books/category/ 連結,
  但掃 id 1–200 另外找到 **6 個選單外分類**,合計 **79 個有內容的分類**:
     24 讀經/研經(529)  25 中文聖經(84)  26 英文聖經(72)
     57 詩集/歌書(5)    62 最新產品(6)   79 精選蔡錦圖博士書藉(10)
  這 6 個分類讓 unique 貨號從 3,221 → **3,845(+624,+19.4%)**。
  → **本爬蟲一律掃 id 範圍列舉分類,不從選單取。**(選單少一層中間分類,
     例如某書寫「分類:中文聖經」而選單裡根本沒有那個連結。)

**★★ 分類收碼仍不等於全站清單(孤兒商品確實存在)。**
  2024 年的舊 sitemap 只有 336 筆,其中 **4 筆不在 79 個分類的收碼裡**,
  逐筆查證:9781963292008《從經文到講章》**有現貨、分類欄空白**;
  9786267287156 等三筆同型。→ 權威清單必須是
  **分類收碼 ∪ sitemap ∪ 最新產品(id 62)**,且對帳差集不會天然為 0,
  判準要先寫清楚,不要把差集一律當成漏抓警報。

**★★ HTTP 500 不是結束,是過載。** 首次全掃時分類 33(心理/情緒)在第 11 頁回 500,
  當時的腳本把它當結束 → 該類少收 63 本次。改為退避重試後重掃得 303 本次 13 頁。
  common.polite_fetch 已內建 500/502/503/504 等 60 秒×次數重試,**不要在外層把它當終止條件**。

**分頁**:每頁 24;超界回 0 筆(乾淨);`page=abc` 退回第 1 頁 24 筆。
  ★ pager 的最大頁碼**隨請求頁碼動態生成**(請求 p99 時 pager 顯示到 98)→ pager 不可當總頁數。
  終止條件雙保險:①該頁 0 筆(先 force 重抓一次再判空,陷阱 23)
                  ②`.pagination li.active` 的頁碼必須等於請求頁碼(陷阱 24)

**欄位覆蓋率(9/21 抽樣 131 本,抽自 31 個主題大類的第 1、3 頁,不抽新品促銷)**
  書名/分類/定價/出版日期/作者 100% | 出版社 98.5% | Barcode 99.2%
  頁數 91.6% | 封面 94.7% | 尺寸 77.1%(已扣除 `0.0 × 0.0 mm` 佔位)| 重量 71.8%
  多分類(兩類以上)74.8% | 簡介 <100 字 20.6% | **簡介最長 7,631 字元**

**★★★ 本站最大的坑:`ISBN：` 那一欄多半不是 ISBN。**
  合法 ISBN-13(驗檢查碼):**Barcode 欄 95.4%(125/131)、ISBN 欄只有 55.7%(73/131)**。
  ISBN 欄實際內容常是站方書號:LP1111、PT212、BTS303、CKT0293、SA1744、MHR801、
  TATFS0F02、AA1531、24050……,還有 `978626725721`(12 碼壞號,只檢格式就會採用)。
  → **ISBN 取 Barcode 欄**;`ISBN：` 欄一律當書號寫 `item_no`,原值留 `isbn_field_raw`。
  → 兩欄都是合法 13 碼但不相等的情形實測有(貨號 9789628402335 / Barcode 9789628402366)
     → 兩個都寫進 identifiers 候選,主 ISBN 取 Barcode,另一個留 `isbn_alt`,不靜默丟棄。

**同書多貨號**:`-D####` 後綴貨號全站 121 筆(例 9789887593133 與 9789887593133-D0038,
  同書名同作者同 Barcode,尺寸 0.0×0.0)。它們會以同 Barcode 併成一本、掛兩條購書連結,
  **dry-run 合併明細要逐筆看**。貨號最長實測 19 字元。

**這站賣的不只自家書**:出版社欄大量是他社(聖經資源中心、基道、天道、道聲、光啟、
  建道、漢語聖經協會、麥種……)→ **與既有十六家重疊會很高**。
  陷阱 27 適用:dry-run 若印「模糊比對 0」當場追,不要記進待辦。

**幣別 HKD**(定價寫 `HK$262.0`)★ 必須在 tools/import.php 的 SOURCE_CURRENCY 登錄,
  漏登會靜默落回 TWD。

════════ 待熊哥裁示(未定案前不要跑 --emit-map-sql 產正式 migration)════════
  決議二 欄位解析深度:本檔先按慣用解實作(正則抽關鍵欄位 + 規格全文存 extra)。
  決議三 79 個分類 → CategoryV11 對映依據:**CAT_MAP 右欄尚未填**,
        因為對映表的 internal_name 必須是 categories.name 實際存在的值,
        填錯不會報錯、只會靜默不歸類。需熊哥貼回:
            SELECT category_id, code, name FROM categories ORDER BY code;
        書系類分類(麥種聖經註釋、丁道爾、ACCS、明道、普天、天道聖經註釋、
        猶太人智慧故事、天國的童話、我與天父、繽紛小樹苗、我的天糧、
        不再一樣/塑造主門徒、反思系列、精選蔡錦圖)建議一律 NULL 改寫 series。
        非書(文具 77、影音 52)建議 unpublish=1 —— 依 2026-09-17 收錄判準一,
        文具/周邊不屬於書目收錄範圍。

════════ 2026-09-22 主機 probe 覆核(第一次真的跑起來)════════

**對上的**:分類列舉 **79 個有內容**(與瀏覽器實測吻合);分頁邊界 page=1/2 各 24 筆、
pager active 等於請求頁碼、page=99 回 0 筆;ISBN **12/12 全部取自 Barcode**;
store_code 6/12(LP1111、TH0053、C010003、A53TW1709、210L2404…)—— 與「ISBN 欄多半
不是 ISBN」的判斷完全一致;最長欄位值 cover_url 79、source_url 45,離 schema 上限很遠。

**★★ 抓到的 bug(已修,REV 2026-09-22a)**
1. **summary 抓錯節點**:第一版正則寫 `id="...info..."`,命中的是分頁**導覽列**
   (`<a id="pills-info-tab">產品資訊</a>`),於是每本書的簡介都變成
   「目錄 / 試讀 / 付款/運送」這串標籤,最長只有 25 字元(瀏覽器實測應為 7,631)。
   ★ 而**覆蓋率照樣印 summary 12/12 100%** —— 有值不等於值是對的。
   新增欄位一定要同時看**最長值與樣本內容**,只看覆蓋率會被騙。
   正確錨點是 `<div class="tab-pane" id="pills-info">`,另把「目錄」分頁
   (`pills-index`)收進 `toc_raw`。
2. **404 被當成可重試錯誤**:掃 250 個 id 時有 171 個 404,polite_fetch 一律重試 5 次
   還遞增退避,光等 404 就吃掉大半時間。改為 `_probe_category()`:
   **404 立刻放棄、5xx 與連線錯誤才退避重試**——反過來把 5xx 當成「不存在」會
   靜默漏掉整個分類(9/21 分類 33 就是被 500 咬掉 63 本次)。
3. 「非書候選 0 本」「無主題分類 0 本」兩張報表在 12 本樣本下都是 0;
   已加註「樣本太小,這個 0 不是結論」——**0 是最容易被當成沒事的數字**。

用法:
    python3 bappress_crawler.py --probe            # 分類列舉/分頁邊界/抽樣欄位/陷阱全量檢查
    python3 bappress_crawler.py                    # 全量
    python3 bappress_crawler.py --limit 50
    python3 bappress_crawler.py --new              # 每日新品(最新產品 id 62)
    python3 bappress_crawler.py --emit-map-sql     # 分類對映表 SQL(待決議三定案後才有意義)
"""
from __future__ import annotations

import argparse
import html as _html
import json
import random
import re
import time
from datetime import datetime, timezone
from pathlib import Path

import requests

from common import JsonlWriter, State, make_session, polite_fetch

# ★ 每次改動都要遞增。2026-09-22 當天改了三輪卻一直掛在 "a",
#   等於 grep 戳記分辨不出新舊 —— 戳記存在的唯一理由就是「當場抓出 FTP 沒蓋到」,
#   不遞增就等於沒有。
#   a = 首版修 summary/404;b = 後處理與目錄上限;c = 白名單正規化、CARRY、rebuild-catids
#   d = categories[].code 改用分類數字 id(對映表對得上)
CRAWLER_REV = "2026-09-22d"

BASE = "https://shop.bappress.org"
SITEMAP = BASE + "/sitemap.xml"
SOURCE = "bappress"
CURRENCY = "HKD"
NEW_CAT_ID = 62                 # 「最新產品」分類(★ 只有 6 筆,涵蓋率待檢定,見 collect_new)

HERE = Path(__file__).parent
CACHE = HERE / "cache" / "bappress"
DATA = HERE / "data"
THROTTLE = (2.0, 3.5)
PER_PAGE = 24
CAT_ID_MAX = 250                # 掃描分類 id 上界(9/21 實測 1–200 內最大有效 id 為 186)
PAGE_CAP = 80                   # 單一分類頁數上界(9/21 實測最大 33 頁)

session = make_session()


# ── 1. 文字工具 ─────────────────────────────────────────────
# ★ 冒號字元類別一律用 unicode escape 寫,不要打字面全形冒號:
#   7/12 曾發生 [:：] 在生成時退化成兩個半形冒號 [::],全形冒號整組失效。
_COLONS = "\uff1a:\u2236"
_TAG_RE = re.compile(r"<[^>]+>")
# 冒號後用 [ \t]* 不要 \s*:空值時 \s* 會吃掉換行、把下一行的標籤當成值。
_LABEL_TPL = r"{}\s*[" + _COLONS + r"][ \t]*([^\n]*)"
_PLACEHOLDER = {"", "-", "—", "N/A", "n/a", "無", "無資料", "未提供", "不提供",
                "mm", "g", "0.0 × 0.0 mm", "0.0 x 0.0 mm", "0.0 g", "0.0", "0"}
_DAYS = (31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31)
# 簡體字偵測(只做標記,絕不做字形轉換 —— 9/1 教訓:繁體來源套 s2tw 會把「后位→後位」改壞)
_HANS_HINT = re.compile(r"[简书讲学总经义证灵长门爱贵传圣与体录变]")


def strip_html(s: str | None) -> str:
    if not s:
        return ""
    s = re.sub(r"<br\s*/?>", "\n", s, flags=re.I)
    s = re.sub(r"</(p|div|li|tr|h[1-6])>", "\n", s, flags=re.I)
    s = _TAG_RE.sub(" ", s)
    s = _html.unescape(s)                       # 陷阱 11:HTML 實體要解碼
    s = re.sub(r"[\u00a0\u3000]", " ", s)
    s = re.sub(r"[ \t]{2,}", " ", s)
    return re.sub(r"\n{3,}", "\n\n", s).strip()


def field(text: str, label: str) -> str | None:
    m = re.search(_LABEL_TPL.format(re.escape(label)), text)
    if not m:
        return None
    v = m.group(1).strip()
    return None if v in _PLACEHOLDER else v


def _ck13(d12: str) -> str:
    s = sum((1 if i % 2 == 0 else 3) * int(c) for i, c in enumerate(d12))
    return str((10 - s % 10) % 10)


def valid13(d: str) -> bool:
    return len(d) == 13 and d.isdigit() and _ck13(d[:12]) == d[12]


def valid10(d: str) -> bool:
    if len(d) != 10:
        return False
    try:
        return sum((10 - i) * (10 if c in "Xx" else int(c))
                   for i, c in enumerate(d)) % 11 == 0
    except ValueError:
        return False


def isbn13_from(raw: str | None) -> tuple[str | None, str | None]:
    """回傳 (ISBN13, 還原方式)。★ 一律驗檢查碼——只檢格式會採用錯號(陷阱 19)。
    本站實測有 `978626725721`(12 碼)這種壞號,格式檢查會放它過去。"""
    if not raw:
        return None, None
    d = re.sub(r"[^0-9Xx]", "", str(raw)).upper()
    if valid13(d):
        return d, "十三碼"
    if valid10(d):
        core = "978" + d[:9]
        return core + _ck13(core), "ISBN10 轉 13(重算檢查碼)"
    if len(d) == 10 and d.isdigit() and valid13("978" + d):
        return "978" + d, "原書號補 978 還原"
    return None, None


def parse_pubdate(raw: str | None) -> tuple[str | None, str | None]:
    """本站 9/21 抽樣 131 本全部是 `YYYY.MM.DD` 且已補零,但不能因此不設防:
    來源端哪天改成 `2026.7.1`,靜默算錯月份是最難發現的一種錯(陷阱 15)。"""
    if not raw:
        return None, None
    m = re.search(r"(\d{4})\D{0,2}(\d{1,2})(?:\D{0,2}(\d{1,2}))?", str(raw))
    if not m:
        return None, None
    y, mo = int(m.group(1)), int(m.group(2))
    if not (1900 <= y <= 2100 and 1 <= mo <= 12):
        return None, None
    if m.group(3):
        day = int(m.group(3))
        if 1 <= day <= _DAYS[mo - 1]:
            return f"{y:04d}-{mo:02d}-{day:02d}", None
        return f"{y:04d}-{mo:02d}", "日不合法,只到月"
    return f"{y:04d}-{mo:02d}", "原值只到月"


def guess_script(title: str, text: str) -> str:
    if re.search(r"[（(]\s*[简簡]\s*[體体]?\s*[)）]|[简簡][體体]版?|简体", title):
        return "hans" if ("简" in title or "体" in title) else "hant"
    if _HANS_HINT.search(title):
        return "hans"
    return "hans" if len(_HANS_HINT.findall(text[:400])) >= 3 else "hant"


# ── 2. 清單頁 ───────────────────────────────────────────────

_CODE_RE = re.compile(r'href="/books/([^"/?#]+)"')
_ACTIVE_RE = re.compile(r'<li[^>]*class="[^"]*\bactive\b[^"]*"[^>]*>\s*(?:<a[^>]*>)?\s*(\d+)')
_H_RE = re.compile(r"<h1[^>]*>(.*?)</h1>|<h2[^>]*>(.*?)</h2>", re.S)


def list_page(cat_id: int, page: int, force: bool = False) -> tuple[list[str], int | None, str]:
    """回傳 (貨號清單, pager 目前頁碼, 分類名)。列表頁一律 force(陷阱 14):
    沒 force 會讀到永久快取 —— 基道曾因此六週零新書而 log 全綠。"""
    html = polite_fetch(session, f"{BASE}/books/category/{cat_id}?page={page}",
                        CACHE, THROTTLE, force=force)
    codes = list(dict.fromkeys(_CODE_RE.findall(html)))
    m = _ACTIVE_RE.search(html)
    act = int(m.group(1)) if m else None
    h = _H_RE.search(html)
    name = strip_html((h.group(1) or h.group(2)) if h else "") if h else ""
    return codes, act, name


def walk_category(cat_id: int) -> tuple[list[str], str, str]:
    """收一個分類的全部貨號。回傳 (codes, 分類名, 結束原因)。

    ★ 終止條件雙保險,兩條缺一不可:
      ① 該頁 0 筆 → 先 force 重抓一次再判空(陷阱 23:站方會回「有總數但零商品」的頁)
      ② pager 的 active 頁碼必須等於請求頁碼(陷阱 24:超界時篩選失效、
         退化成全站最新書籍卻照樣標第 1 頁,會無限迴圈兼灌錯分類)
    ★ HTTP 500 由 polite_fetch 退避重試,**不在這裡當終止條件**——
      9/21 實測分類 33 就是被 500 騙成「結束」,少收 63 本次。"""
    codes: list[str] = []
    name, stop = "", "empty"
    for page in range(1, PAGE_CAP + 1):
        try:
            got, act, nm = list_page(cat_id, page, force=True)
        except RuntimeError as e:
            stop = f"抓取失敗:{e}"
            print(f"    [警告] 分類 {cat_id} 第 {page} 頁抓取失敗,**不當成結束** → {e}", flush=True)
            break
        if nm:
            name = name or nm
        if not got:                                     # 保險①
            got2, act2, _ = list_page(cat_id, page, force=True)
            if not got2:
                stop = "empty"
                break
            got, act = got2, act2
            print(f"    [注意] 分類 {cat_id} 第 {page} 頁首次回 0 筆,重抓得 {len(got)} 筆"
                  "(站方偶發空頁,已吸收)", flush=True)
        if page > 1 and act is not None and act != page:  # 保險②
            stop = f"頁碼標記不符(請求 {page}、回來 {act})"
            break
        codes.extend(got)
        if len(got) < PER_PAGE:
            stop = f"末頁({len(got)} 筆 < {PER_PAGE})"
            break
    else:
        stop = f"達頁數上界 {PAGE_CAP}(★ 請調高 PAGE_CAP 後重跑)"
    return list(dict.fromkeys(codes)), name, stop


def _probe_category(cid: int, max_retries: int = 3) -> str | None:
    """分類 id 探測。回傳 HTML,或 None 表示該 id 不存在。

    ★ 9/22 probe 實測的效能坑:掃 250 個 id 時有 171 個是 404,
      而 polite_fetch 把所有 RequestException 一視同仁重試 5 次(還遞增退避),
      光是等 404 就佔掉大半執行時間。
    ★ 但**不能反過來把所有錯誤都當成不存在**:5xx 是過載,
      當成「這個分類不存在」會靜默漏掉整個分類(9/21 的分類 33 就是被 500 咬掉 63 本次)。
    所以這裡分開處理:404 立刻放棄、5xx 與連線錯誤才退避重試。
    不走快取——分類清單本來就要每次重新確認(列表頁一律 force)。"""
    url = f"{BASE}/books/category/{cid}?page=1"
    for attempt in range(1, max_retries + 1):
        time.sleep(random.uniform(*THROTTLE) * (1 if attempt == 1 else attempt))
        try:
            r = session.get(url, timeout=60)
        except requests.RequestException as e:
            print(f"    [連線錯誤 {attempt}/{max_retries}] id={cid} → {e}", flush=True)
            continue
        if r.status_code == 404:
            return None                      # 確定不存在,不重試
        if r.status_code in (429, 500, 502, 503, 504):
            print(f"    [{r.status_code}] id={cid} 主機忙碌,退避重試 {attempt}/{max_retries}", flush=True)
            time.sleep(30 * attempt)
            continue
        if r.status_code != 200:
            print(f"    [HTTP {r.status_code}] id={cid},視為不存在", flush=True)
            return None
        if not r.encoding or r.encoding.lower() == "iso-8859-1":
            r.encoding = r.apparent_encoding
        return r.text
    print(f"    ★ id={cid} 連 {max_retries} 次都失敗 —— **不當成不存在**,請重跑確認", flush=True)
    return None


def discover_categories(id_max: int = CAT_ID_MAX) -> dict[int, str]:
    """★ 掃 id 範圍列舉分類,不從選單取。
    9/21 實測:選單 75 個,掃 id 另得 6 個(24/25/26/57/62/79),
    那 6 個帶進 624 種書(+19.4%)。只信選單就會漏掉五分之一。
    9/22 主機 probe 覆核:全站 79 個有內容的分類,與瀏覽器實測吻合。"""
    found: dict[int, str] = {}
    for cid in range(1, id_max + 1):
        html = _probe_category(cid)
        if html is None:
            continue
        codes = list(dict.fromkeys(_CODE_RE.findall(html)))
        if not codes:
            continue
        h = _H_RE.search(html)
        name = strip_html((h.group(1) or h.group(2)) if h else "") if h else ""
        found[cid] = name or f"分類{cid}"
        print(f"  id={cid:<4} {len(codes):3d}+ 筆  {found[cid]}", flush=True)
    return found


# ── 3. 商品頁 ───────────────────────────────────────────────

def parse_product(code: str, html: str) -> dict | None:
    text = strip_html(html)
    h = _H_RE.search(html)
    title = strip_html((h.group(1) or h.group(2)) if h else "")
    if not title:
        return None

    rec: dict = {
        "pid": code,
        "source": SOURCE,
        "source_url": f"{BASE}/books/{code}",
        "title": title,
        "currency": CURRENCY,
        "item_no": code[:30],                 # 貨號(實測最長 19)
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "crawler_rev": CRAWLER_REV,
    }

    for label, key in (("作者", "authors_raw"), ("出版社", "publisher"),
                       ("貨號", "goods_no"), ("分類", "category_text"),
                       ("Barcode", "barcode"), ("ISBN", "isbn_field_raw"),
                       ("出版日期", "pubdate_raw"), ("重量", "weight"),
                       ("尺寸", "dimensions"), ("頁數", "page_count_raw"),
                       ("定價", "price_raw"), ("庫存狀態", "stock_status")):
        v = field(text, label)
        if v:
            rec[key] = v[:200]

    # ★★ ISBN:取 Barcode 欄,不取 ISBN 欄(9/21 實測 95.4% vs 55.7%)。
    #    ISBN 欄的內容多半是站方書號(LP1111、BTS303…),寫進 item_no 候選、原值留存。
    got, how = isbn13_from(rec.get("barcode"))
    if got:
        rec["isbn"], rec["isbn_from"] = got, f"Barcode:{how}"
    elif rec.get("barcode"):
        rec["isbn_raw"] = rec["barcode"][:40]          # 還原不出就留原值,不丟棄
    alt, alt_how = isbn13_from(rec.get("isbn_field_raw"))
    if alt and alt != rec.get("isbn"):
        # 兩欄都合法但不相等(實測有)→ 兩個都留,主 ISBN 仍取 Barcode
        rec["isbn_alt"], rec["isbn_alt_from"] = alt, f"ISBN欄:{alt_how}"
    if rec.get("isbn_field_raw") and not alt:
        rec["store_code"] = rec["isbn_field_raw"][:40]  # 站方書號

    # 貨號本身常是 ISBN13(9/21:3,845 種裡 2,503 種是 978/979 開頭)
    if not rec.get("isbn"):
        got2, how2 = isbn13_from(code.split("-")[0])
        if got2:
            rec["isbn"], rec["isbn_from"] = got2, f"貨號:{how2}"

    if rec.get("pubdate_raw"):
        d, note = parse_pubdate(rec["pubdate_raw"])
        if d:
            rec["publish_date"] = d
            if note:
                rec["publish_date_from"] = note
        else:
            rec["publish_date_bad"] = rec["pubdate_raw"][:40]
        rec.pop("pubdate_raw", None)

    # 頁數:多值鐵律——非純數字不進數值欄,原值留 raw,不拆不加總
    if rec.get("page_count_raw"):
        cand = re.sub(r"\s*[頁页]\s*$", "", rec["page_count_raw"].strip())
        if re.fullmatch(r"\d{1,5}", cand):
            rec["page_count"] = cand
            rec.pop("page_count_raw")

    if rec.get("price_raw"):
        m = re.search(r"([\d,]+(?:\.\d+)?)", rec["price_raw"])
        if m:
            rec["price_list"] = m.group(1).replace(",", "")

    # 分類是逗號分隔多值(74.8% 的書掛兩類以上)——原樣保留不拆,另存拆好的清單
    if rec.get("category_text"):
        parts = [p.strip() for p in re.split(r"[,,]", rec["category_text"]) if p.strip()]
        if parts:
            rec["categories"] = [{"code": p, "path": p} for p in parts]
            rec["category_source"] = parts[0]

    m = re.search(r'src="(/frontend/web/cache/books/[^"]+)"', html)
    if m:
        rec["cover_url"] = BASE + _html.unescape(m.group(1))

    # 簡介:實測最長 7,631 字元 → summary 截 4000,全文另存(不可塞進 summary)
    desc = _extract_desc(html)
    if desc:
        rec["summary"] = desc[:4000]
        if len(desc) > 4000:
            rec["desc_full"] = desc[:60000]
    # 目錄分頁。熊哥 9/22 裁示「收」。★ 上限由 20,000 放寬到 60,000(與 desc_full 齊):
    #   全量實測有書撞到 20,000,那代表**有資料被靜默截掉**,違反多值鐵律。
    #   仍會截的極少數會在 audit 的「目錄被截斷」那行印出筆數,不讓它無聲無息。
    toc = _extract_toc(html)
    if toc:
        rec["toc_raw"] = toc[:60000]
        if len(toc) > 60000:
            rec["toc_truncated"] = len(toc)

    rec["script"] = guess_script(title, desc or "")
    if re.search(r"試讀", text):
        rec["has_preview"] = True
    return rec


def _tab_pane(html: str, pane_id: str) -> str:
    """取出某個 Bootstrap 分頁的內容。

    ★★ 9/22 probe 抓到的 bug:第一版正則寫 `id="...info..."`,結果命中的是**分頁導覽列**
       (`<a id="pills-info-tab" ...>產品資訊</a>`),不是內容區,
       於是每本書的 summary 都變成「目錄 / 試讀 / 付款/運送」這串標籤,
       **覆蓋率照樣印 100%**。這就是「工具自印的數字會說謊」——
       覆蓋率只證明欄位有值,不證明值是對的。**新增欄位一定要看最長值與樣本內容**。

    實際結構(9/22 實測):
        <div class="tab-pane fade show active" id="pills-info" role="tabpanel" ...>
            <h2>產品資訊</h2>
            <p>內容簡介<br />…</p>
        </div>
    導覽列那個是 `id="pills-info-tab"`,所以錨點必須用 **`id="pills-info"` 後面緊接非 `-`**,
    而且要從 `tab-pane` 那個 class 開始找,不能只找 id。
    """
    m = re.search(r'<div[^>]*class="[^"]*\btab-pane\b[^"]*"[^>]*\bid="' + re.escape(pane_id)
                  + r'"[^>]*>', html, re.I)
    if not m:
        return ""
    start = m.end()
    # 到下一個 tab-pane 為止;沒有下一個就到 tab-content 容器結束
    nxt = re.search(r'<div[^>]*class="[^"]*\btab-pane\b', html[start:], re.I)
    body = html[start:start + nxt.start()] if nxt else html[start:start + 80000]
    return strip_html(body)


def _extract_desc(html: str) -> str:
    """簡介 = 「產品資訊」分頁(id=pills-info)的內容,去掉開頭的 h2 標題。"""
    body = _tab_pane(html, "pills-info")
    body = re.sub(r"^\s*產品資訊\s*", "", body)
    # 沒有簡介的書,這一格可能整個空的;別把付款運送條款當簡介(SSR 站常見的切除陷阱)
    if re.match(r"^\s*(付款|運送|浸信會網上書店|浸信會出版社)", body):
        return ""
    return body.strip()


def _extract_toc(html: str) -> str:
    """目錄是另一個分頁(id=pills-index)。不是所有書都有。"""
    body = _tab_pane(html, "pills-index")
    return re.sub(r"^\s*目錄\s*", "", body).strip()


def fetch_product(code: str, force: bool = False) -> dict | None:
    try:
        html = polite_fetch(session, f"{BASE}/books/{code}", CACHE, THROTTLE, force=force)
    except RuntimeError as e:
        print(f"  [抓取失敗] {code} → {e}", flush=True)
        return None
    return parse_product(code, html)


# ── 4. 權威清單與對帳 ───────────────────────────────────────

def sitemap_codes(force: bool = True) -> list[str]:
    """2024 年用 mysitemapgenerator 產的舊檔,只有 336 筆商品,**不可當權威清單**,
    但它裡面有 4 筆分類收碼收不到的孤兒商品 → 拿來補集與對帳仍有價值。"""
    try:
        xml = polite_fetch(session, SITEMAP, CACHE, THROTTLE, force=force)
    except RuntimeError as e:
        print(f"  [sitemap 抓取失敗] {e}", flush=True)
        return []
    urls = re.findall(r"<loc>([^<]+)</loc>", xml)
    return list(dict.fromkeys(u.split("/books/")[1] for u in urls if "/books/" in u
                              and u.split("/books/")[1] and "/" not in u.split("/books/")[1]))


def build_authoritative(cats: dict[int, str]) -> tuple[dict[str, list[int]], dict]:
    """權威清單 = 分類收碼 ∪ sitemap ∪ 最新產品。
    ★ 這三者的聯集仍不保證等於全站(站上存在無分類且不在舊 sitemap 的商品),
      所以對帳差集不會天然為 0 —— 報表要分開列,不要一律當漏抓警報。"""
    owner: dict[str, list[int]] = {}
    stats: dict = {"cats": {}, "occurrences": 0}
    for cid, name in cats.items():
        codes, nm, stop = walk_category(cid)
        stats["cats"][cid] = {"name": name or nm, "n": len(codes), "stop": stop}
        stats["occurrences"] += len(codes)
        for c in codes:
            owner.setdefault(c, []).append(cid)
        flag = "" if stop in ("empty",) or stop.startswith("末頁") else "  ★異常結束"
        print(f"  id={cid:<4} {len(codes):4d} 筆  {(name or nm)[:22]:24s} [{stop}]{flag}", flush=True)

    sm = sitemap_codes()
    orphan = [c for c in sm if c not in owner]
    stats["sitemap"] = len(sm)
    stats["orphans"] = orphan
    for c in orphan:
        owner.setdefault(c, [])
    print(f"\n  分類收碼 unique {sum(1 for v in owner.values() if v)} 種 / "
          f"掛載 {stats['occurrences']} 本次", flush=True)
    print(f"  sitemap {len(sm)} 筆,其中不在分類收碼裡的孤兒 {len(orphan)} 筆:", flush=True)
    for c in orphan[:20]:
        print(f"    + {c}", flush=True)
    print(f"  權威清單合計 {len(owner)} 種", flush=True)
    return owner, stats


# ── 4b. 後處理:重複建檔標記與版本標註 ──────────────────────
# 熊哥 2026-09-22 裁示兩件,都在這裡實作:
#   (1) `-D####` 重複建檔 → **只留無後綴那條購書連結**,-D 貨號仍寫 identifiers 供對帳
#   (2) 同 ISBN 不同商品(藍/紅聖經)→ 購書連結**加標註**區分
#
# ★★ 兩條判準都刻意寫成「發生了什麼事」,不是「資料長什麼樣」:
#   - `-D####` 本身不代表重複 —— 只有在**主貨號也確實存在**時才是重複建檔。
#     若某個 -D 沒有對應主貨號,它就是站上唯一一筆,拿掉它的購書連結會讓那本書
#     一條連結都沒有。(這正是「用資料形狀當旗標」踩過的坑:曾以 isbn13 為 null
#     當旗標,等於關掉全站無 ISBN 書的模糊比對。)
#   - 顏色/裝幀詞本身不代表版本 —— 書名叫《藍色的天空》的書不該被標「(藍色)」。
#     只有在**同一個 ISBN 底下有多筆、且書名不同**時才去抽差異詞。

_DUP_RE = re.compile(r"^(.+)-D\d+$")
# 書名正規化:只保留中日韓文字與英數,其餘(所有標點、空白,全形半形都算)一律丟掉。
# ★ 用途是判斷「兩筆是不是同一本書」,不是拿來寫入資料 —— 存進 DB 的一律是原書名。
#
# ★★ 2026-09-22 事故:第一版是**列舉標點**的黑名單寫法
#    `[\s·．.,,、;;::…]`,結果全形逗號/分號/冒號/括號在寫檔時
#    **全部退化成半形重複**(,,→,,、::→::),等於那些全形標點一個都沒去掉。
#    「幸福家庭重建:30天禱告手冊」與「…-30天禱告手冊」照樣被判為不同書,
#    待複核清單預期 4→2 卻還是 4 —— 是這個「對不上的數字」把 bug 抓出來的。
#    (專案陷阱清單第 11 條就寫過這件事,本檔上方的 _COLONS 也早就用 unicode escape,
#     我在同一支程式裡遵守了一處、漏掉另一處。)
#    → 改成**白名單**:根本不列舉標點,就不可能因為字元退化而失效。
_KEEP_RE = re.compile(r"[0-9A-Za-z一-鿿㐀-䶿豈-﫿]+")


def norm_title(s: str) -> str:
    return "".join(_KEEP_RE.findall(s or ""))
_VARIANT_WORDS = re.compile(
    r"(藍色|紅色|黑色|白色|棕色|咖啡色?|紫色|粉紅色?|銀色|金色|灰色|綠色|橙色|米色|"
    r"拉鍊|索引|精裝|平裝|軟皮|硬皮|皮面|布面|大字|袖珍)")


def mark_dup_and_variants(recs: list[dict]) -> dict:
    """就地標記 is_dup_listing / dup_of / variant_tag,回傳統計。"""
    codes = {r["pid"] for r in recs}
    stats = {"dup": 0, "dup_orphan": 0, "variant": 0, "variant_unresolved": 0,
              "dup_orphan_list": [], "variant_unresolved_list": []}

    for r in recs:
        m = _DUP_RE.match(r["pid"])
        if not m:
            continue
        base = m.group(1)
        if base in codes:                      # ★ 主貨號存在才算重複建檔
            r["is_dup_listing"] = True
            r["dup_of"] = base
            stats["dup"] += 1
        else:                                  # 沒有主貨號 → 它就是唯一一筆,照常掛連結
            stats["dup_orphan"] += 1
            stats["dup_orphan_list"].append((r["pid"], r.get("title", "")))

    # 版本標註:同 ISBN 多筆(排除已判定重複建檔的)且書名不同時才標
    by_isbn: dict[str, list[dict]] = {}
    for r in recs:
        if r.get("isbn") and not r.get("is_dup_listing"):
            by_isbn.setdefault(r["isbn"], []).append(r)
    for isbn, grp in by_isbn.items():
        # ★ 書名比較前先正規化。9/22 實測:「幸福家庭重建:30天禱告手冊」與
        #   「幸福家庭重建-30天禱告手冊」只差一個標點,字串不等但明明是同一本書,
        #   拿去抽差異詞當然抽不出來,只會塞爆待複核清單。
        if len(grp) < 2 or len({norm_title(g.get("title", "")) for g in grp}) < 2:
            continue
        # ★★ 2026-09-22 修:標註要取「這本**獨有**的詞」,不是「第一個命中的詞」。
        #    第一版用 _VARIANT_WORDS.search() 取最左命中,結果
        #      「19克超薄和合本**皮面**聖經-藍色」→ 標「(皮面)」
        #      「19克超薄和合本**皮面**聖經-紅色」→ 也標「(皮面)」
        #    兩條購書連結標註一模一樣,**完全失去區分作用**(實查 links 才看到)。
        #    「皮面」是兩本共有的,它根本不是差異。
        #    改法:先算出每本命中的詞集合,只保留同組其他書都沒有的那些。
        found = [set(_VARIANT_WORDS.findall(g.get("title", ""))) for g in grp]
        for i, g in enumerate(grp):
            others: set[str] = set()
            for j, s in enumerate(found):
                if j != i:
                    others |= s
            uniq = sorted(found[i] - others)
            if uniq:
                g["variant_tag"] = uniq[0]
                stats["variant"] += 1
            else:
                stats["variant_unresolved"] += 1
                stats["variant_unresolved_list"].append((isbn, g["pid"], g.get("title", "")))
    return stats


def apply_cat_ids(rec: dict, ids: list[int], cats: dict[int, str]) -> None:
    """把分類收碼的結果寫進記錄:cat_ids 與 categories(code 用**數字 id**)。

    ★★ 為什麼 code 一定要用數字 id:
      對映表 `bappress_category_map.bappress_code` 是站方分類的數字 id。
      第一版的 parse_product 用商品頁「分類:」欄的**中文名**當 code,
      兩邊對不起來 —— 而照慣例**填錯不會報錯,只會靜默一列都不歸類**。
      (陷阱 6 也早就寫過:分類代碼用平台數字 id,不要用中文 slug。)
    ★ 兩個來源可以互換嗎?2026-09-22 抽 60 本均勻樣本比對
      「商品頁分類欄」vs「該書出現在哪些分類清單」:**零差異**(60/60 完全一致)。
      所以改用數字 id 不會改變任何一本書的歸類。
      中文名仍完整保留在 category_text,日後要回頭對照得到。
    ★ 沒有 cat_ids 的(sitemap 孤兒)維持 parse_product 用中文名建的 categories,
      它們本來就不屬於任何分類,對映表也對不到 —— 那是事實,不是 bug。
    """
    if not ids:
        return
    rec["cat_ids"] = ";".join(str(x) for x in ids)
    rec["categories"] = [{"code": str(i), "path": cats.get(i, f"分類{i}")} for i in ids]
    rec["category_source"] = str(ids[0])


def postprocess_file(path: Path) -> dict:
    """讀 jsonl → 標記 → 原子覆寫。中斷後續跑也適用(不依賴記憶體中的 recs)。"""
    recs = []
    with path.open("r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if line:
                recs.append(json.loads(line))
    stats = mark_dup_and_variants(recs)
    tmp = path.with_suffix(".tmp")
    with tmp.open("w", encoding="utf-8") as f:
        for r in recs:
            f.write(json.dumps(r, ensure_ascii=False) + "\n")
    tmp.replace(path)

    print("\n[後處理:重複建檔與版本標註]", flush=True)
    print(f"  -D 重複建檔(主貨號存在,不掛購書連結):{stats['dup']}", flush=True)
    print(f"  -D 但主貨號不存在(照常掛連結):{stats['dup_orphan']}", flush=True)
    for pid, t in stats["dup_orphan_list"][:10]:
        print(f"    - {pid:22s} {t[:34]}", flush=True)
    print(f"  同 ISBN 多筆且書名不同 → 已標版本:{stats['variant']}", flush=True)
    print(f"  ★ 同上但抽不出差異詞(標不了,需人工看):{stats['variant_unresolved']}", flush=True)
    for isbn, pid, t in stats["variant_unresolved_list"][:20]:
        print(f"    - {isbn}  {pid:22s} {t[:34]}", flush=True)
    return stats


# ── 5. 陷阱稽核報表 ─────────────────────────────────────────

def audit(recs: list[dict]):
    n = max(len(recs), 1)
    print("\n[陷阱:ISBN 欄不是 ISBN —— 兩欄對照]", flush=True)
    bc_ok = sum(1 for r in recs if r.get("isbn_from", "").startswith("Barcode"))
    fld_ok = sum(1 for r in recs if r.get("isbn_alt"))
    raw = [r for r in recs if r.get("isbn_raw")]
    code_src = sum(1 for r in recs if r.get("isbn_from", "").startswith("貨號"))
    print(f"  ISBN 取自 Barcode        {bc_ok:5d}/{n}  {bc_ok * 100 // n:3d}%", flush=True)
    print(f"  ISBN 取自貨號(Barcode 無){code_src:5d}/{n}", flush=True)
    print(f"  ISBN 欄另有合法且不同號   {fld_ok:5d}  ← 兩個都留,主號取 Barcode", flush=True)
    print(f"  Barcode 有值但驗不過      {len(raw):5d}", flush=True)
    for r in raw[:10]:
        print(f"    - {r['title'][:24]:26s} {r['isbn_raw']}", flush=True)

    by: dict[str, list[dict]] = {}
    for r in recs:
        if r.get("isbn"):
            by.setdefault(r["isbn"], []).append(r)
    dup = {k: v for k, v in by.items() if len(v) > 1}
    print(f"\n[站內同 ISBN 組數:{len(dup)}]  ★ 多為同書多貨號(-D#### 後綴),"
          "但也可能是站方抄錯 —— 逐組看過再決定要不要登錄 ISBN_NOT_UNIQUE_SOURCES", flush=True)
    for k, v in list(dup.items())[:15]:
        same = "同書名" if len({x["title"] for x in v}) == 1 else "★書名不同,要查"
        print(f"  {k}  [{same}]", flush=True)
        for r in v:
            print(f"    - {r['pid']:22s} {r['title'][:30]}", flush=True)

    print("\n[出版日期非標準格式(逐筆複核)]", flush=True)
    for r in [r for r in recs if r.get("publish_date_from")][:20]:
        print(f"  {r['title'][:24]:26s} → {r['publish_date']}  {r['publish_date_from']}", flush=True)
    bad = [r for r in recs if r.get("publish_date_bad")]
    print(f"  完全解析不出:{len(bad)}", flush=True)

    print("\n[頁數多值(未寫 page_count,原值留 page_count_raw)]", flush=True)
    for r in [r for r in recs if r.get("page_count_raw")][:15]:
        print(f"  {r['title'][:24]:26s} {r['page_count_raw']}", flush=True)

    print("\n[多分類分布]", flush=True)
    multi = sum(1 for r in recs if len(r.get("categories") or []) > 1)
    print(f"  掛兩類以上:{multi}/{n}  {multi * 100 // n}%", flush=True)

    trunc = [r for r in recs if r.get("toc_truncated")]
    print(f"\n[目錄被截斷(超過 60,000 字元)]:{len(trunc)} 本", flush=True)
    for r in trunc[:10]:
        print(f"  {r['pid']:22s} 原長 {r['toc_truncated']}  {r['title'][:30]}", flush=True)

    # ★ 熊哥 9/21 裁示:非書要加書名複核。站方沒有一致地把非書標進影音/文具兩類
    #   ——《世紀頌讚選輯CD》只掛「本社書籍」。這裡只列候選,**不自動下架**,
    #   誤傷(書名裡提到 CD 的書)由人擋。
    print("\n[非書候選:書名命中關鍵字但分類不是影音/文具 —— 需人工複核,未自動下架]", flush=True)
    cand = [r for r in recs
            if NONBOOK_TITLE_RE.search(r.get("title", ""))
            and not ({"影音", "文具"} & {c["path"] for c in (r.get("categories") or [])})]
    for r in cand[:40]:
        cats = "、".join(c["path"] for c in (r.get("categories") or [])) or "(無分類)"
        print(f"  {r['pid']:22s} {r['title'][:34]:36s} [{cats}]", flush=True)
    print(f"  合計 {len(cand)} 本待複核 / 樣本 {n} 本"
          "(確認是非書就在 bappress_category_map 之外另行 is_published=0,不刪資料)", flush=True)
    if not cand and n < 200:
        print("  ★ 樣本太小,這個 0 只代表「這批沒有」,不代表全站沒有 —— 全量跑完再看這張表。",
              flush=True)

    print("\n[對映後會沒有任何主題分類的書(只掛書系/本社書籍/最新產品)]", flush=True)
    # ★ 這張表完全靠 cat_ids。若整批都沒有這個欄位,結果一定是 0 ——
    #   而那個 0 的意思是「沒得比」,不是「沒問題」。先擋掉,不要讓它假裝通過。
    have_cat = sum(1 for r in recs if r.get("cat_ids"))
    if have_cat == 0:
        print("  ★★ 本批資料沒有 cat_ids 欄位,這張表**無法計算**(不是 0 本)。", flush=True)
        print("     cat_ids 由全量流程以權威清單寫入,商品頁上沒有這個資訊。", flush=True)
        print("     若是 --reparse 產生的檔案,請跑 --rebuild-catids 補回。", flush=True)
    else:
        if have_cat < n:
            print(f"  ★ 注意:只有 {have_cat}/{n} 筆有 cat_ids,以下統計只涵蓋那些。", flush=True)
        nomap = set(SERIES_CATS) | set(NON_SUBJECT_CATS)
        nomap -= set(SERIES_FALLBACK)          # 有後備主題的不算
        orphan = [r for r in recs
                  if r.get("cat_ids")
                  and all(int(x) in nomap for x in r["cat_ids"].split(";") if x.isdigit())]
        for r in orphan[:30]:
            print(f"  {r['pid']:22s} {r['title'][:34]:36s} [cat {r['cat_ids']}]", flush=True)
        print(f"  合計 {len(orphan)} 本 / 有 cat_ids 的 {have_cat} 本"
              "(9/21 瀏覽器全站實測:補後備主題前 19 本、補後備後應剩 3 本)", flush=True)
        if not orphan and n < 200:
            print("  ★ 樣本太小,這個 0 不是結論 —— 全量跑完再看這張表。", flush=True)

    print("\n[欄位覆蓋率(全量,不是抽樣)]", flush=True)
    for k in ("title", "authors_raw", "publisher", "isbn", "barcode", "publish_date",
              "page_count", "price_list", "cover_url", "summary", "dimensions",
              "weight", "categories", "stock_status", "store_code"):
        c = sum(1 for r in recs if r.get(k))
        print(f"  {k:16s} {c:5d}/{n}  {c * 100 // n:3d}%", flush=True)

    longest: dict[str, tuple[int, str]] = {}
    for r in recs:
        for k, v in r.items():
            if isinstance(v, str) and len(v) > longest.get(k, (0, ""))[0]:
                longest[k] = (len(v), v[:80])
    print("\n[最長欄位值——對照 schema 上限(匯入前必看,陷阱 5)]", flush=True)
    for k, (ln, s) in sorted(longest.items(), key=lambda x: -x[1][0])[:14]:
        print(f"  {k:16s} {ln:6d}  {s}", flush=True)
    print("  ★ 9/21 抽樣參考:簡介最長 7,631、貨號最長 19、作者最長 49", flush=True)


# ── 6. probe ────────────────────────────────────────────────

def probe(limit: int = 12):
    print(f"== CRAWLER_REV {CRAWLER_REV} ==", flush=True)
    print("== A. 分類列舉(掃 id,不看選單)==", flush=True)
    cats = discover_categories()
    print(f"  合計 {len(cats)} 個有內容的分類", flush=True)

    print("\n== B. 分頁邊界 ==", flush=True)
    for cid, pg in ((23, 1), (23, 2), (23, 99)):
        codes, act, _ = list_page(cid, pg, force=True)
        print(f"  分類 {cid} page={pg:<4} → {len(codes):3d} 筆,pager active={act}", flush=True)
    print("  ★ pager 最大頁碼隨請求動態生成,不可當總頁數;終止只認「0 筆(重抓後仍 0)」"
          "與「active 頁碼 != 請求頁碼」", flush=True)

    print("\n== C. 抽樣欄位(抽主題大類,不抽新品促銷)==", flush=True)
    codes, _, _ = list_page(23, 2, force=True)       # 神學/教義 第 2 頁
    hits: dict[str, int] = {}
    sample = []
    for c in codes[:limit]:
        rec = fetch_product(c)
        if not rec:
            continue
        sample.append(rec)
        for k in rec:
            hits[k] = hits.get(k, 0) + 1
        print("\n  ---", rec["title"], "---", flush=True)
        print(json.dumps({k: v for k, v in rec.items()
                          if k not in ("summary", "desc_full", "toc_raw")},
                         ensure_ascii=False, indent=1)[:900], flush=True)
        # ★ 簡介一定要印長度與開頭:9/22 第一版抓錯節點,summary 變成分頁標籤列
        #   「目錄 / 試讀 / 付款/運送」,而覆蓋率照樣印 100%。有值不等於值是對的。
        s = rec.get("summary", "")
        print(f"   summary {len(s):5d} 字 | {s[:60]!r}", flush=True)
        print(f"   toc     {len(rec.get('toc_raw', '')):5d} 字", flush=True)
    if sample:
        print(f"\n  欄位命中率(樣本 {len(sample)} 件):", flush=True)
        for k, v in sorted(hits.items(), key=lambda x: -x[1]):
            print(f"    {k:18s} {v}/{len(sample)}", flush=True)
        audit(sample)

    print("\n== D. 每日新品入口涵蓋率檢定(陷阱 21)==", flush=True)
    newc, _, nm = list_page(NEW_CAT_ID, 1, force=True)
    print(f"  「{nm}」(id={NEW_CAT_ID})只有 {len(newc)} 筆。", flush=True)
    print("  ★ 這個位置很可能是**手動維護的推薦位**,不是真的新品清單。", flush=True)
    print("    併入 daily_new.sh 前必須檢定:連續幾天記錄它的內容,", flush=True)
    print("    比對同期分類頁新增的貨號 —— 涵蓋率不足就改走「分類頁第 1 頁全掃」。", flush=True)


# ── 7. 每日新品 ─────────────────────────────────────────────

def collect_new(cats: dict[int, str] | None = None) -> list[dict]:
    """★ 未完成涵蓋率檢定前,不要只靠「最新產品」(id 62,只有 6 筆)。
    這裡的預設做法是掃所有分類的第 1 頁(force),交給 import 以 source_url 判重;
    成本是每天 ~80 次請求,遠比漏抓一整批新書便宜。"""
    cats = cats or discover_categories()
    seen: list[str] = []
    for cid in list(cats) + [NEW_CAT_ID]:
        try:
            codes, _, _ = list_page(cid, 1, force=True)
        except RuntimeError as e:
            print(f"  [警告] 分類 {cid} 新品頁抓取失敗(不當成 0 筆):{e}", flush=True)
            continue
        seen.extend(codes)
    out = []
    for c in dict.fromkeys(seen):
        rec = fetch_product(c)
        if rec:
            out.append(rec)
    return out


# ── 8. 分類對映表 ───────────────────────────────────────────
# 右欄 internal_name 以 **2026-09-21 熊哥貼回的 categories 實表**為準(26 類:
# 01 聖經 / 02 聖經研究 / 03 神學 / 04 靈修 / 05 教會復興 / 06 門徒造就 / 07 見證 /
# 08 福音 / 09 兒童主日學 / 10 青少年家庭 / 11 兒童教材 / 18 詩本樂譜 / H 倫理 /
# I 社會 / J 心理 / K 哲學 / L 文學 / M 健康 / N 環境 / O 科學 / P 歷史 / Q 地理 /
# R 藝術 / S 傳媒 / T 期刊雜誌 / ZZ 綜合其他)。
# ★ 填錯不會報錯,只會靜默不歸類 → emit_map_sql() 末尾的驗證查詢務必跑,應回 0 列。
# ★ 熊哥 2026-09-21 已裁示 10 條判斷題(社會/倫理→倫理、生活教導+信徒生活→門徒造就、
#   信仰入門及成長→門徒造就、分齡課程→兒童主日學、繪本童書→兒童教材)。
#   仍有 5 條標「★待確認」(宗教教育 38、事工教材 39、崇拜/聖樂 45、工具書 55、小冊子 58,
#   合計 211 本次),先按建議值登錄;要改只需 UPDATE 對映表那 5 列,不必重爬。
SERIES_CATS = {107: "麥種聖經註釋", 99: "丁道爾研經註釋", 102: "國際釋經應用系列",
               103: "ACCS古代基督信仰聖經註釋叢書", 104: "明道研經叢書",
               182: "中文聖經註釋", 183: "普天註釋", 184: "天道聖經註釋",
               175: "猶太人智慧故事系列", 176: "天國的童話系列", 180: "我與天父系列",
               186: "反思系列", 168: "繽紛小樹苗", 170: "我的天糧",
               173: "不再一樣/塑造主門徒系列"}
# ★ 書系的「後備主題」(熊哥 2026-09-21 裁示)。
#   起因:實測有 19 本書的分類**全部落在僅存證那一組**,對映後會一個分類都沒有,
#   只能靠 classify_categories.php 的關鍵字去猜(例:《國際釋經應用系列．約書亞記》
#   只掛 102、《繽紛小樹苗-兒童崇拜-高小第六冊》只掛 168+71)。
#   做法:書系名照樣寫進 series 欄不變;這裡只是給一個 sort_order 900 的後備主題,
#   **只有在書沒有任何其他主題分類時才會生效**,同時掛具體主題的書完全不受影響。
#   只給主題明確的書系:8 個註釋書系 → 聖經研究;繽紛小樹苗(兒童崇拜教材)→ 兒童主日學。
#   其餘 6 個書系(猶太人智慧故事、天國的童話、我與天父、反思系列、我的天糧、
#   不再一樣/塑造主門徒)主題不單一,維持 NULL。
SERIES_FALLBACK = {99: "聖經研究", 102: "聖經研究", 103: "聖經研究", 104: "聖經研究",
                   107: "聖經研究", 182: "聖經研究", 183: "聖經研究", 184: "聖經研究",
                   168: "兒童主日學"}
# ★ 非書書名關鍵字(熊哥 2026-09-21 裁示:加書名複核,但**不自動下架**)。
#   起因:《世紀頌讚選輯CD》只掛「本社書籍」、沒掛影音 —— 站方沒有一致地把非書
#   標進 52/77 兩個分類,只認分類會讓 CD/DVD 混進書目。
#   audit() 會列出候選清單交人複核;誤傷風險(書名裡提到 CD 的書)由人擋,不由程式擋。
#   ★★ 2026-09-22 全量抓到的 bug:第一版寫 `\bCD\b`,結果**中文後面的 CD 抓不到**——
#      Python 的 `\w` 含中文,所以「選輯CD」的「輯」與「C」之間沒有詞界,`\b` 不成立。
#      實測:《世紀頌讚選輯CD》《美樂頌 SingCD》兩本都是 CD,候選表卻一本都沒列到
#      (它們是靠另一張「無主題分類」表才浮現的)。改用只擋英文字母的 lookaround。
#   ★ 這張表是**給人看的候選清單**,寧可多列幾筆讓人刪,也不要漏列——
#     漏列沒有任何徵兆,多列只是多看兩行。
NONBOOK_TITLE_RE = re.compile(
    r"(?<![A-Z])(?:CD|DVD|VCD)(?![A-Za-z])|(?<![A-Za-z0-9])MP3(?![0-9])|"
    r"光碟|影音|錄音帶|有聲書|有聲聖經|播放器|書籤|書衣|文具|筆記本|"
    r"教具卡|索引貼|月曆|年曆|海報|明信片|禮品|布袋|環保袋|"
    # ★ 2026-09-22 從 import dry-run 的新書清單補的:上一版關鍵字都抓不到這些
    #   「主愛同行 A4 膠套」×10(文件套)、「活水聖經播放器(豐盛版/金色)」(電子裝置)。
    # ★ 熊哥 9/22 裁示:**歌詞集/歌書集算書,歸「詩本樂譜」** —— 它們是印刷品、
    #   有內容可讀,與詩本樂譜同性質,所以**不列入非書關鍵字**;
    #   搭配販售的 CD/DVD 本身仍照舊下架。
    # ★ 熊哥 9/23 裁示:**填色簿/填色遊戲畫冊算書**(兒童活動書),不列非書 ——
    #   站上既有的填色簿(book 46545、46601)本來就是上架的,
    #   把新的擋掉會前後不一致。第一版我把「填色遊戲」寫進來是錯的。
    r"膠套|貼紙")
# 收錄判準一(2026-09-17 熊哥裁示):非書籍的周邊商品不收錄。
NONBOOK_CATS = {77: "文具", 52: "影音"}
# 非主題的篩選頁:不是分類,僅存證,不歸類也不寫 series。
NON_SUBJECT_CATS = {71: "本社書籍(出版社篩選)", 62: "最新產品(時間性)",
                    79: "精選蔡錦圖博士書藉(作者選集)"}
# code → (internal_name, unpublish, sort_order, note);sort_order 小者優先當 primary。
CAT_MAP: dict[str, tuple] = {
    # ── 01 聖經(版本本身)──
    "88": ("聖經", 0, 400, "新標點和合本"),
    "89": ("聖經", 0, 400, "和合本修定版"),
    "185": ("聖經", 0, 400, "和合本"),
    "90": ("聖經", 0, 400, "聖經浸字版"),
    "91": ("聖經", 0, 400, "新普及譯本"),
    "92": ("聖經", 0, 400, "新漢語譯本"),
    "93": ("聖經", 0, 400, "環球聖經新譯本"),
    "97": ("聖經", 0, 400, "現代中文譯本/廣東話版"),
    "94": ("聖經", 0, 400, "祈禱應許版/靈修版/研讀本"),
    "95": ("聖經", 0, 400, "大字版聖經"),
    "96": ("聖經", 0, 400, "並排版聖經"),
    "98": ("聖經", 0, 400, "簡體/拼音版/其他"),
    "27": ("聖經", 0, 400, "中英對照聖經"),
    "28": ("聖經", 0, 400, "兒童聖經(是聖經本身的兒童版,不是教材)"),
    "86": ("聖經", 0, 400, "NIV(9/21 實測 0 筆,先登錄備用)"),
    "25": ("聖經", 0, 450, "中文聖經(選單外分類)"),
    "26": ("聖經", 0, 450, "英文聖經(選單外分類)"),
    # ── 02 聖經研究 ──
    "100": ("聖經研究", 0, 420, "聖經註釋"),
    "101": ("聖經研究", 0, 500, "讀經/研經"),
    "24": ("聖經研究", 0, 500, "讀經/研經(選單外分類)"),
    "55": ("聖經研究", 0, 480, "★待確認:工具書(字典辭典→聖經研究,或改綜合其他)"),
    # ── 03 神學 ──
    "23": ("神學", 0, 420, "神學/教義"),
    "80": ("神學", 0, 420, "護教"),
    # ── 04 靈修 ──
    "31": ("靈修", 0, 420, "靈修/禱告"),
    # ── 05 教會復興(教會事工)──
    "44": ("教會復興", 0, 450, "講道/事奉"),
    "46": ("教會復興", 0, 450, "牧養輔導"),
    "47": ("教會復興", 0, 450, "教會管理"),
    "22": ("教會復興", 0, 450, "長者牧養"),
    "42": ("教會復興", 0, 450, "女性事工"),
    "41": ("教會復興", 0, 450, "男性事工(9/21 實測 0 筆,先登錄備用)"),
    "45": ("教會復興", 0, 450, "★待確認:崇拜/聖樂(崇拜事工→教會復興,或改詩本樂譜)"),
    "39": ("教會復興", 0, 470, "★待確認:事工教材(泛用教材→教會復興,或改兒童教材)"),
    # ── 06 門徒造就 ──
    "40": ("門徒造就", 0, 420, "門徒訓練"),
    "171": ("門徒造就", 0, 450, "長者查經課程"),
    "32": ("門徒造就", 0, 600, "信徒生活(768 筆,泛用大類,故 sort_order 靠後)"),
    "18": ("門徒造就", 0, 600, "熊哥 9/21 裁示:生活教導與信徒生活同歸門徒造就(780 筆)"),
    "29": ("門徒造就", 0, 470, "熊哥 9/21 裁示:信仰入門及成長歸門徒造就(重點在「及成長」)"),
    "38": ("門徒造就", 0, 470, "★待確認:宗教教育(基督教教育→門徒造就,或改兒童主日學)"),
    # ── 07 見證 ──
    "49": ("見證", 0, 420, "見證/傳記"),
    # ── 08 福音 ──
    "43": ("福音", 0, 430, "宣教/差傳"),
    "58": ("福音", 0, 480, "★待確認:小冊子(多為佈道單張→福音,或改綜合其他)"),
    # ── 09 兒童主日學 ──
    "20": ("兒童主日學", 0, 430, "兒童牧養"),
    "154": ("兒童主日學", 0, 440, "熊哥 9/21 裁示:分齡主日學課程 幼稚初級3歲"),
    "155": ("兒童主日學", 0, 440, "熊哥 9/21 裁示:分齡主日學課程 幼稚高級4-5歲"),
    "159": ("兒童主日學", 0, 440, "熊哥 9/21 裁示:分齡主日學課程 初小級6-7歲"),
    "160": ("兒童主日學", 0, 440, "熊哥 9/21 裁示:分齡主日學課程 中小級8-9歲"),
    "161": ("兒童主日學", 0, 440, "熊哥 9/21 裁示:分齡主日學課程 高小級10-11歲"),
    "162": ("青少年家庭", 0, 440, "熊哥 9/21 裁示:初中級12-14歲歸青少年家庭"),
    # ── 10 青少年家庭 ──
    "36": ("青少年家庭", 0, 430, "婚姻/戀愛"),
    "37": ("青少年家庭", 0, 430, "親子/家庭"),
    "21": ("青少年家庭", 0, 430, "青少年牧養"),
    # ── 11 兒童教材 ──
    "181": ("兒童教材", 0, 450, "熊哥 9/21 裁示:繪本/童書歸兒童教材"),
    # ── 18 詩本樂譜 ──
    "57": ("詩本樂譜", 0, 420, "詩集/歌書(選單外分類)"),
    # ── H 倫理 / I 社會 / J 心理 / K 哲學 / L 文學 / P 歷史 / T 期刊雜誌 ──
    "15": ("倫理", 0, 450, "熊哥 9/21 裁示:社會/倫理歸倫理(站內倫理與社會是兩類)"),
    "35": ("社會", 0, 450, "職場/領導"),
    "33": ("心理", 0, 420, "心理/情緒"),
    "34": ("心理", 0, 450, "人際關係"),
    "48": ("哲學", 0, 420, "哲學/宗教比較"),
    "50": ("文學", 0, 430, "文藝/勵志"),
    "30": ("歷史", 0, 420, "教會歷史"),
    "56": ("期刊雜誌", 0, 420, "期刊/雜誌"),
}


def emit_map_sql():
    cats = discover_categories()
    rows, missing = [], []
    for cid, name in sorted(cats.items()):
        code = str(cid)
        if cid in NONBOOK_CATS:
            m = (None, 1, 900, f"非書({NONBOOK_CATS[cid]})——收錄判準一:周邊商品不收錄")
        elif cid in SERIES_CATS:
            fb = SERIES_FALLBACK.get(cid)
            m = (fb, 0, 900,
                 f"書系({SERIES_CATS[cid]}),書系名寫 series;"
                 + (f"後備主題 {fb}(sort_order 900,僅在無其他主題時生效)"
                    if fb else "主題不單一,僅存證不歸類"))
        elif cid in NON_SUBJECT_CATS:
            m = (None, 0, 900, f"非主題篩選頁({NON_SUBJECT_CATS[cid]}),僅存證")
        elif code in CAT_MAP:
            m = CAT_MAP[code]
        else:
            missing.append((code, name))
            m = (None, 0, 500, "★站方新增分類,請補對映")
        nm = "NULL" if m[0] is None else "'" + m[0].replace("'", "''") + "'"
        nt = "NULL" if not m[3] else "'" + m[3].replace("'", "''") + "'"
        rows.append(f"('{code}', '{name[:80].replace(chr(39), chr(39) * 2)}', {nm}, {m[1]}, {m[2]}, {nt})")

    print(f"-- 自動產生:python3 bappress_crawler.py --emit-map-sql  (CRAWLER_REV {CRAWLER_REV})")
    print(f"-- 分類 {len(cats)} 個;尚未對映 {len(missing)} 個(決議三未定案前這是預期的)")
    print("""
CREATE TABLE IF NOT EXISTS bappress_category_map (
  bappress_code VARCHAR(40)  NOT NULL COMMENT '站方分類數字 id(★不用中文 slug,截斷會撞碼)',
  bappress_name VARCHAR(80)  NOT NULL COMMENT '站方分類名(僅供人讀)',
  internal_name VARCHAR(50)  NULL     COMMENT '對映到的站內 categories.name;NULL=僅存證不歸類',
  unpublish     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=非書,bappress-only 命中任一即下架',
  sort_order    INT          NOT NULL DEFAULT 500 COMMENT 'primary 優先序(小者優先)',
  note          VARCHAR(200) NULL,
  PRIMARY KEY (bappress_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='浸信會出版社(國際)分類對映(2026-09-21);書系 NULL 改寫 series,文具/影音 unpublish';

INSERT INTO bappress_category_map
  (bappress_code, bappress_name, internal_name, unpublish, sort_order, note) VALUES""")
    print(",\n".join(rows) + "\nON DUPLICATE KEY UPDATE bappress_name = VALUES(bappress_name);")
    print("""
-- ★★ 必跑的驗證:對映到「站內不存在的分類名」會靜默不歸類,不會報錯。
--    下面這段應該回 0 列;有回列就是 internal_name 打錯或站內分類改名了。
SELECT DISTINCT m.internal_name AS 對不到的分類名
  FROM bappress_category_map m
  LEFT JOIN categories c ON c.name = m.internal_name
 WHERE m.internal_name IS NOT NULL AND c.category_id IS NULL;""")
    if missing:
        print("\n-- ★以下分類尚未對映(已填 NULL),決議三定案後補:")
        for code, name in missing:
            print(f"--   {code}  {name}")


# ── 9. main ─────────────────────────────────────────────────

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true")
    ap.add_argument("--emit-map-sql", action="store_true")
    ap.add_argument("--new", action="store_true", help="每日新品")
    ap.add_argument("--audit-file", help="只讀既有 jsonl 重跑稽核報表,不連網、不重抓")
    ap.add_argument("--postprocess", help="對既有 jsonl 補標記 is_dup_listing / variant_tag(原子覆寫)")
    ap.add_argument("--reparse", action="store_true",
                    help="用磁碟快取重新解析全部商品(不重新下載),套用新的解析規則")
    ap.add_argument("--rebuild-catids", action="store_true",
                    help="只重跑分類收碼,把 cat_ids 補回既有 jsonl(不重抓商品頁)")
    ap.add_argument("--limit", type=int, default=0)
    args = ap.parse_args()

    # ★ 稽核規則改過(例如非書關鍵字)時,不必為了重看報表而重爬三小時。
    if args.audit_file:
        recs = []
        with open(args.audit_file, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if line:
                    recs.append(json.loads(line))
        print(f"== CRAWLER_REV {CRAWLER_REV} == 離線稽核 {len(recs)} 筆:{args.audit_file}",
              flush=True)
        audit(recs)
        return

    if args.postprocess:
        print(f"== CRAWLER_REV {CRAWLER_REV} == 後處理:{args.postprocess}", flush=True)
        postprocess_file(Path(args.postprocess))
        return

    # cat_ids(這本書出現在哪幾個分類)只有走過分類收碼才知道,商品頁上沒有。
    # 這個模式只重跑第一、二階段(分類列舉 + 分類收碼),不碰商品頁。
    if args.rebuild_catids:
        src = DATA / "bappress_books.jsonl"
        print(f"== CRAWLER_REV {CRAWLER_REV} == 重建 cat_ids", flush=True)
        cats = discover_categories()
        owner, _ = build_authoritative(cats)
        recs, hit = [], 0
        for line in src.read_text(encoding="utf-8").splitlines():
            if not line.strip():
                continue
            r = json.loads(line)
            ids = owner.get(r["pid"])
            if ids:
                apply_cat_ids(r, ids, cats)
                hit += 1
            recs.append(r)
        tmp = src.with_suffix(".tmp")
        with tmp.open("w", encoding="utf-8") as f:
            for r in recs:
                f.write(json.dumps(r, ensure_ascii=False) + "\n")
        tmp.replace(src)
        print(f"\ncat_ids 補回 {hit}/{len(recs)} 筆", flush=True)
        print("★ 補不到的是 sitemap 孤兒(不屬於任何分類),那是正常的;"
              "數量應該與對帳報表的孤兒數一致。", flush=True)
        # ★ 2026-09-22:這裡原本只跑 audit,漏了後處理 —— 於是「抽不出差異詞」那張表
        #   整個沒印,白名單正規化改了什麼、有沒有生效,完全沒有數字可對。
        #   後處理是冪等的(重跑結果相同),所以凡是重寫 jsonl 的模式都應該跟著跑一次。
        postprocess_file(src)
        recs = [json.loads(l) for l in src.read_text(encoding="utf-8").splitlines() if l.strip()]
        audit(recs)
        return

    # ★ 解析規則改過(例如目錄上限由 20,000 放寬到 60,000)時,既有 jsonl 仍是舊規則的產物。
    #   商品頁快取都在磁碟上,所以重新解析不必重新下載、不必等三小時。
    #   (快取缺頁的少數會照常下載並節流。)
    if args.reparse:
        src = DATA / "bappress_books.jsonl"
        old = {}
        for line in src.read_text(encoding="utf-8").splitlines():
            if line.strip():
                r = json.loads(line)
                old[r["pid"]] = r
        pids = list(old)
        print(f"== CRAWLER_REV {CRAWLER_REV} == 由快取重新解析 {len(pids)} 筆", flush=True)
        # ★ 2026-09-22 事故:第一版 reparse 只留重新解析出來的欄位,把 cat_ids 弄丟了
        #   —— 那個欄位是全量流程用權威清單(owner map)寫的,商品頁上沒有,重解析拿不回來。
        #   結果「對映後無主題分類」由 3 本變成 **0 本**,而 0 看起來像「沒問題」。
        #   ★ 重新解析只該改「解析得出來的欄位」,解析不出來的一律沿用舊值。
        CARRY = ("cat_ids",)
        out, failed = [], 0
        for i, c in enumerate(pids, 1):
            rec = fetch_product(c)
            if rec is None:
                failed += 1
                continue
            for k in CARRY:
                if k in old[c] and k not in rec:
                    rec[k] = old[c][k]
            out.append(rec)
            if i % 500 == 0:
                print(f"  {i}/{len(pids)}", flush=True)
        tmp = src.with_suffix(".tmp")
        with tmp.open("w", encoding="utf-8") as f:
            for r in out:
                f.write(json.dumps(r, ensure_ascii=False) + "\n")
        tmp.replace(src)
        print(f"重新解析完成:{len(out)} 筆寫回(解析失敗 {failed})", flush=True)
        print("★ 筆數必須與重解析前相同,少了就是快取缺頁或解析規則改壞了。", flush=True)
        postprocess_file(src)
        audit(out)
        return

    if args.emit_map_sql:
        emit_map_sql()
        return
    if args.probe:
        probe()
        return

    DATA.mkdir(exist_ok=True)
    print(f"== CRAWLER_REV {CRAWLER_REV} ==", flush=True)

    if args.new:
        recs = collect_new()
        w = JsonlWriter(DATA / "bappress_new.jsonl", key_field="pid")
        done = sum(1 for r in recs if w.write(r))
        print(f"每日新品:解析 {len(recs)} 件,入檔 {done} 件"
              "(★ 入檔數不等於新書數,新不新由 import 判定)", flush=True)
        return

    state = State(DATA / "bappress_state.json")
    writer = JsonlWriter(DATA / "bappress_books.jsonl", key_field="pid")
    review = (DATA / "bappress_review.tsv").open("a", encoding="utf-8")

    print("== 第一階段:列舉分類(掃 id,不看選單)==", flush=True)
    cats = discover_categories()
    print(f"分類 {len(cats)} 個", flush=True)

    print("\n== 第二階段:權威清單(分類收碼 ∪ sitemap)==", flush=True)
    owner, stats = build_authoritative(cats)

    print("\n== 第三階段:商品頁 ==", flush=True)
    codes = list(owner)
    if args.limit:
        codes = codes[:args.limit]
    recs: list[dict] = []
    for i, c in enumerate(codes, 1):
        if state.is_done("p:" + c):
            continue
        rec = fetch_product(c)
        if rec is None:
            # ★ 解析失敗要寫 review 檔,不要靜默丟棄 ——
            #   沒寫進 master 的碼,每日排程會天天重抓、天天略過。
            review.write(f"{c}\t解析失敗(無書名或抓取失敗)\n")
            review.flush()
            state.mark_done("p:" + c)
            continue
        apply_cat_ids(rec, owner.get(c, []), cats)
        recs.append(rec)
        writer.write(rec)
        state.mark_done("p:" + c)
        if i % 100 == 0:
            print(f"  {i}/{len(codes)}", flush=True)
    review.close()

    print(f"\n完成:本次解析 {len(recs)} 件(檔案累計 {len(writer.seen)})", flush=True)
    print(f"權威清單 {len(owner)} 種 / 分類掛載 {stats['occurrences']} 本次 / "
          f"sitemap 孤兒 {len(stats['orphans'])} 筆", flush=True)
    print("★ 這些是工具自印的數字,只代表爬蟲看到什麼。"
          "匯入後一律回查資料庫對帳,並抽查幾本書名確認「新書」真的是新書。", flush=True)

    # 後處理要吃「全檔」而不是「本次解析的那些」—— 續跑時記憶體裡只有這一輪的,
    # 重複建檔的另一半可能是上一輪寫進去的。所以固定讀檔。
    postprocess_file(DATA / "bappress_books.jsonl")
    recs = [json.loads(l) for l in (DATA / "bappress_books.jsonl").read_text(encoding="utf-8").splitlines() if l.strip()]
    audit(recs)


if __name__ == "__main__":
    main()
