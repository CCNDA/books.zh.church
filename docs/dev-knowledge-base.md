# 屬靈共同書目 — 開發先備知識與環境設定

建立日期：2026-07-11(依 docs/ 全部文件整理)

## 1. 專案定位

CCNDA「基督徒圖書分享服務(屬靈共同書目)」:以開放資料庫為核心的華文基督教書籍交流平台(希伯來書 5:12-14)。參考 Goodreads。長遠願景(2026-01-30 會議):從「整全的基督教記錄庫」提升為「華文基督徒共同知識庫」,支援 AI 應用;內容治理採「標籤化」而非裁決(出版社宗派背景、爭議性提示、神學脈絡標籤),資料來源透明、使用者自決。

## 2. 時程與 Asana 對應(專案:屬靈書目網站開發 GID 1216467419023584)

| 節點 | 期限 | 內容 |
|---|---|---|
| 【衝刺】華福大會 MVP | 7/16 | 書目瀏覽/分類、搜尋、詳情頁、行動適配、簡介頁、部署 |
| 檢核點 0:展示版上線 | 7/17 | 網站可瀏覽搜尋、首批書目上線、正式網址 |
| 階段一 初期規劃 / 檢核點 1 | 7/31 | 大會後檢討、專案章程 |
| 階段二 需求與設計 / 檢核點 2 | 8/21 | 欄位分類架構、規格、線框、視覺定稿 |
| 階段三 開發建置完整版 / 檢核點 3 | 9/18 | 完整匯入、進階搜尋、管理後台(API Key 驗證) |
| 階段四 測試驗收 / 檢核點 4 | 10/2 | 功能測試、資料校對、UAT |
| 階段五 上線維運 / 檢核點 5 | 10/9 | 部署、公告、維運交接 |

MVP 不做:會員系統、管理後台、留言/推薦、多語系、進階篩選。

## 3. 第一階段功能範圍(2025 企劃書)

書籍完整資訊、關鍵字搜尋(書名/作者/摘要/出版社/ISBN/Tag)、購書連結、書籍推薦連結(YouTube/Blog/Podcast)、Email 訂閱書訊、開放 API、每書獨立短網址、出版社資料匯入、網友建檔補充。
後續階段:使用者書庫、閱讀狀態、短評論(70 字以上+讚)、讀書會連結、獎勵制度(靈糧點數)、台灣聖經網會員整合(4.5 萬會員)。

## 4. 技術環境設定

- PHP 8 + MariaDB(utf8mb4),REST API 前後端分離;cPanel/Apache 虛擬主機;無 Node.js
- 目錄:`web/`(前端+router.php)、`api/`、`config/`、`database/migrations/`
- 設定集中 `config/app.local.php`(gitignore;範本 `config/app.example.php`),含 DB、APP_URL、AWS SES
- DB 環境變數:DB_HOST/PORT/NAME/USER/PASS;migrations 001 books、002 users、003 magic_links
- 認證:Magic Link 無密碼登入;寫入端點需 Bearer Token(auth/verify 取得);書目 API 寫入需 X-Api-Key
- 郵件:AWS SES SMTP 587+STARTTLS,寄件者 support@ccnda.org;需 composer aws/aws-sdk-php(走 SMTP 則免 API Key)
- 本機啟動:`php -S 0.0.0.0:8080 web/router.php`
- 規範:PDO prepared statements、輸出 HTML escape、回應 `{"data":...}`/`{"error":...}` + JSON_UNESCAPED_UNICODE、介面繁體中文

## 5. 資料庫架構(兩層)

MVP:books + categories 兩表。
正規化版(Work/Edition 模型,見 database_schema.svg 與整合模板 xlsx 15 工作表):
books(作品:title_zh/en、subtitle、original_title、work_type、language、summary、toc) → editions(1:N,FK publisher_id、edition_statement、publish_date、page_count、binding) → identifiers(ISBN13/10/條碼)、formats_prices;關聯表 book_persons(persons,role 多作者/譯者/序/插畫)、book_series(series)、book_subjects(subjects,可混用分類系統含 CategoryV11);links(購書/外部連結,book 或 edition 層)、media(封面,落地儲存+fallback)、reviews。
遷移原則:從簡化版逐步遷移,不可破壞既有資料。

## 6. 資料來源與參考檔

- `CategoryV11.xls`:自訂分類 757 列(區類/編號/類別中英/次分類 1-4)
- `出版物資料庫.xlsx`:出版社實際庫(產品/供應商/出版商/作譯序/系列/版/釘裝/SalesLink 等 18 表)
- `差異自動比對_欄位對照表.xlsx`:書目欄位 ↔ 出版物資料庫欄位映射、清理規則、合併優先序
- `整合_完整書籍資料庫模板.xlsx`:正規化 14 表 + 快速匯入表(匯入格式標準)
- `A20210304校園圖書目錄.xls`:校園出版社目錄(1600+ 列,含代理)
- `書籍資料.docx`:完整欄位需求(基本資訊/識別碼/分類/內容/規格/流通/外部資源)

## 7. 已完成進度(progress-summary.md, 2026-03-28 註記)

前台書單/搜尋/獨立頁;後台 admin.php + admin_book.php 雙模式;Magic Link 登入;SES 郵件;books/users/magic_links + 正規化表;博客來/校園書房/基道網址抓取預填;封面落地儲存。
已知風險:外站 WAF/CDN 403、來源欄位格式差異大。
目前重點:欄位映射精度、匯入失敗人工備援、進階編輯對關聯資料的更新。

## 8. 開發必備技能清單

1. PHP 8(strict_types,注意 UTF-8 BOM 問題)+ PDO/MariaDB
2. REST API 設計與 Token/API Key 驗證
3. 前端 HTML/JS(呼叫 API 渲染)+ RWD 行動適配
4. MySQL 正規化設計與資料遷移(Work/Edition 模型)
5. 網頁抓取解析(博客來/校園書房/基道;403 備援策略)
6. Excel/CSV 資料清理匯入(openpyxl 等;對照表映射規則)
7. AWS SES(SMTP/SDK)郵件整合
8. cPanel/Apache 虛擬主機部署(.htaccess routing)
9. Asana 專案管理(所有工作記錄於「屬靈書目網站開發」專案)

## 9. 工作規則

- 修改程式給完整檔案或明確 diff,附部署注意事項
- 新功能先確認階段歸屬,不提前實作
- 所有開發工作須在 Asana 專案留下紀錄(任務/子任務/留言)
