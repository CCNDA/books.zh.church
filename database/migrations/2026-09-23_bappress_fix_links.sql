-- ════════════════════════════════════════════════════════════════════════
-- bappress 匯入後的兩處修正 — 2026-09-23
--
-- 這兩個問題都是**匯入後回查資料庫才發現的**,不是程式跑失敗:
-- import.php 的輸出從頭到尾都是綠的(讀 3,704、新書 213、合併 3,491)。
--
-- 【問題一】11 筆「站方重複建檔」(-D 貨號)照樣掛了購書連結
--   熊哥 9/22 裁示「只留無後綴那條連結」。程式只改了 books.buy_links 平面欄的
--   $hasBuy,而 links 表的寫入(import.php 第 773 行)**沒有看那個旗標**,
--   於是 11 條連結照樣寫進去。實查 links 3,704 筆、預期 3,693,差 11 才發現。
--   ★ import.php 自己的註解早就寫過「只改 links.link_type 是不夠的,兩邊是聯集」;
--     反過來也成立。程式已修(兩處都要過旗標),本檔清既有資料。
--
-- 【問題二】同 ISBN 不同商品的版本標註抽到「共有詞」
--   藍/紅兩本聖經都標成「浸信會出版社(皮面)」—— 完全失去區分作用。
--   根因:爬蟲用 regex 交替取**最左命中**的詞,而「皮面」是兩本共有的,
--   它根本不是差異。程式已修(改成只取同組其他書沒有的詞),本檔更正既有兩列。
--   ★ 不能用 REPLACE() 批次改:兩條的 platform 字串**完全一樣**,
--     字串替換分不出哪條是哪條 —— 必須用 url 逐條指定。
--     (這正是簡繁標註那次記下的教訓。)
-- ════════════════════════════════════════════════════════════════════════

-- ── 修正前先看 ──────────────────────────────────────────────────────────
-- 應回 11 列:-D 貨號的 edition 卻有 buy 連結
SELECT l.link_id, e.edition_id, i.id_value AS 貨號, l.platform, l.url
  FROM links l
  JOIN editions e ON e.edition_id = l.edition_id
  JOIN identifiers i ON i.edition_id = e.edition_id AND i.id_type = 'STORE'
 WHERE e.source = 'bappress'
   AND l.link_type = 'buy'
   AND i.id_value REGEXP '-D[0-9]+$'
   -- ★ 只清「主貨號也存在」的那些:沒有主貨號的 -D 是站上唯一一筆,
   --   砍掉它的連結會讓那本書一條購書管道都沒有(實測 121 個 -D 裡有 110 個是這種)。
   AND EXISTS (
        SELECT 1 FROM identifiers i2
          JOIN editions e2 ON e2.edition_id = i2.edition_id
         WHERE e2.source = 'bappress' AND i2.id_type = 'STORE'
           AND i2.id_value = SUBSTRING_INDEX(i.id_value, '-D', 1));

-- 應回 2 列,且 platform 目前都是「浸信會出版社(皮面)」
SELECT l.link_id, l.platform, l.url FROM links l
  JOIN editions e ON e.edition_id = l.edition_id
 WHERE e.source = 'bappress' AND l.platform LIKE '浸信會出版社(%';


-- ── 修正一:刪掉重複建檔的購書連結(11 筆)──────────────────────────────
-- ★ 只刪 links,identifiers(商品代碼)保留 —— 對帳要靠它。
DELETE l FROM links l
  JOIN editions e ON e.edition_id = l.edition_id
  JOIN identifiers i ON i.edition_id = e.edition_id AND i.id_type = 'STORE'
 WHERE e.source = 'bappress'
   AND l.link_type = 'buy'
   AND i.id_value REGEXP '-D[0-9]+$'
   AND EXISTS (
        SELECT 1 FROM identifiers i2
          JOIN editions e2 ON e2.edition_id = i2.edition_id
         WHERE e2.source = 'bappress' AND i2.id_type = 'STORE'
           AND i2.id_value = SUBSTRING_INDEX(i.id_value, '-D', 1));

-- ── 修正二:版本標註改成真正的差異詞(逐條以 url 指定)──────────────────
UPDATE links SET platform = '浸信會出版社(藍色)'
 WHERE url = 'https://shop.bappress.org/books/B11R125';

UPDATE links SET platform = '浸信會出版社(紅色)'
 WHERE url = 'https://shop.bappress.org/books/B11R125R';

-- books.buy_links 平面欄位同步(★ 這裡可以用 REPLACE:平面欄是每本書一筆 JSON,
--    而藍/紅併成同一本書,該筆 JSON 裡兩個 url 不同,用 url 當錨點仍可區分)
UPDATE books SET buy_links = REPLACE(buy_links, '浸信會出版社(皮面)', '浸信會出版社(藍色)')
 WHERE buy_links LIKE '%B11R125"%' AND buy_links NOT LIKE '%B11R125R%';


-- ── 修正後驗證(三條都要跑)──────────────────────────────────────────────
-- 一、購書連結數:應為 3,693(= 匯入 3,704 − 重複建檔 11)
SELECT COUNT(*) AS bappress購書連結數
  FROM links l JOIN editions e ON e.edition_id = l.edition_id
 WHERE e.source = 'bappress' AND l.link_type = 'buy';

-- 二、版本標註:應回 2 列,且一藍一紅(不再是兩個「皮面」)
SELECT l.platform, l.url FROM links l
  JOIN editions e ON e.edition_id = l.edition_id
 WHERE e.source = 'bappress' AND l.platform LIKE '浸信會出版社(%'
 ORDER BY l.platform;

-- 三、★ 反向守門:確認沒有任何 bappress 的書變成「一條購書連結都沒有」
--     (修正一若寫錯,會誤砍到只有 -D 一筆的那 110 本)
--     應回 0 列。
SELECT b.book_id, b.title
  FROM books b
 WHERE EXISTS (SELECT 1 FROM editions e
                WHERE e.book_id = b.book_id AND e.source = 'bappress')
   AND NOT EXISTS (SELECT 1 FROM links l
                     JOIN editions e2 ON e2.edition_id = l.edition_id
                    WHERE e2.book_id = b.book_id AND l.link_type = 'buy')
 LIMIT 20;
