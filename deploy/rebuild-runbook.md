# 一次性重建手冊(基道爬完後執行)

背景:campus 解析已修正(commit 9ce0314)、本機已 reparse 出修正版 campus_books.jsonl(18,989)。
因跨站 ISBN 合併,採「清空→重匯」一次做完;封面用 book_id 當檔名,故 **book_id 定案後才跑封面**。
**在基道(logos)爬完前不要重匯、不要跑封面。**

## 步驟

1. 確認基道爬蟲跑完(logos_books.jsonl 達 ~24,609;若還沒到量先讓它跑完)。
2. FTP 上傳到主機 /home/ubuntu/books/crawler/data/:
   - campus_books.jsonl(已 reparse 修正版)
   - logos_books.jsonl(爬完的完整版)
3. Navicat 清空(子表→父表):
   DELETE FROM book_persons; DELETE FROM book_subjects; DELETE FROM identifiers;
   DELETE FROM formats_prices; DELETE FROM links; DELETE FROM media;
   DELETE FROM book_series; DELETE FROM editions; DELETE FROM books;
   DELETE FROM persons; DELETE FROM publishers; DELETE FROM subjects; DELETE FROM series;
4. 主機匯入(先 campus 再 logos,同 ISBN 會合併、logos 補 HKD 版本):
   cd /home/ubuntu/books
   php tools/import.php --file=/home/ubuntu/books/crawler/data/campus_books.jsonl --source=campus
   php tools/import.php --file=/home/ubuntu/books/crawler/data/logos_books.jsonl  --source=logos
5. 下架非書籍(禮品/DVD,大分類 >= 19):
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
   舊的孤兒 R2 物件(前次若試跑過)可到 R2 後台刪 books/ 前綴後再跑,或不管。
8. 線上驗證:清單/搜尋/詳情(作者·出版社·雙幣價格)、分類 chip 數字與篩選、封面指向 imgr2。

## 驗證重點(欄位修正成效)
- 隨機抽查數本台灣出版社書(如彰基崎嶇之途):作者應為人名、出版社為出版社名,不再是 ISBN。
