# 天道北美全量對照：寫回書目網的設計

狀態：**設計草稿**（小芯 2026-11-26，Asana 1220919560670331；母票 1220374760596931）。還沒寫程式。
依據：11/26 全量對照報告（vm10 /tmp/ustd/full_20261125.json；crawler/ustiendao_recheck.py 產）——

| 類別 | 本數 | 寫回做什麼 |
|---|---|---|
| 沒變 | 9,718 | 不動 |
| 改價 | 32 | 更新價格 |
| 有沒有貨「變了」 | 161 | 全是 None→InStock＝第一次讀到「有貨」→ 寫入 |
| 書頁沒標有沒有貨 | 70 | **不動**（None 不蓋已知值） |
| 下架（404／410） | 13 | 標記下架、不刪 |
| 換成別本（ISBN 變了） | 4 | **不自動改**，列給人審 |
| 讀不出 2、抓不到 1、sitemap 不見 14 | 17 | 只記錄，下次對照再看 |

## 1. 資料放哪裡（現況實查）

- 價格與有沒有貨：`formats_prices(edition_id, media_type, price, currency, availability)`。
  - `availability` 欄（varchar(50)，註解「庫存/上架狀態」）**全庫 83,728 筆都是 NULL**，從沒寫過。
  - API `app/lib/book_detail.php` 會把 availability 帶出去，但**前台目前沒顯示**。
- 購買連結：`links(edition_id, link_type='buy', platform, url, note)`，**沒有狀態欄**。

## 2. 寫回規則

1. **只動天道北美的 edition**：`editions.source='ustiendao'`，用 report 的 product_id／url 對到 edition（對不到的列進「對不到」清單，不猜）
2. **改價**：`formats_prices.price` 更新為新值；`currency` 必須是 USD 才寫（不是就停、列出來）
3. **有沒有貨**：值照 schema.org 原字（`InStock`／`OutOfStock`／`PreOrder`…）寫進 `availability`
   - 新值是 None（書頁沒標）→ **不動**（小布 11/24：不明不是缺貨）
4. **下架 13 本**：`formats_prices.availability='Discontinued'`；`links` 不刪（`note` 加「天道北美 2026-11-26 對照為下架」）
   - 下一次對照如果又找得到 → 照新值改回
5. **換成別本 4 本**：不寫；產一份 TSV（book_id、舊 ISBN、新 ISBN、書名、網址）給人審（可能是新版／再版）
6. **冪等**：同一份報告重跑結果一樣；只改「值真的不同」的列

## 3. 工具

`tools/ustiendao_writeback.php <report.json> [--dry-run]`
- 讀報告 → 對 edition → 算出要改的列 → **產生一支 SQL 檔**（不直接寫庫）
- 正式庫寫入走 `ccnda-db-run`：先試跑（ROLLBACK）看筆數，數字對才 `--commit`（自動備份）
- 輸出：改價 N、availability 寫入 N、下架 N、對不到 N、換本 TSV 路徑

## 4. 前台顯示（要熊叔叔決定，見第 6 節）

書目頁購買連結旁顯示：
- `OutOfStock`：「缺貨」（灰色，連結照留）
- `Discontinued`：「天道北美已下架」，**不顯示購買鈕**（或灰掉）
- `InStock`、NULL：照現在（不加字）

## 5. 測試

- 單元：規則表（改價／None 不蓋／InStock 寫入／下架／換本不寫／幣別不是 USD 停）
- 開發庫：books_dev 只有 54 本天道北美（比正式庫舊），用假報告跑 dry-run 對筆數；陰性對照拿掉「None 不蓋」→ 會蓋掉已知值
- 正式：db-run 試跑筆數＝報告筆數才 commit

## 6. 待熊叔叔決定

1. 下架的書前台怎麼呈現：甲「顯示已下架、不給購買鈕」／乙「直接拿掉天道北美那個購買連結」
2. 缺貨要不要在前台標「缺貨」（還是只有下架才標）
3. 之後每週對照一次就自動寫回，還是每次都先給人看報告再寫

## 7. 工作拆解

| 步驟 | 內容 | 估時 |
|---|---|---|
| 1 | 小布審這份設計＋熊叔叔三題 | — |
| 2 | ustiendao_writeback.php＋單元測試＋dry-run | 1 天 |
| 3 | 正式庫 db-run 試跑 → commit | 0.5 天 |
| 4 | 前台顯示（依第 6 節決定）＋上線 | 0.5 天 |
