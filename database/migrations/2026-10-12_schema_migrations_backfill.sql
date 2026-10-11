-- ============================================================
-- schema_migrations 回溯補登（Asana 1219384828338400：migration 對帳）
-- 建立日:2026-10-12
--
-- 對帳結果（小芯 2026-10-12 03:3x，用唯讀帳號 cora_ro 查正式庫）:
--   repo main 有 61 支 .sql、正式庫 schema_migrations 有 43 筆；差的 18 支全部是「repo 有、紀錄沒有」，反向 0 支。
--   18 支分兩類:
--   (A) 有改結構或資料、而且效果已經在正式庫 → 是「執行過但沒寫紀錄」，本檔補登 10 筆（下面逐筆附查證）
--   (B) 只有查詢的診斷／驗證檔（8 支）→ 本來就不是要執行的 migration，不補登:
--       2026-09-19_m1b_search_rewrite_verify.sql（ALTER 與 INSERT 都是註解）、2026-09-19_search_perf_round4.sql、
--       2026-09-19_search_perf_round4b.sql、2026-09-19_search_perf_round6_year.sql、2026-09-25_search_perf_round7_year.sql、
--       2026-09-28_year_fallback_guard.sql、2026-09-29_title_promo_audit.sql、2026-10-03_person_cleanup_B1_step3_verify.sql
--   ★ 建議:以後診斷／驗證查詢放 database/diagnostics/，不放 migrations/，對帳才不會每次都要人工分辨。
--
-- 只寫 schema_migrations，不動任何資料表。冪等:ON DUPLICATE KEY 只更新 note。
-- 執行方式:熊哥 Navicat 對正式庫執行。
-- ============================================================

INSERT INTO schema_migrations (version, applied_at, note) VALUES
  ('2026-09-19_search_perf_round5.sql',              NULL, '回溯補登 2026-10-12;books.idx_pub_created 已在(同一個索引也由 2026-09-19_idx_pub_created.sql 建,有紀錄)'),
  ('2026-09-21_bappress_category_map.sql',           NULL, '回溯補登 2026-10-12;bappress_category_map 表已在(81 列)'),
  ('2026-09-23_bappress_fix_links.sql',              NULL, '回溯補登 2026-10-12;抽查 B11R125 連結 platform=浸信會出版社(藍色)'),
  ('2026-09-23_bappress_unpublish_nonbooks.sql',     NULL, '回溯補登 2026-10-12;抽查 105254、105374 is_published=0'),
  ('2026-09-29b_title_revert_goodtv.sql',            NULL, '回溯補登 2026-10-12;96943、96945 書名已還原'),
  ('2026-09-29_title_strip_promo.sql',               NULL, '回溯補登 2026-10-12;抽查 89740 已去促銷詞,上架書「新書79折」0 本'),
  ('2026-10-03_person_cleanup_B1.sql',               NULL, '回溯補登 2026-10-12;與 B1_fast、step1+step2 是同一組人名清理的不同寫法,效果已在,無法從資料判斷實際跑哪一支'),
  ('2026-10-03_person_cleanup_B1_fast.sql',          NULL, '回溯補登 2026-10-12;同上(B1 的另一種寫法)'),
  ('2026-10-03_person_cleanup_B1_step1_merge.sql',   NULL, '回溯補登 2026-10-12;抽查 person 16778、16804、16842 已合併刪除'),
  ('2026-10-03_person_cleanup_B1_step2_rename.sql',  NULL, '回溯補登 2026-10-12;抽查 16900 已改名「巫海丁」')
ON DUPLICATE KEY UPDATE note = VALUES(note);

INSERT INTO schema_migrations (version, applied_at, note) VALUES
  ('2026-10-12_schema_migrations_backfill.sql', NOW(), 'migration 對帳:回溯補登 10 筆(執行過但沒寫紀錄)')
ON DUPLICATE KEY UPDATE note = VALUES(note);

-- 驗證:repo 的 migration 數 − 診斷檔 8 支 = 本表筆數（2026-10-12 時應為 61 − 8 + 1(本檔) = 54）
-- SELECT COUNT(*) FROM schema_migrations;
