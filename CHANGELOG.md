# 更新紀錄(CHANGELOG)

本檔記錄 books.zh.church(CCNDA 屬靈共同書目)每一版的重點變更。
版本號採語意化版本(SemVer):`主版本.次版本.修訂`。首頁 footer 顯示至次版本(如 `v1.0`)。

- **主版本**:重大架構或功能里程碑(例:正規化正式版、管理後台上線)
- **次版本**:新增功能(例:訂閱、進階篩選、使用者書庫)
- **修訂**:修 bug、資料修正、小調整

發布流程:更新 `VERSION` → 在本檔新增版本區塊 → commit → 建 git tag `vX.Y.Z` → FTP 部署 → 同步首頁 footer 版號。

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
