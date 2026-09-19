-- =============================================================
-- 2026-09-19  M1-B 收尾:get_books() 改「不碰 v_book_list」之後的等價性對帳 + 效能量測
-- Asana: 1216467657931851(M1-B 檢索完善)/ 檢核點 2:1216467831523241
--
-- 本檔**前兩段是唯讀**,只是把「改寫前 / 改寫後」兩種寫法各跑一次比數字。
-- 第三段是一個**可選**索引,要先看第二段的量測結果再決定加不加(理由見該段)。
--
-- 執行:熊哥本機 Navicat 對遠端 DB。本檔不需 FTP。
-- ★ 請把每一段的輸出貼回,特別是第一段——**只要有任何一列 差異 <> 0,就不要上程式**。
-- =============================================================


-- ═══ 第一段:等價性對帳(唯讀,務必先跑) ═══
-- 改寫的風險不在「會不會壞」,而在「會不會悄悄少算幾筆」。
-- 左邊是改寫前的寫法(JOIN v_book_list),右邊是改寫後的寫法(純 books)。
-- ★ 預期:每一列的「差異」都是 0。

-- (1) 無條件瀏覽
SELECT '1-瀏覽全站' AS 情境,
       (SELECT COUNT(*) FROM v_book_list v JOIN books b ON b.book_id = v.book_id
         WHERE v.is_published = 1)                                        AS 改寫前,
       (SELECT COUNT(*) FROM books b WHERE b.is_published = 1)            AS 改寫後,
       (SELECT COUNT(*) FROM v_book_list v JOIN books b ON b.book_id = v.book_id
         WHERE v.is_published = 1)
     - (SELECT COUNT(*) FROM books b WHERE b.is_published = 1)            AS 差異;

-- (2) 關鍵字(走瘦表)。把 '麥種' 換成幾個不同的詞各跑一次,含中文詞、英文、ISBN、商品代碼。
SELECT '2-關鍵字' AS 情境,
       (SELECT COUNT(*) FROM v_book_list v
          JOIN books b ON b.book_id = v.book_id
          JOIN book_search bs ON bs.book_id = v.book_id
         WHERE v.is_published = 1
           AND (bs.search_key LIKE '%麥種%' OR bs.search_key LIKE '%麥種%'))  AS 改寫前,
       (SELECT COUNT(*) FROM books b
          JOIN book_search bs ON bs.book_id = b.book_id
         WHERE b.is_published = 1
           AND (bs.search_key LIKE '%麥種%' OR bs.search_key LIKE '%麥種%'))  AS 改寫後;

-- (3) ★★ 年份篩選 —— 本次最需要盯的一段。
--   view 的 publish_date 是 coalesce(最新版 edition 日期, books 平面欄)。
--   9/17 留言原本寫「改成 EXISTS(SELECT 1 FROM editions …)」,那個改法**會少算**
--   「只有 books.publish_date 有值、editions 沒有日期」的書。
--   程式裡採用的是原地重建同一個 coalesce 運算式,本段就是要證明它真的等價。
SELECT '3-年份 2020起' AS 情境,
       (SELECT COUNT(*) FROM v_book_list v JOIN books b ON b.book_id = v.book_id
         WHERE v.is_published = 1 AND v.publish_date >= '2020')           AS 改寫前_view,
       (SELECT COUNT(*) FROM books b
         WHERE b.is_published = 1
           AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                          WHERE ey.book_id = b.book_id), b.publish_date) >= '2020')
                                                                          AS 改寫後_採用,
       (SELECT COUNT(*) FROM books b
         WHERE b.is_published = 1
           AND EXISTS (SELECT 1 FROM editions ey
                        WHERE ey.book_id = b.book_id AND ey.publish_date >= '2020'))
                                                                          AS 若用EXISTS_對照;
-- 預期:改寫前_view = 改寫後_採用;
--       「若用EXISTS_對照」這一欄**會不一樣**(那正是沒有採用它的理由),
--       差多少就是當初照留言直接改會靜默漏掉的書數 —— 請一併貼回,值得記在 history。

-- (4) 年份 + 來源 + 分類三個一起帶(最容易出錯的組合)
SELECT '4-組合條件' AS 情境,
       (SELECT COUNT(*) FROM v_book_list v JOIN books b ON b.book_id = v.book_id
         WHERE v.is_published = 1
           AND v.publish_date >= '2020' AND v.publish_date <= '2026-12-31'
           AND EXISTS (SELECT 1 FROM editions es WHERE es.book_id = v.book_id AND es.source = 'akow'))
                                                                          AS 改寫前,
       (SELECT COUNT(*) FROM books b
         WHERE b.is_published = 1
           AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                          WHERE ey.book_id = b.book_id), b.publish_date) >= '2020'
           AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                          WHERE ey.book_id = b.book_id), b.publish_date) <= '2026-12-31'
           AND EXISTS (SELECT 1 FROM editions es WHERE es.book_id = b.book_id AND es.source = 'akow'))
                                                                          AS 改寫後;

-- (5) 瘦表同步狀況(順便確認 9/17 之後匯入的新書有沒有漏進瘦表)
SELECT '5-瘦表對帳' AS 情境,
       (SELECT COUNT(*) FROM books)        AS books筆數,
       (SELECT COUNT(*) FROM book_search)  AS 瘦表筆數,
       (SELECT COUNT(*) FROM books b
         WHERE NOT EXISTS (SELECT 1 FROM book_search bs WHERE bs.book_id = b.book_id))
                                           AS 未進瘦表_應為0;
-- ★ 「未進瘦表」不是 0 → 先在主機跑 php tools/build_search_text.php --missing 再繼續。


-- ═══ 第二段:效能量測(唯讀;Navicat 看執行時間) ═══
-- 跑之前先確認統計是新的,否則量到的是假的:
ANALYZE TABLE `books`, `editions`, `book_search`;

-- (A) 關鍵字 COUNT —— 改寫後(預期數百毫秒;9/17 實測同型查詢 438ms)
SELECT COUNT(*) FROM books b
  JOIN book_search bs ON bs.book_id = b.book_id
 WHERE b.is_published = 1 AND bs.search_key LIKE '%麥種%';

-- (B) 同一個查詢的執行計畫:★ 驗收標準是 **EXPLAIN 裡不可以出現兩次 books**,
--     且驅動表(第一列)應該是 bs(book_search),不是 b。
EXPLAIN SELECT COUNT(*) FROM books b
  JOIN book_search bs ON bs.book_id = b.book_id
 WHERE b.is_published = 1 AND bs.search_key LIKE '%麥種%';

-- (C) 瀏覽第一頁(沒有關鍵字)—— 這是本次**唯一沒有被加速**的路徑,要量一次才知道要不要第三段。
SELECT b.book_id FROM books b
 WHERE b.is_published = 1
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

EXPLAIN SELECT b.book_id FROM books b
 WHERE b.is_published = 1
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;
-- 看 Extra 欄:出現 "Using filesort" 且 type=ALL,代表為了拿 20 筆排了 65,290 列
-- (而 books 是 501 MB)。→ 才考慮第三段。


-- ═══ 第三段:可選索引(★ 先看第二段 (C) 的耗時再決定,不要照抄就跑) ═══
-- 2026-09-16 的 migration 刻意沒建 is_published 單欄索引,理由是基數極低(幾乎全為 1),
-- 當時寫的但書是「若之後量到需要,正確做法是放進複合索引的前導欄,而那要看實際查詢形狀」。
-- 現在查詢形狀確定了:`WHERE is_published=1 ORDER BY created_at DESC, book_id DESC LIMIT n`,
-- 這正是複合索引能一次吃掉「過濾 + 排序 + 取前 n 列」的形狀。
--
-- 判斷準則:第二段 (C) 若在 300ms 以內,**不要加**(索引有寫入成本,而 import 每天都在寫);
--          若是秒級,再加。
--
-- ALTER TABLE `books` ADD INDEX `idx_pub_created` (`is_published`, `created_at`, `book_id`);
--
-- 加完重跑第二段 (C) 的 EXPLAIN,預期 Extra 不再有 "Using filesort"、type 變 ref。
-- 還原:ALTER TABLE `books` DROP INDEX `idx_pub_created`;
--
-- 若決定採用,再把下面這段的註解拿掉一起執行:
-- INSERT INTO `schema_migrations` (`version`, `applied_at`, `note`) VALUES
--   ('2026-09-19_m1b_search_rewrite_verify.sql', NOW(),
--    'M1-B:get_books 改不碰 v_book_list 後的等價性對帳;加 books(is_published, created_at, book_id) 複合索引')
-- ON DUPLICATE KEY UPDATE `applied_at` = NOW();
