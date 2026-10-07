#!/usr/bin/env bash
# books.zh.church 部署:從 GitHub 拉最新 main,並逐項自我證明
# 用法:cd /home/ubuntu/books && ./deploy/deploy.sh
#
# 取代原本的手動 FTP。注意:
#   - config/app.local.php 是 untracked,留在主機不會被 pull 動到
#   - crawler/data、crawler/cache、crawler/logs、crawler/venv 同理
#   - 這支腳本不碰資料庫;migration 仍由熊哥用 Navicat 先跑
#
# ★ 只有「整個目錄被改名」時才需要額外重載 PHP-FPM:
#     sudo systemctl reload php8.3-fpm
#   否則 PHP 的 realpath 快取會記住已消失的舊路徑,症狀是
#   「有些請求 200、有些 404」,錯誤 log 寫 "Primary script unknown"。
#   一般的 git pull 路徑沒變,不需要。
set -euo pipefail

ROOT="${BOOKS_ROOT:-/home/ubuntu/books}"
cd "$ROOT"

echo "===== 部署前狀態 ====="
BEFORE_VER="$(cat VERSION)"
BEFORE_SHA="$(git rev-parse --short HEAD)"
echo "VERSION = ${BEFORE_VER}"
echo "HEAD    = ${BEFORE_SHA}"

# 主機上不該有手改的檔。untracked(憑證、爬蟲資料)不算,只看追蹤中的。
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "!! 主機工作區有未提交的修改,停止部署:"
  git status --short --untracked-files=no
  exit 1
fi

echo ""
echo "===== git pull ====="
git pull --ff-only origin main

AFTER_VER="$(cat VERSION)"
AFTER_SHA="$(git rev-parse --short HEAD)"
echo ""
echo "VERSION : ${BEFORE_VER} -> ${AFTER_VER}"
echo "HEAD    : ${BEFORE_SHA} -> ${AFTER_SHA}"
if [ "$BEFORE_SHA" = "$AFTER_SHA" ]; then
  echo "(本次 pull 沒有新 commit)"
fi

echo ""
echo "===== 必要的本機檔案(pull 不會帶來)====="
missing=0
for f in config/app.local.php; do
  if [ -f "$f" ]; then echo "OK   $f"; else echo "缺少 $f"; missing=1; fi
done
[ "$missing" -eq 0 ] || { echo "!! 缺檔,停止"; exit 1; }

echo ""
echo "===== release-notes 檢查 ====="
# notify_discord.php 在主機讀 release-notes/v{VERSION}.md,不存在會報「找不到更新說明」
if [ -f "release-notes/v${AFTER_VER}.md" ]; then
  echo "OK   release-notes/v${AFTER_VER}.md"
else
  echo "警告 release-notes/v${AFTER_VER}.md 不存在 → Discord 公告會失敗"
fi

echo ""
echo "===== 排程要用的檔(執行位元掉了 cron 會無聲失敗)====="
shbad=0
for f in crawler/daily_new.sh crawler/logos_cat_refresh.sh; do
  if [ -x "$f" ]; then echo "OK   $f 可執行"; else echo "!! $f 沒有執行權限"; shbad=1; fi
done
[ "$shbad" -eq 0 ] || { echo "!! 修正後再部署:git update-index --chmod=+x <檔>"; exit 1; }

echo ""
echo "===== PHP 語法檢查 ====="
err=0
while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null 2>&1 || { echo "語法錯誤:$f"; php -l "$f" || true; err=1; }
done < <(find public app tools -name '*.php' -print0)
if [ "$err" -eq 0 ]; then echo "全部通過"; else echo "!! 有語法錯誤,請處理後再對外"; exit 1; fi

echo ""
echo "===== 線上抽查 ====="
H='Host: books.zh.church'
code(){ curl -s -o /dev/null -w "%{http_code}" -H "$H" "http://127.0.0.1$1"; }
fail=0
check(){ # $1=路徑 $2=期望碼 $3=說明
  c="$(code "$1")"
  if [ "$c" = "$2" ]; then
    printf 'OK   %-26s -> %s  %s\n' "$1" "$c" "$3"
  else
    printf '!!   %-26s -> %s (期望 %s)  %s\n' "$1" "$c" "$2" "$3"
    fail=1
  fi
}
check "/"                     200 "首頁"
check "/api/books?limit=1"    200 "API + 資料庫"
check "/assets/lang.js"       200 "前端資源"
# 安全迴歸:這些在 web root 之外,服務得到就是 root 設錯了
check "/config/app.local.php" 404 "憑證不可外露"
check "/tools/import.php"     403 "工具不可外露"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "!! 抽查有項目不符預期。先看 /var/log/nginx/books.zh.church-error.log"
  echo "   若症狀是「有些 200 有些 404」,多半是目錄改名後的路徑快取:"
  echo "   sudo systemctl reload php8.3-fpm && sudo systemctl reload nginx"
  exit 1
fi

echo ""
echo "===== 部署完成 ====="
