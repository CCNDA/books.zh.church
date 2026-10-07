# 屬靈共同書目 — 現行資料庫 Schema(books.zh.church)

**產生日期**:2026-09-16
**來源**:熊哥以 Navicat 對正式庫執行六段 `information_schema` 盤點,結果貼回後據以產生。
**對應 Asana**:票 1217927332453913

> ⚠️ 本文件過期就沒有價值。**改 schema = 同時更新本檔 + 在 migration 結尾 INSERT 一列 `schema_migrations`**。

---

## 〇、版本與環境(全部實查)

| 項目 | 實查值 |
|---|---|
| DB 引擎 | **10.11.13-MariaDB-0ubuntu0.24.04.1** |
| 資料庫名 | `books` |
| 字元集 | utf8mb4 |
| **資料庫預設排序規則** | **`utf8mb4_general_ci`** |
| **所有表的排序規則** | **`utf8mb4_unicode_ci`** |
| 時區 | `SYSTEM` / 系統 `UTC` |
| 儲存引擎 | 全部 InnoDB |
| 物件數 | 32 張表 + 1 個 VIEW |
| schema 版本追蹤 | `2026-09-16_schema_migrations.sql` 已備(待執行) |

### ★★ 陷阱一:資料庫預設 collation 與表的 collation 不一致

**資料庫預設是 `utf8mb4_general_ci`,但既有 32 張表全部是 `utf8mb4_unicode_ci`。**

⇒ **新建表若不明寫 `COLLATE`,會拿到 `general_ci`**,與既有表不同。
　 之後任何跨表字串 JOIN 都會噴 `Illegal mix of collations`。

**規則:建表一律明寫 `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`。**

補充:`utf8mb4_unicode_ci` 不分大小寫與全半形,**但不把簡繁視為相等**
→ 對映表的簡繁兩種寫法要各自登錄一列,且一律不做字形轉換。
需要精確比對位元組時用 `COLLATE utf8mb4_bin`。

### ★★ 陷阱二:時區是 UTC

`created_at` / `updated_at` / `clicked_at` 存的是 **UTC**,而台灣是 UTC+8。
每日新品 cron 在 06:30(主機本地)執行 → **「今日新增」這類查詢要自己處理 8 小時位移**,
不能直接拿 `CURDATE()` 比。

### ★★ 陷阱三:統計資訊已過期,會影響查詢計畫

| 項目 | `TABLE_ROWS`(估計) | 實查 `COUNT(*)` |
|---|---|---|
| `books` | **45,924** | **65,290** |

差 19,366。`TABLE_ROWS` 是 InnoDB 的估計值,索引的 `CARDINALITY` 同樣停在 45,924。

⇒ **最佳化器是拿舊統計在選索引**,這會直接影響 M1-B 的搜尋效能評估。
**先跑 `ANALYZE TABLE` 再量效能**,否則量到的是假的:

```sql
ANALYZE TABLE books, editions, identifiers, book_subjects, book_persons,
              formats_prices, links, media, persons, publishers, subjects;
```

★ **對外數字一律用 `COUNT(*)`,絕不用 `TABLE_ROWS`。**

---

## 一、架構總覽

Work / Edition 正規化。**作品層(`books`)存書名與彙總欄位,版本層(`editions`)存版本與來源。**

```
books(作品)45,924*
 ├─ editions(版本;source / source_url 在這裡)96,916*
 │   ├─ identifiers(ISBN13/ISBN10/STORE…)153,690*
 │   ├─ formats_prices(型態/價格/幣別/庫存)77,677*
 │   ├─ links(可掛 book 或 edition)94,460*
 │   └─ media(可掛 book 或 edition)95,434*
 ├─ book_persons 76,053* → persons 36,644*
 ├─ book_series 0 → series 0            ← ★ 從未使用
 ├─ book_subjects 244,480* → subjects 1,431*
 ├─ categories 25
 ├─ reviews 0                            ← 預留
 └─ book_clicks 748*

publishers 4,974*(canonical_id 自我參照 441 筆別名)
 └─ publisher_org_links 0                ← ★ 整合預留,啟用前為空

{source}_category_map × 15 + btproduct_book_override
```

\* 為 `TABLE_ROWS` 估計值,見陷阱三。

### 四個必記的結構事實

1. **商品代碼不在 `books`。** 店內碼存成 `identifiers`(`id_type='STORE'`,掛 `edition_id`),
   用商品代碼篩書必須三表 join。
2. **`categories` 是 CategoryV11**,但**站內只建了 25 列**(`docs/CategoryV11.xls` 有 757 項)
   → 對映表的 `internal_name` 只能落在這 25 個名稱之內。
3. **`editions` 沒有 `title`、也沒有 ISBN 欄。** 版本的書名不存在於資料庫,ISBN 在 `identifiers`。
   ★★ **推論:誤併無法從資料庫內部判斷。** edition 被併進錯的作品後身上只剩
   `source` 與 `source_url`,要知道它原本是哪本書**只能回源站打開那個網址**。
4. **購書連結在兩處**:`links`(`link_type='buy'`)與 `books.buy_links`(平面後備)。
   API 以 URL 去重且 links 優先 → 顯示名稱只認 `links.platform`。

---

## 二、作品與版本

### `books`(27 欄)

| # | 欄位 | 型別 | NULL | 索引 | 說明 |
|---|---|---|---|---|---|
| 1 | book_id | int unsigned | NO | PRI | |
| 2 | title | varchar(255) | NO | `idx_title` | 書名 |
| 3 | subtitle | varchar(255) | YES | | |
| 4 | original_title | varchar(255) | YES | | 原文書名 |
| 5 | author | varchar(255) | YES | `idx_author` | 作者(多人以、分隔) |
| 6 | translator | varchar(255) | YES | | 譯者 |
| 7 | editors | varchar(255) | YES | | 編者/主編(; 分隔) |
| 8 | publisher | varchar(100) | YES | `idx_publisher` | 出版社(平面欄,與 `publishers` 並存) |
| 9 | publish_date | varchar(10) | YES | **無** | YYYY-MM 或 YYYY |
| 10 | edition_statement | varchar(100) | YES | | |
| 11 | series | varchar(255) | YES | | 系列(`名稱#冊次`;可多筆) |
| 12 | page_count | int unsigned | YES | | ★ 多值頁數(「864+696」)不入此欄 |
| 13 | binding | varchar(50) | YES | | |
| 14 | language | varchar(50) | YES | | 語言(; 分隔) |
| 15 | isbn13 | varchar(13) | YES | `idx_isbn13` | |
| 16 | isbn10 | varchar(10) | YES | | |
| 17 | category_id | int unsigned | YES | `idx_category` + FK | → `categories` |
| 18 | summary | text | YES | | 簡介 |
| 19 | summary_short | varchar(500) | YES | | 短書介 |
| 20 | keywords | varchar(500) | YES | | 關鍵字(; 分隔) |
| 21 | cover_url | varchar(500) | YES | | |
| 22 | buy_links | mediumtext | YES | | JSON(2026-08-18 TEXT→MEDIUMTEXT) |
| 23 | extra | mediumtext | YES | | JSON,**依來源分鍵**;合併過的副檔存 `{source}_dup{舊id}` |
| 24 | source | varchar(50) | YES | **無** | |
| 25 | is_published | tinyint(1) | NO(1) | **無** | **下架設 0,不刪資料** |
| 26 | created_at | timestamp | NO | | UTC |
| 27 | updated_at | timestamp | NO | | UTC |

★ `JSON_KEYS(extra)` 可用來檢查一本書有沒有合併痕跡(找 `*_dup*` 鍵)。

### `editions`(14 欄)

`edition_id` PRI / `book_id`(`idx_book` + FK)/ `publisher_id`(**兩個重複索引** + FK)/
`edition_statement` / `publish_date` varchar(10) / `place_of_publication` varchar(100) /
`print_run` varchar(50) / `page_count` / `binding` / `dimensions` varchar(50) / `weight_g` /
`copyright` varchar(255) / **`source` varchar(50)(無索引)** / `source_url` varchar(1000)
(2026-08-23 由 500 加寬)

**正確的量測查法:**
```sql
SELECT e.source, COUNT(DISTINCT e.book_id) FROM editions e GROUP BY e.source; -- 各站種數(不可相加)
SELECT COUNT(*) FROM books;                        -- 全站書目種數
SELECT COUNT(*) FROM books WHERE is_published = 1; -- 已上架種數
```

---

## 三、識別碼、價格、連結、封面

- **`identifiers`**:`id_type` varchar(10)(ISBN13/ISBN10/EAN/UPC/ISSN/DOI,實務另有 **STORE**)/
  `id_value` varchar(30)。索引:`idx_value(id_value)`、`uq_type_value_edition(edition_id, id_type, id_value)` UNIQUE。
- **`formats_prices`**:`media_type`(print/ebook/audio)/ `file_format` / `sku` / `price` decimal(10,2) /
  **`currency` varchar(5)**(TWD/HKD/USD)/ `availability`。
  ★ 新來源要在 `tools/import.php` 的 `SOURCE_CURRENCY` 登錄,**漏登靜默落回 TWD**。
- **`links`**:`link_type`(buy/official/ebook/audio/intro/video/social)/ `platform` varchar(50) /
  `url` varchar(1000)。`book_id` 與 `edition_id` 皆可空且**都有外鍵**(可掛作品或版本層)。
  ★ 簡繁併本的連結以 `platform` 加註「(正體)/(簡體)」→ **不可用 `REPLACE()` 批次修**,
  同一本書底下兩條連結會一起改錯,要按 edition 逐條重建。
- **`media`**:`media_type`(cover/inside/promo)/ `url_or_path` varchar(700) / `is_primary`。
  封面存 R2:bucket `oursweb`、prefix `books/`、公開網址 `https://imgr2.oursweb.net/books/{檔名}`。

---

## 四、人、系列、分類

- **`persons`**(36,644*):`name` varchar(150) + `idx_name` / `name_en` / **`aka` varchar(255)**
  (別名/常用譯名,; 分隔)/ `bio` / `website`
  ★ **`aka` 已存在** → 異體字同一人(托馬斯/託馬斯)可直接用它收斂,不需新增表。
- **`book_persons`**(76,053*):`role`(author/editor/translator/illustrator/foreword/advisor/
  proofreader/contributor)/ `role_order` / **`credit_text`**(封面署名原文,保留排版與全形)。
  UNIQUE `uq_book_person_role(book_id, person_id, role)`。
- **`series` / `book_series`:兩張表都是 0 列。**
  ⚠️ **從未被使用。** 書系資料目前只在 `books.series` 平面欄。
  ★ 但 `akow_category_map` 的表註解寫著「書系一律 NULL 改寫 series」
  → **v1.13.0 說要把五個書系寫進 `series` 表,實際沒寫入。待查 `books.series` 有無值。**
- **`categories`**(25 列):`code` varchar(10) UNIQUE(CategoryV11 編號)/ `name` varchar(100)。
- **`subjects`**(1,431*):`scheme` varchar(20)(categoryv11/campus/logos/ddc/custom)/
  **`code` varchar(40)**(2026-08-25 由 20 加寬)/ `label`。UNIQUE `uq_scheme_code_label`。
- **`book_subjects`**(244,480*,全庫最大表):UNIQUE `uq_book_subject(book_id, subject_id)`。

---

## 五、出版社與機構名錄

### `publishers`(4,974*)

`publisher_id` PRI / `name_zh` varchar(150) **UNIQUE** / `name_en` /
**`canonical_id`**(`idx_pub_canonical`;非 NULL 表本列為別名,指向正規列)/
`org_ref_id` / `website` / `contact`

**canonical_id 現況(實查 2026-09-16):總 5,004 列中 441 列已登錄別名。**

已歸一的例子品質良好:天道 → 天道書樓、學園/學園傳道會/中國學園傳道會出版部 → 中國學園傳道會、
三民/三民出版社 → 三民書局、上智文化/上智文化事業 → 上智。

★ 別名清單也暴露了來源端雜訊已被收攏:「SAM-S013」→ SAM、「亞洲歸主協會-Y002」→ 亞洲歸主
(商品碼混進出版社名)、「中國主日學(其它)」「人光其它」(來源分類殘留後綴)。

**尚未歸一(待裁示):**

| 組 | 狀態 | 判斷 |
|---|---|---|
| 「其他」(5326) / 「其它」(2298) | 兩者 canonical_id 皆 NULL | **同義,應歸一** |
| 「橄欖」(2755) / 「橄欖華宣」(4389) | 兩者皆 NULL | 待確認是否同一實體 |
| 「道聲」(2810) / 「香港道聲」(2371) | 兩者皆 NULL | **不同出版社,維持分開正確** |

★ 出版社頁(M1-B)必須跟隨 `canonical_id`,否則同一家會裂成兩頁。

### `publisher_org_links`(12 欄,**0 列**)

出版社 ↔ 教會機構名錄 ↔ 宗派。表註解:「整合預留,**啟用前為空**」。
欄位:`directory_system`(預設 `'ccnda_church'`)/ `directory_oid` / `org_pid` / `org_pname` /
`matched_name` / **`match_status`**(unconfirmed/confirmed/rejected)/ `matched_at`

⚠️ **這是空表。** 「出版社宗派背景標籤」的結構已備、**資料還沒有**
→ M1-B 出版社頁目前**不能**顯示宗派,除非先做名錄比對。

---

## 六、評論與統計

### `reviews`(8 欄,**0 列**)
`source`(媒體/平台/個人)/ `rating` decimal(3,1) / `quote` varchar(500) / `content` / `url`

⚠️ **沒有 `user_id`** → 現行是「外部書評/媒體引述」用的。
**M2-3 會員評分短評不能直接沿用**,需新增欄位或關聯表。這點要寫進 M2-3 票。

### `book_clicks`(5 欄,**748 列**)
`book_id`(`idx_book`)/ `clicked_at`(`idx_month_book(clicked_at, book_id)`)/
`source` 預設 `'list'` / **`q` varchar(200)**(搜尋關鍵字)

⚠️ **只有 748 列。** 結構支援熱門排行與搜尋詞分析,但**樣本太小,還不足以做排行**。
M1-B 的「排序與排行」要嘛先靠新書排序,要嘛等點擊累積。

---

## 七、來源分類對映表(15 張 + 1 張單書覆蓋)

慣用結構:`{前綴}_code`(PRI,= `subjects.code`)/ `{前綴}_name`(僅供人讀)/
`internal_name`(→ `categories.name`;**NULL = 僅存證不歸類**)/ `unpublish`(1 = 非書,
該來源獨有書命中即下架)/ `sort_order`(預設 500,小者優先)/ `note`

| 表 | 列數 | 鍵型別 | 鍵內容 | unpublish | sort_order | internal_name 可 NULL |
|---|---|---|---|---|---|---|
| `akow_category_map` | 46 | varchar(40) | 數字=Woo 分類 id;`sub:xxx`=站方細分類 | ✓ | ✓(細分類 110) | ✓ |
| `btproduct_category_map` | 43 | varchar(20) | cat 6 位數字 / `S+數字` | ✓ | ✓ | ✓ |
| `cclm_category_map` | 100 | varchar(20) | 中文 slug 或 `pc{id}` | ✓ | ✓ | ✓ |
| `cosmiccare_category_map` | 51 | varchar(20) | 「大類/子類」 | ✓ | ✓ | ✓ |
| `elim_category_map` | 35 | varchar(60) | 分類名稱路徑(父/子) | ✓ | **✗** | **✗** |
| `grace_category_map` | 25 | varchar(60) | 分類名稱(平面) | ✓ | ✓ | **✗** |
| `logos_category_map` | **16** | varchar(40) | `logos_top` | **✗** | **✗** | **✗** |
| `methodist_category_map` | 82 | varchar(20) | slug 末段或 path id | ✓ | ✓ | ✓ |
| `mezu_category_map` | 119 | varchar(20) | collection handle | ✓ | ✓ | ✓ |
| `osb_category_map` | 62 | varchar(20) | collection handle | ✓ | ✓ | ✓ |
| `pctpress_category_map` | 128 | **int** | Woo 分類 id | ✓ | ✓ | ✓ |
| `taosheng_category_map` | 67 | varchar(20) | collection handle | ✓ | ✓ | ✓ |
| `tiendao_category_map` | 95 | varchar(20) | 平台數字 path | ✓ | ✓ | ✓ |
| `twgbr_category_map` | 51 | varchar(40) | 分類 handle | ✓ | ✓ | ✓ |
| `wdbook_category_map` | 110 | varchar(20) | 微讀分類 id | ✓ | ✓ | **✗** |

`btproduct_book_override`(19 列):單書層級分類覆蓋與下架,突破機構專用。

### ★ 四個不一致(建議列入待辦)

1. **`campus` 沒有對映表。** 16 個來源只有 15 張,且 migration 清單裡也沒有
   → 證實不是漏看。校園分類可能直接寫進 `subjects`,**機制待確認**。
2. **`logos_category_map` 只有 3 欄 16 列**,缺 `unpublish` 與 `sort_order`
   → 基道**無法自動下架非書、無法設分類優先序**,與其他 14 站行為不一致。
   可能是基道分類票(1216651317073747)的隱含缺口。
3. **四張早期表的 `internal_name` 是 NOT NULL**(elim / grace / logos / wdbook)
   → 不支援「僅存證不歸類」語意。
4. ★★ **對映表的 `internal_name` 沒有外鍵**(字串對 `categories.name`)
   → **這就是「填錯不報錯、只靜默不歸類」的結構原因。**
   因此每次產對映 SQL 一律要附驗證查詢,應回 0 列:
   ```sql
   SELECT DISTINCT m.internal_name AS 對不到的分類名
     FROM {source}_category_map m
     LEFT JOIN categories c ON c.name = m.internal_name
    WHERE m.internal_name IS NOT NULL AND c.category_id IS NULL;
   ```

### 鍵長度截斷風險
多張表鍵為 `varchar(20)` 且註明「截 20 字」。**slug 截斷會撞碼**(教會公報社已發生)
→ 新來源優先用平台數字 id 當鍵;必須用 slug 時鍵長比照 akow/twgbr 的 `varchar(40)`。

---

## 八、外鍵(16 個,全部實查)

| 表 | 約束名 | 欄位 | → 參照 |
|---|---|---|---|
| books | fk_books_category | category_id | categories.category_id |
| book_clicks | fk_clicks_book | book_id | books.book_id |
| book_persons | fk_bp_book / fk_bp_person | book_id / person_id | books / persons |
| book_series | fk_bs_book / fk_bs_series | book_id / series_id | books / series |
| book_subjects | fk_bsub_book / fk_bsub_subject | book_id / subject_id | books / subjects |
| editions | fk_ed_book / fk_ed_pub | book_id / publisher_id | books / publishers |
| formats_prices | fk_fp_edition | edition_id | editions.edition_id |
| identifiers | fk_id_edition | edition_id | editions.edition_id |
| links | fk_lk_book / fk_lk_edition | book_id / edition_id | books / editions |
| media | fk_md_book / fk_md_edition | book_id / edition_id | books / editions |
| reviews | fk_rv_book | book_id | books.book_id |

**沒有外鍵的地方**:全部 15 張對映表(見第七節第 4 點)、`publisher_org_links`、
`publishers.canonical_id`(自我參照,只有索引)。

---

## 九、★ M1-B 前置:索引缺口與冗餘

檢核點 2 要「六種搜尋皆可用」與「搜尋效能(索引/FULLTEXT)」。現況:

### 已有索引
`books`:`idx_title`、`idx_author`、`idx_publisher`、`idx_category`、`idx_isbn13`(單欄 BTREE)、
`idx_publish_date`(2026-09-16 加)、**`idx_pub_created (is_published, created_at, book_id)`(2026-09-19 加)**
`editions`:`idx_ed_book_source (book_id, source)`、`idx_ed_book_pubdate (book_id, publish_date)`(皆 2026-09-16 加)

### 缺口現況(2026-09-19 更新)

| 搜尋維度 | 索引狀態 |
|---|---|
| 關鍵字(書名/作者/摘要) | **全庫沒有任何 FULLTEXT 索引**(刻意:MariaDB 無 ngram,中文無分詞)→ 改走 `book_search` 瘦表單欄 LIKE,13.5 MB 全掃約 150 ms |
| 年份 | ✅ `books.idx_publish_date` + `editions.idx_ed_book_pubdate` |
| 來源 | ✅ `editions.idx_ed_book_source` |
| 上架狀態 + 排序 | ✅ `books.idx_pub_created` |

★ `publish_date` 是 `varchar(10)`(YYYY / YYYY-MM / YYYY-MM-DD 混用)
→ 年份篩選要用前綴比對,索引才吃得到;不要用函式包欄位。

### ★★ `idx_pub_created` 的由來(附一則被推翻的判斷)

`2026-09-16_m1b_search_text_and_indexes.sql` 曾寫「刻意不建 `is_published` 索引:
幾乎全為 1(65,290 中僅數百筆為 0),基數極低」。
**9/19 實查:全站 65,304、上架 54,530 → 下架 10,771 本(16.5%),差了兩個數量級。**

而查詢形狀是 `WHERE is_published=1 ORDER BY created_at DESC, book_id DESC LIMIT n`,
正是複合索引能一次吃掉「過濾 + 排序 + 取前 n 列」的形狀。實測:

| | 建索引前 | 建索引後 |
|---|---|---|
| 瀏覽第一頁 | 5.004 s | **0.00041 s** |
| EXPLAIN | `ALL` + `Using filesort` | `ref` + `key=idx_pub_created` + `Using index` |

★ 這條路徑是首頁與所有「不帶關鍵字的瀏覽/分類/來源篩選」在走的 ——
也就是說**首頁瀏覽一直都是 5 秒**,與搜尋無關,只是從來沒有人量過它。

### ★★ ORDER BY + LIMIT 會讓最佳化器翻轉驅動表(2026-09-19 實測)

關鍵字搜尋同一組條件、同樣命中 3 筆:

| 寫法 | 秒 |
|---|---|
| `COUNT(*)`(無 ORDER BY) | 0.288 |
| 取 20 筆 `ORDER BY created_at DESC LIMIT 20` | **4.776** |
| 同句拿掉 ORDER BY | 0.153 |
| `STRAIGHT_JOIN` 強制 `book_search` 當驅動表 | **0.167** |
| 改 `IN` 子查詢 | 4.793 |
| 改衍生表先過濾 | 5.208 |

最佳化器看到 `ORDER BY + LIMIT`,會把驅動表從 13.5 MB 的 `book_search`
換成 511 MB 的 `books`(以為能早點湊滿 20 筆),而符合的只有 3 筆 → 一路掃到底。
**`IN` 與衍生表都擋不住(仍是建議),只有 `STRAIGHT_JOIN` 是命令。**
`api/index.php` 的 `get_books()` 第一段因此固定寫成
`SELECT STRAIGHT_JOIN … FROM book_search bs JOIN books b …`。

★ 量效能時,命中筆數要抽多的那種:只有 3 筆時「早停」佔不到便宜,
會高估效果(447 筆的複測是 0.388 s,仍達標)。

### ★ 兩個冗餘索引(浪費空間、拖慢寫入,可刪)

1. **`editions` 的 `publisher_id` 有兩個索引**:`idx_publisher` 與 `idx_editions_publisher`
   (後者由 `2026-07-16_idx_editions_publisher.sql` 加入,當時已存在前者)。
2. **`media.idx_edition(edition_id)` 是 `idx_media_cover(edition_id, media_type, is_primary, media_id)`
   的前綴**,完全冗餘。

⚠️ 刪索引前先跑 `ANALYZE TABLE`(見陷阱三),並確認 `v_book_list` 沒有依賴特定索引名。

---

## 十、VIEW

### `v_book_list`(16 欄)
清單/搜尋用彙總視圖。與 `books` 同名欄位的型別差異透露其來源:

| 欄位 | view 型別 | `books` 型別 | 推測來源 |
|---|---|---|---|
| author / translator | **mediumtext** | varchar(255) | 由 `book_persons` GROUP_CONCAT |
| publisher | varchar(150) | varchar(100) | `publishers.name_zh` |
| isbn13 | varchar(30) | varchar(13) | `identifiers.id_value` |
| cover_url | text | varchar(500) | 可能聚合 `media` |

⚠️ **完整定義仍未取得** —— `SHOW CREATE VIEW v_book_list` 的 `Create View` 欄在 Navicat 顯示被截斷,
只看到 `CREATE ALGORITHM=UI…`。**這是本文件唯一仍缺的一塊。**

取得方式(擇一):Navicat 右鍵該 view →「設計檢視/DDL」複製全文,或
```sql
SELECT VIEW_DEFINITION FROM information_schema.VIEWS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'v_book_list';
```
把全文存成 `docs/schema-view-v_book_list.sql`。

★ **為什麼非拿到不可**:要知道它是否已跟隨 `publishers.canonical_id`。
若沒有,出版社頁(M1-B)得改 view 或改走 `books` 直查——這是動工前必須先確定的事。
另外 `2026-07-16_fix_v_book_list_cover_perf.sql` 顯示它為封面效能改過一次,
改動內容也只有 DDL 看得出來。

---

## 十一、維護規則

1. **改 schema = 同時更新本檔 + 在 migration 結尾 INSERT 一列 `schema_migrations`。**
2. **建表一律明寫 `COLLATE=utf8mb4_unicode_ci`**(資料庫預設是 `general_ci`,不寫會不一致)。
3. 對外數字一律 `COUNT(*)` 實查,**不用 `TABLE_ROWS`**;量效能前先 `ANALYZE TABLE`。
4. 所有 SQL 一律 PDO prepared statements;`EMULATE_PREPARES=false`
   → **同名參數不可重複使用**,多處要用就編號 `:q1`、`:q2`。
5. 建 lookup(publishers / subjects)一律
   `INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)`
   —— 唯一鍵是 `utf8mb4_unicode_ci`,PHP 陣列鍵去重會漏。
6. 下架一律 `is_published=0`,**不刪資料**。

---

## 附:本次盤點用的六段查詢

留存以便下次重跑(`docs/database-current.md` 的再生產程序)。

```sql
-- ① 環境
SELECT VERSION(), DATABASE(), @@character_set_database, @@collation_database,
       @@time_zone, @@system_time_zone;
-- ② 欄位
SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
       COLUMN_DEFAULT, COLUMN_KEY, COLUMN_COMMENT
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
 ORDER BY TABLE_NAME, ORDINAL_POSITION;
-- ③ 索引
SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE,
       CARDINALITY, INDEX_TYPE
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
 ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
-- ④ 外鍵
SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME,
       REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL;
-- ⑤ 表層級
SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_ROWS, TABLE_COLLATION, TABLE_COMMENT
  FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();
-- ⑥ VIEW 定義
SELECT VIEW_DEFINITION FROM information_schema.VIEWS
 WHERE TABLE_SCHEMA = DATABASE();
```
