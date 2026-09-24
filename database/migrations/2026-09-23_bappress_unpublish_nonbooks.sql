-- ════════════════════════════════════════════════════════════════════════
-- bappress 非書下架(人工複核後)— 2026-09-23
--
-- 起因:站方**沒有一致地**把非書標進「影音(52)」「文具(77)」兩個分類,
--   所以 apply_bappress_categories.php 的 unpublish 判定抓不到它們:
--     《世紀頌讚選輯CD》《美樂頌 SingCD》只掛「本社書籍(71)」
--     《摯愛中華 (CD)》只掛「見證/傳記(49)」
--     《我的天糧套裝-環保小布袋》掛「繪本/童書、兒童牧養、靈修/禱告、本社書籍」
--   → 只認分類會讓 CD 與布袋混進書目。爬蟲的書名關鍵字報表把它們撈出來,
--     熊哥 2026-09-23 逐筆複核後裁示下架。
--
-- 判準(熊哥 9/17 + 9/23):**能讀的內容算書;播放、配戴、裝盛的不算。**
--   下架:純 CD/DVD、播放器、布袋
--   保留:附 DVD/CD 的書(主體是書)、附自學 CD 的聖經、填色簿/填色畫冊(兒童活動書)
--   ★ 填色簿保留是因為站上既有的 46545、46601 本來就上架,擋新的會前後不一致。
--
-- ★ 這四本全是 bappress 單源書,下架不影響其他來源。
-- ★ 不刪資料,只設 is_published=0(全站慣例)。
-- ════════════════════════════════════════════════════════════════════════

-- ── 下架前先確認是這四本、且都是單源 ────────────────────────────────────
-- 應回 4 列,來源數皆為 1
SELECT b.book_id, b.title, b.is_published,
       (SELECT COUNT(DISTINCT e.source) FROM editions e WHERE e.book_id = b.book_id) AS 來源數
  FROM books b
 WHERE b.book_id IN (105254, 105374, 105391, 105392)
 ORDER BY b.book_id;

-- ── 下架 ────────────────────────────────────────────────────────────────
UPDATE books SET is_published = 0
 WHERE book_id IN (
   105254,  -- 我的天糧套裝-環保小布袋
   105374,  -- 摯愛中華 (CD)
   105391,  -- 世紀頌讚選輯CD
   105392   -- 美樂頌 SingCD
 );

-- ── 驗證 ────────────────────────────────────────────────────────────────
-- 一、這四本應為 is_published = 0
SELECT book_id, title, is_published FROM books
 WHERE book_id IN (105254, 105374, 105391, 105392) ORDER BY book_id;

-- 二、bappress 非書下架總數:應為 16(apply 的 12 + 本檔 4)
SELECT COUNT(*) AS bappress下架總數 FROM books b
 WHERE b.is_published = 0
   AND (SELECT COUNT(DISTINCT e.source) FROM editions e WHERE e.book_id = b.book_id) = 1
   AND EXISTS (SELECT 1 FROM editions e WHERE e.book_id = b.book_id AND e.source = 'bappress');

-- 三、★ 反向守門:確認該保留的沒有被誤下架(應全部 is_published = 1)
--     附 DVD/CD 的書、附自學 CD 的聖經、填色簿
SELECT book_id, title, is_published FROM books
 WHERE book_id IN (46545, 46601, 46963, 59552, 60865, 68973, 71402,
                   87991, 102003, 102024, 105249, 105331, 105377)
 ORDER BY is_published, book_id;

-- 四、bappress 上架種數(對外公布用的數字,以此為準)
SELECT COUNT(DISTINCT e.book_id) AS bappress上架種數
  FROM editions e JOIN books b ON b.book_id = e.book_id
 WHERE e.source = 'bappress' AND b.is_published = 1;
