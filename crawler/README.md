# 書目爬蟲(校園書房 + 基道 + 以琳書房)

單執行緒禮貌抓取。輸出 JSONL 原始資料,匯入正規化表由後續 importer 處理(拆分可無損還原,多值一律原樣保留)。校園/基道在熊哥本機(Windows)執行;以琳(2026-07-31 新增)於主機執行。

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
| `data/*_state.json` | 已完成的分類/年份(續跑用) |
| `cache/` | 已抓頁面 gzip 快取(重新解析不需重抓) |

`data/` 與 `cache/` 不進 git。

## 合規

- UA 註明 CCNDA、聯絡信箱與用途;robots.txt 已核(校園允許商品/分類頁;基道無限制)
- 429/503 自動退避;單執行緒 + 隨機延遲
- 簡介與封面入庫時保留 source_url 標注出處
