#!/bin/bash
# 新品警報「讀不到才報」測試（Asana 1220550095018569；熊哥 2026-11-15 TG「甲 照建議改（讀不到才報）」）
# 用假的 summary TSV 跑 tools/send_alert.php --dry-run，不寄信、不記節流。用法：bash tests/test_send_alert_scanned.sh
set -u
cd "$(dirname "$0")/.."
D=$(mktemp -d); trap 'rm -rf "$D"' EXIT
fail=0
ok() { if [ "$1" = 0 ]; then echo "✅ $2"; else echo "🔴 $2"; fail=$((fail+1)); fi; }
TODAY=20261115
# 寫 14 天摘要：$1=今天那列的備註、$2=之前 13 天的備註（同一個來源 akow 每天 0 本）
mk() {
  rm -f "$D"/summary-*.tsv
  for i in $(seq 0 13); do
    d=$(date -d "2026-11-15 -$i day" +%Y%m%d)
    note=$([ "$i" = 0 ] && echo "$1" || echo "$2")
    printf 'time\tstep\tstatus\tcount\tsec\tnote\n2026-11-15 07:00:00\tcrawl:akow\tnone\t0\t5\t%s\n2026-11-15 07:00:00\tcrawl:campus\tok\t3\t9\t\n' "$note" > "$D/summary-$d.tsv"
  done
  printf '{"finished_at":"2026-11-15 07:50:00"}\n' > "$D/last_ok.json"
}
run() { php tools/send_alert.php --dry-run --date=$TODAY --log-dir="$D" --now=2026-11-15T08:00:00 2>&1; }

mk "" ""
out=$(run); ok $(echo "$out" | grep -q '連續 14 天都是 0 本新書'; echo $?) "1 舊摘要（沒有清單件數）：照舊 14 天 0 本要叫（後備）"

mk "清單 144 件" ""
out=$(run); ok $(! echo "$out" | grep -q '0 本新書\|清單讀到 0 件'; echo $?) "2 今天清單讀到 144 件：只是沒新書，不叫"

mk "清單 0 件" "清單 144 件"
out=$(run); ok $(echo "$out" | grep -q '今天清單讀到 0 件' && ! echo "$out" | grep -q '連續 14 天'; echo $?) "3 今天清單讀到 0 件：當天叫「讀不到」，不重複叫 14 天"

mk "清單 144 件" "清單 144 件"
out=$(run); ok $(echo "$out" | grep -q '沒有狀況'; echo $?) "4 14 天都讀得到、都沒新書：沒有狀況"

# 只有前幾天、今天才第一次 0：不叫 14 天（天數不夠）
rm -f "$D"/summary-*.tsv
printf 'time\tstep\tstatus\tcount\tsec\tnote\n2026-11-15 07:00:00\tcrawl:akow\tnone\t0\t5\t\n' > "$D/summary-$TODAY.tsv"
printf '{"finished_at":"2026-11-15 07:50:00"}\n' > "$D/last_ok.json"
out=$(run); ok $(! echo "$out" | grep -q '連續 14 天'; echo $?) "5 資料不滿 14 天：不判"

# 失敗列照舊叫
printf 'time\tstep\tstatus\tcount\tsec\tnote\n2026-11-15 07:00:00\tcrawl:akow\tfail\t0\t5\t抓取失敗\n' > "$D/summary-$TODAY.tsv"
out=$(run); ok $(echo "$out" | grep -q '抓取或匯入失敗'; echo $?) "6 抓取失敗：照舊叫"

# new_arrivals 端：_AskedSet 會記被問過的不同 key
python3 - <<'EOF' && r=0 || r=1
import sys, tempfile, pathlib
sys.path.insert(0, "crawler")
from common import JsonlWriter
p = pathlib.Path(tempfile.mkdtemp()) / "x.jsonl"
p.write_text('{"pid":"a"}\n{"pid":"b"}\n', encoding="utf-8")
w = JsonlWriter(p, "pid")
assert w.asked_count == 0
_ = "a" in w.seen; _ = "a" in w.seen; _ = w.has("z"); _ = "c" not in w.seen
assert w.asked_count == 3, w.asked_count          # a、z、c
assert w.write({"pid": "c"}) and not w.write({"pid": "a"})
assert len(w.seen) == 3 and "c" in w.seen
w.close()
EOF
ok $r "7 JsonlWriter.asked_count 算的是被問過的不同 key（重複問只算一次），寫入照舊"

echo; [ $fail = 0 ] && echo "✅ 全部通過" || { echo "🔴 $fail 項不對"; exit 1; }
