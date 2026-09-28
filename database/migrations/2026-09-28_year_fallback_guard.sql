-- =============================================================
-- 2026-09-28  年份篩選後備層守門查詢(唯讀,不改 schema 不改資料)
-- Asana: 1218643959919910([Bug] 年份篩選 5.2 秒)→ 併入 1218194268650259(對帳守門員)
--
-- 【這支在守什麼】
-- api/index.php 的年份篩選在 2026-09-28 從
--     COALESCE((SELECT MAX(ey.publish_date) FROM editions ey WHERE …), b.publish_date)
-- 改成 JOIN 一個先彙總、先用 HAVING 篩過的 editions 衍生表(COUNT 5,140 ms → 167 ms)。
--
-- 新寫法**只看 editions.publish_date,沒有 books.publish_date 那層後備**。
-- 它與舊寫法在今天的資料上輸出完全相同,靠的是一個事實:
--     「沒有任何 edition 有 publish_date、但 books 平面欄有日期」的上架書 = 0 本
-- 而這個 0 來自 tools/import.php 的行為(parse_date() 回傳兩個值,
-- 要嘛都有值要嘛都是 null,同一次匯入同時寫入 books 與 editions),
-- **不是資料庫層的約束**。
--
-- ★ 哪天有工具只更新 books.publish_date(手動 SQL、單表 reparse、新來源的匯入路徑…),
--   那些書就會從年份篩選裡**靜默消失** —— 網站照跑、log 全綠、沒有任何徵兆。
--   這正是本專案反覆踩過的那一類失敗,所以要有這支。
--
-- 【怎麼用】每日排程跑一次;回 0 列 = 正常,回任何列 = 立即處理。
-- =============================================================

-- ── 守門查詢:應回 0 列 ──────────────────────────────────────
SELECT b.book_id, b.title, b.source, b.publish_date AS 平面欄日期
  FROM books b
 WHERE b.is_published = 1
   AND b.publish_date IS NOT NULL
   AND b.publish_date <> ''
   AND NOT EXISTS (SELECT 1 FROM editions ey
                    WHERE ey.book_id = b.book_id
                      AND ey.publish_date IS NOT NULL)
 LIMIT 50;
-- ★ 回了列代表:這些書在年份篩選裡找不到,但書目頁仍會顯示出版年(v_book_list 有後備)。
--   使用者看得到年份、卻篩不到 —— 這種不一致沒有人會來回報。
--   處置二選一:
--     (a) 把那些書的日期補寫進 editions.publish_date(治本,資料回到一致)
--     (b) api/index.php 的年份條件加回後備層(治標,會把 5 秒拿回來)
--   ★ 不要只改其中一半:books 與 editions 兩處都要想過再動。

-- ── 摘要版(給排程抓數字用,一律回一列)────────────────────────
SELECT COUNT(*) AS 只靠平面欄後備_應為0
  FROM books b
 WHERE b.is_published = 1
   AND b.publish_date IS NOT NULL
   AND b.publish_date <> ''
   AND NOT EXISTS (SELECT 1 FROM editions ey
                    WHERE ey.book_id = b.book_id
                      AND ey.publish_date IS NOT NULL);
-- 2026-09-28 基準值:0(當時上架 54,783 本、有 edition 日期 42,964 本)
