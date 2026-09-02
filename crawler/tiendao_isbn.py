# -*- coding: utf-8 -*-
"""天道 ISBN 三段式判定 + language 正規化 —— 爬蟲與離線修正工具**共用**的單一實作。

抽成獨立模組的理由:同一套規則若在 tiendao_crawler.py 與 fix_tiendao_isbn.py
各寫一份,兩邊必然隨時間漂移(改了一邊忘了另一邊),日後每日新品就會又開始
產生錯號,而全量資料看起來是對的 —— 這種不一致最難察覺。

═══ 為什麼需要三段式(2026-09-02 診斷,Asana 1217984797715279)═══

全量 1233 件中 63 筆 ISBN 檢查碼不合。diag 的判定分布:
  兩邊同值且都錯 54 / 兩邊都錯但不同值 5 / ISBN 欄正確 4

原本以為是「條碼欄打錯、ISBN 欄正確」,實測推翻——修 fallback 只救 4 筆。
真正的根因是**站方把舊書的 ISBN10 機械加上 `978` 前綴,沒有重算檢查碼**:

  9789622084435 → 去 978 得 9622084435,是**有效的 ISBN10**(mod-11 通過)
                → 正確 ISBN13 = 978 + 962208443 + 重算檢查碼 = 9789622084438

樣本 12 筆有 9 筆(75%)符合此型,且全為 962208(天道自家社號)。

**這是還原,不是造號**:ISBN10 的 mod-11 檢查碼保證了前 9 碼正確,而
ISBN10 → ISBN13 是 ISBN 標準定義的一對一轉換。所以只要 ISBN10 驗證通過,
還原出的 ISBN13 在數學上是唯一確定的。

反過來說,**若去 978 後不是有效 ISBN10,就不能還原** —— 那代表前 9 碼本身
可能也錯了,硬算檢查碼就成了憑空造號。這種一律不寫號、只存證。

═══ 衝突處理 ═══

條碼欄與 ISBN 欄各自可能產出一個「經驗證的 ISBN13」。兩者一致才採用;
不一致就標 conflict、不寫號,交人工判 —— 猜錯的代價是把兩本不同的書
合併成同一個 Work(import.php:359 只要 ISBN13 相同就合併),比漏收嚴重得多。

實測 pid 1557 的兩條路會**收斂到同一個答案**:
  條碼 9789888124726(錯)→ 去 978 得 9888124726(有效 ISBN10)→ 還原 9789888124725
  ISBN 欄 9789888124725(本來就對)
兩路一致 → 採用,這也反向驗證了還原規則的正確性。
"""
from __future__ import annotations

import re

ISBN13_FMT = re.compile(r"^97[89]\d{10}$")
ISBN10_FMT = re.compile(r"^\d{9}[\dXx]$")

# 語言欄正規化:站方錯字與隱形字元(2026-09-02 實測)
#   「瀪體中文」9 筆——「瀪」是「繁」的錯字
#   '繁體中文\n英文' 1 筆——含換行,會原樣寫進 VARCHAR
_LANG_TYPO = {"瀪": "繁"}


def isbn13_valid(s: str) -> bool:
    """ISBN13 格式 + 檢查碼(EAN-13 mod-10 加權 1,3)。"""
    if not ISBN13_FMT.match(s or ""):
        return False
    return _ck13(s[:12]) == s[12]


def _ck13(first12: str) -> str:
    t = sum(int(c) * (1 if i % 2 == 0 else 3) for i, c in enumerate(first12))
    return str((10 - t % 10) % 10)


def isbn10_valid(s: str) -> bool:
    """ISBN10 格式 + 檢查碼(mod-11 加權 10..1,末碼可為 X=10)。"""
    if not ISBN10_FMT.match(s or ""):
        return False
    tot = 0
    for i, c in enumerate(s):
        tot += (10 if c in "Xx" else int(c)) * (10 - i)
    return tot % 11 == 0


def isbn10_to_13(s10: str) -> str:
    """ISBN10 → ISBN13(標準轉換:978 + 前 9 碼 + 重算檢查碼)。

    與 tools/import.php:134 的 isbn10_to_13() 同一套規則,兩邊必須一致。"""
    core = "978" + s10[:9]
    return core + _ck13(core)


def normalize(raw: str | None) -> str:
    """去連字號、空白、全形空白;英文字母統一大寫(ISBN10 末碼 x → X)。"""
    if not raw:
        return ""
    return re.sub(r"[-\s　]", "", str(raw)).upper()


def resolve_one(raw: str | None) -> tuple[str | None, str | None]:
    """單一候選值 → (經驗證的 ISBN13, 判定理由)。無法驗證則回 (None, 理由)。

    三段:
      1. 本來就是有效 ISBN13        → 直接採用
      2. 是有效 ISBN10             → 標準轉換為 ISBN13
      3. 格式像 ISBN13 但檢查碼錯,
         且去 978 後是有效 ISBN10  → 還原(站方漏算檢查碼)
      其餘                          → 不採用
    """
    v = normalize(raw)
    if not v:
        return None, None
    if isbn13_valid(v):
        return v, "valid13"
    if isbn10_valid(v):
        return isbn10_to_13(v), "from10"
    # 長度 13 且 978/979 起頭 → 試「後 10 碼是不是有效 ISBN10」
    #
    # ★ 這裡刻意**不要求後 10 碼全為數字**。9/2 首版寫成 `if ISBN13_FMT.match(v)`
    #   (= 97[89]\d{10}),結果 9 筆 `978962208___X` 全被判 unrecognized 而漏救 ——
    #   那個 X 是 ISBN10 的檢查碼(X=10),站方把整個 ISBN10 連 X 一起接在 978 後面。
    #   實測 9/9 筆的 tail 都是有效 ISBN10,可完整還原。
    if len(v) == 13 and v[:3] in ("978", "979"):
        tail = v[3:]                      # 去掉 978/979 前綴的 10 碼
        if isbn10_valid(tail):
            return isbn10_to_13(tail), "restored"
        return None, "bad13_unrestorable"
    return None, "unrecognized"


def resolve(barcode: str | None, isbn_field: str | None) -> dict:
    """條碼欄 + ISBN 欄 → 判定結果。

    回傳 {isbn, status, detail}:
      status = ok / conflict / invalid / none
      ok       → isbn 可寫入 identifiers
      conflict → 兩欄各自驗證通過但答案不同,**不寫號**,交人工
      invalid  → 有原值但都無法驗證,**不寫號**,原值存 extra.isbn_invalid
      none     → 兩欄皆空
    """
    b13, breason = resolve_one(barcode)
    i13, ireason = resolve_one(isbn_field)
    raws = {"barcode": normalize(barcode) or None,
            "isbn_field": normalize(isbn_field) or None}

    cands = {x for x in (b13, i13) if x}
    if len(cands) == 1:
        isbn = cands.pop()
        # 兩條路都驗證通過且一致 → 信心最高
        both = b13 is not None and i13 is not None
        return {"isbn": isbn, "status": "ok",
                "detail": {"via_barcode": breason, "via_isbn_field": ireason,
                           "agreed": both, "raw": raws}}
    if len(cands) > 1:
        return {"isbn": None, "status": "conflict",
                "detail": {"candidates": sorted(cands),
                           "via_barcode": breason, "via_isbn_field": ireason,
                           "raw": raws}}
    if raws["barcode"] or raws["isbn_field"]:
        return {"isbn": None, "status": "invalid",
                "detail": {"via_barcode": breason, "via_isbn_field": ireason,
                           "raw": raws}}
    return {"isbn": None, "status": "none", "detail": {}}


def normalize_language(raw: str | None) -> tuple[str | None, str | None]:
    """language 主欄位正規化 → (正規化值, 若有改動則回原值否則 None)。

    站方原值依多重呈現鐵律留給呼叫端存 extra,本函式只負責產出乾淨的主欄位值:
      '繁體中文\\n英文' → '繁體中文／英文'(換行轉全形斜線,不用半型 / 避免與現有分隔符撞色)
      '瀪體中文'        → '繁體中文'(站方錯字)
    """
    if raw is None:
        return None, None
    orig = str(raw)
    v = re.sub(r"[\r\n\t]+", "／", orig)
    v = re.sub(r"[ 　]{2,}", " ", v).strip().strip("／")
    for bad, good in _LANG_TYPO.items():
        v = v.replace(bad, good)
    v = v or None
    return v, (orig if v != orig else None)


# ══ 已知站方誤標:同一 ISBN 掛在兩本不相干的書上 ══
#
# 刻意用「明確的 pid 清單」而不是「書名相似度門檻」:門檻設不好會誤傷正確的合併
# (「走出黑洞」與「走出黑洞　一個情緒病患者康復的經歷」本來就該併,
#  「耶利米書註釋卷上」與「天註:耶利米書(卷上)」也是),而且門檻無法被審計。
# 一筆一筆列、附查證依據,日後看得懂為什麼。
#
# 9789622087613(2026-09-02 查證):天道同時掛在
#   pid 1181 在軟弱中享安好(TD3430)  ← 正確擁有者
#   pid 1238 脫胎換骨得自由(TD2622)  ← 站方誤標
# 依據不是書名判斷,而是站上既有資料:`SELECT ... WHERE isbn13='9789622087613'`
# 回 book_id 66002「在軟弱中享安好」,由**基道(logos)獨立收錄**時帶入 ——
# 第三方來源佐證,不是我們自己的推論。
KNOWN_ISBN_MISLABELED = {
    "1238": ("9789622087613 經查證屬「在軟弱中享安好」"
             "(站上 book_id 66002,基道 logos 獨立收錄);本書為站方誤標"),
}


def mislabeled_reason(rec: dict) -> str | None:
    """已知站方誤標 → 回原因字串(呼叫端據此清空 isbn 並存證);否則 None。"""
    return KNOWN_ISBN_MISLABELED.get(str(rec.get("pid")))


def is_fake_isbn_leaflet(rec: dict) -> bool:
    """福音單張 TGP0xx —— 站方在 ISBN 欄貼了一整串連號,不是真書號。

    2026-09-02 實測:TGP001~TGP031 的 ISBN(9789622089013~9789622089310)
    與「天註/普天註釋」等正經書逐一撞號。若照原值匯入,import.php:359 會把
    HK$16 的福音單張與 HK$160 的天註書合併成同一個 Work,而且下架救不了
    (流程是 import → apply,ISBN 早已寫入 identifiers 並完成合併)。

    判定綁兩個條件(單獨任一都可能誤傷):貨號 TGP 前綴 + 掛在單張/小冊子分類。
    """
    item_no = str(rec.get("item_no") or "").upper()
    codes = {c.get("code") for c in (rec.get("categories") or [])}
    return item_no.startswith("TGP") and bool(codes & {"73_77", "77"})
