-- =============================================================
-- 2026-09-16  M1-B 第一步:books.search_text 欄位 + 搜尋/篩選索引
-- Asana: 1216467657931851(M1-B 檢索完善)/ 檢核點 2:1216467831523241
--
-- 承 8/17 已定案的設計(當時草稿未進 repo,依記憶中的決策重寫):
--   ★ 不用 FULLTEXT —— MariaDB 無 ngram 分詞,中文無效。
--   ★ 搜尋慢的真因是 api/index.php 的 get_books() 對 v_book_list 的
--     v.author / v.publisher / v.translator 做 LIKE,而那三欄在 view 裡是
--     **相關子查詢 + GROUP_CONCAT** → 每一列都要執行子查詢,65,290 列 × 6 個。
--     解法是把可搜文字反正規化到 books.search_text 單欄,改查單欄。
--
-- ★★ 執行順序鐵律(順序錯會讓全站搜尋回 0 筆):
--   1. 本 migration(建欄位 + 索引)
--   2. tools/build_search_text.php --all(填值,分批)
--   3. 回查 search_text 覆蓋率達標
--   4. **最後**才改 api/index.php 讓 q 改查 search_text
--   在第 3 步確認之前改程式 = 搜尋查一個空欄位 = 全站搜不到任何書。
--
-- 執行:熊哥本機 Navicat。本檔不需 FTP。
-- =============================================================

-- ═══ 前置:先看現況(唯讀) ═══
SELECT 'PRE-1' AS q, COUNT(*) AS 全站書目種數,
       SUM(is_published = 1) AS 已上架
  FROM books;

SELECT 'PRE-2' AS q, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('books','editions','media')
 ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;


-- ═══ 第一部分:search_text 欄位 ═══
-- ★ 必須明寫 COLLATE:本資料庫預設排序規則是 utf8mb4_general_ci,
--   而既有 32 張表全是 utf8mb4_unicode_ci。不寫會拿到 general_ci,
--   之後與其他欄位比對會噴 Illegal mix of collations。(2026-09-16 實查發現)
ALTER TABLE `books`
  ADD COLUMN `search_text` MEDIUMTEXT
      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
      COMMENT '反正規化搜尋欄(書名+副標+作者+譯者+出版社+摘要+關鍵字+ISBN);由 tools/build_search_text.php 維護'
      AFTER `keywords`;

-- ⚠️ 不對 search_text 建索引,這是刻意的:
--    (a) MEDIUMTEXT 建普通索引必須指定前綴長度,而搜尋是 LIKE '%關鍵字%',
--        前綴索引對中間比對無效;
--    (b) FULLTEXT 在 MariaDB 對中文無分詞,無效。
--    ⇒ search_text 的價值不是「走索引」,而是「把 6 個相關子查詢換成 1 次單欄掃描」。
--       即使仍是全表掃描,成本差一個數量級。


-- ═══ 第二部分:篩選用索引 ═══

-- (1) 年份篩選:books.publish_date 是 varchar(10) 且混 YYYY / YYYY-MM / YYYY-MM-DD
--     ⇒ 查詢一律用前綴比對(publish_date >= '2020' AND publish_date < '2026'),
--        **不可用 YEAR() 等函式包欄位**,否則索引失效。
ALTER TABLE `books` ADD INDEX `idx_publish_date` (`publish_date`);

-- (2) 來源篩選:editions.source 目前無索引,而家數統計與來源篩選全靠它
--     複合 (book_id, source) 同時服務「某書有哪些來源」與「某來源有哪些書」
ALTER TABLE `editions` ADD INDEX `idx_ed_book_source` (`book_id`, `source`);

-- (3) 年份也可能走 editions.publish_date(view 取最新版的日期)
ALTER TABLE `editions` ADD INDEX `idx_ed_book_pubdate` (`book_id`, `publish_date`);

-- ⚠️ **刻意不建 books.is_published 單欄索引。**
--    我 2026-09-16 先前把它列為「缺口」,那個判斷是錯的:
--    is_published 幾乎全為 1(65,290 中僅數百筆為 0),基數極低,
--    單欄索引選擇性太差,最佳化器會直接忽略。
--    若之後量到需要,正確做法是把它放進複合索引的前導欄(如 (is_published, category_id)),
--    而那要看實際查詢形狀再決定,不預先加。


-- ═══ 第三部分:刪除冗餘索引(可逆,重建指令附在下方) ═══
-- ⚠️ 這兩段可選。若不放心可先跳過,不影響功能,只影響寫入速度與空間。

-- (1) editions.publisher_id 有兩個功能相同的索引
--     idx_publisher(原有)與 idx_editions_publisher(2026-07-16 加入時前者已存在)
ALTER TABLE `editions` DROP INDEX `idx_editions_publisher`;
-- 還原:ALTER TABLE `editions` ADD INDEX `idx_editions_publisher` (`publisher_id`);

-- (2) media.idx_edition(edition_id) 是 idx_media_cover(edition_id, media_type, is_primary, media_id)
--     的最左前綴,完全冗餘
ALTER TABLE `media` DROP INDEX `idx_edition`;
-- 還原:ALTER TABLE `media` ADD INDEX `idx_edition` (`edition_id`);


-- ═══ 第四部分:更新統計(★ 不做這步,後面量效能都是假的) ═══
-- 2026-09-16 實查:books.TABLE_ROWS 顯示 45,924,實際 COUNT(*) 是 65,290,
-- 索引 CARDINALITY 也停在舊值 → 最佳化器在拿過期統計選索引。
ANALYZE TABLE `books`, `editions`, `identifiers`, `book_subjects`, `book_persons`,
              `formats_prices`, `links`, `media`, `persons`, `publishers`, `subjects`;


-- ═══ ★ 驗證(務必執行) ═══

-- (1) 欄位建起來了,且 collation 正確(應為 utf8mb4_unicode_ci)
SELECT 'POST-1' AS q, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
       CHARACTER_SET_NAME, COLLATION_NAME
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'books' AND COLUMN_NAME = 'search_text';

-- (2) 三個新索引存在、兩個冗餘索引已消失
SELECT 'POST-2' AS q, TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE()
   AND INDEX_NAME IN ('idx_publish_date','idx_ed_book_source','idx_ed_book_pubdate',
                      'idx_editions_publisher','idx_edition')
 ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
-- 預期:前三個各一列(或兩列,複合);後兩個 **不應出現**

-- (3) 統計已更新(books 的 CARDINALITY 應接近 65,290,不再是 45,924)
SELECT 'POST-3' AS q, TABLE_ROWS FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'books';

-- (4) search_text 目前應該全是 NULL(還沒填值)
SELECT 'POST-4' AS q,
       COUNT(*) AS 總數,
       SUM(search_text IS NULL) AS 尚未填值_應等於總數
  FROM books;


-- ═══ 登錄 schema_migrations(需先建表) ═══
INSERT INTO `schema_migrations` (`version`, `applied_at`, `note`) VALUES
  ('2026-09-16_m1b_search_text_and_indexes.sql', NOW(),
   'M1-B:search_text 欄(不建索引,理由見檔內)+ publish_date/editions 兩複合索引;刪兩個冗餘索引;ANALYZE TABLE')
ON DUPLICATE KEY UPDATE `applied_at` = NOW();


-- =============================================================
-- ★ 下一步(不要跳過順序)
--   1. 本檔執行完、四段驗證通過
--   2. 我產出 tools/build_search_text.php → FTP 到主機 → 跑 --all(分批 2000)
--   3. 回查覆蓋率:SELECT COUNT(*) FROM books WHERE search_text IS NULL;  應趨近 0
--   4. **確認第 3 步之後**,才改 api/index.php 讓 q 查 search_text
--      (提前改 = 查空欄位 = 全站搜尋回 0 筆)
--   5. daily_new.sh 加一行 build_search_text.php --missing,讓新書自動有值
-- =============================================================
