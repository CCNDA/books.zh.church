-- 真哪噠書系欄帶「名稱」標籤、語言欄是「別」（Asana 1218961225060659）
-- 成因：站方規格表的標籤是「系列名稱」「語文別」，爬蟲只認得「系列」「語文」，把標籤剩下的字當成值的開頭。
--   爬蟲已修（fix/mezu-series-label）；但 import.php 對既有書是 COALESCE(series, …)，舊資料不會自己變好 → 這支修。
-- 只剝標籤：「名稱 Meet 40」→「Meet 40」、「名稱：反思系列」→「反思系列」；冊次記號保留。整格只有「名稱」的 5 本改成 NULL。
-- 改到的書把 search_key／search_text 清空，跑完請接著跑 php tools/build_search_text.php --missing（重建搜尋欄）。
-- ★ 重跑：條件綁在「名稱」開頭與「別」，第二次 A0 會回 0、UPDATE 改 0 列，不會改到別的東西。
-- 2026-10-31 正式庫唯讀查：書系 90 本（全部有真哪噠版本）、語言「別」2 本（都有真哪噠版本）。

START TRANSACTION;

-- A0. 要改的數量（10/31 查是 90 和 2）：差很多就 ROLLBACK，先回報
SELECT COUNT(*) AS 書系帶標籤_約90 FROM books WHERE series REGEXP '^名稱([[:space:]:：／/]|$)';
SELECT COUNT(*) AS 語言是別_約2 FROM books WHERE language = '別';

-- A1. 改完的樣子（不會寫入）：右欄應該都是乾淨的書系名，或 NULL
SELECT book_id, series, NULLIF(TRIM(REGEXP_REPLACE(series, '^名稱[[:space:]:：／/]*', '')), '') AS 改成
  FROM books WHERE series REGEXP '^名稱([[:space:]:：／/]|$)' ORDER BY book_id;

UPDATE books
   SET series = NULLIF(TRIM(REGEXP_REPLACE(series, '^名稱[[:space:]:：／/]*', '')), ''),
       search_key = NULL, search_text = NULL
 WHERE series REGEXP '^名稱([[:space:]:：／/]|$)';

UPDATE books
   SET language = NULL, search_key = NULL, search_text = NULL
 WHERE language = '別';

-- E1. 改完應該都是 0
SELECT COUNT(*) AS 還帶標籤_應為0 FROM books WHERE series REGEXP '^名稱([[:space:]:：／/]|$)';
SELECT COUNT(*) AS 語言還是別_應為0 FROM books WHERE language = '別';

INSERT INTO schema_migrations (version, applied_at, note) VALUES
  ('2026-10-31_mezu_series_label.sql', NOW(), '真哪噠書系剝「名稱」標籤、語言「別」改 NULL')
ON DUPLICATE KEY UPDATE note = VALUES(note);

-- A0 數量對、E1 都 0：COMMIT;   不對：ROLLBACK;
-- COMMIT 之後：php tools/build_search_text.php --missing
