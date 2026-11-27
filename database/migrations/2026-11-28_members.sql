-- 書目網會員（M2-1；Asana 1218124736678630；熊叔叔 11/27 TG「甲 台灣聖經網 SSO」「甲 照建議」）
-- DDL：用 sudo ccnda-db-run <本檔> --commit 跑；可重跑（IF NOT EXISTS）。
-- 只存台灣聖經網的會員編號、暱稱、頭像、登入時間；不存 Email、密碼。
-- 之後的書庫、短評表用 FOREIGN KEY (member_id) REFERENCES members ON DELETE CASCADE，刪帳號就一起刪。
CREATE TABLE IF NOT EXISTS members (
  member_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sso_id        INT UNSIGNED NOT NULL COMMENT '台灣聖經網會員編號（token 的 ID）',
  name          VARCHAR(100) NOT NULL COMMENT '暱稱（token 的 Name，每次登入更新）',
  avatar_url    VARCHAR(500) NULL COMMENT '頭像網址（只收 https）',
  created_at    DATETIME     NOT NULL,
  last_login_at DATETIME     NOT NULL,
  PRIMARY KEY (member_id),
  UNIQUE KEY uq_members_sso (sso_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='書目網會員（台灣聖經網 SSO）';

CREATE TABLE IF NOT EXISTS sso_seen (
  token_hash CHAR(64)     NOT NULL COMMENT 'sha256(token)，同一張 token 只收一次',
  exp        INT UNSIGNED NOT NULL COMMENT 'token 到期（unix 秒），過期就清',
  PRIMARY KEY (token_hash),
  KEY idx_sso_seen_exp (exp)
) ENGINE=InnoDB DEFAULT CHARSET=ascii COMMENT='台灣聖經網 token 防重放';

INSERT INTO schema_migrations (version, applied_at, note) VALUES
  ('2026-11-28_members.sql', NOW(), '書目網會員 members＋token 防重放 sso_seen（M2-1）')
ON DUPLICATE KEY UPDATE note = VALUES(note);
