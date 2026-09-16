-- =============================================================
-- 2026-09-17  book_search 瘦表:把搜尋欄搬出 501 MB 的 books
-- Asana: 1216467657931851(M1-B)/ 檢核點 2:1216467831523241
--
-- ═══ 為什麼(三輪量測後才確定的真因) ═══
--
-- 線上搜尋 12 秒。前兩輪的診斷都不對,記下來避免重蹈:
--   ✗ 第一輪假設「v_book_list 被具體化」→ EXPLAIN 證明 view 是 MERGE,
--     DEPENDENT SUBQUERY 都走索引 rows=1,不是瓶頸。
--   ✗ 第二輪假設「search_text 太長(平均 463 字)」→ 拆出 search_key(91 字)後
--     線上仍是 12 秒,deep=1 只差 13.7%。
--
--   ✓ 真因(2026-09-17 實測):
--       純掃 search_key   4,738 ms
--       純掃 search_text  5,316 ms   ← 只差 12.2%,而資料量差 5.09 倍
--       books 表 DATA_LENGTH = **501 MB**(每列平均 7.7 KB)
--
--     InnoDB 全表掃描以 page(16KB)為單位**讀取整列**。
--     books 每列除了 search_key 的 270 bytes,還躺著 extra(七來源合併的 MEDIUMTEXT)、
--     summary、buy_links 共 7 KB+ → 掃描成本由整列大小決定,與搜尋欄長度幾乎無關。
--
-- ═══ 解法 ═══
-- 把 search_key 搬到只有兩欄的瘦表:
--   65,290 列 × 約 300 bytes ≈ **20 MB**(對比 books 的 501 MB,約 25 倍)
--   預期掃描成本 4,738 / 25 ≈ **190 ms**
--
-- ★ 查詢要寫成 JOIN(不是 EXISTS):
--     JOIN book_search bs ON bs.book_id = v.book_id WHERE bs.search_key LIKE :q1
--   這樣最佳化器可以拿小表當驅動表(掃 20MB)→ 得到少數 book_id
--   → 再用 PRIMARY 索引回 books 取那幾列。
--   若寫成 EXISTS(...),會變成對 books 每一列執行子查詢 = 又掃 501 MB。
--
-- ★ search_text(含摘要,deep=1 用)**留在 books 不搬** ——
--   搬過來會讓瘦表變成 90 MB,失去意義;深度搜尋本來就接受慢。
--
-- ★ books.search_key 暫時保留不刪(可逆原則),確認新方案有效後再另一支 migration 移除。
--
-- 執行:熊哥本機 Navicat。本檔不需 FTP。
-- =============================================================

-- ═══ 前置(唯讀) ═══
SELECT 'PRE' AS q,
       ROUND(DATA_LENGTH/1024/1024)  AS books資料MB,
       ROUND(INDEX_LENGTH/1024/1024) AS books索引MB
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'books';


-- ═══ 建表 ═══
-- ★ 明寫 COLLATE:本 DB 預設是 utf8mb4_general_ci,既有表全是 unicode_ci
CREATE TABLE IF NOT EXISTS `book_search` (
  `book_id`    int(10) unsigned NOT NULL,
  `search_key` varchar(1500) COLLATE utf8mb4_unicode_ci DEFAULT NULL
               COMMENT '不含摘要的搜尋文字;由 tools/build_search_text.php 維護',
  PRIMARY KEY (`book_id`),
  CONSTRAINT `fk_bsearch_book` FOREIGN KEY (`book_id`)
      REFERENCES `books` (`book_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='搜尋專用瘦表:books 每列 7.7KB,全表掃描要讀 501MB;本表僅約 20MB';


-- ═══ 灌入現有資料(books.search_key 已由工具填好,不必重跑 PHP) ═══
INSERT INTO `book_search` (`book_id`, `search_key`)
SELECT `book_id`, `search_key` FROM `books` WHERE `search_key` IS NOT NULL
ON DUPLICATE KEY UPDATE `search_key` = VALUES(`search_key`);

ANALYZE TABLE `book_search`;


-- ═══ ★ 驗證 ═══

-- (1) 筆數應與 books 相同(65,290)
SELECT 'POST-1' AS q,
       (SELECT COUNT(*) FROM books)       AS books筆數,
       (SELECT COUNT(*) FROM book_search) AS 瘦表筆數;

-- (2) ★ 表大小對比:瘦表應在 20–30 MB 之譜,books 是 501 MB
SELECT 'POST-2' AS q, TABLE_NAME,
       ROUND(DATA_LENGTH/1024/1024)  AS 資料MB,
       ROUND(INDEX_LENGTH/1024/1024) AS 索引MB
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('books','book_search');

-- (3) ★★ 效能對照 —— 這是本 migration 成敗的唯一判準
SET @t := NOW(6);
SELECT COUNT(*) FROM books WHERE search_key LIKE '%RCU63A%';
SELECT 'POST-3a 掃 books(501MB)' AS 測試, TIMESTAMPDIFF(MICROSECOND,@t,NOW(6))/1000 AS 毫秒;

SET @t := NOW(6);
SELECT COUNT(*) FROM book_search WHERE search_key LIKE '%RCU63A%';
SELECT 'POST-3b 掃 book_search(瘦表)' AS 測試, TIMESTAMPDIFF(MICROSECOND,@t,NOW(6))/1000 AS 毫秒;

-- (4) 兩者結果必須相同(功能等價)
SELECT 'POST-4' AS q,
       (SELECT COUNT(*) FROM books       WHERE search_key LIKE '%RCU63A%') AS 由books,
       (SELECT COUNT(*) FROM book_search WHERE search_key LIKE '%RCU63A%') AS 由瘦表;


-- ═══ 登錄 schema_migrations ═══
INSERT INTO `schema_migrations` (`version`, `applied_at`, `note`) VALUES
  ('2026-09-17_book_search_table.sql', NOW(),
   'M1-B:搜尋欄搬到瘦表。實測 books 表 501MB 是全表掃描成本主因,與搜尋欄長度幾乎無關')
ON DUPLICATE KEY UPDATE `applied_at` = NOW();


-- =============================================================
-- ★ 下一步
--   1. 本檔 → 看 POST-3 的兩個毫秒數
--      · 瘦表明顯快(預期 200ms 上下)→ 繼續第 2 步
--      · 沒有明顯差距 → **停下來告訴我**,代表這個方向也不對,不要再往下改程式
--   2. FTP 新版 tools/build_search_text.php(同步維護 book_search)
--   3. FTP 新版 api/index.php(q 改成 JOIN book_search)
--   4. 線上重測
-- =============================================================
