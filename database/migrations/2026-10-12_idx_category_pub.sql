-- ============================================================
-- books 加複合索引 (category_id, is_published)：/api/categories 7–8 秒 → 0.04 秒
-- 建立日:2026-10-12（Asana 1219384840302646：API 效能）
--
-- 原因（books_dev 實測）:
--   GET /api/categories 用 LEFT JOIN books ON category_id AND is_published = 1 數每個分類的上架書。
--   原本只有 idx_category(category_id)，判斷 is_published 要回資料表逐筆讀 ——
--   books 有 488 MB（summary、search_text 等大欄位都在列裡），六萬多次隨機讀取 ≈ 7–8 秒（正式站實測 8.3 秒）。
--   加 (category_id, is_published) 後 EXPLAIN 變成「Using index」（InnoDB 次要索引本來就帶主鍵 book_id，
--   COUNT(book_id) 不用碰資料表）→ 0.04 秒；回傳內容與加之前逐位元組一致。
--
-- ALGORITHM=INPLACE, LOCK=NONE：線上建索引，不鎖表（books_dev 建 5.6 秒）。
-- 冪等：ADD INDEX IF NOT EXISTS。
-- 舊的 idx_category 是新索引的前綴，理論上可以拿掉；先保留，確認沒有查詢依賴它再說。
-- 執行方式:熊哥 Navicat 對正式庫執行。
-- ============================================================

ALTER TABLE books ADD INDEX IF NOT EXISTS idx_category_pub (category_id, is_published), ALGORITHM=INPLACE, LOCK=NONE;

INSERT INTO schema_migrations (version, applied_at, note) VALUES
  ('2026-10-12_idx_category_pub.sql', NOW(), 'books (category_id, is_published) 複合索引:/api/categories 8 秒 → 0.04 秒')
ON DUPLICATE KEY UPDATE note = VALUES(note);

-- 驗證:EXPLAIN 應出現 key=idx_category_pub、Extra=Using index
-- EXPLAIN SELECT c.category_id, COUNT(b.book_id) FROM categories c
--   LEFT JOIN books b ON b.category_id = c.category_id AND b.is_published = 1 GROUP BY c.category_id;
