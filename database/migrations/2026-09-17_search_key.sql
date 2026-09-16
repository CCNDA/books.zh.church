-- =============================================================
-- 2026-09-17  books.search_key:不含摘要的快速搜尋欄
-- Asana: 1216467657931851(M1-B)/ 檢核點 2:1216467831523241
--
-- ═══ 為什麼需要(實測數據) ═══
-- 2026-09-17 線上實測:搜一個關鍵字要 5–17 秒,使用者會以為壞了。
-- EXPLAIN 指出真因,而且**不是** v_book_list 具體化(view 其實是 MERGE 的,
-- 主查詢沒有 DERIVED / Using temporary,那些 DEPENDENT SUBQUERY 都走索引 rows=1):
--
--   PRIMARY  b  type=ALL  key=NULL  rows=46079  Using where
--
-- 真因是 `search_text LIKE '%…%'` 無法用任何索引 → 逐列掃過整張 books。
-- 而 search_text 平均 463 字(摘要佔絕大部分),
-- 65,290 列 × 463 字 × 約 3 bytes ≈ **90 MB 文字要逐列比對** → 5 秒是這個資料量的下限。
--
-- 對照實測:
--   COUNT 走 view   5,644 ms
--   COUNT 走 books  4,946 ms   ← 只快 12%,證明瓶頸不在 view
--   資料列 LIMIT 20 9,550 ms
--
-- ═══ 解法 ═══
-- 拆兩欄。摘要是那 463 字的絕大部分:
--   不含摘要平均 **59.02** 字、最長 533 字(實查)
--   含摘要平均 463.34 字
--   → 比值 7.85 倍,預期掃描成本 4,946 / 7.85 ≈ **630 ms**
--
--   search_key   不含摘要 → q 的**預設**搜尋目標(快)
--   search_text  含摘要   → 僅在 API 帶 deep=1 時搜(慢但完整)
--
-- ★ 為什麼是 VARCHAR 而不是 TEXT:VARCHAR 存在 row 內(inline),
--   TEXT 可能存到 off-page,掃描要多讀 overflow page。這一欄的用途就是被掃,
--   所以刻意選 VARCHAR。1500 字元對實測最長 533 字留了近 3 倍餘裕。
--
-- ★ 為什麼不建索引:`LIKE '%關鍵字%'` 中間比對用不到 B-tree 前綴索引;
--   FULLTEXT 在 MariaDB 對中文無分詞(無 ngram parser)。
--   要真正走索引得自建 bigram 分詞欄 + FULLTEXT,那是檢核點 2 之後的優化票。
--
-- ★ 必須明寫 COLLATE:本 DB 預設排序規則是 utf8mb4_general_ci,
--   而既有表全是 utf8mb4_unicode_ci(2026-09-16 實查)。
--
-- 執行:熊哥本機 Navicat。本檔不需 FTP。
-- =============================================================

-- ═══ 前置(唯讀) ═══
SELECT 'PRE' AS q,
       AVG(CHAR_LENGTH(search_text)) AS 現行search_text平均,
       MAX(CHAR_LENGTH(search_text)) AS 現行search_text最長
  FROM books;


-- ═══ 加欄位 ═══
ALTER TABLE `books`
  ADD COLUMN `search_key` VARCHAR(1500)
      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
      COMMENT '快速搜尋欄(不含摘要):書名+副標+原文名+作者+譯者+出版社+系列+關鍵字+ISBN+商品碼;由 tools/build_search_text.php 維護'
      AFTER `search_text`;


-- ═══ ★ 驗證 ═══

-- (1) 欄位與 collation(★ 必須是 utf8mb4_unicode_ci)
SELECT 'POST-1' AS q, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLLATION_NAME
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'books'
   AND COLUMN_NAME IN ('search_text','search_key')
 ORDER BY ORDINAL_POSITION;

-- (2) 目前應全為 NULL(還沒填值)
SELECT 'POST-2' AS q, COUNT(*) AS 總數, SUM(search_key IS NULL) AS 尚未填值_應等於總數
  FROM books;


-- ═══ 登錄 schema_migrations ═══
INSERT INTO `schema_migrations` (`version`, `applied_at`, `note`) VALUES
  ('2026-09-17_search_key.sql', NOW(),
   'M1-B:search_key VARCHAR(1500) 不含摘要;實測 search_text LIKE 全表掃描 90MB 需 5 秒,拆欄後預期 630ms')
ON DUPLICATE KEY UPDATE `applied_at` = NOW();


-- =============================================================
-- ★ 下一步順序(不可跳)
--   1. 本檔(建欄位)
--   2. FTP 新版 tools/build_search_text.php → 主機跑 --all(同時填兩欄)
--   3. 回查:SELECT COUNT(*) FROM books WHERE search_key IS NULL;  應為 0
--   4. FTP 新版 api/index.php(q 改查 search_key,新增 deep 參數)
--   5. 線上重測耗時,確認降到 1 秒以下
-- =============================================================
