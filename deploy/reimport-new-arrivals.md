# 重匯今日新品(修正解析錯位)runbook — 2026-07-16

背景:主機 `campus_crawler.py` 曾為舊版(位置式解析),今日 cron 匯入的校園新書出現欄位錯位
(如 book 74639《君王覺醒》:作者中文名 → 出版社、代理商 → 原文書名)。7/15 重建的約 2.9 萬本
是用本機修正版解析、不受影響;**僅今日 cron 新增的校園書需重匯**。

## 步驟(依序;先部署再清資料)

### 1. 部署修正版程式(FTP 上傳到主機)
- `crawler/campus_crawler.py`(讀「作者：/出版社：」標籤 + 清除誤標 title_en + 貢獻者擷取)
- `crawler/logos_crawler.py`、`crawler/common.py`、`tools/import.php`(多角色寫入)

### 2. 先看今日匯入了哪些(Navicat,線上庫)
```sql
SELECT b.book_id, e.source_url, b.title, b.author, b.publisher, b.original_title
FROM books b JOIN editions e ON e.book_id = b.book_id
WHERE b.source = 'campus' AND DATE(b.created_at) = '2026-07-16'
ORDER BY b.book_id;
```
確認這些就是要重匯的書(數量約個位數)。

### 3. 刪除今日新增的校園書(Navicat;含各關聯子表)
> 先備份:`mysqldump ... books editions identifiers formats_prices media links book_persons book_subjects book_series > backup_before_reimport.sql`
```sql
CREATE TEMPORARY TABLE tmp_reimport AS
  SELECT book_id FROM books WHERE source = 'campus' AND DATE(created_at) = '2026-07-16';

DELETE FROM identifiers    WHERE edition_id IN (SELECT edition_id FROM editions WHERE book_id IN (SELECT book_id FROM tmp_reimport));
DELETE FROM formats_prices WHERE edition_id IN (SELECT edition_id FROM editions WHERE book_id IN (SELECT book_id FROM tmp_reimport));
DELETE FROM media          WHERE edition_id IN (SELECT edition_id FROM editions WHERE book_id IN (SELECT book_id FROM tmp_reimport));
DELETE FROM links          WHERE edition_id IN (SELECT edition_id FROM editions WHERE book_id IN (SELECT book_id FROM tmp_reimport))
                              OR book_id IN (SELECT book_id FROM tmp_reimport);
DELETE FROM editions       WHERE book_id IN (SELECT book_id FROM tmp_reimport);
DELETE FROM book_persons   WHERE book_id IN (SELECT book_id FROM tmp_reimport);
DELETE FROM book_subjects  WHERE book_id IN (SELECT book_id FROM tmp_reimport);
DELETE FROM book_series    WHERE book_id IN (SELECT book_id FROM tmp_reimport);
DELETE FROM books          WHERE book_id IN (SELECT book_id FROM tmp_reimport);
DROP TEMPORARY TABLE tmp_reimport;
```
註:誤建的孤兒 persons/publishers(如 publisher「吳炳偉」)不刪也無妨——它們沒有關聯,
不會出現在 /api/persons、/api/publishers(皆 JOIN 有書的關聯)。日後可另清。

### 4. 從 master jsonl 移除今日抓取的校園記錄,讓 cron 重新偵測為新書(主機)
```bash
cd /home/ubuntu/books/crawler
cp data/campus_books.jsonl data/campus_books.jsonl.bak
grep -v '"fetched_at":"2026-07-16' data/campus_books.jsonl.bak > data/campus_books.jsonl
wc -l data/campus_books.jsonl.bak data/campus_books.jsonl   # 確認少了今日筆數
```
(快取頁保留即可;重跑會用快取 HTML 以**新解析**重新產生正確欄位,不必重抓。)

### 5. 重跑一次(主機)
```bash
cd /home/ubuntu/books/crawler
source venv/bin/activate
bash daily_new.sh
tail -n 30 logs/new-arrivals-$(date +%Y%m%d).log
```

### 6. 驗證(線上)
```
https://books.zh.church/book/74639
```
應為:作者=吳炳偉(Dr. Rev. Herbert Wu)、出版社=以琳代理、無錯誤的原文書名;
其餘今日書抽查作者/出版社正確。

## 之後
- 明日起 cron 已用修正版解析,新書欄位正確、並帶譯者/繪者。
- 若日後仍見零星台灣書錯位,多屬來源頁排版特例,回報 book_id 個案處理。
