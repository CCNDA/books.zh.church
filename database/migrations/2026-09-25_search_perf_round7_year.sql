-- =============================================================
-- 2026-09-25  第七輪:年份篩選 5 秒 —— 候選解要在 COUNT 上比,不是只在取列上比
-- Asana: 1218643959919910([Bug] 年份篩選 year_from/year_to 線上 5.2 秒)
--
-- ★ 本檔唯讀,不改 schema、不改資料。整批跑,貼回 SHOW PROFILES 與最後三段結果。
-- ★ 取代 2026-09-19_search_perf_round6_year.sql(該檔的關鍵句已併入本檔第一部分)。
--
-- ════════ 9/25 線上黑箱實測(瀏覽器 fetch,ms,各跑 2 次)════════
--   無條件瀏覽 p1                 524 / 632
--   無條件瀏覽 p1000              263            ← 深分頁本身不貴
--   q=喜樂(212 筆)               922 / 661
--   ─────────────────────────────────────────
--   year_from=2020(9,969 筆)     5,072 / 5,061
--   year_to=2015(26,628 筆)      4,970 / 5,204
--   q=喜樂 & year_from=2020(28 筆) 5,769 / 6,835
--
-- ════════ 從黑箱數字推出的假設(★ 待本檔的 profile 證實,尚未證實)════════
--   year_from=2020 per_page=1      4,135     ← 只取 1 列也要 4.1 秒
--   year_from=2020 per_page=20     4,957
--   year_from=2020 page=400        9,866     ← 深分頁再多 5 秒
--   year_to=2015   per_page=1      4,648
--
--   (a) per_page=1 仍要 4.1 秒 → **固定成本約 4 秒,與取幾列無關**。
--       查詢裡唯一與取列筆數無關、且必須評估全表的,就是 COUNT(*)。
--   (b) 沒有年份條件時 page=1000 只要 263 ms → 深分頁本身不貴,
--       貴的是「年份運算式 × 每一列」。
--   (c) year_to 命中 26,628 筆(佔全站近半),取列理論上掃 40 列就該湊滿 20 筆,
--       卻同樣 4.6 秒 → 再次指向 COUNT 而非取列。
--
--   ⇒ 假設:**主要成本在 COUNT(*),不在取列。**
--     COUNT 沒有 LIMIT,必須對全部上架書各跑一次
--     `COALESCE((SELECT MAX(ey.publish_date) …), b.publish_date)`
--     這個 DEPENDENT SUBQUERY —— 5 萬多次索引查找。
--
--   ★ 這與前三輪的盲點正好相反(前三輪只量 COUNT、沒量取列;這次可能是 COUNT)。
--     所以本檔對**每一個候選解都量兩次:COUNT 一次、取列一次**。
--     只在取列上比出來的「快」,在本題上可能完全沒有意義。
--
-- ★ 三個候選解的差別(先講清楚語意,不要只看秒數):
--     A 現行     COALESCE(相關子查詢 MAX, 平面欄)      語意基準,其他版本都要跟它對筆數
--     B 衍生表   LEFT JOIN (GROUP BY book_id) + COALESCE 語意與 A 完全相同
--     C CTE 下推 INNER JOIN,HAVING 先篩掉不合格的 book  ★ 少了平面欄後備層,
--                                                         只有在第六部分「只靠平面欄後備 = 0」
--                                                         成立時才等價,且必須加對帳守住
--     D OR 拆解  EXISTS … OR (NOT EXISTS … AND 平面欄)   語意與 A 相同,但 COUNT 會跑兩個 EXISTS
--
--   ※ 純 EXISTS 版(9/17 留言建議過的)**不列入候選**:year_from 上同值,
--     但 year_to 實測差 613 本(「最新版 ≤ 2015」與「存在某版 ≤ 2015」是不同的問題)。
--     本檔第五部分會把這 613 本再驗一次,當成「語意警戒線」留著。
-- =============================================================

SET profiling = 1;
SET profiling_history_size = 60;


-- ═══════════ 第一部分:確認真兇是 COUNT 還是取列 ═══════════
-- ★ 這是本輪唯一的必答題。(1)(2) 慢而 (3) 快 → 假設成立,後面只需要看候選解的 COUNT。

-- (1) A 現行 · COUNT · year_from
SELECT SQL_NO_CACHE COUNT(*) AS c_A_count_yf
  FROM books b
 WHERE b.is_published = 1
   AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                  WHERE ey.book_id = b.book_id), b.publish_date) >= '2020';

-- (2) A 現行 · COUNT · year_to
SELECT SQL_NO_CACHE COUNT(*) AS c_A_count_yt
  FROM books b
 WHERE b.is_published = 1
   AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                  WHERE ey.book_id = b.book_id), b.publish_date) <= '2015-12-31';

-- (3) A 現行 · 取列 20 筆 · year_from · 第 1 頁
SELECT SQL_NO_CACHE b.book_id
  FROM books b
 WHERE b.is_published = 1
   AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                  WHERE ey.book_id = b.book_id), b.publish_date) >= '2020'
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- (4) A 現行 · 取列 20 筆 · year_from · OFFSET 8000(深分頁,黑箱量到 +5 秒)
SELECT SQL_NO_CACHE b.book_id
  FROM books b
 WHERE b.is_published = 1
   AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                  WHERE ey.book_id = b.book_id), b.publish_date) >= '2020'
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 8000;

-- (5) 對照:COUNT 不帶年份(9/19 實測 0.288 s)
SELECT SQL_NO_CACHE COUNT(*) AS c_base_count
  FROM books b
 WHERE b.is_published = 1;

-- (6) 對照:取列不帶年份(9/19 實測 0.0004 s)
SELECT SQL_NO_CACHE b.book_id
  FROM books b
 WHERE b.is_published = 1
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;


-- ═══════════ 第二部分:候選解的 COUNT(真兇若在這,就得在這分勝負)═══════════

-- (7) B 衍生表 · COUNT · year_from ── 語意與 A 完全相同
SELECT SQL_NO_CACHE COUNT(*) AS c_B_count_yf
  FROM books b
  LEFT JOIN (SELECT book_id, MAX(publish_date) AS pd
               FROM editions GROUP BY book_id) m ON m.book_id = b.book_id
 WHERE b.is_published = 1
   AND COALESCE(m.pd, b.publish_date) >= '2020';

-- (8) B 衍生表 · COUNT · year_to
SELECT SQL_NO_CACHE COUNT(*) AS c_B_count_yt
  FROM books b
  LEFT JOIN (SELECT book_id, MAX(publish_date) AS pd
               FROM editions GROUP BY book_id) m ON m.book_id = b.book_id
 WHERE b.is_published = 1
   AND COALESCE(m.pd, b.publish_date) <= '2015-12-31';

-- (9) C CTE 下推 · COUNT · year_from
--     ★ 條件在 HAVING 先篩掉,JOIN 進來的列數少很多。
--     ★ 但這版**沒有平面欄後備**,等價性靠第六部分的「只靠平面欄後備 = 0」撐著。
WITH ed AS (
  SELECT book_id, MAX(publish_date) AS pd
    FROM editions
   WHERE publish_date IS NOT NULL
   GROUP BY book_id
  HAVING pd >= '2020'
)
SELECT SQL_NO_CACHE COUNT(*) AS c_C_count_yf
  FROM books b JOIN ed ON ed.book_id = b.book_id
 WHERE b.is_published = 1;

-- (10) C CTE 下推 · COUNT · year_to
WITH ed AS (
  SELECT book_id, MAX(publish_date) AS pd
    FROM editions
   WHERE publish_date IS NOT NULL
   GROUP BY book_id
  HAVING pd <= '2015-12-31'
)
SELECT SQL_NO_CACHE COUNT(*) AS c_C_count_yt
  FROM books b JOIN ed ON ed.book_id = b.book_id
 WHERE b.is_published = 1;

-- (11) D OR 拆解 · COUNT · year_from ── 語意與 A 相同,但要跑兩個 EXISTS
SELECT SQL_NO_CACHE COUNT(*) AS c_D_count_yf
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
       );


-- ═══════════ 第三部分:候選解的取列(page=1 與深分頁各一次)═══════════

-- (12) B 衍生表 · 取列 · year_from · 第 1 頁
SELECT SQL_NO_CACHE b.book_id
  FROM books b
  LEFT JOIN (SELECT book_id, MAX(publish_date) AS pd
               FROM editions GROUP BY book_id) m ON m.book_id = b.book_id
 WHERE b.is_published = 1
   AND COALESCE(m.pd, b.publish_date) >= '2020'
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- (13) B 衍生表 · 取列 · year_from · OFFSET 8000
SELECT SQL_NO_CACHE b.book_id
  FROM books b
  LEFT JOIN (SELECT book_id, MAX(publish_date) AS pd
               FROM editions GROUP BY book_id) m ON m.book_id = b.book_id
 WHERE b.is_published = 1
   AND COALESCE(m.pd, b.publish_date) >= '2020'
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 8000;

-- (14) C CTE 下推 · 取列 · year_from · 第 1 頁
WITH ed AS (
  SELECT book_id, MAX(publish_date) AS pd
    FROM editions
   WHERE publish_date IS NOT NULL
   GROUP BY book_id
  HAVING pd >= '2020'
)
SELECT SQL_NO_CACHE b.book_id
  FROM books b JOIN ed ON ed.book_id = b.book_id
 WHERE b.is_published = 1
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- (15) C CTE 下推 · 取列 · year_from · OFFSET 8000
WITH ed AS (
  SELECT book_id, MAX(publish_date) AS pd
    FROM editions
   WHERE publish_date IS NOT NULL
   GROUP BY book_id
  HAVING pd >= '2020'
)
SELECT SQL_NO_CACHE b.book_id
  FROM books b JOIN ed ON ed.book_id = b.book_id
 WHERE b.is_published = 1
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 8000;

-- (16) D OR 拆解 · 取列 · year_from · 第 1 頁
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


-- ═══════════ 第四部分:關鍵字 + 年份(API 實際送出的 STRAIGHT_JOIN 形狀)═══════════
-- ★ 黑箱量到「q=喜樂 & year_from」只命中 28 筆,卻比 year_from 單獨(9,969 筆)還慢
--   —— 命中少反而慢,與第五輪同型,要確認 STRAIGHT_JOIN 在帶年份時有沒有被抵消。

-- (17) A 現行 · COUNT · q + year_from(COUNT 沒有 STRAIGHT_JOIN,照 API 原形)
SELECT SQL_NO_CACHE COUNT(*) AS c_A_count_q_yf
  FROM books b JOIN book_search bs ON bs.book_id = b.book_id
 WHERE b.is_published = 1
   AND (bs.search_key LIKE '%喜樂%' OR bs.search_key LIKE '%喜樂%')
   AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                  WHERE ey.book_id = b.book_id), b.publish_date) >= '2020';

-- (18) A 現行 · 取列 · q + year_from
SELECT SQL_NO_CACHE STRAIGHT_JOIN b.book_id
  FROM book_search bs
  JOIN books b ON b.book_id = bs.book_id
 WHERE b.is_published = 1
   AND (bs.search_key LIKE '%喜樂%' OR bs.search_key LIKE '%喜樂%')
   AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                  WHERE ey.book_id = b.book_id), b.publish_date) >= '2020'
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- (19) 對照:同 (18) 拿掉年份(9/19 實測 0.388 s)
SELECT SQL_NO_CACHE STRAIGHT_JOIN b.book_id
  FROM book_search bs
  JOIN books b ON b.book_id = bs.book_id
 WHERE b.is_published = 1
   AND (bs.search_key LIKE '%喜樂%' OR bs.search_key LIKE '%喜樂%')
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

-- (20) B 衍生表 · 取列 · q + year_from
SELECT SQL_NO_CACHE STRAIGHT_JOIN b.book_id
  FROM book_search bs
  JOIN books b ON b.book_id = bs.book_id
  LEFT JOIN (SELECT book_id, MAX(publish_date) AS pd
               FROM editions GROUP BY book_id) m ON m.book_id = b.book_id
 WHERE b.is_published = 1
   AND (bs.search_key LIKE '%喜樂%' OR bs.search_key LIKE '%喜樂%')
   AND COALESCE(m.pd, b.publish_date) >= '2020'
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;

SHOW PROFILES;


-- ═══════════ 第五部分:等價性對帳(★ 比秒數更重要,先看這個)═══════════
-- 四種寫法的筆數必須一模一樣。year_to 那一列是關鍵:
-- 純 EXISTS 版在這裡會差 613 本,C 版(CTE 下推)若也差就代表後備層不能省。

SELECT 'year_from=2020' AS 條件,
       (SELECT COUNT(*) FROM books b WHERE b.is_published = 1
         AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                        WHERE ey.book_id = b.book_id), b.publish_date) >= '2020')   AS A_現行,
       (SELECT COUNT(*) FROM books b
          LEFT JOIN (SELECT book_id, MAX(publish_date) AS pd
                       FROM editions GROUP BY book_id) m ON m.book_id = b.book_id
         WHERE b.is_published = 1 AND COALESCE(m.pd, b.publish_date) >= '2020')      AS B_衍生表,
       (SELECT COUNT(*) FROM books b
          JOIN (SELECT book_id, MAX(publish_date) AS pd FROM editions
                 WHERE publish_date IS NOT NULL GROUP BY book_id HAVING pd >= '2020') e2
            ON e2.book_id = b.book_id
         WHERE b.is_published = 1)                                                   AS C_CTE下推,
       (SELECT COUNT(*) FROM books b WHERE b.is_published = 1
         AND (EXISTS (SELECT 1 FROM editions ey WHERE ey.book_id = b.book_id
                       AND ey.publish_date IS NOT NULL AND ey.publish_date >= '2020')
              OR (NOT EXISTS (SELECT 1 FROM editions ey2 WHERE ey2.book_id = b.book_id
                               AND ey2.publish_date IS NOT NULL)
                  AND b.publish_date >= '2020')))                                    AS D_OR拆解,
       (SELECT COUNT(*) FROM books b WHERE b.is_published = 1
         AND EXISTS (SELECT 1 FROM editions ey WHERE ey.book_id = b.book_id
                      AND ey.publish_date >= '2020'))                                AS 純EXISTS_警戒線
UNION ALL
SELECT 'year_to=2015',
       (SELECT COUNT(*) FROM books b WHERE b.is_published = 1
         AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                        WHERE ey.book_id = b.book_id), b.publish_date) <= '2015-12-31'),
       (SELECT COUNT(*) FROM books b
          LEFT JOIN (SELECT book_id, MAX(publish_date) AS pd
                       FROM editions GROUP BY book_id) m ON m.book_id = b.book_id
         WHERE b.is_published = 1 AND COALESCE(m.pd, b.publish_date) <= '2015-12-31'),
       (SELECT COUNT(*) FROM books b
          JOIN (SELECT book_id, MAX(publish_date) AS pd FROM editions
                 WHERE publish_date IS NOT NULL GROUP BY book_id HAVING pd <= '2015-12-31') e2
            ON e2.book_id = b.book_id
         WHERE b.is_published = 1),
       (SELECT COUNT(*) FROM books b WHERE b.is_published = 1
         AND (EXISTS (SELECT 1 FROM editions ey WHERE ey.book_id = b.book_id
                       AND ey.publish_date IS NOT NULL AND ey.publish_date <= '2015-12-31')
              OR (NOT EXISTS (SELECT 1 FROM editions ey2 WHERE ey2.book_id = b.book_id
                               AND ey2.publish_date IS NOT NULL)
                  AND b.publish_date <= '2015-12-31'))),
       (SELECT COUNT(*) FROM books b WHERE b.is_published = 1
         AND EXISTS (SELECT 1 FROM editions ey WHERE ey.book_id = b.book_id
                      AND ey.publish_date <= '2015-12-31'));
-- ★ 判讀:A = B 一定要成立(B 只是把同一個運算式換個算法)。A ≠ B 就是我寫錯,不要往下走。
--   A = C 只在「只靠平面欄後備 = 0」時成立(見第六部分)。
--   純EXISTS 那一欄在 year_to 上本來就會偏多,它擺在這裡是警戒線,不是候選。


-- ═══════════ 第六部分:後備層現況 ★ 必須重驗,不可沿用 9/19 的數字 ═══════════
-- 9/19 量到「只靠平面欄後備 = 0」,但那是 **bappress(第十八來源,3,671 種)匯入之前**。
-- 資料變了,這個數字就要重量 —— C 版能不能用,完全押在這一格上。
SELECT COUNT(*) AS 上架書,
       SUM(EXISTS (SELECT 1 FROM editions ey
                    WHERE ey.book_id = b.book_id AND ey.publish_date IS NOT NULL))
         AS 有edition日期,
       SUM(b.publish_date IS NOT NULL AND b.publish_date <> '') AS 有平面欄日期,
       SUM(NOT EXISTS (SELECT 1 FROM editions ey
                        WHERE ey.book_id = b.book_id AND ey.publish_date IS NOT NULL)
           AND b.publish_date IS NOT NULL AND b.publish_date <> '')
         AS 只靠平面欄後備
  FROM books b
 WHERE b.is_published = 1;
-- ★★ 「只靠平面欄後備」不是 0 → C 版直接出局,不必再看它多快。
--    是 0 → C 版可用,但程式裡必須寫明「這是資料現況決定的,不是語意上等價」,
--          並且要有一條對帳(建議併進對帳守門員那張票)在它變成非 0 時叫出來。
--          ★ 沒有那條對帳就用 C 版 = 埋一個沒有徵兆的靜默失敗。


-- ═══════════ 第七部分:執行計畫(貼回這三張)═══════════
EXPLAIN SELECT COUNT(*)
  FROM books b
 WHERE b.is_published = 1
   AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                  WHERE ey.book_id = b.book_id), b.publish_date) >= '2020';

EXPLAIN SELECT COUNT(*)
  FROM books b
  LEFT JOIN (SELECT book_id, MAX(publish_date) AS pd
               FROM editions GROUP BY book_id) m ON m.book_id = b.book_id
 WHERE b.is_published = 1
   AND COALESCE(m.pd, b.publish_date) >= '2020';

EXPLAIN SELECT b.book_id
  FROM books b
 WHERE b.is_published = 1
   AND COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                  WHERE ey.book_id = b.book_id), b.publish_date) >= '2020'
 ORDER BY b.created_at DESC, b.book_id DESC
 LIMIT 20 OFFSET 0;


-- =============================================================
-- 【判讀順序】先看對帳,再看秒數。
--
--  1. 第五部分 A ≠ B → 我寫錯了,停,不要選任何一個。
--  2. 第六部分「只靠平面欄後備」≠ 0 → C 出局。
--  3. 第一部分 (1)(2) 慢、(3) 快 → 假設成立:**真兇是 COUNT**。
--       → 候選解只看 (7)(9)(11) 這三句 COUNT 的秒數,取列那幾句是附帶確認。
--     (1)(2) 快而 (3) 慢 → 假設不成立,回到第五輪的 ORDER BY 翻轉那條路
--       (STRAIGHT_JOIN / 強制索引),本檔第三部分的取列句就是主戰場。
--     兩者都慢 → 成本在相關子查詢本身,B 與 C 是唯一出路。
--  4. 選中的版本必須同時滿足:COUNT < 1 s、取列 < 1 s、對帳筆數與 A 完全相同。
--     ★ 三項缺一就不改程式 —— 改了也只是把 5 秒搬到另一個參數上。
--  5. 三個候選都不行 → 才考慮 books 補實體欄「最新出版日」(由 build_search_text.php
--     一併維護)。那要另外開票:「兩份資料要同步」是本專案已經踩過的靜默失敗來源,
--     不能當成這張票的順手之作。
--
-- 【改完之後一定要做的】
--   - 重跑 2026-09-19_m1b_search_rewrite_verify.sql 第一段(等價性對帳),筆數不變才算數
--   - 線上重測四種組合:year_from / year_to / q+year_from / year_from 深分頁
--     ★ 深分頁那一項不能省:黑箱量到它是 9.9 秒,比第 1 頁更嚴重,
--       只測第 1 頁會得到「修好了」的錯覺
-- =============================================================
