#!/usr/bin/env bash
# books.zh.church 部署:從 GitHub 拉最新 main,並逐項自我證明
# 用法:cd /home/ubuntu/books && ./deploy/deploy.sh
#
# 取代原本的手動 FTP。注意:
#   - config/app.local.php 是 untracked,留在主機不會被 pull 動到
#   - crawler/data、crawler/cache、crawler/logs、crawler/venv 同理
#   - 這支腳本不碰資料庫;migration 仍由熊哥用 Navicat 先跑
#
# ★ 全部包在 main() 裡再呼叫,是刻意的,不要攤平。
#   bash 是邊讀邊執行腳本檔的;這支腳本會 git pull、而 pull 可能換掉它自己,
#   攤平寫法會讓 bash 照舊的位元組位置繼續讀,跑出新舊混雜的內容
#   (2026-10-07 實際發生:拉到新版卻跑完舊版,報告是舊版檢查產生的)。
#   包成函式後 bash 必須先解析完整個函式才開始執行,等於先整份讀進記憶體。
#
# ★ 只有「整個目錄被改名」時才需要額外重載 PHP-FPM:
#     sudo systemctl reload php8.3-fpm
#   否則 PHP 的 realpath 快取會記住已消失的舊路徑,症狀是
#   「有些請求 200、有些 404」,錯誤 log 寫 "Primary script unknown"。
#   一般的 git pull 路徑沒變,不需要。
set -euo pipefail

main() {
  local ROOT="${BOOKS_ROOT:-/home/ubuntu/books}"
  cd "$ROOT"

  local H='Host: books.zh.church'
  local fail=0

  code() { curl -s -o /dev/null -w "%{http_code}" -H "$H" "http://127.0.0.1$1"; }

  # 必須正常服務
  check_ok() { # $1=路徑 $2=說明
    local c; c="$(code "$1")"
    if [ "$c" = "200" ]; then
      printf 'OK   %-26s -> %s  %s\n' "$1" "$c" "$2"
    else
      printf '!!   %-26s -> %s (期望 200)  %s\n' "$1" "$c" "$2"
      fail=1
    fi
  }

  # 必須擋下來。403 / 404 / 401 都算擋住了,只有「真的被服務到」才是失敗。
  # ★ 不要寫死成某一個碼:2026-10-07 曾把快取異常期間觀測到的 403 當基準,
  #   穩定狀態其實是 404,於是每次部署都誤報。會誤報的守門員等於沒有守門員。
  check_blocked() { # $1=路徑 $2=說明
    local c; c="$(code "$1")"
    case "$c" in
      2??|3??) printf '!!   %-26s -> %s 被服務到了!  %s\n' "$1" "$c" "$2"; fail=1 ;;
      *)       printf 'OK   %-26s -> %s 已擋下  %s\n' "$1" "$c" "$2" ;;
    esac
  }

  echo "===== 部署前狀態 ====="
  local BEFORE_VER BEFORE_SHA AFTER_VER AFTER_SHA
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
  [ "$BEFORE_SHA" = "$AFTER_SHA" ] && echo "(本次 pull 沒有新 commit)"

  echo ""
  echo "===== 必要的本機檔案(pull 不會帶來)====="
  local missing=0 f
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
  local shbad=0
  for f in crawler/daily_new.sh crawler/logos_cat_refresh.sh; do
    if [ -x "$f" ]; then echo "OK   $f 可執行"; else echo "!! $f 沒有執行權限"; shbad=1; fi
  done
  [ "$shbad" -eq 0 ] || { echo "!! 修正後再部署:git update-index --chmod=+x <檔>"; exit 1; }

  echo ""
  echo "===== PHP 語法檢查 ====="
  local err=0
  while IFS= read -r -d '' f; do
    php -l "$f" >/dev/null 2>&1 || { echo "語法錯誤:$f"; php -l "$f" || true; err=1; }
  done < <(find public app tools -name '*.php' -print0)
  if [ "$err" -eq 0 ]; then echo "全部通過"; else echo "!! 有語法錯誤,請處理後再對外"; exit 1; fi

  echo ""
  echo "===== 線上抽查 ====="
  check_ok      "/"                     "首頁"
  check_ok      "/api/books?limit=1"    "API + 資料庫"
  check_ok      "/assets/lang.js"       "前端資源"
  # 安全迴歸:這些在 web root 之外,被服務到就是 root 設錯了
  check_blocked "/config/app.local.php" "憑證"
  check_blocked "/tools/import.php"     "工具"
  check_blocked "/app/lib/db.php"       "共用程式碼"
  check_blocked "/.git/config"          "版控目錄"

  if [ "$fail" -ne 0 ]; then
    echo ""
    echo "!! 抽查有項目不符預期。先看 /var/log/nginx/books.zh.church-error.log"
    echo "   若症狀是「有些 200 有些 404」,多半是目錄改名後的路徑快取:"
    echo "   sudo systemctl reload php8.3-fpm && sudo systemctl reload nginx"
    exit 1
  fi

  echo ""
  echo "===== 部署完成 ====="
}

main "$@"
