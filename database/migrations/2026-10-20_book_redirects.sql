-- ============================================================
-- 合併後舊書號轉址表 book_redirects
-- 建立日:2026-10-20(Asana 1219483419251976,v1.19.4)
--
-- 背景:merge_duplicate_books.php 把重複書併掉後,被併的 book_id 整列刪除,舊網址 /book/{id} 變 404。
--   Google 已收錄的舊網址會在 Search Console 變成 404(v1.19.3 才清過一輪)。
--   本表記「舊書號 → 保留的書號」,book.php 找不到書時查這裡,301 轉到保留的那本。
-- ★ 不設外鍵:舊書號在 books 已不存在;新書號若之後又被併,merge 工具會把指向它的列一併改指新目標(不留轉址鏈)。
-- ★ 寫入者:tools/merge_duplicate_books.php(合併時順手寫)。表不在時 merge 工具會拒絕執行,避免併了卻沒轉址。
-- 可重跑:CREATE TABLE IF NOT EXISTS;schema_migrations ON DUPLICATE KEY 只更新 note。
-- ============================================================

CREATE TABLE IF NOT EXISTS book_redirects (
  old_book_id INT UNSIGNED NOT NULL COMMENT '被併掉、已不存在的書號',
  new_book_id INT UNSIGNED NOT NULL COMMENT '保留的書號(301 目標)',
  reason      VARCHAR(32)  NOT NULL DEFAULT 'merge',
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (old_book_id),
  KEY idx_new_book (new_book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version, applied_at, note) VALUES
  ('2026-10-20_book_redirects.sql', NOW(), '合併後舊書號 301 轉址表')
ON DUPLICATE KEY UPDATE note = VALUES(note);
