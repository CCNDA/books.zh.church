# 維運總覽（books.zh.church）

給接手維運的人一頁看完：東西在哪裡、每天會自己跑什麼、出事先看哪裡。
**本文件不放任何密碼、權杖或金鑰，只寫它們放在哪裡；本 repo 是公開的，內網位址與帳號名稱也不寫在這裡。** 細節以各 runbook 為準。
（Asana【M3-B】公開上線與維運交接 1216467419399059 第 3 項）

## 一、東西在哪裡

| 項目 | 位置 | 備註 |
| --- | --- | --- |
| 正式站 | https://books.zh.church（雲端主機：Ubuntu 24.04、nginx、PHP 8.3-FPM、MariaDB 10.11） | 只有熊哥能操作；主機時區是 **UTC** |
| 站點目錄 | 主機 `/home/ubuntu/books`（git checkout，追蹤 GitHub `main`） | `deploy/deploy.sh` 部署 |
| 原始碼 | GitHub `CCNDA/books.zh.church` | Issue 是對外版，Asana 是正本 |
| 設定（含所有憑證） | 主機 `config/app.local.php`（不進 git） | 資料庫、R2、Discord webhook；範本 `config/app.example.php` |
| 封面圖 | Cloudflare R2（`app.local.php` 的 `r2.public_url`） | `tools/covers_to_r2.php` 轉存 |
| 爬蟲資料 | 主機 `crawler/data/`（各站 master jsonl、`new/` 每日 delta、對照檔） | 不進 git；**重建時一定要先在** |
| 排程 log | 主機 `crawler/logs/new-arrivals-YYYYMMDD.log`、`summary-YYYYMMDD.tsv`、`last_run.json` | summary／last_run 自 feature/daily-summary 起 |
| 測試站 | https://books.ps194.net（協會測試機，Cloudflare Access 保護） | 先在這裡驗證再上正式站 |
| 程式備份＋每日修改紀錄 | 協會 NAS（位置見內部文件） | 修改紀錄只放 NAS、不進 git |
| 唯讀查詢 | 只有 SELECT 權限的資料庫帳號，經 SSH 轉接 | 數位同工查正式資料用，不能寫 |

## 二、每天自己跑的排程（主機 crontab，時間是 UTC）

| 時間（UTC） | 台北時間 | 指令 | 說明 |
| --- | --- | --- | --- |
| 每天 06:30 | 14:30 | `crawler/daily_new.sh` | 十七站新品 → 匯入 → 分類對映 → 分類回填 → 搜尋欄補值 → 年份守門 → 非書籍守門 → sitemap；週一加天道全掃與海天書樓、週三突破全掃。見 `deploy/cron-new-arrivals.md` |
| 每週二 22:00 | 週三 06:00 | `crawler/logos_cat_refresh.sh` | 基道全站分類對照重爬（約 6 小時），給週三 06:30 那輪 apply 用。見 `deploy/logos-categories.md` |

⚠️ 查「今天有沒有跑」時記得換算時區：台北下午兩點半之前，今天那一輪還沒開始。

## 三、發布流程

1. 在分支開發 → 測試站驗證 → 小布複測 → 熊哥確認
2. 有 migration：熊哥先用 Navicat 在正式庫跑 `database/migrations/` 的 SQL（冪等、會寫 `schema_migrations`）
3. 熊哥在主機 `cd /home/ubuntu/books && ./deploy/deploy.sh`（拉 main、逐項自我檢查，`/api/version` 要等於 `VERSION`）
4. `php tools/notify_discord.php --dry-run` 看公告內容 → 去掉 `--dry-run` 送出（內容在 `release-notes/vX.Y.Z.md`）
5. 收尾：NAS pull、`history/` 補記、Asana 結票附證據、GitHub 里程碑關閉

版本規則與每一版改了什麼看 `CHANGELOG.md`。

## 四、出事先看哪裡

| 症狀 | 先看 | 常見原因／處理 |
| --- | --- | --- |
| 新書沒進來 | 當天 `new-arrivals-*.log`（或 `summary-*.tsv` 的 fail 行） | 來源站改版（0 筆、HTTP 非 200）；單站失敗不影響其他站，修好重跑該站 |
| 新書搜不到 | `php tools/build_search_text.php --missing` | 手動匯入後忘了補搜尋欄（排程會自動補） |
| 年份篩不到某些書 | `php tools/check_year_fallback.php` | 有工具只寫了 `books.publish_date` |
| 非書籍上架 | `nonbook_guard_log` 表 | 守門規則在 `tools/lib_nonbook.php`；誤判改回 `is_published=1` |
| 封面破圖 | `covers_to_r2.php --dry-run --limit=20` | 來源網址 404（基道舊書很多）或只有根網址（以琳） |
| 部分請求 404、log 寫 `Primary script unknown` | `sudo systemctl reload php8.3-fpm` | 站點目錄被改名後 PHP realpath 快取 |
| 整站重建 | `deploy/rebuild-runbook.md` | 先確認 `crawler/data/` master jsonl 都在 |

## 五、還沒有的（待熊哥決定）

- **資料庫定期備份**：目前沒有排程，只有重建前手動 `mysqldump`。需要決定頻率、保留天數、放哪裡
- **每日摘要信／抓取失效警報**：資料已備（summary TSV、last_run.json），寄信等 SES 寄件／收件位址與 Region（Asana 1217767344711740）
- **憑證輪換**（資料庫、R2、Discord webhook）：沒有紀錄，需確認是否做過、多久輪一次
- **上線公告與推廣**（台灣聖經網、文協、合作出版社）：沒有紀錄
