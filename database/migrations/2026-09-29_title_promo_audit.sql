-- ============================================================================
-- 2026-09-29_title_promo_audit.sql
-- 書名混入促銷詞:全庫實查(唯讀,不改 schema、不改資料)
-- Asana 1218961224853655
--
-- 熊哥裁示(9/29):
--   (1) 剝除範圍 = 只剝促銷詞。絕版/缺書(庫存狀態)與「套書」不動。
--   (2) 既有資料 = 改 import + 一次性清理,但清理前要先出 dry-run 對照清單逐條看過。
--
-- 用 Navicat 對遠端 DB 整批跑,把第 1～6 部分的結果貼回 Asana。
-- ★ 對帳一律含下架書(is_published=0),所以下面刻意不過濾;但會分開列出。
-- ============================================================================


-- ─── 第 1 部分:促銷樣態,全庫筆數與上下架分布 ──────────────────────────────
-- 一個樣態一列,方便看哪一種最多、值不值得個別寫規則。
SELECT '折扣(NN折)'   AS 樣態, COUNT(*) AS 書數,
       SUM(is_published=1) AS 上架, SUM(is_published=0) AS 下架
  FROM books WHERE title REGEXP '[0-9]+[[:space:]]*折'
UNION ALL
SELECT '特價', COUNT(*), SUM(is_published=1), SUM(is_published=0)
  FROM books WHERE title LIKE '%特價%'
UNION ALL
SELECT '優惠', COUNT(*), SUM(is_published=1), SUM(is_published=0)
  FROM books WHERE title LIKE '%優惠%'
UNION ALL
SELECT '預購', COUNT(*), SUM(is_published=1), SUM(is_published=0)
  FROM books WHERE title LIKE '%預購%'
UNION ALL
SELECT '免運', COUNT(*), SUM(is_published=1), SUM(is_published=0)
  FROM books WHERE title LIKE '%免運%'
UNION ALL
SELECT '限時/限量', COUNT(*), SUM(is_published=1), SUM(is_published=0)
  FROM books WHERE title REGEXP '限時|限量'
UNION ALL
SELECT '瑕疵', COUNT(*), SUM(is_published=1), SUM(is_published=0)
  FROM books WHERE title LIKE '%瑕疵%'
UNION ALL
SELECT '促銷/特惠/下殺', COUNT(*), SUM(is_published=1), SUM(is_published=0)
  FROM books WHERE title REGEXP '促銷|特惠|下殺|破盤';


-- ─── 第 2 部分:去重後的總數(一本書可能同時命中兩個樣態) ────────────────────
-- ★ 第 1 部分的數字不可相加,要以這一個為準。
SELECT COUNT(*) AS 命中書數_不重複,
       SUM(is_published=1) AS 上架, SUM(is_published=0) AS 下架
  FROM books
 WHERE title REGEXP '[0-9]+[[:space:]]*折|特價|優惠|預購|免運|限時|限量|瑕疵|促銷|特惠|下殺|破盤';


-- ─── 第 3 部分:來源分布 ─────────────────────────────────────────────────
-- ★ 商品代碼與來源都不在 books 表,一律走 editions。
SELECT e.source, COUNT(DISTINCT b.book_id) AS 書數
  FROM books b
  JOIN editions e ON e.book_id = b.book_id
 WHERE b.title REGEXP '[0-9]+[[:space:]]*折|特價|優惠|預購|免運|限時|限量|瑕疵|促銷|特惠|下殺|破盤'
 GROUP BY e.source
 ORDER BY 書數 DESC;


-- ─── 第 4 部分:逐筆清單(決定剝除規則用,要看實際字面) ──────────────────────
-- 規則要「白名單式列舉」,不要寫寬鬆通則 —— 所以必須先看過真實樣本。
SELECT b.book_id, b.title,
       GROUP_CONCAT(DISTINCT e.source ORDER BY e.source) AS 來源,
       b.is_published
  FROM books b
  JOIN editions e ON e.book_id = b.book_id
 WHERE b.title REGEXP '[0-9]+[[:space:]]*折|特價|優惠|預購|免運|限時|限量|瑕疵|促銷|特惠|下殺|破盤'
 GROUP BY b.book_id, b.title, b.is_published
 ORDER BY 來源, b.book_id;


-- ─── 第 5 部分:★ 不在本次剝除範圍,但要知道有多少(熊哥裁示不動) ─────────────
-- 庫存/流通狀態。剝掉會失去「這本買不到」的資訊,本輪不處理,只記錄基準值。
SELECT '庫存狀態(絕版/缺書/售完/停版)' AS 樣態,
       COUNT(*) AS 書數, SUM(is_published=1) AS 上架, SUM(is_published=0) AS 下架
  FROM books WHERE title REGEXP '絕版|缺書|已售完|售完|停版'
UNION ALL
-- 「套書/合售/冊」是書名本身的一部分,**絕不可剝**,這裡只是留個量級對照。
SELECT '套書/合售(不可剝)', COUNT(*), SUM(is_published=1), SUM(is_published=0)
  FROM books WHERE title REGEXP '套書|合售|套裝';


-- ─── 第 6 部分:★ 促銷詞造成的重複書,先量一個下限 ──────────────────────────
-- 把最單純的一種尾註(整段括號在字尾)剝掉後,看有沒有同名的另一本書。
-- ★ 這只是下限:REPLACE/TRIM 做不到完整剝除,真正的清單要由 PHP dry-run 產。
--   已在線上實測到至少 4 組(87839↔57881、88945↔62842、79675↔102456、79674↔83271↔45452)。
SELECT a.book_id AS 髒的, a.title AS 髒書名,
       c.book_id AS 乾淨的, c.title AS 乾淨書名
  FROM books a
  JOIN books c
    ON c.book_id <> a.book_id
   AND c.title = TRIM(REGEXP_REPLACE(a.title,
         '[（(【\\[][^）)】\\]]*([0-9]+[[:space:]]*折|特價|優惠|免運|限時|限量|瑕疵|預購)[^）)】\\]]*[）)】\\]][[:space:]]*$',
         ''))
 WHERE a.title REGEXP '[（(【\\[][^）)】\\]]*([0-9]+[[:space:]]*折|特價|優惠|免運|限時|限量|瑕疵|預購)[^）)】\\]]*[）)】\\]][[:space:]]*$'
 ORDER BY a.book_id;


-- ============================================================================
-- 判讀順序(先看對帳,再談要不要改程式)
--
-- 1. 第 2 部分的總數若與第 3 部分各來源加總對不起來 → 有書掛多來源,正常;
--    但若第 3 部分某個來源是 0 而你記得它有促銷詞 → regex 漏了,先補 regex。
-- 2. 第 4 部分要**整份看過**再寫剝除規則。★ 只看前幾列就寫規則,
--    等於拿抽樣當全貌 —— 本專案已經因為抽樣偏差誤判過兩次。
-- 3. 第 6 部分回的組數是**下限**,不是最終要合併的清單。
--    合併一律走「dry-run 產對照清單 → 熊哥逐條複核 → merge_duplicate_books.php --pairs」,
--    不可依這段 SQL 直接合併(akow 那次 100 列裡有 5 列是不該併的)。
-- 4. 剝除規則寫好後,第 1～4 部分要**再跑一次**當作前後對照,
--    ★ 數字一律以這裡的實查為準,不用工具自印的。
-- ============================================================================
