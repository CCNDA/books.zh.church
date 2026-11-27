#!/usr/bin/env bash
# 作者頁 person.php／出版社頁 publisher.php：開發庫 books_dev 唯讀實測（Asana 1220588345978224）
# 用法：bash tests/test_pp_pages_dev.sh <repo 根目錄> [port]
set -u
W=$(cd "${1:?worktree}" && pwd); PORT=${2:-8844}; cd "$W"
q(){ sudo mariadb -N books_dev -e "$1" 2>/dev/null; }
[ "$(q 'select database()')" = books_dev ] || { echo "不是 books_dev，停"; exit 2; }
CFG_COPIED=0
if [ ! -f config/app.local.php ]; then cp /srv/dev/books.zh.church/config/app.local.php config/app.local.php; CFG_COPIED=1; fi
pass=0; fail=0; ok(){ echo "  PASS $1"; pass=$((pass+1)); }; ng(){ echo "  FAIL $1"; fail=$((fail+1)); }
php -S 127.0.0.1:$PORT -t public > /tmp/pppage_$PORT.log 2>&1 & SRV=$!; sleep 1
get(){ curl -s -o /tmp/pppage_body -w '%{http_code}' "http://127.0.0.1:$PORT$1"; }
has(){ grep -qF -- "$1" /tmp/pppage_body; }

PID=$(q "select bp.person_id from book_persons bp join books b on b.book_id=bp.book_id and b.is_published=1 group by bp.person_id order by count(distinct bp.book_id) desc limit 1")
PNAME=$(q "select name from persons where person_id=$PID")
N=$(q "select count(distinct bp.book_id) from book_persons bp join books b on b.book_id=bp.book_id and b.is_published=1 where bp.person_id=$PID")
echo "作者 $PID（$N 本）"
[ "$(get "/person.php?id=$PID")" = 200 ] && ok "200" || ng "不是 200"
has "<h1 class=\"serif\">$PNAME</h1>" && ok "h1 是作者名（伺服器端輸出）" || ng "h1 不對"
has "<b>$N</b> 本書" && ok "書數 $N" || ng "書數不對"
has "<link rel=\"canonical\" href=\"https://books.zh.church/person.php?id=$PID\">" && ok "canonical" || ng "canonical 不對"
has '"@type":"Person"' && ok "JSON-LD Person" || ng "沒有 JSON-LD"
C=$(grep -o 'href="/book/[0-9]*"' /tmp/pppage_body | wc -l); [ "$C" -ge 1 ] && [ "$C" -le 24 ] && ok "列出 $C 本（≤24）" || ng "書單筆數 $C"
has "href=\"/?person=$PID\">看全部 $N 本" && ok "看全部連到首頁篩選" || ng "沒有看全部連結"
has 'href="/person.php?id=' && ok "合作者連到作者頁" || ng "沒有合作者連結"
has '簡介' && ng "出現簡介字樣" || ok "沒有簡介區塊"
[ "$(get "/person.php?id=999999999")" = 404 ] && ok "不存在 404" || ng "不存在不是 404"
has 'noindex' && ok "404 頁 noindex" || ng "404 頁沒 noindex"
[ "$(get "/person.php?id=abc")" = 404 ] && ok "非數字 404" || ng "非數字不是 404"

read -r VID CID <<< "$(q "select v.publisher_id, v.canonical_id from publishers v join editions e on e.publisher_id=v.publisher_id join books b on b.book_id=e.book_id and b.is_published=1 where v.canonical_id is not null and v.canonical_id<>v.publisher_id group by v.publisher_id order by count(*) desc limit 1")"
CNAME=$(q "select name_zh from publishers where publisher_id=$CID")
echo "出版社 變體 $VID → 正規 $CID"
[ "$(get "/publisher.php?id=$VID")" = 200 ] && ok "200" || ng "不是 200"
has "<h1 class=\"serif\">$CNAME</h1>" && ok "h1 是正規社名" || ng "h1 不對"
has "<link rel=\"canonical\" href=\"https://books.zh.church/publisher.php?id=$CID\">" && ok "變體頁 canonical 指向正規社" || ng "canonical 不對"
has "href=\"/?publisher=$CID&amp;category=" && ok "常出分類連到首頁篩選" || ng "分類連結不對"
has '出版年份 <b>' && ok "有出版年份" || ng "沒有出版年份"
has '"@type":"Organization"' && ok "JSON-LD Organization" || ng "沒有 JSON-LD"

# 逃逸：名字含 HTML 的作者（開發庫找不到就跳過，不寫入）
XID=$(q "select person_id from persons where name like '%<%' or name like '%&%' or name like '%\"%' limit 1")
if [ -n "$XID" ]; then get "/person.php?id=$XID" >/dev/null; grep -q '<h1 class="serif">[^<]*[<"][^<]*</h1>' /tmp/pppage_body && ng "名字沒逃逸" || ok "名字有逃逸（person $XID）"; else echo "  (開發庫沒有含 < & \" 的作者名，逃逸靠 ent_e)"; fi

kill $SRV 2>/dev/null
[ $CFG_COPIED = 1 ] && rm -f config/app.local.php
echo "結果：PASS $pass／FAIL $fail"
[ $fail -eq 0 ]
