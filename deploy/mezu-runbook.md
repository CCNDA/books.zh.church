# 真哪噠(mezu)來源上線 Runbook(2026-08-22 建立)

第十一個書目來源:**基督教真哪噠買書(MEZU)網** www.mezu.com.tw
(EasyStore 商城,浸信會特約書店,TWD、繁體;全站 10,027 件)。
版號 **v1.8.0**(v1.7.0 已給宇宙光;M1-B 進階搜尋順延 v1.9.0)。

## 0. 三項決議(8/22 熊哥)

1. **抓取範圍**:全站 10,027 件抓入存證,非書(喜樂影音/哪噠禮品/潮牌禮品/
   客製化月曆/月桌曆)由 `mezu_category_map` 的 `unpublish` 下架
   (任一命中即下架,僅 mezu-only 書;跨站合併的書不下架)。
2. **欄位解析**:站方描述是 Froala 自由文字、標籤不統一 → **全量抓原始描述,
   欄位解析留待後續版本**。原文整段存 `extra.desc_raw`、通用標籤採集存
   `extra.spec_all`,只有白名單欄位入平面欄。
3. **分類依據**:只有站方 **17 個主題分類**參與站內分類;出版社/總代理
   (約 70 個)與促銷彙整僅存證不歸類,出版社分類另作出版社欄位佐證
   (apply 工具只報表、不寫入)。

## 1. 檔案上傳對照表(FTP;本機 → 主機)

| 本機(repo 內) | 主機路徑 | 動作 |
|---|---|---|
| `web/crawler/mezu_crawler.py` | `crawler/mezu_crawler.py` | 新增 |
| `web/tools/apply_mezu_categories.php` | `tools/apply_mezu_categories.php` | 新增 |
| `web/database/migrations/2026-08-22_mezu_category_map.sql` | (不必上傳,Navicat 執行) | — |
| `web/tools/import.php` | `tools/import.php` | 覆蓋(來源白名單 + mezu 購書平台名) |
| `web/tools/covers_to_r2.php` | `tools/covers_to_r2.php` | 覆蓋(`--source` 白名單) |
| `web/api/index.php` | `api/index.php` | 覆蓋(購書平台「真哪噠」排序 12) |
| `web/crawler/new_arrivals.py` | `crawler/new_arrivals.py` | 覆蓋(`--source mezu`) |
| `web/crawler/daily_new.sh` | `crawler/daily_new.sh` | 覆蓋(第十一站 + apply) |
| `web/crawler/README.md` | `crawler/README.md` | 覆蓋(文件) |

> 前端 `web/` 目錄本次**沒有**變更。
>
> ⚠️ **`VERSION`(已改 1.8.0)與 `index.html` footer 版號請等真哪噠正式上線後再一併
> 處理**——repo 版號會領先主機,提前上傳會讓站上顯示未發布的版號(8/21 教訓)。
> footer 目前是 `v1.7`,上線當天改成 `v1.8` 並同批 FTP。

## 2. 執行順序(主機 CLI)

```bash
cd ~/books/crawler

# (1) 探測:驗 sitemap 件數、分頁停止條件、商品頁解析、欄位命中率
venv/bin/python mezu_crawler.py --probe 2>&1 | tee logs/mezu-probe.log
#     → 把輸出貼回給 Claude 確認後再繼續(尤其「欄位命中率」與「標籤分布」)

# (2) 全量(可中斷續跑;預估 5-7 小時,建議夜間跑)
nohup venv/bin/python mezu_crawler.py > logs/mezu.log 2>&1 &
tail -f logs/mezu.log

# (3) Navicat 執行 migration(建 mezu_category_map;119 列)
#     database/migrations/2026-08-22_mezu_category_map.sql
#     驗證:SELECT COUNT(*), SUM(internal_name IS NOT NULL), SUM(unpublish)
#             FROM mezu_category_map;   -- 預期 119 / 23 / 5

# (4) 匯入(先 dry-run 看「合併明細」:ISBN 命中 vs 模糊比對)
cd ~/books
php tools/import.php --file=crawler/data/mezu_books.jsonl --source=mezu --dry-run
php tools/import.php --file=crawler/data/mezu_books.jsonl --source=mezu

# (5) 套分類 + 下架非書
php tools/apply_mezu_categories.php --dry-run
php tools/apply_mezu_categories.php

# (6) 封面轉 R2(只轉新書)
php tools/covers_to_r2.php --source=mezu --dry-run
php tools/covers_to_r2.php --source=mezu
```

## 3. 驗收(Navicat / 線上)

```sql
-- 來源件數與下架數
SELECT COUNT(*) AS 版本數 FROM editions WHERE source='mezu';
SELECT SUM(is_published=1) AS 上架, SUM(is_published=0) AS 下架
  FROM books WHERE source='mezu';
-- 站方分類存證
SELECT COUNT(*) FROM subjects WHERE scheme='mezu';
-- 站內分類分布
SELECT c.name, COUNT(*) FROM books b
  JOIN categories c ON c.category_id=b.category_id
  JOIN editions  e ON e.book_id=b.book_id AND e.source='mezu'
 WHERE b.is_published=1 GROUP BY c.name ORDER BY 2 DESC;
-- 欄位命中率(決定下一版要不要做完整欄位解析)
SELECT COUNT(*) AS mezu書,
       SUM(author IS NULL OR author='')      AS 無作者,
       SUM(publisher IS NULL OR publisher='') AS 無出版社,
       SUM(isbn13 IS NULL)                    AS 無ISBN
  FROM books WHERE source='mezu';
-- 描述區標籤分布(下一版欄位對映的依據)
SELECT JSON_KEYS(JSON_EXTRACT(extra,'$.mezu.spec_all')) AS 標籤, COUNT(*)
  FROM books WHERE JSON_EXTRACT(extra,'$.mezu.spec_all') IS NOT NULL
 GROUP BY 1 ORDER BY 2 DESC LIMIT 30;
```

線上抽查:https://books.zh.church 搜「真哪噠」;隨機一本點進詳情頁確認
封面走 R2、購書連結出現「真哪噠」(排序 12)。

## 4. 隔日驗證每日新品 cron

```bash
grep -A3 "真哪噠" crawler/logs/new-arrivals-$(date +%Y%m%d).log
```
預期:`mezu:無新書` 或 `mezu:偵測到 N 本新書,匯入完成`,且
`真哪噠分類套用完成`。

## 4.5 8/23 probe 結果與補強(已改進 crawler,需重新 FTP `mezu_crawler.py`)

probe 通過:sitemap 10,027 件 / 119 分類、各款聖經 240 件、`page=99` 被夾回末頁、
sitemap 覆蓋完整;出版社/價格/簡介 100%、封面 97%、作者 90%。
唯一弱項是 **ISBN 只有 17%**(站方描述多半不寫 ISBN)→ 補強:

- ISBN 來源優先序改為「標籤 → 條碼欄(含「電腦條碼」)→ 描述裸掃 → **handle**」;
  站方 **1,937 件的 handle 本身就是 ISBN**(ISBN13 1,491、ISBN10 446),
  後兩種來源**嚴驗檢查碼**(實測 1,489/1,491、427/446 通過),裸掃還要求整段只有
  一個 ISBN,寧缺勿錯——ISBN 錯會把兩本不同的書合併成一本。來源記在 `isbn_from`。
- handle 像店內貨號者存 `item_no`(排除英文書名 slug);價格去小數尾零(站方是
  `2190.00`);`spec_all` 過濾目錄行(「第十二課 …」)與單字鍵;複合值再撈尺寸/頁數。

重跑 `--probe` 會用快取,幾秒就跑完,可直接看新的命中率與 `isbn←來源` 分布。

### 8/23 重跑 probe 結果(補強生效)

`isbn←handle 15%`、`isbn←條碼欄 2%`,加上原本的標籤來源,ISBN 命中率約翻倍;
`spec_all` 的目錄行垃圾鍵幾乎清光。**下一版欄位對映的候選**(本次抽樣各只出現 1 次,
量太少,不值得為它重啟 5-7 小時的全量):

- `英文書名` / `英文名稱` → `title_en`
- `型號` → `item_no` 的另一來源(目前 item_no 只取自 handle)
- `出版者`(× 3)、`國際書號`(× 1)已在白名單內

上線後用第 3 節那條 `JSON_KEYS` SQL 統計全量 10,027 件的標籤分布,再一次決定
要不要做完整欄位解析(8/22 決議把這件事留給下一版)。

## 5. 已知待覆核(上線後抽查)

- `詩本其他` 是混類(詩本 + 其它雜項),已對映「詩本樂譜」→ 抽查是否需拆。
- `各類教材` 對映「門徒造就」;若實際多為兒童主日學教材,Navicat 改
  `internal_name='兒童教材'` 後重跑 apply 即生效。
- `音樂娃娃`、`磐石有聲事工` 兩個代理分類可能含影音 → 若確認,把該分類
  `unpublish=1` 或靠 `classify_categories.php` 的非書關鍵字保底。
- 站方有少數簡體品項(書名含「简体/簡體」),本版**不做簡繁轉換**,
  語文別若站方有寫則入 `language` 欄。
