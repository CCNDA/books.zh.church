-- ============================================================
-- 以琳書房(elim)官方分類 → 站內瀏覽分類 對映表
-- 建立日:2026-07-31
--
-- 背景:新增書目來源「以琳書房」(www.elimbookstore.com.tw)。熊哥 7/31 要求
--   分類雙軌並存:(1) 以琳官網原始分類(完整路徑,一書可多分類)由 import.php
--   寫入 subjects(scheme='elim') 永久存證;(2) 站內瀏覽分類經本對映表換算
--   (tools/apply_elim_categories.php),兩軌皆在庫、日後有問題可互相查照。
--
-- 鍵為完整名稱路徑(如「書籍/神學研經」);internal_name 對映 categories.name
--   (校園 12 類 + CategoryV11 擴充類)。內容面可於 Navicat 直接改 internal_name
--   後重跑 apply 工具,無須改程式。
-- unpublish=1:非書商品,匯入後資料層下架(沿 7/30「非書下架、留庫可還原」決議)。
--
-- 依 7/31 決議只抓「書籍」「聖經」兩大類(影音/禮品不抓),故對映僅此範圍。
-- 可重跑:INSERT ... ON DUPLICATE KEY 冪等。
-- ============================================================

CREATE TABLE IF NOT EXISTS elim_category_map (
  elim_path     VARCHAR(60)  NOT NULL COMMENT '以琳官網分類完整名稱路徑(父/子)',
  internal_name VARCHAR(50)  NOT NULL COMMENT '對映到的站內 categories.name',
  unpublish     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=非書商品,elim-only 書匯入後下架',
  note          VARCHAR(200) NULL     COMMENT '對映說明/待內容面確認事項',
  PRIMARY KEY (elim_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='以琳官方分類→站內瀏覽分類對映(可於 Navicat 調整)';

INSERT INTO elim_category_map (elim_path, internal_name, unpublish, note) VALUES
  ('書籍',           '綜合其他',   0, '僅直掛父分類、無子分類的書;待內容面複查'),
  ('書籍/神學研經',   '神學',       0, '神學+研經;或可拆聖經研究'),
  ('書籍/教會事奉',   '教會復興',   0, '事奉/事工;或可改門徒造就'),
  ('書籍/禱告靈修',   '靈修',       0, NULL),
  ('書籍/醫治輔導',   '心理',       0, '內在醫治/輔導;或可改健康'),
  ('書籍/福音見證',   '見證',       0, '福音+見證;或可改福音'),
  ('書籍/生活家庭',   '青少年家庭', 0, '生活/家庭;或可改門徒造就'),
  ('書籍/童書系列',   '兒童教材',   0, NULL),
  ('書籍/教材系列',   '兒童教材',   0, '主日學/門訓教材;或可改門徒造就'),
  ('書籍/休閒藝文',   '文學',       0, '休閒+藝文;或可拆藝術'),
  ('書籍/日誌月曆',   '綜合其他',   1, '非書紙品,依 7/30 決議下架(留庫可還原)'),
  ('書籍/外文書',     '綜合其他',   0, '外文原文書;或依主題人工細分'),
  ('聖經',                '聖經', 0, NULL),
  ('聖經/和合本',          '聖經', 0, NULL),
  ('聖經/新標點和合本',    '聖經', 0, NULL),
  ('聖經/新譯本',          '聖經', 0, NULL),
  ('聖經/現代中文譯本',    '聖經', 0, NULL),
  ('聖經/中英對照',        '聖經', 0, NULL),
  ('聖經/兒童聖經',        '聖經', 0, NULL),
  ('聖經/多功能聖經',      '聖經', 0, NULL),
  ('聖經/新約全書',        '聖經', 0, NULL),
  ('聖經/英文聖經',        '聖經', 0, NULL),
  ('聖經/恢復本',          '聖經', 0, NULL),
  ('聖經/外文聖經',        '聖經', 0, NULL),
  ('聖經/和合本修訂版',    '聖經', 0, NULL),
  ('聖經/新標研讀本',      '聖經', 0, NULL),
  ('聖經/客語聖經',        '聖經', 0, NULL),
  ('聖經/標準本',          '聖經', 0, NULL),
  ('聖經/大字版',          '聖經', 0, NULL),
  ('聖經/注音聖經',        '聖經', 0, NULL),
  ('聖經/新普及譯本',      '聖經', 0, NULL),
  ('聖經/簡體聖經',        '聖經', 0, NULL),
  ('聖經/台語聖經',        '聖經', 0, NULL),
  ('聖經/當代譯本',        '聖經', 0, NULL),
  ('聖經/原住民語聖經',    '聖經', 0, NULL)
ON DUPLICATE KEY UPDATE internal_name = VALUES(internal_name),
                        unpublish     = VALUES(unpublish),
                        note          = VALUES(note);
