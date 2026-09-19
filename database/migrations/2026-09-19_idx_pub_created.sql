-- =============================================================
-- 2026-09-19  books 複合索引 idx_pub_created(is_published, created_at, book_id)
-- Asana: 1216467657931851(M1-B 檢索完善)/ 檢核點 2:1216467831523241
--
-- ⚠️ **本索引已於 2026-09-19 在正式庫建立完成**
--    (由 2026-09-19_search_perf_round5.sql 第三部分執行,ALTER 耗時 4.93 s)。
--    本檔的作用是**補登記錄**:讓 schema_migrations 與文件對得上,不是要再跑一次。
--    第一段是冪等檢查,已存在就不會重建。
--
-- 【為什麼建:9/16 的判斷前提是錯的】
--   2026-09-16_m1b_search_text_and_indexes.sql 第 65-70 行寫:
--     「刻意不建 books.is_published 單欄索引…is_published 幾乎全為 1
--       (65,290 中僅數百筆為 0),基數極低,單索引選擇性太差」
--   9/19 實查:全站 65,304、上架 54,530 → **下架 10,771 本(16.5%)**,
--   「僅數百筆」差了兩個數量級。
--   當時留的但書是「若之後量到需要,正確做法是放進複合索引的前導欄,
--   而那要看實際查詢形狀再決定」—— 現在形狀確定了:
--     WHERE is_published = 1 ORDER BY created_at DESC, book_id DESC LIMIT n
--   這正是複合索引能一次吃掉「過濾 + 排序 + 取前 n 列」的形狀。
--
-- 【實測(SHOW PROFILES,單位秒)】
--   無關鍵字瀏覽第一頁   建索引前 5.004  →  建索引後 **0.00041**
--   EXPLAIN             ALL + Using filesort  →  ref + key=idx_pub_created + Using index
--   ⇒ 從全表掃描 511 MB 變成純索引取前 20 列。
--
-- ★ 這條路徑是首頁與所有「不帶關鍵字的瀏覽/分類/來源篩選」在走的,
--   也就是說**首頁瀏覽一直都是 5 秒**,與搜尋無關,只是從來沒有人量過它。
-- =============================================================


-- ═══ 1. 冪等建立(已存在就跳過) ═══
-- MariaDB 10.11 支援 IF NOT EXISTS
ALTER TABLE `books` ADD INDEX IF NOT EXISTS `idx_pub_created`
  (`is_published`, `created_at`, `book_id`);

ANALYZE TABLE `books`;


-- ═══ 2. 驗證 ═══
SELECT 'POST-1' AS q, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'books'
   AND INDEX_NAME = 'idx_pub_created'
 ORDER BY SEQ_IN_INDEX;
-- 預期三列:is_published(1) / created_at(2) / book_id(3)

EXPLAIN SELECT b.book_id FROM books b
 WHERE b.is_published = 1
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;
-- 預期:key = idx_pub_created、Extra 含 "Using index"、**不可再有 "Using filesort"**

-- 順便把當初判斷錯的那個數字留在庫裡有據可查
SELECT 'POST-2' AS q, COUNT(*) AS 全站,
       SUM(is_published = 1) AS 上架,
       SUM(is_published = 0) AS 下架
  FROM books;


-- ═══ 3. 登錄 schema_migrations ═══
INSERT INTO `schema_migrations` (`version`, `applied_at`, `note`) VALUES
  ('2026-09-19_idx_pub_created.sql', NOW(),
   'books(is_published, created_at, book_id) 複合索引;瀏覽第一頁 5.004s -> 0.0004s;更正 9/16「僅數百筆下架」的錯誤前提(實為 10,771 本)')
ON DUPLICATE KEY UPDATE `applied_at` = NOW();


-- ═══ 還原 ═══
-- ALTER TABLE `books` DROP INDEX `idx_pub_created`;
-- DELETE FROM `schema_migrations` WHERE `version` = '2026-09-19_idx_pub_created.sql';
