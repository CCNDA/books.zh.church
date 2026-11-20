#!/bin/bash
# covers_to_r2「404 永久失敗不再重試」測試（Asana 1217982001242514；熊叔叔 2026-11-11 TG「甲 標成永久失敗、不再重試」）
# 不連資料庫、不連 R2、不連外：把 covers_to_r2.php 複製到暫存目錄，旁邊放假的 app/lib/db.php，
# 再用 php -S 開本機假來源站（/gone*.jpg 404、/forbid.jpg 403、/ok.jpg 200）和假 R2（PUT 一律 200）。
# 用法：bash tests/test_covers_dead.sh
set -u
cd "$(dirname "$0")/.."
D=$(mktemp -d); SRV=""
trap '[ -n "$SRV" ] && kill $SRV 2>/dev/null; rm -rf "$D"' EXIT
fail=0
ok() { if [ "$1" = 0 ]; then echo "✅ $2"; else echo "🔴 $2"; fail=$((fail+1)); fi; }

mkdir -p "$D/tools" "$D/app/lib" "$D/www"
cp tools/covers_to_r2.php "$D/tools/"
PORT=$((20000 + RANDOM % 20000))
BASE="http://127.0.0.1:$PORT"

# 假來源站＋假 R2：每個請求記一行到 hits.log
cat > "$D/www/router.php" <<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents(__DIR__ . '/../hits.log', $_SERVER['REQUEST_METHOD'] . ' ' . $p . "\n", FILE_APPEND);
if ($_SERVER['REQUEST_METHOD'] === 'PUT') { http_response_code(200); exit; }
if (str_starts_with($p, '/gone')) { http_response_code(404); echo 'not found'; exit; }
if ($p === '/forbid.jpg') { http_response_code(403); echo 'forbidden'; exit; }
if ($p === '/ok.jpg') { header('Content-Type: image/jpeg'); echo "\xFF\xD8" . str_repeat('x', 2000); exit; }
http_response_code(500);
PHP

# 假 db()／app_config()：列從 rows.json 讀，UPDATE 記到 updates.log
cat > "$D/app/lib/db.php" <<'PHP'
<?php
class FakeStmt {
    function __construct(private array $rows = [], private string $sql = '') {}
    function fetchAll() { return $this->rows; }
    function execute(array $p = []) {
        file_put_contents(dirname(__DIR__, 2) . '/updates.log', $this->sql . ' ' . json_encode($p) . "\n", FILE_APPEND);
        return true;
    }
}
class FakePdo {
    function query($sql) { return new FakeStmt(json_decode(file_get_contents(dirname(__DIR__, 2) . '/rows.json'), true)); }
    function prepare($sql) { return new FakeStmt([], preg_replace('/\s+/', ' ', $sql)); }
    function quote($s) { return "'" . $s . "'"; }
}
function db() { return new FakePdo(); }
function app_config(): array {
    return ['r2' => ['access_key_id' => 'test', 'secret_access_key' => 'test', 'endpoint' => getenv('FAKE_BASE') . '/r2',
                     'bucket' => 'b', 'prefix' => 'books/', 'public_url' => 'https://img.example']];
}
PHP

php -S "127.0.0.1:$PORT" "$D/www/router.php" >/dev/null 2>&1 & SRV=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$BASE/ok.jpg" && break; sleep 0.2; done
: > "$D/hits.log"

rows() {  # $1 = 第 1 本（media 11）的網址
  printf '[{"book_id":1,"media_id":11,"src":"%s"},{"book_id":2,"media_id":12,"src":"%s/forbid.jpg"},{"book_id":3,"media_id":13,"src":"%s/ok.jpg"}]' "$1" "$BASE" "$BASE" > "$D/rows.json"
}
run() { FAKE_BASE="$BASE" php "$D/tools/covers_to_r2.php" "$@" 2>&1; }
hits() { grep -c "^GET $1\$" "$D/hits.log"; }
DEAD="$D/storage/covers_dead.tsv"

# 1. 第一次跑：404 記進清單，403 不記，200 照常轉存
rows "$BASE/gone.jpg"
out=$(run)
echo "$out" | grep -q "完成:1 成功、2 失敗" ; ok $? "第一次：1 成功 2 失敗"
echo "$out" | grep -q "其中 1 本來源 404/410" ; ok $? "第一次：結尾說 1 本記入清單"
[ -f "$DEAD" ] && [ "$(wc -l < "$DEAD")" = 1 ] ; ok $? "清單只有 1 行"
cut -f1-3 "$DEAD" | grep -qx "11	$BASE/gone.jpg	404" ; ok $? "清單記 media 11、原網址、404"
! grep -q "^12	" "$DEAD" ; ok $? "403 那本沒進清單"
grep -q '"books/3.jpg"\|books\\/3.jpg\|:id":3' "$D/updates.log" ; ok $? "200 那本有寫回資料庫"

# 2. 第二次跑：404 那本不再下載，403 照舊重試
: > "$D/hits.log"
out=$(run)
echo "$out" | grep -q "另有 1 本來源已 404" ; ok $? "第二次：開頭說跳過 1 本"
[ "$(hits /gone.jpg)" = 0 ] ; ok $? "第二次：沒再打 /gone.jpg"
[ "$(hits /forbid.jpg)" = 1 ] ; ok $? "第二次：403 那本照舊重試"
[ "$(wc -l < "$DEAD")" = 1 ] ; ok $? "第二次：清單沒有重複加"

# 3. --retry-dead：清單忽略、再試一次
: > "$D/hits.log"
out=$(run --retry-dead)
[ "$(hits /gone.jpg)" = 1 ] ; ok $? "--retry-dead：有再打 /gone.jpg"
! echo "$out" | grep -q "另有" ; ok $? "--retry-dead：不顯示跳過"

# 4. 來源網址換了：自動再試
: > "$D/hits.log"
rows "$BASE/gone2.jpg"
out=$(run)
[ "$(hits /gone2.jpg)" = 1 ] ; ok $? "換網址：新網址有下載"
tail -1 "$DEAD" | cut -f1-2 | grep -qx "11	$BASE/gone2.jpg" ; ok $? "換網址：新網址也 404 → 清單加一行"
: > "$D/hits.log"; out=$(run)
[ "$(hits /gone2.jpg)" = 0 ] && echo "$out" | grep -q "另有 1 本" ; ok $? "換網址後再跑：以最後一行為準、跳過"

# 5. --dry-run：不下載、不寫清單
rm -f "$DEAD"; : > "$D/hits.log"
rows "$BASE/gone.jpg"
run --dry-run >/dev/null
[ ! -f "$DEAD" ] && [ ! -s "$D/hits.log" ] ; ok $? "--dry-run：不下載、不建清單"

echo; [ $fail = 0 ] && echo "全部通過" || echo "失敗 $fail 項"
exit $fail
