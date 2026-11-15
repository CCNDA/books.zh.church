#!/bin/bash
# 簡繁互連② 後台 editions.php 整合測試（cora-lab，books_dev；跑完把改到的列還原）
set -u
cd "$(dirname "$0")/../.."
J=$(php tools/dev/mkjwt.php cora@ccnda.org)          # 測試 JWT 不印出
H="Cf-Access-Jwt-Assertion: $J"
B=http://127.0.0.1:18091/admin/editions.php
CK=$(mktemp); OUT=$(mktemp)
php -S 127.0.0.1:18091 -t public > /tmp/ed_srv.log 2>&1 & SRV=$!
sleep 1
fail=0
ok() { if [ "$1" = 0 ]; then echo "✅ $2"; else echo "🔴 $2"; fail=$((fail+1)); fi; }
sql() { sudo -n mariadb -N books_dev -e "$1"; }

before=$(sql "select count(*), sum(decision is null) from edition_pairs")
first=$(sql "select pair_id from edition_pairs where decision is null order by same_ratio desc, pair_id limit 1")

code=$(curl -s -o $OUT -w '%{http_code}' -H "$H" -c $CK -b $CK "$B")
ok $([ "$code" = 200 ] && grep -q "待判（$(echo $before | awk '{print $2}')）" $OUT; echo $?) "1 待判頁 200、待判數跟資料庫一樣（$before）"
ok $(grep -q "id=\"r$first\"" $OUT; echo $?) "2 第一組是相同比例最高、編號最小的那組（$first）"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B")
ok $([ "$code" = 403 ]; echo $?) "3 沒有 JWT → 403"
csrf=$(grep -o 'name="csrf" value="[0-9a-f]*"' $OUT | head -1 | grep -o '[0-9a-f]\{64\}')

# 判一組「是」
code=$(curl -s -o /dev/null -w '%{http_code}' -H "$H" -c $CK -b $CK -d "csrf=$csrf&action=decide&pair_id=$first&decision=same&back=pending&p=1" "$B")
d=$(sql "select decision, decided_by from edition_pairs where pair_id=$first")
ok $([ "$code" = 303 ] && [ "$d" = "same	cora@ccnda.org" ]; echo $?) "4 按「是」→ 303、資料庫記 same＋判定人（$d）"
# 錯的 CSRF
code=$(curl -s -o /dev/null -w '%{http_code}' -H "$H" -c $CK -b $CK -d "csrf=bad&action=decide&pair_id=$first&decision=different" "$B")
ok $([ "$code" = 400 ] && [ "$(sql "select decision from edition_pairs where pair_id=$first")" = same ]; echo $?) "5 CSRF 不對 → 400、不改資料"
# 亂給的判定值
code=$(curl -s -o /dev/null -w '%{http_code}' -H "$H" -c $CK -b $CK -d "csrf=$csrf&action=decide&pair_id=$first&decision=delete" "$B")
ok $([ "$code" = 400 ]; echo $?) "6 判定值不在白名單 → 400"
# 已判分頁看得到
curl -s -o $OUT -H "$H" -c $CK -b $CK "$B?tab=done"
ok $(grep -q "id=\"r$first\"" $OUT && grep -q 'aria-pressed="true">是同一本的簡繁版' $OUT; echo $?) "7 已判分頁有這組、「是」按鈕標成已按"

# 手動加一組：64263（簡）／81275（正，書名多副標，自動規則配不到）
code=$(curl -s -o /dev/null -w '%{http_code}' -H "$H" -c $CK -b $CK -d "csrf=$csrf&action=add&simp_book_id=64263&trad_book_id=81275&back=pending&p=1" "$B")
m=$(sql "select match_how, decision from edition_pairs where simp_book_id=64263 and trad_book_id=81275")
ok $([ "$code" = 303 ] && [ "$m" = "人工加入	same" ]; echo $?) "8 手動加 64263／81275 → 記成 人工加入＋same（$m）"
# 反過來再加一次 → 不重複
n0=$(sql "select count(*) from edition_pairs")
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -H "$H" -c $CK -b $CK -d "csrf=$csrf&action=add&simp_book_id=81275&trad_book_id=64263" "$B")
ok $([ "$n0" = "$(sql "select count(*) from edition_pairs")" ] && [[ "$loc" == *ok=add_exists* ]]; echo $?) "9 填反再加 → 不新增、提示已在表上"
# 不存在的書、同一本
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -H "$H" -c $CK -b $CK -d "csrf=$csrf&action=add&simp_book_id=999999999&trad_book_id=81275" "$B")
ok $([[ "$loc" == *ok=add_nobook* ]]; echo $?) "10 不存在的編號 → 提示找不到"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -H "$H" -c $CK -b $CK -d "csrf=$csrf&action=add&simp_book_id=81275&trad_book_id=81275" "$B")
ok $([[ "$loc" == *ok=add_bad* ]]; echo $?) "11 兩個編號一樣 → 提示不能一樣"
# 提示訊息只吃白名單
curl -s -o $OUT -H "$H" -c $CK -b $CK "$B?ok=%3Cscript%3E&id=1"
ok $(! grep -q '<script>' $OUT; echo $?) "12 ok 參數亂給不會印出來"
ok $(! grep -q -E 'Deprecated|Warning|Fatal|Notice' /tmp/ed_srv.log; echo $?) "13 伺服器 log 沒有 PHP 警告"

# 還原
sql "delete from edition_pairs where simp_book_id=64263 and trad_book_id=81275 and match_how='人工加入'"
sql "update edition_pairs set decision=NULL, decided_by=NULL, decided_at=NULL where pair_id=$first"
after=$(sql "select count(*), sum(decision is null) from edition_pairs")
ok $([ "$before" = "$after" ]; echo $?) "14 還原後跟測試前一樣（$after）"
kill $SRV; rm -f $CK $OUT /tmp/ed_srv.log
echo; [ $fail = 0 ] && echo "✅ 全部通過" || echo "🔴 $fail 項不對"
