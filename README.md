# 屬靈共同書目(books.zh.church)

CCNDA「基督徒圖書分享服務」網站。PHP 8 + MariaDB(utf8mb4),REST API 前後端分離,部署於 cPanel/Apache 虛擬主機。

## 目錄結構

```
api/                  REST API(JSON,{"data":...}/{"error":...})
web/                  前端頁面 + router.php
config/               app.example.php(範本)→ 複製為 app.local.php(gitignore)
database/migrations/  SQL migration,依編號順序執行
```

## 本機啟動

```bash
composer require aws/aws-sdk-php   # Magic Link 郵件(AWS SES)
php -S 0.0.0.0:8080 web/router.php
```

## 開發規範

- 所有 SQL 一律 PDO prepared statements;前端輸出一律 HTML escape
- API 回應 JSON_UNESCAPED_UNICODE;寫入端點需驗證 X-Api-Key / Bearer Token
- 介面文字使用繁體中文
- 設定集中於 `config/app.local.php`,勿提交憑證

詳細建置說明見專案 docs/php-restapi-setup.md(不在本 repo)。
