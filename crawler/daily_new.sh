#!/usr/bin/env bash
# 每日新品增量檢查 + 匯入(伺服器 cron 專用)。設定與部署見 deploy/cron-new-arrivals.md
#
# 流程:new_arrivals.py 抓十一站新品(校園/基道/以琳/天恩/微讀/衛理/格子外面/道聲/橄欖華宣/宇宙光/真哪噠)→ 產生當日 delta jsonl
#      → tools/import.php 匯入(以 source_url 去重)→ 九站套對映分類 → 其餘分類回填。
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
# 基道:年份錨 2021 起,**全掃列表不早停**(約 197 頁、約 8 分鐘;商品頁仍走快取,
# 成本與新書數成正比)。兩個站方特性導致必須這樣做:
#   1) 清單按「出版日期」而非上架日期排序 → 新上架的舊書落在清單深處,任何早停都會漏
#   2) 站方以 offset 分頁,排序鍵並列就會相鄰頁互相重複、有些列從不顯示
#      (日期排序實測涵蓋率僅 77.8%)→ 改用唯一鍵 sort=Code(new_arrivals.py 預設)
# log 結尾會印涵蓋率;若出現 [警告] 涵蓋率不足,跑 python3 logos_coverage.py 對帳。
# 另建議每月手動或另排一次深掃補年份較舊者:python3 new_arrivals.py --source logos --year 1
run_source logos --year 2021
# 以琳:逐分類增量(列表最新在前、無新品即停);紀錄自帶分類路徑
run_source elim
# 天恩:Store API 日期倒序增量(無新品即停);紀錄自帶完整分類清單
run_source grace
# 微讀書城:所有書籍列表增量(SSR 上架新→舊,無新品即停);全站電子書
run_source wdbook
# 衛理書房:導覽全分類日期倒序增量(無新品即停);紀錄自帶完整分類歸屬
run_source methodist
# 格子外面:全分類走訪增量(範圍=osb+聖經三分類+★新書到);紀錄自帶完整分類歸屬
run_source osb
# 道聲:Cyberbiz 全分類走訪增量(全站抓入,非書由對映表下架)
run_source taosheng
# 橄欖華宣:全分類增量(清單 id 遞減、整頁已見即停;範圍=書籍+聖經)
run_source cclm
# 宇宙光:六大類彙整清單增量(聯集=全站);有新品才補走 Tag 取分類歸屬
run_source cosmiccare
# 真哪噠:新品上架/注目優惠清單增量(全站抓入,非書由對映表下架);有新品才補走 119 分類取歸屬
run_source mezu
run_source twgbr
run_source pctpress
# 天道書樓:每日只走 path=105「最新出版」(1 頁 64 件),偵測到新 pid 才走全分類
#   取主題分類歸屬 —— 只走 105 的話新書只會掛到 promo 分類(internal_name=NULL),
#   apply 無從歸類、那本書就沒有 primary。
# ★ 每週一改走全分類對帳(--full-scan):105 是站方**手動維護的推薦位**,不是自動
#   新品列表(9/2 離線檢定:105 共 64 件卻橫跨 1999-2026;全站出版日最新 30 本
#   100% 在 105 內,但最新 60 本只有 88.3%)。漏掉的是「上架日新、出版日舊」那類,
#   單靠 105 會永久漏而且 log 全綠看不出來 —— 就是 8/27 基道那次的形狀。
if [ "$(date +%u)" = "1" ]; then
  log "天道:今天是週一,改走全分類對帳(--full-scan)"
  run_source tiendao --full-scan
else
  run_source tiendao
fi

# ── 對映分類套用:scheme 存證 → *_category_map 對映站內分類 ──
# 冪等可重跑;需在 classify 之前跑,新書由對映表歸類(而非關鍵字猜測),
# category_id 就位後 classify(只處理 NULL)自然跳過它們。
apply_map() {
  local name="$1" tool="$2"
  if [ -f "$tool" ]; then
    log "--- ${name}分類套用(對映表)---"
    if php "$tool" >>"$LOG" 2>&1; then
      log "${name}分類套用完成"
    else
      log "[錯誤] ${name}分類套用失敗(可手動重跑 php $tool)"
    fi
  else
    log "[警告] 找不到 $tool,略過${name}分類套用"
  fi
}
apply_map "以琳" "$ROOT/tools/apply_elim_categories.php"
apply_map "天恩" "$ROOT/tools/apply_grace_categories.php"
apply_map "微讀" "$ROOT/tools/apply_wdbook_categories.php"
apply_map "衛理" "$ROOT/tools/apply_methodist_categories.php"
apply_map "格子外面" "$ROOT/tools/apply_osb_categories.php"
apply_map "道聲" "$ROOT/tools/apply_taosheng_categories.php"
apply_map "橄欖華宣" "$ROOT/tools/apply_cclm_categories.php"
apply_map "宇宙光" "$ROOT/tools/apply_cosmiccare_categories.php"
apply_map "真哪噠" "$ROOT/tools/apply_mezu_categories.php"
apply_map "福音書房" "$ROOT/tools/apply_twgbr_categories.php"
apply_map "教會公報社" "$ROOT/tools/apply_pctpress_categories.php"
apply_map "天道書樓" "$ROOT/tools/apply_tiendao_categories.php"

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
