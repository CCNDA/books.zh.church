# 更新紀錄(CHANGELOG)

本檔記錄 books.zh.church(CCNDA 屬靈共同書目)每一版的重點變更。
版本號採語意化版本(SemVer):`主版本.次版本.修訂`。首頁 footer 顯示至次版本(如 `v1.0`)。

- **主版本**:重大架構或功能里程碑(例:正規化正式版、管理後台上線)
- **次版本**:新增功能(例:訂閱、進階篩選、使用者書庫)
- **修訂**:修 bug、資料修正、小調整

發布流程:更新 `VERSION` → 在本檔新增版本區塊 → commit → 建 git tag `vX.Y.Z` → FTP 部署 → 同步首頁 footer 版號。

---

## [1.8.0] - 2026-08-23 —— 新增書目來源:真哪噠買書(MEZU)網(www.mezu.com.tw)

### 上線實績(8/23)
- 走訪 10,027 件全數入檔(略過 0)→ 匯入版本 10,027(1 筆重複網址去重)→ 新書 4,859(上架 4,633、非書下架 226)、跨站合併 5,167(ISBN 命中 2,829 / 模糊 2,325 / 同檔 4)
- apply 8,903 本:多分類 1,262、無主題分類 641 交 classify;classify 再處理 895 本(關鍵字 894、落入綜合其他 129=14.4%、非書下架 1)。**未分類歸零**
- primary 分布:靈修 4,349 / 神學 1,441 / 門徒造就 539 / 見證 390 / 福音 377 / 青少年家庭 347 / 健康 317 / 聖經 256 / 兒童教材 183 / 詩本樂譜 60 / 社會 3
- **ISBN 來源實績(驗證 8/23 兜底設計)**:handle 1,765 / 描述標籤 1,705 / 條碼欄 119 / 描述裸掃 75 = 3,664 本;三種兜底貢獻 1,959 本(佔 53%)。最大標籤組合 `[作者,譯者,出版社]` 1,937 件完全沒有 ISBN,全靠 handle 救回
- 封面轉 R2 4,440 張
- 8/23 決議:站方最大萬用類「生命造就」(3,978 件)對映**靈修**而非門徒造就(全歸門徒造就會讓該類暴增六成)

### 新增
- 真哪噠爬蟲 `crawler/mezu_crawler.py`:EasyStore 商城(TWD、繁體,浸信會特約書店兼營代編代印)。**站方無任何 JSON API**(`/products.json`、`?format=json` 皆回 HTML 首頁),改以 **`sitemap_products.xml`(34 分片 = 10,027 件)當權威全站清單**,119 個 collection 清單只負責蒐集分類歸屬(一書多分類)→ 站方改選單也不會漏抓(其「潮牌禮品」4 個子選單連結已 404)
- 站台四陷阱(寫入爬蟲檔頭與 `crawler/README.md`):①**超出末頁回傳末頁內容且 HTTP 200**(各款聖經 5 頁 240 件,`page=99` 仍回第 5 頁那 40 件)→ 停止條件改為「不足 50 件或本頁與前頁 handle 集合相同」;②分頁列只是「當前頁 ±5」的視窗,看不出總頁數;③**404 頁面仍含 4 個推薦商品連結**→ 每個網址都要驗 HTTP 狀態;④商品描述是 **Froala 富文本自由文字**,同欄位多種寫法(「出版社:」vs「出 版 商」+`&nbsp;`)
- 範圍決議(8/22):**全站 10,027 件抓入存證**,非書(喜樂影音/哪噠禮品/潮牌禮品/客製化月曆/月桌曆)由 `mezu_category_map` unpublish 下架(任一命中即下架,僅 mezu-only 書)
- 欄位決議(8/22):**原文優先、解析留待後續**——描述原文整段存 `extra.desc_raw`、通用「標籤→值」採集存 `extra.spec_all`(供事後統計標籤分布再決定對映),只有白名單欄位(ISBN/EAN/出版社/作者/譯者/出版日期/頁數/尺寸/重量/系列/語文/裝訂)入平面欄
- 分類決議(8/22):只有站方 **17 個主題分類**參與站內分類;出版社/總代理(約 70 個)與促銷彙整僅存證不歸類,出版社分類另作出版社欄位佐證(apply 工具只報表不寫入)
- 雙軌分類:`mezu_category_map`(119 列,與站台分類 100% 對齊)+ `tools/apply_mezu_categories.php`
- **ISBN 多來源兜底(8/23 probe 後補強)**:站方描述只有約 17% 有 ISBN 標籤,但 **handle 本身就是 ISBN 的有 1,937 件**(ISBN13 1,491、ISBN10 446;檢查碼實測 1,489/1,491 與 427/446 通過)→ 來源優先序「標籤 → 條碼欄(含「電腦條碼」)→ 描述裸掃 → handle」,後兩者**嚴驗檢查碼**、裸掃要求整段只有一個 ISBN(叢書列表會列別本書的 ISBN),寧缺勿錯;來源記於 `isbn_from` 供稽核。handle 像店內貨號者存 `item_no`(排除英文書名 slug),價格去小數尾零,`spec_all` 過濾目錄行與單字鍵,複合值(「頁數開本 尺寸:… 頁數:…」)再撈尺寸/頁數/重量
- 每日新品:`new_arrivals.py --source mezu`(先走「新品上架/注目優惠」約 8 頁;有新品才補走 119 個分類取歸屬)+ `daily_new.sh` 十一站(含九套 apply)
- `deploy/mezu-runbook.md`:上線步驟、FTP 上傳對照表、驗收 SQL、待覆核清單

### 變更
- `tools/import.php`:白名單加 mezu;購書連結標「真哪噠」
- `api/index.php`:`BUY_PLATFORM_LABELS`/`ORDER` 加真哪噠(排序 12)
- `tools/covers_to_r2.php`:`--source` 白名單加 mezu
- **`database/migrations/2026-08-23_widen_source_url.sql`**:`editions.source_url` 500→1000、`links.url` 700→1000。真哪噠中文 handle percent-encoding 後每個中文字佔 9 字元,最長網址 540 字元(全站僅 1 筆超過 500)撞上 `1406 Data too long`,匯入中途中斷;兩欄皆無索引,加寬零風險。**教訓:新來源 probe 階段就要量欄位長度上限**
- 版號規劃調整:M1-B 進階搜尋改用 **v1.9.0**(原規劃 v1.8.0)

---

## [1.7.0] - 2026-08-21 —— 新增書目來源:宇宙光全人關懷機構(bookstore.cosmiccare.org)

### 新增
- 宇宙光爬蟲 `crawler/cosmiccare_crawler.py`:自建 SSR 商城(ASP.NET MVC 系,TWD、繁體);清單 `/Product/List?Category=&Tag=&page=N`(每頁 16、末頁由 `>>` 宣告)、商品 `/Product/Detail/{商品代碼}`;書名取 `h2.product-title`(本站無 h1)、規格逐欄 `.detailsp`(掃全頁——尺寸/重量/頁數/裝訂在頁籤區之後)、內容頁籤以 `h3.tabcont-title` 取名(頁籤列 4 項但 pane 有 5 個,不可索引對位)、`og:image` 封面
- 範圍決議(8/21):**全站抓入存證**(1,671 件:書籍約 1,419、繪本 50、雜誌 120、影音 23、禮品 59),非書(影音/禮品/雜誌訂閱/海外運費)由對映表下架;代銷他社書全收(同 ISBN 自動跨站合併)
- 雜誌決議(8/21):《宇宙光雜誌》約 120 期收錄,新增站內分類**「期刊雜誌」**(categories code='T');該站 ISBN 欄放的是 ISSN 條碼(977…),爬蟲不會誤判為 ISBN13,不會與書籍誤合併
- 分類決議(8/21):作者系列 Tag(林治平/黃小石/魏外揚/諾曼．萊特/張德健長老作品)與★福利書**不參與分類**,`internal_name=NULL` 僅存證,分類改由其他主題 Tag 決定
- 雙軌分類:`cosmiccare_category_map`(51 列)+ `tools/apply_cosmiccare_categories.php`;對映表與爬蟲走訪清單 100% 對齊(零未對映)
- 每日新品:`new_arrivals.py --source cosmiccare`(先走 6 個大類彙整約 107 頁;有新品才補走 45 個 Tag 取分類歸屬)+ `daily_new.sh` 十站(含八套 apply)
- `tools/import.php` dry-run 新增**合併明細**:`ISBN 命中 / 模糊比對 / 同檔內重複` 三分,並列出最多 30 筆模糊配對供人眼核對;重用 import 自身比對邏輯,落實「合併率偏高先拆再匯」的慣例(宇宙光 77% 合併率:ISBN 1,158、模糊 118、同檔 6,零誤併)
- `tools/notify_discord.py`:Discord 版本公告的 Python 版(只用標準函式庫,無 PHP 環境如 Windows 本機也能跑),與 PHP 版同一份 `release-notes/vX.Y.Z.md` 文案、同樣的切則與 allowed_mentions 規則;webhook 依序取自環境變數 `DISCORD_WEBHOOK_URL`、`--webhook-file`、`config/app.local.php`

### 變更
- `tools/import.php`:白名單加 cosmiccare;購書連結標「宇宙光」
- `api/index.php`:`BUY_PLATFORM_LABELS`/`ORDER` 加宇宙光(排序 11)
- `tools/covers_to_r2.php`:`--source` 白名單加 cosmiccare
- 版號規劃調整:M1-B 進階搜尋改用 **v1.8.0**(原規劃 v1.7.0)

### 已知陷阱(寫進爬蟲註解)
- 清單頁側欄「熱門排行 TOP」與 Tag 頁頂端兩格「焦點」(`div.product.topsection`)都是商品連結且每頁重複 → 選擇器必須是 `div.product:not(.topsection)`
- 書籍 Tag「真實故事」的站方 Tag 值**開頭有一個半形空格**,少一格會 0 件(0 件分類不會印 log)

---

## [1.6.0] - 2026-08-21 —— 新增書目來源:道聲(taosheng.com.tw)、橄欖華宣(cclm.com.tw)

### 新增
- 道聲爬蟲 `crawler/taosheng_crawler.py`:Cyberbiz 商城(與格子外面同平台,TWD、繁體),全站入口 `/collections/all`(1,637 件);規格表格式異於格子外面(裝訂/頁數/規格/出版社/ISBN/出版日期/商品語言),沿用「規格欄位命中 ≥2」內容特徵辨識(實測 16/20);tags 可辨非書
- 橄欖華宣爬蟲 `crawler/cclm_crawler.py`:自建 SSR 商城(OpenCart 系,TWD、繁體);清單 `/{中文分類}?page=N`(每頁 15、id 遞減=新→舊,增量可整頁已見即停)、商品頁 `#additionalinformation` 規格(標籤與值被 inline 拆行 → 標籤字樣切割並切掉退貨條款)、`#description` 簡介、`.new-price`/`.old-price`、`og:image` 封面
- 範圍決議(8/19):道聲**全站抓入**、非書(影音/月曆/刮刮卡/桌遊/禮品/福音機)由對映表下架,代銷他社書全收(同 ISBN 自動跨站合併);橄欖華宣沿以琳「只抓書籍+聖經」——書籍全枝 + 聖經全枝(實抓 2,261 件;偵察推估的 4,400 是以「最大分頁 × 每頁 15」估算,實際多數頁不滿 15 件),文創/客製印刷/節期不抓,聖經周邊抓入但下架
- 雙軌分類:`taosheng_category_map`(67 列)+ `tools/apply_taosheng_categories.php`、`cclm_category_map`(100 列)+ `tools/apply_cclm_categories.php`;兩表皆與爬蟲走訪清單 100% 對齊(零未對映)
- 每日新品:`new_arrivals.py --source taosheng|cclm` + `daily_new.sh` 九站(含兩套 apply)
- 版本公告工具 `tools/notify_discord.php`:把 `release-notes/vX.Y.Z.md`(寫給一般使用者的更新說明,與 CHANGELOG 分工)發佈到 Discord webhook;超過 2000 字自動依段落切則、`allowed_mentions` 關閉避免誤 @everyone;webhook URL 放 `config/app.local.php` 的 `discord.webhook_url`(不進 git)

### 修正
- `cclm_crawler.py` 分頁走訪提前中斷(8/21):原「首次空頁/無新項即停」使「書籍」只走到第 3 頁,全站僅抓 1,595 件。cclm 分頁序列布滿長度不定的空洞頁(page 120/150 空,但 200/250/294 仍有商品),改以 `max_page_hint()` 取分頁列宣告的末頁為邊界、空洞頁略過續走,另加 `PAGE_MARGIN = 6` 滾動延伸 → 2,261 件(「全部書籍」42 → 2,169)
- `cclm_crawler.py` 增量模式空洞頁誤判:`all(p in stop_on_seen for p in got)` 對空集合回 True,加 `got and` 保護
- `cclm_crawler.py` + `cclm_category_map`:`聖經 > 註釋研讀` 網址由單段 `/注釋`(404)修正為兩段式 `/注釋/研讀`,對映表 code 同步 `注釋` → `研讀`

### 變更
- `tools/import.php`:白名單加 taosheng、cclm;購書連結標「道聲」「橄欖華宣」
- `api/index.php`:BUY_PLATFORM 加 taosheng=9(道聲)、cclm=10(橄欖華宣)
- `tools/covers_to_r2.php`:--source 白名單加 taosheng、cclm
- 首頁 footer 版號 v1.6;about.html 收錄書房清單加入道聲、橄欖華宣(台灣區)

### 部署
1. Navicat 跑 `database/migrations/2026-08-19_taosheng_category_map.sql` 與 `2026-08-19_cclm_category_map.sql`
2. FTP 上傳程式(見交付對照表)
3. 依 `crawler/README.md` 兩段落執行 probe → 全量 → 匯入 → 套分類 → 封面轉 R2(橄欖華宣清單 759 頁約 30 分鐘,加商品頁全程約 2 小時)
4. 對映表若已建過,`注釋` → `研讀` 改 code 用 `UPDATE cclm_category_map SET cclm_code='研讀' WHERE cclm_code='注釋';`

### 上線實績
- 道聲:抓 1,639 件 → 新書 698 + 跨站合併 941 → apply 1,609 本、非書下架 105、未對映 0 → 封面 595 張 0 失敗
- 橄欖華宣:抓 2,261 件 → 新書 400 + 跨站合併 1,860(其中 1,816 靠 ISBN、約 44 靠模糊比對) → apply 2,252 本、非書下架 5、未對映 0 → 封面 401 張 0 失敗

---

## [1.5.0] - 2026-08-18 —— 新增書目來源:格子外面(osb.com.tw)

### 新增
- 格子外面爬蟲 `crawler/osb_crawler.py`:台灣「格子外面」書房(前程文化),Cyberbiz 商城、TWD、全站繁體(不需 OpenCC)。清單走 `search_products.json` 公開 JSON(每頁 100;`products.json` 分頁失效不可用)、商品走 `/products/{handle}.json`;規格表(原書號/ISBN/出版日期/頁數/尺寸/語言/裝訂/出版社分類)散落 other_descriptions 且區塊語意不固定 → 以「規格欄位命中 ≥2」內容特徵辨識,先逐行比對、不足再退標籤切割;中文 handle 請求時 quote、source_url 存 percent-encoded 正規網址;封面取 photo_urls[].original
- 範圍(8/18 決議,沿以琳「只抓書籍+聖經」):全部書籍(osb,約 1,106 件)+ 聖經三分類(和合本/當代譯本/活頁筆記-聖經)+ ★新書到;文創禮品/前程教材/回頭書不抓,混入非書由對映表 unpublish(任一命中即下架,沿天恩規則)+ classify 關鍵字保底
- 雙軌分類:subjects(scheme='osb', code=collection handle,中文 handle 直接入 code)存證 + `osb_category_map`(62 列,internal_name 可 NULL=僅存證)+ `tools/apply_osb_categories.php`(查表一律 array_key_exists,沿 8/17 教訓);分類歸屬由清單走訪蒐集(書籍類型 43 + 幸福門訓 18 + 前程彙整)
- 每日新品:`new_arrivals.py --source osb`(每日重走全部分類清單約 85 請求,無排序假設,範圍內未見過的 handle 即新品)+ `daily_new.sh` 七站(含 apply_osb_categories 套用)

### 變更
- `tools/import.php`:來源白名單加 osb;購書連結標「格子外面」
- `api/index.php`:BUY_PLATFORM_LABELS/ORDER 加 osb=8(格子外面)
- `tools/covers_to_r2.php`:--source 白名單加 osb
- `about.html`:「資料來源」新增目前收錄書房清單,依地區分組(台灣:校園/以琳/天恩/格子外面;香港:基道 BookFinder;馬來西亞:衛理書房;電子書:微讀書城),名稱含官網連結
- 首頁 footer 版號 v1.5

### 部署
1. Navicat 跑 `database/migrations/2026-08-18_osb_category_map.sql`
2. FTP 上傳程式(osb_crawler.py、new_arrivals.py、daily_new.sh、import.php、apply_osb_categories.php、covers_to_r2.php、api/index.php、index.html)
3. 主機依 `crawler/README.md` 格子外面段落執行 probe → 全量 → 匯入 → 套分類 → 封面轉 R2

---

## [1.4.0] - 2026-08-17 —— 新增書目來源:衛理書房(methodistbookroom.com)

### 新增
- 衛理書房爬蟲 `crawler/methodist_crawler.py`:馬來西亞衛理公會書局,OpenCart 商城、全站 SSR、MYR 計價(本站第一個 MYR 來源)。分類樹由導覽選單動態解析(約 27 頂層 + 150 餘子分類,不寫死);商品分類歸屬由「清單走訪」蒐集(一書多分類,同以琳);商品頁以 route 正規網址抓取避免多網址重複快取;Product SKU 直接當 ISBN13(97x 開頭才認定,禮品為一般條碼不誤收);「详细资料」面板解析 作者/譯者/出版日期/頁數/尺寸/語言/裝訂/出版社原始分類;快取縮圖封面網址改寫回原圖
- 簡體處理沿微讀規則(8/9 決議):主欄位 OpenCC s2tw 轉繁入庫、原文存 extra.hans;is_hans 以「語言」欄位為準(8/12 教訓)
- 範圍(8/17 決議):全站抓入存證;非書(禮品/詩歌CD/桌遊/單張/會員費等)與外文書(英文/馬來文/印尼文,含外文聖經)由對映表下架(任一命中即下架,沿天恩規則)
- 雙軌分類:subjects(scheme='methodist', code=SEO slug 末段截 20 字)存證 + `methodist_category_map`(153 列,internal_name 可 NULL=僅存證)+ `tools/apply_methodist_categories.php`
- 每日新品:new_arrivals.py 新增 collect_methodist(導覽全分類 ?sort=p.date_added&order=DESC 日期倒序增量、無新品即停,8/17 實測排序參數有效);daily_new.sh 併入排程(六站)
- 匯入:import.php 支援 --source=methodist,購書連結「衛理書房」(簡體書標「衛理書房(簡體)」);api/index.php 購書排序 methodist=7;covers_to_r2 --source 支援 methodist + 衛理縮圖網址改寫候選

### 部署
- Navicat 先跑 `database/migrations/2026-08-17_methodist_category_map.sql`(執行前先 `SELECT name FROM categories` 核對表頭註解所列 18 個分類名皆存在)
- FTP 上傳:crawler/methodist_crawler.py、crawler/new_arrivals.py、crawler/daily_new.sh、tools/import.php、tools/apply_methodist_categories.php、tools/covers_to_r2.php、api/index.php、index.html、VERSION
- 主機:`methodist_crawler.py --probe` 貼回確認 → 全量(nohup)→ import(先 --dry-run)→ apply_methodist_categories(先 --dry-run)→ covers_to_r2 --source=methodist;cron 隔日自動跟進

---

## [1.3.0] - 2026-08-09 —— 新增書目來源:微讀書城(wdbook.com)

### 新增
- 微讀書城爬蟲 `crawler/wdbook_crawler.py`:WeDevote 純電子書店(USD),SSR 清單(/store/category/0「所有書籍」,上架新→舊)+ 商品頁 .info-area 欄位;全站約 2,022 件(8/9 決議:含免費電子書與套裝書),快取續跑;全站商品頁無 ISBN
- 簡體書處理(8/9 決議):書名/作者/譯者/出版社/簡介以 OpenCC s2t 轉繁體入主欄位(利跨站模糊合併與前台一致),原始簡體存 extra.hans;書名尾綴「(繁體版)/(簡體版)」去除後入檔(原名存 name_raw)
- 雙軌分類:subjects(scheme='wdbook', code=微讀分類 id)存證 + `wdbook_category_map`(鍵=分類 id;站方分類名稱隨語系浮動不可靠)+ `tools/apply_wdbook_categories.php`
- 每日新品:new_arrivals.py 新增 collect_wdbook(category/0 最新在前、無新品即停;「近期上架」頁為 JS widget 不採用,內容與 category/0 一致);daily_new.sh 併入排程
- 匯入:import.php 支援 --source=wdbook,全站視為電子書、與紙本同書合併(沿 8/6 天恩規則);購書連結「微讀書城」(簡體書標「微讀書城(簡體)」);api/index.php 購書排序 wdbook=6;covers_to_r2 --source 支援 wdbook
- crawler 依賴新增 opencc-python-reimplemented(簡繁轉換;未安裝時全量模式中止並提示)

### 部署
- Navicat 先跑 `database/migrations/2026-08-09_wdbook_category_map.sql`
- FTP 上傳:crawler/wdbook_crawler.py、crawler/new_arrivals.py、crawler/daily_new.sh、crawler/requirements.txt、tools/import.php、tools/apply_wdbook_categories.php、tools/covers_to_r2.php、api/index.php、index.html、VERSION
- 主機:venv `pip install "opencc-python-reimplemented>=0.1.7"` → `wdbook_crawler.py --probe` 貼回確認 → 全量 → import → apply_wdbook_categories → covers_to_r2 --source=wdbook

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
