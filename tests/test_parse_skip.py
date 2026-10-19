"""回歸測試：基道解析失敗碼不再每天白抓（Asana 1216651317073747 追加 C）
用法：crawler/venv/bin/python tests/test_parse_skip.py   全過 exit 0
不連網：用假的 parse_fn。"""
import sys, tempfile, pathlib
from datetime import datetime, timedelta
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent / "crawler"))
from new_arrivals import ParseSkip

fail = 0
def check(what, ok):
    global fail
    print(("  ✓ " if ok else "  ✗ ") + what)
    fail += not ok

calls = []
def fake(code):
    calls.append(code)
    if code == "NET":
        raise RuntimeError("timeout")
    return None if code.startswith("BAD") else {"title": code}

print("基道解析失敗碼")
d = pathlib.Path(tempfile.mkdtemp()); f = d / "skip.tsv"

ps = ParseSkip(f, fake)
check("第一次：解析失敗回 None", ps("BAD1") is None)
check("第一次：有呼叫 parse_fn", calls == ["BAD1"])
check("失敗碼寫進 TSV", f.exists() and "BAD1\t" in f.read_text(encoding="utf-8"))
check("成功的照常回傳", ps("OK1") == {"title": "OK1"})

calls.clear()
ps2 = ParseSkip(f, fake)
check("第二天：7 天內的失敗碼不再呼叫 parse_fn", ps2("BAD1") is None and calls == [])
check("跳過次數有記", ps2.skipped == 1)

try:
    ParseSkip(f, fake)("NET"); check("網路錯誤要往外丟（給下次補抓）", False)
except RuntimeError:
    check("網路錯誤照樣往外丟、不記成解析失敗", "NET" not in f.read_text(encoding="utf-8"))

old = (datetime.now() - timedelta(days=8)).strftime("%Y-%m-%d")
f.write_text(f"code\tfirst_failed\tlast_failed\tcount\nBAD1\t{old}\t{old}\t3\nBAD2\t{old}\t{old}\t1\n", encoding="utf-8")
calls.clear()
ps3 = ParseSkip(f, lambda c: (calls.append(c), {"title": c} if c == "BAD2" else None)[1])
ps3("BAD1"); ps3("BAD2")
check("超過 7 天會再試一次", calls == ["BAD1", "BAD2"])
t = f.read_text(encoding="utf-8")
today = datetime.now().strftime("%Y-%m-%d")
check("再試仍失敗 → 次數 +1、保留第一次失敗日、更新最後失敗日", f"BAD1\t{old}\t{today}\t4" in t.splitlines())
check("再試成功 → 從清單移除", "BAD2" not in t)

f.write_text("code\tfirst_failed\tlast_failed\tcount\n", encoding="utf-8")
ps4 = ParseSkip(f, fake, dry_run=True); ps4("BAD9")
check("dry-run 不寫檔", "BAD9" not in f.read_text(encoding="utf-8"))

print("\n全部通過" if not fail else f"\n有 {fail} 條不過")
sys.exit(1 if fail else 0)
