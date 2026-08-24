<?php
// 複製為 config/app.local.php 後填入實際值(app.local.php 已在 .gitignore)
return [
    'app' => [
        'url'   => 'https://books.zh.church',
        'debug_magic_link' => 0, // 開發測試時設 1,API 才會回傳 magic_link
    ],
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'books',
        'user'    => 'DB_USER',
        'pass'    => 'DB_PASS',
        'charset' => 'utf8mb4',
    ],
    'api' => [
        'write_key' => 'X_API_KEY_FOR_WRITE_ENDPOINTS',
    ],
    'r2' => [
        // Cloudflare R2(S3 相容)— 書籍封面儲存,公開網址 + 上傳憑證
        'access_key_id'     => 'R2_ACCESS_KEY_ID',
        'secret_access_key' => 'R2_SECRET_ACCESS_KEY',
        'endpoint'          => 'https://<account_id>.r2.cloudflarestorage.com',
        'bucket'            => 'BUCKET_NAME',
        'public_url'        => 'https://imgr2.example.net',
        'prefix'            => 'books/',
    ],
    'discord' => [
        // 版本更新公告(tools/notify_discord.php)。到 Discord 頻道
        // 設定 → 整合 → 建立 Webhook 取得網址;此檔已在 .gitignore
        'webhook_url' => 'https://discord.com/api/webhooks/...',
        'username'    => '屬靈共同書目',
    ],
    'aws' => [
        'region'        => 'ap-northeast-1',
        'ses_from'      => 'support@ccnda.org',
        'ses_from_name' => 'CCNDA Support',
        'ses_reply_to'  => 'support@ccnda.org',
        // 若只有 SMTP 資訊,填 smtp 區塊即可(免 API Key,系統優先走 SMTP)
        'smtp' => [
            'host'       => 'email-smtp.ap-northeast-1.amazonaws.com',
            'port'       => 587,
            'encryption' => 'tls',
            'username'   => 'SES_SMTP_USERNAME',
            'password'   => 'SES_SMTP_PASSWORD',
        ],
    ],
];
