-- ============================================================
-- books.extra / buy_links:TEXT → MEDIUMTEXT
-- 建立日:2026-08-18
--
-- 背景:匯入第七來源(osb 格子外面)時,合併書的 extra JSON
--   (=各來源原始紀錄依來源分鍵整包保存,osb 另含 sections 大段文字)
--   首次超過 TEXT 上限 65,535 bytes。MariaDB 嚴格模式在截斷點落在
--   多位元組字元時拋 1366 Incorrect string value(訊息顯示截斷點
--   字節,極易誤判為 charset 問題——全表實為 utf8mb4,已驗證)。
-- 修法:extra 擴為 MEDIUMTEXT(16MB);buy_links(七來源購書連結
--   JSON 陣列)預防性一併擴。summary 為單段文字,維持 TEXT。
-- 可重跑:MODIFY 冪等。
-- ============================================================

ALTER TABLE books
  MODIFY extra     MEDIUMTEXT NULL COMMENT 'JSON:未映射欄位,依來源分鍵(2026-08-18 TEXT→MEDIUMTEXT,64KB 不敷七來源合併)',
  MODIFY buy_links MEDIUMTEXT NULL COMMENT 'JSON:[{"platform":"...","url":"..."}](2026-08-18 TEXT→MEDIUMTEXT)';

-- 驗證:
--   SHOW COLUMNS FROM books LIKE 'extra';       -- Type 應為 mediumtext
--   SELECT MAX(LENGTH(extra)) FROM books;       -- 目前最大值(修前應已逼近 65535 才會爆)
--   SELECT COUNT(*) FROM books
--     WHERE extra IS NOT NULL AND JSON_VALID(extra) = 0;  -- 應為 0(確認過去無無聲截斷)
