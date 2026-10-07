#!/usr/bin/env bash
# books.zh.church 部署:從 GitHub 拉最新 main,並逐項自我證明
# 用法:cd /home/ubuntu/books && ./deploy/deploy.sh
#
# 取代原本的手動 FTP。注意:
#   - config/app.local.php 是 untracked,留在主機不會被 pull 動到
#   - crawler/data、crawler/logs 同理
#   - 這支腳本不碰資料庫;migration 仍由熊哥用 Navicat 先跑
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

echo
echo "===== git pull ====="
git pull --ff-only origin main

AFTER_VER="$(cat VERSION)"
AFTER_SHA="$(git rev-parse --short HEAD)"
echo
echo "VERSION : ${BEFORE_VER} -> ${AFTER_VER}"
echo "HEAD    : ${BEFORE_SHA} -> ${AFTER_SHA}"
if [ "$BEFORE_SHA" = "$AFTER_SHA" ]; then
  echo "(本次 pull 沒有新 commit)"
fi

echo
echo "===== 必要的本機檔案(pull 不會帶來)====="
missing=0
for f in config/app.local.php; do
  if [ -f "$f" ]; then echo "OK   $f"; else echo "缺少 $f"; missing=1; fi
done
[ "$missing" -eq 0 ] || { echo "!! 缺檔,停止"; exit 1; }

echo
echo "===== release-notes 檢查 ====="
# notify_discord.php 在主機讀 release-notes/v{VERSION}.md,不存在會報「找不到更新說明」
if [ -f "release-notes/v${AFTER_VER}.md" ]; then
  echo "OK   release-notes/v${AFTER_VER}.md"
else
  echo "警告 release-notes/v${AFTER_VER}.md 不存在 → Discord 公告會失敗"
fi

echo
echo "===== PHP 語法檢查 ====="
err=0
while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null 2>&1 || { echo "語法錯誤:$f"; php -l "$f" || true; err=1; }
done < <(find public app tools -name '*.php' -print0)
if [ "$err" -eq 0 ]; then echo "全部通過"; else echo "!! 有語法錯誤,請處理後再對外"; exit 1; fi

echo
echo "===== 線上抽查 ====="
for p in "/" "/api"; do
  code="$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: books.zh.church' "http://127.0.0.1${p}" || echo "000")"
  echo "${p} -> ${code}"
done
echo "(非 2xx/3xx 就去看 /var/log/nginx/books.zh.church-error.log)"

echo
echo "===== 部署完成 ====="
