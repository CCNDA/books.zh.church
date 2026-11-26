#!/usr/bin/env bash
# 書目頁購書鈕（天道北美已下架／缺貨）：開發庫 books_dev 實測（Asana 1220919560670331）
# 挑兩本天道北美：A 改成下架（Discontinued＋備註標記）、B 改成缺貨（OutOfStock）→ 打書目頁（伺服器端）和 API → 還原備份
# 用法：bash tests/test_ustd_display_dev.sh <repo 根目錄> [port]
set -u
W=$(cd "${1:?worktree}" && pwd); PORT=${2:-8841}; cd "$W"
q(){ sudo mariadb -N books_dev -e "$1" 2>/dev/null; }
[ "$(q 'select database()')" = books_dev ] || { echo "不是 books_dev，停"; exit 2; }
CFG_COPIED=0
if [ ! -f config/app.local.php ]; then cp /srv/dev/books.zh.church/config/app.local.php config/app.local.php; CFG_COPIED=1; fi
ts=$(date +%Y%m%d-%H%M%S); sudo sh -c "mariadb-dump books_dev formats_prices links > /srv/dev/backup/books_dev_ustdui_$ts.sql" && echo "備份 /srv/dev/backup/books_dev_ustdui_$ts.sql"
mapfile -t E < <(q "select e.edition_id, e.book_id from editions e join formats_prices f on f.edition_id=e.edition_id join links l on l.edition_id=e.edition_id and l.link_type='buy' where e.source='ustiendao' group by e.edition_id order by e.edition_id limit 2")
[ ${#E[@]} -eq 2 ] || { echo "開發庫找不到 2 本合用的天道北美，停"; exit 2; }
read -r EA BA <<< "${E[0]}"; read -r EB BB <<< "${E[1]}"
q "update formats_prices set availability='Discontinued' where edition_id=$EA; update links set note=trim(concat(ifnull(note,''),' [天道北美已下架]')) where edition_id=$EA and link_type='buy'; update formats_prices set availability='OutOfStock' where edition_id=$EB"
pass=0; fail=0; ok(){ echo "  PASS $1"; pass=$((pass+1)); }; ng(){ echo "  FAIL $1"; fail=$((fail+1)); }
php -S 127.0.0.1:$PORT -t public > /tmp/ustdui_$PORT.log 2>&1 & SRV=$!; sleep 1
HA=$(curl -s "http://127.0.0.1:$PORT/book.php?id=$BA"); HB=$(curl -s "http://127.0.0.1:$PORT/book.php?id=$BB")
echo "A＝book $BA（下架）"
grep -q '<span class="btn off" aria-disabled="true">[^<]*：已下架</span>' <<<"$HA" && ok "顯示「已下架」" || ng "沒有「已下架」"
grep -q 'class="btn primary[^"]*" href="https\?://[^"]*ustiendao' <<<"$HA" && ng "還有天道北美購買鈕" || ok "沒有天道北美購買鈕"
grep -q '天道北美已下架\]' <<<"$(sed -n '/id="bBtns"/,/copyBtn/p' <<<"$HA")" && ng "標記字樣跑到畫面上" || ok "標記字樣沒顯示在按鈕"
echo "B＝book $BB（缺貨）"
grep -q 'class="btn primary oos" href="https\?://[^"]*ustiendao[^"]*"[^>]*>🛒 [^<]*（缺貨）</a>' <<<"$HB" && ok "缺貨標示、連結照留" || ng "缺貨標示不對"
echo "API"
curl -s "http://127.0.0.1:$PORT/api/books/$BA" | python3 -c "import json,sys;d=json.load(sys.stdin);d=d.get('data',d);print([l.get('status') for l in d.get('buy_links',[]) if 'ustiendao' in l.get('url','')])" | grep -q "discontinued" && ok "API buy_links 帶 status=discontinued" || ng "API 沒帶 status"
grep -h "PHP Fatal\|PHP Warning" /tmp/ustdui_$PORT.log | head -2; grep -q "PHP Fatal" /tmp/ustdui_$PORT.log && ng "有 PHP Fatal" || ok "沒有 PHP Fatal"
kill $SRV 2>/dev/null; rm -f /tmp/ustdui_$PORT.log
sudo sh -c "mariadb books_dev < /srv/dev/backup/books_dev_ustdui_$ts.sql" && echo "還原完成：A 下架標記剩 $(q "select count(*) from links where edition_id=$EA and note like '%天道北美已下架%'")"
[ $CFG_COPIED = 1 ] && rm -f config/app.local.php
echo "$pass passed, $fail failed"; [ "$fail" = 0 ]
