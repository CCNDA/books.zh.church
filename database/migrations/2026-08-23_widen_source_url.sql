-- ============================================================
-- 加寬 source_url / links.url(2026-08-23)
--
-- 起因:真哪噠(mezu)匯入時 `1406 Data too long for column 'source_url'`。
--   該站商品 handle 常是中文書名,percent-encoding 後**每個中文字變 9 個字元**
--   (「不」→ %E4%B8%8D),例如 20 字的中文書名網址就超過 200 字元,
--   長書名(含副標、冊次)輕易破 500。
--   editions.source_url 原為 VARCHAR(500)、links.url 原為 VARCHAR(700),
--   兩者皆**無索引**(editions 只有 idx_book / idx_publisher;links 只有
--   idx_book / idx_edition)→ 加寬不影響索引長度限制,零風險。
--
-- 決定寬度 1000:實測真哪噠最長網址 < 950;utf8mb4 下 VARCHAR(1000) 不進索引
--   沒有 3072 bytes 上限問題,InnoDB 行長也還有很大餘裕。
--
-- 可重跑(MariaDB 對相同定義的 MODIFY 是 no-op)。
-- ============================================================

ALTER TABLE editions MODIFY source_url VARCHAR(1000) NULL
  COMMENT '來源頁面(標注出處;中文 handle percent-encoding 後很長,8/23 由 500 加寬)';

ALTER TABLE links MODIFY url VARCHAR(1000) NOT NULL;

-- 驗證
-- SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE() AND (
--        (TABLE_NAME='editions' AND COLUMN_NAME='source_url')
--     OR (TABLE_NAME='links'    AND COLUMN_NAME='url'));
-- 預期:source_url 1000、url 1000
