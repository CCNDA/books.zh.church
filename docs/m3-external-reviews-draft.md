# M3 收錄各地讀書心得與影片：規格草稿

狀態：**草稿**（小芯 2026-11-26，Asana 1220695769004271）。還沒寫程式；M2 上線後開工。
依據：熊叔叔 2026-11-19 TG 三題定案——

1. 收錄方式：**只放標題＋來源＋連結＋我們自己寫的一兩句簡介**；影片用 YouTube 官方嵌入；**不摘錄原文**
2. 來源：**自動搜尋＋人工審核**（YouTube 官方 API；部落格用搜尋 API、守各家規定、放慢）；找到的先進後台待審，確認是同一本書才上
3. 排程：另開 M3，M2 上線後再做；審核用 M2-4 後台

## 0. 前提與相依

- **M2-4 後台審核頁**要先有（列表、通過／退回、記錄經手人）。
- **沿用現有 `reviews` 表**（不另開新表）：它本來就是「外部書評／媒體引述」用的（source、url…，沒有會員欄位，見 docs/database-current.md），目前 **0 列**，用途跟 M3 一樣。M2-3 會員短評另開 `member_reviews`，兩者不混。
- `reviews.quote`、`reviews.content` 依定案 1 **不放原文**：quote 一律 NULL；content 改放「我們自己寫的簡介」。

## 1. 資料表：擴充 `reviews`

```sql
-- 冪等：欄位已在就不加（實作時逐欄判斷 information_schema）
ALTER TABLE reviews
  ADD COLUMN kind         ENUM('blog','video','media') NOT NULL DEFAULT 'media' AFTER book_id,
  ADD COLUMN title        VARCHAR(300) NULL AFTER kind,          -- 原文標題（標題不算摘錄）
  ADD COLUMN author_name  VARCHAR(190) NULL AFTER source,        -- 部落格作者／YouTube 頻道名
  ADD COLUMN video_id     VARCHAR(20)  NULL AFTER url,           -- YouTube 影片 ID（嵌入用）
  ADD COLUMN published_on DATE         NULL,                     -- 原文／影片發布日
  ADD COLUMN status       ENUM('pending','published','rejected') NOT NULL DEFAULT 'pending',
  ADD COLUMN found_by     VARCHAR(30)  NULL,                     -- youtube_api／search_api／manual
  ADD COLUMN match_score  TINYINT UNSIGNED NULL,                 -- 同書比對分數 0～100（第 3 節）
  ADD COLUMN reviewed_by  VARCHAR(190) NULL, ADD COLUMN reviewed_at DATETIME NULL,
  ADD COLUMN reject_reason VARCHAR(255) NULL,
  ADD UNIQUE KEY uq_book_url (book_id, url(191)),                -- 同一本書同一網址只收一次
  ADD KEY idx_status_created (status, created_at);
```

- 前台只顯示 `status='published'`。
- 通過時 `content`（我們的簡介）必填、1～2 句（伺服器端檢查 20～120 字）。

## 2. 搜尋來源與配額

| 來源 | 用法 | 配額／成本 | 禮貌 |
|---|---|---|---|
| YouTube Data API v3 | `search.list`（q＝書名＋作者，type=video，regionCode=TW）→ `videos.list` 補頻道、發布日、長度 | 每天 10,000 單位；search 100、videos 1 → **一天最多約 90 本** | 官方 API，不爬網頁 |
| Google 可程式搜尋（JSON API） | q＝「書名」＋作者＋（心得 OR 讀後感 OR 書評） | 免費每天 100 次 | 只取搜尋結果的標題、網址；**不抓原文頁面** |

- 每天一批、放在每日排程最後；**一天搜尋本數**要熊叔叔定（見第 6 節 ②）。
- 排序：先搜「新書」「點擊多」「有 ISBN」的書；同一本書 90 天內不重搜。

## 3. 同書比對規則（決定進不進待審）

給分（0～100），**≥ 60 才進待審**；**永遠不自動上架**：

- 標題或說明含完整書名（去標點、繁簡互轉後）＋40
- 含作者名（任一作者／譯者）＋30
- 說明含 ISBN ＋50（直接過門檻）
- 書名太短（≤ 3 字，如「禱告」）另需作者名同時命中，不然不收
- 扣分：標題含「試讀」「團購」「代購」「二手」－40；影片少於 60 秒－30

## 4. 後台審核流程（M2-4）

1. 待審列表：左邊我們的書（封面、書名、作者），右邊候選（標題、來源、作者／頻道、發布日、分數；影片可點開預覽）
2. **通過**：要寫 1～2 句簡介（我們自己的話）→ status=published、記 reviewed_by／at
3. **退回**：選原因（不是同一本、內容不適合、重複、其他）→ rejected；同網址以後不再收
4. 已上架的可以下架（改回 rejected＋原因），供作者要求移除時用

## 5. 前台顯示

- 書目頁新增「讀書心得與影片」區：每則顯示標題（連到原文）、來源／作者、發布日、我們的簡介
- 影片：先顯示縮圖＋播放鈕，點了才載入 `https://www.youtube-nocookie.com/embed/{video_id}`（省流量、不先送追蹤）
- **CSP 要加** `frame-src https://www.youtube-nocookie.com`、`img-src https://i.ytimg.com`（上線前清單第 5 條要回讀）
- 外部連結加 `rel="noopener nofollow ugc"`

## 6. 待熊叔叔決定

1. 沿用 `reviews` 表擴充（建議）／另開新表
2. 一天搜尋幾本：建議先 **30 本**（YouTube 約 3,000 單位、搜尋 API 30 次），審核量一天約 5～15 則
3. YouTube API 金鑰用協會哪個 Google 帳號／專案（金鑰走 NAS 交付，不走 TG）
4. 部落格名稱要不要顯示（建議顯示：給原作者應有的出處）
5. 被作者要求移除時的聯絡窗口（建議用網站「聯絡我們」）

## 7. 工作拆解（M2 上線後）

| 步驟 | 內容 | 估時 |
|---|---|---|
| 1 | migration（擴充 reviews，冪等）＋開發庫試跑 | 0.5 天 |
| 2 | `tools/find_external_reviews.php`：兩個 API、比對給分、寫 pending；配額記錄 | 1.5 天 |
| 3 | M2-4 後台審核頁（列表、通過要寫簡介、退回原因、下架） | 1 天 |
| 4 | 書目頁顯示區＋影片延遲載入＋CSP | 1 天 |
| 5 | 每日排程掛上、測試（比對規則單元測試、陰性對照）、兩面審查、小布複測 | 1 天 |
