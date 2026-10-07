-- =============================================================
-- 出版社別名變體盤點(唯讀,不改資料)
-- 2026-09-16 / 對應熊哥裁示:其他=其它同義、橄欖=橄欖華宣同一家、道聲≠香港道聲
--
-- 用法:在 Navicat 開啟本檔,逐段執行(每段都有 q 標記欄,貼回時我能辨認是哪一段)
-- =============================================================

-- ── Q1:待裁示的三組 + 商品碼殘留 ──────────────────────────
SELECT 'Q1' AS q,
       p.publisher_id,
       p.name_zh,
       p.canonical_id,
       c.name_zh AS 目前指向,
       (SELECT COUNT(*) FROM books b WHERE b.publisher = p.name_zh) AS books平面欄
  FROM publishers p
  LEFT JOIN publishers c ON c.publisher_id = p.canonical_id
 WHERE p.name_zh LIKE '%橄欖%'
    OR p.name_zh LIKE '%道聲%'
    OR p.name_zh IN ('其他','其它')
    OR p.name_zh LIKE '%其它%'
    OR p.name_zh LIKE '%(其他)%'
    OR p.name_zh REGEXP '-[A-Z0-9]{3,4}$'
 ORDER BY p.name_zh;


-- ── Q2:★ canonical 鏈結檢查(A→B→C)──────────────────────
-- v_book_list 只做一次 COALESCE(canonical_id, publisher_id),
-- 所以 A→B→C 只會收斂到 B。本查詢應回 0 列;有列就要打平成直接指向最終正規列。
SELECT 'Q2' AS q,
       a.publisher_id AS 別名id, a.name_zh AS 別名,
       b.publisher_id AS 中間id, b.name_zh AS 中間層,
       c.publisher_id AS 最終id, c.name_zh AS 最終正規名
  FROM publishers a
  JOIN publishers b ON b.publisher_id = a.canonical_id
  JOIN publishers c ON c.publisher_id = b.canonical_id
 WHERE a.canonical_id IS NOT NULL
   AND b.canonical_id IS NOT NULL;


-- ── Q3:「其他/其它」這兩筆到底掛了哪些書、來自哪些來源 ──────
-- 判斷它們是佔位值(建議設 NULL)還是真的出版社名
SELECT 'Q3' AS q,
       b.publisher, e.source, COUNT(DISTINCT b.book_id) AS 本數
  FROM books b
  JOIN editions e ON e.book_id = b.book_id
 WHERE b.publisher IN ('其他','其它')
 GROUP BY b.publisher, e.source
 ORDER BY 本數 DESC;


-- ── Q4:series 表未落實的查證(v1.13.0 遺留)──────────────────
-- akow_category_map 註解寫「書系一律 NULL 改寫 series」,但 series 表 0 列
SELECT 'Q4' AS q,
       COUNT(*) AS 有書系值的書
  FROM books WHERE series IS NOT NULL AND series <> '';

SELECT 'Q4b' AS q, source, COUNT(*) AS 本數
  FROM books WHERE series IS NOT NULL AND series <> ''
 GROUP BY source ORDER BY 本數 DESC;
