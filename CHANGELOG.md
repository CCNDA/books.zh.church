# 更新紀錄(CHANGELOG)

本檔記錄 books.zh.church(CCNDA 屬靈共同書目)每一版的重點變更。
版本號採語意化版本(SemVer):`主版本.次版本.修訂`。首頁 footer 顯示至次版本(如 `v1.0`)。

- **主版本**:重大架構或功能里程碑(例:正規化正式版、管理後台上線)
- **次版本**:新增功能(例:訂閱、進階篩選、使用者書庫)
- **修訂**:修 bug、資料修正、小調整

發布流程:更新 `VERSION` → 在本檔新增版本區塊 → commit → 建 git tag `vX.Y.Z` → FTP 部署 → 同步首頁 footer 版號。

---

## [1.2.1] - 2026-08-06 —— 天恩爬蟲解析修正(主機 probe 回饋)

### 修正
- 商品頁欄位比對改「標籤與值之間必須有分隔(空白/冒號)」:選單「作者列表」、描述「作者簡介」曾被誤抓成作者
- 頁籤面板不再靠 id 排除描述,改逐面板比對、取欄位命中最多者(描述面板的零星誤中不再蓋過「資訊」面板);無面板頁(試讀冊)退回整頁前先摘除頁首選單/頁尾
- 「裝訂方式」標籤修正(值不再殘留「方式」);電子書編號以 sku(eb 碼)為準,不被商品頁顯示的紙本編號覆蓋(頁面值另存 item_no_page)
- classify_categories.php 非書關鍵字新增「試讀冊/試讀本」:天恩 0 元試讀冊無分類、走關鍵字時一併下架

### 部署
- FTP 上傳 crawler/graceph_crawler.py、tools/classify_categories.php、index.html;主機重跑 --probe 確認後開全量

---

## [1.2.0] - 2026-08-06 —— 新增書目來源:天恩出版社(graceph.com)

### 新增
- 天恩出版社爬蟲 `crawler/graceph_crawler.py`:WooCommerce Store API 商品清單(日期倒序)+ 商品頁「資訊」頁籤書目欄位;全站抓入(約 1,626 件),快取續跑
- 每日新品:`new_arrivals.py --source grace`(Store API 日期倒序、無新品即停)、`daily_new.sh` 加入 grace 與天恩分類套用
- 分類雙軌(沿以琳做法):`subjects(scheme='grace')` 原樣存證 + `grace_category_map` 對映站內分類(migration `2026-08-06_grace_category_map.sql` + `tools/apply_grace_categories.php`)
- 電子書(8/6 決議):照書上架、與紙本同書合併(同名同作者即使 eISBN 不同也併為同一作品的版本);價格記 `media_type='ebook'`,購書連結標示「天恩出版社(電子書)」與紙本並列
- 非書(文創禮品/質選文創好物/專輯有聲/虛擬商品/年度日月曆):全站抓入存證,匯入後由對映表下架(命中任一即下架,留庫可還原)
- API 購書平台常數補 `elim`/`grace`(含電子書),排序:校園→基道→以琳→天恩→天恩電子書

### 部署
- Navicat 跑 `database/migrations/2026-08-06_grace_category_map.sql` → FTP 上傳 6 檔(graceph_crawler.py、new_arrivals.py、daily_new.sh、tools/import.php、tools/apply_grace_categories.php、api/index.php)→ 主機先 `--probe` 驗證解析,再全量抓取+匯入(見 crawler/README.md)

---

## [1.1.2] - 2026-07-30 —— 新品分類:標籤反查代碼

### 修正
- 校園新品無分類代碼時誤走關鍵字分類(如 74695「新約聖經(新譯本.聖經註釋版)」被摘要「福音派」誤歸福音,應為聖經)
- 分類器新增 `campus_code_by_label()`:新品只有 category_text 標籤時,以標籤反查整站爬取建立的 subjects 代碼對照(先精確、後大類前綴),照來源權威歸位;查無才走關鍵字

### 部署
- FTP 上傳 `tools/classify_categories.php` → 主機重分校園來源書:`php tools/classify_categories.php --campus-only --dry-run` 確認分布後正式跑(勿用 --all)

---

## [1.1.1] - 2026-07-30 —— 非書商品下架 + 購書連結排序

### 修正
- 月曆等紙品新品被關鍵字誤判分類(如 74697「2027主題月曆」誤歸見證)。決議:非書商品(紙品/禮品)一律下架,沿 7/15 原則,不新增分類
- 分類器(tools/classify_categories.php):分類前先比對「校園來源標籤(紙品/禮品/文具)+ 書名(月曆/桌曆/賀卡/金句卡/書籤/拼圖…)」,命中即 is_published=0 並列入報表下架清單;每日新品 cron 既有流程自動生效
- API 購書連結排序:歷史資料 links.platform 存中文名(校園書房/基道 BookFinder),排序常數補中文鍵,確保校園→基道→其他順序

### 部署
- FTP 上傳 `api/index.php`、`tools/classify_categories.php`;既有已上架紙品以 SQL 下架(見 history/2026-07-30.md,先 SELECT 複核再 UPDATE);無 migration

---

## [1.1.0] - 2026-07-30 —— 簡繁體支援 + 多來源購書連結

華福大會後首次功能更新(M1 跟進)。

### 新增
- **簡繁體切換**:全站(首頁/詳情頁/關於本站)支援簡體中文顯示
  - 依瀏覽器語言自動偵測(zh-CN/SG/MY 預設簡體),頁首「简体/繁體」鈕手動切換,選擇記在瀏覽器
  - 顯示層即時轉換(OpenCC),資料一律以繁體儲存不變動;簡體關鍵字搜尋自動轉繁體查詢
  - 轉換器優先讀自站 `/assets/vendor/opencc-full.js`,失敗退 CDN;皆失敗維持繁體
- **多來源購書連結**:同書在校園書房、基道皆有上架時,詳情頁購書按鈕並列所有來源
  - API `/api/books/{id}` 彙整 links(link_type='buy')與 books.buy_links,URL 去重、平台固定排序
  - 統一格式 `{platform, label, url, note}`;未來新增來源只需擴充 API 平台常數,前端不需改
  - 延伸連結區塊不再重複顯示購書連結

### 部署
- 新檔:`assets/lang.js`;需另下載 opencc-js full.js 放 `assets/vendor/opencc-full.js`(見部署清單)
- 更新:`index.html`、`book.php`、`about.html`、`api/index.php`;無資料庫異動

---

## [1.0.0] - 2026-07-18 —— 華福大會 MVP

首個公開版本,於 2026 華福大會(7/20)展示。

### 功能
- 書籍完整資訊、封面(Cloudflare R2)、簡介
- 關鍵字搜尋:書名 / 作者 / 摘要 / 出版社 / ISBN / Tag
- 分類瀏覽(校園 12 類 + CategoryV11 擴充類)
- 書籍詳情頁:購書連結、推薦連結(YouTube/Blog/Podcast)
- 每書獨立短網址
- 開放 API(`/api/books`)
- Email 訂閱書訊(AWS SES)

### 資料
- 收錄校園書房 + 基道兩大書房,近 3 萬本上架書
- 跨站同 ISBN13 版本合併(雙幣價)
- 每日自動抓兩站新書入庫(cron 06:30)
- 基道官方分類套用:全站上架書 100% 有分類(NULL 歸零),官網原生階層分類存證

### 技術
- PHP 8.3 + MariaDB(utf8mb4),前後端分離 REST API
- 主機 Ubuntu + nginx + PHP-FPM,手動 FTP 部署
- 正式站 https://books.zh.church(HTTPS)

---

<!-- 後續版本(華福會後跟進)在此上方新增區塊 -->
