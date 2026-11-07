-- 簡繁版本對照表（Asana 1218475436576276、子票 1220314648553834）
-- 熊哥 2026-09-14：「合併，但是簡體繁體，在詳細內容頁可以註明有繁體和簡體版；如果 ISBN 編碼不一樣，應該就要拆開了」
-- 熊哥 2026-11-07 15:1x TG 選「甲 對照表＋後台確認」：自動比對約八成準，先在後台一組一組確認，確認過（decision=same）的才在書目頁顯示。
-- ★ 可重跑：CREATE TABLE IF NOT EXISTS；schema_migrations 用 ON DUPLICATE KEY UPDATE。

CREATE TABLE IF NOT EXISTS edition_pairs (
  pair_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  simp_book_id  INT UNSIGNED NOT NULL COMMENT '簡體那本 book_id',
  trad_book_id  INT UNSIGNED NOT NULL COMMENT '正體那本 book_id',
  match_how     VARCHAR(40)  DEFAULT NULL COMMENT '書名逐字相同／書名 N 成相同',
  same_ratio    DECIMAL(4,3) DEFAULT NULL COMMENT '書名逐字相同的比例（0～1）',
  pub_how       VARCHAR(20)  DEFAULT NULL COMMENT '出版社相同／ISBN 號段相同',
  decision      ENUM('same','different','unsure') DEFAULT NULL COMMENT 'same=同一本書的簡繁兩版（書目頁顯示）different=不是 unsure=看不出來；NULL=待判',
  decided_by    VARCHAR(120) DEFAULT NULL,
  decided_at    DATETIME     DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (pair_id),
  UNIQUE KEY uq_pair (simp_book_id, trad_book_id),
  KEY idx_decision (decision),
  KEY idx_trad (trad_book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 驗證：表在、欄位 10 個（應回 10）
SELECT COUNT(*) AS 欄位數 FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edition_pairs';

INSERT INTO schema_migrations (version, applied_at, note) VALUES
  ('2026-11-07_edition_pairs.sql', NOW(), '簡繁版本對照表 edition_pairs（後台確認後書目頁顯示另有簡體／繁體版）')
ON DUPLICATE KEY UPDATE note = VALUES(note);
