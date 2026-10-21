-- 重複書複核表（Asana 1219668289655146）
-- 熊哥 2026-10-20 TG「應該是用後台處理才對」：bappress 疑似重複清單在後台一筆一筆判，不用 TSV 交人看。
-- 熊哥 2026-10-21 裁示：dup-import-A（按下去只記錄，不匯入；之後小芯用工具 dev 演練、熊哥一次補匯）、
--   dup-who-A（先只開給熊哥，沿用現有後台 Access）、dup-scope-A（先做 bappress，表設計成可加別的清單來源）。
-- ★ 可重跑：CREATE TABLE IF NOT EXISTS；schema_migrations 用 ON DUPLICATE KEY UPDATE。

CREATE TABLE IF NOT EXISTS dup_review (
  review_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  list_name         VARCHAR(40)  NOT NULL COMMENT '清單來源：bappress_dup（之後可加 title_publisher 等）',
  source            VARCHAR(20)  NOT NULL COMMENT '來源書房代碼（bappress）',
  source_key        VARCHAR(64)  NOT NULL COMMENT '來源貨號／pid',
  src_title         VARCHAR(255) DEFAULT NULL,
  src_author        VARCHAR(255) DEFAULT NULL,
  src_publisher     VARCHAR(255) DEFAULT NULL,
  src_isbn          VARCHAR(20)  DEFAULT NULL,
  src_url           VARCHAR(500) DEFAULT NULL,
  src_cover         VARCHAR(500) DEFAULT NULL,
  candidate_book_id INT UNSIGNED NOT NULL COMMENT '站上疑似同一本的 book_id',
  match_how         VARCHAR(40)  DEFAULT NULL COMMENT '書名相同／書名互為前綴／共同前綴N字（可信度較低）',
  decision          ENUM('same','different','unsure') DEFAULT NULL COMMENT 'same=同一本（不匯入）different=不同書（之後補匯入）unsure=看不出來',
  decided_by        VARCHAR(120) DEFAULT NULL,
  decided_at        DATETIME     DEFAULT NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (review_id),
  UNIQUE KEY uq_list_pair (list_name, source, source_key, candidate_book_id),
  KEY idx_pending (list_name, decision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 驗證：表在、欄位 16 個（應回 16）
SELECT COUNT(*) AS 欄位數 FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dup_review';

INSERT INTO schema_migrations (version, applied_at, note) VALUES
  ('2026-10-21_dup_review.sql', NOW(), '重複書複核表 dup_review（後台 /admin/dup.php）')
ON DUPLICATE KEY UPDATE note = VALUES(note);
