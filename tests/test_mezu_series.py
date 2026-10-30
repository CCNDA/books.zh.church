"""回歸測試：真哪噠書系欄不再帶「名稱」標籤（Asana 1218961225060659）
用法：crawler/venv/bin/python tests/test_mezu_series.py   全過 exit 0
不連網：用商品頁描述的幾種寫法。"""
import sys, pathlib
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent / "crawler"))
from mezu_crawler import harvest_specs, pick_specs

fail = 0
def check(what, got, want):
    global fail
    ok = got == want
    print(("  ✓ " if ok else "  ✗ ") + what + ("" if ok else f"：得到 {got!r}，應為 {want!r}"))
    fail += not ok

def series(text):
    specs, _ = harvest_specs(text)
    return pick_specs(text, specs).get("series_text")

print("真哪噠書系")
check("系列名稱＋兩個空白", series("出版商  真哪噠出版社\n系列名稱  道生神學院門徒進深叢書第一冊\n作者  陳達"), "道生神學院門徒進深叢書第一冊")
check("系列名稱＋全形冒號", series("系列名稱：Meet 14\n作者：某人"), "Meet 14")
check("系列名稱＋半形冒號", series("系列名稱:Truth 07"), "Truth 07")
check("系列名稱沒有值 → 沒有書系", series("系列名稱\n作者  陳達"), None)
check("舊寫法 系列別 不受影響", series("系列別：福音派神學叢書"), "福音派神學叢書")
check("舊寫法 系列 不受影響", series("系列  Truth 24"), "Truth 24")
check("冊次記號保留", series("系列名稱  Meet 40"), "Meet 40")
sys.exit(1 if fail else 0)
