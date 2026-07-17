# 讓每本書都落到分類 — 執行手冊(2026-07-17)

## 目的
基道(logos)與每日新品書無來源分類,category_id 為 NULL(約 1.78 萬本)。
本手冊:①補齊分類(CategoryV11 主類)②自動把每本書分到 ≥1 類,保證無 NULL。
支援「一書多分類」(寫入 book_subjects scheme='cat',primary 另寫 category_id)。

> 這是「第一輪自動分類(關鍵字規則)」求覆蓋率;精修(CategoryV11 細類、逐本校正)屬內容面續做。

## 步驟

### 1. 建齊分類(Navicat 對遠端 DB 執行)
執行 `web/database/migrations/2026-07-17_categories_extend.sql`
(新增 倫理/社會/心理/哲學/文學/健康/環境/科學/歷史/地理/藝術/傳媒 + 綜合其他;冪等可重跑)

驗證:`SELECT code,name FROM categories ORDER BY sort_order;` 應見原 12 類 + 新增 13 類。

### 2. FTP 上傳程式
- `tools/classify_categories.php`
(api/、database/ 已在站上;本工具走 CLI,不對外)

### 3. 主機試跑(dry-run,不寫入)
```
cd /home/ubuntu/books
php tools/classify_categories.php --dry-run
```
看「primary 分類分布」是否合理(例如 綜合其他 佔比不宜過高;若某類異常多,回報以便調規則)。
可先小量:`php tools/classify_categories.php --limit=500 --dry-run`

### 4. 正式寫入(僅處理未分類的書,不動既有校園分類)
```
php tools/classify_categories.php
```

### 5. 驗證(貼回結果)
```
SELECT COUNT(*) FROM books WHERE is_published=1 AND category_id IS NULL;   -- 應為 0
SELECT c.name, COUNT(*) n FROM books b JOIN categories c ON c.category_id=b.category_id
 WHERE b.is_published=1 GROUP BY c.name ORDER BY n DESC;                    -- 各類分布
```
線上:重新整理首頁,分類 chip 數字應涵蓋全部上架書;「全部」與各類合計對得起來。

## 選項
- `--all`:重分「所有」上架書(含校園既有分類會被覆寫)。預設不加,只補 NULL。
- `--limit=N`:只處理前 N 本(試跑用)。

## 可逆 / 重跑
- 冪等:重跑只會覆寫 category_id 與 book_subjects.weight,不重複建列。
- 還原第一輪:`UPDATE books SET category_id=NULL WHERE ...`(依需要;或還原備份)。
- 多分類前端瀏覽尚未接 book_subjects(本次先確保覆蓋+資料就位);接多分類 UI 屬後續。
