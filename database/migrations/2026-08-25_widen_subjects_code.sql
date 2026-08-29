-- ============================================================
-- subjects.code 加寬 VARCHAR(20) → VARCHAR(40)
-- 建立日:2026-08-25(福音書房 twgbr 上線前必跑)
--
-- 原因:福音書房(Shopline)的分類 handle 最長 24 字元
--   (「精選商品」的 handle 是 24 碼 ID 57625fbd61706924fd302a00),
--   另有中文長 handle(「李常受文集電子版暢讀序號」)。原欄寬 20 會在匯入時
--   炸 `1406 Data too long`,中斷整批匯入(真哪噠 8/23 的教訓:匯入前先量
--   最長欄位值對照 schema 上限)。
--
-- 影響評估:
--   UNIQUE KEY uq_scheme_code_label (scheme, code, label)
--   = 20*4 + 40*4 + 150*4 = 840 bytes,遠低於 InnoDB 3072 上限 → 安全。
--   既有資料(campus/logos/elim/grace/wdbook/methodist/osb/taosheng/cclm/
--   cosmiccare/mezu)不受影響——加寬不截斷、不重建索引語意。
--
-- 可重跑:MariaDB 對相同定義的 MODIFY 是 no-op。
-- ============================================================

ALTER TABLE subjects
  MODIFY code VARCHAR(40) NULL COMMENT '來源分類代碼(2026-08-25 20→40,福音書房 24 碼 ID handle)';

-- 驗證:應顯示 varchar(40)
-- SHOW COLUMNS FROM subjects LIKE 'code';
