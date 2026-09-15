# 更新紀錄(CHANGELOG)

本檔記錄 books.zh.church(CCNDA 屬靈共同書目)每一版的重點變更。
版本號採語意化版本(SemVer):`主版本.次版本.修訂`。首頁 footer 顯示至次版本(如 `v1.0`)。

- **主版本**:重大架構或功能里程碑(例:正規化正式版、管理後台上線)
- **次版本**:新增功能(例:訂閱、進階篩選、使用者書庫)
- **修訂**:修 bug、資料修正、小調整

發布流程:更新 `VERSION` → 在本檔新增版本區塊 → commit → 建 git tag `vX.Y.Z` → FTP 部署 → 同步首頁 footer 版號。

---

## [1.13.0] - 2026-09-14 —— 新增第十七來源:麥種傳道會(akow,美國)

海外第一批第 5 站,原排 v1.15.0;9/13 依實際開工順序重編為 v1.13.0
(海天書樓順延 v1.15.0,浸信會出版社維持 v1.14.0)。收錄 144 本,以聖經註釋與神學譯著為主。

### 新增

- `crawler/akow_crawler.py`:WooCommerce Store API 全量、probe、每日新品、分類對映表產生器、
  sitemap 對帳、五陷阱稽核報表。
- `tools/apply_akow_categories.php`、`database/migrations/2026-09-14_akow_category_map.sql`(46 列)。
- `new_arrivals.py` / `daily_new.sh` 併入 akow(`orderby=date` 倒序兩頁)。

### 本站與既有來源不同的三件事

- **票上兩個前提都是錯的**:原記「網路訂書是訂購頁不是購物車 → 只收書目、不掛購書連結」,
  實測是完整 WooCommerce 購物車(USD 結帳)→ **照常掛購書連結**;原記「有 JS 重定向牆 429」,
  主機 curl Store API 回 200,純 HTTP 可抓。
- **權威清單三方一致且零差集**:sitemap 144 = Store API 144 = shop 頁 144,URL 逐筆比對無差集。
  十七站以來第一次,因此本站每日新品不需要天道/突破那種每週全掃對帳。
- **分類兩層並用**:商品簡介裡另有比 15 個 product_cat 精確得多的站方細分類
  (「聖經論叢／詩篇」「神學類／救恩論」,32 種),以 `sub:` 前綴獨立成 subjects 列、
  `sort_order=110` 優先於 product_cat(5xx)。書系(光照/成長/焦點/種子/根基)不當主題,
  改寫入 `books.series`。簡繁兩種寫法各自登錄,不做字形轉換。

### 修正與強化(影響全站,不只本來源)

- **`tools/import.php` 新增 `ISBN_NOT_UNIQUE_SOURCES` 合併防線。**
  麥種母資料有「同一個 ISBN 掛在兩本不同書上」,且 akow.org 與 akow.tw 兩站一致
  → 是出版社母資料錯,不是單站手誤。實測四組:
  `9781951456115`《真正的快樂》/《現代神學精髓》、
  `9781939251176`《以西結書註釋(上下)》/《主耶穌的畫像》、
  `9781939251015`《舊約歷史書手冊》/《主耶穌的比喻》、
  `9781932184020`《喜樂平安的人生》/《當主耶穌面對世界》(後者已流進別家書店資料)。
  對這些來源,ISBN 命中須再通過「同一作品」判定(模糊鍵相同／一方包含另一方／
  最長共同子字串占短書名過半／簡繁同字數)才合併;判不出來不合併也不寫 isbn13,
  原值仍完整留在 `extra`。**其他十六站合併行為不變。**
- **ISBN 還原:原書號是「ISBN-13 去掉 978」而非 ISBN-10**(天道同族陷阱)。
  補 978 後檢查碼吻合即可確定性還原,17 本因此救回;ISBN 覆蓋率 23 → 51 本(35%),
  標籤有值卻還原不出 0 本。
- **出版日期混民國年**:`98040101` → 2009-04-01 等三本;另有 `20250431`(四月無 31 日)、
  九位數、雙冒號。西元優先、解不出才試民國,且一律標記推定方式供逐筆複核。
- **頁數多值不拆不加總**:`864+696`、`上774、下1724` 等保留原值於 `page_count_raw`。
- `tools/covers_to_r2.php`:`--source` 白名單補 `akow`;順帶補上自 v1.12.0 起就漏登的 `btproduct`。

### ★ 匯入後發現:模糊比對形同失效,九成「新書」其實是重複

線上抽查發現匯入工具報的「新書 100 本」裡,**有 95 本是站上已經有的書**
(《我不以為恥》《宣教士保羅》《聖經教牧學》書名一模一樣都沒併)。根因:

    // import.php fuzzy_key()
    if (!$title || !$firstAuthor) return null;   // 沒有作者就完全不比對

akow 只有 46% 的書有作者欄,站上既有的麥種書也有不少沒作者 ——
**任一邊缺作者,模糊比對就不會啟動**,必然長出新書。加上 akow 書名帶
「(正體)(繁)(正)」尾註且常用短書名(「大衛寶庫」vs 站上「大衛寶庫（1）：詩篇一至二十六篇」),
就算有作者也比不中。匯入時「模糊比對 0」的統計就是這個現象,當時未追下去。

處理:新增 `tools/find_akow_duplicates.php`(唯讀,產 review 清單)與
`merge_duplicate_books.php --pairs=FILE`(依人工複核過的配對合併,方向固定「新書→既有書」),
逐本複核 100 列後合併 95 組。

★ **`same_work()` 不能拿來找重複**:它當「否決條件」安全(ISBN 已指出候選),
當「搜尋條件」危險 —— 冊次剛好落在最長共同子字串忽略的字元上
(「每日效法基督1(正)」vs「每日效法基督：耶穌生平靈修365（3）」相似度 86%)。
find 工具因此另外比對冊次記號,對不上一律降為「待判」。

### 正體/簡體的處理原則(熊哥 2026-09-14 裁示)

**同 ISBN 就併、不同 ISBN 就拆。** 麥種的簡體版一律另編 ISBN(已知 4/4 案例:
耶穌所傳的福音 `...433`/`...662`、罪與恩典 `...594`/`...693`、
保羅的禱告 `...136`/`...792`、神的故事你的故事 `...501`/`...730`),
故簡繁各自獨立成書;少數站方給正簡兩版同一 ISBN 者(如麥種基督教要義)才併為一本兩版。
購書連結平台名加註「(正體)/(簡體)」以資識別。

### 書房家數更正

v1.12.0 的 release-notes 寫「共十六家」但只列了 15 個名字。依 2026-09-14 資料庫實查
(`SELECT e.source … GROUP BY e.source`),當時確為**十五家**,數字錯、名單對;
該篇已更正為十五家。加上麥種之後才真的是十六家。

★ 提醒:`release-notes/` **必須 FTP 到主機** —— `tools/notify_discord.php` 讀的是主機上的
`release-notes/v{VERSION}.md`,沒上傳就發不出公告。另該檔內容會**原樣送到 Discord**,
所以裡面不要寫內部檢討或 HTML 註解(本版一度寫了,發布前移除)。

### 數字(資料庫實查,非工具輸出)

匯入 144 個版本 → 合併重複後,站上**新增 5 種書、135 種補上麥種購書連結與美金定價**;
封面轉存 100/100 成功、0 失敗;站方分類套用 139 本(細分類決定 39 本、product_cat 決定 54 本、
46 本無主題分類交關鍵字分類器,落入「綜合其他」僅 1 本)。

---

## [1.12.1] - 2026-09-09 —— 修:單書分類覆蓋對不到書、多作者切割兩缺陷

v1.12.0 上線後首次線上抽查(9/8)發現三個缺陷,都在同一次調查裡追出來。
本版只動 `tools/` 底下的離線工具與資料,**沒有動網站程式**,首頁 footer 維持 `v1.12`。

### 修正

- **`btproduct_book_override` 19 筆裡有 12 筆 pid 抄錯**(9/5 逐本判讀「宗教」16 本時抄錯)。
  錯 pid 造成雙重傷害:被錯指的書分類被蓋錯、真正該覆蓋的書落回關鍵字猜測。
  實證:pid `29152561` 表上記「結連,一輩子」,實際指向「「未完成」套裝」(站方分類社會文化),
  被蓋成「聖經研究」。12 筆正確 pid 已逐本以線上資料查證後更新。

- **`tools/apply_btproduct_categories.php`:單書覆蓋對跨站合併書完全無效。**
  覆蓋被「跨站合併書不改 primary」的規則擋住 —— 16 本宗教書裡有 15 本是合併書,
  等於覆蓋機制對絕大多數目標沒作用。新增 `$forcePrim`:命中覆蓋表且有指定分類時
  強制改寫 `books.category_id`,但**不刪別站貢獻的 cat 標籤**(那是 `--override-all` 的行為),
  只改 primary 並追加自己的標籤。

- **`tools/import.php` `split_names()` 兩個缺陷**(影響全部十六個來源,不只突破機構):
  1. 字元集 hexdump 是 `5b 3b 3b e3 80 81 5d` = `[ ; ; 、 ]` —— **兩個半形分號、
     全形分號 U+FF1B 遺失**。以全形分號分隔的多作者從未被拆開(實測 47 本書、56 筆 person)。
     本案第二次踩到「含全形標點的 regex 會退化」(前例:全形冒號 `[:：]` → `[::]`)。
  2. 切割不看括號,把「Breakazine 創作小組 (彼、桀、onki、gi)」拆成四個 person。
     新增 `split_delims()` 做括號感知切割;括號不成對時印警告並保守退回直接切割。
  **兩處分隔符與括號一律改以 PHP `\u{}` escape 書寫**(純 ASCII,不會再退化)。

- `tools/merge_duplicate_books.php:53` 同一個全形分號退化,一併補回。

### 影響數字(皆回查資料庫/線上 API 確認,非工具自印)

- 分類修正 **20 本**:7 本被錯誤覆蓋汙染的已回到正確分類、13 本該覆蓋而未覆蓋的已生效。
- apply 重跑後 `合併書強制改 primary:15`;下架 19(教科書 16 + 站方測試資料 3)維持不變。
- 全站分類分布未受影響:文學 210、社會 152、心理 131、青少年家庭 50。

### 教訓

- **dry-run 報表一直在說謊**:`$primary = $mapped[0]` 從一開始就把覆蓋目標算進分布統計,
  不管後面有沒有真的寫進資料庫 —— 所以 9/5 跑 dry-run 看到的是「覆蓋已生效」的樣子。
  又一個「不要拿工具自印的數字當證據」的實例。
- **抄寫型 override 表需要防呆**:apply 應比對 pid 對到的書名與表上書名,不符就報警而非靜默覆蓋。
- 含全形標點的 regex 一律以 `\u{}` escape 書寫,並在發版前 hexdump 驗位元組。

---

## [1.12.0] - 2026-09-05 —— 新增第十六個來源:突破機構(香港,海外第二家)

香港突破機構(Breakthrough,1973 創立的青少年文化機構)/tc/book/,PHP 自建站,HKD,繁體。
**全站書籍 583 種**,是站上最小的一家。海外第一批第 2 站。

### 新增

- `crawler/btproduct_crawler.py`:清單、商品頁、封面、對帳一支到底。
- `database/migrations/2026-09-05_btproduct_category_map.sql`:
  `btproduct_category_map` 43 列(cat 主題 21 + ser 系列 22)
  + **`btproduct_book_override` 19 列**(單書分類覆蓋,新機制)。
- `tools/apply_btproduct_categories.php`:對映吃兩軸 + 單書覆蓋。
- `new_arrivals.py` 加 `collect_btproduct()`;`daily_new.sh` 加 btproduct(**週三 --full-scan 對帳**)。

### ★★ 權威清單是「空關鍵字查詢」

`product_list.php?product_key=`(關鍵字留空)走完分頁 = 全站 583 本。
第二本帳 `rss_book.php?lang=tc` 也是 583,兩本互相獨立卻一致。
而 cat + ser 兩軸聯集只有 **575,單向漏 8 本**(沒掛任何分類的孤兒書)——
靠走訪分類永遠抓不到,而且 log 全綠,正是基道七月漏 22.8% 的同一形狀。
本站**沒有 sitemap.xml**,所以「權威清單 vs cat/ser 聯集」是唯一的自我檢查,已內建為 `--reconcile`。

### ★★ 分頁超界會「篩選失效」,不是回空頁

分頁參數是 `page_num`(page/p/offset/start 全部靜默無效),每頁 **21 筆**(不是 20)。
超界時**整個 cat 篩選失效,退化成全站最新書籍 21 筆,照樣標「第1頁」、照樣帶「下一頁」**。
用「滿 21 筆就續抓」判斷會無限迴圈 + 把別類的書灌進這一類。
終止條件雙保險:①有「下一頁」才續抓 ②回來的「第N頁」標記須等於請求頁碼。

### 其他站方特性

- 商品頁是**兩欄表格**,label 格內只有「書名 :」值在下一格 → 一律走 td 配對,行尾式 regex 會全抓到空字串。
- 分類與系列是**多值**(`,` 分隔),已拆為多筆;分類碼用平台數字 id,系列前綴 `S`。
- ISBN 欄格式至少四種(含 ISBN10 與產品編號式斷字),**一律驗檢查碼**,驗不過只存證不寫號(4 本)。
- 書名混有庫存標記「(斷貨)(斷版)(少量)(極少)」共 72 本,**不清洗書名**,另記 `availability`。
- 站方測試資料(書名純數字)3 本寫入 `data/btproduct_suspect.tsv` 並下架,不靜默丟棄。
- 封面走 `/get_photo/?i=…&l=1000&m=w`(實測可取大圖)。

### ★ 第一個沒有線上購書的來源

商品頁無購物車(`buy.php` 只是批發聯絡資訊)。熊哥裁示:不產生購書連結,
商品頁網址以一般連結放在書籍資訊區 → `import.php` 新增 `NO_BUY_SOURCES`,
對這類來源寫 `links.link_type='official'` 而非 `'buy'`,
**並同步排除 `books.buy_links` 平面欄位** —— 只改 `links` 不夠,
`api/index.php` 的購書彙整是兩邊聯集,漏掉平面欄位仍會長出假的購書按鈕。
浸信會(bappress)、麥種(akow)沿用;海外共通決議 B 項(無購書連結的說明文字)因此不需要了。

### 資料

- 匯入:讀 583 → **新書 79、跨站合併 504**;無效 0、跳過 0、無欄位溢位。
- 合併明細:**ISBN 命中 498、模糊 6**(模糊比對 6 筆全部正確)。
  → 原本擔心 72 本庫存標記會害跨站合併失敗,實測 ISBN 命中率 85%,fuzzy 幾乎沒被用到,**不動比對邏輯**。
- 分類:單書覆蓋命中 19、分類完全靠系列軸 15、仍無對映 16、**下架 19**
  (btproduct-only 教科書 16 + 測試資料 3;跨站合併的不下架,`is_published` 在作品層)。
- primary 分布(上架 564):文學 291、社會 74、門徒造就 69、青少年家庭 38、心理 27、
  綜合其他 23、見證 13、聖經研究 10、教會復興 9…
- 封面轉存 R2:**560 / 564 = 99.3%**(4 本因同書另有基道失效封面排在前面而卡住,歸 1217982001242514)。
- 連結型別:**official 583、buy 0**。

---

## [1.11.1] - 2026-09-04 —— 基道分類對照併入每日流程,修掉三個「靜默漏抓」

站台功能無異動;本版全部是排程與爬蟲的可靠性修補。起因是基道新書分類被關鍵字亂猜
(CR306《離散激宕．站穩向前:香港教會研究2024》被歸到「青少年家庭」)。

### 新增

- `crawler/logos_cat_refresh.sh`:基道官網分類對照重爬的排程包裝。`python3 -u`、
  `timeout -s INT 21600`(6 小時,用 SIGINT 才會保存進度)、`flock -n` 單一實例鎖、
  逾時在 log 直接印補跑指令。cron 排**週二 22:00**(避開週一天道 --full-scan 與每日 06:30)。
- `crawler/daily_new.sh` 的 apply_map 加入基道 `apply_logos_categories.php`(12 → 13 站)。
- `logos_categories.py` 新增 `--resume`(繞過快取但保留進度)、`--max-age DAYS`(預設 7,
  無時間戳的舊格式進度一律重抓)、`--only` 可指定完整子分類路徑;進度每類記 `fetched_at`。

### 修正(都是「log 全綠但資料短少」那一類)

- **偶發空清單頁靜默吃掉一整頁**:站方會回「有『找到 N 項』但零商品」的頁面,
  `is_valid_list_page()` 認得 total 就不重抓 → 一次少 20 筆。改為 0 碼時先強制重抓該頁一次。
  實測:其他/期刊／主日學教材 713/733 → **733/733**;教會事工/差傳／佈道 670/690 → **690/690**。
  (兩次重跑觸發的是不同頁,證實是隨機而非特定壞頁。)
- **空分類被誤判為抓取失敗**:`bool(extract_total(...))` 對「找到 0 項」是 falsy。
  以瀏覽器讀選單複核:「童書」在站方選單裡存在但站方自己回 0 項,是空分類不是失敗。
- **nohup/cron 下 log 空白**:Python 對重導向 stdout 的塊緩衝讓進度訊息卡到程式結束才吐,
  一度誤判重爬卡死。程式改行緩衝,`daily_new.sh` 的 `new_arrivals.py` 也改 `python3 -u`。
- 清單頁重試上限降為 2(原本 5 次×60 秒退避,單頁最壞 15 分鐘);force 時不再重複抓第二次。
- 空頁、提早結束、每類涵蓋率不足 98% 都會出聲。

### 新增(對帳與補抓)

- `tools/reconcile_logos_codes.php`:拿站方分類清單的權威商品碼比對資料庫,三方對帳
  (站方有 DB 沒有 / DB 有站方沒有 / 兩邊都有),產出 TSV 與待補抓商品碼清單。
- `crawler/recover_logos.py`:依商品碼清單補抓,共用 `logos_crawler.parse_product`,
  可續跑,結尾強制印出 import 指令(手動跑爬蟲後忘了匯入 = 書靜默消失,8/28 踩過)。

### 資料

- 對照檔重建:64 類、**29,725 個商品碼**。
- **首次對帳發現漏抓 7,801 本**:資料庫 22,950 ÷ 站方 29,725 = **77.2%**,正好對上
  8/28 量到的「涵蓋率 77.8%」—— 那次只修了每日新品路徑(改 `sort=Code` 唯一鍵),
  七月全站建檔用的年份錨定清單從未回頭補,這是那個 bug 的欠帳。
- 補抓 7,791 本(10 本解析不到書名,已寫入 `data/logos_parse_failed.tsv`),
  匯入後**新書 5,836、跨站合併 1,955**。
- apply 對照到書 21,429 → **29,128 本**(logos-only 21,814、跨站合併 7,314),多分類 4,928。
- **未對照商品碼 8,236 → 79**(10 個解析失敗 + 69 個非書品項),降 99%。
- classify 待處理 0 本 —— 新書分類全部由官網對映就位,沒有一本落到關鍵字猜測。

## [1.11.0] - 2026-09-02 —— 新增第十五個來源:天道書樓(香港,海外第一家)

海外第一批五站的第 1 站。香港環球天道機構,OpenCart,HKD 定價,繁體。來源代碼 `tiendao`。

### 新增

- **天道書樓 1,233 件全站收錄**:新書 389 本、與既有書合併 844 本(合併率 68.5%,
  台灣書房已代理大量港書)、非書(禮品/影音/單張小冊)162 本由對映表下架。
- `crawler/tiendao_crawler.py` OpenCart 爬蟲。清單頁 `&limit=100`、超界回空頁故以
  「空頁即停」;導覽選單 95 個分類(sitemap 只有 86,以導覽為準),頂層有捷徑重複掛載、
  父類不含子類商品,故各類加總 5,734 含重複,**不是全站量**,須以 product_id 去重。
- `crawler/tiendao_isbn.py` **ISBN 三段式判定與 language 正規化的單一實作**,
  爬蟲與離線修正工具共用,避免兩套邏輯漂移。
- `crawler/verify_tiendao.py` / `diag_tiendao.py` / `fix_tiendao_isbn.py`
  對帳、定點診斷、離線修正三支工具。
- `database/migrations/2026-09-01_tiendao_category_map.sql` 95 筆對映
  (21 筆下架、37 筆僅存證);`tools/apply_tiendao_categories.php`。
- 每日新品接 `path=105`「最新出版」快路徑,偵測到新 pid 才走全分類取主題歸屬;
  **每週一改走 `--full-scan` 全分類對帳**(105 是站方手動維護的推薦位,
  離線檢定顯示全站出版日最新 30 本 100% 在內、但最新 60 本僅 88.3%,
  單靠它會永久漏掉「上架日新、出版日舊」的書)。
- `tools/import.php` 幣別改為 `SOURCE_CURRENCY` 對映表(爬蟲值優先、表當 fallback),
  移除只認 `logos=HKD` 的硬編碼三元式;購書平台名稱漏登由靜默產生空標題鈕改為當場中止。

### 修復

- **`tiendao_crawler` 的 ISBN 判定只檢格式不驗檢查碼**,導致 63 筆錯號被採用。
  根因是站方把舊書 ISBN10 機械加上 `978` 前綴卻沒重算檢查碼。改為三段式:
  有效 ISBN13 直採 / 有效 ISBN10 標準轉換 / 去 978 後為有效 ISBN10 則還原檢查碼
  (ISBN10 的 mod-11 保證前 9 碼正確,故還原是確定性的);兩欄各自驗證通過但答案
  不同則標 conflict 不寫號。修正後檢查碼不合 63 → **0**,救回 67 筆。
- **福音單張 TGP001-031 的 ISBN 與天註書逐一撞號**(站方在 ISBN 欄貼了一串連號)。
  若照原值匯入,`import.php` 會把 HK$16 的單張與 HK$160 的天註書併成同一個 Work,
  而流程是 import → apply,下架發生在合併之後、事後拆不開。改為匯入前清空並存證。
- `classify_categories.php:244`/`:299` 的 `echo "已處理 $done…"` —— 「…」(U+2026)
  位元組 `E2 80 A6` 全落在 PHP 變數名允許的 `\x80-\xff`,PHP 把 `$done…` 整個當成
  變數名 → Undefined variable、進度數字印不出來。改為 `{$done}`。
- `classify_categories.php:209` 的 `--orphans` 沒有專屬進度標籤,落到 else 分支印出
  「(僅未分類)」,與實際 WHERE(無 campus/logos 存證的**全部**上架書)不符。已改為印實際語意。
- `tools/covers_to_r2.php` 新增 `encode_url_path()`:只編 path 段、query 原樣、
  已編碼過的不再編(雙重編碼會把 `%E9` 變 `%25E9` 取回 404)。

### 已知待處理

- ISBN 仍無法驗證者 6 筆、兩欄衝突 1 筆(pid 501 賽氏簡明註釋,候選差在 978/979 前綴),
  已存 `extra.isbn_invalid` / `isbn_conflict`,清單交內容專案人工核對。
- 站內同 ISBN 保留 2 組,經查證均為**正確合併**(耶利米書註釋改版換名、走出黑洞補副標新版)。

---

## [1.10.1] - 2026-08-29 —— 修復:基道每日新書靜默失效、商品頁欄位從未解析

修 bug 與資料修正,無新功能、無新來源。起因是熊哥在基道站看到一本未收錄的新書
(CR306《離散激宕．站穩向前:香港教會研究2024》),追下去挖出四層問題。

### 修復

- **基道每日新書自 cron 上線起靜默失效約六週**。`new_arrivals.collect_logos()` 抓列表頁
  未帶 `force`,`common.polite_fetch` 命中磁碟快取直接回傳 → 每天讀同一份第 1 頁快照 →
  書碼全在 `writer.seen` → 早停 → 天天回報「無新書」而 log 全綠。
  其餘十二站的 collector 都有 `force=True`,只有基道漏掉。
  同款陷阱第二處:`logos_categories.py` 的 `--refresh` 只清進度檔不清頁面快取,一併修正。
- **早停對基道本來就不成立**。站方按「出版日期」而非上架日期倒序,新上架的舊書會落在
  清單深處(實測第 197 頁仍有首見的書)→ 改為**全掃列表不早停**;新增 `--min-pages` 省時模式。
- **分頁排序鍵並列造成 22% 資料從不顯示**。`sort=FirstPublishDate` 大量同日期,ASP 以 offset
  取頁時並列群組順序任意 → 相鄰頁互相重複。離線對帳:3,934 格 = 去重 3,061 + 重複 873(差 0)。
  → 改用唯一鍵 `sort=Code`,涵蓋率 **77.8% → 99.5%**、重複歸零。
  另加「整趟掃完後補抓失敗頁」與涵蓋率告警。
- **基道商品頁欄位從建站以來一次都沒解析到**。產品資訊由 `<script>` 的 `document.write`
  動態寫出,BeautifulSoup 不執行 JS,`ISBN：`/`出版日期：`/`頁數：` 等正則全部落空。
  → 新增 `logos_crawler.parse_info_script()` 直接對原始 HTML 抽鍵值對。

### 資料修正(基道 22,855 筆)

| 欄位 | 前 | 後 |
|---|---|---|
| publish_date 為 NULL | 約 22,900 | **78** |
| ISBN 覆蓋率 | 62.1% | **93.9%** |

另補回 page_count 21,351、dimensions 17,882、weight 13,359、translators_raw 9,616;
identifiers 21,179 列;補收 8/28-29 漏掉的新書 90 本(含 CR306)。

### 新增工具

- `crawler/reparse_logos.py` — 離線重解析(不發網路請求,只讀商品頁快取),只補空欄位
- `tools/backfill_logos_fields.php` — 把補回的欄位寫進資料庫,全走 `COALESCE` 只補空、冪等;
  **刻意不做合併**,只列出撞號清單供複核
- `tools/lib_isbn.php` — ISBN 正規化共用檔(`function_exists` 包住,未動 import.php)
- `crawler/logos_coverage.py` — 離線對帳分頁涵蓋率(讀快取,不發請求)

### 陷阱與教訓

- **★日期未補零會被 `parse_date()` 毀掉**:基道顯示「2026-7-15」,`import.php` 的
  `parse_date()` 先刪非數字再切 → `2026715`(7 碼)→ 月份變成 **71**。
  已加 `logos_crawler.norm_date()` 在來源端補零。
  **`parse_date()` 這個弱點對其他來源仍在,尚未修**(改它會動到十三站)。
- **「無新書」不等於「沒問題」**。靠快取的爬蟲,列表頁一律 `force`;
  告警要含「連續 N 天零新書」這類靜默指標,只看錯誤永遠抓不到。
- **接新來源前先確認站方排序依據**(上架日期 vs 出版日期)**與排序鍵是否唯一**。
  排序鍵有並列 + offset 分頁 = 靜默漏資料,對帳之前完全看不出來。
- **★不要拿工具自印的數字當證據**。回填工具兩個計數器都誤報,兩次都是查資料庫才知道真相:
  `rowCount()` 對 `INSERT IGNORE` 在本專案 PDO 設定下一律回 0(報「新增 0 列」、實際 21,179 列);
  `extra 補 spec_all` 報 113 本、實際 29,346 本(**此項原因未查明,誠實記錄**)。
  往後批次工具的統計,結尾一律直接查 DB 對帳。
- **PHP 全形字元陷阱再現**:`"已處理 $done…"` —— PHP 識別字允許 0x80-0xFF,全形刪節號
  被吃進變數名。變數插值一律 `{$var}`。(微讀書城那次已記過,仍再犯一次。)
- **驗證解析正確性的好方法**:拿 14,196 筆「商品碼本身就是 ISBN13」當獨立對照組,
  比對新解析器抓到的 ISBN 是否等於商品碼 → 一致率 97.88%。

### 已知待辦(已開工單)

- master jsonl 與資料庫無對帳機制:手動跑爬蟲未跑 import 會靜默吃掉整批書(1217967706140716)
- 補回 ISBN 後浮現 2,344 組 ISBN 撞號,合併另議
- 139 筆「商品碼 ISBN ≠ 頁面 ISBN」待人工複核(頁面值保留在 `extra.spec_all`)
- 基道官方分類未併入 `daily_new.sh` 的 apply_map,新書仍靠關鍵字猜(1216651317073747)
- `covers_to_r2` 積壓 589 筆失敗(1217982001242514)

---

## [1.10.0] - 2026-08-27 —— 新增書目來源:台灣教會公報社(buy.pctpress.org)

### 上線實績(8/27)
- Store API 走訪 **4,531 件**全數入檔(46 頁,抓取失敗 0)
- 匯入:新書 2,279、跨站合併 2,252(**ISBN 命中 2,129 = 94.7%** / 模糊 113 / 同檔 7)、版本 4,531
- apply:非書與促銷品下架 **1,150**;classify 補完未分類 → 未分類歸零
- 封面轉 R2 2,136 張(1 張失敗:中文檔名 `上主的家庭.jpg` HTTP 無回應,重跑會再試)
- 站上新增最大的一塊:台灣教會史料、台文/白話字出版品、主日學教材、香港代理書(基督教文藝、道風、天道、麥種)

### 新增
- 教會公報社爬蟲 `crawler/pctpress_crawler.py`:**第一個 WooCommerce 來源**(WordPress 6.9.4 + WooCommerce 10.8.1 + Enfold)。**走公開 Store API**(`/wp-json/wc/store/v1/products`、`/products/categories`)——商品 JSON 自帶 categories(一書多分類,不必逐分類走訪)、分類 API 自帶 count(對帳零猜測)、`sku`/`prices`/`images` 結構化;`description` 是標籤行(定價/作者/ISBN/出版日期)。全量只要幾分鐘,是目前十四站中最乾淨的來源
- **對映表由程式產生**:`pctpress_crawler.py --emit-map-sql` 依內建 `CAT_MAP`(129 筆)輸出 migration SQL,並把「站方新增而未對映」的分類列在檔尾 → 不必手打 129 列 id,日後站方加分類也會自動被抓出來
- 雙軌分類:`pctpress_category_map`(129 列 = 站台分類數,未對映 0)+ `tools/apply_pctpress_categories.php`
- 台語/客語/原民語出版品自動標 `language`(nan/hak/map),依分類 slug 或書名判斷——這是本站獨有、其他十三站沒有的一塊
- 每日新品:`new_arrivals.py --source pctpress`(Store API 取「書籍NEW」分類,一次就有完整欄位)+ `daily_new.sh` 十四站

### 變更
- `tools/import.php`、`api/index.php`(購書平台「教會公報社」排序 14)、`tools/covers_to_r2.php` 白名單加 pctpress

### 陷阱與教訓
- **父分類的 count 含子孫商品**,而商品只列「實際指派」的分類 → 父分類「抓到 < 站方」是正常的,對帳只能看葉分類。報表已分開統計「葉分類不符」,那個數字才要為零
- **★中文 slug 截斷會撞碼**:兩個不同分類同名「生命教育教材」,percent-encoded slug 截 40 字後完全相同,導致 43+8 兩類被併成 51 → **分類代碼改用 WooCommerce 分類 id(數字)**,slug 只留給人讀
- **抽樣偏差二度重演**:probe 抽樣預設取最新上架,前八件全是月曆與畫框,ISBN 只有 2/8,差點又誤判「這站沒 ISBN」。改成固定從「書籍」分類抽樣後是 5/8,全站實際 ISBN 命中 94.7%。**已把「抽樣抽主題大類」寫進 probe 程式,不再只靠記憶**
- 站方用「不提供」當佔位值(formatted_dimensions/weight),不可當有值;描述含 HTML 實體(`&#215;`)要 unescape;繪本用「文:/圖:」而非「作者:」;ISBN 標籤也可能寫成「國際條碼」
- **部署順序踩坑**:apply 工具漏傳主機,匯入後直接跑 classify,導致 1,150 件非書(紅包袋/春聯/程序單/月曆/CD)一度上架 → 補傳後 apply 修正。教訓:**FTP 對照表要逐項確認,匯入前先 `ls -l` 驗檔案在不在**

---

## [1.9.0] - 2026-08-25 —— 新增書目來源:臺灣福音書房(www.twgbr.org.tw)

### 上線實績(8/25)
- 走訪 51 個分類 → 不重複商品 **1,661 件**全數入檔(抓取失敗 0、非商品頁 0);分類件數與站方頁面逐一吻合
- 匯入:新書 1,455、跨站合併 206(ISBN 命中 112 / 模糊比對 65 / 同檔內重複 17)、版本 1,661
- apply 1,629 本:多分類 114、**非書與外文下架 415**、無主題分類 388 交 classify;未分類歸零
- primary 分布:門徒造就 555 / 聖經 306 / 見證 193 / 兒童教材 124 / 福音 50 / 詩本樂譜 13
- 封面轉 R2 1,042 張

### 新增
- 福音書房爬蟲 `crawler/twgbr_crawler.py`:**第一個 Shopline 來源**(TWD、繁體),網址走 locale 前綴 `/zh-hant/`,分類頁 SSR 可解析且 `?limit=72` 生效
- 站台三陷阱(寫入爬蟲檔頭):①**sitemap 不是權威清單**(索引檔三分片,zh-hant 分片只有約 30 個 products,全站 1,661 件根本沒進去)→ 清單走分類聯集;②**超界頁是空頁 + 文案「抱歉,這個商品類別沒有相關商品」**(不是 404、也不像真哪噠夾回末頁);③**中文 handle 編碼不對時站方回首頁且 HTTP 200** → 每頁以 og:type/JSON-LD/商品區塊三個訊號驗「真的是商品頁」
- **假商品格自動偵測**:每個清單頁固定夾帶 Shopline 樣板連結 `{{item.product_id}}`(連空頁也有)→ 以「超界頁的商品連結集合」當雜訊基準自動扣除,不寫死選擇器。扣除後件數與站方標示完全吻合
- 欄位解析:JSON-LD Product 優先(name/sku/description/offers)+ 頁面 `NT$` 對抓定價/售價;書名前置商品編號切成 `item_no`(含 `E4351-1` 這類英文版編號);簡介原是「著者：X ■ 簡介… ■ 目錄…」一整塊 → 拆成 summary / 目錄(存 extra)/ 著者;規格採集排除頁尾地址與客服電話
- ISBN:8/25 probe3 更正——**本站商品頁其實有 ISBN**(抽樣 8 本中 7 本,檢查碼全通過),故合併走「ISBN 優先、無 ISBN 才模糊比對」;模糊 65 筆逐筆檢視皆為書名與作者完全相同(李常受、倪柝聲、編輯部),無誤併
- 雙軌分類:`twgbr_category_map`(51 列 = 站台分類數)+ `tools/apply_twgbr_categories.php`
- 範圍決議(8/24):**全站抓入存證**,影音/電子/用品由對映表 unpublish;**外文書(英文/日語/其他語言六個分類)抓入但下架**(沿衛理書房)
- 每日新品:`new_arrivals.py --source twgbr`(先走「新品推介」,有新書才補走全部分類取歸屬)+ `daily_new.sh` 十三站

### 變更
- `tools/import.php`:白名單加 twgbr、購書連結標「福音書房」;**分類代碼 cap 20→40**(subjects.code 已加寬,原截斷讓 24 碼 ID handle 對不上對映表)
- `api/index.php`:`BUY_PLATFORM` 加福音書房(排序 13)
- `tools/covers_to_r2.php`:`--source` 白名單加 twgbr
- **`database/migrations/2026-08-25_widen_subjects_code.sql`**:`subjects.code` 20→40。福音書房「精選商品」的 handle 是 24 碼 ID,原欄寬會在匯入時炸 `1406 Data too long`——**這次在匯入前的「最長欄位值檢查」就攔到,沒有中斷匯入**(真哪噠 8/23 的教訓生效)
- 版號:本版取 v1.9.0(教會公報社因站方 robots.txt 全站禁止抓取而暫停,不占版號)

### 教訓
- **抽樣要抽對地方**:第一輪抽樣抽到「新品推介」(半年度訓練綱要、晨興聖言等內部出版品,本來就沒書號),誤判成「這站沒有 ISBN」,連合併策略都因此定錯;改抽一般書籍大類後才看到真相。probe 的抽樣分類要挑主題大類,不要挑促銷彙整

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
