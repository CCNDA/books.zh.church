-- 書目網出版年份修正（Asana 1220948673053089；熊叔叔 11/27 TG「甲 照建議兩批都修」）
-- 11/27 正式庫試跑數字：editions A 民國 42、B 21xx 2、C 假日期 662；books A 14、C 571
-- 每一段累加 ROW_COUNT()，最後數字不對就故意出錯（SELECT 1 UNION SELECT 2 當純量子查詢會報錯）讓整批不提交
SET @ny = YEAR(CURDATE()) + 1;
SET @e = 0;
SET @b = 0;

-- A 版本：民國 2 碼年月被當西元（9305-01-01 → 2004-05-01）
UPDATE editions
   SET publish_date = CONCAT(LEFT(REPLACE(publish_date, '-', ''), 2) + 1911, '-',
                             SUBSTRING(REPLACE(publish_date, '-', ''), 3, 2),
                             IF(SUBSTRING(REPLACE(publish_date, '-', ''), 5, 2) BETWEEN '01' AND '31',
                                CONCAT('-', SUBSTRING(REPLACE(publish_date, '-', ''), 5, 2)), ''))
 WHERE publish_date REGEXP '^[4-9][0-9](0[1-9]|1[0-2])' AND LEFT(publish_date, 4) + 0 > @ny;
SET @e = @e + ROW_COUNT();

-- B 版本：2108-01、2110-10（書本身是 2021-08、2021-10）→ 用書的年月
UPDATE editions e JOIN books b ON b.book_id = e.book_id
   SET e.publish_date = b.publish_date
 WHERE e.edition_id IN (106236, 104382) AND e.publish_date IN ('2108-01', '2110-10') AND b.publish_date IN ('2021-08', '2021-10');
SET @e = @e + ROW_COUNT();

-- C 版本：假日期 → 空
UPDATE editions SET publish_date = NULL
 WHERE LEFT(publish_date, 10) IN ('1899-12-30', '1900-01-01', '0000-00-00');
SET @e = @e + ROW_COUNT();

-- D 版本逐筆（其餘 1789-10-01、1900-05-01 可能是真的老書，留給人看）
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
UPDATE books
   SET publish_date = CONCAT(LEFT(REPLACE(publish_date, '-', ''), 2) + 1911, '-', SUBSTRING(REPLACE(publish_date, '-', ''), 3, 2))
 WHERE publish_date REGEXP '^[4-9][0-9](0[1-9]|1[0-2])' AND LEFT(publish_date, 4) + 0 > @ny;
SET @b = @b + ROW_COUNT();

-- C 書：假日期 → 空
UPDATE books SET publish_date = NULL WHERE LEFT(publish_date, 7) IN ('1899-12', '1900-01', '0000-00');
SET @b = @b + ROW_COUNT();

-- D 書逐筆
UPDATE books SET publish_date = '2012' WHERE book_id = 86114 AND publish_date = '0101-20';
SET @b = @b + ROW_COUNT();
UPDATE books SET publish_date = NULL
 WHERE (book_id = 87784 AND publish_date = '0205-03')
    OR (book_id = 55283 AND publish_date = '1900-12');
SET @b = @b + ROW_COUNT();

-- 預期：editions 42+2+662+1+1+3 = 711；books 14+571+1+2 = 588
SELECT @e AS editions_changed, @b AS books_changed;
SELECT IF(@e = 711 AND @b = 588, 'OK', (SELECT 1 UNION SELECT 2)) AS guard;
-- 改完還剩的可疑年份（應只剩 1789-10、1900-05 兩類，各 1）
SELECT 'left_editions' t, publish_date, COUNT(*) n FROM editions
 WHERE publish_date REGEXP '^[0-9]{4}' AND (LEFT(publish_date, 4) + 0 > @ny OR LEFT(publish_date, 4) + 0 <= 1900) GROUP BY publish_date;
SELECT 'left_books' t, publish_date, COUNT(*) n FROM books
 WHERE publish_date REGEXP '^[0-9]{4}' AND (LEFT(publish_date, 4) + 0 > @ny OR LEFT(publish_date, 4) + 0 <= 1900) GROUP BY publish_date;
