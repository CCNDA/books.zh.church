-- 書目網出版年份修正：第一步，建舊值備份表（Asana 1220948673053089；小布 11/27 審查二）
-- DDL，要用 sudo ccnda-db-run <本檔> --commit 跑（試跑帳號沒有 DDL 權限）；可重跑。
-- 跑完再跑 2026-11-27_publish_date_fix.sql（那支會把每一列改前的值存進這張表）。
CREATE TABLE IF NOT EXISTS pubdate_fix_bak_20261127 (
  tbl              VARCHAR(10)  NOT NULL COMMENT 'editions 或 books',
  id               INT UNSIGNED NOT NULL COMMENT 'edition_id 或 book_id',
  old_publish_date VARCHAR(10)  NULL,
  saved_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tbl, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='2026-11-27 出版日期修正前的舊值（還原：UPDATE … JOIN 本表）';

INSERT INTO schema_migrations (version, applied_at, note) VALUES
  ('2026-11-27_publish_date_fix_bak.sql', NOW(), '出版日期修正前的舊值備份表 pubdate_fix_bak_20261127')
ON DUPLICATE KEY UPDATE note = VALUES(note);
