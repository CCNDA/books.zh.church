"""天道北美每週對照（改價、下架、有沒有貨）的比對規則（Asana 1220374760596931）
用法：crawler/venv/bin/python tests/test_ustiendao_recheck.py   全過 exit 0
不連網：假的快照、假的 sitemap、假的抓取結果。"""
import sys, pathlib
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent / "crawler"))
from ustiendao_recheck import compare, run

fail = 0
def check(what, got, want):
    global fail
    ok = got == want
    print(("  ✓ " if ok else "  ✗ ") + what + ("" if ok else f"：得到 {got!r}，應為 {want!r}"))
    fail += not ok

def page(gtin="9789622449640", price="17.95", avail="https://schema.org/InStock"):
    ld = ('[ {"@context": "http://schema.org", "@type": "Product", "name": "陶恕系列02", "brand": "宣道 China Alliance Press",'
          ' "gtin13": "' + gtin + '", "mpn": "陶恕", "offers": {"@type": "Offer", "price": "' + price + '", "priceCurrency": "USD", "availability": "' + avail + '"} } ]')
    return f'<html><head><meta property="og:title" content="陶恕系列02"/><script type="application/ld+json">{ld}</script></head></html>'

U = "https://www.ustiendao.com/a.html"
OLD = {"product_id": "a", "source_url": U, "isbn": "9789622449640", "title": "陶恕系列02", "price_list": "17.95", "availability": "InStock"}

print("單本比對")
check("沒變 → 空清單", compare(OLD, page(), U), [])
check("改價", compare(OLD, page(price="15.00"), U), [{"kind": "price", "old": "17.95", "new": "15.00"}])
check("缺貨", compare(OLD, page(avail="https://schema.org/OutOfStock"), U), [{"kind": "availability", "old": "InStock", "new": "OutOfStock"}])
check("改價又缺貨 → 兩筆", [c["kind"] for c in compare(OLD, page(price="1", avail="https://schema.org/OutOfStock"), U)], ["price", "availability"])
check("網址換成別本書 → 只報 isbn_changed", compare(OLD, page(gtin="9789570536065", price="1"), U), [{"kind": "isbn_changed", "old": "9789622449640", "new": "9789570536065"}])
check("讀不出（沒有 JSON-LD）", compare(OLD, "<html></html>", U), [{"kind": "unparsable", "why": "no_ld"}])

print("整輪")
snap = {"a": OLD, "b": dict(OLD, product_id="b", source_url="https://www.ustiendao.com/b.html"),
        "c": dict(OLD, product_id="c", source_url="https://www.ustiendao.com/c.html"),
        "d": dict(OLD, product_id="d", source_url="https://www.ustiendao.com/d.html")}
sitemap = ["https://www.ustiendao.com/a.html", "https://www.ustiendao.com/b.html", "https://www.ustiendao.com/c.html", "https://www.ustiendao.com/new.html"]
answers = {"https://www.ustiendao.com/a.html": ("ok", page()), "https://www.ustiendao.com/b.html": ("ok", page(price="9.99")),
           "https://www.ustiendao.com/c.html": ("gone", None), "https://www.ustiendao.com/d.html": ("error", None)}
rep = run(snap, sitemap, lambda u: answers[u])
check("sitemap 不見了：d", rep["not_in_sitemap"], ["d"])
check("計數", rep["counts"], {"checked": 4, "same": 1, "error": 1, "price": 1, "gone": 1})
check("變動列出書名、網址", [(r["product_id"], r["kind"]) for r in rep["changes"]], [("b", "price"), ("c", "gone")])
check("沒有停", rep["stopped"], None)

answers["https://www.ustiendao.com/b.html"] = ("challenge", None)
rep = run(snap, sitemap, lambda u: answers[u])
check("遇到驗證頁就停（b 之後的 c、d 不抓）", (rep["stopped"] is not None, rep["counts"]["checked"]), (True, 1))

calls = []
rep = run(snap, sitemap, lambda u: (calls.append(u), ("ok", page()))[1], limit=2, seed=1)
check("--limit 只抓兩頁", len(calls), 2)

print("全部通過" if not fail else f"有 {fail} 項不過")
sys.exit(1 if fail else 0)
