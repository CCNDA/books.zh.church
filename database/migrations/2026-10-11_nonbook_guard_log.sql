-- =============================================================
-- 2026-10-11  非書籍守門紀錄表 nonbook_guard_log
-- Asana: 1219384830498351（非書籍守門）；判準：1218562704413649（收錄判準一）
--
-- 【為什麼】
--   2026-10-11 下架非書籍 146 筆後，熊哥問「有排進每日更新的程式中嗎」。
--   讀 tools/import.php：新書 INSERT 一律 is_published = 1，沒有關鍵字層 → 新的非書籍還是會上架。
--   tools/guard_nonbook.php 在每日新品後跑：A 級詞先下架、B 級詞只標記，都記在這張表。
--   小芯每天用唯讀帳號讀這張表、把清單傳熊哥確認；確認是書就改回上架並把 action 記成 restored。
--
-- 【欄位】
--   action：hidden（A 級，已設 is_published = 0）／flagged（B 級，沒動）／
--           restored（熊哥確認是書，已改回上架）／confirmed（熊哥確認是非書籍）
--   UNIQUE(book_id, action)：每日排程的 26 小時窗口會重疊，同一本書不會重複記同一個動作。
--   title_snapshot：記下當時的書名，日後書名被改也查得到為什麼被擋。
--
-- 冪等：CREATE TABLE IF NOT EXISTS；INSERT 用 ON DUPLICATE KEY UPDATE。
-- 執行方式：熊哥 Navicat 對正式庫執行（小芯沒有正式庫寫入權限）。
-- =============================================================

CREATE TABLE IF NOT EXISTS `nonbook_guard_log` (
  `log_id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `book_id`        INT UNSIGNED NOT NULL,
  `tier`           CHAR(1)      NOT NULL COMMENT 'A＝自動下架／B＝只標記',
  `word`           VARCHAR(20)  NOT NULL COMMENT '命中的關鍵字（tools/lib_nonbook.php）',
  `action`         ENUM('hidden','flagged','restored','confirmed') NOT NULL,
  `title_snapshot` VARCHAR(500) NULL,
  `source`         VARCHAR(30)  NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at`    DATETIME     NULL COMMENT '熊哥確認的時間',
  PRIMARY KEY (`log_id`),
  UNIQUE KEY `uq_book_action` (`book_id`, `action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='非書籍守門紀錄（guard_nonbook.php）';

INSERT INTO `schema_migrations` (`version`, `applied_at`, `note`) VALUES
  ('2026-10-11_nonbook_guard_log.sql', NOW(), '非書籍守門紀錄表（每日新品 A 級先下架、B 級只標記）')
ON DUPLICATE KEY UPDATE `note` = VALUES(`note`);

-- 還原（需要時才手動執行）：
-- DROP TABLE `nonbook_guard_log`;
-- DELETE FROM `schema_migrations` WHERE `version` = '2026-10-11_nonbook_guard_log.sql';
