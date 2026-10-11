<?php
declare(strict_types=1);

/**
 * 非書籍守門（Asana 1219384830498351）。規則在 tools/lib_nonbook.php。
 *
 * 每日新品匯入後跑（crawler/daily_new.sh）：最近新增、仍上架的書，書名命中
 *   A 級詞 → 設 is_published = 0（不刪資料，可還原）並記一筆 hidden
 *   B 級詞 → 不動，記一筆 flagged
 * 記錄寫進 nonbook_guard_log（migration 2026-10-11_nonbook_guard_log.sql），
 * 小芯每天用唯讀帳號讀這張表，把清單傳給熊哥確認；誤判就把該書改回 is_published = 1。
 *
 * 用法：
 *   php tools/guard_nonbook.php                          # dry-run：最近 26 小時新增的上架書
 *   php tools/guard_nonbook.php --apply                  # 實際下架 A 級並寫紀錄（daily_new.sh 用這個）
 *   php tools/guard_nonbook.php --since="2026-10-01"     # 指定起點
 *   php tools/guard_nonbook.php --all --out=/tmp/nb.tsv  # 全站上架書盤點（只列不動，--all 不接受 --apply）
 *
 * 為什麼是 26 小時：每日排程 06:30 跑、偶爾跑到 08:xx，留兩小時重疊避免漏掉邊界上的書；
 * 重疊的書不會重複下架（只改 is_published = 1 的），紀錄有 UNIQUE(book_id, action) 也不會重複寫。
 */

require __DIR__ . '/../app/lib/db.php';
require_once __DIR__ . '/lib_nonbook.php';

$opt   = getopt('', ['apply', 'all', 'since::', 'out::']);
$apply = isset($opt['apply']);
$all   = isset($opt['all']);
if ($all && $apply) {
    fwrite(STDERR, "--all 只能盤點，不能和 --apply 一起用（全站下架要人看過清單再決定）\n");
    exit(2);
}
$since = $opt['since'] ?? null;
if ($since !== null && strtotime((string) $since) === false) {
    fwrite(STDERR, "--since 看不懂：{$since}\n");
    exit(2);
}

$pdo = db();
$where = 'b.is_published = 1';
$params = [];
if (!$all) {
    $where .= ' AND b.created_at >= :since';
    $params[':since'] = $since !== null
        ? date('Y-m-d H:i:s', (int) strtotime((string) $since))
        : date('Y-m-d H:i:s', time() - 26 * 3600);
}
$st = $pdo->prepare(
    "SELECT b.book_id, b.title, b.created_at,
            (SELECT e.source FROM editions e WHERE e.book_id = b.book_id ORDER BY e.edition_id LIMIT 1) AS source
       FROM books b
      WHERE {$where}
      ORDER BY b.book_id"
);
$st->execute($params);

$hits = ['A' => [], 'B' => []];
$checked = 0;
foreach ($st as $r) {
    $checked++;
    $c = classify_nonbook($r['title']);
    if ($c !== null) {
        $hits[$c['tier']][] = $r + $c;
    }
}

$hasLog = (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'nonbook_guard_log'"
)->fetchColumn();

$hidden = 0;
if ($apply) {
    if (!$hasLog) {
        fwrite(STDERR, "[非書籍守門] 找不到 nonbook_guard_log，先跑 migration 2026-10-11_nonbook_guard_log.sql；本次不下架\n");
        exit(1);
    }
    $down = $pdo->prepare('UPDATE books SET is_published = 0 WHERE book_id = :id AND is_published = 1');
    $log  = $pdo->prepare(
        'INSERT IGNORE INTO nonbook_guard_log (book_id, tier, word, action, title_snapshot, source)
         VALUES (:id, :tier, :word, :action, :title, :src)'
    );
    foreach (['A' => 'hidden', 'B' => 'flagged'] as $tier => $action) {
        foreach ($hits[$tier] as $h) {
            if ($tier === 'A') {
                $down->execute([':id' => $h['book_id']]);
                $hidden += $down->rowCount();
            }
            $log->execute([':id' => $h['book_id'], ':tier' => $tier, ':word' => $h['word'], ':action' => $action,
                           ':title' => mb_substr((string) $h['title'], 0, 500), ':src' => $h['source']]);
        }
    }
}

$scope = $all ? '全站上架書' : '自 ' . $params[':since'] . ' 起新增的上架書';
printf("非書籍守門（%s）：檢查 %d 本；A 級 %d 本%s；B 級 %d 本（只列不動）\n",
    $scope, $checked, count($hits['A']), $apply ? "，實際下架 {$hidden} 本" : '（dry-run，沒有下架）', count($hits['B']));
foreach (['A', 'B'] as $tier) {
    foreach ($hits[$tier] as $h) {
        printf("  [%s] %d　%s　（%s，詞：%s）\n", $tier, $h['book_id'], $h['title'], $h['source'] ?? '-', $h['word']);
    }
}
if ($hidden > 0) {
    echo "  還原（確認是書時）：UPDATE books SET is_published = 1 WHERE book_id IN ("
        . implode(',', array_column($hits['A'], 'book_id')) . ");\n";
}

if (isset($opt['out']) && $opt['out'] !== false) {
    $fh = fopen((string) $opt['out'], 'w');
    fwrite($fh, "tier\tbook_id\ttitle\tsource\tword\tcreated_at\n");
    foreach (['A', 'B'] as $tier) {
        foreach ($hits[$tier] as $h) {
            fwrite($fh, implode("\t", [$tier, $h['book_id'], str_replace("\t", ' ', (string) $h['title']),
                                       $h['source'] ?? '', $h['word'], $h['created_at']]) . "\n");
        }
    }
    fclose($fh);
    echo "  清單寫到 {$opt['out']}\n";
}
exit(0);
