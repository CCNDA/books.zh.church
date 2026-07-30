-- 003:擴充 books 以承接「整合_完整書籍資料庫模板 00_快速匯入表」全部欄位
-- 原則:高頻顯示欄位開專欄;其餘進 extra(JSON)不丟資料,階段三再拆正規化表
-- 已上線環境直接執行本檔即可(001/002 之後)

SET NAMES utf8mb4;

ALTER TABLE books
  ADD COLUMN editors           VARCHAR(255) NULL COMMENT '編者/主編(;分隔)' AFTER translator,
  ADD COLUMN original_title    VARCHAR(255) NULL COMMENT '原文書名' AFTER subtitle,
  ADD COLUMN edition_statement VARCHAR(100) NULL COMMENT '版次資訊' AFTER publish_date,
  ADD COLUMN series            VARCHAR(255) NULL COMMENT '系列(名稱#冊次;可多筆)' AFTER edition_statement,
  ADD COLUMN page_count        INT UNSIGNED NULL AFTER series,
  ADD COLUMN binding           VARCHAR(50)  NULL COMMENT '裝幀' AFTER page_count,
  ADD COLUMN language          VARCHAR(50)  NULL COMMENT '語言(;分隔)' AFTER binding,
  ADD COLUMN keywords          VARCHAR(500) NULL COMMENT '關鍵字(;分隔)' AFTER summary,
  ADD COLUMN summary_short     VARCHAR(500) NULL COMMENT '短書介(80-150字)' AFTER summary,
  ADD COLUMN extra             TEXT         NULL COMMENT 'JSON:未映射欄位(contributors/ean_upc/多ISBN/多封面/subjects原值等)' AFTER buy_links;

-- 既有 summary 定位為「長書介」(summary_long);summary_short 供列表卡片使用
