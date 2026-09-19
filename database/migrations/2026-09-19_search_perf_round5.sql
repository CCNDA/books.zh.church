-- =============================================================
-- 2026-09-19  搜尋效能第五輪:確認「ORDER BY + LIMIT 造成執行計畫翻轉」並量三個候選解
-- Asana: 1216467657931851(M1-B 檢索完善)
--
-- 【round4b 的結論 —— 兇手終於現形】
--   SHOW PROFILES(秒):
--     掃瘦表 連跑三次          0.158 / 0.152 / 0.191   ← 沒有冷熱差,**buffer pool 假設死了**
--     換沒查過的詞(447 筆)     0.135
--     COUNT(JOIN books)       **0.288**               ← 9/17 的 438ms 是對的,改寫確實生效
--     取 20 個 book_id         **4.776**               ← ★ 兇手
--     只掃瘦表(兩個 LIKE)      0.140
--     無關鍵字瀏覽第一頁        **4.525**               ← 同一個病
--
--   同樣的 WHERE、同樣命中 3 筆,只差一個
--   `ORDER BY b.created_at DESC, b.book_id DESC LIMIT 20`,就從 0.288 變成 4.776 秒。
--   排序 3 筆不可能花 4.5 秒 ⇒ 不是排序本身,是**最佳化器換了執行計畫**:
--   看到 ORDER BY + LIMIT,它不再用 13.5 MB 的瘦表當驅動表,
--   改去掃 511 MB 的 books「期待早點湊滿 20 筆」,而符合的只有 3 筆 → 一路掃到底。
--
--   ★★ 方法教訓:9/17 的三輪診斷從頭到尾都在量 COUNT,**從來沒量過真正取資料那一句**。
--      今天照著那些數字改,等於把同一個盲點再走一次。
--      「一個查詢」在 API 裡其實是兩句,兩句都要量。
--
-- 【還有一件先前沒人量過的事】
--   無關鍵字瀏覽第一頁 4.525 秒 —— 這跟搜尋無關,**首頁瀏覽一直都是這個速度**。
--
-- ★ 本檔第一、二部分唯讀;第三部分會建一個索引(附還原指令)。
-- ★ 一樣整批跑,最後貼回 SHOW PROFILES 那張表。
-- =============================================================


-- ═══ 第一部分:先證明「計畫翻轉」確有其事(唯讀) ═══

-- (A) 兇手那一句的執行計畫。★ 這是本輪最重要的一張圖。
--     預期看到 b 變成驅動表(type=ALL,rows 約五萬),而不是 round4 那樣由 bs 驅動。
EXPLAIN SELECT b.book_id
  FROM books b
  JOIN book_search bs ON bs.book_id = b.book_id
 WHERE b.is_published = 1
   AND (bs.search_key LIKE '%RCU63A%' OR bs.search_key LIKE '%RCU63A%')
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- (B) 同一句,只把 ORDER BY 拿掉 —— 若瞬間變快,翻轉假設成立。
SET profiling = 1;
SET profiling_history_size = 40;

SELECT SQL_NO_CACHE b.book_id
  FROM books b
  JOIN book_search bs ON bs.book_id = b.book_id
 WHERE b.is_published = 1
   AND (bs.search_key LIKE '%RCU63A%' OR bs.search_key LIKE '%RCU63A%')
 LIMIT 20 OFFSET 0;


-- ═══ 第二部分:三個候選解,各量一次(唯讀) ═══

-- 【候選一】STRAIGHT_JOIN:直接命令最佳化器「瘦表一定當驅動表」。
--   改動最小,只改 SQL 一個關鍵字,不動 schema。
SELECT SQL_NO_CACHE STRAIGHT_JOIN b.book_id
  FROM book_search bs
  JOIN books b ON b.book_id = bs.book_id
 WHERE b.is_published = 1
   AND (bs.search_key LIKE '%RCU63A%' OR bs.search_key LIKE '%RCU63A%')
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- 【候選二】先用子查詢把命中的 id 圈出來,再排序。
--   不依賴 hint,語意也比較清楚。
SELECT SQL_NO_CACHE b.book_id
  FROM books b
 WHERE b.is_published = 1
   AND b.book_id IN (SELECT book_id FROM book_search
                      WHERE search_key LIKE '%RCU63A%'
                         OR search_key LIKE '%RCU63A%')
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- 【候選三】衍生表:先在瘦表裡做完過濾,外層只對少數列排序。
SELECT SQL_NO_CACHE t.book_id
  FROM (SELECT bs.book_id
          FROM book_search bs
         WHERE bs.search_key LIKE '%RCU63A%'
            OR bs.search_key LIKE '%RCU63A%') t
  JOIN books b ON b.book_id = t.book_id
 WHERE b.is_published = 1
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- (C) ★ 換一個命中很多的關鍵字再測一次最好的那個形狀 ——
--     只有 3 筆命中時「早停」佔不到便宜,447 筆時才看得出真實表現。
SELECT SQL_NO_CACHE STRAIGHT_JOIN b.book_id
  FROM book_search bs
  JOIN books b ON b.book_id = bs.book_id
 WHERE b.is_published = 1
   AND (bs.search_key LIKE '%喜樂%' OR bs.search_key LIKE '%喜樂%')
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

SHOW PROFILES;
-- ★ 貼回這張表。三個候選哪個最快,就照哪個改程式;
--   若三個都還是秒級,不要改程式,回來重新想。


-- ═══ 第三部分:無關鍵字瀏覽(4.525 秒)——這個要靠索引 ═══
-- 9/16 的 migration 判斷「不建 is_published 索引」,依據是「僅數百筆為 0」。
-- 9/19 實查:全站 65,304、上架 54,530 → **下架 10,771 本**,前提數字差了兩個數量級。
-- 而且現在查詢形狀已經明確:WHERE is_published=1 ORDER BY created_at DESC LIMIT n。
-- 這正是複合索引能一次吃掉「過濾 + 排序 + 取前 n 列」的形狀。

-- 先量現況(索引還沒建)
SELECT SQL_NO_CACHE b.book_id
  FROM books b
 WHERE b.is_published = 1
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- 建索引(可逆)
ALTER TABLE `books` ADD INDEX `idx_pub_created` (`is_published`, `created_at`, `book_id`);
ANALYZE TABLE `books`;

-- 再量一次(同一句)
SELECT SQL_NO_CACHE b.book_id
  FROM books b
 WHERE b.is_published = 1
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- 看計畫有沒有擺脫 filesort
EXPLAIN SELECT b.book_id
  FROM books b
 WHERE b.is_published = 1
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;
-- 預期:Extra 不再有 "Using filesort",key = idx_pub_created。

-- ★ 索引建好之後,再回頭跑一次第二部分的三個候選 ——
--   新索引可能讓最佳化器對關鍵字那條路也改變選擇(可能更好,也可能更糟,要量)。

SHOW PROFILES;

-- 還原(若沒有變快,或寫入明顯變慢):
-- ALTER TABLE `books` DROP INDEX `idx_pub_created`;


-- ═══ 第四部分:只有在前面都無效時才考慮(先不要做) ═══
-- 【候選四】把 is_published 與 created_at 併進 book_search 瘦表,
--   讓關鍵字搜尋完全不必 JOIN books:
--     ALTER TABLE book_search ADD COLUMN is_published TINYINT(1) NOT NULL DEFAULT 1,
--                             ADD COLUMN created_at TIMESTAMP NULL;
--     ALTER TABLE book_search ADD INDEX idx_bs_pub_created (is_published, created_at, book_id);
--   代價:多兩個要同步的欄位(build_search_text.php 要一起維護,
--   而「兩份資料要同步」正是這個專案已經踩過的靜默失敗來源)。
--   ⇒ 前三個候選有任何一個能做到 1 秒以下,就不要走這條。


-- =============================================================
-- 【判讀】
--   (B) 拿掉 ORDER BY 就快 → 翻轉假設成立,問題確定是「最佳化器選錯驅動表」。
--   候選一/二/三 有任一 < 1 秒(且 447 筆那次也 < 1 秒)→ 照它改 api/index.php。
--   三個都慢 → 不要改程式,把 EXPLAIN 貼回來重新診斷。
--   第三部分建索引後瀏覽 < 300ms → 索引留著,並在 CHANGELOG 記一筆
--     「9/16 判斷不建此索引的前提(僅數百筆下架)是錯的」。
-- =============================================================
