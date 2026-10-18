"""回歸測試：衛理書房詳細資料面板的作者欄不能吃進 ISBN／書號／出版（Asana 1219613623198535）
用法：crawler/venv/bin/python tests/test_methodist_detail.py   全過 exit 0
案例取自正式庫 2026-10-19 實查的 3 本（作者欄被污染）＋原本就正常的寫法。"""
import sys, pathlib
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent / "crawler"))
from methodist_crawler import parse_detail_panel

fail = 0
def check(what, got, want):
    global fail
    ok = got == want
    print(("  ✓ " if ok else "  ✗ ") + what + ("" if ok else f"（得到 {got!r}，應為 {want!r}）"))
    fail += not ok

print("衛理詳細資料面板")
d = parse_detail_panel("作者：陳恩明 ISBN ： 9789888562442 出版：突破 出版日期：2020-05-01 頁數：200")
check("86624：作者只剩人名", d.get("authors_raw"), "陳恩明")
check("86624：出版日期照常", d.get("publish_date"), "2020-05-01")
check("86624：「出版：」不會被當成出版社", d.get("publisher_page"), None)
d = parse_detail_panel("作者：辛西亞·湯白斯 / 卡羅爾·芬克 ISBN ： 9787510857294 出版社：中國文聯")
check("86386：作者只剩人名", d.get("authors_raw"), "辛西亞·湯白斯 / 卡羅爾·芬克")
check("86386：出版社照常（不被「出版」切走）", d.get("publisher_page"), "中國文聯")
d = parse_detail_panel("作者：李志秋，張心瑋合著 書號：9789811438141 出版社：新加坡神學院")
check("85920：作者只剩人名", d.get("authors_raw"), "李志秋，張心瑋合著")
d = parse_detail_panel("作者：亨利．克勞德 譯者：鄭惟恩 出版社：校園書房出版社 出版日期：2019-01-01 語言：繁體中文")
check("原本正常的寫法不變：作者", d.get("authors_raw"), "亨利．克勞德")
check("原本正常的寫法不變：譯者", d.get("translators_raw"), "鄭惟恩")
check("原本正常的寫法不變：出版社", d.get("publisher_page"), "校園書房出版社")
check("原本正常的寫法不變：語言", d.get("language"), "繁體中文")
print("\n全部通過" if not fail else f"\n有 {fail} 條不過")
sys.exit(1 if fail else 0)
