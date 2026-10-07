<?php
declare(strict_types=1);
/**
 * 年份篩選後備層守門(唯讀,不寫任何資料)。
 *
 * ════════ 為什麼需要這支 ════════
 * 2026-09-28(v1.15.1)API 的出版年份篩選由
 *     COALESCE((SELECT MAX(ey.publish_date) FROM editions ey WHERE …), b.publish_date)
 * 改成 JOIN 一個先彙總、先用 HAVING 篩過的 editions 衍生表(COUNT 5,140 ms → 167 ms)。
 *
 * 新寫法**只看 editions.publish_date,沒有 books.publish_date 那層後備**。
 * 它與舊寫法在當時的資料上輸出完全相同,靠的是一個事實:
 *     「沒有任何 edition 有 publish_date、但 books 平面欄有日期」的上架書 = 0 本
 * 而那個 0 來自 tools/import.php 的行為(parse_date() 回傳 [books 用, edition 用]
 * 兩個值,要嘛都有值要嘛都是 null,同一次匯入同時寫入兩張表),
 * **不是資料庫層的約束**。
 *
 * ★ 哪天有工具只更新 books.publish_date(手動 SQL、單表 reparse、新來源的匯入路徑…),
 *   那些書就會從年份篩選裡**靜默消失** —— 網站照跑、log 全綠、書目頁還是照常顯示
 *   出版年(v_book_list 有後備),使用者看得到年份卻篩不到。沒有人會來回報這種事。
 *
 * ★ 這支刻意**不修資料、不改 API**:它只負責讓那件事「有徵兆」。
 *   真要處理時有兩條路,都要人決定:
 *     (a) 把日期補寫進 editions.publish_date(治本)
 *     (b) API 年份條件加回後備層(治標,會把 5 秒拿回來)
 *
 * 用法:
 *   php tools/check_year_fallback.php            # 正常:印一行 0 本,exit 0
 *   php tools/check_year_fallback.php --limit=50 # 有問題時要列幾筆(預設 20)
 *
 * 結束碼:0 = 正常(0 本);1 = 發現後備層書(daily_new.sh 會記成 [錯誤])。
 *
 * 相關:Asana 1218643959919910、對帳守門員 1218194268650259、
 *       database/migrations/2026-09-28_year_fallback_guard.sql(同一條查詢的 SQL 版)
 */

require __DIR__ . '/../app/lib/db.php';

$opt   = getopt('', ['limit::']);
$limit = max(1, (int) ($opt['limit'] ?? 20));

$cond = "b.is_published = 1
         AND b.publish_date IS NOT NULL
         AND b.publish_date <> ''
         AND NOT EXISTS (SELECT 1 FROM editions ey
                          WHERE ey.book_id = b.book_id
                            AND ey.publish_date IS NOT NULL)";

$n = (int) db()->query("SELECT COUNT(*) FROM books b WHERE $cond")->fetchColumn();

if ($n === 0) {
    echo "年份後備層守門:0 本 ✓(2026-09-28 基準值同為 0)\n";
    exit(0);
}

// ★ 非 0 才會走到這裡。把書列出來,不要只印一個數字 ——
//   「有幾本」不足以判斷,要看是哪些書、哪個來源,才知道是哪支工具寫出來的。
echo "★★ 年份後備層守門:發現 {$n} 本「有平面欄日期但沒有任何 edition 日期」的上架書。\n";
echo "   這些書在 API 的年份篩選(year_from/year_to)裡**找不到**,\n";
echo "   但書目頁仍會顯示出版年 —— 使用者看得到年份卻篩不到,不會有人回報。\n";
echo "   成因:有工具只更新了 books.publish_date,沒有一併寫 editions.publish_date。\n";
echo "   處置見本檔開頭註解(a 補資料 / b API 加回後備層),兩條都要人決定。\n\n";

$st = db()->prepare(
    "SELECT b.book_id, b.source, b.publish_date, b.title
       FROM books b
      WHERE $cond
      ORDER BY b.book_id DESC
      LIMIT :lim"
);
$st->bindValue(':lim', $limit, PDO::PARAM_INT);
$st->execute();

printf("%-9s %-12s %-12s %s\n", 'book_id', 'source', '平面欄日期', '書名');
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("%-9d %-12s %-12s %s\n",
        (int) $r['book_id'],
        (string) $r['source'],
        (string) $r['publish_date'],
        (string) $r['title']);
}
if ($n > $limit) {
    echo "…(僅列前 {$limit} 筆,共 {$n} 筆;要看更多加 --limit=N)\n";
}

// 來源分布 —— 集中在單一來源就表示是那支匯入/reparse 工具的問題,不是全站性的。
echo "\n來源分布:\n";
foreach (db()->query("SELECT COALESCE(b.source,'(空)') AS src, COUNT(*) AS n
                        FROM books b WHERE $cond
                       GROUP BY b.source ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("  %-14s %d\n", (string) $r['src'], (int) $r['n']);
}

exit(1);
