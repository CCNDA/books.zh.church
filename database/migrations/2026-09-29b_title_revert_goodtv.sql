-- ============================================================================
-- 2026-09-29b_title_revert_goodtv.sql
-- 把 96943 / 96945 兩筆改回原書名。Asana 1218961224853655。
--
-- ════════ 為什麼要改回去 ════════
-- 2026-09-29_title_strip_promo.sql 跑完後,第 3 段 (c) 的同名檢查出現一組新的:
--     Good TV DVD  →  96943、96945
-- 這兩筆原本是
--     96943「Good TV DVD 特價199元」
--     96945「Good TV DVD 特價99元」
-- **價格是這兩筆唯一的區別**,剝掉之後站上出現兩筆一模一樣的「Good TV DVD」,
-- 使用者分不出差異,後續的重複書偵測也會把它們當成同一本。
--
-- ★ 這是「破壞性清洗」的實例 —— 剝除本身沒寫錯,錯在**它剝掉了唯一的識別資訊**。
-- ★ dry-run 沒攔下來,因為它問錯了問題:當時只比「剝後書名 vs 站上既有書名」,
--   **沒有比「這一批 AUTO 彼此之間會不會撞」**。
--   tools/check_title_promo.php 已補上同批互撞偵測(第 3.5 段),
--   之後這兩筆會自動落在 HOLD,不會再被判成可自動剝。
--
-- ★ 這兩筆本來就是**非書商品**(Good TV 的 DVD),真正該處理的是「要不要收錄/下架」,
--   登記在收錄判準 1218562704413649,不在本票範圍。這裡只把識別資訊還原。
-- ============================================================================

UPDATE books SET title = 'Good TV DVD 特價199元' WHERE book_id = 96943 AND title = 'Good TV DVD';
UPDATE books SET title = 'Good TV DVD 特價99元'  WHERE book_id = 96945 AND title = 'Good TV DVD';
-- 兩句都應影響 1 列。若是 0,表示書名已被別的東西動過,停下來回報。


-- ─── 驗證(唯讀) ────────────────────────────────────────────────────────
-- (a) 兩筆確實回到原書名
SELECT book_id, title FROM books WHERE book_id IN (96943, 96945) ORDER BY book_id;

-- (b) 全庫還帶促銷詞的書數:應為 **17**(還原前 15 + 這兩筆回來)
SELECT COUNT(*) AS 還帶促銷詞的書數
  FROM books
 WHERE title REGEXP '[0-9]+[[:space:]]*折|特價|免運|瑕疵|優惠|限時';

-- (c) 剝完的同名組應只剩本來就有的四組,Good TV DVD 不該再出現
SELECT b.title, COUNT(*) AS 幾本, GROUP_CONCAT(b.book_id ORDER BY b.book_id) AS book_ids
  FROM books b
 WHERE b.title IN ('100個至愛聖經故事', 'Good TV DVD', '禱告探訪手冊',
                   '跟耶穌學安靜：戒除空虛的忙碌，活出輕省負軛的人生', '釋放潔淨禱告手冊')
 GROUP BY b.title
HAVING COUNT(*) > 1
 ORDER BY b.title;


-- ─── 主機複驗(不是 SQL,貼在 SSH;要先 FTP 上傳新版 check_title_promo.php) ──
--   php tools/check_title_promo.php
--   預期:SQL 撈到 **87**、AUTO **0**、HOLD **17**、EXCLUDED 70
--   (118 − 已清乾淨的 31 筆 = 87;17 筆全部是 HOLD,其中兩筆是這次還原的 Good TV DVD)
--   ★ AUTO 不是 0 → 有東西沒跑到,停下來回報。
