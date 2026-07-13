# -*- coding: utf-8 -*-
"""共用工具:禮貌抓取(節流+快取+重試)、JSONL 輸出、進度狀態。

設計原則:
- 每個已抓頁面以 gzip 快取到磁碟,重跑時直接讀快取 → 天然斷點續跑
- 單執行緒 + 隨機延遲,UA 註明 CCNDA 身分與聯絡方式
- 所有檔案 I/O 一律 UTF-8(Windows 請設 PYTHONUTF8=1 或用 -X utf8)
"""
from __future__ import annotations

import gzip
import hashlib
import json
import random
import time
from pathlib import Path

import requests

USER_AGENT = (
    "CCNDA-BooksBot/1.0 (+https://books.zh.church; contact: cowork@ccnda.org; "
    "purpose: Christian bibliography aggregation, polite single-thread crawl)"
)


def make_session() -> requests.Session:
    s = requests.Session()
    s.headers.update({
        "User-Agent": USER_AGENT,
        "Accept-Language": "zh-TW,zh;q=0.9,en;q=0.5",
    })
    return s


def _cache_path(cache_dir: Path, url: str, post_data: dict | None) -> Path:
    key = url if not post_data else url + "|" + json.dumps(post_data, sort_keys=True, ensure_ascii=False)
    h = hashlib.sha1(key.encode("utf-8")).hexdigest()
    return cache_dir / h[:2] / (h + ".html.gz")


def polite_fetch(
    session: requests.Session,
    url: str,
    cache_dir: Path,
    throttle: tuple[float, float] = (3.0, 5.0),
    post_data: dict | None = None,
    encoding: str | None = None,
    max_retries: int = 5,
    force: bool = False,
) -> str:
    """抓取一頁(GET 或 POST),命中快取則不發請求、不延遲。

    429/500/502/503/504 視為主機忙碌/暫時故障:等 60 秒×次數再試
    (校園主機較弱,500 多為過載,給足喘息時間通常可過)。"""
    cp = _cache_path(cache_dir, url, post_data)
    if cp.exists() and not force:
        return gzip.decompress(cp.read_bytes()).decode("utf-8", errors="replace")

    last_err: Exception | None = None
    for attempt in range(1, max_retries + 1):
        time.sleep(random.uniform(*throttle) * attempt)  # 重試時加倍退避
        try:
            if post_data is None:
                resp = session.get(url, timeout=60)
            else:
                resp = session.post(url, data=post_data, timeout=60)
            if resp.status_code in (429, 500, 502, 503, 504):
                last_err = RuntimeError(f"HTTP {resp.status_code}")
                wait = 60 * attempt
                print(f"  [{resp.status_code}] 主機忙碌/暫時故障,等 {wait} 秒後重試 {attempt}/{max_retries}")
                time.sleep(wait)
                continue
            resp.raise_for_status()
            if encoding:
                resp.encoding = encoding
            elif not resp.encoding or resp.encoding.lower() == "iso-8859-1":
                resp.encoding = resp.apparent_encoding
            html = resp.text
            cp.parent.mkdir(parents=True, exist_ok=True)
            cp.write_bytes(gzip.compress(html.encode("utf-8")))
            return html
        except requests.RequestException as e:
            last_err = e
            print(f"  [重試 {attempt}/{max_retries}] {url} → {e}")
    raise RuntimeError(f"抓取失敗(已重試 {max_retries} 次):{url}\n{last_err}")


class JsonlWriter:
    """附加式 JSONL 輸出;啟動時載入既有 key 避免重複。"""

    def __init__(self, path: Path, key_field: str):
        self.path = path
        self.key_field = key_field
        self.seen: set[str] = set()
        if path.exists():
            with path.open("r", encoding="utf-8") as f:
                for line in f:
                    line = line.strip()
                    if not line:
                        continue
                    try:
                        self.seen.add(str(json.loads(line)[key_field]))
                    except (json.JSONDecodeError, KeyError):
                        pass
        path.parent.mkdir(parents=True, exist_ok=True)
        self._fh = path.open("a", encoding="utf-8")

    def has(self, key: str) -> bool:
        return str(key) in self.seen

    def write(self, record: dict) -> bool:
        key = str(record[self.key_field])
        if key in self.seen:
            return False
        self._fh.write(json.dumps(record, ensure_ascii=False) + "\n")
        self._fh.flush()
        self.seen.add(key)
        return True

    def close(self):
        self._fh.close()


class State:
    """簡單進度狀態(JSON 檔):記錄已完成的分類/年份等工作單位。"""

    def __init__(self, path: Path):
        self.path = path
        self.data: dict = {}
        if path.exists():
            self.data = json.loads(path.read_text(encoding="utf-8"))

    def is_done(self, unit: str) -> bool:
        return unit in self.data.get("done", [])

    def mark_done(self, unit: str):
        self.data.setdefault("done", [])
        if unit not in self.data["done"]:
            self.data["done"].append(unit)
        self.save()

    def save(self):
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.path.write_text(
            json.dumps(self.data, ensure_ascii=False, indent=1), encoding="utf-8"
        )
