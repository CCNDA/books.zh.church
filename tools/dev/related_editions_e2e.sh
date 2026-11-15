#!/bin/bash
# 簡繁互連③ 整合測試：API related_editions＋書目頁「另有版本」（cora-lab，books_dev；跑完還原）
# 用法：bash tools/dev/related_editions_e2e.sh
set -u
cd "$(dirname "$0")/../.."
ROOT=$(pwd)
R=$(mktemp -d); OUT=$(mktemp)
cat > $R/router.php <<EOF
<?php
// 模擬 nginx：/book/N → book.php、/api/... → api/index.php
\$p = parse_url(\$_SERVER["REQUEST_URI"], PHP_URL_PATH);
if (preg_match('#^/book/(\d+)#', \$p, \$m)) { \$_GET["id"] = \$m[1]; chdir("$ROOT/public"); require "$ROOT/public/book.php"; return true; }
if (str_starts_with(\$p, "/api/")) { chdir("$ROOT/public/api"); require "$ROOT/public/api/index.php"; return true; }
return false;
EOF
php -S 127.0.0.1:18093 -t public $R/router.php > $R/srv.log 2>&1 & SRV=$!
trap "kill $SRV 2>/dev/null; rm -rf $R $OUT" EXIT   # 中途失敗也一定關掉伺服器（11/15 留下一支佔住連接埠）
sleep 1
B=http://127.0.0.1:18093
fail=0
ok() { if [ "$1" = 0 ]; then echo "✅ $2"; else echo "🔴 $2"; fail=$((fail+1)); fi; }
sql() { sudo -n mariadb -N books_dev -e "$1"; }
api_rel() { curl -s "$B/api/books/$1" | python3 -c 'import sys,json; d=json.load(sys.stdin); d=d.get("data",d); print(json.dumps([[r["book_id"],r["script"]] for r in d.get("related_editions",[])], ensure_ascii=False))'; }

read PID S T <<< "$(sql "select pair_id, simp_book_id, trad_book_id from edition_pairs p join books s on s.book_id=p.simp_book_id and s.is_published=1 join books t on t.book_id=p.trad_book_id and t.is_published=1 where decision is null order by pair_id limit 1")"
UNPUB=$(sql "select book_id from books where is_published=0 order by book_id limit 1")
BEFORE=$(sql "select count(*), sum(decision is null), sum(decision='same') from edition_pairs")
echo "測試用：第 $PID 組（簡 $S／正 $T）、未上架 $UNPUB；測試前 $BEFORE"

ok $([ "$(api_rel $S)" = "[]" ]; echo $?) "1 還沒判：API related_editions 是空的"
curl -s "$B/book/$S" -o $OUT
ok $(! grep -q '另有版本</dt>' $OUT; echo $?) "2 還沒判：書目頁沒有「另有版本」"

sql "update edition_pairs set decision='same', decided_by='test', decided_at=now() where pair_id=$PID"
ok $([ "$(api_rel $S)" = "[[$T, \"正體\"]]" ]; echo $?) "3 判「是」：簡體那本的 API 列出正體 $T（$(api_rel $S)）"
ok $([ "$(api_rel $T)" = "[[$S, \"簡體\"]]" ]; echo $?) "4 判「是」：正體那本的 API 列出簡體 $S（$(api_rel $T)）"
curl -s "$B/book/$S" -o $OUT
ok $(grep -q "另有版本</dt><dd>正體版：<a href=\"/book/$T\">" $OUT; echo $?) "5 書目頁伺服器輸出有「另有版本 正體版：連結」"

sql "update edition_pairs set decision='different' where pair_id=$PID"
ok $([ "$(api_rel $S)" = "[]" ]; echo $?) "6 改判「不是」：不再列出"

# 對到未上架的書：不列
sql "insert into edition_pairs (simp_book_id, trad_book_id, match_how, decision) values ($S, $UNPUB, '測試', 'same')"
ok $([ "$(api_rel $S)" = "[]" ]; echo $?) "7 另一本未上架：不列出"
sql "delete from edition_pairs where simp_book_id=$S and trad_book_id=$UNPUB and match_how='測試'"

# 表不存在（正式站 migration 還沒跑）：頁面照常
sql "rename table edition_pairs to edition_pairs_tmp_e2e"
code=$(curl -s -o $OUT -w '%{http_code}' "$B/book/$S")
ok $([ "$code" = 200 ] && grep -q 'id="bTitle"' $OUT && [ "$(api_rel $S)" = "[]" ]; echo $?) "8 表不存在：書目頁 200、API related_editions 空陣列"
sql "rename table edition_pairs_tmp_e2e to edition_pairs"

ok $(! grep -q -E 'Deprecated|Warning|Fatal|Notice' $R/srv.log; echo $?) "9 伺服器 log 沒有 PHP 警告"

# 還原
sql "update edition_pairs set decision=NULL, decided_by=NULL, decided_at=NULL where pair_id=$PID"
AFTER=$(sql "select count(*), sum(decision is null), sum(decision='same') from edition_pairs")
ok $([ "$AFTER" = "$BEFORE" ]; echo $?) "10 還原後跟測試前一樣（$AFTER）"
kill $SRV; rm -rf $R $OUT
echo; [ $fail = 0 ] && echo "✅ 全部通過" || echo "🔴 $fail 項不對"
