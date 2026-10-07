#!/usr/bin/env bash
# 基道(logos)官網分類對照重爬 —— 每週一次,產出 crawler/data/logos_code_categories.jsonl,
# 供 tools/apply_logos_categories.php 把基道書(含當週新書)歸到官網分類,而不是關鍵字猜測。
#
# 為什麼獨立成一支、不放在 daily_new.sh 裡:
#   全站 64 個分類、父子分類重複列示,2026-09-03 實測 29,725 個不重複商品碼、耗時 356 分
#   (當時有兩支並行,單跑估 3-4 小時)。塞進 06:30 那條鏈會把 apply/classify 推到中午,
#   故改為**週二 22:00** 獨立跑(夜間、站方負載低),最遲清晨四點結束,
#   隔天週三 06:30 的 apply 就吃得到當週最新對照檔。
#
# 用法:
#   ./logos_cat_refresh.sh              # 整份重爬(cron 用這個)
#   ./logos_cat_refresh.sh --resume     # 被中斷/逾時後補跑,跳過已完成的分類
set -uo pipefail

ROOT="${BOOKS_ROOT:-/home/ubuntu/books}"
CRAWLER_DIR="$ROOT/crawler"
LOG_DIR="$CRAWLER_DIR/logs"
LOG="$LOG_DIR/logos-cat-refresh-$(date +%Y%m%d).log"
MODE="${1:---refresh}"

mkdir -p "$LOG_DIR"
log() { echo "[$(date '+%F %T')] $*" | tee -a "$LOG"; }

cd "$CRAWLER_DIR" || { echo "找不到 $CRAWLER_DIR"; exit 1; }

# 單一實例鎖:2026-09-03 實測有兩支同指令並行(cron 與手動、或手動按兩次),
# 對站方的請求量直接翻倍,而且兩支寫同一個進度檔;整場跑了 356 分。
exec 9>"$CRAWLER_DIR/.logos_cat_refresh.lock"
if ! flock -n 9; then
  echo "[$(date '+%F %T')] [略過] 已有另一支重爬在執行中" | tee -a "$LOG"
  exit 0
fi
# shellcheck disable=SC1091
if [ -f venv/bin/activate ]; then source venv/bin/activate; fi

log "=== 基道分類對照重爬開始($MODE)==="

# -u:不加會 block buffering(4-8KB),進度與重試訊息要等程式結束才吐,整場看起來像卡死。
# timeout -s INT 21600:4 小時上限,且用 SIGINT 而非 SIGTERM —— logos_categories.py 攔
#   KeyboardInterrupt,收到 INT 會保存進度並寫出對照檔;SIGTERM 會讓那一輪的進度白費。
if timeout -s INT 14400 python3 -u logos_categories.py "$MODE" >>"$LOG" 2>&1; then
  log "重爬完成;對照檔 $(wc -c < data/logos_code_categories.jsonl) bytes、$(wc -l < data/logos_code_categories.jsonl) 個商品碼"
  log "提醒:接著跑 php $ROOT/tools/apply_logos_categories.php --dry-run 再正式套用"
else
  rc=$?
  if [ "$rc" = "124" ] || [ "$rc" = "130" ]; then
    log "[警告] 逾時 6 小時已中止(進度已保存)。補跑:$0 --resume"
  else
    log "[錯誤] 重爬失敗(rc=$rc);apply 將沿用舊對照檔,基道新書分類會落回關鍵字猜測"
  fi
  exit 1
fi
