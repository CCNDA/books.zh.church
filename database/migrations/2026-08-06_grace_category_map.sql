-- ============================================================
-- 天恩出版社(grace)官方分類 → 站內瀏覽分類 對映表
-- 建立日:2026-08-06
--
-- 背景:新增書目來源「天恩出版社」(graceph.com,WooCommerce)。沿 7/31
--   以琳雙軌分類決議:(1) 天恩官網原始分類(平面多分類,一書可多類)由
--   import.php 寫入 subjects(scheme='grace') 永久存證;(2) 站內瀏覽分類經
--   本對映表換算(tools/apply_grace_categories.php),兩軌皆在庫、互可查照。
--
-- 鍵為天恩分類名稱(官網分類為平面結構,名稱唯一);internal_name 對映
--   categories.name。內容面可於 Navicat 直接改 internal_name / sort_order
--   後重跑 apply 工具,無須改程式。
-- sort_order:primary 優先序(小者優先;愈具體的主題類愈前,泛用類靠後)。
-- unpublish=1:非書商品(8/6 決議:文創禮品/質選文創好物/專輯有聲/虛擬商品/
--   年度日月曆全站抓入存證、匯入後資料層下架,留庫可還原)。
--   與以琳「全數命中才下架」不同,天恩採「命中任一即下架」(見 apply 工具)
--   ——天恩分類皆平面主題類,掛非書分類即代表商品型態(如專輯常同掛禱告敬拜)。
--
-- 「新書快報」「暢銷排行」(促銷輪替)與「電子書」(載體格式)不入對映表:
--   apply 工具遇未對映名稱自動略過;電子書另受保護不因非書分類下架。
-- 可重跑:INSERT ... ON DUPLICATE KEY 冪等。
-- ============================================================

CREATE TABLE IF NOT EXISTS grace_category_map (
  grace_name    VARCHAR(60)  NOT NULL COMMENT '天恩官網分類名稱(平面,唯一)',
  internal_name VARCHAR(50)  NOT NULL COMMENT '對映到的站內 categories.name',
  unpublish     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=非書商品,grace-only 書命中任一即下架',
  sort_order    INT          NOT NULL DEFAULT 500 COMMENT 'primary 優先序(小者優先)',
  note          VARCHAR(200) NULL     COMMENT '對映說明/待內容面確認事項',
  PRIMARY KEY (grace_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='天恩官方分類→站內瀏覽分類對映(可於 Navicat 調整)';

INSERT INTO grace_category_map (grace_name, internal_name, unpublish, sort_order, note) VALUES
  ('聖經',           '聖經',       0,  10, NULL),
  ('真理解經',       '聖經研究',   0,  20, NULL),
  ('研經工具',       '聖經研究',   0,  30, NULL),
  ('先知預言',       '神學',       0,  40, '先知性教導;或依內容改教會復興'),
  ('科學有神',       '神學',       0,  50, '科學與信仰/護教'),
  ('深度信仰',       '神學',       0,  60, NULL),
  ('聖經輔導',       '心理',       0,  70, NULL),
  ('醫治釋放',       '心理',       0,  80, '內在醫治;或可改健康'),
  ('關懷輔導',       '心理',       0,  90, NULL),
  ('婚姻家庭',       '青少年家庭', 0, 100, NULL),
  ('親子教育',       '青少年家庭', 0, 110, NULL),
  ('親密關係',       '青少年家庭', 0, 120, NULL),
  ('自我成長',       '心理',       0, 130, '或可改門徒造就'),
  ('門徒訓練',       '門徒造就',   0, 140, NULL),
  ('生命造就',       '門徒造就',   0, 150, NULL),
  ('領導管理',       '教會復興',   0, 160, '教會領導/管理;或可改門徒造就'),
  ('福音宣教',       '福音',       0, 170, NULL),
  ('禱告敬拜',       '靈修',       0, 180, NULL),
  ('靈命成長',       '靈修',       0, 190, '天恩最大宗(477 件),泛用類故靠後'),
  ('其他',           '綜合其他',   0, 200, NULL),
  ('專輯有聲',       '詩本樂譜',   1, 300, '音樂專輯/有聲品,非書下架(8/6 決議)'),
  ('文創禮品',       '綜合其他',   1, 310, '非書下架(8/6 決議)'),
  ('質選文創好物',   '綜合其他',   1, 320, '非書下架(8/6 決議)'),
  ('虛擬商品',       '綜合其他',   1, 330, '非書下架(8/6 決議);電子書另受保護不下架'),
  ('年度日月曆系列', '綜合其他',   1, 340, '非書紙品下架(沿 7/30 決議)')
ON DUPLICATE KEY UPDATE internal_name = VALUES(internal_name),
                        unpublish     = VALUES(unpublish),
                        sort_order    = VALUES(sort_order),
                        note          = VALUES(note);
