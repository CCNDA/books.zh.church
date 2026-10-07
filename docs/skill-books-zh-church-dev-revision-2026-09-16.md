# books-zh-church-dev 技能修訂清單(2026-09-16 改版)

對應 Asana 票 **1218041461060685**「[開發紀錄 9/1] 更新 Claude 專案 instructions 與技能」
的第二、四項待辦。改版後的 SKILL.md 已存回帳號(`save_skill`,overwrite)。

改版前的技能**整份停在 7 月**,共 65 行。以下逐項列出改了什麼、為什麼。

---

## 一、必須刪除的錯誤描述(寫著就會誤導)

| 舊技能的敘述 | 事實 |
|---|---|
| 「一般虛擬主機(Apache)」 | Ubuntu 24.04 + **nginx 1.24 + PHP 8.3-FPM**,`.htaccess` 無效,路由在 nginx server block |
| 「MariaDB(utf8mb4)」未載明版本 | **MariaDB 10.11**,**無原生 JSON 型別**(`extra`/`buy_links` 是 TEXT/MEDIUMTEXT + JSON 函式) |
| 「MVP 期間 books 平面表只是 staging 層」「階段三程式拆分 → 正規化 14 表」 | Work/Edition 正規化**早已上線**,不是未來式 |
| 「MVP(7/17 華福大會)…階段二 8/21 / 階段三 9/18 / 階段四 10/2 / 階段五 10/9」 | 全數過時。**現況時程一律以 Asana 與專案記憶為準,技能不記載** |
| 「**history.md**(工作資料夾根目錄):追加當日段落」 | 根目錄 `history.md` **已廢除**,改 `history/YYYY-MM-DD.md` 每日一檔 |
| 「MVP 衝刺任務 GID …,子任務 D1-D6(7/11-7/16)」 | 該衝刺 7 月即結案,留著會讓人誤以為那是當前工作 |
| 「掛載磁碟怪癖:git 二進位寫入的 .git/config 會不可讀…用 shell 重導向手寫 .git/config」 | **與現行鐵律直接衝突**:掛載端**不跑任何 git 指令**,連唯讀的 `status`/`diff` 都不行 |
| 「push 需使用者提供一次性 GitHub PAT」 | PAT 存在 `web/config/deploy.local.md`(gitignore);git 一律由使用者本機 PowerShell 執行 |

## 二、新增章節(舊技能完全沒有的)

1. **三層設定分工說明** —— 明寫「現況資訊(版號/站數/里程碑)本手冊不記載」,避免技能再度腐化。
2. **動工前三件事** —— Asana 先開單鐵律;**★ 重驗票上前提**(已有多張票的前提被實測推翻);確認階段歸屬。另加「notes 是歷史不是現況,要看子任務/最後幾則 comment/落地檔案三處」。
3. **新來源標準流程(逐步細節)** —— 舊技能只有籠統的「開發與驗證工作流程」四步。新版含:
   - 偵察要拿到的六類實測數字(平台家族、權威清單 URL 逐筆差集、分頁超界行為、全量欄位覆蓋率、最長欄位值、幣別)
   - **平台家族對照表**(WooCommerce / Shopline / Cyberbiz / OpenCart / Shopify 系 / 自建)與可沿用的既有爬蟲,含「外觀像 Shopify 不代表端點可用」
   - 三項決議要使用者裁示,不自己定
   - 爬蟲六種模式與 `CRAWLER_REV` 版本戳記、解析失敗碼要寫 review 檔
   - 對映表 `internal_name` 必對實表 + **驗證查詢 SQL 原文**
   - **四處白名單清單**(SOURCE_CURRENCY / NO_BUY_SOURCES / api / covers_to_r2)
4. **28 條必查陷阱**(分五類:清單分頁 / 欄位解析 / ISBN / 匯入合併 / 部署驗證)—— 舊技能零條。
5. **匯入與合併判準細節** —— `same_work()` 四條判準與 0.4 門檻的校準依據、簡繁處理(同 ISBN 就併、不同就拆;絕不做字形轉換;既有連結不可用 `REPLACE()` 修)、兩個 schema 陷阱(商品代碼在 `identifiers` 附三表 join SQL、`categories` 是 CategoryV11)。
6. **改共用檔的守則** —— 條件要描述「發生了什麼事」不是「資料長什麼樣」;改共用檔一定要有能驗的數字;上傳後先對既有來源跑 dry-run;交付說明的保證要對照實作再寫。
7. **對帳守門員** —— 權威清單取得方式、對帳含下架書、家數實查 SQL、「漏抓完全沒有外部徵兆」。
8. **發版與交付格式** —— 五檔、release-notes 會原樣送 Discord(不可寫內部註解)、`--follow-tags` 不推 lightweight tag、完整發布順序、git 指令包與四項驗證、FTP 對照表義務(含 release-notes 要上傳、migrations 不用)。
9. **環境限制** —— 沙箱無 PHP 但瀏覽器連得到外站;Edit 工具截斷、Glob 逾時、NAS 同步覆蓋三個掛載怪癖。
10. **憑證處理** —— `pgrep -c` 而非 `pgrep -a`、輸出先遮罩、`app.local.php` 要單獨 FTP。
11. **收尾義務四項** —— Asana 回報附驗證數字、history 每日一檔、專案記憶、對外內容先給草稿。

## 三、保留並補強的

- SQL 全用 PDO prepared statements、**同名參數不可重複使用**(`EMULATE_PREPARES=false`)→ 保留
- HTML escape、介面繁體中文、API 回應格式、X-Api-Key `hash_equals`、UTF-8 無 BOM → 保留
- **全形標點 regex** → 由「要寫 `[:：]`」補強為「並以 hex 驗檔案實際位元組(生成時常退化成 `[::]`);冒號後用 `[ \t]*` 不要 `\s*`」
- 資料多重呈現鐵律 → 保留,補上「頁數多值不拆不加總」「匯入前量最長欄位值」「lookup 用 `ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)`」與「`utf8mb4_unicode_ci` 不把簡繁視為相等 → 簡繁兩種寫法各自登錄」
- 封面 R2 設定 → 保留
- 常用參考檔 → 補 `web/deploy/nginx-books.conf`、`web/CLAUDE.md`

## 四、description 也改了(影響觸發準確度)

舊描述列的是「PHP 8 + MariaDB 技術規範、資料多重呈現原則、Navicat/FTP 部署驗證流程、Asana 與 history 紀錄義務」。
新描述改列實際內容並補觸發語「收一個新書房」:

> …內含新來源標準流程逐步細節、28 條必查陷阱、匯入合併判準、共用檔修改守則、對帳守門員、發版與交付格式。

---

## 尚未處理 / 需要注意

- **本檔在根目錄 `docs/`,不在 `web/` repo 內,因此不進版控。** 技能本體只存在帳號裡;
  若要讓技能進版控,需另行決定是否在 `web/docs/` 放一份 SKILL.md 副本並納入發版檢查。
- 技能內容無法程式化驗證,只能靠下一次實際工作時檢驗是否好用。
  建議在下一個新來源(bappress)開工時特別留意:流程章節是否真的能照著做、
  有沒有哪一步在手冊上找不到答案。
- 票上第一、三項待辦(產出 instructions 草稿、貼上專案設定)看來已完成
  —— 現行專案 instructions 已含該票列出的全部缺漏事項,但票上勾選框仍全空,待熊哥確認勾除。
