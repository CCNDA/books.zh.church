<?php
declare(strict_types=1);

/**
 * 「後台恢復上架的書，程式不要再把它下架」（Asana 1219387164843657）。
 *
 * 熊哥 2026-10-11：「已下架的也要能有位置可以讓他上架，怕誤下架的時候可以恢復」。
 * 問題：每日新品會重跑 16 支分類對映工具（apply_*_categories.php、classify_categories.php），
 *   它們每天對**全部**書重新判斷，對映到非書分類就 is_published = 0 —— 後台恢復的書隔天早上又被下架。
 *   guard_nonbook.php 的 26 小時窗口也一樣。
 * 做法：所有「設 is_published = 0」的 UPDATE 都接上 keep_published_sql()，
 *   跳過「在 nonbook_guard_log 裡最後一次判定是 restored」的書。
 *   熊哥之後又按「下架」（confirmed 比 restored 新）就不再保護。
 *
 * ★ 紀錄表還不存在（migration 還沒跑）時回空字串，舊流程照常。
 * ★ 新增會下架書的工具時，UPDATE 一定要接這個，否則後台的「恢復上架」會被它蓋掉。
 *
 * 用法：$pdo->prepare('UPDATE books SET is_published = 0 WHERE book_id = :bid' . keep_published_sql());
 *       別名查詢：'... FROM books b WHERE ...' . keep_published_sql('b.book_id')
 */
function keep_published_sql(string $col = 'books.book_id'): string
{
    static $hasLog = null;
    if ($hasLog === null) {
        $hasLog = (bool) db()->query(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'nonbook_guard_log'"
        )->fetchColumn();
    }
    if (!$hasLog) {
        return '';
    }
    return " AND NOT EXISTS (SELECT 1 FROM nonbook_guard_log kp_r
                 WHERE kp_r.book_id = {$col} AND kp_r.action = 'restored'
                   AND NOT EXISTS (SELECT 1 FROM nonbook_guard_log kp_c
                                    WHERE kp_c.book_id = {$col} AND kp_c.action = 'confirmed'
                                      AND kp_c.reviewed_at > kp_r.reviewed_at))";
}
