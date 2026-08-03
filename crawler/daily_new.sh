#!/usr/bin/env bash
# 每日新品增量檢查 + 匯入(伺服器 cron 專用)。設定與部署見 deploy/cron-new-arrivals.md
#
# 流程:new_arrivals.py 抓三站新品(校園/基道/以琳)→ 產生當日 delta jsonl
#      → tools/import.php 匯入(以 source_url 去重)→ 以琳套對映分類 → 其餘分類回填。
# 全程寫入當日 log;任一步驟失敗會記錄但不中斷另一來源。
set -uo pipefail

# ── 路徑設定(依主機實際站點調整;預設 /home/ubuntu/books)──────────
ROOT="${BOOKS_ROOT:-/home/ubuntu/books}"
CRAWLER_DIR="$ROOT/crawler"
IMPORT="$ROOT/tools/import.php"
VENV="$CRAWLER_DIR/venv"
LOG_DIR="$CRAWLER_DIR/logs"
TS="$(date +%Y%m%d-%H%M)"
LOG="$LOG_DIR/new-arrivals-$(date +%Y%m%d).log"

mkdir -p "$LOG_DIR" "$CRAWLER_DIR/data/new"
log() { echo "[$(date '+%F %T')] $*" | tee -a "$LOG"; }

cd "$CRAWLER_DIR" || { echo "找不到 $CRAWLER_DIR"; exit 1; }
# 有 venv 就啟用;沒有(改用 pip --user/--break-system-packages 裝套件)則用系統 python3
# shellcheck disable=SC1091
if [ -f "$VENV/bin/activate" ]; then source "$VENV/bin/activate"; fi

log "=== 每日新品檢查開始 ==="

run_source() {
  local src="$1"; shift
  local out="data/new/${src}_${TS}.jsonl"
  log "--- $src:抓取新品 ---"
  if python3 new_arrivals.py --source "$src" --out "$out" "$@" >>"$LOG" 2>&1; then
    if [ -s "$out" ]; then
      local n; n="$(wc -l < "$out" | tr -d ' ')"
      log "$src:偵測到 $n 本新書,匯入中…"
      if php "$IMPORT" --file="$CRAWLER_DIR/$out" --source="$src" >>"$LOG" 2>&1; then
        log "$src:匯入完成($n 本)"
      else
        log "$src:[錯誤] 匯入失敗,delta 保留於 $out"
      fi
    else
      log "$src:無新書"
    fi
  else
    log "$src:[錯誤] 抓取失敗(見上方 log),略過匯入"
  fi
}

# 校園:全館新書列表(量小,約 13 頁);遇 500 可加 --browser-ua
run_source campus --browser-ua
# 基道:年份錨 2021 起、日期倒序;增量翻頁見到既有書即停
run_source logos --year 2021
# 以琳:逐分類增量(列表最新在前、無新品即停);紀錄自帶分類路徑
run_source elim

# ── 以琳分類套用:scheme='elim' 存證 → elim_category_map 對映站內分類 ──
# 冪等可重跑;需在 classify 之前跑,elim 新書由對映表歸類(而非關鍵字猜測),
# category_id 就位後 classify(只處理 NULL)自然跳過它們。
APPLY_ELIM="$ROOT/tools/apply_elim_categories.php"
if [ -f "$APPLY_ELIM" ]; then
  log "--- 以琳分類套用(對映表)---"
  if php "$APPLY_ELIM" >>"$LOG" 2>&1; then
    log "以琳分類套用完成"
  else
    log "[錯誤] 以琳分類套用失敗(可手動重跑 php tools/apply_elim_categories.php)"
  fi
else
  log "[警告] 找不到 $APPLY_ELIM,略過以琳分類套用"
fi

# ── 分類回填:替本次新匯入(category_id 仍為 NULL)的書套用分類器 ──
# classify_categories.php 不加 --all 時只處理 category_id IS NULL 的書(即新品),
# 校園照來源代碼、基道/新品照關鍵字;不動既有已分類書。
CLASSIFY="$ROOT/tools/classify_categories.php"
if [ -f "$CLASSIFY" ]; then
  log "--- 分類回填:未分類新書 ---"
  if php "$CLASSIFY" >>"$LOG" 2>&1; then
    log "分類回填完成"
  else
    log "[錯誤] 分類回填失敗(新書仍為未分類,可手動重跑 php tools/classify_categories.php)"
  fi
else
  log "[警告] 找不到 $CLASSIFY,略過分類回填"
fi

log "=== 每日新品檢查結束 ==="
# 註:匯入的新書預設 is_published=1、封面仍指來源站;分類已於匯入後自動回填(見上)。非書複核見 runbook 待辦。
