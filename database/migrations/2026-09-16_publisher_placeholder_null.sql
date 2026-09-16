-- =============================================================
-- 2026-09-16  清除 publisher 佔位值「其他」「其它」→ NULL
-- Asana: 1216467657931851(M1-B 出版社頁前置)
--
-- 熊哥裁示 2026-09-16:「兩筆都設 NULL」。
--
-- 為什麼是 NULL 而不是歸一:
--   別名清單顯示「中國主日學(其它)」「人光其它」「以斯拉(其它)」都已被當成別名收攏,
--   可見「其它」是來源站商品分類的殘留後綴,不是出版社名稱。
--   歸一的話,出版社頁會出現一個叫「其他」的出版社,點進去是一堆不相干的書。
--   設 NULL = 誠實表示「這些書的出版社不明」。
--
-- ★ 為什麼兩處都要清:
--   v_book_list 的 publisher 是
--     COALESCE((從 editions→publishers 取,跟隨 canonical_id), books.publisher)
--   只清一邊,另一邊會被 COALESCE 撈回來。
--
-- ★ 為什麼不刪 publishers 那兩列:
--   刪除不可逆,而且會撞外鍵 fk_ed_pub。解除引用後那兩列成為孤立列,無害,
--   且保留了「來源曾經給過這個值」的痕跡。
--
-- 執行:熊哥本機 Navicat 對遠端 DB。本檔不需 FTP(migrations 一律不上傳)。
-- =============================================================

-- ═══ 第一部分:前置查詢(唯讀,先看會動到多少) ═══
-- ★ 先跑這三段、確認數字合理,再往下執行 UPDATE。

-- (1) 這兩筆 publisher 的 id 與現況
SELECT 'PRE-1' AS q, publisher_id, name_zh, canonical_id
  FROM publishers
 WHERE TRIM(name_zh) IN ('其他','其它');

-- (2) 會被清掉的 books 平面欄筆數
SELECT 'PRE-2' AS q, publisher, COUNT(*) AS 本數
  FROM books
 WHERE TRIM(publisher) IN ('其他','其它')
 GROUP BY publisher;

-- (3) 會被清掉的 editions.publisher_id 筆數
SELECT 'PRE-3' AS q, COUNT(*) AS editions筆數
  FROM editions
 WHERE publisher_id IN (
       SELECT publisher_id FROM publishers WHERE TRIM(name_zh) IN ('其他','其它'));

-- (4) ★ 順便找出其他可能的佔位值(本次不處理,只列出來判斷要不要另開票)
SELECT 'PRE-4' AS q, p.name_zh,
       (SELECT COUNT(*) FROM books b WHERE b.publisher = p.name_zh) AS books本數
  FROM publishers p
 WHERE TRIM(p.name_zh) IN ('未知','無','N/A','NA','-','—','null','NULL','不明','其他出版社','未分類')
    OR CHAR_LENGTH(TRIM(p.name_zh)) <= 1
 ORDER BY books本數 DESC;


-- ═══ 第二部分:實際更新 ═══
-- 順序重要:先解除 editions 的外鍵引用,再清 books 平面欄。

-- (A) editions.publisher_id → NULL(該欄可為 NULL,外鍵允許)
UPDATE editions
   SET publisher_id = NULL
 WHERE publisher_id IN (
       SELECT publisher_id FROM publishers WHERE TRIM(name_zh) IN ('其他','其它'));

-- (B) books.publisher → NULL
UPDATE books
   SET publisher = NULL
 WHERE TRIM(publisher) IN ('其他','其它');


-- ═══ 第三部分:★ 驗證查詢(務必執行,全部應回 0) ═══

-- (1) books 平面欄應無殘留
SELECT 'POST-1' AS q, COUNT(*) AS 應為0
  FROM books WHERE TRIM(publisher) IN ('其他','其它');

-- (2) editions 應無指向那兩列
SELECT 'POST-2' AS q, COUNT(*) AS 應為0
  FROM editions
 WHERE publisher_id IN (
       SELECT publisher_id FROM publishers WHERE TRIM(name_zh) IN ('其他','其它'));

-- (3) ★ 從 view 確認真的不再出現(這才是使用者看到的)
SELECT 'POST-3' AS q, COUNT(*) AS 應為0
  FROM v_book_list WHERE publisher IN ('其他','其它');

-- (4) 受影響的書現在 publisher 為 NULL,抽查幾本
SELECT 'POST-4' AS q, book_id, title, publisher, source
  FROM v_book_list
 WHERE publisher IS NULL
 ORDER BY book_id
 LIMIT 10;


-- ═══ 登錄到 schema_migrations ═══
-- ★ 需先執行 2026-09-16_schema_migrations.sql 建表,否則本段會失敗。
INSERT INTO `schema_migrations` (`version`, `applied_at`, `note`) VALUES
  ('2026-09-16_publisher_placeholder_null.sql', NOW(),
   '清除 publisher 佔位值 其他/其它 → NULL(熊哥裁示);不刪 publishers 列')
ON DUPLICATE KEY UPDATE `applied_at` = NOW();
