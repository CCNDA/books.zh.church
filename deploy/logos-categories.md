# 基道(logos)官方分類重判 —— 部署與執行手冊

**建立:2026-07-17** ｜ 目的:以基道官網「分類」選單的權威歸類,取代 logos 書
`category_id` 的關鍵字猜測,並永久保留 logos 原生階層分類(scheme='logos')。

## 背景

logos 22,855 本書以「出版年檢索」爬取,**無來源分類**,匯入後 `category_id`
只能靠 `tools/classify_categories.php` 關鍵字猜測。官網頂部選單有 16 個主題主分類
(部分帶子分類 ▾),`content.asp?...&field=Category&text=<路徑>`,是出版社/店家的
權威歸類。本流程只抓**分類 listing 頁**(不重抓 2.2 萬本商品頁),建立
「商品碼 → 官方分類路徑」對照後套用到 DB。

## 產出檔案

| 檔案 | 說明 |
|---|---|
| `crawler/logos_categories.py` | 分類 listing 爬蟲,產出 code→分類對照 |
| `database/migrations/2026-07-17_logos_category_map.sql` | 建 `logos_category_map`(16 主類→站內分類,可 Navicat 調整) |
| `tools/apply_logos_categories.php` | 讀對照檔套用到 DB(原生存證 + 對映瀏覽分類) |

## 執行順序

### 0. 前置(先確認已跑過)
- `database/migrations/2026-07-17_categories_extend.sql`(擴充分類已建齊)。

### 1. 爬取分類對照(主機或本機皆可;host 端建議 nohup/screen)

**本機 Windows(PowerShell)** —— 沿用先前跑 campus/logos 爬蟲的 Python,不需啟用 venv:
```powershell
cd Z:\DD-code\books.zh.church\web\crawler
python -X utf8 logos_categories.py --discover   # 先看探索到的分類清單(主類+子類)
python -X utf8 logos_categories.py --probe      # 抓第一個分類首頁驗證解析
python -X utf8 logos_categories.py              # 全量(可 Ctrl+C 續跑;約 1000-1500 個 listing 頁)
# 若缺套件:python -m pip install requests beautifulsoup4 lxml
```

**主機 Linux(bash)** —— 沿用每日新品排程的 venv:
```bash
cd crawler
source venv/bin/activate            # venv 內含 requests/bs4/lxml
python -X utf8 logos_categories.py --discover
python -X utf8 logos_categories.py --probe
python -X utf8 logos_categories.py              # 全量(可 Ctrl+C 續跑)
```
產出 `crawler/data/`:
- `logos_categories.json`(各分類宣稱總數 + code 清單,續跑進度檔)
- `logos_code_categories.jsonl`(每 code 一列:paths / tops)

> 節流 2–3 秒 + gzip 快取,中斷重跑即續。與 logos_crawler 共用 `cache/logos/`。

### 2. 建對映表(Navicat 對遠端 DB 執行)
執行 `database/migrations/2026-07-17_logos_category_map.sql`。
內容面若要調整歸類,改 `logos_category_map.internal_name` 即可(無須改程式)。

### 3. 上傳並套用(FTP + 主機 CLI)
- FTP 上傳:`tools/apply_logos_categories.php`、`crawler/logos_categories.py`、
  以及 `crawler/data/logos_code_categories.jsonl`(若爬蟲在本機跑)。
```bash
php tools/apply_logos_categories.php --dry-run    # 先看主分類命中 + 對映後分布
php tools/apply_logos_categories.php              # 寫入
```

### 4. 驗證(Navicat / 線上)
```sql
SELECT COUNT(*) FROM subjects WHERE scheme='logos';        -- logos 原生分類數(>0)
SELECT c.name, COUNT(*) FROM books b
  JOIN categories c ON c.category_id=b.category_id
  WHERE b.is_published=1 GROUP BY c.name ORDER BY 2 DESC;   -- 瀏覽分類分布
```
線上:開分類頁確認基道書落到合理分類;開幾本基道書詳情頁對照官網分類。

## 套用規則(摘要)

- **對照商品**:`code → identifiers(id_type='STORE') → editions(source='logos') → books`。
- **原生存證**:每個官網分類路徑寫 `subjects(scheme='logos')` + `book_subjects`(永久保留)。
- **瀏覽分類(scheme='cat' + `books.category_id`)**:
  - **logos-only 書**(`books.source='logos'`):取代關鍵字猜測(刪舊 cat、寫對映分類、設 primary)。
  - **跨站合併書**(`books.source='campus'`,同 ISBN 已有校園分類):**不覆蓋**校園分類,
    只追加官網對映分類(兩邊皆可瀏覽)。`--override-all` 可強制取代(慎用)。
- **primary 優先序**:官網選單順序(神學／教義 → … → 其他)。
- 全程冪等,可重跑。

## 已知限制 / 後續

- 只抓 16 個**主題**主分類(排除二手書/暢銷榜/電子書/Top50/得獎推介/禮品等促銷/格式類)。
- 對映為第一版提案(見 `logos_category_map.note`);細分與逐本校正屬內容面/階段三。
- 子分類的細層瀏覽 UI 未接(本次先確保 `scheme='logos'` 資料就位)。
- 每日新品(cron)之後仍走關鍵字分類;如需 logos 新品即時帶官方分類,需於
  `new_arrivals.py` 補抓商品頁分類欄(排後續)。


---

## 每週自動重爬(2026-09-03 起)

### 為什麼要定期重爬

`tools/apply_logos_categories.php` 吃的是 `crawler/data/logos_code_categories.jsonl`,
而該檔只含「上次重爬時官網選單裡有的商品碼」。**新書的 code 不在檔內 → apply 撈不到 →
分類落回 `classify_categories.php` 的關鍵字猜測**(實例:CR306《離散激宕．站穩向前:
香港教會研究2024》被猜成「青少年家庭」)。所以光把 apply 加進每日排程不夠,對照檔本身要定期重爬。

### 排程(兩條分開)

```cron
30 6 * * *  /home/ubuntu/books/crawler/daily_new.sh          >/dev/null 2>&1   # 每日新品(含 apply 基道)
0  22 * * 2 /home/ubuntu/books/crawler/logos_cat_refresh.sh  >/dev/null 2>&1   # 每週二 22:00 重爬分類對照
```

重爬**刻意不放在 `daily_new.sh` 裡**:實測 3-6 小時,塞進 06:30 那條鏈會把 apply 與 classify 推到中午。
排**週二 22:00**(夜間、站方負載低),最遲清晨結束,隔天週三 06:30 的 apply 就吃得到當週最新對照檔;
也避開週一的天道 `--full-scan`。腳本有 `flock` 單一實例鎖 —— 9/3 實測兩支並行過,請求量直接翻倍。

### 實測時間(2026-09-03,`--refresh` 全量)

| 項目 | 實測 |
|---|---|
| 分類數 | **64**(16 主題主分類 + 48 子分類) |
| 頁面抓取速率 | **66 頁 / 5 分鐘 ≈ 4.5 秒/頁**(節流 2-3 秒 + 站方回應約 2 秒) |
| 第一類「神學／教義」 | 宣稱 3,363 項 → 約 169 頁 ≈ 13 分鐘 |
| 全站實測(2026-09-03) | 64 類跑完、**29,725 個不重複商品碼**、耗時 **356.7 分**(當時有兩支並行,單跑估 3-4 小時) |

★ 先前文件與交付訊息裡「約 50 分鐘」的估計是錯的(只算了 22,855 ÷ 20 頁,沒算父子重複)。

### 三個踩過的坑

1. **`nohup … > log` 看起來像卡死**:Python 對重導向的 stdout 預設 4-8KB 塊緩衝,而本程式每類
   只印 2 行 → 要跑到四十幾類才吐第一批。**判斷是否在跑不要看 log**,看這兩個:
   ```bash
   ls -l data/logos_categories.json            # 每完成一類 save_progress 一次,mtime 會跳
   find cache/logos -name '*.html.gz' -newermt '-5 min' | wc -l   # 健康值 60 上下
   ```
   已於 2026-09-03 修正:`logos_categories.py` 開頭改行緩衝,包裝腳本一律 `python3 -u`。
2. **退避會放大成整晚**:`common.polite_fetch` 對 429/5xx 等 60 秒×次數、最多 5 次(單頁最壞
   15 分鐘),`fetch_list` 失敗後又 force 抓一次,`crawl_category` 容忍 3 個空頁 →
   單一分類最壞靜默卡 1.5 小時。已改:清單頁 `max_retries=2`、force 時不重複抓、空頁與提早結束會出聲。
3. **中斷就得從頭**:原本只有 `--refresh`(清空進度)。已新增 `--resume`:同樣繞過頁面快取,
   但保留進度只補未完成分類。包裝腳本的 4 小時 `timeout -s INT` 也是走這條(SIGINT 才會保存進度)。

### 手動重爬與收尾

```bash
cd /home/ubuntu/books/crawler
./logos_cat_refresh.sh              # 全量;log 在 logs/logos-cat-refresh-YYYYMMDD.log
./logos_cat_refresh.sh --resume     # 中斷後補跑

cd /home/ubuntu/books
php tools/apply_logos_categories.php --dry-run   # 先看統計
php tools/apply_logos_categories.php             # 再寫入
```

收尾檢查:log 最後一行「完成 64 類、N 個商品碼」的 N 應在 22,855 量級或略多;
任何一類出現「[警告] 涵蓋率 xx%」都要複查該類。

### 2026-09-03 首次完整重爬的四個發現

1. **兩支並行**:`[1]` 與 `[3]` 兩個 job 跑同一道指令(手動重跑時舊的沒收掉),請求量翻倍,
   356.7 分有一半是自己造成的。→ `logos_cat_refresh.sh` 已加 `flock -n` 單一實例鎖。
2. **童書整類抓取失敗**:`[略過] 分類首頁抓取失敗:童書`,實得 0 碼 → 該類的書 apply 撈不到。
   補抓:`python3 -u logos_categories.py --only 童書 --refresh`
   (`--only` + `--refresh` 現在只重置指定的那幾類,不會清掉其他 63 類的進度)。
3. **多個大類固定少 1 筆**:社會／倫理 1564/1565、見證／傳記 1370/1371、文藝／勵志 2961/2962。
   跨分類一致的 -1,指向 `extract_codes()` 漏掉某一種連結寫法(商品碼含
   `[A-Za-z0-9-]` 以外字元?)或站方宣稱數本身多算一。**與追加待辦 B「清單第 166 頁少 1 筆」同一條線索**。
4. **其他/期刊／主日學教材 713/733(97.3%)少 20**,是唯一大幅短少的類,值得單獨重抓確認。

商品碼 29,725 > 7 月書目 22,855 屬合理:分類清單含影音、教會用品、單張等非書品項,以及七月後新品。
