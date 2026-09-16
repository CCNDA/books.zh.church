-- =============================================================
-- 2026-09-16  schema_migrations 追蹤表 + 回溯補登既有 34 筆
-- Asana: 1217927332453913
--
-- 目的:讓資料庫自己能回答「我套用到哪一版」。
--       在此之前只能靠檔名排序與人工記憶。
--
-- 設計決策(三項,都刻意為之):
--  1. applied_at 對回溯補登的 34 筆一律 NULL —— **不假造套用時間**。
--     檔名裡的日期是「寫檔日期」,不是「套用到正式庫的日期」,兩者不該混為一談。
--     本表之後新增的列才會有真實的 applied_at。
--  2. version 用完整檔名(含 .sql),避免歧義。
--     排序規則刻意指定 utf8mb4_bin:utf8mb4_unicode_ci 不分大小寫與全半形,
--     用它當主鍵會讓大小寫不同的檔名互相撞號。
--  3. 用 INSERT ... ON DUPLICATE KEY UPDATE,本檔可重複執行不報錯(idempotent)。
--
-- 執行方式:熊哥本機 Navicat Premium 對遠端 DB 執行。
-- 本檔不需 FTP 上傳(database/migrations/ 一律不上傳)。
-- =============================================================

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version`     varchar(100) COLLATE utf8mb4_bin NOT NULL
                COMMENT 'migration 完整檔名(含 .sql);bin 排序以精確比對',
  `applied_at`  datetime DEFAULT NULL
                COMMENT '實際套用到本庫的時間;NULL=2026-09-16 回溯補登,真實時間不可知',
  `note`        varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                COMMENT '說明(如 回溯補登、重跑原因)',
  `recorded_at` datetime NOT NULL DEFAULT current_timestamp()
                COMMENT '登錄到本表的時間',
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='migration 套用紀錄。★ 改 schema = 每支 migration 結尾 INSERT 一列 + 同步更新 docs/database-current.md';

-- -------------------------------------------------------------
-- 回溯補登既有 34 個 migration(依檔名字典序,即實際執行順序)
-- applied_at 一律 NULL,理由見檔頭設計決策 1
-- -------------------------------------------------------------
INSERT INTO `schema_migrations` (`version`, `applied_at`, `note`) VALUES
  ('001_create_books_categories.sql',            NULL, '回溯補登;建 books/categories'),
  ('002_seed_demo_data.sql',                     NULL, '回溯補登;範例資料'),
  ('003_extend_books_for_import.sql',            NULL, '回溯補登;books 擴欄以供匯入'),
  ('004_normalized_relations.sql',               NULL, '回溯補登;建正規化關聯表'),
  ('005_book_clicks.sql',                        NULL, '回溯補登;點擊統計表'),
  ('2026-07-16_fix_v_book_list_cover_perf.sql',  NULL, '回溯補登;v_book_list 封面效能'),
  ('2026-07-16_idx_editions_publisher.sql',      NULL, '回溯補登;editions.publisher_id 索引'),
  ('2026-07-16_publisher_canonical.sql',         NULL, '回溯補登;publishers.canonical_id 機制'),
  ('2026-07-16_publisher_org_links.sql',         NULL, '回溯補登;出版社↔機構名錄↔宗派'),
  ('2026-07-17_categories_extend.sql',           NULL, '回溯補登'),
  ('2026-07-17_logos_category_map.sql',          NULL, '回溯補登;基道分類對映(僅 3 欄,無 unpublish/sort_order)'),
  ('2026-07-30_category_gifts.sql',              NULL, '回溯補登'),
  ('2026-07-31_elim_category_map.sql',           NULL, '回溯補登'),
  ('2026-08-03_publisher_canonical_v2.sql',      NULL, '回溯補登;canonical 第二輪'),
  ('2026-08-03_publisher_canonical_v3.sql',      NULL, '回溯補登;canonical 第三輪'),
  ('2026-08-06_grace_category_map.sql',          NULL, '回溯補登'),
  ('2026-08-09_wdbook_category_map.sql',         NULL, '回溯補登'),
  ('2026-08-11_wdbook_category_map_v2.sql',      NULL, '回溯補登'),
  ('2026-08-12_fix_wdbook_hans_labels.sql',      NULL, '回溯補登'),
  ('2026-08-17_methodist_category_map.sql',      NULL, '回溯補登'),
  ('2026-08-18_books_extra_mediumtext.sql',      NULL, '回溯補登;extra TEXT→MEDIUMTEXT'),
  ('2026-08-18_osb_category_map.sql',            NULL, '回溯補登'),
  ('2026-08-19_cclm_category_map.sql',           NULL, '回溯補登'),
  ('2026-08-19_taosheng_category_map.sql',       NULL, '回溯補登'),
  ('2026-08-21_cosmiccare_category_map.sql',     NULL, '回溯補登'),
  ('2026-08-22_mezu_category_map.sql',           NULL, '回溯補登'),
  ('2026-08-23_widen_source_url.sql',            NULL, '回溯補登;source_url 500→1000'),
  ('2026-08-25_twgbr_category_map.sql',          NULL, '回溯補登'),
  ('2026-08-25_widen_subjects_code.sql',         NULL, '回溯補登;subjects.code 20→40'),
  ('2026-08-27_pctpress_category_map.sql',       NULL, '回溯補登'),
  ('2026-09-01_tiendao_category_map.sql',        NULL, '回溯補登'),
  ('2026-09-05_btproduct_category_map.sql',      NULL, '回溯補登'),
  ('2026-09-14_akow_category_map.sql',           NULL, '回溯補登;46 列,含簡繁兩種寫法'),
  ('2026-09-15_akow_link_variant.sql',           NULL, '回溯補登;akow 購書連結正體/簡體標註')
ON DUPLICATE KEY UPDATE `note` = VALUES(`note`);

-- 本檔自己也要登錄(這一列有真實時間)
INSERT INTO `schema_migrations` (`version`, `applied_at`, `note`) VALUES
  ('2026-09-16_schema_migrations.sql', NOW(), '建立追蹤表本身')
ON DUPLICATE KEY UPDATE `applied_at` = NOW();

-- =============================================================
-- ★ 驗證查詢:跑完務必執行
-- =============================================================

-- ① 總列數應為 35(34 筆回溯 + 本檔自己)
SELECT COUNT(*) AS 應為35 FROM `schema_migrations`;

-- ② 只有本檔那一列有 applied_at,其餘 34 筆應為 NULL
SELECT
  SUM(`applied_at` IS NULL)     AS 回溯補登_應為34,
  SUM(`applied_at` IS NOT NULL) AS 有真實時間_應為1
FROM `schema_migrations`;

-- ③ 目前套用到哪一版(日後回答「我是哪一版」就查這個)
SELECT `version`, `applied_at`, `note`
  FROM `schema_migrations`
 ORDER BY `version` DESC
 LIMIT 5;
