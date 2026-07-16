-- CategoryV11 正式分類(A0000–H0000)還原檔
-- 背景:2026-07-15 華福 MVP 展示暫用校園 01–18 分類,先清掉此正式方案;
--       展示後由內容面續建 CategoryV11 對照時,以此還原(或改用完整 CategoryV11 清單)。
-- 註:以下 8 列為 2026-07-15 當時 categories 表內容重建;sort_order 為推定(10 遞增)。
--     權威副本另在 step 0 全庫備份 books_backup_20260715.sql。
DELETE FROM categories;
INSERT INTO categories (code, name, sort_order) VALUES
('A0000','神學／教義',10),
('B0000','讀經／研經',20),
('C0000','靈修生活',30),
('D0000','禱告',40),
('E0000','見證／傳記',50),
('F0000','教會歷史',60),
('G0000','婚姻家庭',70),
('H0000','佈道／宣教',80);
