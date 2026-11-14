# M2-3 評分（五星）＋短評：資料表與 API 草稿

狀態：**草稿**（小芯 2026-11-15，Asana 1218125899210184）。還沒寫程式；先把資料形狀與規則定下來，等 M2-1 會員做好再實作。
依據：企劃書 20260711 第 77、124 行（五星＋70 字以上短評、每書一則、按讚實用度）。

## 0. 前提與相依

- **要先有 M2-1 會員**（Magic Link 登入，Asana 1218124736678630）：評分、短評、按讚都綁會員。本草稿假設會員表叫 `members(member_id, email, display_name, status, created_at)`，M2-1 定案後照實際名稱改。
- **不沿用現有 `reviews` 表**：那張是「外部書評／媒體引述」（source、quote、url，沒有會員欄位，見 docs/database-current.md 第 231 行），用途不同，混在一起會讓前台分不清「媒體說的」和「會員說的」。

## 1. 資料表

```sql
-- 會員短評（每位會員每本書一則；評分必填、短評必填且 ≥ 70 字）
CREATE TABLE member_reviews (
  review_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id      INT UNSIGNED NOT NULL,
  member_id    INT UNSIGNED NOT NULL,
  rating       TINYINT UNSIGNED NOT NULL,          -- 1～5，程式端檢查（MariaDB CHECK 也加）
  body         TEXT NOT NULL,                       -- 短評本文；字數在伺服器端用 mb_strlen 檢查
  status       ENUM('pending','published','hidden') NOT NULL DEFAULT 'published',  -- 見決定 ②
  helpful_cnt  INT UNSIGNED NOT NULL DEFAULT 0,     -- 冗餘計數，排序用；以 review_helpful 為準
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  hidden_by    VARCHAR(190) NULL, hidden_at DATETIME NULL, hidden_reason VARCHAR(255) NULL,  -- M2-4 後台審核用
  UNIQUE KEY uq_book_member (book_id, member_id),   -- 每書一則
  KEY idx_book_status (book_id, status, helpful_cnt),
  KEY idx_member (member_id),
  CONSTRAINT fk_mr_book   FOREIGN KEY (book_id)   REFERENCES books(book_id),
  CONSTRAINT fk_mr_member FOREIGN KEY (member_id) REFERENCES members(member_id),
  CONSTRAINT ck_mr_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 按「實用」：一人對一則一次；不能按自己的
CREATE TABLE review_helpful (
  review_id  INT UNSIGNED NOT NULL,
  member_id  INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (review_id, member_id),
  CONSTRAINT fk_rh_review FOREIGN KEY (review_id) REFERENCES member_reviews(review_id) ON DELETE CASCADE,
  CONSTRAINT fk_rh_member FOREIGN KEY (member_id) REFERENCES members(member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

書的平均分不另存表：查詢時 `AVG(rating), COUNT(*) WHERE status='published'`；書多了再加快取欄位（`books.rating_avg／rating_cnt`，寫短評時一併更新）。

## 2. 規則（伺服器端檢查，前端只做提示）

| 規則 | 做法 |
|---|---|
| 評分 1～5 | 整數；資料庫 CHECK 再擋一次 |
| 短評 ≥ 70 字 | `mb_strlen(trim(去掉連續空白後))`；上限 2000 字 |
| 每書一則 | UNIQUE (book_id, member_id)；已寫過就改成「編輯」 |
| 不能按自己的實用 | API 檢查 member_id ≠ 作者 |
| 平均分顯示門檻 | 公開短評 ≥ 3 則才顯示平均（避免 1 則就定調）——見決定 ③ |
| 輸入安全 | 全部預備語句；輸出 htmlspecialchars；不收 HTML |
| 限流 | 同一會員每小時最多新增／編輯 10 次、按實用 60 次 |

## 3. API

| 方法 | 路徑 | 誰能用 | 說明 |
|---|---|---|---|
| GET | `/api/books/{id}/reviews?sort=helpful\|new&page=N` | 所有人 | 只回 published；每頁 20；附平均分與則數 |
| GET | `/api/me/reviews` | 會員 | 自己寫過的（含 pending／hidden 狀態） |
| POST | `/api/books/{id}/reviews` | 會員 | `{rating, body}`；已有則回 409，前端改走 PUT |
| PUT | `/api/reviews/{rid}` | 作者本人 | 改評分／本文；hidden 的不能改 |
| DELETE | `/api/reviews/{rid}` | 作者本人 | 真刪（含按讚） |
| POST | `/api/reviews/{rid}/helpful` | 會員 | 切換（按／取消）；回最新 helpful_cnt |

寫入類全部要登入（M2-1 的 session）＋ CSRF token；錯誤回 `{error:{code,message}}`，訊息用中文。

## 4. 要熊叔叔決定的

1. **短評要不要先審後上**：(A) 直接公開、事後檢舉下架（status 預設 published）；(B) 內測期先審（預設 pending，M2-4 後台審）。草稿暫用 A。
2. **70 字怎麼算**：中英文都算 1 字、不算空白（草稿用這個）；或照「喵喵模型」另訂。
3. **平均分門檻**：≥ 3 則才顯示（草稿用這個），或 1 則就顯示。
4. **種子書評**：企劃書寫「種子書評 ≥ 50 則」——誰寫、用哪些帳號。

## 5. 測試要涵蓋

評分 0／6／小數被擋；69 字擋、70 字過（中英混合、全形空白）；同書第二則 409；按自己的實用被擋；別人的短評不能改刪；hidden 不出現在公開列表；平均分只算 published；限流第 11 次被擋。
