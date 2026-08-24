# 宇宙光(cosmiccare)上線執行包 — v1.7.0

建立日:2026-08-21。來源:宇宙光全人關懷機構線上商城 `https://bookstore.cosmiccare.org/`
全站實測 **1,671 件**(書籍約 1,419、繪本 50、雜誌 120、影音 23、禮品 59)。

決議:全站抓入、非書由對映表下架;《宇宙光雜誌》收錄(新分類「期刊雜誌」);
作者系列 Tag 與★福利書不參與分類。

---

## 步驟 0(先做)FTP 上傳程式檔

本機 repo 根目錄 `Z:\DD-code\books.zh.church\web\` → 主機 `/home/ubuntu/books/`

| # | 本機完整路徑 | 主機完整路徑 | 說明 |
|---|---|---|---|
| 1 | `Z:\DD-code\books.zh.church\web\crawler\cosmiccare_crawler.py` | `/home/ubuntu/books/crawler/cosmiccare_crawler.py` | **新檔**,爬蟲 |
| 2 | `Z:\DD-code\books.zh.church\web\tools\apply_cosmiccare_categories.php` | `/home/ubuntu/books/tools/apply_cosmiccare_categories.php` | **新檔**,分類套用 |
| 3 | `Z:\DD-code\books.zh.church\web\tools\import.php` | `/home/ubuntu/books/tools/import.php` | 改:白名單 + 購書標籤「宇宙光」 |
| 4 | `Z:\DD-code\books.zh.church\web\tools\covers_to_r2.php` | `/home/ubuntu/books/tools/covers_to_r2.php` | 改:`--source` 白名單 |
| 5 | `Z:\DD-code\books.zh.church\web\crawler\new_arrivals.py` | `/home/ubuntu/books/crawler/new_arrivals.py` | 改:每日增量加宇宙光 |
| 6 | `Z:\DD-code\books.zh.church\web\crawler\daily_new.sh` | `/home/ubuntu/books/crawler/daily_new.sh` | 改:十站 + 八套 apply |
| 7 | `Z:\DD-code\books.zh.church\web\api\index.php` | `/home/ubuntu/books/api/index.php` | 改:購書平台「宇宙光」排序 11 |
| 8 | `Z:\DD-code\books.zh.church\web\VERSION` | `/home/ubuntu/books/VERSION` | 1.7.0 |
| 9 | `Z:\DD-code\books.zh.church\web\index.html` | `/home/ubuntu/books/index.html` | footer 版號 v1.7 — **等資料上線後再傳** |

**不用上傳**:
`database/migrations/2026-08-21_cosmiccare_category_map.sql`(走 Navicat)、
`CHANGELOG.md`、`crawler/README.md`、`deploy/cosmiccare-runbook.md`(本檔,文件而已)。

> 檔案 1–7 先傳(爬蟲與匯入要用);第 9 項 index.html 等步驟 6 完成、線上抽查沒問題再傳,
> 免得 footer 顯示 v1.7 但資料還沒進去。

---

## 步驟 1 探測(務必先跑,輸出貼回)

```bash
cd /home/ubuntu/books/crawler
venv/bin/python cosmiccare_crawler.py --probe 2>&1 | tee logs/cosmiccare-probe.log
```

**貼回整段輸出**,我會核對:

- 51 個分類是否都有件數(**0 件的分類就是網址錯了**——本站已知「真實故事」的 Tag 值前面有一個空格)
- 分頁是否有效(第 1 頁與第 2 頁的商品代碼不同)
- 四個樣本(書籍/繪本/雜誌/禮品)的書名、作者、ISBN、價格、封面、頁籤
- **雜誌樣本的 `isbn` 應該是空的、`isbn_raw` 是 977 開頭的 ISSN 條碼**(這是對的,期刊不該當 ISBN 合併)

---

## 步驟 2 Navicat 執行 migration

檔案:`Z:\DD-code\books.zh.church\web\database\migrations\2026-08-21_cosmiccare_category_map.sql`

這支會做兩件事:

1. **新增站內分類「期刊雜誌」**(categories code='T',排序 33)——收《宇宙光雜誌》用;
2. 建 `cosmiccare_category_map` 並寫入 51 列對映。

跑完驗證:

```sql
SELECT COUNT(*) FROM cosmiccare_category_map;      -- 應為 51
SELECT * FROM categories WHERE code = 'T';         -- 期刊雜誌
SELECT internal_name, COUNT(*) FROM cosmiccare_category_map
  GROUP BY internal_name ORDER BY 2 DESC;
```

> 沒跑這步就跑 apply,會顯示「對映表為空」並中止(不損資料,補跑後重跑即可)。

---

## 步驟 3 全量爬取(約 1.5–2 小時)

```bash
cd /home/ubuntu/books/crawler
nohup venv/bin/python cosmiccare_crawler.py > logs/cosmiccare.log 2>&1 &
# 看進度
tail -f logs/cosmiccare.log
```

收尾行應為「完成:本次入檔 N 件」。核對筆數:

```bash
wc -l /home/ubuntu/books/crawler/data/cosmiccare_books.jsonl   # 預期約 1,671
```

可中斷續跑(有快取與 JSONL 去重),中斷後重下同一道指令即可。

---

## 步驟 4 匯入

```bash
cd /home/ubuntu/books
php tools/import.php --file=crawler/data/cosmiccare_books.jsonl --source=cosmiccare --dry-run
# 統計貼回給我看過再跑正式
php tools/import.php --file=crawler/data/cosmiccare_books.jsonl --source=cosmiccare
```

**dry-run 統計請貼回**。若「合併」比例異常高(例如超過七成),先在 Navicat 驗一下 ISBN 命中數,
確認不是模糊比對誤併(沿 8/19 橄欖華宣的慣例):

```sql
-- 這批 JSONL 的 ISBN 有多少已經在庫(數字對得起來才安全)
SELECT COUNT(DISTINCT id_value) FROM identifiers WHERE id_type='ISBN13';
```

---

## 步驟 5 套用分類

```bash
cd /home/ubuntu/books
php tools/apply_cosmiccare_categories.php --dry-run
# 統計貼回給我核對(分類分布 + 未對映清單應為空)再跑正式
php tools/apply_cosmiccare_categories.php
```

預期:未對映 0 筆(對映表與爬蟲走訪清單 100% 對齊);非書下架約 80–100 筆
(影音 23 + 禮品 59 + 雜誌訂閱 17,扣掉跨站合併的)。

---

## 步驟 6 封面轉 R2

```bash
cd /home/ubuntu/books
php tools/covers_to_r2.php --source=cosmiccare
```

---

## 步驟 7 線上抽查

- https://books.zh.church/ 搜「宇宙光」看筆數與封面
- 隨手點一本進詳情頁,確認購書連結出現「宇宙光」
- 分類頁應多出「期刊雜誌」

沒問題再 FTP 傳 `index.html`(footer v1.7),並告訴我實際筆數,我來寫
`release-notes/v1.7.0.md`(給一般使用者看的公告)。

---

## 步驟 8 git commit + tag(本機 PowerShell)

```powershell
cd Z:\DD-code\books.zh.church\web
git add -A
git commit -m "feat(source): 新增第十個書目來源 宇宙光全人關懷機構(cosmiccare)

- crawler/cosmiccare_crawler.py:自建 SSR 商城,清單 /Product/List(每頁 16、末頁由 >> 宣告)、
  商品 /Product/Detail/{code};書名取 h2.product-title(本站無 h1)、規格逐欄 .detailsp(掃全頁)、
  頁籤以 h3.tabcont-title 取名(nav 4 項 vs pane 5 個,不可索引對位)、og:image 封面
- 清單選擇器 div.product:not(.topsection):排除側欄熱門排行與每頁重複的「焦點」推薦
- 範圍全站抓入 1,671 件,非書由 cosmiccare_category_map 下架;《宇宙光雜誌》收錄,
  新增站內分類「期刊雜誌」(code=T);作者系列 Tag 與★福利書不參與分類
- 對映表 migration(51 列)+ tools/apply_cosmiccare_categories.php
- import/api/covers 白名單與購書標籤;new_arrivals 兩階段增量;daily_new.sh 十站
- VERSION 1.7.0 + CHANGELOG + footer v1.7(M1-B 進階搜尋順延 v1.8.0)"
git tag v1.7.0
git push
git push --tags
```

> 私有 repo push 需要 PAT 或瀏覽器認證。若 v1.6.0 的 tag 還沒打,先補
> `git tag v1.6.0 <該次 commit>` 再 push --tags。

---

## 步驟 9 隔日驗 cron(十站)

```bash
cd /home/ubuntu/books/crawler
grep -E '新品|匯入|錯誤' logs/new-arrivals-$(date +%Y%m%d).log | tail -50
```

判讀:十站都要出現;「0 本新書」正常;出現「錯誤」或整段缺席不正常。
宇宙光那段應該先印六個大類彙整的件數(合計約 1,671),再印「新品 N」。
