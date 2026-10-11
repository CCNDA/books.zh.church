-- ============================================================
-- books 加窄索引 (book_id, is_published, created_at)：關鍵字搜尋 2–3 秒 → 0.3 秒
-- 建立日:2026-10-12（Asana 1219384840302646：API 效能）
--
-- 原因（books_dev 分步計時，搜尋「聖經」）:
--   COUNT 0.2–0.4 秒、取卡片 0.035 秒 —— 慢的是「取第 1 頁 20 個 book_id」1.5–3.0 秒:
--   book_search 瘦表（13.5 MB）找出 7,194 本含關鍵字的書後，要拿每本的 is_published 和 created_at 排序，
--   而這兩欄只能從 books 主鍵（叢集索引＝整列，488 MB）逐筆讀 → 7 千多次隨機讀取。
--   加這個以 book_id 開頭的窄索引後，JOIN 直接用它取 is_published、created_at（EXPLAIN：Using index），不碰整列。
--
-- 實測（books_dev）:取 book_id 2.4–3.0 秒 → 0.16 秒；整條 API /api/books?q=聖經 1.8 秒 → 0.30 秒。
--   聖經、禱告、恩典、C.S.路易斯、9789 五個關鍵字加索引前後結果逐筆相同。
--   最佳化器自己就會選這個索引（不用改程式、不用 FORCE INDEX）。
--
-- ALGORITHM=INPLACE, LOCK=NONE：線上建索引不鎖表（books_dev 5 秒）。冪等：IF NOT EXISTS。
-- 執行方式:熊哥 Navicat 對正式庫執行。
-- ============================================================

ALTER TABLE books ADD INDEX IF NOT EXISTS idx_id_pub_created (book_id, is_published, created_at), ALGORITHM=INPLACE, LOCK=NONE;

INSERT INTO schema_migrations (version, applied_at, note) VALUES
  ('2026-10-12_idx_id_pub_created.sql', NOW(), 'books (book_id, is_published, created_at) 窄索引:關鍵字搜尋 2–3 秒 → 0.3 秒')
ON DUPLICATE KEY UPDATE note = VALUES(note);

-- 驗證:第二列 b 應為 key=idx_id_pub_created、Extra=Using index
-- EXPLAIN SELECT STRAIGHT_JOIN b.book_id FROM book_search bs JOIN books b ON b.book_id = bs.book_id
--   WHERE b.is_published = 1 AND bs.search_key LIKE '%聖經%' ORDER BY b.created_at DESC, b.book_id DESC LIMIT 20;
