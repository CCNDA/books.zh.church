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
