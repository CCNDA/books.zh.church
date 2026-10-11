-- ============================================================
-- 海天書樓(rockhouse)站方分類 → 站內瀏覽分類 對映表
-- 建立日:2026-10-12(Asana 1217984724611028,v1.19.0)
--
-- 背景:第十九個書目來源「海天書樓」(www.rockhousebooks.com,香港,Java 自建站,HKD)。
--   全站 338 件 → 書 168 件;秩序單／吉儀封／音樂光碟／海天日月曆 170 件爬蟲就不寫進匯入檔。
--   沿 7/31 以琳雙軌分類決議:
--   (1) 站方分類由 import.php 寫入 subjects(scheme='rockhouse')永久存證(麵包屑每一層都存);
--   (2) 站內瀏覽分類經本對映表換算(tools/apply_rockhouse_categories.php)。
--
-- ★ code 是站方分類的 ParentId(19 位數字),不用中文名(分類代碼不可用中文 slug)。
-- ★ sort_order:子分類 100–199 優先於第一層 500–599
--   → 例「歷史‧傳記 > 傳記」(多為基督徒日記、回憶錄)歸「見證」,其餘「歷史‧傳記」歸「歷史」。
-- ★ internal_name NULL = 只存證不歸類(沿用同一本書第一層分類的對映),避免報表一堆「未對映」。
-- ★ 恆用日曆系列(5 件,每天翻一頁、有內容的桌曆)是否算書等熊哥決定:先歸「靈修」、unpublish 0;
--   熊哥若決定不收 → UPDATE rockhouse_category_map SET unpublish = 1 WHERE rh_code = '1253507506468008326';
--   再跑 apply 工具即下架(rockhouse-only 的書才下架,資料不刪)。
-- ★ 站方頁面沒有分類麵包屑的書(《中文聖經啟導本》9 件)不在這裡,交 classify_categories.php 依書名歸類。
--
-- 可重跑:INSERT ... ON DUPLICATE KEY 冪等(不覆蓋 internal_name/unpublish/note —— 表建立後改對映請直接 UPDATE)。
-- ============================================================

CREATE TABLE IF NOT EXISTS rockhouse_category_map (
  rh_code       VARCHAR(40)  NOT NULL COMMENT '=subjects.code;站方分類 ParentId',
  rh_path       VARCHAR(120) NOT NULL COMMENT '站方分類路徑(僅供人讀)',
  internal_name VARCHAR(50)  NULL     COMMENT '對映到的站內 categories.name;NULL=僅存證不歸類',
  unpublish     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=不該上架,rockhouse-only 書命中任一即下架',
  sort_order    INT          NOT NULL DEFAULT 500 COMMENT 'primary 優先序(小者優先;子分類 1xx、第一層 5xx)',
  note          VARCHAR(200) NULL,
  PRIMARY KEY (rh_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='海天書樓站方分類→站內分類對映';

INSERT INTO rockhouse_category_map (rh_code, rh_path, internal_name, unpublish, sort_order, note) VALUES
  -- ── 第一層(2026-10-12 全量實測書數)──
  ('1250582624703004926', '聖經',                 '聖經',       0, 500, '5 本(另有啟導本 9 本站方沒掛分類)'),
  ('1251083906250008112', '證道‧普及神學',        '神學',       0, 510, '29 本'),
  ('1253517575203003158', '靈修‧勵志',            '靈修',       0, 520, '7 本'),
  ('1253593302859007875', '禮品書',               '靈修',       0, 521, '2 本:耶穌語錄、童年的祈禱'),
  ('1253507506468008326', '恆用日曆系列',         '靈修',       0, 522, '5 本桌曆,是否算書待熊哥決定(不收就把 unpublish 改 1)'),
  ('1251100875375004986', '歷史‧傳記',            '歷史',       0, 530, '22 本;傳記子分類另歸見證'),
  ('1251080134453006126', '家庭‧生活',            '青少年家庭', 0, 540, '13 本'),
  ('1250669919562004001', '兒童與少年讀物',       '兒童教材',   0, 550, '21 本'),
  ('1250585051093006299', '幼兒與兒童',           '兒童教材',   0, 551, '15 本'),
  ('1250823056859006490', '少年文學‧童話',        '文學',       0, 560, '27 本:安徒生、狄更斯、民間故事'),
  ('1251175383796010399', '世界文學名著',         '文學',       0, 561, '5 本'),
  ('1391068067671055165', '小說',                 '文學',       0, 562, '2 本'),
  ('1253602164500002073', '新聞‧傳播專著',        '傳媒',       0, 570, '4 本(余也魯等)'),
  ('1253604766937003152', '學好英文的書',         '綜合其他',   0, 580, '2 本'),
  -- ── 子分類:要另外歸類的 ──
  ('1251105098593007366', '歷史‧傳記 > 傳記',     '見證',       0, 100, '17 本,多為基督徒日記、回憶錄'),
  ('1251103770281006549', '歷史‧傳記 > 歷史',     '歷史',       0, 101, '5 本'),
  -- ── 子分類:只存證(沿用第一層)──
  ('1251167270625005833', '歷史‧傳記 > 傳記 > 百日書系列',               NULL, 0, 900, '書系'),
  ('1250842546093006495', '少年文學‧童話 > 安徒生童話故事',              NULL, 0, 900, NULL),
  ('1250842785453006619', '少年文學‧童話 > 安徒生童話故事 > 硬面精裝本', NULL, 0, 900, '裝訂分類'),
  ('1250843242281006965', '少年文學‧童話 > 安徒生童話故事 > 軟面精裝本', NULL, 0, 900, '裝訂分類'),
  ('1250844089421007483', '少年文學‧童話 > 少年文學',                    NULL, 0, 900, NULL),
  ('1250844328375007749', '少年文學‧童話 > 少年新知',                    NULL, 0, 900, NULL),
  ('1250841931531006037', '少年文學‧童話 > 民間故事',                    NULL, 0, 900, NULL),
  ('1250844209218007604', '少年文學‧童話 > 狄更斯名著',                  NULL, 0, 900, NULL),
  ('1251191646218007591', '聖經 > 中文聖經啟導本(簡體版)',               NULL, 0, 900, NULL),
  ('1250582693484004975', '聖經 > 中文聖經啟導本増訂新版',               NULL, 0, 900, NULL),
  ('1684222915445949494', '聖經 > 中文聖經啟導本増訂新版 > 精裝版',      NULL, 0, 900, '裝訂分類'),
  ('1250584266671005840', '聖經 > 大字版聖經及註解',                     NULL, 0, 900, NULL)
ON DUPLICATE KEY UPDATE rh_path = VALUES(rh_path);

INSERT INTO schema_migrations (version, applied_at, note) VALUES
  ('2026-10-12_rockhouse_category_map.sql', NOW(), '海天書樓分類對映表(28 列)')
ON DUPLICATE KEY UPDATE note = VALUES(note);

-- 驗證(應回 0 列:對映目標都要真的存在於 categories):
-- SELECT m.rh_path, m.internal_name FROM rockhouse_category_map m
--   LEFT JOIN categories c ON c.name = m.internal_name
--   WHERE m.internal_name IS NOT NULL AND c.category_id IS NULL;
