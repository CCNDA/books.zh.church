# daily_new.sh 逾時＋鎖＋邊寫邊出 的測試：假 ROOT，不抓網路、不碰資料庫
# 用法（repo 根目錄）：bash tests/test_daily_new.sh [daily_new.sh] [common.py]，預設測 repo 裡的；Asana 1217767336780488
# 要 flock、timeout、php、python3。會在 /tmp/crawl_t 建暫存目錄、測完刪掉
set -u
set -- "${1:-crawler/daily_new.sh}" "${2:-crawler/common.py}"
T=/tmp/crawl_t; rm -rf $T; mkdir -p $T/crawler/data $T/tools
cp "$1" $T/crawler/daily_new.sh
cp "$2" $T/crawler/common.py
# 假爬蟲：用真的 common.JsonlWriter（含 tee，舊版沒有 tee 就照舊版整輪結束才寫 delta）
cat > $T/crawler/new_arrivals.py <<'PY'
import os, sys, time, json, signal, subprocess, inspect
from pathlib import Path
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from common import JsonlWriter
a = sys.argv; src = a[a.index("--source") + 1]; out = Path(a[a.index("--out") + 1])
mode = os.environ.get("MODE_" + src, "none")
kw = {"tee": out} if "tee" in inspect.signature(JsonlWriter.__init__).parameters else {}
w = JsonlWriter(Path(__file__).parent / "data" / f"{src}_books.jsonl", "id", **kw)
fresh = []
if mode in ("write2_hang", "write2_ok"):
    for i in (1, 2):
        r = {"id": f"{src}-{i}", "title": f"{src} 第{i}本", "source_url": f"https://x/{src}/{i}"}
        if w.write(r): fresh.append(r)
if mode == "write2_hang":
    print("  [503] 假裝一直 503…", flush=True); time.sleep(300)
if mode == "orphan":
    subprocess.Popen(["sleep", "300"], start_new_session=True, close_fds=False)   # 孤兒行程，繼承打開的 fd（像瀏覽器那樣）
if mode == "oom":
    time.sleep(1); os.kill(os.getpid(), signal.SIGKILL)
w.close()
if fresh and not kw:   # 舊版：整輪結束才寫 delta
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text("".join(json.dumps(r, ensure_ascii=False) + "\n" for r in fresh), encoding="utf-8")
PY
# 假 import.php：把 delta 每行的 source_url 記進「資料庫」
cat > $T/tools/import.php <<'PHP'
<?php
$file = ''; foreach ($argv as $x) if (str_starts_with($x, '--file=')) $file = substr($x, 7);
foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) file_put_contents(getenv('FAKE_DB'), json_decode($l, true)['source_url'] . "\n", FILE_APPEND);
PHP
for f in $(grep -o -E 'python3 (-u )?[a-z_]+\.py' "$1" | awk '{print $NF}' | sort -u); do [ -f $T/crawler/$f ] || echo 'import sys; sys.exit(0)' > $T/crawler/$f; done
ok=0; bad=0; check() { if [ "$2" = "$3" ]; then ok=$((ok+1)); echo "  PASS $1"; else bad=$((bad+1)); echo "  FAIL $1（預期 $3，實際 $2）"; fi; }
D=$(date +%Y%m%d); L=$T/crawler/logs/new-arrivals-$D.log; S=$T/crawler/logs/summary-$D.tsv
export FAKE_DB=$T/db.txt
run() { BOOKS_ROOT=$T "$@" timeout 200 bash $T/crawler/daily_new.sh >/dev/null 2>&1; }
reset() { rm -rf $T/crawler/logs $T/crawler/data/* $T/db.txt; touch $T/db.txt; }

echo "== 1. cclm 卡住：逾時要停掉、接著跑完"
reset; t0=$SECONDS
run env SOURCE_TIMEOUT=3 MODE_cclm=write2_hang
check "整支在 60 秒內跑完" "$([ $((SECONDS - t0)) -lt 60 ] && echo y)" y
check "summary 記 crawl:cclm fail 逾時" "$(awk -F'\t' '$2=="crawl:cclm" && $3=="fail" && $6 ~ /逾時/' $S | wc -l)" 1
check "cclm 之後的 akow 有跑" "$(grep -c 'crawl:akow' $S)" 1
check "跑到「每日新品檢查結束」" "$(grep -c '每日新品檢查結束' $L)" 1
check "沒有殘留的假 cclm" "$(pgrep -f "$T/crawler/new_arrivals.py --source cclm" | wc -l)" 0

echo "== 2. 小布要的：先寫兩筆進 master 再逾時 → 兩筆要進資料庫（不能永久漏掉）"
check "master 有 2 筆（已算見過）" "$(wc -l < $T/crawler/data/cclm_books.jsonl 2>/dev/null || echo 0)" 2
check "資料庫有 cclm 那 2 筆" "$(grep -c 'https://x/cclm/' $T/db.txt)" 2
check "summary 備註「已匯入 2 本」" "$(awk -F'\t' '$2=="crawl:cclm" && $6 ~ /已匯入 2 本/' $S | wc -l)" 1
echo "   隔天再跑一次（站方好了）：那 2 筆已見過不會再當新書，但已經在資料庫，所以沒漏"
run env SOURCE_TIMEOUT=3 MODE_cclm=write2_ok
check "資料庫還是那 2 筆、沒有重複匯入" "$(grep -c 'https://x/cclm/' $T/db.txt)" 2

echo "== 3. 正常有新書：delta 一筆一行、不重複"
reset; run env SOURCE_TIMEOUT=30 MODE_osb=write2_ok
check "osb 匯入 2 本" "$(grep -c 'https://x/osb/' $T/db.txt)" 2
check "osb summary ok 2" "$(awk -F'\t' '$2=="crawl:osb" && $3=="ok" && $4==2' $S | wc -l)" 1

echo "== 4. 137 但沒到時間（記憶體不足之類）→ 記「被強制停」不是「逾時」"
reset; run env SOURCE_TIMEOUT=30 MODE_elim=oom
check "summary 記 elim 被強制停" "$(awk -F'\t' '$2=="crawl:elim" && $3=="fail" && $6 ~ /被強制停/' $S | wc -l)" 1

echo "== 5. 鎖：第一輪還在跑，第二輪要略過、不能清掉第一輪的 summary"
reset
BOOKS_ROOT=$T SOURCE_TIMEOUT=20 MODE_cclm=write2_hang bash $T/crawler/daily_new.sh >/dev/null 2>&1 &
P1=$!
for i in $(seq 1 40); do grep -q -- '--- cclm' $L 2>/dev/null && break; sleep 0.5; done
before=$(wc -l < $S); t0=$SECONDS
run env SOURCE_TIMEOUT=20; rc=$?
check "第二輪 exit 0" "$rc" 0
check "第二輪馬上結束（< 5 秒）" "$([ $((SECONDS - t0)) -lt 5 ] && echo y)" y
check "log 有「上一輪還在跑,這輪略過」" "$(grep -c '上一輪還在跑' $L)" 1
check "第一輪的 summary 沒被清掉" "$([ "$(wc -l < $S)" -ge "$before" ] && [ "$before" -gt 1 ] && echo y)" y
wait $P1
check "第一輪自己跑完" "$(grep -c '每日新品檢查結束' $L)" 1

echo "== 6. 小布要的：子行程留下孤兒，鎖不能被占住"
reset; run env SOURCE_TIMEOUT=30 MODE_campus=orphan
check "孤兒行程真的還在（測試有效）" "$([ "$(pgrep -f '^sleep 300$' | wc -l)" -ge 1 ] && echo y)" y
run env SOURCE_TIMEOUT=30
check "下一輪沒被略過、有跑完" "$(grep -c '每日新品檢查結束' $L)" 2
check "沒有出現「這輪略過」" "$(grep -c '這輪略過' $L)" 0

pkill -f '^sleep 300$' 2>/dev/null; pkill -f "$T/crawler" 2>/dev/null; rm -rf $T
echo; echo "OK $ok / FAIL $bad"; [ "$bad" -eq 0 ]
