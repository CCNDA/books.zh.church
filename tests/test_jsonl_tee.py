# new_arrivals 的 _writer＋JsonlWriter tee：每筆一行不重複、沒新書不產生檔案、dry-run 不寫、main 結尾不再重寫一次
import json, os, sys, tempfile
from pathlib import Path
# 用法（repo 根目錄）：python3 tests/test_jsonl_tee.py；Asana 1217767336780488
CRAWLER = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "crawler")
sys.path.insert(0, CRAWLER)
os.chdir(CRAWLER)
import importlib, types
# cora-lab 沒裝爬蟲的第三方套件（bs4 等）：缺什麼就塞一個空模組，這個測試用不到它們
while True:
    try:
        na = importlib.import_module("new_arrivals"); break
    except ModuleNotFoundError as e:
        name = e.name; parts = name.split(".")
        for i in range(1, len(parts) + 1):
            m = types.ModuleType(".".join(parts[:i])); m.__getattr__ = lambda attr: type(attr, (), {})
            sys.modules.setdefault(".".join(parts[:i]), m)

ok = bad = 0
def check(label, cond):
    global ok, bad
    print(("  PASS " if cond else "  FAIL ") + label); ok += bool(cond); bad += (not cond)

tmp = Path(tempfile.mkdtemp())
na.DATA = tmp / "data"
na.NEW_DIR = tmp / "new"

# 1. 直接用 _writer：重複的 key 不寫第二次，tee 一筆一行
na._DELTA_OUT = tmp / "d1.jsonl"
w = na._writer("x_books.jsonl", "id")
w.write({"id": "a", "t": "甲"}); w.write({"id": "a", "t": "甲"}); w.write({"id": "b", "t": "乙"})
lines_mid = (tmp / "d1.jsonl").read_text(encoding="utf-8").splitlines()
check("還沒 close 就已經寫進 delta（中途被停掉也在）", len(lines_mid) == 2)
w.close()
check("master 2 行", len((na.DATA / "x_books.jsonl").read_text(encoding="utf-8").splitlines()) == 2)

# 2. 沒新書：不產生 delta 檔
na._DELTA_OUT = tmp / "d2.jsonl"
w = na._writer("x_books.jsonl", "id"); w.write({"id": "a"}); w.close()
check("全是見過的：不產生 delta 檔", not (tmp / "d2.jsonl").exists())

# 3. 走 main()：假的 collect_cclm 寫兩筆，delta 不能變成 4 行（結尾不再重寫）
def fake_cclm(writer, max_pages, dry_run):
    out = []
    for i in (1, 2):
        r = {"pid": f"p{i}", "title": f"書{i}"}
        if dry_run or writer.write(r): out.append(r)
    return out
na.collect_cclm = fake_cclm
out = tmp / "d3.jsonl"
sys.argv = ["new_arrivals.py", "--source", "cclm", "--out", str(out)]
na.main()
d = out.read_text(encoding="utf-8").splitlines()
check(f"main()：delta 2 行（實際 {len(d)}）", len(d) == 2 and json.loads(d[0])["pid"] == "p1")

# 4. dry-run：不寫 master 也不寫 delta
out4 = tmp / "d4.jsonl"
before = (na.DATA / "cclm_books.jsonl").read_text(encoding="utf-8")
sys.argv = ["new_arrivals.py", "--source", "cclm", "--out", str(out4), "--dry-run"]
na.main()
check("dry-run 不產生 delta", not out4.exists())
check("dry-run 不動 master", (na.DATA / "cclm_books.jsonl").read_text(encoding="utf-8") == before)
print(f"\nOK {ok} / FAIL {bad}")
sys.exit(1 if bad else 0)
