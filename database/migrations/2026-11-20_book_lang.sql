-- 語言篩選用的小表（M1-B；Asana 1220724721485985）
-- 不在 books（約 500MB）上加欄位：正式庫 ALTER 會整表重建、鎖很久。照 book_search 瘦表的做法另開小表。
-- 內容由 tools/build_book_lang.php 全量重建（冪等）；這支 migration 只建表，重跑不會出錯。
CREATE TABLE IF NOT EXISTS book_lang (
  book_id    INT UNSIGNED NOT NULL,
  lang_group VARCHAR(8)   NOT NULL COMMENT 'zh-Hant／zh-Hans／en／multi／other；未填的書不放進來',
  PRIMARY KEY (book_id),
  KEY idx_lang_book (lang_group, book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
