-- =============================================================
-- 2026-09-19  第六輪:年份篩選 5.2 秒 —— 先分辨是哪一句慢,再談改法
-- Asana: 1216467657931851(M1-B 檢索完善)
--
-- 【第五輪之後的線上實測(瀏覽器,ms)】
--   q=RCU63A            875 / 839      ← 原 5,713
--   ISBN 帶連字號        839            ← 原 4,850
--   q=喜樂(212 筆)      836
--   source=akow         715            ← 原 8,815
--   無條件瀏覽           279            ← 原 8,487(網路本身就要 ~300,等於貼著下限)
--   第 100 頁深分頁      438
--   ─────────────────────────────────
--   year_from=2020            **5,195**   (9,898 筆)
--   q=喜樂 & year_from=2020   **5,247**   (28 筆)
--   deep=1                     6,070      (設計上接受)
--
--   只多一個 year_from,同一條路從 279 ms 變 5,195 ms。
--   兇手是我 9/19 寫進去的年份運算式:
--     COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
--                WHERE ey.book_id = b.book_id), b.publish_date)
--   (這不是回歸 —— 改之前走 v.publish_date 也是 5 秒等級 —— 但也沒被修好。)
--
-- ★★ 本輪第一件事不是找解法,是**分辨 COUNT 與取列哪一句慢**。
--    這正是前三輪一路踩過來的坑:只量 COUNT,沒量真正取資料那一句。
--
-- ★ 本檔唯讀,不改 schema。整批跑,最後貼回 SHOW PROFILES。
-- =============================================================

SET profiling = 1;
SET profiling_history_size = 40;


-- ═══ 第一部分:先分辨是哪一句 ═══

-- (1) COUNT,帶 year_from(API 實際送出的形狀)
SELECT SQL_NO_CACHE COUNT(*)
  FROM books b
 WHERE b.is_published = 1
   AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                  WHERE ey.book_id = b.book_id), b.publish_date) >= '2020';

-- (2) 取 20 列,帶 year_from(API 實際送出的形狀)
SELECT SQL_NO_CACHE b.book_id
  FROM books b
 WHERE b.is_published = 1
   AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                  WHERE ey.book_id = b.book_id), b.publish_date) >= '2020'
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- (3) 對照:同一句但拿掉年份條件(已知 0.0004 s)
SELECT SQL_NO_CACHE b.book_id
  FROM books b
 WHERE b.is_published = 1
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- (4) 關鍵字 + 年份(STRAIGHT_JOIN 形狀,API 實際送出的)
SELECT SQL_NO_CACHE STRAIGHT_JOIN b.book_id
  FROM book_search bs
  JOIN books b ON b.book_id = bs.book_id
 WHERE b.is_published = 1
   AND (bs.search_key LIKE '%喜樂%' OR bs.search_key LIKE '%喜樂%')
   AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                  WHERE ey.book_id = b.book_id), b.publish_date) >= '2020'
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- (5) 同 (4) 但拿掉年份(已知 0.388 s)——(4)(5) 的差就是年份運算式的代價
SELECT SQL_NO_CACHE STRAIGHT_JOIN b.book_id
  FROM book_search bs
  JOIN books b ON b.book_id = bs.book_id
 WHERE b.is_published = 1
   AND (bs.search_key LIKE '%喜樂%' OR bs.search_key LIKE '%喜樂%')
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;


-- ═══ 第二部分:成本來源 —— 是「相關子查詢跑太多次」還是「用不到索引」 ═══

-- (6) 速度下限:只用 books 平面欄(語意不對,純粹當基準)
SELECT SQL_NO_CACHE b.book_id
  FROM books b
 WHERE b.is_published = 1 AND b.publish_date >= '2020'
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- (7) 純 EXISTS 版(year_from 上與 COALESCE 同值,已於等價對帳驗過;
--     但 year_to 會差 613 本,所以不能直接拿來用 —— 這裡只為了看它快不快)
SELECT SQL_NO_CACHE b.book_id
  FROM books b
 WHERE b.is_published = 1
   AND EXISTS (SELECT 1 FROM editions ey
                WHERE ey.book_id = b.book_id AND ey.publish_date >= '2020')
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- (8) 候選:把「最新出版日」先在 editions 上彙總成衍生表再 JOIN,
--     避免逐列跑相關子查詢
SELECT SQL_NO_CACHE b.book_id
  FROM books b
  LEFT JOIN (SELECT book_id, MAX(publish_date) AS pd
               FROM editions GROUP BY book_id) m ON m.book_id = b.book_id
 WHERE b.is_published = 1
   AND COALESCE(m.pd, b.publish_date) >= '2020'
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- (9) 候選:用 OR 拆成兩個可各自走索引的條件,語意與 COALESCE 完全相同
--     「有 edition 日期 → 取最大值比較」OR「完全沒有 edition 日期 → 用平面欄」
SELECT SQL_NO_CACHE b.book_id
  FROM books b
 WHERE b.is_published = 1
   AND (
         EXISTS (SELECT 1 FROM editions ey
                  WHERE ey.book_id = b.book_id
                    AND ey.publish_date IS NOT NULL
                    AND ey.publish_date >= '2020')
      OR (NOT EXISTS (SELECT 1 FROM editions ey2
                       WHERE ey2.book_id = b.book_id
                         AND ey2.publish_date IS NOT NULL)
          AND b.publish_date >= '2020')
       )
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

SHOW PROFILES;


-- ═══ 第三部分:計畫(貼回這兩張) ═══
EXPLAIN SELECT b.book_id
  FROM books b
 WHERE b.is_published = 1
   AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                  WHERE ey.book_id = b.book_id), b.publish_date) >= '2020'
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;
-- 重點看:key 還是不是 idx_pub_created、Extra 有沒有 Using filesort。
-- 若索引沒被用到 → 是「WHERE 裡有不可索引的運算式」讓它放棄了排序索引。

EXPLAIN SELECT b.book_id
  FROM books b
 WHERE b.is_published = 1
   AND EXISTS (SELECT 1 FROM editions ey
                WHERE ey.book_id = b.book_id AND ey.publish_date >= '2020')
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;


-- ═══ 第四部分:年份欄位的實際分布(決定要不要走「補欄位」那條路) ═══
SELECT 'Y-1' AS q,
       COUNT(*) AS 全站,
       SUM(EXISTS (SELECT 1 FROM editions ey
                    WHERE ey.book_id = b.book_id AND ey.publish_date IS NOT NULL))
         AS 有edition日期,
       SUM(b.publish_date IS NOT NULL AND b.publish_date <> '') AS 有平面欄日期,
       SUM(NOT EXISTS (SELECT 1 FROM editions ey
                        WHERE ey.book_id = b.book_id AND ey.publish_date IS NOT NULL)
           AND b.publish_date IS NOT NULL AND b.publish_date <> '')
         AS 只靠平面欄後備
  FROM books b;
-- ★「只靠平面欄後備」若是 0,代表 COALESCE 的後備那一層在現有資料上從不生效
--   → 可以改用純 EXISTS(快),但**必須在程式裡註明這是資料現況決定的,
--     未來有來源只寫平面欄就會失效**,並加一條對帳把它守住。
--   若不是 0,後備層就是必要的,得走 (8)(9) 或補欄位。


-- =============================================================
-- 【判讀】
--   (1) 快 (2) 慢  → 又是 ORDER BY + LIMIT 的計畫翻轉,解法同第五輪(強制驅動表/索引)。
--   (1)(2) 都慢    → 成本在相關子查詢本身,看 (8)(9) 哪個快。
--   (6) 很快而 (7) 也快 → 貴的是 COALESCE 這個「包了子查詢的運算式」,不是 editions 查詢。
--   (8) 或 (9) < 1 秒 → 照它改 api/index.php。
--   全部都慢       → 不改程式,回頭考慮在 books 補一個「最新出版日」實體欄
--                    (由 build_search_text.php 一併維護),但那要另外開票,
--                    因為「兩份資料要同步」是這個專案已經踩過的靜默失敗來源。
--
-- ★ 無論結果如何:年份篩選的等價性對帳(2026-09-19_m1b_search_rewrite_verify.sql 第一段)
--   在改完之後要**重跑一次**,確認筆數沒有變 —— 這次不能只看快不快。
-- =============================================================
