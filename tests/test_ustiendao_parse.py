"""天道北美（ustiendao）書頁解析與收錄規則（Asana 1220373285067379）
用法：crawler/venv/bin/python tests/test_ustiendao_parse.py   全過 exit 0
不連網：用 11/10 實抓書頁的結構（JSON-LD Product）做的幾種樣本。"""
import sys, pathlib
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent / "crawler"))
from ustiendao_crawler import parse_product, publisher_zh, valid_isbn13

fail = 0
def check(what, got, want):
    global fail
    ok = got == want
    print(("  ✓ " if ok else "  ✗ ") + what + ("" if ok else f"：得到 {got!r}，應為 {want!r}"))
    fail += not ok

def page(gtin, name, og=None, brand="宣道 China Alliance Press", desc="陶恕 (A. W. Tozer) 著<br />\r\n<br />\r\n本書針對的，是教會。", avail="https://schema.org/InStock"):
    # 站方 JSON-LD：description 裡夾真的 \r\n（標準 JSON 不允許），價格是字串
    ld = ('[ {"@context": "http://schema.org", "@type": "BreadcrumbList", "itemListElement": []},\n'
          ' {"@context": "http://schema.org", "@type": "Product", "name": "' + name + '", "url": "https://www.ustiendao.com/x.html",\n'
          '  "brand": "' + brand + '",    "description": "' + desc + '",\n'
          '  "image": "https://cdn.shoplightspeed.com/x.jpg", "gtin13": "' + gtin + '", "mpn": "陶恕", "sku": "' + gtin + '",\n'
          '  "offers": {"@type": "Offer", "price": "17.95", "priceCurrency": "USD" , "availability": "' + avail + '"} } ]')
    og_tag = f'<meta property="og:title" content="{og}"/>' if og else ''
    return f'<html><head><title>{name}</title>{og_tag}<script type="application/ld+json">{ld}</script></head></html>'

U = "https://www.ustiendao.com/x.html"
print("收錄規則")
rec, why = parse_product(page("9789622449640", "宣道 China Alliance Press 陶恕系列02：超然的經歷", og="陶恕系列02：超然的經歷"), U)
check("合法 ISBN、中文書名 → 收", why, "ok")
check("description 夾真的換行也讀得到（strict=False）", rec and rec["summary"], "陶恕 (A. W. Tozer) 著\n\n本書針對的，是教會。")
check("書名用 og:title（不黏出版社）", rec and rec["title"], "陶恕系列02：超然的經歷")
check("作者取 mpn", rec and rec["authors_raw"], "陶恕")
check("出版社只留中文", rec and rec["publisher"], "宣道")
check("出版社原值另存", rec and rec["publisher_raw"], "宣道 China Alliance Press")
check("價格、幣別", rec and (rec["price_list"], rec["currency"]), ("17.95", "USD"))
check("availability 只留最後一段", rec and rec["availability"], "InStock")
check("product_id 取網址代號", rec and rec["product_id"], "x")
check("禮品（EAN 不是 978／979）→ 不收", parse_product(page("6006937092112", "主日學書籤套裝"), U)[1], "not_isbn")
check("ISBN 檢查碼不對 → 不收", parse_product(page("9789622449641", "陶恕系列02"), U)[1], "not_isbn")
check("gtin 空的 → 不收", parse_product(page("", "某某紀念品"), U)[1], "not_isbn")
check("英文書（書名沒有中文）→ 不收", parse_product(page("9781400338689", "The Love and Respect Devotional"), U)[1], "english")
check("中英並列書名 → 收", parse_product(page("9789575564759", "慕安得烈每日靈修01：交通的訣要（中英對照） The Secret to Fellowship"), U)[1], "ok")
r2 = parse_product(page("9789575561635", "抽絲剝繭話信仰 When Reason Fails"), U)[0]
check("中英並列 → 書名只留中文", r2["title"], "抽絲剝繭話信仰")
check("中英並列 → 英文進 title_en", r2["title_en"], "When Reason Fails")
check("原書名另存 title_raw", r2["title_raw"], "抽絲剝繭話信仰 When Reason Fails")

print("書名拆分")
from ustiendao_crawler import split_title
check("「中文 | English」", split_title("異邦中的屬靈人：路得與但以理（十二課歸納查經材料 ） | Ruth & Daniel: God's People"),
      ("異邦中的屬靈人：路得與但以理（十二課歸納查經材料 ）", "Ruth & Daniel: God's People"))
check("後面接數字不切", split_title("時代的星火 01"), ("時代的星火 01", None))
check("英文後面還有中文 → 往後找", split_title("陶恕系列 A 版：受教的心 God Tells The Man"), ("陶恕系列 A 版：受教的心", "God Tells The Man"))
check("沒有英文 → 不切", split_title("陶恕系列 05：受教的心"), ("陶恕系列 05：受教的心", None))
check("英文在前、中文在後 → 不切", split_title("Kosmos 紅包祝福卡"), ("Kosmos 紅包祝福卡", None))
check("沒有 JSON-LD → 不收、原因 no_ld", parse_product("<html><title>x</title></html>", U)[1], "no_ld")
check("Cloudflare 驗證頁 → challenge（呼叫端要停）", parse_product("<html><head><title>Just a moment...</title></head></html>", U)[1], "challenge")
check("沒有 availability → None", parse_product(page("9789622449640", "陶恕系列02", avail=""), U)[0]["availability"], None)

print("出版社")
check("中英並列", publisher_zh("建道神學院 Alliance Bible Seminary"), "建道神學院")
check("地區標記 (TW)", publisher_zh("歸主 (TW)"), "歸主")
check("只有中文", publisher_zh("台灣教會公報社"), "台灣教會公報社")
check("只有英文 → 原樣", publisher_zh("Thomas Nelson Publishers"), "Thomas Nelson Publishers")
check("HTML 實體", publisher_zh("天道 &amp; Tien Dao"), "天道")
check("空 → None", publisher_zh(None), None)

print("ISBN")
check("978 合法", valid_isbn13("9789881421272"), True)
check("979 合法", valid_isbn13("9798887690605"), True)
check("12 碼", valid_isbn13("978988142127"), False)
check("非 978／979 開頭的 EAN", valid_isbn13("4713008131138"), False)

print("sitemap")
xml = ("<loc>https://www.ustiendao.com/.html</loc><loc>https://www.ustiendao.com/1889-1951.html</loc>"
       "<loc>https://www.ustiendao.com/brands/x/</loc><loc>https://www.ustiendao.com/tags/a.html</loc>")
import ustiendao_crawler as uc
class S: pass
uc.polite_fetch = lambda *a, **k: xml
check("「/.html」（沒有代號）與子目錄不算書頁", uc.sitemap_urls(S()), ["https://www.ustiendao.com/1889-1951.html"])
sys.exit(1 if fail else 0)
