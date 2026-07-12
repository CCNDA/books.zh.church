# 屬靈共同書目(books.zh.church)

CCNDA「基督徒圖書分享服務」網站。PHP 8 + MariaDB(utf8mb4),REST API 前後端分離,部署於 cPanel/Apache 虛擬主機。

## 目錄結構

```
api/                  REST API(JSON,{"data":...}/{"error":...})
web/                  前端頁面 + router.php
config/               app.example.php(範本)→ 複製為 app.local.php(gitignore)
database/migrations/  SQL migration,依編號順序執行
```

## 部署(正式站 https://books.zh.church)

- 更新方式:**手動 FTP** 上傳異動檔案(不使用 CI/CD)
- 首次部署:先在主機建立 MariaDB 資料庫(utf8mb4),依序執行 `database/migrations/` 的 SQL,再上傳程式與建立 `config/app.local.php`
- `config/app.local.php` 只存在主機上,絕不進版控

## 本機啟動

```bash
composer require aws/aws-sdk-php   # Magic Link 郵件(AWS SES)
php -S 0.0.0.0:8080 web/router.php
```

## 開發規�