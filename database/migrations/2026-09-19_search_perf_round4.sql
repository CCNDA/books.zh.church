-- =============================================================
-- 2026-09-19  搜尋效能第四輪:線上仍 5 秒,先量清楚再改程式
-- Asana: 1216467657931851(M1-B 檢索完善)
--
-- 【為什麼要有這一輪】
-- 9/19 改寫後線上實測(瀏覽器,分段扣除):
--     靜態檔(純網路+Cloudflare)      249–345 ms
--     PHP 404(PHP 有跑、不連 DB)      284 ms
--     單書詳情(連 DB + 小查詢)        614–703 ms
--     關鍵字零命中(只有 COUNT + id)  4,885 / 5,016 / 5,255 / 5,365 ms  ← 連跑四次,不是暖機
--   ⇒ 網路 0.3s、PHP ~0、DB 連線 0.3s,**關鍵字查詢本身約 4.4 秒**。
--   雙重 JOIN 已消失(EXPLAIN 只剩一個 books),但 9/17 量到的 438ms 沒有轉移到線上。
--
-- 【兩個待排除的可能】
--   (a) 寫法不同:我實作成 JOIN,而 9/17 被量到 438ms 的 ③ 是 IN 子查詢。
--   (b) 9/17 的 389ms / 438ms 是**查詢快取的假象**(重複跑同一句),
--       而 PDO 走 server-side prepare 不吃查詢快取。
--       若成立,「換瘦表就快 11.7 倍」這個第三輪結論本身要作廢。
--
-- ★ 全部加 SQL_NO_CACHE —— 這一輪的重點就是不要再被快取騙一次。
-- ★ 每句請**分開執行**並記下耗時,不要整批跑(Navicat 只會顯示最後一句的時間)。
-- ★ 本檔唯讀,不改任何資料,不需 FTP。
-- =============================================================


-- ═══ 0. 先確認快取設定(決定 9/17 的數字可不可信) ═══
SHOW VARIABLES LIKE 'query_cache%';
-- 看 query_cache_type:ON / DEMAND 代表 9/17 那些重複量測**可能**吃到快取;
-- OFF 則可能性 (b) 直接排除。


-- ═══ 1. 成本下限:只掃瘦表,什麼都不 JOIN ═══
-- 這一句決定一切。book_search 只有 book_id + search_key、約 14 MB。
SELECT SQL_NO_CACHE COUNT(*) AS 命中
  FROM book_search
 WHERE search_key LIKE '%RCU63A%';
-- 耗時 = ______ ms
-- 9/17 量到的對應數字是 389 ms。若這次是數千毫秒 → 可能性 (b) 成立。


-- ═══ 2. 目前線上的寫法(JOIN) ═══
-- 與 api/index.php 現行 COUNT 完全一致(含兩個 LIKE:一個原樣、一個去連字號)。
SELECT SQL_NO_CACHE COUNT(*)
  FROM books b
  JOIN book_search bs ON bs.book_id = b.book_id
 WHERE b.is_published = 1
   AND (bs.search_key LIKE '%RCU63A%' OR bs.search_key LIKE '%RCU63A%');
-- 耗時 = ______ ms


-- ═══ 3. 9/17 量到 438ms 的寫法(IN 子查詢) ═══
SELECT SQL_NO_CACHE COUNT(*)
  FROM books b
 WHERE b.is_published = 1
   AND b.book_id IN (SELECT book_id FROM book_search
                      WHERE search_key LIKE '%RCU63A%'
                         OR search_key LIKE '%RCU63A%');
-- 耗時 = ______ ms


-- ═══ 4. 兩種寫法的執行計畫對照(看驅動表與 rows) ═══
EXPLAIN SELECT COUNT(*)
  FROM books b
  JOIN book_search bs ON bs.book_id = b.book_id
 WHERE b.is_published = 1
   AND (bs.search_key LIKE '%RCU63A%' OR bs.search_key LIKE '%RCU63A%');

EXPLAIN SELECT COUNT(*)
  FROM books b
 WHERE b.is_published = 1
   AND b.book_id IN (SELECT book_id FROM book_search
                      WHERE search_key LIKE '%RCU63A%'
                         OR search_key LIKE '%RCU63A%');


-- ═══ 5. 補充:瘦表的實際大小與排序規則(若第 1 句就慢,這裡找原因) ═══
SELECT SQL_NO_CACHE
       TABLE_NAME, ENGINE, TABLE_ROWS,
       ROUND(DATA_LENGTH /1024/1024, 1) AS 資料MB,
       ROUND(INDEX_LENGTH/1024/1024, 1) AS 索引MB,
       TABLE_COLLATION
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('book_search','books');
-- 預期 book_search 約 14 MB。若遠大於此(例如上百 MB),
-- 「瘦表」根本不瘦,第三輪的前提就錯了。
-- 另看 TABLE_COLLATION:utf8mb4_unicode_ci 的 LIKE 要算排序權重,
-- 比 _bin / _general_ci 慢;若第 1 句慢而表確實只有 14 MB,這是下一個要查的方向。


-- ═══ 6. 只有在第 1 句也慢時才跑:排序規則的影響有多大 ═══
-- (唯讀比較,不改欄位)
SELECT SQL_NO_CACHE COUNT(*) AS 命中_bin比對
  FROM book_search
 WHERE search_key COLLATE utf8mb4_bin LIKE '%RCU63A%';
-- 耗時 = ______ ms
-- 若這句明顯快於第 1 句,方向就是排序規則而不是資料量。


-- =============================================================
-- 【判讀】(先寫在這裡,免得數字回來又要來回一次)
--
--   第1句 ≈ 400ms、第3句 ≈ 400ms、第2句 ≈ 4s
--     → 可能性 (a):純粹是寫法差異。把 api/index.php 的 JOIN 改回 IN 子查詢即可,
--       第二段(用 id 取顯示欄位)不動。
--
--   第1句就 ≈ 4s
--     → 可能性 (b):瘦表方案從一開始就沒有效,9/17 第三輪結論作廢
--       (那會是這個題目上第四個被實測否決的假設)。
--       接著看第 5、6 句決定是「表不夠瘦」還是「排序規則太貴」,
--       都不是 → 回到最上游重新診斷,**不要再憑假設改程式**。
--
--   第1、2、3 句都 ≈ 400ms
--     → 慢的不在這三句,而在第二段(向 v_book_list 取顯示欄位)或 PHP 端。
--       但線上「零命中」那次根本不會執行第二段卻仍要 5 秒,
--       所以這個分支若成立,代表線上與 Navicat 之間還有別的差異
--       (server-side prepare、連線設定),要另外查。
-- =============================================================
