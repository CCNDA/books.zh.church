# PHP + RestAPI 建置方式（無 Node.js）

本專案可完全以 PHP 執行，前端頁面由 `web/` 提供，API 由 `api/` 提供。

## 0. 單一設定檔（建議）

所有設定集中在：`config/app.local.php`

- 此檔已在 `.gitignore`，不會上傳到 GitHub
- 請以 `config/app.example.php` 為範本建立/更新
- 包含：DB、APP_URL、AWS SES（寄件者 `support@ccnda.org`）

## 1. 匯入資料庫

請先依序執行 migration：

- `database/migrations/001_create_books_table.sql`
- `database/migrations/002_create_users_table.sql`
- `database/migrations/003_create_magic_links_table.sql`

## 2. 設定資料庫連線

資料庫設定檔：`api/config/database.php`

建議改用環境變數：

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`

Magic Link 郵件（AWS SES）：

- `AWS_REGION`（例如 `ap-northeast-1`）
- `AWS_SES_FROM`（預設 `support@ccnda.org`）
- `AWS_SES_FROM_NAME`（例如 `CCNDA Support`）
- `AWS_SES_REPLY_TO`（例如 `support@ccnda.org`）
- `AWS_SES_CONFIGURATION_SET`（可選）
- `AWS_SES_ENDPOINT`（可選）
- `AWS_ACCESS_KEY_ID`（若不用 IAM Role）
- `AWS_SECRET_ACCESS_KEY`（若不用 IAM Role）
- `AWS_SESSION_TOKEN`（可選，STS）
- `AWS_PROFILE`（可選，本機 profile）
- `APP_URL`（例如 `https://books.oursweb.net`）
- `APP_DEBUG_MAGIC_LINK`（開發測試用，設 `1` 才會在 API 回傳 magic_link）

另外需安裝 AWS SDK：

```bash
composer require aws/aws-sdk-php
```

SES 上線檢查：

- `support@ccnda.org` 或其網域需在 SES 完成 Verified identity
- 帳號若仍是 SES sandbox，收件者也必須是已驗證地址
- 執行環境 IAM 需有 `ses:SendEmail`

若你只有 SES SMTP 資訊（Host/Port/Username/Password），請在 `config/app.local.php` 設定：

```php
'aws' => [
  'region' => 'ap-northeast-1',
  'ses_from' => 'support@ccnda.org',
  'ses_from_name' => 'CCNDA Support',
  'ses_reply_to' => 'support@ccnda.org',
  'smtp' => [
    'host' => 'email-smtp.ap-northeast-1.amazonaws.com',
    'port' => 587,
    'encryption' => 'tls',
    'username' => 'YOUR_SES_SMTP_USERNAME',
    'password' => 'YOUR_SES_SMTP_PASSWORD',
  ],
],
```

此情況下不需要填 AWS API Key/Secret，系統會優先走 SMTP 寄信。

## 3. 啟動 PHP 伺服器（同時提供網站與 API）

在專案根目錄啟動：

```bash
cd /home/ubuntu/books
php -S 0.0.0.0:8080 web/router.php
```

## 4. 可用網址

- 首頁書單：`http://<host>:8080/`
- 登入/註冊：`http://<host>:8080/login.php`
- 後台管理：`http://<host>:8080/admin.php`
- RestAPI：`http://<host>:8080/api/...`

## 5. API 範例

```bash
# 取得書單（新到舊）
curl http://<host>:8080/api/books

# 搜尋書籍
curl "http://<host>:8080/api/books?q=靈修"

# 註冊（寄送 Magic Link）
curl -X POST http://<host>:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Admin","email":"admin@example.com"}'

# 登入（寄送 Magic Link）
curl -X POST http://<host>:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com"}'

# 驗證 Magic Link token（取得 API token）
curl -X POST http://<host>:8080/api/auth/verify \
  -H "Content-Type: application/json" \
  -d '{"token":"<magic-link-token>"}'
```

> 注意：`POST/PATCH/DELETE` 的書籍操作需帶 Bearer Token（由 `auth/verify` 回傳）。
