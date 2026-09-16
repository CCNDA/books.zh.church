-- =============================================================
-- 2026-09-16  publishers 別名歸一第四輪(承 v1/v2/v3)
-- Asana: 1216467657931851(M1-B 出版社頁前置)
--
-- 熊哥裁示 2026-09-16:橄欖=橄欖華宣同一家;道聲≠香港道聲;其他/其它設 NULL(另一支)
--
-- ★★ 本檔只處理「明確是同一家」的變體。
--    盤點時發現 publishers 表混雜了五種東西,其中三種**不可歸一**,見檔尾說明。
--    不確定的一律不動,留在檔尾待裁示區。
--
-- 執行:熊哥本機 Navicat。本檔不需 FTP。
-- =============================================================

-- ═══ 前置:先看現況(唯讀) ═══
SELECT 'PRE' AS q, publisher_id, name_zh, canonical_id
  FROM publishers
 WHERE publisher_id IN (2755,4389,5065,5492,5958,2423,5059,2371,5339)
 ORDER BY name_zh;


-- ═══ 第一部分:橄欖家族 ═══
-- 正規列選 2755「橄欖」而非 4389「橄欖華宣」的理由:
--   2755 已經是聚合中心 —— 橄欖基金會(505 本)、橄欖文化事業基金會(212)、
--   橄欖出版社(192)、橄欖出版有限公司、橄欖其它 五個別名都已指向它。
--   若改以 4389 為正規,那五列得一起改向,否則會形成 A→B→C 鏈結,
--   而 v_book_list 只做一次 COALESCE(canonical_id, publisher_id),只會收斂一層。
--   ★ 若熊哥要顯示名為「橄欖華宣」,正確做法是改 2755 那一列的 name_zh,不是改指向。

UPDATE publishers SET canonical_id = 2755 WHERE publisher_id = 4389;  -- 橄欖華宣(91 本)
UPDATE publishers SET canonical_id = 2755 WHERE publisher_id = 5065;  -- 橄欖出版(0)
UPDATE publishers SET canonical_id = 2755 WHERE publisher_id = 5492;  -- 橄欖出版公司(0)
UPDATE publishers SET canonical_id = 2755 WHERE publisher_id = 5958;  -- 橄欖文化(1)

-- ★ 注意:青橄欖是**不同出版社**,不可併入橄欖(我的 LIKE '%橄欖%' 會把它們抓在一起)
UPDATE publishers SET canonical_id = 2423 WHERE publisher_id = 5059;  -- 青橄欖出版公司(0) → 青橄欖出版社


-- ═══ 第二部分:香港道聲(全形括號變體) ═══
UPDATE publishers SET canonical_id = 2371 WHERE publisher_id = 5339;  -- 香港道聲出版社（道聲）(0)


-- ═══ ★ 驗證(務必執行) ═══

-- (1) 鏈結檢查:A→B→C 應回 0 列。有列就是歸一歸錯了,view 只會收斂一層。
SELECT 'POST-1' AS q,
       a.publisher_id AS 別名id, a.name_zh AS 別名,
       b.name_zh AS 中間層, c.name_zh AS 最終
  FROM publishers a
  JOIN publishers b ON b.publisher_id = a.canonical_id
  JOIN publishers c ON c.publisher_id = b.canonical_id
 WHERE a.canonical_id IS NOT NULL AND b.canonical_id IS NOT NULL;

-- (2) 自我指向檢查:應回 0 列
SELECT 'POST-2' AS q, publisher_id, name_zh
  FROM publishers WHERE canonical_id = publisher_id;

-- (3) 橄欖家族收斂後的樣子
SELECT 'POST-3' AS q, p.publisher_id, p.name_zh, c.name_zh AS 指向
  FROM publishers p LEFT JOIN publishers c ON c.publisher_id = p.canonical_id
 WHERE p.name_zh LIKE '%橄欖%' ORDER BY p.name_zh;

-- (4) ★ 從 view 確認使用者看到的出版社數(這才是出版社頁會列出的東西)
SELECT 'POST-4' AS q, publisher, COUNT(*) AS 本數
  FROM v_book_list
 WHERE publisher LIKE '%橄欖%' OR publisher LIKE '%道聲%'
 GROUP BY publisher ORDER BY 本數 DESC;


-- ═══ 登錄 schema_migrations(需先建表) ═══
INSERT INTO `schema_migrations` (`version`, `applied_at`, `note`) VALUES
  ('2026-09-16_publisher_canonical_v4.sql', NOW(),
   '橄欖家族 4 筆 + 青橄欖出版公司 + 香港道聲全形括號變體歸一;三類不可歸一者未處理')
ON DUPLICATE KEY UPDATE `applied_at` = NOW();


-- =============================================================
-- ★★★ 以下三類**刻意不處理**,因為歸一會產生錯誤資料
-- =============================================================
--
-- 【一】「A(道聲)」格式 = 經銷關係,括號外才是真出版社
--   時報出版(道聲)、改革宗翻譯社(道聲)、中華信義神學院(道聲)、南與北(道聲)、
--   GOOD TV好消息衛星電視台 (道聲)、舉手網絡(道聲)、冠冕出版事業(道聲）、
--   沈永強(道聲)、(推喇奴)道聲、Ted HUANG(黃文雄)(道聲)… 共 18 筆
--   ⇒ 這是來源站標「原出版社(經銷商)」的寫法。
--   ★ 若歸一到道聲,《時報出版》的書會被算成道聲出版的 —— 那是錯誤資料。
--   正解是取括號外的名稱,並把「道聲」記為經銷關係(目前 schema 沒有這個概念)。
--   這 18 筆多為 0 本,但機制要先議定再動。
--
-- 【二】多家並列 = 共同出版,依多值鐵律不拆不歸一
--   「橄欖 | 華宣 | 華神」(15 本)、「橄欖、聖經資源中心」(1)、「聖經資源中心、橄欖」(11)、
--   「台灣神學研究學院、橄欖」(1)、「橄欖、真光」(1)、「基督教台灣浸會神學院、橄欖」(0)、
--   「橄欖、台灣神學研究學院」(0)
--   ⇒ 同一組合還出現兩種順序(橄欖、聖經資源中心 / 聖經資源中心、橄欖)。
--   ★ 專案鐵律:分隔符號多值原樣保留不拆。歸到任一家都會丟失另一家。
--   正解需要 book↔publisher 多對多,屬架構變更。
--
-- 【三】髒值:非出版社的東西被寫進 publisher 欄
--   ISBN:「道聲代理 ISBN ： 9786260112134」「道聲出版社 ISBN ： 9789866205880/9789866205989」
--   商品碼:「4010-163」「CITY HARVEST CHURCH-C060」「台灣以利亞之家協會-E003」
--   裝訂:「Leather-Look」  人名:「Ng Kam-weng」「洪漢義-H008」「吳英同 牧師-W007」
--   HTML 殘留:「其它/P>」(應為 </P>)、「靈糧堂(其它」(括號未閉合)
--   代理關係:「道聲代理」(64 本)、「恩膏代理(其他)」(4)、「道聲代理個人出版品」(1)
--   ⇒ 這些要回頭修爬蟲的 publisher 解析,不是在 DB 歸一。建議另開 [Bug] 票。
--
-- 【四】待熊哥裁示的個案
--   「臺灣道聲出版社」(33 本)  → 併入「道聲」(2810)?臺/台 異體字 + 是否同一家
--   「青橄欖書殿」(37 本)      → 併入「青橄欖出版社」(2423)?「書殿」可能是書店不是出版社
--   「基文社(其它)」(25 本)    → 正規列「基文社」不存在,要先建
--   「宇宙光其它」(28) 已歸一,但「宇宙光(其它)」(2) 也已歸一 ✓ 無需處理
-- =============================================================
