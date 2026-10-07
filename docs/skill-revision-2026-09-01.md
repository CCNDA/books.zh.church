# books-zh-church-dev 技能修訂清單(2026-09-01)

現行技能檔停在 7 月中,與現況有六處明確錯誤、八處缺漏。以下按「錯誤 → 缺漏 → 分工建議」列出。

---

## A. 錯誤(會讓 Claude 做錯事,優先改)

| # | 現行內容 | 應改為 | 影響 |
|---|---|---|---|
| A1 | 「PHP 8 + MariaDB(utf8mb4),REST API 前後端分離,**一般虛擬主機(Apache)**」 | 「主機 172.237.12.60,Ubuntu 24.04 + nginx 1.24 + PHP 8.3-FPM;MariaDB 10.11;**`.htaccess` 無效,路由在 nginx server block(範本 `web/deploy/nginx-books.conf`)**」 | 會建議改 .htaccess 或用 Apache 語法,白做一輪 |
| A2 | 未載明 MariaDB 版本與 JSON 限制 | 補「MariaDB 10.11 **無原生 JSON 型別**,`extra`/`buy_links` 是 TEXT/MEDIUMTEXT + JSON 函式;有 CTE 與 window function」 | 會寫出 `JSON` 欄位型別的 migration |
| A3 | 「push 需使用者提供**一次性 GitHub PAT**,不寫入任何檔案」 | 「PAT 存在 `web/config/deploy.local.md`(gitignore),push 時從該檔取用,不必再請熊哥貼;**2026-10-11 到期**,到期前提醒 Regenerate」 | 每次都白問一次 |
| A4 | 「掛載磁碟怪癖:git config 會壞掉⋯commit/push 正常」 | 「**掛載端一律不跑任何 git 指令,連唯讀的 `status`/`diff` 都不行**——會留下 `index.lock` 癱瘓熊哥本機所有 git。git 一律由熊哥本機 PowerShell 跑」 | 現行寫法等於鼓勵在沙箱 commit,已造成兩次事故 |
| A5 | 紀錄義務寫「**history.md**(工作資料夾根目錄):追加當日段落」 | 「`history/YYYY-MM-DD.md`,**每日一檔**;根目錄 history.md 已廢除」 | 會寫錯檔案位置 |
| A6 | 「階段界線:MVP(7/17 華福大會)⋯階段三完整版(9/18)⋯」整段 | 全部刪掉,改成「以 Asana 檢核點為準,動工前先確認新功能屬於哪個階段」。**版號、站數、時程不寫進技能** | 已全數過時(華福實際是 7/20,十四家書房與正規化早已上線) |

另有一處措辭要調:「**MVP 期間** books 平面表只是 staging 層」——現況已進正規化,應改成「books 平面表是 staging 層,拆分必須可從 staging 無損還原」,拿掉「MVP 期間」的時間限定。

---

## B. 缺漏(重複溝通的主因)

1. **Asana 先開單鐵律**(8/28 熊哥明講):任何工作先建工單再動工,完成後回到那張任務留言回報。技能現行只寫「工作結束前記錄 Asana」,語氣是事後補記。
2. **FTP 上傳對照表義務**(8/6 熊哥要求,每次必附不用等問):本機路徑 → 主機路徑一檔一列,標明哪些不用上傳。
3. **git 驗證鐵律**:指令包第一行先檢查 `.git/index.lock`;commit 後看 `git log -1`、push 後驗 `git ls-remote`;tag 有不等於 commit 有。
4. **兩個 schema 陷阱**:商品代碼在 `identifiers`(books 無 `store_code`,要三表 join)、`categories` 是 CategoryV11 不是校園 01-18。
5. **新來源標準流程 + 必查陷阱清單**(17 條):分頁超界要實測、假商品格、sitemap 先驗筆數、分類代碼不可用中文 slug、probe 抽樣要抽主題大類、列表頁一律 force 且排序鍵必須唯一、日期在來源端補零、幣別走對映表⋯。這是技能最該收的內容,目前完全沒有。
6. **版本管理慣例**:一來源一 minor 版號、footer 只到次版本、發版五檔、Discord 公告流程。
7. **robots / 白名單分工**:部分站台 Claude 抓不到 → 熊哥的機器跑 probe,Claude 讀檔。
8. **全形冒號 regex 要 hex 驗證**、**冒號後用 `[ \t]*` 不要 `\s*`**、**分類器 `--all` 禁用(只能 `--campus-only` 與 `--orphans`,否則覆蓋基道官方分類)**。

---

## C. 三層分工建議(避免同一件事寫三遍又互相矛盾)

| 層 | 放什麼 | 何時載入 |
|---|---|---|
| **Project Instructions** | 環境事實、憑證規則、程式硬規範、schema 陷阱、工作流程鐵律、階段界線 | 每次對話自動載入 |
| **books-zh-church-dev 技能** | 操作手冊:新來源標準流程、爬蟲/解析陷阱清單、匯入細節、分類器規則、發版流程 | 動工時載入 |
| **web/CLAUDE.md** | 通用開發行為原則(先想再寫、外科手術式修改、大聲失敗、commit 紀律) | 進 repo 時載入 |

技能仍會與 instructions 有少量重複(主機、資料庫),這是刻意的——技能可能在沒有掛專案的對話中被叫起來,基本環境事實還是要能自足。但**階段、版號、站數這類會動的資訊,三層都不要寫**,一律指向 Asana 與專案記憶。

`web/CLAUDE.md` 只有最後一段要改:「每次工作結束必記錄 Asana 與 **history.md**」→ 改成 `history/YYYY-MM-DD.md`,並把「沙箱⋯部署走手動 FTP」那句補上「掛載端不跑 git」。
