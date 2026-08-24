# 書目爬蟲(校園書房 + 基道 + 以琳書房 + 天恩出版社 + 微讀書城 + 衛理書房 + 格子外面 + 道聲 + 橄欖華宣 + 宇宙光 + 真哪噠)

單執行緒禮貌抓取。輸出 JSONL 原始資料,匯入正規化表由後續 importer 處理(拆分可無損還原,多值一律原樣保留)。校園/基道在熊哥本機(Windows)執行;以琳(2026-07-31 新增)、天恩(2026-08-06 新增)於主機執行。

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

## 天恩出版社(grace,8/6 新增)

WooCommerce 站:商品清單走公開 Store API(穩定 JSON、日期倒序),書目欄位
(作者/譯者/ISBN/頁數/初版/開數/定價/英文書名)在商品頁 HTML「資訊」頁籤,
每件仍抓一次 HTML(節流 2-3 秒,全站約 1,626 件、約 1.5 小時)。

主機執行流程(先 Navicat 跑 `database/migrations/2026-08-06_grace_category_map.sql`,再 FTP 上傳程式):

```bash
cd crawler
venv/bin/python graceph_crawler.py --probe       # 先探測,輸出貼回給 Claude 確認
nohup venv/bin/python graceph_crawler.py > logs/grace.log 2>&1 &    # 全量(可中斷續跑)
# 完成後匯入 + 套分類(都先 dry-run 看統計):
php tools/import.php --file=crawler/data/grace_books.jsonl --source=grace --dry-run
php tools/import.php --file=crawler/data/grace_books.jsonl --source=grace
php tools/apply_grace_categories.php --dry-run
php tools/apply_grace_categories.php
php tools/classify_categories.php                 # 只補未分類新書
php tools/covers_to_r2.php --source=grace         # 封面轉存 R2
```

範圍(8/6 決議):全站抓入存證;非書(文創禮品/質選文創好物/專輯有聲/虛擬商品/年度日月曆)匯入後由對映表下架(命中任一即下架);電子書照書上架、與紙本同書合併(同名同作者即使 eISBN 不同也併),購書連結標示「天恩出版社(電子書)」。
分類雙軌:天恩原始分類(平面多分類)入 `subjects(scheme='grace')` 永久存證;站內瀏覽分類由 `grace_category_map` 對映(Navicat 可改 internal_name/sort_order,改後重跑 apply 即生效);「新書快報/暢銷排行/電子書」為促銷/格式類,不入對映。
電子書判定:商品名含「電子書」或編號以 eb 開頭(電子書「分類」不可靠,有紙本書誤掛)。

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
| `data/elim_books.jsonl` | 每行一本,key=gid |
| `data/grace_books.jsonl` | 每行一本,key=pid |
| `data/wdbook_books.jsonl` | 每行一本,key=pid(微讀書城,全站電子書) |
| `data/*_state.json` | 已完成的分類/年份(續跑用) |
| `cache/` | 已抓頁面 gzip 快取(重新解析不需重抓) |

`data/` 與 `cache/` 不進 git。

## 合規

- UA 註明 CCNDA、聯絡信箱與用途;robots.txt 已核(校園允許商品/分類頁;基道無限制;天恩僅擋 wp-admin/cart/checkout/my-account;微讀僅擋 /mine/)
- 429/503 自動退避;單執行緒 + 隨機延遲
- 簡介與封面入庫時保留 source_url 標注出處


## 衛理書房(methodist,8/17 新增)

馬來西亞衛理公會書局(methodistbookroom.com),OpenCart 商城、全站 SSR、MYR 計價。
書名/簡介多為簡體 → 沿微讀規則 s2tw 轉繁入庫、原文存 extra.hans。
分類樹由導覽選單動態解析(不寫死);商品分類歸屬由「清單走訪」蒐集(一書多分類,同以琳)。
Product SKU 多數即 ISBN13(97x 開頭才認定);Brand=出版社。

主機執行流程(先 Navicat 跑 `database/migrations/2026-08-17_methodist_category_map.sql`,再 FTP 上傳程式):

```bash
cd crawler
venv/bin/python methodist_crawler.py --probe     # 先探測,輸出貼回給 Claude 確認
nohup venv/bin/python methodist_crawler.py > logs/methodist.log 2>&1 &   # 全量(可中斷續跑)
# 完成後匯入 + 套分類(都先 dry-run 看統計):
php tools/import.php --file=crawler/data/methodist_books.jsonl --source=methodist --dry-run
php tools/import.php --file=crawler/data/methodist_books.jsonl --source=methodist
php tools/apply_methodist_categories.php --dry-run
php tools/apply_methodist_categories.php
php tools/covers_to_r2.php --source=methodist
```

範圍(8/17 決議):全站抓入存證;非書(禮品/詩歌CD/桌遊等)與外文書(英文/馬來文/印尼文)
由 methodist_category_map 下架(任一命中即下架)。
每日新品:new_arrivals.py --source methodist(導覽全分類 ?sort=p.date_added&order=DESC
日期倒序增量、無新品即停),已入 daily_new.sh。

## 格子外面(osb,8/18 新增)

Cyberbiz 站(osb.com.tw,前程文化/格子外面,TWD,全站繁體):清單走
`/zh-TW/collections/{handle}/search_products.json?page=N&per=100`(穩定 JSON;
注意 `products.json` 分頁參數無效不可用),商品走 `/products/{handle}.json`
(title/vendor=出版社/slogan≈作者/body_html=簡介/other_descriptions 含規格表
——區塊語意不固定,以「規格欄位命中 ≥2」內容特徵辨識)。handle 可為中文,
請求時 quote、source_url 存 percent-encoded 正規網址。

範圍(8/18 決議,沿以琳「只抓書籍+聖經」):`osb`(全部書籍,約 1,106 件)
+ 聖經三分類(和合本/當代譯本/活頁筆記-聖經)+ `★新書到`(每日增量入口)。
文創禮品/前程教材/回頭書不抓;混入的非書由 `osb_category_map`(unpublish)
+ classify 關鍵字下架。分類歸屬由「清單走訪」蒐集(走訪 書籍類型 43 +
幸福門訓 18 + 前程彙整,寫死清單;站方改選單需重新偵察),一書多分類全數
存證 `subjects(scheme='osb', code=collection handle)`。

主機執行流程(先 Navicat 跑 `database/migrations/2026-08-18_osb_category_map.sql`,再 FTP 上傳程式):

```bash
cd crawler
venv/bin/python osb_crawler.py --probe           # 先探測,輸出貼回給 Claude 確認
nohup venv/bin/python osb_crawler.py > logs/osb.log 2>&1 &          # 全量(可中斷續跑)
# 完成後匯入 + 套分類(都先 dry-run 看統計):
php tools/import.php --file=crawler/data/osb_books.jsonl --source=osb --dry-run
php tools/import.php --file=crawler/data/osb_books.jsonl --source=osb
php tools/apply_osb_categories.php --dry-run
php tools/apply_osb_categories.php
# 封面轉 R2:
php tools/covers_to_r2.php --source=osb
```

每日新品:`new_arrivals.py --source osb` 每日重走全部分類清單(約 85 請求,
無排序假設),範圍內未見過的 handle 即新品;已併入 `daily_new.sh`。

## 道聲(taosheng,8/19 新增)

Cyberbiz 站(taosheng.com.tw,**與格子外面同平台**),TWD、繁體:清單走
`/zh-TW/collections/{handle}/search_products.json?page=N&per=100`(全站入口
`all`,1,637 件),商品走 `/products/{handle}.json`。規格表格式**異於格子外面**
(裝訂/頁數/規格/出版社/ISBN/出版日期/商品語言),同樣以「規格欄位命中 ≥2」
內容特徵辨識(實測命中 16/20;月刊/月曆/文創本就無規格)。

範圍(8/19 決議):**全站抓入存證**,非書(影音/月曆/刮刮卡/桌遊/禮品/福音機)
由 `taosheng_category_map` unpublish 下架;**代銷他社書全收**(香港道聲 185、
高示 97、格子外面 68、橄欖華宣 67…同 ISBN 自動跨站合併,多一個購書管道)。

```bash
cd crawler
venv/bin/python taosheng_crawler.py --probe        # 先探測,輸出貼回確認
nohup venv/bin/python taosheng_crawler.py > logs/taosheng.log 2>&1 &
php tools/import.php --file=crawler/data/taosheng_books.jsonl --source=taosheng --dry-run
php tools/import.php --file=crawler/data/taosheng_books.jsonl --source=taosheng
php tools/apply_taosheng_categories.php --dry-run
php tools/apply_taosheng_categories.php
php tools/covers_to_r2.php --source=taosheng
```

每日新品:`new_arrivals.py --source taosheng`(重走全分類約 90 請求),已入 `daily_new.sh`。

## 橄欖華宣(cclm,8/19 新增)

自建 SSR 商城(cclm.com.tw,OpenCart 系),TWD、繁體:清單頁 `/{中文分類}` 或
`/product-category/{id}` + `?page=N`(每頁 15;**排序為商品 id 遞減=新→舊**,
增量可「整頁已見即停」),商品頁 `/product/{id}`——`h1` 書名、`#description`
簡介、`#additionalinformation` 規格(商品貨號/出版社/作者/ISBN/頁數/尺寸/重量;
標籤與值會被 inline 標籤拆行 → 以標籤字樣切割整段,並切掉其後的退貨條款)、
`.new-price`/`.old-price` 價格、`og:image` 封面。

範圍(8/19 決議,沿以琳「只抓書籍+聖經」):【書籍】全枝(294 頁約 4,400 件)
+【聖經】全枝(約 110 件);文創/客製印刷/節期禮品不抓,聖經周邊抓入但下架。
全量約 4,500 件、節流 2-3 秒 → **預估 4-5 小時,建議過夜跑**。

```bash
cd crawler
venv/bin/python cclm_crawler.py --probe            # 先探測,輸出貼回確認
nohup venv/bin/python cclm_crawler.py > logs/cclm.log 2>&1 &
php tools/import.php --file=crawler/data/cclm_books.jsonl --source=cclm --dry-run
php tools/import.php --file=crawler/data/cclm_books.jsonl --source=cclm
php tools/apply_cclm_categories.php --dry-run
php tools/apply_cclm_categories.php
php tools/covers_to_r2.php --source=cclm
```

每日新品:`new_arrivals.py --source cclm`(逐分類、整頁已見即停),已入 `daily_new.sh`。

## 宇宙光全人關懷機構(cosmiccare,8/21 新增)

自建 SSR 商城(bookstore.cosmiccare.org,ASP.NET MVC 系),TWD、繁體:清單頁
`/Product/List?Category={大類}[&Tag={子類}][&page=N]`(每頁 16 件,分頁列末頁由
`>>` 宣告),商品頁 `/Product/Detail/{商品代碼}`(代碼如 MA628、DS060-3)。
商品頁**沒有 h1**——書名在 `h2.product-title`;規格是一欄一個 `.detailsp`
(售價/優惠價/作者/出版社/ISBN/出版日期/尺寸/重量/頁數/裝訂),**其中尺寸/重量/
頁數/裝訂在頁籤區之後**,要掃全頁;內容頁籤 `.tab-pane` 各自帶
`h3.tabcont-title`(頁籤列只有 4 項但 pane 有 5 個,**不可用索引對位**),
「商品介紹」入簡介、其餘存 `extra.tabs`;封面取 `og:image`。

清單走訪兩個陷阱:側欄「熱門排行 TOP」是商品連結(整頁 regex 會混入)、Tag
清單頁最上方兩格「焦點」是 `div.product.topsection` 且**每頁重複**——選擇器一律
用 `div.product:not(.topsection)`。

範圍(8/21 決議):**全站抓入存證**(實測 1,671 件:書籍約 1,419、繪本 50、
雜誌 120、影音 23、禮品 59),非書(影音/禮品/雜誌訂閱/海外運費)由
`cosmiccare_category_map` unpublish 下架;《宇宙光雜誌》約 120 期收錄(新分類
「期刊雜誌」);作者系列 Tag 與★福利書不參與分類。
**五個大類清單的聯集 = `/Product/List` 全站 1,671 件**(8/21 全站走訪驗證,
無空洞頁、無重複)→ 走訪 6 個大類即保證完整,Tag 走訪只為取細分類。
全量約 1,671 件、節流 2-3 秒 → 預估 1.5-2 小時。

```bash
cd crawler
venv/bin/python cosmiccare_crawler.py --probe      # 先探測,輸出貼回確認
nohup venv/bin/python cosmiccare_crawler.py > logs/cosmiccare.log 2>&1 &
# 先跑 migration:database/migrations/2026-08-21_cosmiccare_category_map.sql(Navicat)
php tools/import.php --file=crawler/data/cosmiccare_books.jsonl --source=cosmiccare --dry-run
php tools/import.php --file=crawler/data/cosmiccare_books.jsonl --source=cosmiccare
php tools/apply_cosmiccare_categories.php --dry-run
php tools/apply_cosmiccare_categories.php
php tools/covers_to_r2.php --source=cosmiccare
```

每日新品:`new_arrivals.py --source cosmiccare`(先走 6 個大類彙整約 107 頁;
偵測到新品才補走 45 個 Tag 取分類歸屬),已入 `daily_new.sh`。

## 真哪噠買書(MEZU)網(mezu,8/22 新增)

EasyStore 商城(www.mezu.com.tw,浸信會特約書店、承襲恩膏書房,兼營代編代印),
TWD、繁體(少數簡體品項)。**沒有任何 JSON API**(`/products.json`、
`?format=json` 都回 HTML 首頁),只能解析 HTML。

**商品清單走 sitemap**:`/sitemap_products.xml`(34 個分片 = 10,027 件)是權威
全站清單;`/sitemap_collections.xml` = 119 個分類。分類頁只負責蒐集「分類歸屬」
(一書多分類)。好處是不寫死導覽選單——站方「潮牌禮品」4 個子選單連結已 404。

清單分頁 `?page=N` 固定每頁 50 件(**`limit` 參數無效**),四個陷阱:

1. **超出末頁不會空、也不會 404,而是回傳末頁內容(HTTP 200)** —— 各款聖經共
   5 頁 240 件,`page=6/7/20/99` 都回第 5 頁那 40 件。停止條件必須是
   「不足 50 件」或「本頁 handle 集合與前一頁相同」。
2. 分頁列只是「當前頁 ±5」的視窗,**看不出總頁數**(禱告靈修第 1 頁顯示到 6、
   第 9 頁顯示到 14)→ 不可拿分頁列末頁當邊界。
3. **404 頁面仍含 4 個推薦商品連結** → 每個網址都要驗 HTTP 狀態。
4. 商品規格是 **Froala 富文本自由文字**,同欄位寫法不一(「出版社:」vs
   「出 版 商」+`&nbsp;`;還有系列別/語文別/頁數開本/印刷裝訂/EAN/類別)。

**欄位解析(8/22 決議):原文優先、解析留待後續**——描述原文整段存
`extra.desc_raw`,通用「標籤→值」採集存 `extra.spec_all`(供事後統計標籤分布
再決定對映),只有白名單欄位(ISBN/EAN/出版社/作者/譯者/出版日期/頁數/尺寸/
重量/系列/語文/裝訂)入平面欄。價格取 `.product-single__price`(現價)與
`.product-single__sale-price`(定價),封面取 `og:image`(cdn.store-assets.com)。

範圍(8/22 決議):**全站 10,027 件抓入存證**,非書(喜樂影音/哪噠禮品/潮牌
禮品/客製化月曆)由 `mezu_category_map` unpublish 下架;分類只採站方 17 個主題
分類,出版社/總代理(約 70 個)與促銷彙整僅存證不歸類。
全量約 10,027 件商品頁 + 約 600 頁清單、節流 1.5-2.5 秒 → **預估 5-7 小時**
(可中斷續跑,快取在 `cache/mezu`)。

```bash
cd crawler
venv/bin/python mezu_crawler.py --probe            # 先探測,輸出貼回確認
nohup venv/bin/python mezu_crawler.py > logs/mezu.log 2>&1 &
# 先跑 migration:database/migrations/2026-08-22_mezu_category_map.sql(Navicat)
php tools/import.php --file=crawler/data/mezu_books.jsonl --source=mezu --dry-run
php tools/import.php --file=crawler/data/mezu_books.jsonl --source=mezu
php tools/apply_mezu_categories.php --dry-run
php tools/apply_mezu_categories.php
php tools/covers_to_r2.php --source=mezu
```

每日新品:`new_arrivals.py --source mezu`(先走「新品上架/注目優惠」約 8 頁;
偵測到新品才補走 119 個分類取歸屬),已入 `daily_new.sh`。
