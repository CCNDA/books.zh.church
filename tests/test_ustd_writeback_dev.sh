#!/usr/bin/env bash
# 天道北美寫回：開發庫 books_dev 實測（假報告 3 筆：改價、第一次讀到有貨、下架）→ 套用 → 再跑一次要 0 句 → 還原
set -u
W=$(cd "${1:?worktree}" && pwd); cd "$W"
q(){ sudo mariadb -N books_dev -e "$1" 2>/dev/null; }
[ "$(q 'select database()')" = books_dev ] || { echo "不是 books_dev，停"; exit 2; }
T=$(mktemp -d); ts=$(date +%Y%m%d-%H%M%S)
sudo sh -c "mariadb-dump books_dev formats_prices links > /srv/dev/backup/books_dev_uw_e2e_$ts.sql" && echo "備份 /srv/dev/backup/books_dev_uw_e2e_$ts.sql"
mapfile -t E < <(q "select e.edition_id, e.source_url from editions e join formats_prices f on f.edition_id=e.edition_id and f.currency='USD' join links l on l.edition_id=e.edition_id and l.link_type='buy' where e.source='ustiendao' group by e.edition_id having count(distinct f.format_id)=1 order by e.edition_id limit 3")
[ ${#E[@]} -eq 3 ] || { echo "開發庫找不到 3 本合用的天道北美，停"; exit 2; }
read -r E1 U1 <<< "${E[0]}"; read -r E2 U2 <<< "${E[1]}"; read -r E3 U3 <<< "${E[2]}"
P1=$(q "select price from formats_prices where edition_id=$E1")
NEWP=$(python3 -c "print(f'{float(\"${P1:-0}\")+1:.2f}')")
cat > $T/report.json <<EOF
{"checked_at":"e2e","limit":0,"stopped":null,"snapshot_size":9999,"counts":{},
 "changes":[{"kind":"price","url":"$U1","old":"$P1","new":"$NEWP"},
            {"kind":"availability","url":"$U2","old":null,"new":"InStock"},
            {"kind":"gone","url":"$U3"}]}
EOF
echo "== 第一次"; php tools/ustiendao_writeback.php $T/report.json $T/run1.sql $T/review1.tsv
grep -c '^UPDATE' $T/run1.sql
sudo mariadb books_dev < $T/run1.sql
echo "套用後：E1 價格 $(q "select price from formats_prices where edition_id=$E1")（期望 $NEWP）｜E2 有沒有貨 $(q "select availability from formats_prices where edition_id=$E2")｜E3 $(q "select availability from formats_prices where edition_id=$E3")｜E3 note 含標記 $(q "select count(*) from links where edition_id=$E3 and link_type='buy' and note like '%[天道北美已下架]%'")"
echo "== 第二次（同一份報告）"; php tools/ustiendao_writeback.php $T/report.json $T/run2.sql $T/review2.tsv | grep 產生
echo "== 重新上架（E3 不在下架名單）"; cat > $T/report3.json <<EOF
{"checked_at":"e2e","limit":0,"stopped":null,"snapshot_size":9999,"counts":{},"changes":[]}
EOF
php tools/ustiendao_writeback.php $T/report3.json $T/run3.sql $T/review3.tsv | grep -E "產生|availability|link_note"
sudo mariadb books_dev < $T/run3.sql
echo "重新上架後：E3 有沒有貨 $(q "select ifnull(availability,'NULL') from formats_prices where edition_id=$E3")｜note 含標記 $(q "select count(*) from links where edition_id=$E3 and link_type='buy' and note like '%[天道北美已下架]%'")"
echo "== 還原備份"; sudo sh -c "mariadb books_dev < /srv/dev/backup/books_dev_uw_e2e_$ts.sql" && echo "還原完成：E1 價格 $(q "select price from formats_prices where edition_id=$E1")（原 $P1）"
rm -rf $T
