<?php
declare(strict_types=1);
/**
 * 天道北美全量對照 → 書目網寫回：讀報告＋資料庫，產一支 SQL 檔（本程式不寫庫）
 * 設計 docs/ustiendao-writeback-design.md；規則 tools/lib_ustd_writeback.php；Asana 1220919560670331
 *
 * 用法（主機 SSH，在 /home/ubuntu/books 下）：
 *   php tools/ustiendao_writeback.php <報告.json> <輸出.sql> [人審.tsv]
 * 正式庫寫入一律：sudo ccnda-db-run <輸出.sql>（先試跑、筆數對）→ 再加 --commit
 * 異常閘擋下時不產 SQL，exit 3。
 */
require __DIR__ . '/../app/lib/db.php';
require __DIR__ . '/lib_ustd_writeback.php';

[$_, $reportPath, $sqlPath] = $argv + [null, null, null];
$reviewPath = $argv[3] ?? ($sqlPath ? preg_replace('/\.sql$/', '', $sqlPath) . '_review.tsv' : null);
if (!$reportPath || !$sqlPath) { fwrite(STDERR, "用法：php tools/ustiendao_writeback.php <報告.json> <輸出.sql> [人審.tsv]\n"); exit(2); }
$report = json_decode((string) file_get_contents($reportPath), true);
if (!is_array($report) || !isset($report['changes'])) { fwrite(STDERR, "報告讀不到或格式不對：$reportPath\n"); exit(2); }

// 天道北美的 edition（source_url、ISBN13、價格列、購買連結）
$pdo = db();
$eds = [];
foreach ($pdo->query("SELECT edition_id, source_url FROM editions WHERE source = 'ustiendao'") as $r) {
    $eds[(int) $r['edition_id']] = ['url' => $r['source_url'], 'isbn13' => null, 'formats' => [], 'links' => []];
}
foreach ($pdo->query("SELECT i.edition_id, i.id_value FROM identifiers i JOIN editions e ON e.edition_id = i.edition_id
                       WHERE e.source = 'ustiendao' AND i.id_type = 'ISBN13'") as $r) {
    if (isset($eds[(int) $r['edition_id']])) $eds[(int) $r['edition_id']]['isbn13'] = $r['id_value'];
}
foreach ($pdo->query("SELECT f.format_id, f.edition_id, f.price, f.currency, f.availability FROM formats_prices f
                       JOIN editions e ON e.edition_id = f.edition_id WHERE e.source = 'ustiendao'") as $r) {
    $eds[(int) $r['edition_id']]['formats'][(int) $r['format_id']] = ['price' => $r['price'], 'currency' => $r['currency'], 'availability' => $r['availability']];
}
foreach ($pdo->query("SELECT l.link_id, l.edition_id, l.note FROM links l JOIN editions e ON e.edition_id = l.edition_id
                       WHERE e.source = 'ustiendao' AND l.link_type = 'buy'") as $r) {
    $eds[(int) $r['edition_id']]['links'][(int) $r['link_id']] = ['note' => $r['note']];
}

$plan = ustd_plan($report, $eds);
printf("天道北美 edition %d 個；報告 changes %d 筆\n", count($eds), count($report['changes']));
if ($plan['halt'] !== null) { echo "🔴 異常閘擋下：{$plan['halt']}\n   不產 SQL，請人看過報告。\n"; exit(3); }

// 人審清單
if ($reviewPath) {
    $fh = fopen($reviewPath, 'w');
    fwrite($fh, "why\tkind\tedition_id\tisbn\told\tnew\turl\n");
    foreach (array_merge($plan['review'], array_map(fn($c) => $c + ['why' => '對不到 edition'], $plan['unmatched'])) as $r) {
        fwrite($fh, implode("\t", array_map(fn($v) => str_replace(["\t", "\n"], ' ', (string) $v),
            [$r['why'] ?? '', $r['kind'] ?? '', $r['edition_id'] ?? '', $r['isbn'] ?? '', $r['old'] ?? '', $r['new'] ?? '', $r['url'] ?? ''])) . "\n");
    }
    fclose($fh);
}

// SQL（只 UPDATE 有變的列；每句都帶舊值條件，跑第二次就是 0 列）
$q = fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v);
$eq = fn($col, $v) => $v === null ? "$col IS NULL" : "$col = " . $q($v);
$sql = ["-- 天道北美全量對照寫回（報告 " . basename($reportPath) . "，checked_at " . ($report['checked_at'] ?? '?') . "）",
        "-- 產生：tools/ustiendao_writeback.php；Asana 1220919560670331；冪等（帶舊值條件）",
        "-- 先 sudo ccnda-db-run 試跑看筆數，對了再加 --commit",
        "SET @ustd_n = 0;"];
// ccnda-db-run 不印影響列數 → 每句後面累加 ROW_COUNT()，最後印出來跟預期比
$cnt = " SET @ustd_n = @ustd_n + ROW_COUNT();";
foreach ($plan['ops'] as $o) {
    if ($o['type'] === 'price') $sql[] = "UPDATE formats_prices SET price = {$q($o['new'])} WHERE format_id = {$o['format_id']} AND {$eq('price', $o['old'])};$cnt";
    elseif ($o['type'] === 'availability') $sql[] = "UPDATE formats_prices SET availability = {$q($o['new'])} WHERE format_id = {$o['format_id']} AND {$eq('availability', $o['old'])};$cnt";
    elseif ($o['type'] === 'link_note') $sql[] = "UPDATE links SET note = {$q($o['new'])} WHERE link_id = {$o['link_id']} AND {$eq('note', $o['old'])};$cnt";
}
$sql[] = sprintf("SELECT @ustd_n AS actual_rows, %d AS expected_rows, IF(@ustd_n = %d, 'OK', 'MISMATCH') AS result;", count($plan['ops']), count($plan['ops']));
// 小布 11/26：不符就直接出錯（子查詢多列 → 1242），沒看結果就 --commit 也會中止整個交易
$sql[] = sprintf("SELECT IF(@ustd_n = %d, 'OK', (SELECT 1 UNION SELECT 2)) AS guard;", count($plan['ops']));
// 小布 11/26：試跑實際筆數要等於這個數；少了＝產 SQL 之後有列被改過 → 不 commit、用當下的庫重產
array_splice($sql, 3, 0, [sprintf('-- 預期 %d 列（每句剛好 1 列；試跑少於這個數就不 commit、重產）', count($plan['ops']))]);
file_put_contents($sqlPath, implode("\n", $sql) . "\n");

echo "異常閘：", json_encode($plan['gate'] ?? [], JSON_UNESCAPED_UNICODE), "\n";
printf("產生 %d 句 UPDATE → %s\n", count($plan['ops']), $sqlPath);
foreach ($plan['counts'] as $k => $n) echo "  $k $n\n";
printf("人審 %d、對不到 %d → %s\n", count($plan['review']), count($plan['unmatched']), $reviewPath ?? '（沒寫）');
