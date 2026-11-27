-- 書目網出版年份修正：第二步，改資料（Asana 1220948673053089；熊叔叔 11/27 TG「甲 照建議兩批都修」）
-- ★ 只能用 sudo ccnda-db-run 跑（先試跑、數字對才 --commit），不要用 Navicat：
--   ccnda-db-run 會把整支包在一個交易裡（autocommit=0、START TRANSACTION，最後 ROLLBACK 或 COMMIT），
--   任何一句出錯 mariadb 就停、不會走到 COMMIT，下面的守門才有效。本檔裡不要自己寫 START TRANSACTION／COMMIT
--   （巢狀 START TRANSACTION 會把前面的偷偷提交）。ccnda-db-run 每次執行前也會整庫備份。
-- ★ 順序：先部署新版 tools/lib_pubdate.php，再跑本檔（小布 11/27 審查二-3）：books 匯入是 COALESCE(publish_date, 新值)，
--   清成 NULL 後若舊版匯入先跑到，1899-12 這類假日期會被補回去。
-- ★ 先跑 2026-11-27_publish_date_fix_bak.sql（建舊值表）。改前的值逐列存進 pubdate_fix_bak_20261127，可照表還原。
-- ★ 守門數字（editions 711、books 588）是 11/27 正式庫試跑的快照；執行那天若中間有新匯入而對不上，
--   會被擋下（整批不寫）——重跑一次試跑看新數字、確認原因後再改這裡的數字。
SET @ny = YEAR(CURDATE()) + 1;
SET @e = 0;
SET @b = 0;
SET @k = 0;

-- A 版本：民國 2 碼年月被當西元（9305-01-01 → 2004-05-01）
INSERT INTO pubdate_fix_bak_20261127 (tbl, id, old_publish_date)
SELECT 'editions', edition_id, publish_date FROM editions
 WHERE publish_date REGEXP '^[4-9][0-9](0[1-9]|1[0-2])' AND LEFT(publish_date, 4) + 0 > @ny;
SET @k = @k + ROW_COUNT();
UPDATE editions
   SET publish_date = CONCAT(LEFT(REPLACE(publish_date, '-', ''), 2) + 1911, '-',
                             SUBSTRING(REPLACE(publish_date, '-', ''), 3, 2),
                             IF(SUBSTRING(REPLACE(publish_date, '-', ''), 5, 2) BETWEEN '01' AND '31',
                                CONCAT('-', SUBSTRING(REPLACE(publish_date, '-', ''), 5, 2)), ''))
 WHERE publish_date REGEXP '^[4-9][0-9](0[1-9]|1[0-2])' AND LEFT(publish_date, 4) + 0 > @ny;
SET @e = @e + ROW_COUNT();

-- B 版本：2108-01、2110-10（書本身是 2021-08、2021-10）→ 用書的年月
INSERT INTO pubdate_fix_bak_20261127 (tbl, id, old_publish_date)
SELECT 'editions', edition_id, publish_date FROM editions
 WHERE edition_id IN (106236, 104382) AND publish_date IN ('2108-01', '2110-10');
SET @k = @k + ROW_COUNT();
UPDATE editions e JOIN books b ON b.book_id = e.book_id
   SET e.publish_date = b.publish_date
 WHERE e.edition_id IN (106236, 104382) AND e.publish_date IN ('2108-01', '2110-10') AND b.publish_date IN ('2021-08', '2021-10');
SET @e = @e + ROW_COUNT();

-- C 版本：假日期 → 空
INSERT INTO pubdate_fix_bak_20261127 (tbl, id, old_publish_date)
SELECT 'editions', edition_id, publish_date FROM editions
 WHERE LEFT(publish_date, 10) IN ('1899-12-30', '1900-01-01', '0000-00-00');
SET @k = @k + ROW_COUNT();
UPDATE editions SET publish_date = NULL
 WHERE LEFT(publish_date, 10) IN ('1899-12-30', '1900-01-01', '0000-00-00');
SET @e = @e + ROW_COUNT();

-- D 版本逐筆（1789-10-01、1900-05-01 可能是真的老書，留給人看）
INSERT INTO pubdate_fix_bak_20261127 (tbl, id, old_publish_date)
SELECT 'editions', edition_id, publish_date FROM editions
 WHERE (edition_id = 111863 AND publish_date = '0111-04-26')
    OR (edition_id = 104217 AND publish_date = '0101-20-02')
    OR (edition_id = 108131 AND publish_date = '0205-03-01')
    OR (edition_id = 59992  AND publish_date = '1900-12-30')
    OR (edition_id = 110288 AND publish_date = '0008-11-01');
SET @k = @k + ROW_COUNT();
UPDATE editions SET publish_date = '2022-04-26' WHERE edition_id = 111863 AND publish_date = '0111-04-26';
SET @e = @e + ROW_COUNT();
UPDATE editions SET publish_date = '2012' WHERE edition_id = 104217 AND publish_date = '0101-20-02';
SET @e = @e + ROW_COUNT();
UPDATE editions SET publish_date = NULL
 WHERE (edition_id = 108131 AND publish_date = '0205-03-01')
    OR (edition_id = 59992  AND publish_date = '1900-12-30')
    OR (edition_id = 110288 AND publish_date = '0008-11-01');
SET @e = @e + ROW_COUNT();

-- A 書：民國年月（9305-01 → 2004-05）
INSERT INTO pubdate_fix_bak_20261127 (tbl, id, old_publish_date)
SELECT 'books', book_id, publish_date FROM books
 WHERE publish_date REGEXP '^[4-9][0-9](0[1-9]|1[0-2])' AND LEFT(publish_date, 4) + 0 > @ny;
SET @k = @k + ROW_COUNT();
UPDATE books
   SET publish_date = CONCAT(LEFT(REPLACE(publish_date, '-', ''), 2) + 1911, '-', SUBSTRING(REPLACE(publish_date, '-', ''), 3, 2))
 WHERE publish_date REGEXP '^[4-9][0-9](0[1-9]|1[0-2])' AND LEFT(publish_date, 4) + 0 > @ny;
SET @b = @b + ROW_COUNT();

-- C 書：假日期 → 空（書只存到月，1900-01 也一起清；小布 11/27：正式庫應沒有真的 1900 年 1 月的書）
INSERT INTO pubdate_fix_bak_20261127 (tbl, id, old_publish_date)
SELECT 'books', book_id, publish_date FROM books WHERE LEFT(publish_date, 7) IN ('1899-12', '1900-01', '0000-00');
SET @k = @k + ROW_COUNT();
UPDATE books SET publish_date = NULL WHERE LEFT(publish_date, 7) IN ('1899-12', '1900-01', '0000-00');
SET @b = @b + ROW_COUNT();

-- D 書逐筆
INSERT INTO pubdate_fix_bak_20261127 (tbl, id, old_publish_date)
SELECT 'books', book_id, publish_date FROM books
 WHERE (book_id = 86114 AND publish_date = '0101-20')
    OR (book_id = 87784 AND publish_date = '0205-03')
    OR (book_id = 55283 AND publish_date = '1900-12');
SET @k = @k + ROW_COUNT();
UPDATE books SET publish_date = '2012' WHERE book_id = 86114 AND publish_date = '0101-20';
SET @b = @b + ROW_COUNT();
UPDATE books SET publish_date = NULL
 WHERE (book_id = 87784 AND publish_date = '0205-03')
    OR (book_id = 55283 AND publish_date = '1900-12');
SET @b = @b + ROW_COUNT();

INSERT INTO schema_migrations (version, applied_at, note) VALUES
  ('2026-11-27_publish_date_fix.sql', NOW(), '出版日期修正：民國年轉西元、假日期清空（舊值在 pubdate_fix_bak_20261127）')
ON DUPLICATE KEY UPDATE note = VALUES(note);

-- 預期：editions 42+2+662+1+1+3 = 711；books 14+571+1+2 = 588；舊值備份 711+588 = 1299
SELECT @e AS editions_changed, @b AS books_changed, @k AS backed_up;
SELECT IF(@e = 711 AND @b = 588 AND @k = 1299, 'OK', (SELECT 1 UNION SELECT 2)) AS guard;
-- 改完還剩的可疑年份（應只剩 1789-10、1900-05 兩類，各 1）
SELECT 'left_editions' t, publish_date, COUNT(*) n FROM editions
 WHERE publish_date REGEXP '^[0-9]{4}' AND (LEFT(publish_date, 4) + 0 > @ny OR LEFT(publish_date, 4) + 0 <= 1900) GROUP BY publish_date;
SELECT 'left_books' t, publish_date, COUNT(*) n FROM books
 WHERE publish_date REGEXP '^[0-9]{4}' AND (LEFT(publish_date, 4) + 0 > @ny OR LEFT(publish_date, 4) + 0 <= 1900) GROUP BY publish_date;
