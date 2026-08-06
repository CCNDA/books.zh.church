# 書目爬蟲(校園書房 + 基道 + 以琳書房 + 天恩出版社)

單執行緒禮貌抓取。輸出 JSONL 原始資料,匯入正規化表由後續 importer 處理(拆分可無損還原,多值一律原樣保留)。校園/基道在熊哥本機(Windows)執行;以琳(2026-07-31 新增)、天恩(2026-08-06 新增)於主機執行。

## 以琳書房(elim,7/31 新增)

主機執行流程(先 Navicat 跑 `database/migrations/2026-07-31_elim_category_map.sql`,再 FTP 上傳程式):

```bash
cd crawler
python3 -m venv venv && venv/bin/pip install -r requirements.txt   # 一次
venv/bin/python elim_crawler.py --probe          # 先探測,輸出貼回給 Claude 確認
nohup venv/bin/python elim_crawler.py > logs/elim.log 2>&1 &        # 全量(可中斷續跑)
# 完成後匯入 + 套分類(都先 dry-run 看統計):
php tools/import.php --file=crawler/data/elim_books.jsonl --source=elim --dry-run
php tools/import.php --file=crawler/data/elim_books.jsonl --source=elim
php tools/apply_elim_categories.php --dry-run
php tools/apply_elim_categories.php
```

範圍:只抓「書籍」「聖經」兩大類(7/31 決議;影音/禮品不抓,日誌月曆照抓、匯入後由對映表下架)。
分類雙軌:以琳原始分類(一書多分類、完整路徑)入 `subjects(scheme='elim')` 永久存證;站內瀏覽分類由 `elim_category_map` 對映(Navicat 可改,改後重跑 apply 即生效)。
注意:以琳商品頁不顯示 ISBN 文字,僅能從商品圖檔名提取(候選唯一才認定),其餘靠書名+作者模糊合併。

## 天恩出版社(grace,8/6 新增)

WooCommerce 站:商品清單走公開 Store API(穩定 JSON、日期倒序),書目欄位
(作者/譯者/ISBN/頁數/初版/開數/定價/英文書名)在商品頁 HTML「資訊」頁籤,
每件仍抓一次 HTML(節流 2-3 秒,全站約 1,626 件、約 1.5 小時)。

主機執行流程(先 Navicat 跑 `database/migrations/2026-08-06_grace_category_map.sql`,再 FTP 上傳程式):

```bash
cd crawler
venv/bin/python graceph_crawler.py --probe       # 先探測,輸出貼回給 Claude 確認
nohup venv/bin/python graceph_crawler.py > logs/grace.log 2>&1 &    # 全量(可中斷續跑)
# 完成後匯入 + 套分類(都先 dry-run 看統計):
php tools/import.php --file=crawler/data/grace_books.jsonl --source=grace --dry-run
php tools/import.php --file=crawler/data/grace_books.jsonl --source=grace
php tools/apply_grace_categories.php --dry-run
php tools/apply_grace_categories.php
php tools/classify_categories.php                 # 只補未分類新書
php tools/covers_to_r2.php --source=grace         # 封面轉存 R2
```

範圍(8/6 決議):全站抓入存證;非書(文創禮品/質選文創好物/專輯有聲/虛擬商品/年度日月曆)匯入後由對映表下架(命中任一即下架);電子書照書上架、與紙本同書合併(同名同作者即使 eISBN 不同也併),購書連結標示「天恩出版社(電子書)」。
分類雙軌:天恩原始分類(平面多分類)入 `subjects(scheme='grace')` 永久存證;站內瀏覽分類由 `grace_category_map` 對映(Navicat 可改 internal_name/sort_order,改後重跑 apply 即生效);「新書快報/暢銷排行/電子書」為促銷/格式類,不入對映。
電子書判定:商品名含「電子書」或編號以 eb 開頭(電子書「分類」不可靠,有紙本書誤掛)。

## 安裝(一次)

```bat
cd web\crawler
pip install -r requirements.txt
```

## 第一步:探測(務必先跑,把輸出貼回給 Claude)

兩站的分頁機制與欄位版型是依 7/11 偵察寫的,需先驗證:

```bat
python -X utf8 campus_crawler.py --probe
python -X utf8 logos_crawler.py --probe
```

把兩段輸出貼回對話,Claude 確認/修正解析後再開全量。

## 第二步:全量開跑(兩個視窗並行)

```bat
:: 視窗 1(校園,3-5 秒/請求)
python -X utf8 campus_crawler.py

:: 視窗 2(基道,2-3 秒/請求)
python -X utf8 logos_crawler.py
```

- 隨時 **Ctrl+C 可中斷**;重跑同指令自動續抓(頁面快取 + 進度狀態)
- 預估:兩站並行約 33 小時內完成(7/14 前)
- 試跑:加 `--limit 50`;指定範圍:campus `--category 0402`、logos `--years 2024,2025`

## 產出

| 檔案 | 內容 |
|---|---|
| `data/campus_books.jsonl` | 每行一本,key=product_id |
| `data/logos_books.jsonl` | 每行一本,key=code |
| `data/elim_books.jsonl` | 每行一本,key=gid |
| `data/grace_books.jsonl` | 每行一本,key=pid |
| `data/*_state.json` | 已完成的分類/年份(續跑用) |
| `cache/` | 已抓頁面 gzip 快取(重新解析不需重抓) |

`data/` 與 `cache/` 不進 git。

## 合規

- UA 註明 CCNDA、聯絡信箱與用途;robots.txt 已核(校園允許商品/分類頁;基道無限制;天恩僅擋 wp-admin/cart/checkout/my-account)
- 429/503 自動退避;單執行緒 + 隨機延遲
- 簡介與封面入庫時保留 source_url 標注出處
