-- 麥種傳道會(akow)分類對映表(2026-09-14)
-- 與主機上 `python3 akow_crawler.py --emit-map-sql` 的產出相同(46 列),
-- 另行寫一份到本機,方便熊哥直接用 Navicat 開啟執行。
--
-- 代碼 46 個 = 14 個 product_cat(站方 15 類中 Uncategorized 無商品實際掛載)
--            + 32 種站方細分類(簡介裡的「分類:」欄,含簡繁兩種寫法各自成列)
-- 未對映 0 個。
--
-- ★ 兩層並用(熊哥 2026-09-13 決議):
--   sub: 開頭的細分類 sort_order=110,排在 product_cat(5xx)之前 → 有細分類的書
--   以細分類決定 primary,沒有的自動落回 product_cat。
-- ★ 書系(光照/成長/焦點/種子/根基系列)internal_name=NULL,僅存證不歸類;
--   書系名已由爬蟲寫入 books.series。
-- ★ 簡繁兩種寫法不做字形轉換、各自成列:utf8mb4_unicode_ci 不把簡繁視為相等。

CREATE TABLE IF NOT EXISTS akow_category_map (
  akow_code     VARCHAR(40)  NOT NULL COMMENT '=subjects.code;數字=WooCommerce 分類 id,sub:xxx=簡介裡的站方細分類',
  akow_path     VARCHAR(80)  NOT NULL COMMENT '站方分類路徑(僅供人讀)',
  akow_slug     VARCHAR(100) NULL     COMMENT 'product_cat 的 slug(細分類無)',
  internal_name VARCHAR(50)  NULL     COMMENT '對映到的站內 categories.name;NULL=僅存證不歸類',
  unpublish     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=非書,akow-only 命中任一即下架',
  sort_order    INT          NOT NULL DEFAULT 500 COMMENT 'primary 優先序(小者優先;細分類 110 贏過 product_cat 5xx)',
  note          VARCHAR(200) NULL,
  PRIMARY KEY (akow_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='麥種傳道會分類對映(2026-09-14);書系一律 NULL 改寫 series,細分類優先於 product_cat';

INSERT INTO akow_category_map
  (akow_code, akow_path, akow_slug, internal_name, unpublish, sort_order, note) VALUES
('37', '光照系列', 'exposition', NULL, 0, 900, '光照系列=書系,僅存證(已寫入 series)'),
('39', '婚姻家庭', 'marriage_family', '青少年家庭', 0, 590, '婚姻家庭 → 站內「青少年家庭」'),
('41', '成長系列', 'growth', NULL, 0, 900, '成長系列=書系,僅存證(已寫入 series)'),
('42', '焦點系列', 'focus', NULL, 0, 900, '焦點系列=書系,僅存證(已寫入 series)'),
('43', '種子系列', 'kernelseries', NULL, 0, 900, '種子系列=書系,僅存證(已寫入 series)'),
('44', '聖經人物', 'biblicalcharacters', '聖經研究', 0, 570, '聖經人物=人物研究'),
('45', '聖經原文', 'language', '聖經研究', 0, 530, '聖經原文(原文教材歸聖經研究,「聖經」類留給聖經本身)'),
('46', '聖經教育', 'education', '門徒造就', 0, 550, '聖經教育=教導裝備'),
('47', '聖經神學', 'theology', '神學', 0, 520, '聖經神學'),
('48', '聖經註釋', 'commentary', '聖經研究', 0, 510, '聖經註釋'),
('49', '聖經靈修', 'devotionals', '靈修', 0, 560, '聖經靈修'),
('50', '解經講道', 'preaching', '教會復興', 0, 540, '解經講道=講道法,屬教會事工 → 站內最近的是教會復興'),
('51', '輔導協談', 'psychology', '心理', 0, 580, '輔導協談 → 站內「心理」'),
('76', '根基系列', 'themelios', NULL, 0, 900, '根基系列=書系,僅存證(已寫入 series)'),
('sub:生命造就', '站方細分類 > 生命造就', '', '門徒造就', 0, 110, '站方細分類(1 件)'),
('sub:神学类／教义', '站方細分類 > 神学类／教义', '', '神學', 0, 110, '站方細分類(2 件)'),
('sub:神学类／系统神学概论', '站方細分類 > 神学类／系统神学概论', '', '神學', 0, 110, '站方細分類(2 件)'),
('sub:神學類／救恩論', '站方細分類 > 神學類／救恩論', '', '神學', 0, 110, '站方細分類(1 件)'),
('sub:神學類／教義', '站方細分類 > 神學類／教義', '', '神學', 0, 110, '站方細分類(2 件)'),
('sub:神學類／神論', '站方細分類 > 神學類／神論', '', '神學', 0, 110, '站方細分類(1 件)'),
('sub:神學類／系統神學概論', '站方細分類 > 神學類／系統神學概論', '', '神學', 0, 110, '站方細分類(1 件)'),
('sub:神學類／聖靈論', '站方細分類 > 神學類／聖靈論', '', '神學', 0, 110, '站方細分類(1 件)'),
('sub:聖經註釋', '站方細分類 > 聖經註釋', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／以弗所書', '站方細分類 > 聖經論叢／以弗所書', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／以賽亞書', '站方細分類 > 聖經論叢／以賽亞書', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／使徒行傳', '站方細分類 > 聖經論叢／使徒行傳', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／加拉太書', '站方細分類 > 聖經論叢／加拉太書', '', '聖經研究', 0, 110, '站方細分類(2 件)'),
('sub:聖經論叢／哥林多前後', '站方細分類 > 聖經論叢／哥林多前後', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／啟示錄', '站方細分類 > 聖經論叢／啟示錄', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／希伯來書', '站方細分類 > 聖經論叢／希伯來書', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／提摩太前後', '站方細分類 > 聖經論叢／提摩太前後', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／教牧書信', '站方細分類 > 聖經論叢／教牧書信', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／新約引用舊約', '站方細分類 > 聖經論叢／新約引用舊約', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／歌羅西書', '站方細分類 > 聖經論叢／歌羅西書', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／歷史書', '站方細分類 > 聖經論叢／歷史書', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／箴言', '站方細分類 > 聖經論叢／箴言', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／約伯記', '站方細分類 > 聖經論叢／約伯記', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／約翰壹、貳、參書', '站方細分類 > 聖經論叢／約翰壹、貳、參書', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／約翰福音', '站方細分類 > 聖經論叢／約翰福音', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／羅馬書', '站方細分類 > 聖經論叢／羅馬書', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／耶穌生平與教訓', '站方細分類 > 聖經論叢／耶穌生平與教訓', '', '聖經研究', 0, 110, '站方細分類(3 件)'),
('sub:聖經論叢／聖經神學', '站方細分類 > 聖經論叢／聖經神學', '', '聖經研究', 0, 110, '站方細分類(2 件)'),
('sub:聖經論叢／腓立比書', '站方細分類 > 聖經論叢／腓立比書', '', '聖經研究', 0, 110, '站方細分類(2 件)'),
('sub:聖經論叢／詩篇', '站方細分類 > 聖經論叢／詩篇', '', '聖經研究', 0, 110, '站方細分類(3 件)'),
('sub:聖經論叢／路得記', '站方細分類 > 聖經論叢／路得記', '', '聖經研究', 0, 110, '站方細分類(1 件)'),
('sub:聖經論叢／馬太福音', '站方細分類 > 聖經論叢／馬太福音', '', '聖經研究', 0, 110, '站方細分類(1 件)')
ON DUPLICATE KEY UPDATE akow_path = VALUES(akow_path), akow_slug = VALUES(akow_slug);

-- ★★ 必跑的驗證一:對映到「站內不存在的分類名」會靜默不歸類,不會報錯。
--    下面這段應該回 0 列;有回列就是 internal_name 打錯或站內分類改名了。
--    (2026-09-13 曾有六個分類名猜錯五個,就是這段要抓的東西)
SELECT DISTINCT m.internal_name AS 對不到的分類名
  FROM akow_category_map m
  LEFT JOIN categories c ON c.name = m.internal_name
 WHERE m.internal_name IS NOT NULL AND c.category_id IS NULL;

-- ★★ 必跑的驗證二:對映表列數與涵蓋率
SELECT COUNT(*) AS 對映列數 FROM akow_category_map;                    -- 應 46
SELECT COUNT(*) AS 細分類列數 FROM akow_category_map
 WHERE akow_code LIKE 'sub:%';                                         -- 應 32

-- ★★ 必跑的驗證三:import 實際寫進去的東西(不要只信工具自印的數字)
SELECT COUNT(*) AS 版本數   FROM editions WHERE source = 'akow';       -- 應 144
SELECT COUNT(*) AS 新建作品 FROM books    WHERE source = 'akow';       -- 應 100(其餘 44 掛在別站的 book 下)
SELECT COUNT(*) AS 存證分類 FROM subjects WHERE scheme = 'akow';       -- 應 46 上下
SELECT COUNT(*) AS 細分類存證 FROM subjects
 WHERE scheme = 'akow' AND code LIKE 'sub:%';                          -- ★ 應 32;若為 0 表示「兩層並用」沒生效
SELECT COUNT(*) AS ISBN13 數 FROM identifiers i
  JOIN editions e ON e.edition_id = i.edition_id
 WHERE e.source = 'akow' AND i.id_type = 'ISBN13';                     -- 應 47(51 減掉防線擋下的 4)

-- 對照組(站方 ISBN 抄錯而未寫入 isbn13 的四本,原值仍在 books.extra):
--   9781951456115 真正的快樂 / 現代神學精髓
--   9781939251176 以西結書註釋(上下) / 主耶穌的畫像
--   9781939251015 舊約歷史書手冊 / 主耶穌的比喻
--   9781932184020 喜樂平安的人生 / 當主耶穌面對世界
