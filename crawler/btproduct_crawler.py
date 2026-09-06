# -*- coding: utf-8 -*-
r"""突破機構(btproduct,香港)爬蟲 —— 第十六來源,v1.12.0。

用法:
  venv/bin/python btproduct_crawler.py --probe            # 偵察:選單 + 分頁保護實測 + 3 個樣本
  venv/bin/python btproduct_crawler.py --sample 15        # 抽樣 N 本(等距跨主題),不寫 master
  venv/bin/python btproduct_crawler.py --limit 30         # 試跑 30 件(寫 master)
  venv/bin/python btproduct_crawler.py --reconcile        # 只對帳,不抓商品頁
  nohup venv/bin/python -u btproduct_crawler.py > logs/btproduct.log 2>&1 &   # 全量(可續跑)

★ 本檔 docstring 加了 r 前綴(raw string),因為內文有 \s 這類 regex 片段;
  不加 r 前綴 Python 3.12 會報 SyntaxWarning: invalid escape sequence(9/5 probe 已出現)。
  同理,docstring 內文不可再出現三連引號,否則會提前結束字串。

━━━ 本站三項決議(熊哥 2026-09-05 裁示)━━━
1. 抓取範圍:只取 /tc/book/。禮品(/tc/gift/)整區不抓 —— 兩者目錄分流乾淨,
   不需要靠品名判斷非書。
2. 來源分類:原始來源分類**原樣保留**(subjects scheme='btproduct'),
   另建對映表併入站上自身分類表。爬蟲只負責存原值,不做任何分類判斷。
3. 購書連結:本站**沒有購物車**(buy.php 只是批發聯絡資訊),不產生購書連結;
   商品頁網址以一般連結放在書籍資訊區 → import 端對 btproduct 寫 link_type='official'。

━━━ ★★★ 權威清單:空關鍵字查詢(9/5 第三輪才找到)━━━
`product_list.php?product_key=`(關鍵字留空)走完分頁 = **全站書籍 583 本**。
票上原本擔心「product_list.php 是關鍵字查詢式,找不到全站入口」—— 就是這個。

第二本帳 `rss_book.php?lang=tc` 也是 **583**,兩本互相獨立卻一致 → 可信。

而 cat + ser 兩軸聯集只有 **575**,單向漏 8 本(聯集完全被 583 包含,反向差集為 0):

    19136735 無法預知的遠方
    36133061 Breakazine!050 - 仆直
    45123198 總有一次失戀 (斷版)
    34111568 父親卓越成就──養育子女與自我成長的雙贏指引
    37124564 思維的眼睛──開闊思考的視野
    36141697 11111     ← 疑似站方測試資料
    39141996 2020      ← 疑似站方測試資料
    71141546 11111     ← 疑似站方測試資料

這 8 本沒有掛任何分類或系列,靠走訪 cat/ser 永遠抓不到,而且 log 會全綠 ——
正是基道七月漏 22.8% 的同一個形狀(以為清單就是全部)。

→ **抓取清單一律以 product_key= 空查詢為準**;cat/ser 走訪降級為對帳手段
  (`--reconcile`,或全量時自動比一次),差集不為 0 就告警。
→ 本站沒有 sitemap.xml,所以「權威清單 vs cat/ser 聯集」是唯一的自我檢查,不要拿掉。

━━━ ★★ 分頁超界 ━━━
分頁參數是 `page_num`(page / p / offset / start 全部無效,傳了會靜默回第 1 頁)。
每頁 **21 筆**(不是 20)。

**超界時不是回空頁,也不是回該分類第 1 頁,而是整個篩選條件失效,
退化成「全站最新書籍」21 筆,而且照樣標「第1頁」、照樣帶「下一頁」。**
9/5 主機 probe 實測(cat=577136,實際 3 頁 56 本):

    page_num=2    標記=第2頁   21 筆
    page_num=3    標記=第3頁   14 筆   ← 最後一頁,無「下一頁」
    page_num=4    標記=第1頁   21 筆   ← ★超界退化
    page_num=999  標記=第1頁   21 筆   ← ★超界退化

→ 若用「抓到滿 21 筆就續抓」判斷,會**無限迴圈 + 把別類的書灌進這一類**。
→ 終止條件必須雙保險,兩個都要:
     ① 頁面上有「下一頁」連結才續抓
     ② 回來的「第N頁」標記必須等於這次請求的頁碼
   最容易誤判的個案是「剛好 21 本、只有 1 頁」的分類(實測:系列/栽培新一代 21 本),
   雙保險擋得住;只做其中一項都會漏。

━━━ ★ 分類與系列是「多值」欄位 ━━━
商品頁的「分類」「系列」可以是多值,以 `,` 分隔:

    "分類": "心理/成長自助 , 教育/青少年教育 , 宗教"
    "系列": "心靈關顧 , 栽培新一代 , 生命故事 , 成長"

首版把整串當成單一分類碼,違反「資料多重呈現鐵律」,而且會讓 subjects.code
存進一整串中文、對映表永遠比不到。現已拆成多筆。

分類碼**用選單上的數字 id**(cat=418988 → 宗教),不用中文當 code
(必查陷阱:分類代碼不可用中文 slug)。對照表讀 /tc/book/ 首頁選單就有;
商品頁出現、但選單裡沒有的分類名,會印警告並暫以中文為 code,不靜默。
★★ **無分類的 19 本是站方沒給,不是解析失敗**(9/5 逐本查證,見下節)。

━━━ 其他實測要點 ━━━
- 純伺服器渲染,沒有 document.write(不會重演基道 8/29 的欄位全失效)
- 商品頁欄位是**兩欄表格**:label 格內只有「書名 :」,值在下一個 td。
  用行尾式 regex 抓會全部抓到空字串 —— 9/5 已實測踩過,所以一律走 td 配對。
- 產品編號是 ISBN13 換一種斷字(978-988884-6276),條碼是無連字號的 ISBN13。
  三者同源,不要在跨站合併時當成三個獨立證據。
- ★ ISBN 欄格式不統一,實測至少四種:978-988-8846-27-6 / 9789888562817 /
  962-8913-31-X(ISBN10)/ 978-962899-6322(產品編號式斷字)。
  **一律驗檢查碼**,驗不過的不寫 isbn、只存 isbn_invalid ——
  天道 9/2 的教訓:錯一碼的 ISBN 撞到別站正確 ISBN,會把兩本不同的書合併成同一個 Work。
- 出版日期是 YYYY年MM月DD日,**站方已補零**,不必像基道那樣補。
- 封面走 /get_photo/?i=product_book/photo/<md5>.jpg&l=<邊長>&m=w;
  頁面用 l=110 縮圖,實測 l=1000&m=w 可取到 85 KB 大圖 → 轉存 R2 用大圖。
  直接抓 /upload/product_book/photo/<md5>.jpg 抓不到,一律走 get_photo。
- 香港站,原文是繁體 → 用 t2tw,**不可用 s2tw**(會把「后位→後位」改壞)。
- ★ 書名裡混有庫存標記:「(斷貨)」「(斷版)」「(少量)」「(極少)」。**不清洗書名**
  (鐵律:絕不做破壞原值的清洗),另記 availability;9/5 抽樣實測 4/15 = 27%,
  推估全站約 150 本 —— 這會影響 import 的 fuzzy_key 跨站合併,待熊哥決定是否在 import 端處理。
- ★ 疑似站方測試資料(書名純數字如「11111」「2020」)寫入 data/btproduct_suspect.tsv
  並標記 suspect_junk,**不靜默丟棄**(基道追加待辦 C 的教訓:丟掉就每天重抓一次)。

━━━ ★★ 無分類的 19 本:更正一個錯誤推論(9/5)━━━
全量後統計「無分類 19 本」而對帳孤兒只有 8 本,我一度推論「差的 11 本有清單歸屬、
只是商品頁分類欄空白」,並據此寫了清單歸屬 fallback。**逐本查證後證實這個推論是錯的**:

    19 本的 cat 歸屬**全部是空的**。清單歸屬補不到任何一本的主題分類。

實際組成:
  ① 11 本有**系列**歸屬(心理自助 8、心靈關顧 1、生命故事 1、通識閱讀 2),
     而且商品頁「系列」欄本來就有值 —— 所以連 series 的 fallback 也不會觸發。
  ② 8 本 cat/ser 全無(= 對帳孤兒),其中 3 本是站方測試資料。

→ **爬蟲端沒有辦法補這 19 本的主題分類,這是站方資料本身的缺口,不是抓取問題。**
  分類缺口在後面兩關處理:
    - 那 11 本:`apply_btproduct_categories.php` 的對映表**除了 cat 也要吃 ser**
      (系列「心理自助」→ 站上心理類),否則它們會落到關鍵字猜測
    - 那 8 本:交給 `classify_categories.php` 關鍵字 fallback;3 本測試資料不該上架
→ 清單歸屬 fallback **保留**(無害,站方日後補分類就會生效),但不要再期待它救回什麼。
  教訓:**「兩個數字兜不攏」推出來的解釋,要逐本查證過才能寫進程式碼註解。**
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urljoin

from bs4 import BeautifulSoup

from common import JsonlWriter, make_session, polite_fetch

# 版本戳記:每次改動就往上加,啟動時印出來。
# (9/1 為了確認「主機上跑的到底是哪一版」翻了半天輸出差異 —— 一行戳記就能省掉考古。)
CRAWLER_REV = ("2026-09-05f  系列也寫進 categories 存證(code 前綴 S),讓對映表能吃兩軸 —— "
               "那 11 本只有系列的書才有機會歸類")

BASE = "https://www.btproduct.com"
BOOK = BASE + "/tc/book/"
ALL_LIST = BOOK + "product_list.php?product_key="   # ★ 權威清單:關鍵字留空 = 全站書籍
NEW_LIST = BOOK + "product_list.php?type=new"        # 每日快路徑:最新上架(按上架日倒序)
NEW_PAGES = 2        # 每日走前 2 頁(42 件)。1 頁會漏「出版日新但上架日較早」的邊緣書
HERE = Path(__file__).parent
CACHE = HERE / "cache" / "btproduct"
DATA = HERE / "data"
THROTTLE = (1.5, 2.5)
PER_PAGE = 21          # 實測每頁 21 筆(不是 20)
MAX_PAGES = 80         # 迴圈保險絲;權威清單 583 本 = 28 頁
COVER_SIZE = 1000      # get_photo 的 l 參數;實測 1000 可取大圖

session = make_session()

# ── 繁簡:香港站原文即繁體,只做字形正規化(t2tw),不可用 s2tw ──
try:
    from opencc import OpenCC
    _CC_HANT = OpenCC("t2tw")
except ImportError:
    _CC_HANT = None


def zh_norm(s: str | None) -> str | None:
    return _CC_HANT.convert(s) if (s and _CC_HANT is not None) else s


# ── 欄位對照:商品頁 label → 記錄欄位名(對齊 tools/import.php map_record)──
FIELD_MAP = {
    "書名":     "title",
    "作者":     "authors_raw",
    "ISBN":     "_isbn_raw",
    "出版日期": "_pubdate_raw",
    "定價":     "_price_raw",
    "頁數":     "page_count",
    "尺寸":     "dimensions",
    "產品編號": "item_no",      # → identifiers(STORE)
    "條碼":     "ean_upc",      # 與 ISBN13 同源,存 extra 不另立證據
    "分類":     "_cat_text",
    "系列":     "_ser_text",
}
LABEL_RE = re.compile(r"^(" + "|".join(map(re.escape, FIELD_MAP)) + r")\s*[:：]\s*$")
MONEY_RE = re.compile(r"([\d,]+(?:\.\d+)?)")
DATE_RE = re.compile(r"(\d{4})\D{0,3}(\d{1,2})?\D{0,3}(\d{1,2})?")
MULTI_SEP_RE = re.compile(r"\s*[,，、]\s*")      # 分類/系列多值分隔
STOCK_RE = re.compile(r"[（(]\s*(斷貨|斷版|缺貨|少量|極少|絕版)[^）)]*[）)]")
JUNK_TITLE_RE = re.compile(r"^[\d\s\-_.]{1,12}$")   # 「11111」「2020」這種站方測試資料


def fetch(url: str, force: bool = False) -> str:
    return polite_fetch(session, url, CACHE, throttle=THROTTLE, force=force)


def _clean(s: str | None) -> str | None:
    if s is None:
        return None
    s = re.sub(r"\s+", " ", s).strip()
    return s or None


def _split_multi(s: str | None) -> list[str]:
    """分類/系列多值拆分。原值另外整串保留,這裡只負責拆。"""
    if not s:
        return []
    return [p for p in (x.strip() for x in MULTI_SEP_RE.split(s)) if p]


def _iso_date(raw: str | None) -> str | None:
    """2026年07月15日 → 2026-07-15。站方已補零,這裡仍照補以防個別漏填。"""
    if not raw:
        return None
    m = DATE_RE.search(raw)
    if not m:
        return None
    out = m.group(1)
    if m.group(2):
        out += f"-{int(m.group(2)):02d}"
        if m.group(3):
            out += f"-{int(m.group(3)):02d}"
    return out


# ── ISBN:一律驗檢查碼(天道 9/2 教訓,見檔頭)──

def _isbn13_ok(s: str) -> bool:
    if len(s) != 13 or not s.isdigit():
        return False
    return sum((1 if i % 2 == 0 else 3) * int(c) for i, c in enumerate(s)) % 10 == 0


def _isbn10_ok(s: str) -> bool:
    if len(s) != 10 or not re.fullmatch(r"\d{9}[\dXx]", s):
        return False
    return sum((10 - i) * (10 if c in "Xx" else int(c)) for i, c in enumerate(s)) % 11 == 0


def _isbn10_to13(s: str) -> str:
    core = "978" + s[:9]
    chk = (10 - sum((1 if i % 2 == 0 else 3) * int(c) for i, c in enumerate(core)) % 10) % 10
    return core + str(chk)


def resolve_isbn(raw: str | None) -> tuple[str | None, str | None]:
    """回傳 (可用的 ISBN, 存證用的原值)。驗不過一律不寫號,只存證。"""
    if not raw:
        return None, None
    cand = re.sub(r"[^0-9Xx]", "", raw)
    if _isbn13_ok(cand):
        return cand, None
    if _isbn10_ok(cand):
        # ISBN10 → 13 由檢查碼重算(不是直接加 978,基道那次就是沒重算)
        return _isbn10_to13(cand.upper()), raw
    return None, raw


# ═══════════════════ 清單 ═══════════════════

def _page_marker(soup: BeautifulSoup) -> int | None:
    """取頁尾「第N頁」的 N。沒有標記(0 筆頁)回 None。"""
    m = re.search(r"第\s*(\d+)\s*頁", soup.get_text(" ", strip=True))
    return int(m.group(1)) if m else None


def _has_next(soup: BeautifulSoup) -> bool:
    return any("下一頁" in (a.get_text() or "") for a in soup.select("a"))


def _ids_on_page(soup: BeautifulSoup) -> list[str]:
    """只取表格內的商品連結;側欄「最新書籍」在 div 裡,不能算進來。"""
    out, seen = [], set()
    for a in soup.select("table a[href]"):
        m = re.search(r"product\.php\?id=(\d+)", a.get("href") or "")
        if m and m.group(1) not in seen:
            seen.add(m.group(1))
            out.append(m.group(1))
    return out


def walk_list(base_url: str, label: str, force: bool = False, quiet: bool = False,
              max_pages: int = MAX_PAGES, expect_truncate: bool = False) -> list[str]:
    """走完一個清單的所有分頁,回傳去重後的商品 id。

    ★ 雙保險終止(缺一不可,理由見檔頭):
        ① 沒有「下一頁」→ 停
        ② 回來的「第N頁」標記 != 請求頁碼 → 這是超界退化成全站新書,**整頁丟棄**再停
    """
    sep = "&" if "?" in base_url else "?"
    ids: list[str] = []
    seen: set[str] = set()
    page = 1
    while page <= max_pages:
        url = base_url + (f"{sep}page_num={page}" if page > 1 else "")
        soup = BeautifulSoup(fetch(url, force=force), "lxml")
        marker = _page_marker(soup)

        if marker is None:
            if page == 1 and not quiet:
                print(f"  [空清單] {label} —— 站方回 0 項,不是抓取失敗", flush=True)
            break
        if marker != page:
            print(f"  [超界保護] {label} 請求第 {page} 頁,站方回「第 {marker} 頁」"
                  f" → 丟棄本頁並停止(本站已知行為,不是錯誤)", flush=True)
            break

        page_ids = _ids_on_page(soup)
        new = [i for i in page_ids if i not in seen]
        seen.update(new)
        ids.extend(new)

        if not _has_next(soup):
            break
        if len(page_ids) < PER_PAGE:
            print(f"  [警告] {label} 第 {page} 頁只有 {len(page_ids)} 筆(每頁應 {PER_PAGE})"
                  f"卻仍有下一頁,已停止,請人工複核", flush=True)
            break
        page += 1
    if page > max_pages and not expect_truncate:
        print(f"  [警告] {label} 走到頁數上限 {max_pages} 頁仍未結束,請人工複核", flush=True)
    return ids


def list_all_books(force: bool = False) -> list[str]:
    """★ 權威清單:product_list.php?product_key=(關鍵字留空)= 全站書籍。"""
    ids = walk_list(ALL_LIST, "全站書籍(權威清單)", force=force)
    print(f"權威清單:{len(ids)} 本", flush=True)
    return ids


def list_new(pages: int = NEW_PAGES, force: bool = True) -> list[str]:
    """每日快路徑:最新上架清單前 N 頁。

    ★ 列表頁一律 force —— 基道 8/27 的事故就是列表頁讀到永久快取,
      六週零新書而 log 全綠。

    9/5 拿 583 本 master 離線檢定 type=new 第 1 頁(21 件)的涵蓋率:
        出版日最新 10 本 → 10/10  (100.0%)
        出版日最新 21 本 → 19/21  ( 90.5%)
      且**第 1 頁沒有任何一本落在「最新 60 本」之外** —— 與天道的 105 完全不同
      (105 只有 64 件卻橫跨 1999-2026,是站方手動推薦位)。
      本站 type=new 是按**上架日**排序的自動清單,可信。

    那 2 本沒命中的是「出版日新、上架日較早」,對每日增量無害(上架當天就會在第 1 頁),
    但為了邊界安全走前 2 頁(42 件)。成本 2 次請求。
    """
    ids = walk_list(NEW_LIST, f"最新上架(前 {pages} 頁)", force=force,
                    max_pages=pages, expect_truncate=True)
    print(f"最新上架清單:{len(ids)} 件", flush=True)
    return ids


def list_menus(force: bool = False) -> tuple[list[dict], list[dict]]:
    """從 /tc/book/ 首頁取分類/系列選單,只為了建「中文名 → 數字 id」對照。"""
    soup = BeautifulSoup(fetch(BOOK, force=force), "lxml")
    cats, sers, seen = [], [], set()
    for a in soup.select("a[href]"):
        href = a.get("href") or ""
        m = re.search(r"product_list\.php\?(cat|ser)=(\d+)", href)
        if not m:
            continue
        kind, cid = m.group(1), m.group(2)
        if (kind, cid) in seen:
            continue
        seen.add((kind, cid))
        row = {"key": kind, "id": cid, "label": zh_norm(a.get_text(strip=True)) or cid}
        (cats if kind == "cat" else sers).append(row)
    return cats, sers


def collect_memberships(cats: list[dict] | None = None, sers: list[dict] | None = None,
                        force: bool = False) -> tuple[dict[str, list[dict]], list[str]]:
    """走 cat/ser 全部清單,記下**每本書出現在哪些清單**。回傳 (members, 空清單清單)。

    主要用途是對帳(權威清單 vs cat/ser 聯集);歸屬本身給 parse_product 當
    「商品頁分類/系列欄空白時」的 fallback。

    ★ 但實測 fallback 目前救不到任何一本:583 本裡無分類的 19 本,cat 歸屬全部是空的
      (詳見檔頭「無分類的 19 本」)。fallback 保留是為了站方日後補分類,
      不要拿它當「分類缺口已解決」的理由。
    """
    if cats is None or sers is None:
        cats, sers = list_menus(force=force)
    members: dict[str, list[dict]] = {}
    empty: list[str] = []
    print(f"[清單走訪] 主題分類 {len(cats)} 類、系列分類 {len(sers)} 類…", flush=True)
    for rows, kind in ((cats, "cat"), (sers, "ser")):
        for r in rows:
            got = walk_list(f"{BOOK}product_list.php?{kind}={r['id']}",
                            f"{kind}={r['id']} {r['label']}", force=force, quiet=True)
            if not got:
                empty.append(f"{kind}={r['id']} {r['label']}")
            for pid in got:
                members.setdefault(pid, []).append(
                    {"kind": kind, "code": r["id"], "label": r["label"]})
    return members, empty


def report_reconcile(authoritative: list[str], members: dict[str, list[dict]],
                     empty: list[str]) -> set[str]:
    """對帳:cat/ser 兩軸聯集 vs 權威清單。本站沒有 sitemap,這是唯一的自我檢查。"""
    auth = set(authoritative)
    union = set(members)
    only_auth = auth - union
    only_union = union - auth
    print(f"[對帳] 權威清單 {len(auth)}、cat/ser 聯集 {len(union)}", flush=True)
    if empty:
        print(f"[對帳] 空清單 {len(empty)} 個(站方本來就 0 項,非失敗):{', '.join(empty)}", flush=True)
    if only_auth:
        print(f"[對帳] ★ 權威清單有、分類走訪抓不到的 {len(only_auth)} 本"
              f"(沒掛任何分類/系列的孤兒書,正常):{', '.join(sorted(only_auth))}", flush=True)
    if only_union:
        print(f"[對帳] ★★ 分類走訪有、權威清單沒有的 {len(only_union)} 本 —— "
              f"這不該發生,請人工複核:{', '.join(sorted(only_union))}", flush=True)
    if not only_auth and not only_union:
        print("[對帳] 兩本帳完全一致。", flush=True)
    return only_auth


# ═══════════════════ 商品頁 ═══════════════════

def product_url(pid: str) -> str:
    return f"{BOOK}product.php?id={pid}"


def _cover_url(soup: BeautifulSoup) -> str | None:
    """封面走 get_photo;把頁面上的縮圖 l=110 換成大圖 l=COVER_SIZE。"""
    for img in soup.select("img[src]"):
        src = img.get("src") or ""
        if "get_photo" not in src or "product_book/photo/" not in src:
            continue
        full = urljoin(BASE, src)
        full = re.sub(r"([?&]l=)\d+", rf"\g<1>{COVER_SIZE}", full)
        if "l=" not in full:
            full += f"&l={COVER_SIZE}"
        return re.sub(r"([?&]m=)h", r"\g<1>w", full)
    return None


_unmapped: dict[str, int] = {}     # 商品頁有、選單沒有的分類/系列名 → 出現次數
_suspect: list[tuple[str, str]] = []   # 疑似站方測試資料 (pid, title)


def parse_product(pid: str, cat_map: dict | None = None, ser_map: dict | None = None,
                  force: bool = False, members: list[dict] | None = None) -> dict | None:
    """★ 走 td 配對,不用文字 regex(理由見檔頭)。"""
    cat_map = cat_map or {}
    ser_map = ser_map or {}
    url = product_url(pid)
    try:
        html = fetch(url, force=force)
    except RuntimeError as e:
        print(f"  [跳過 {pid},下次重跑補抓] {e}", flush=True)
        return None
    soup = BeautifulSoup(html, "lxml")

    raw: dict[str, str] = {}
    for td in soup.select("td"):
        label = LABEL_RE.match(td.get_text(" ", strip=True) or "")
        if not label:
            continue
        val_td = td.find_next("td")
        if val_td is None:
            continue
        val = _clean(val_td.get_text(" ", strip=True))
        # 值格若剛好是下一個 label(該欄為空)→ 視為空,不要吃掉下一欄的標籤
        if val and LABEL_RE.match(val):
            val = None
        if val:
            raw.setdefault(label.group(1), val)

    title = zh_norm(raw.get("書名"))
    if not title:
        print(f"  [略過 {pid}] 找不到書名", flush=True)
        return None

    rec: dict = {
        "pid": pid,
        "source": "btproduct",
        "source_url": url,
        "currency": "HKD",
        "is_ebook": False,
        "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "spec_all": raw,                 # 整表存證(多重呈現原則:映射不了的一律留原值)
    }

    for label, field in FIELD_MAP.items():
        val = raw.get(label)
        if not val:
            continue
        rec[field] = zh_norm(val) if field in ("title", "authors_raw") else val

    # 疑似站方測試資料:標記但**不丟棄**(丟掉就每天重抓一次,基道追加待辦 C 的教訓)
    if JUNK_TITLE_RE.match(title) and not raw.get("ISBN"):
        rec["suspect_junk"] = True
        _suspect.append((pid, title))

    # 書名裡的庫存標記:**不清洗書名**,只另記(鐵律:不做破壞原值的清洗)
    st = STOCK_RE.search(title)
    if st:
        rec["availability"] = st.group(1)
        rec["title_no_stock"] = _clean(STOCK_RE.sub("", title))   # 僅供比對參考,不覆蓋 title

    # ISBN:一律驗檢查碼,驗不過不寫號只存證
    isbn_raw = rec.pop("_isbn_raw", None)
    isbn, bad = resolve_isbn(isbn_raw)
    if isbn:
        rec["isbn"] = isbn
        if bad:
            rec["isbn_source_raw"] = bad     # 經 ISBN10→13 還原,留站方原值
    elif isbn_raw:
        rec["isbn_invalid"] = isbn_raw
        print(f"  [ISBN 檢查碼不合,不寫號只存證] {pid} {title[:20]} 「{isbn_raw}」", flush=True)

    # 出版日期
    if rec.pop("_pubdate_raw", None):
        rec["publish_date"] = _iso_date(raw.get("出版日期"))
        rec["publish_date_raw"] = raw.get("出版日期")
    # 定價:HK$ 118.00 → 118.00(幣別另記,不做匯率換算,決議 A)
    pr = rec.pop("_price_raw", None)
    if pr:
        m = MONEY_RE.search(pr)
        if m:
            rec["price_list"] = m.group(1).replace(",", "")
        rec["price_raw"] = pr
    # 頁數取數字
    if rec.get("page_count"):
        m = re.search(r"\d+", str(rec["page_count"]))
        rec["page_count"] = m.group(0) if m else None

    # ── 來源分類:多值拆分 + 用選單數字 id 當 code(決議 2)──
    # ★ 權威清單裡的孤兒書沒有分類,這裡會是空的 —— 正常,不是解析失敗。
    cat_text = rec.pop("_cat_text", None)
    if cat_text:
        cat_text = zh_norm(cat_text)
        rec["category_raw"] = cat_text                 # 整串原值存證
        cats = []
        for name in _split_multi(cat_text):
            code = cat_map.get(name)
            if code is None:
                _unmapped[f"cat:{name}"] = _unmapped.get(f"cat:{name}", 0) + 1
                code = name                            # 對不到就先用中文,但已計數告警
            cats.append({"code": code, "path": name})
        if cats:
            rec["categories"] = cats
            # 平面後備欄位只放第一個(import.php 有 categories 時不會用到)
            rec["category_source"] = cats[0]["code"]
            rec["category_text"] = cats[0]["path"]

    # ── 系列:多值。整串原樣保留給 books.series(鐵律:多值不拆),另存拆好的清單 ──
    #
    # ★ 系列也一併寫進 `categories`(code 前綴 S),理由:
    #   import.php 只把 `categories` 寫進 subjects(scheme='btproduct'),
    #   `series_list` 不會進資料庫 → 對映表就吃不到系列這一軸。
    #   而全站有 11 本**只有系列、沒有主題分類**(站方沒給),不走這條路它們就只能靠
    #   關鍵字猜測。前綴 S 是為了與 cat 的 6 位數字 id 分流,不會撞碼。
    #   排在 cat 之後,所以 primary 仍由主題分類決定(對映表另以 sort_order 保證)。
    ser_text = rec.pop("_ser_text", None)
    if ser_text:
        ser_text = zh_norm(ser_text)
        rec["series_text"] = ser_text                  # 原樣保留,不拆
        names = _split_multi(ser_text)
        if names:
            rec["series_list"] = [{"code": ser_map.get(n) or n, "name": n} for n in names]
            ser_subjects = []
            for n in names:
                if n not in ser_map:
                    _unmapped[f"ser:{n}"] = _unmapped.get(f"ser:{n}", 0) + 1
                ser_subjects.append({"code": "S" + (ser_map.get(n) or n),
                                     "path": "系列/" + n})
            rec["categories"] = (rec.get("categories") or []) + ser_subjects
            # 平面後備欄位:cat 優先,沒有 cat 時才落到系列
            if not rec.get("category_source"):
                rec["category_source"] = rec["categories"][0]["code"]
                rec["category_text"] = rec["categories"][0]["path"]

    # ── 商品頁分類/系列欄空白時,用清單歸屬補;標記 *_from_list 以區分資料來源。
    #    ★ 9/5 逐本查證:目前**一本都補不到**(無分類的 19 本 cat 歸屬全空,
    #      其中 11 本只有 ser 而商品頁系列欄本來就有值)。保留備用,不要當缺口已解。
    if members:
        if not rec.get("categories"):
            from_list = [{"code": m["code"], "path": m["label"]}
                         for m in members if m["kind"] == "cat"]
            if from_list:
                rec["categories"] = from_list
                rec["category_source"] = from_list[0]["code"]
                rec["category_text"] = from_list[0]["path"]
                rec["category_from_list"] = True
        if not rec.get("series_text"):
            ser_from_list = [m for m in members if m["kind"] == "ser"]
            if ser_from_list:
                rec["series_text"] = " , ".join(m["label"] for m in ser_from_list)
                rec["series_list"] = [{"code": m["code"], "name": m["label"]}
                                      for m in ser_from_list]
                rec["series_from_list"] = True

    # 簡介:「內容簡介 :」那一格的下一格
    for td in soup.select("td"):
        if re.match(r"^內容簡介\s*[:：]?\s*$", td.get_text(" ", strip=True) or ""):
            nxt = td.find_next("td")
            if nxt is not None:
                body = re.sub(r"\n{3,}", "\n\n", nxt.get_text("\n", strip=True))
                if body and len(body) > 20:
                    rec["summary"] = zh_norm(body)
            break

    cover = _cover_url(soup)
    if cover:
        rec["cover_url"] = cover

    return rec


def _flush_reports() -> None:
    if _unmapped:
        print("\n[警告] 商品頁出現、但選單沒有的分類/系列名(已暫以中文為 code,請補對照):", flush=True)
        for k, v in sorted(_unmapped.items(), key=lambda x: -x[1]):
            print(f"  {k}  ×{v}", flush=True)
    if _suspect:
        path = DATA / "btproduct_suspect.tsv"
        with path.open("w", encoding="utf-8") as f:
            f.write("pid\ttitle\turl\n")
            for pid, t in _suspect:
                f.write(f"{pid}\t{t}\t{product_url(pid)}\n")
        print(f"\n[複核] 疑似站方測試資料 {len(_suspect)} 筆已寫入 {path}"
              f"(仍有抓進 jsonl,標記 suspect_junk;匯入前請人工看過):", flush=True)
        for pid, t in _suspect:
            print(f"  {pid}  {t}", flush=True)


# ═══════════════════ CLI ═══════════════════

def do_probe() -> int:
    """偵察:印選單、實測分頁保護、抓 3 個樣本。不寫任何檔。"""
    cats, sers = list_menus()
    cat_map = {r["label"]: r["id"] for r in cats}
    ser_map = {r["label"]: r["id"] for r in sers}
    print(f"\n【選單】主題分類 {len(cats)}、系列分類 {len(sers)}")
    for r in cats[:5] + sers[:3]:
        print(f"  {r['key']}={r['id']}  {r['label']}")

    print("\n【分頁超界實測】cat=577136(流行讀物/勵志讀物,實測 3 頁 56 本)")
    p1 = BeautifulSoup(fetch(f"{BOOK}product_list.php?cat=577136"), "lxml")
    ids1 = _ids_on_page(p1)
    for pn in (2, 3, 4, 999):
        s = BeautifulSoup(fetch(f"{BOOK}product_list.php?cat=577136&page_num={pn}"), "lxml")
        ids = _ids_on_page(s)
        mk = _page_marker(s)
        same = "與第1頁相同" if ids[:3] == ids1[:3] else "與第1頁不同"
        flag = "  ← ★超界退化(整頁應丟棄)" if (mk is not None and mk != pn) else ""
        print(f"  page_num={pn:<4} 標記=第{mk}頁  {len(ids):>3} 筆  {same}{flag}")

    print("\n【每日快路徑】type=new")
    list_new()

    print("\n【權威清單】")
    auth = list_all_books()
    print("\n【對帳】")
    members, empty = collect_memberships(cats, sers)
    orphans = report_reconcile(auth, members, empty)

    print("\n【樣本 3 本】(優先挑對帳孤兒書,驗沒有分類時不會誤判成解析失敗)")
    picks = (sorted(orphans)[:2] + auth[:1]) if orphans else auth[:3]
    for pid in picks:
        rec = parse_product(pid, cat_map, ser_map, members=members.get(pid))
        if rec:
            print(json.dumps(rec, ensure_ascii=False, indent=2)[:1400])
            print("-" * 60)
    _flush_reports()
    return 0


def main() -> int:
    ap = argparse.ArgumentParser(description="突破機構(btproduct)爬蟲")
    ap.add_argument("--probe", action="store_true", help="偵察:選單 + 分頁保護 + 對帳 + 3 樣本")
    ap.add_argument("--reconcile", action="store_true", help="只對帳(權威清單 vs cat/ser 聯集),不抓商品頁")
    ap.add_argument("--sample", type=int, default=0, help="抽樣 N 本(等距),不寫 master")
    ap.add_argument("--limit", type=int, default=0, help="只抓 N 件(試跑,會寫 master)")
    ap.add_argument("--fast", action="store_true",
                    help="略過 cat/ser 走訪:不對帳,**也不會用清單歸屬補分類**(趕時間才用)")
    ap.add_argument("--force", action="store_true", help="繞過頁面快取重抓")
    args = ap.parse_args()

    print(f"btproduct_crawler {CRAWLER_REV}", flush=True)
    DATA.mkdir(parents=True, exist_ok=True)

    if args.probe:
        return do_probe()

    # ★ 一律以權威清單為抓取對象(cat/ser 聯集會漏掉沒掛分類的孤兒書)
    ids = list_all_books(force=args.force)
    if not ids:
        print("[錯誤] 權威清單抓到 0 本 —— 站方可能改版,請先人工複核,不要當成沒有新書。", flush=True)
        return 1

    cats, sers = list_menus(force=args.force)
    cat_map = {r["label"]: r["id"] for r in cats}
    ser_map = {r["label"]: r["id"] for r in sers}

    # ★ cat/ser 只走一次,同時供「補回商品頁空白的分類」與「對帳」兩用
    members: dict[str, list[dict]] = {}
    if not args.fast:
        members, empty = collect_memberships(cats, sers, force=args.force)
        report_reconcile(ids, members, empty)
    if args.reconcile:
        return 0

    if args.sample:
        step = max(1, len(ids) // args.sample)
        picks = ids[::step][:args.sample]
        ok = 0
        fields = ["title", "authors_raw", "isbn", "publish_date", "price_list",
                  "page_count", "dimensions", "item_no", "ean_upc",
                  "categories", "series_text", "summary", "cover_url"]
        fill = {f: 0 for f in fields}
        multi_cat = multi_ser = bad_isbn = stock = 0
        for pid in picks:
            rec = parse_product(pid, cat_map, ser_map, force=args.force,
                                members=members.get(pid))
            if not rec:
                continue
            ok += 1
            for f in fields:
                if rec.get(f):
                    fill[f] += 1
            if len(rec.get("categories") or []) > 1:
                multi_cat += 1
            if len(rec.get("series_list") or []) > 1:
                multi_ser += 1
            if rec.get("isbn_invalid"):
                bad_isbn += 1
            if rec.get("availability"):
                stock += 1
            codes = ",".join(c["code"] for c in (rec.get("categories") or [])) or "無分類"
            print(f"  {rec.get('title','')[:26]:<28} {rec.get('isbn','-'):<16}"
                  f" {rec.get('publish_date','-'):<12} [{codes}]", flush=True)
        print(f"\n抽樣 {ok}/{len(picks)} 本,欄位填充率:", flush=True)
        for f in fields:
            print(f"  {f:<16} {fill[f]:>3}/{ok}", flush=True)
        print(f"\n  多分類 {multi_cat} 本、多系列 {multi_ser} 本、"
              f"ISBN 驗不過 {bad_isbn} 本、書名帶庫存標記 {stock} 本", flush=True)
        _flush_reports()
        return 0

    writer = JsonlWriter(DATA / "btproduct_books.jsonl", key_field="pid")
    todo = [i for i in ids if not writer.has(i)]
    if args.limit:
        todo = todo[:args.limit]
    print(f"待抓 {len(todo)} 本(master 已有 {len(ids) - len(todo)} 本)", flush=True)

    done = fail = 0
    for n, pid in enumerate(todo, 1):
        rec = parse_product(pid, cat_map, ser_map, force=args.force,
                            members=members.get(pid))
        if rec is None:
            fail += 1
            continue
        writer.write(rec)
        done += 1
        if n % 50 == 0:
            print(f"  …{n}/{len(todo)}", flush=True)
    writer.close()

    _flush_reports()
    print(f"\n完成:本次寫入 {done} 本、解析失敗 {fail} 本、權威清單 {len(ids)} 本", flush=True)
    print(f"檔案:{DATA / 'btproduct_books.jsonl'}", flush=True)
    print("\n★ 下一步(不要忘了 import,8/28 那次「跑了爬蟲沒跑匯入」讓 32 本靜默消失兩天):", flush=True)
    print(f"   php tools/import.php --file={DATA / 'btproduct_books.jsonl'} "
          f"--source=btproduct --dry-run", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
