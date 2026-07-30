# 每日新品增量檢查排程(伺服器 cron)

校園(全館新書 IsNewBook.aspx)與基道(年份檢索、日期倒序)每日自動偵測新書並匯入正式站。
完全在主機執行,不需本機。原規劃屬檢核點 2(M1)的「增量爬取排程」,依熊哥指示提前完成。

## 組成

| 檔案 | 作用 |
| --- | --- |
| `crawler/new_arrivals.py` | 新品列表入口 + 增量翻頁(見到既有書即停);重用 campus_crawler / logos_crawler 解析 |
| `crawler/daily_new.sh` | cron 進入點:抓新品 → 產生 delta jsonl → `tools/import.php` 匯入 → 寫 log |
| `tools/import.php` | 既有匯入器;以 `editions.source_url` 去重、可重跑,新書預設 `is_published=1` |

資料流:新品列表 → 只抓「不在 master jsonl」的商品頁 → 寫入 `crawler/data/<source>_books.jsonl`(master,天然去重)+ 當日 `crawler/data/new/<source>_<ts>.jsonl`(delta)→ import 匯入 delta。

## 前置(一次性)

1. **確認 master jsonl 已在主機**:`crawler/data/campus_books.jsonl`、`crawler/data/logos_books.jsonl`
   (重建時已上傳)。這是「哪些書已在庫」的判斷依據;若缺,首次執行會把整份新品清單當成新書全抓,雖仍會被 import 以 source_url 去重、不會重複入庫,但會多抓很多商品頁。務必確認存在再排程。
2. **裝 Python 相依套件**(requests / beautifulsoup4 / lxml)。二選一:

   **(a) venv(首選,需先裝 OS 套件)**——Ubuntu 24.04/python3.12 的 `python3 -m venv` 需 `python3.12-venv`,否則報 `ensurepip is not available`:
   ```bash
   sudo apt update && sudo apt install -y python3.12-venv
   cd /home/ubuntu/books/crawler
   rm -rf venv && python3 -m venv venv
   source venv/bin/activate
   pip install --upgrade pip
   pip install requests beautifulsoup4 lxml
   ```

   **(b) 無 sudo 時**——裝到使用者環境,不建 venv(`daily_new.sh` 偵測不到 venv 會自動改用系統 python3):
   ```bash
   cd /home/ubuntu/books/crawler
   python3 -m pip install --user --break-system-packages requests beautifulsoup4 lxml
   ```
3. **確認站點路徑**:`daily_new.sh` 預設 `ROOT=/home/ubuntu/books`。若不同,改檔頭 `ROOT=` 或設環境變數 `BOOKS_ROOT`。
4. `chmod +x /home/ubuntu/books/crawler/daily_new.sh`

## 手動驗證(排程前務必先跑)

```bash
cd /home/ubuntu/books/crawler
source venv/bin/activate

# 1) dry-run:只列出偵測到的新書,不寫任何檔、不動 DB
python3 new_arrivals.py --source campus --dry-run --browser-ua --max-pages 2
python3 new_arrivals.py --source logos  --dry-run --max-pages 2

# 2) 產生 delta 但先用 import 的 --dry-run 模擬匯入
python3 new_arrivals.py --source logos --out data/new/test.jsonl --max-pages 2
php ../tools/import.php --file="$(pwd)/data/new/test.jsonl" --source=logos --dry-run

# 3) 全流程試跑一次
bash daily_new.sh
tail -n 40 logs/new-arrivals-$(date +%Y%m%d).log
```

穩定後線上抽驗:首頁「共 N 本」數字應增加,新書可搜尋、詳情頁正常。

## 排上 cron

每日 06:30 執行(避開白天流量;校園主機較弱、節流已放慢):
```cron
30 6 * * * /home/ubuntu/books/crawler/daily_new.sh >/dev/null 2>&1
```
安裝(免進編輯器):
```bash
chmod +x /home/ubuntu/books/crawler/daily_new.sh
( crontab -l 2>/dev/null; echo "30 6 * * * /home/ubuntu/books/crawler/daily_new.sh >/dev/null 2>&1" ) | crontab -
crontab -l
```
log 於 `crawler/logs/new-arrivals-YYYYMMDD.log`。

## 查詢主機現有排程

Ubuntu 排程分散在使用者 cron、系統 cron、systemd timer 三處:
```bash
crontab -l                       # 目前使用者(ubuntu)cron ← 本排程在此
sudo crontab -l                  # root cron
cat /etc/crontab                 # 系統主 cron
ls -la /etc/cron.d/              # 各別系統排程檔
ls -la /etc/cron.{daily,hourly,weekly,monthly}/   # 週期性目錄
systemctl list-timers --all --no-pager            # systemd 計時器
```
一次掃全部:
```bash
echo "== user =="; crontab -l 2>/dev/null
echo "== root =="; sudo crontab -l 2>/dev/null
echo "== /etc/cron.d =="; ls /etc/cron.d 2>/dev/null
echo "== timers =="; systemctl list-timers --all --no-pager
```
確認 cron 實際觸發(近期執行紀錄):
```bash
grep CRON /var/log/syslog | tail -n 20
```

## 已知限制與待辦(MVP 可接受,列入後續階段)

- **新書分類為 NULL**:新品列表不帶分類,校園新書匯入時 `category_source` 為空,不會回填 12 分類 chip(可被搜尋、不現於分類頁)。與現有 logos-only 書相同,屬階段三(內容面 CategoryV11 對照)處理。
- **非書偵測未套用**:新書預設上架。校園「新書新品」多為書(禮品在另一頁 IsNewProduct.aspx,本排程未納入);基道年度新品偶有月曆/雜誌,依重建規則仍視為書,僅影音需下架——量少,可定期以既有 `analyze_logos_nonbooks.py`(identifiers join 版)複核下架。
- **封面**:新書封面仍指來源站(hotlink),與現況一致;R2 轉存另由 `covers_to_r2.php` 定期補跑。
- **通知**:目前僅寫 log。如需每日 email 摘要,可後續於 `daily_new.sh` 結尾串接主機 SES(設定範本已備),暫不納入以免 cron 因寄信失敗中斷。
- **禮貌抓取**:沿用 common.polite_fetch(校園 5-8 秒、基道 2-3 秒、UA 註明 CCNDA 身分),robots 允許範圍內;每日增量僅抓真正新增部分,負載極小。
