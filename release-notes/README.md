# release-notes/

每個版本一份「寫給一般使用者看」的更新說明，檔名 `v{版號}.md`（如 `v1.6.0.md`）。

與 `CHANGELOG.md` 的分工：

- `CHANGELOG.md` — 寫給開發者：檔案、資料表、修正細節、部署步驟。
- `release-notes/vX.Y.Z.md` — 寫給使用者：新增了什麼書、多了什麼功能、對他們有什麼差別。不提爬蟲、資料表、md5。

發佈到 Discord：

```bash
php tools/notify_discord.php --dry-run   # 先看內容（版號取自 VERSION）
php tools/notify_discord.php             # 確認後送出
```

Webhook URL 放在 `config/app.local.php` 的 `discord.webhook_url`（不進 git）。
Discord 單則上限 2000 字，超過時工具會自動依段落切成多則依序送出。
