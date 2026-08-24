# -*- coding: utf-8 -*-
"""版本更新公告 → Discord webhook(Python 版,只用標準函式庫)。

與 tools/notify_discord.php 同一件事、同一份文案來源
(release-notes/vX.Y.Z.md),差別只在執行環境:
  - PHP 版:主機(Ubuntu)上跑,設定讀 config/app.local.php。
  - 本檔:**沒有 PHP 的環境也能跑**(如熊哥的 Windows 本機),
    只需要 Python 3.7+,不用 pip 裝任何套件。
兩支行為一致:allowed_mentions 全關、超過 1900 字依段落切則、
任一則失敗即中止(重跑會重複已送出的段落)。

Webhook URL 取得順序(**絕不寫進 git**):
  1. 環境變數 DISCORD_WEBHOOK_URL
  2. --webhook-file=<檔案>(整個檔案就是一行網址)
  3. config/app.local.php 的 'webhook_url'(該檔已 gitignore)

用法:
  python tools/notify_discord.py --dry-run          # 只印出要送的內容
  python tools/notify_discord.py                    # 送出
  python tools/notify_discord.py --version=1.7.0    # 指定版本(預設讀 VERSION)
  python tools/notify_discord.py --file=path/to.md  # 指定文案檔
  python tools/notify_discord.py --username=名稱     # 覆蓋顯示名稱
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path

LIMIT = 1900          # 官方上限 2000,留餘裕
ROOT = Path(__file__).resolve().parent.parent


def read_php_config() -> dict:
    """從 config/app.local.php 撈 discord 設定(簡單 regex,夠用即可)。"""
    path = ROOT / "config" / "app.local.php"
    if not path.exists():
        return {}
    text = path.read_text(encoding="utf-8", errors="replace")
    block = re.search(r"'discord'\s*=>\s*\[(.*?)\]", text, re.S)
    if not block:
        return {}
    body = block.group(1)
    out = {}
    for key in ("webhook_url", "username"):
        m = re.search(r"'" + key + r"'\s*=>\s*'([^']*)'", body)
        if m:
            out[key] = m.group(1)
    return out


def split_paragraphs(text: str, limit: int = LIMIT) -> list[str]:
    """依段落切則;單一段落仍超長則硬切。"""
    parts: list[str] = []
    buf = ""
    for para in re.split(r"\n{2,}", text.strip()):
        piece = para.strip()
        if not piece:
            continue
        if len(piece) > limit:                 # 單段就爆表 → 硬切
            if buf:
                parts.append(buf)
                buf = ""
            for i in range(0, len(piece), limit):
                parts.append(piece[i:i + limit])
            continue
        candidate = piece if not buf else buf + "\n\n" + piece
        if len(candidate) > limit:
            parts.append(buf)
            buf = piece
        else:
            buf = candidate
    if buf:
        parts.append(buf)
    return parts


def post(url: str, payload: dict) -> tuple[bool, str]:
    data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(
        url, data=data, method="POST",
        headers={"Content-Type": "application/json; charset=utf-8",
                 "User-Agent": "CCNDA-BooksBot/1.0 (+https://books.zh.church)"})
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return 200 <= resp.status < 300, f"HTTP {resp.status}"
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", errors="replace")[:300]
        return False, f"HTTP {e.code} {body}"
    except Exception as e:                      # 連線/逾時
        return False, str(e)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--version")
    ap.add_argument("--file")
    ap.add_argument("--username")
    ap.add_argument("--webhook-file")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    cfg = read_php_config()

    version = args.version or (ROOT / "VERSION").read_text(encoding="utf-8").strip()
    if not version:
        print("找不到版號(VERSION 為空,請用 --version=X.Y.Z)")
        return 1

    note = Path(args.file) if args.file else ROOT / "release-notes" / f"v{version}.md"
    if not note.exists():
        print(f"找不到文案檔:{note}")
        return 1
    content = note.read_text(encoding="utf-8").strip()
    if not content:
        print(f"文案檔是空的:{note}")
        return 1

    username = args.username or cfg.get("username", "")
    parts = split_paragraphs(content)

    print(f"版本 v{version}、文案 {note.name}({len(content)} 字)→ {len(parts)} 則")
    if args.dry_run:
        for i, p in enumerate(parts, 1):
            print(f"\n----- 第 {i}/{len(parts)} 則({len(p)} 字)-----\n{p}")
        print("\n[dry-run 未送出] 確認無誤後拿掉 --dry-run 再跑一次。")
        return 0

    webhook = os.environ.get("DISCORD_WEBHOOK_URL", "").strip()
    if not webhook and args.webhook_file:
        webhook = Path(args.webhook_file).read_text(encoding="utf-8").strip()
    if not webhook:
        webhook = cfg.get("webhook_url", "").strip()
    if not webhook.startswith("https://discord.com/api/webhooks/") and \
       not webhook.startswith("https://discordapp.com/api/webhooks/"):
        print("找不到有效的 webhook URL。請設環境變數 DISCORD_WEBHOOK_URL、"
              "用 --webhook-file=,或在 config/app.local.php 填 discord.webhook_url。")
        return 1

    for i, p in enumerate(parts, 1):
        payload = {"content": p, "allowed_mentions": {"parse": []}}
        if username:
            payload["username"] = username
        ok, msg = post(webhook, payload)
        if not ok:
            print(f"第 {i}/{len(parts)} 則送出失敗:{msg}")
            print("中止:後續段落未送出,修正後可重跑(注意已送出的段落會重複)。")
            return 1
        print(f"第 {i}/{len(parts)} 則已送出({msg})")
    print("完成。")
    return 0


if __name__ == "__main__":
    sys.exit(main())
