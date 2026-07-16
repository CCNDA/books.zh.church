-- ============================================================
-- 出版社 ↔ 華人教會機構名錄(church.oursweb.net API 3.1)整合預留
-- 建立日:2026-07-16
--
-- 目的:僅「預留」對照結構,現在不做整合功能。
--   - 完全不改動 publishers 表、不動任何現有資料。
--   - 新增一張(啟用時為空)對照表,把本站 publisher 對應到名錄機構 oid。
--   - 名錄 API 端點:https://church.oursweb.net/api/{api_key}/search/{keyword}
--                     https://church.oursweb.net/api/{api_key}/oid/{oid}
--     機構主鍵=oid(編碼);另有 orgpid 宗派編碼、porg_cname 宗派名稱可一併留存。
--   - API Key 存 config/app.local.php,不入 git/記憶。
--
-- 之後整合時:比對 publisher 名稱→名錄 search→取得 oid→寫入本表(match_status=confirmed)。
-- 回滾:DROP TABLE publisher_org_links;(不影響其他資料)
-- 註:未加 FK 以免與 publishers.publisher_id 型別不符而中止;僅建索引。
--     確認型別一致後可另補:ALTER TABLE publisher_org_links
--       ADD CONSTRAINT fk_pol_publisher FOREIGN KEY (publisher_id)
--       REFERENCES publishers(publisher_id) ON DELETE CASCADE;
-- ============================================================

CREATE TABLE IF NOT EXISTS publisher_org_links (
  link_id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  publisher_id     INT UNSIGNED  NOT NULL                COMMENT '本站 publishers.publisher_id',
  directory_system VARCHAR(40)   NOT NULL DEFAULT 'ccnda_church' COMMENT '名錄來源系統識別',
  directory_oid    VARCHAR(32)   NULL                    COMMENT '名錄機構編碼 oid',
  org_pid          VARCHAR(32)   NULL                    COMMENT '宗派編碼 orgpid',
  org_pname        VARCHAR(150)  NULL                    COMMENT '宗派名稱 porg_cname',
  matched_name     VARCHAR(150)  NULL                    COMMENT '名錄端比對到的機構名稱(存查)',
  match_status     VARCHAR(20)   NOT NULL DEFAULT 'unconfirmed' COMMENT 'unconfirmed/confirmed/rejected',
  matched_at       DATETIME      NULL,
  note             VARCHAR(255)  NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (link_id),
  UNIQUE KEY uq_pub_system (publisher_id, directory_system),
  KEY idx_directory_oid (directory_system, directory_oid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='出版社↔教會機構名錄對照(整合預留,啟用前為空)';
