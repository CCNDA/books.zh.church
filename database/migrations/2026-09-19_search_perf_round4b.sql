-- =============================================================
-- 2026-09-19  搜尋效能第四輪(續):用 SHOW PROFILES 一次拿到所有耗時
-- Asana: 1216467657931851(M1-B 檢索完善)
--
-- 【round4 已經確定的事】
--   ✗ 查詢快取假象 —— query_cache_type = OFF,排除。
--   ✗ JOIN vs IN 寫法差異 —— 兩個 EXPLAIN 完全相同
--      (book_search ALL 68,903 → b eq_ref PRIMARY),
--      MariaDB 把 IN 子查詢最佳化成同一個計畫 ⇒ **改寫法不會有任何差別**。
--   ✓ 瘦表確實瘦:book_search 13.5 MB / books 511.8 MB。
--   ✓ 三種寫法命中數都是 3,正確性沒問題。
--
-- 【還沒拿到,而且是唯一決定性的東西】
--   **每一句的實際耗時**。round4 回傳的都是結果值,沒有毫秒數。
--   本檔改用 SHOW PROFILES —— 它會把每一句的 Duration 列成一張表,
--   一次截圖就有全部數字,不必去找 Navicat 的狀態列。
--
-- 【目前唯一還活著的假設】
--   InnoDB buffer pool:Navicat 重複跑是熱的(資料在 RAM),
--   而線上每次查詢可能是冷的 —— 511 MB 的 books 只要被掃一次
--   (首頁分類統計、deep 搜尋、無條件瀏覽),
--   就會把 13.5 MB 的瘦表擠出記憶體,下一次關鍵字搜尋又要從磁碟讀。
--   線上實測「關鍵字零命中」連跑四次都是 4.9–5.4 秒,正符合「每次都冷」的形狀。
--
-- ★ 本檔唯讀。請**整批一次執行**(與 round4 相反 —— profiling 就是為了整批跑而用的),
--   最後把 SHOW PROFILES 那張表整張貼回。
-- =============================================================


-- ═══ 第一部分:環境(先看 buffer pool 有多大) ═══
SHOW VARIABLES LIKE 'innodb_buffer_pool_size';
-- 若是 134217728(128 MB,MariaDB 預設)→ 假設成立的機率很高:
--   books 光資料就 511 MB,放不進去,任何一次全表掃描都會洗掉整個 pool。

SHOW VARIABLES LIKE 'innodb_buffer_pool_instances';

SELECT 'pool 使用狀況' AS q,
       ROUND(SUM(DATA_LENGTH + INDEX_LENGTH)/1024/1024, 1) AS 全庫MB
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE();


-- ═══ 第二部分:開 profiling,把要比的查詢跑一遍 ═══
SET profiling = 1;
SET profiling_history_size = 30;

-- (1) 冷/熱都測:同一句連跑三次。
--     ★ 這三次的差距,就是「buffer pool 有沒有幫上忙」的直接證據。
--     第 1 次明顯慢、第 2、3 次快 ⇒ 假設成立,線上慢是因為每次都冷。
--     三次一樣快      ⇒ 假設不成立,要另外找。
SELECT SQL_NO_CACHE COUNT(*) FROM book_search WHERE search_key LIKE '%RCU63A%';
SELECT SQL_NO_CACHE COUNT(*) FROM book_search WHERE search_key LIKE '%RCU63A%';
SELECT SQL_NO_CACHE COUNT(*) FROM book_search WHERE search_key LIKE '%RCU63A%';

-- (2) 換一個沒查過的關鍵字(避開任何殘留的熱資料,但掃的是同一張表)
SELECT SQL_NO_CACHE COUNT(*) FROM book_search WHERE search_key LIKE '%喜樂%';

-- (3) 現行線上的 COUNT(JOIN 寫法,含兩個 LIKE)
SELECT SQL_NO_CACHE COUNT(*)
  FROM books b
  JOIN book_search bs ON bs.book_id = b.book_id
 WHERE b.is_published = 1
   AND (bs.search_key LIKE '%RCU63A%' OR bs.search_key LIKE '%RCU63A%');

-- (4) 現行線上的第一段(取本頁 20 個 book_id)
SELECT SQL_NO_CACHE b.book_id
  FROM books b
  JOIN book_search bs ON bs.book_id = b.book_id
 WHERE b.is_published = 1
   AND (bs.search_key LIKE '%RCU63A%' OR bs.search_key LIKE '%RCU63A%')
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- (5) ★ 關鍵對照:同樣掃一遍,但**完全不碰 books**。
--     與 (3) 的差距 = 「為了 is_published 與 created_at 去碰 511 MB 的 books」的代價。
SELECT SQL_NO_CACHE COUNT(*) FROM book_search
 WHERE search_key LIKE '%RCU63A%' OR search_key LIKE '%RCU63A%';

-- (6) 無關鍵字的瀏覽第一頁(先前 EXPLAIN 顯示 ALL + Using filesort,一直沒量到時間)
SELECT SQL_NO_CACHE b.book_id
  FROM books b
 WHERE b.is_published = 1
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;


-- ═══ 第三部分:把所有耗時列出來(★ 這張表整張貼回) ═══
SHOW PROFILES;
-- Duration 欄單位是「秒」,例如 0.438 就是 438 ms。


-- ═══ 第四部分:最慢那一句的成本拆解 ═══
-- 先看上面哪一個 Query_ID 最慢,把下面的 N 換成那個編號再執行。
-- SHOW PROFILE CPU, BLOCK IO FOR QUERY N;
-- ★ 看 "Sending data" 這一列:它其實就是「讀資料」的時間。
--   若 Block_ops_in 很大 → 真的在讀磁碟 ⇒ buffer pool 假設成立。


-- =============================================================
-- 【判讀】(先寫好,免得數字回來又要來回一次)
--
--   (1) 三次:第一次慢、二三次快
--     → buffer pool 假設成立。線上慢是因為每次查詢都是冷的。
--       解法方向不是再改 SQL,而是:加大 innodb_buffer_pool_size,
--       或把 books 的大欄位(extra / summary / buy_links,佔 511 MB 的大宗)
--       搬到副表,讓 books 本體小到能常駐記憶體。
--       ★ 這兩者都要另外開票,不塞進 v1.14.0。
--
--   (1) 三次一樣快(都 ≈ 400ms)
--     → 那 Navicat 端從頭到尾就是快的,慢的只有線上 ⇒ 差異在 PHP↔DB 之間
--       (server-side prepare、連線設定、PHP 端逐列處理),要從那裡查,
--       不要再動 SQL。
--
--   (3) 與 (5) 差距很大
--     → 代價來自「碰 books」。那麼 is_published 與 created_at 這兩個欄位
--       應該併進 book_search 瘦表,讓關鍵字搜尋完全不需要 JOIN books。
--       這是有數字支持才做的改動,先量再說。
--
--   (6) 若是秒級
--     → 瀏覽路徑要補 (is_published, created_at, book_id) 複合索引
--       (見 2026-09-19_m1b_search_rewrite_verify.sql 第三段)。
-- =============================================================
