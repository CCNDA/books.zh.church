-- ============================================================
-- 基道(logos)官方分類 → 站內瀏覽分類 對映表
-- 建立日:2026-07-17
--
-- 背景:logos 22,855 本書匯入時無來源分類(以出版年檢索),category_id 只能
--   靠 classify_categories.php 關鍵字猜測。改以 logos_categories.py 從基道官網
--   「分類」選單抓取的權威歸類(scheme='logos' 原生存證)重判類別,再透過本
--   對映表換算成站內現有瀏覽分類(categories:校園 12 類 + CategoryV11 擴充類)。
--
-- 用途:tools/apply_logos_categories.php 讀本表得「logos 主分類 → 站內分類名」。
--   內容面可於 Navicat 直接改 internal_name 調整歸類,無須改程式。
--   scheme='logos' 的完整階層路徑(如「聖經/硬面聖經」)由 apply 工具寫入
--   subjects/book_subjects,永久保留原始權威分類(可作日後細分依據)。
--
-- 可重跑:INSERT ... ON DUPLICATE KEY 冪等。
-- ============================================================

CREATE TABLE IF NOT EXISTS logos_category_map (
  logos_top     VARCHAR(40)  NOT NULL COMMENT '基道官網主題主分類名',
  internal_name VARCHAR(50)  NOT NULL COMMENT '對映到的站內 categories.name',
  note          VARCHAR(200) NULL     COMMENT '對映說明/待內容面確認事項',
  PRIMARY KEY (logos_top)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='基道官方主分類→站內瀏覽分類對映(可於 Navicat 調整)';

INSERT INTO logos_category_map (logos_top, internal_name, note) VALUES
  ('神學／教義',     '神學',       NULL),
  ('讀經／研經',     '聖經研究',   NULL),
  ('聖經',           '聖經',       NULL),
  ('信仰入門',       '福音',       '入門/慕道;內容面可改門徒造就或另立「信仰入門」'),
  ('教會歷史',       '教會復興',   '教會史歸教會復興(含教會史);或可改歷史'),
  ('靈修／禱告',     '靈修',       NULL),
  ('信徒生活',       '門徒造就',   '信徒生活/生命塑造;或可改靈修'),
  ('教會事工',       '教會復興',   '教會事工/事奉;或可改門徒造就'),
  ('分齡牧養',       '青少年家庭', '分齡(兒童/青少年/家庭/長者);子分類可再細分兒童主日學/教材'),
  ('社會／倫理',     '社會',       '亦涵蓋倫理(H)'),
  ('哲學／宗教比較', '哲學',       NULL),
  ('見證／傳記',     '見證',       NULL),
  ('文藝／勵志',     '文學',       '文藝/勵志;或可再分藝術'),
  ('童書',           '兒童教材',   NULL),
  ('精選影音',       '綜合其他',   '影音多為非書(多已下架);保留者歸綜合其他'),
  ('其他',           '綜合其他',   NULL)
ON DUPLICATE KEY UPDATE internal_name = VALUES(internal_name), note = VALUES(note);
