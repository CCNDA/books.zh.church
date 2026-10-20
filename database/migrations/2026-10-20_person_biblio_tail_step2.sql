-- 作者欄書目尾巴清理 第二段：刪掉重切後已多餘的 8 個髒名字（Asana 1219613623198535）
-- 裁示：熊哥 2026-10-20 TG（訊息 953，按 pn-dirty-A）
-- ★ 順序：先跑 2026-10-20_person_biblio_tail.sql → 再對 15 本跑 fix_person_names.php --book=N --apply（補上正確的人）→ 最後才跑本檔
--   書：85903 85904 85905 85907 85908 85909 85910 85911 85912 85913 85914 85915 85916 85917 85920
-- ★ book_persons 對 persons 有 ON DELETE CASCADE。

START TRANSACTION;

-- A0. 前置檢查（應回 0 列）：刪掉後會變成沒有作者的書——有列回來表示重切沒先跑，停下來
SELECT bp.book_id, b.title, COUNT(*) AS 只剩這些關聯
  FROM book_persons bp JOIN books b ON b.book_id = bp.book_id
 WHERE bp.book_id IN (SELECT book_id FROM book_persons WHERE person_id IN (41656,41659,41662,41663,41665,41666,41667,41682))
 GROUP BY bp.book_id, b.title
HAVING SUM(bp.person_id NOT IN (41656,41659,41662,41663,41665,41666,41667,41682)) = 0;

DELETE FROM persons WHERE person_id IN (41656,41659,41662,41663,41665,41666,41667,41682);

-- E1. 應回 0 列
SELECT person_id, name FROM persons WHERE person_id IN (41656,41659,41662,41663,41665,41666,41667,41682);
-- E2. 這 15 本每本都還有作者（應回 0 列）
SELECT b.book_id FROM books b LEFT JOIN book_persons bp ON bp.book_id = b.book_id
 WHERE b.book_id IN (85903,85904,85905,85907,85908,85909,85910,85911,85912,85913,85914,85915,85916,85917,85920)
 GROUP BY b.book_id HAVING COUNT(bp.person_id) = 0;

INSERT INTO schema_migrations (version, applied_at, note) VALUES
  ('2026-10-20_person_biblio_tail_step2.sql', NOW(), '重切後刪 8 個多餘的髒名字（15 本）')
ON DUPLICATE KEY UPDATE note = VALUES(note);

-- 以上 A0／E1／E2 都 0 列：COMMIT;   有任何一句回了列：ROLLBACK;
