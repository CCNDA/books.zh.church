# 一次性重建手冊(基道爬完後執行)

背景:campus 解析已修正(commit 9ce0314),本機已 reparse 出修正版 campus_books.jsonl。
因跨站 ISBN 合併,採「清空→重匯」一次做完;封面用 book_id 當檔名,故 **book_id 定案後才跑封面**。

## 前置狀態(2026-07-15 已確認)

- campus_books.jsonl:本機 **18,989 筆、authors_raw 疑似 ISBN = 0、人名 10,872**,確認為修正版且檔案完好。
- logos_books.jsonl:基道全站爬完 **22,855 筆**,已 FTP 上傳主機。
- ⚠️ 修正版 campus_books.jsonl 仍需 FTP 上傳主機蓋掉舊檔(線上 DB 現存為舊錯位版)。

## 步驟

0. **備份現行線上 DB(清空前必做,救命用)**
   - 主機:`mysqldump -u <USER> -p<PWD> <DBNAME> > ~/books_backup_20260715.sql`(帳密見 config/app.local.php;-p 後不留空格)
   - 或 Navicat:右鍵資料庫 → 轉儲 SQL 檔案(含結構+資料)。
   - 確認備份檔非 0 byte 再往下。

1. 確認兩個 jsonl 都在主機 /home/ubuntu/books/crawler/data/:
   - campus_books.jsonl(修正版 18,989)
   - logos_books.jsonl(完整版 22,855)
   並確認主機 tools/import.php 為含「唯一鍵 upsert + cap() 截斷」修正的已推版本(commit 0f1f95e 之後)。

2. 匯入前先 dry-run 各跑一次,確認讀取筆數與零無效跳過:
   cd /home/ubuntu/books
   php tools/import.php --file=crawler/data/campus_books.jsonl --source=campus --dry-run
   php tools/import.php --file=crawler/data/logos_books.jsonl  --source=logos  --dry-run

3. Navicat 清空(子表→父表):
   DELETE FROM book_persons; DELETE FROM book_subjects; DELETE FROM identifiers;
   DELETE FROM formats_prices; DELETE FROM links; DELETE FROM media;
   DELETE FROM book_series; DELETE FROM editions; DELETE FROM books;
   DELETE FROM persons; DELETE FROM publishers; DELETE FROM subjects; DELETE FROM series;

4. 主機正式匯入(先 campus 再 logos,同 ISBN13 會合併為同一 Work、logos 補 HKD 版本):
   php tools/import.php --file=crawler/data/campus_books.jsonl --source=campus
   php tools/import.php --file=crawler/data/logos_books.jsonl  --source=logos

5. 下架非書籍(禮品/DVD,campus 大分類 >= 19):
   UPDATE books b JOIN book_subjects bs ON bs.book_id=b.book_id
   JOIN subjects s ON s.subject_id=bs.subject_id
   SET b.is_published=0 WHERE s.scheme='campus' AND LEFT(s.code,2) >= '19';

6. 分類回填(categories 已建、直接回填 category_id):
   UPDATE books SET category_id=NULL WHERE category_id IS NOT NULL;
   UPDATE books b
   JOIN (SELECT bs.book_id, LEFT(s.code,2) AS big
         FROM book_subjects bs JOIN subjects s ON s.subject_id=bs.subject_id
         WHERE s.scheme='campus') x ON x.book_id=b.book_id
   JOIN categories c ON c.code=x.big
   SET b.category_id=c.category_id WHERE b.is_published=1;

7. 封面轉存(最後、一次;book_id 已定案):
   nohup php tools/covers_to_r2.php > covers.log 2>&1 &   (約 3 小時,可續跑)

8. 線上驗證:清單/搜尋/詳情(作者·出版社·雙幣價格)、分類 chip 數字與篩選、封面指向 imgr2。

## 驗證重點(欄位修正成效)
- 隨機抽查數本台灣出版社書(如彰基崎嶇之途):作者應為人名、出版社為出版社名,不再是 ISBN。
- 抽查基道書:應有 HKD 版本;若與校園同 ISBN,應合併為同一書、兩幣別價格並存。

## 基道(logos)專屬處理(2026-07-15 定案)

logos 爬蟲按出版年檢索、**無分類欄位**,故與校園處理方式不同。

### 5b. 基道非書籍下架(接在步驟 5 校園下架之後)
判別改用「標題結尾格式標記」三層分類(不用會誤判的代碼前綴,亦不用會誤傷書的純關鍵字):
- **A 確定非書**:標題括號含 CD/DVD/VCD/MP3/MP4/USB/藍光/NFC/下載卡碼,且非「附贈光碟」、非歌書/樂譜/手冊/繪本 → 自動下架。
- **B 待人工複核**:含 拼圖/貼紙/文具/月曆/禮盒/文件夾/杯… 但無影音標記(假陽性多:婚禮聖經禮盒其實是聖經、耶穌拼圖是書、心靈月曆是書)→ 逐筆判。
- **C 書**:其餘。

執行方式(本機,爬蟲資料就緒後):
  cd web/crawler
  python -X utf8 analyze_logos_nonbooks.py
  # 產出 data/logos_downpublish.sql(A 層精確 store_code 下架 SQL)
  #       data/logos_review.tsv     (B 層複核清單)

- 匯入完成後,於 Navicat 執行 data/logos_downpublish.sql(A 層)。
- B 層:開 logos_review.tsv 挑出確定非書的商品代碼,自行補一條(商品代碼在 identifiers,需三表 join):
  ```
  UPDATE books b
  JOIN editions e ON e.book_id=b.book_id
  JOIN identifiers i ON i.edition_id=e.edition_id
  SET b.is_published=0
  WHERE b.source='logos' AND i.id_type='STORE' AND i.id_value IN ('...');
  ```
- 保留項(勿下架):歌書/樂譜/曲集(校園亦保留詩本樂譜)、雜誌/期刊/學刊、繪本/故事書、附贈光碟的研習手冊、婚禮聖經禮盒(實為聖經)。
- 邊界待你決定:AQ 福音單張(一包30張)目前歸為書(未自動下架),要下架再另補 SQL。

### 6 補充. 分類回填只涵蓋校園
- 步驟 6 的回填以 scheme='campus' 的 book_subjects 為據。logos 書無 subjects:
  - 與校園同 ISBN 合併者 → 沿用校園分類(source='campus')。
  - logos-only 書 → category_id 維持 NULL(可搜尋、不歸類)。**MVP 已知限制,不強做**;基道自建分類屬階段三。

### 7 補充. 封面轉存涵蓋 logos
- covers_to_r2.php 以 is_published=1 AND cover_url IS NULL 篩,與來源無關,基道封面(logos.com.hk)一併轉。
- **順序鐵律:先跑 5(校園)+5b(基道 A 層+B 層複核)非書下架 → 再跑步驟 7 封面**,避免浪費頻寬轉非書封面。
