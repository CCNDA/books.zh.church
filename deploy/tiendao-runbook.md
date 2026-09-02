# 天道書樓(tiendao)來源上線 Runbook(2026-09-01)

第十五個書目來源:**天道書樓** https://www.tiendao.org.hk/store/
(香港,1976,環球天道機構;OpenCart,HK$、繁體)。版號 **v1.11.0**。
海外第一批第 1 站 —— 這一站的流程要當成後面四站的樣板。

Asana:[待執行] 收錄第十五來源 1217984797715279

---

## 0. robots 裁示(9/1,熊哥)

    /robots.txt        HTTP 404          ← 網域根目錄,未宣告任何限制
    /store/robots.txt  HTTP 200, 26 bytes
        User-agent: *
        Disallow: /

robots.txt 僅在網域根目錄具效力(RFC 9309),`/store/` 那份按標準不被讀取、
內容亦像 OpenCart 安裝預設檔。**熊哥裁示採此解釋,照原計畫抓。**

仍維持的自我約束(已寫進爬蟲):

- 單執行緒、節流 2-3 秒、UA 具名並附 cowork@ccnda.org
- 只讀公開頁面,不登入、不下單、不碰任何寫入端點
- **連續 403/429 視為站方明示拒絕 → `SiteRefused` 直接中止整個作業並回報**,
  不重試、不換 UA、不繞道(`fetch()` 攔在共用 `polite_fetch` 之前)

---

## 1. 三項決議(9/1)

1. **抓取範圍**:全站抓入存證(含禮品/影音/單張小冊),非書由 `tiendao_category_map`
   於 apply 階段下架(任一命中即下架,沿天恩/衛理)。
   > ⚠ **9/1 修正**:原訂「再加 `product_type` 判非書」**作廢**。probe 實測一張心意卡的
   > `product_type` 也是 `Books` —— 那是 OpenCart 屬性群組名稱,全站共用,不是商品類型。
   > 替代線索:禮品的 `spec_all` 只有「出版商」一欄、無 ISBN、無語言、無頁數,
   > apply 階段可加一道「無 ISBN 且 spec 稀疏」複核。
2. **欄位解析深度**:本站規格表夠乾淨 → **全欄位解析入平面欄**,整表另存 `extra.spec_all`。
3. **來源分類依據**:清單走訪蒐集(一書多分類);分類 code 用**平台數字 path**
   (如 `73_75_62_92`),不用中文 slug(教會公報社撞碼教訓)。

---

## 2. 偵察已確認的事實(9/1 兩輪,非推測)

| 項目 | 實測結果 |
|---|---|
| 平台 | OpenCart(`route=product/` 721 處、`.product-layout`、`image/cache`) |
| 分頁 | `&limit=100` **生效**;超界回**空頁** → 空頁即停 |
| 不存在的 id | 回**真 404**,不是夾回首頁 → 可信任狀態碼 |
| 分類 | 導覽 **95 個**、sitemap 只有 86 → **以導覽為準**;深達 4 層 |
| 分類結構 | 樹根是 `73 網上購物`;105/106/140/62 等**同時掛頂層當捷徑 → 重複計數** |
| 父子關係 | 父分類**不含**子分類商品(`filter_sub_category=false`)→ 必須走完每個葉節點 |
| 全站量 | 各類加總 5,734 **含重複,不是全站量**;唯一商品估 1,300-1,600 |
| 對帳 | 站方自印「共 N 個 / 共 N 頁」→ 逐類比對(爬蟲已內建不符告警) |
| 規格表 | `#tab-specification` 乾淨 label\|value;**第一列是商品類型**(`Books`) |
| ISBN | **條碼欄 = 無連字號 ISBN13**;ISBN 欄是有連字號版 → 取條碼 |
| 版次 | 第一版 / 最新一版分開給 → 正好對上 Work/Edition |
| 語言 | 欄位直書「繁體中文」/「簡體」→ `is_hans` 有依據,不用字形猜 |
| 貨號與封面 | `Model: TD1406` → 封面 `image/catalog/cover/TD1406.jpg`,原圖改寫**實測 200 通過** |
| ⚠ 價格陷阱 | `.price` 會先命中頁尾「更多來自這個品牌」關聯商品(印出 180/115/150/150,本書實價 330)→ 爬蟲**先拆關聯區**再取價,並保留文字流保底。已驗證修正後取到 165/20/10 本書價 |
| ⚠ 佔位符 | 譯者欄「沒有」時填破折號,**全形半形混用**(`–` U+2013 / `-`)→ 不濾會生出叫「–」的假譯者。已加 `_clean()` |
| ⚠ 日期 | 第一版欄也混用 EN DASH(`2012–08` vs `2005-07`),兩種皆可解析 |
| ⚠ 簡介位置 | **有簡介**,但不在 `#tab-description` 而在 `.tab-content`(第一輪誤判為「整站無簡介」) |
| ⚠ 簡介結構 | 一整塊是「目錄 → 電子書導購句 → `[內容簡介]` → `[作者介紹]` → 點評小工具」黏在一起。直接當 summary 會讓書目頁開頭是數百行目錄,且「發表點評 請先 登錄」會進搜尋索引。爬蟲依方括號標題切段:summary 取 `[內容簡介]`、前導段當 toc、`[作者介紹]` 另存 |
| ⚠ 清理陷阱 | 「電子書平臺（請按圖示或連結以取得）」出現在 `[內容簡介]` **之前**,**不可當「切到結尾」標記**(第一版這樣寫,把整段書介刪光)。只有「發表點評+請先+登錄」完整序列才切到結尾 |
| 站方資料瑕疵 | 樣本 1 規格表作者「蔡金玲」vs 作者介紹「蔡金鈴」不一致;目錄有缺字(「以斯帖X宴款待王X哈曼」),原始資料就是 X |
| 待查 | `73_75_91 期刊` 讀不到「共 N 個」 |

---

## 3. 本批上傳與執行

### 檔案上傳對照表(FTP;本機 → 主機)

| 本機(repo 內) | 主機路徑 | 動作 |
|---|---|---|
| `web/crawler/tiendao_crawler.py` | `~/books/crawler/tiendao_crawler.py` | 新增 |

> `probe_tiendao.py` 任務結束,不必再跑。
> `tools/import.php`、`api/index.php`、`tools/covers_to_r2.php` 已改好(幣別對映表 + tiendao 白名單),
> 但**等 probe 驗過、要 import 時才同批上傳**。

### 執行:先 probe,不要直接開全量

```bash
cd ~/books/crawler
venv/bin/python tiendao_crawler.py --probe 2>&1 | tee logs/tiendao-crawl-probe.log
```

會印出選單檢查 + 三個樣本的完整 JSON(一般書 / 聖經 / 禮品)。**貼回對話確認**:

- 價格是不是本書的價(不是 180/115/150/150 那組關聯商品價)
- `isbn` 是不是 13 碼、`item_no` 是不是 TDxxxx、`cover_url` 是不是原圖
- `product_type` 有沒有抓到(書應為 `Books`,禮品應為別的)
- `publish_date` / `edition_statement` 是否分別對上「第一版」「最新一版」
- 繁簡欄位有沒有被 OpenCC 改壞

確認無誤後再:

```bash
venv/bin/python tiendao_crawler.py --limit 30 2>&1 | tee logs/tiendao-30.log   # 試跑
nohup venv/bin/python tiendao_crawler.py > logs/tiendao.log 2>&1 &             # 全量
```

全量分兩階段:先走 95 個分類的清單(約 15-25 分鐘,含逐類「共 N 個」對帳告警),
再抓每個商品頁。中斷可直接重跑,快取與 JSONL 都會續跑。

---

## 3.5 全量跑完後:量欄位長度(匯入前必做)

```bash
cd ~/books/crawler
python3 - <<'PY'
import json, collections
mx = collections.defaultdict(int); n = 0
for line in open('data/tiendao_books.jsonl', encoding='utf-8'):
    r = json.loads(line); n += 1
    for k, v in r.items():
        if isinstance(v, str):
            mx[k] = max(mx[k], len(v.encode('utf-8')))
        elif isinstance(v, (dict, list)):
            mx[k] = max(mx[k], len(json.dumps(v, ensure_ascii=False).encode('utf-8')))
print(f'{n} 筆')
for k, v in sorted(mx.items(), key=lambda x: -x[1]):
    print(f'  {k:<20}{v:>8} bytes')
PY
```

對照 schema 上限(**位元組**,中文一字約 3 bytes):
`title` 255、`publisher` 100、`series_text` 255、`language` 50、`binding` 50、
`item_no`(→identifiers)30、`source_url` 500、`summary` TEXT 65,535、
`extra` MEDIUMTEXT 16MB。`toc` 動輒數千字要特別看 —— 它會併進 extra。

## 4. 後續(全量跑完才做)

- [ ] 匯入前**量最長欄位值對照 schema 上限**(`summary`、`spec_all`、`source_url`)
- [ ] 分類對映 migration `2026-09-0X_tiendao_category_map.sql` + `tools/apply_tiendao_categories.php`
- [ ] `import.php --source=tiendao --dry-run` 看合併明細(**港書跨站合併率會很高**,
      細看 ISBN 命中 vs 模糊比對;站上另有 2,344 組 ISBN 撞號未清,Asana 1217969441137237)
- [ ] apply → classify → `covers_to_r2.php --source=tiendao`
- [ ] 對帳:站方逐類「共 N 個」去重後總數 vs 實收
- [ ] 併入 `new_arrivals.py` / `daily_new.sh`(**含 apply_map**)
- [ ] 發版 v1.11.0(`VERSION` + `index.html` footer 等正式上線當天才改,8/21 教訓)
- [ ] FTP 對照表逐項 `ls -l` 驗 → 線上抽查 10 筆(**含 HK$ 顯示**)→ 隔日 cron 驗證
- [ ] `git log -1` 與 `git ls-remote` 必驗(index.lock 教訓)
