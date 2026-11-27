#!/usr/bin/env bash
# 作者頁／出版社頁 API：開發庫 books_dev 唯讀實測（Asana 1220588345978224）
# 數字跟直接查庫比對；不寫入資料庫
# 用法：bash tests/test_pp_api_dev.sh <repo 根目錄> [port]
set -u
W=$(cd "${1:?worktree}" && pwd); PORT=${2:-8843}; cd "$W"
q(){ sudo mariadb -N books_dev -e "$1" 2>/dev/null; }
[ "$(q 'select database()')" = books_dev ] || { echo "不是 books_dev，停"; exit 2; }
CFG_COPIED=0
if [ ! -f config/app.local.php ]; then cp /srv/dev/books.zh.church/config/app.local.php config/app.local.php; CFG_COPIED=1; fi
pass=0; fail=0; ok(){ echo "  PASS $1"; pass=$((pass+1)); }; ng(){ echo "  FAIL $1"; fail=$((fail+1)); }
php -S 127.0.0.1:$PORT -t public public/api/index.php > /tmp/ppapi_$PORT.log 2>&1 & SRV=$!; sleep 1
api(){ curl -s -o /tmp/ppapi_body -w '%{http_code}' "http://127.0.0.1:$PORT/api$1"; }
jq_(){ python3 -c "import json,sys;d=json.load(open('/tmp/ppapi_body'))['data'];print(eval(sys.argv[1]))" "$1"; }

# 作者：挑上架書最多、而且有合作者的一位
PID=$(q "select bp.person_id from book_persons bp join books b on b.book_id=bp.book_id and b.is_published=1 group by bp.person_id order by count(distinct bp.book_id) desc limit 1")
echo "作者 person_id=$PID"
[ "$(api /persons/$PID)" = 200 ] && ok "200" || ng "不是 200"
DB_N=$(q "select count(distinct bp.book_id) from book_persons bp join books b on b.book_id=bp.book_id and b.is_published=1 where bp.person_id=$PID")
[ "$(jq_ "d['book_count']")" = "$DB_N" ] && ok "書數 $DB_N 跟庫一致" || ng "書數不一致 $(jq_ "d['book_count']") vs $DB_N"
LIST_N=$(curl -s "http://127.0.0.1:$PORT/api/books?person=$PID&per_page=1" | python3 -c "import json,sys;print(json.load(sys.stdin)['data']['total'])")
[ "$LIST_N" = "$DB_N" ] && ok "跟 /api/books?person= 的 total 一致" || ng "跟書單 total 不一致 $LIST_N"
[ "$(jq_ "sum(r['book_count'] for r in d['roles']) >= d['book_count']")" = True ] && ok "各角色書數加總 ≥ 總數" || ng "角色書數加總不合理"
[ "$(jq_ "len(d['collaborators']) <= 10 and all(c['person_id'] != d['person_id'] for c in d['collaborators'])")" = True ] && ok "合作者 ≤10 且不含自己" || ng "合作者清單不對"
[ "$(jq_ "'bio' in d or 'intro' in d")" = False ] && ok "沒有空的簡介欄位" || ng "出現簡介欄位"
[ "$(api /persons/999999999)" = 404 ] && ok "不存在的作者 404" || ng "不存在的作者不是 404"
[ "$(api /persons/abc)" = 404 ] && ok "非數字 id 404" || ng "非數字 id 不是 404"

# 出版社：挑有變體（canonical_id 指向它）的一家，用變體 id 打，應回正規社
read -r VID CID <<< "$(q "select v.publisher_id, v.canonical_id from publishers v join editions e on e.publisher_id=v.publisher_id join books b on b.book_id=e.book_id and b.is_published=1 where v.canonical_id is not null and v.canonical_id<>v.publisher_id group by v.publisher_id order by count(*) desc limit 1")"
echo "出版社 變體 $VID → 正規 $CID"
[ "$(api /publishers/$VID)" = 200 ] && ok "200" || ng "不是 200"
[ "$(jq_ "d['publisher_id']")" = "$CID" ] && ok "變體 id 回正規社 id" || ng "沒有回正規社"
DB_N=$(q "select count(distinct e.book_id) from editions e join books b on b.book_id=e.book_id and b.is_published=1 join publishers p on p.publisher_id=e.publisher_id where coalesce(p.canonical_id,p.publisher_id)=$CID")
[ "$(jq_ "d['book_count']")" = "$DB_N" ] && ok "整社書數 $DB_N 跟庫一致" || ng "書數不一致 $(jq_ "d['book_count']") vs $DB_N"
LIST_N=$(curl -s "http://127.0.0.1:$PORT/api/books?publisher=$VID&per_page=1" | python3 -c "import json,sys;print(json.load(sys.stdin)['data']['total'])")
[ "$LIST_N" = "$DB_N" ] && ok "跟 /api/books?publisher= 的 total 一致" || ng "跟書單 total 不一致 $LIST_N"
[ "$(jq_ "d['year_from'] is not None and 1000 <= d['year_from'] <= d['year_to'] <= 2100")" = True ] && ok "年份範圍 $(jq_ "d['year_from']")–$(jq_ "d['year_to']")" || ng "年份範圍不對 $(jq_ "(d['year_from'], d['year_to'])")"
[ "$(jq_ "0 < len(d['top_categories']) <= 5")" = True ] && ok "常出分類 1～5 筆" || ng "常出分類筆數不對"
[ "$(api /publishers/999999999)" = 404 ] && ok "不存在的出版社 404" || ng "不存在的出版社不是 404"
[ "$(api /persons)" = 200 ] && ok "原本的 /api/persons 清單照舊" || ng "/api/persons 清單壞了"

kill $SRV 2>/dev/null
[ $CFG_COPIED = 1 ] && rm -f config/app.local.php
echo "結果：PASS $pass／FAIL $fail"
[ $fail -eq 0 ]
