-- persons 表現況盤點(唯讀)—— Asana 1218277971470042
-- 用途:**重驗票上 2026-09-08 的數字**。中間多收了 bappress 等來源,數字一定變了;
--      而且這份是純 SQL,不依賴 tools/check_person_names.php 是否寫對 ——
--      兩個獨立來源對得起來,數字才算數。
--
-- 跑法:Navicat Premium 對遠端 DB,一段一段跑,把結果貼回。
-- ★ 本檔**不改任何資料**。
--
-- ════════ 查詢陷阱(票上備忘,照抄) ════════
-- 1. `utf8mb4_unicode_ci` 把**全形與半形視為相等** → 比全形分號、全形括號
--    一律要 `COLLATE utf8mb4_bin`,否則會把所有含半形分號的名字一起撈進來。
-- 2. 用 HEX() 比位元組時,`'%28%'` 這種短樣式會大量誤中
--    (「梁」=E6A281、「沈」=E6B288 的十六進位字串裡就含 28)。
--    `'%EFBC9B%'` 安全,因為 0xFB 不會出現在合法 UTF-8。

-- ═══ 0. 基準總量(先記下來,清理後要再跑一次對帳) ═══
SELECT (SELECT COUNT(*) FROM persons)      AS persons_總數,
       (SELECT COUNT(*) FROM book_persons) AS book_persons_總數,
       (SELECT COUNT(*) FROM books)        AS books_總數;


-- ═══ 1. 全形分號(票上 9/8:books.author 47 本、persons.name 56 筆) ═══
SELECT 'books.author 含全形分號' AS 項目, COUNT(*) AS 筆數
  FROM books
 WHERE author COLLATE utf8mb4_bin LIKE CONCAT('%', CHAR(0xEFBC9B USING utf8mb4), '%')
UNION ALL
SELECT 'persons.name 含全形分號', COUNT(*)
  FROM persons
 WHERE name COLLATE utf8mb4_bin LIKE CONCAT('%', CHAR(0xEFBC9B USING utf8mb4), '%');

-- 1b. 來源分布(票上 9/8:logos 33、elim 4、methodist 3、campus 2、mezu 2、cosmiccare 2、pctpress 1)
SELECT COALESCE(source, '(空)') AS 來源, COUNT(*) AS 本數
  FROM books
 WHERE author COLLATE utf8mb4_bin LIKE CONCAT('%', CHAR(0xEFBC9B USING utf8mb4), '%')
 GROUP BY source
 ORDER BY 本數 DESC;


-- ═══ 2. 角色詞被當成人名(票上 9/8:六列、合計 68 本次) ═══
SELECT p.person_id, p.name, COUNT(DISTINCT bp.book_id) AS 本數
  FROM persons p
  LEFT JOIN book_persons bp ON bp.person_id = p.person_id
 WHERE p.name IN ('文','圖','譯','著','繪','編','作','撰',
                  '主編','編輯','編者','著者','作者','譯者','翻譯','編譯','編著','合著',
                  '校訂','審訂','選編','彙編','口述','繪圖','插圖','插畫','漫畫','攝影',
                  '等','其他','其它')
 GROUP BY p.person_id, p.name
 ORDER BY 本數 DESC;


-- ═══ 3. 尾註沒清:同一個人散成好幾筆 ═══
-- 做法:把字尾註記剝掉後,看站上是否另有一列正好等於剝完的名字。
-- ★ 只列「剝完確實對得上另一列」的,所以不會誤報真名
--   (「陳志文」剝不出東西,「李著」剝完只剩一字也對不到人)。
SELECT d.person_id AS 髒列id, d.name AS 髒列, d.本數 AS 髒列本數,
       c.person_id AS 正規列id, c.name AS 正規列, c.本數 AS 正規列本數
  FROM (
        SELECT p.person_id, p.name,
               COUNT(DISTINCT bp.book_id) AS 本數,
               -- ★ 巢狀 TRIM 是**由內往外**執行 → 長的詞要放在最內層。
               --   放反了「黃伯和主編」會先被 '編' 吃掉變成「黃伯和主」,
               --   正好製造出一筆新的髒資料。(PHP 那邊也是同一條:長的先試。)
               TRIM(TRAILING '等'   FROM
               TRIM(TRAILING '譯'   FROM
               TRIM(TRAILING '編'   FROM
               TRIM(TRAILING '著'   FROM
               TRIM(TRAILING '校訂' FROM
               TRIM(TRAILING '審訂' FROM
               TRIM(TRAILING '合著' FROM
               TRIM(TRAILING '編著' FROM
               TRIM(TRAILING '主編' FROM
               TRIM(TRAILING '編輯' FROM TRIM(p.name)))))))))))  AS 剝完
          FROM persons p
          LEFT JOIN book_persons bp ON bp.person_id = p.person_id
         GROUP BY p.person_id, p.name
       ) d
  JOIN (
        SELECT p.person_id, p.name, COUNT(DISTINCT bp.book_id) AS 本數
          FROM persons p
          LEFT JOIN book_persons bp ON bp.person_id = p.person_id
         GROUP BY p.person_id, p.name
       ) c
    ON c.name = TRIM(d.剝完) AND c.person_id <> d.person_id
 WHERE d.剝完 <> d.name
   AND CHAR_LENGTH(TRIM(d.剝完)) >= 2
 ORDER BY d.本數 DESC
 LIMIT 200;

-- 3b. 票上點名的那六筆現況(17537 / 17835 / 25007 / 18609 / 48078 / 34655)
SELECT p.person_id, p.name, COUNT(DISTINCT bp.book_id) AS 本數
  FROM persons p LEFT JOIN book_persons bp ON bp.person_id = p.person_id
 WHERE p.person_id IN (17537, 17835, 25007, 18609, 48078, 34655)
    OR p.name IN ('梁家麟', '邁爾', '梁淑儀', '黃伯和')
 GROUP BY p.person_id, p.name
 ORDER BY p.name, 本數 DESC;


-- ═══ 4. HTML entity 未解碼(票上 9/8:persons 11、books.author 4、books.title 0、publishers 0 = 15) ═══
-- ★ 結尾的 `;` 寫成可有可無 —— 被分隔符吃掉分號的殘骸(`Anselm Gr&uuml`)才是大宗。
SELECT 'persons.name' AS 表欄, COUNT(*) AS 筆數 FROM persons
 WHERE name   REGEXP '&(#[0-9]+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]+);?'
UNION ALL
SELECT 'books.author', COUNT(*) FROM books
 WHERE author REGEXP '&(#[0-9]+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]+);?'
UNION ALL
SELECT 'books.title', COUNT(*) FROM books
 WHERE title  REGEXP '&(#[0-9]+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]+);?'
UNION ALL
SELECT 'publishers.name_zh', COUNT(*) FROM publishers
 WHERE name_zh REGEXP '&(#[0-9]+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]+);?';

-- 4b. 明細(票上點名 43311/43313/43317/43806/43315/43328/43334/43338/43782/43125)
SELECT p.person_id, p.name, COUNT(DISTINCT bp.book_id) AS 本數
  FROM persons p LEFT JOIN book_persons bp ON bp.person_id = p.person_id
 WHERE p.name REGEXP '&(#[0-9]+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]+);?'
 GROUP BY p.person_id, p.name
 ORDER BY 本數 DESC;


-- ═══ 5. 整段文字被當人名(票上第五節,量少、人工處理) ═══
SELECT p.person_id, CHAR_LENGTH(p.name) AS 字數, p.name,
       COUNT(DISTINCT bp.book_id) AS 本數
  FROM persons p LEFT JOIN book_persons bp ON bp.person_id = p.person_id
 WHERE CHAR_LENGTH(p.name) > 25
    OR p.name LIKE CONCAT('%', CHAR(0xE38082 USING utf8mb4), '%')          -- 。
    OR p.name LIKE CONCAT('%', CHAR(0xE280A6 USING utf8mb4), '%')          -- …
 GROUP BY p.person_id, p.name
 ORDER BY 字數 DESC
 LIMIT 100;


-- ═══ 6. 異體字同一人(票上:41017 托馬斯 27 本 vs 41020 託馬斯 24 本) ═══
-- ★ 這裡只查票上點名的那一組。全站候選由 tools/check_person_names.php 產
--   (同長度、只差一字的通配鍵分群),純 SQL 做那個會很醜而且慢。
-- ★ persons.aka 欄位**已經存在**(varchar(255),; 分隔)→ 不需要新增對照表。
SELECT p.person_id, p.name, p.aka, COUNT(DISTINCT bp.book_id) AS 本數
  FROM persons p LEFT JOIN book_persons bp ON bp.person_id = p.person_id
 WHERE p.person_id IN (41017, 41020)
    OR p.name LIKE '%馬斯%奧登%'
 GROUP BY p.person_id, p.name, p.aka
 ORDER BY 本數 DESC;

-- 6b. aka 目前有多少列已經在用(決定要不要先備份這一欄)
SELECT COUNT(*) AS aka_已有值的列數 FROM persons WHERE aka IS NOT NULL AND aka <> '';
