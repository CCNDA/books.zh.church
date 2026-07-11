-- 種子測試資料(source='seed'):供開發與展示前預覽,正式書目匯入後可
-- DELETE FROM books WHERE source='seed';
-- 分類取自 CategoryV11 主要區類

SET NAMES utf8mb4;

INSERT INTO categories (code, name, sort_order) VALUES
  ('A0000', '神學／教義', 1),
  ('B0000', '讀經／研經', 2),
  ('C0000', '靈修生活', 3),
  ('D0000', '禱告', 4),
  ('E0000', '見證／傳記', 5),
  ('F0000', '教會歷史', 6),
  ('G0000', '婚姻家庭', 7),
  ('H0000', '佈道／宣教', 8)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- 示範書目(書名/作者/出版社為常見華文基督教出版品,細節僅供展示,正式資料以匯入為準)
INSERT INTO books (title, author, translator, publisher, publish_date, category_id, summary, source) VALUES
  ('標竿人生', '華理克', '楊高俐理', '基督使者協會', '2003', (SELECT category_id FROM categories WHERE code='C0000'), '以四十天的靈修旅程,引導讀者思想「我究竟為何而活」,從敬拜、團契、門訓、事奉與宣教五個目的,重新校準人生方向。', 'seed'),
  ('認識神', '巴刻', '尹妙珍', '福音證主協會', '1996', (SELECT category_id FROM categories WHERE code='A0000'), '巴刻的經典之作,帶領讀者從認識神的屬性進入與神相交的實際,是神學與靈修並重的入門必讀。', 'seed'),
  ('禱告', '楊腓力', '徐成德', '校園書房出版社', '2007', (SELECT category_id FROM categories WHERE code='D0000'), '楊腓力誠實面對禱告中的疑惑與掙扎,探問「禱告有用嗎」,邀請讀者在真實的張力中持續與神對話。', 'seed'),
  ('恩典多奇異', '楊腓力', '徐成德', '校園書房出版社', '1999', (SELECT category_id FROM categories WHERE code='C0000'), '在一個缺乏恩典的世界,楊腓力以動人的故事闡明恩典的顛覆性力量,喚醒教會活出「不合理」的愛。', 'seed'),
  ('浪子回頭', '盧雲', '徐成德', '校園書房出版社', '1997', (SELECT category_id FROM categories WHERE code='C0000'), '盧雲凝視林布蘭的名畫,從小兒子、大兒子到父親的三重角色,寫下關於離家與回家的屬靈經典。', 'seed'),
  ('基督教要義(上/下)', '加爾文', '錢曜誠等', '加爾文出版社', '2007', (SELECT category_id FROM categories WHERE code='A0000'), '宗教改革神學的集大成之作,系統闡述認識神與認識人的智慧,是改革宗信仰的根基性文獻。', 'seed'),
  ('如何讀聖經', '戈登・費依、道格拉斯・史督華', '魏啟源等', '校園書房出版社', '2015', (SELECT category_id FROM categories WHERE code='B0000'), '按文體類型教導讀經原則,從敘事、律法、詩歌到書信,幫助讀者「按正意分解真理的道」。', 'seed'),
  ('天路歷程', '本仁約翰', '西海', '基督教文藝出版社', '2009', (SELECT category_id FROM categories WHERE code='E0000'), '基督徒天路客從毀滅城奔向天城的寓言之旅,三百多年來造就無數信徒的屬靈經典。', 'seed'),
  ('返璞歸真', '魯益師', '余也魯', '海天書樓', '1995', (SELECT category_id FROM categories WHERE code='A0000'), '魯益師以理性而幽默的筆觸闡明「純粹的基督教」,從道德律出發論證信仰的合理與可信。', 'seed'),
  ('教會歷史', '祈伯爾', '李林靜芝', '校園書房出版社', '1986', (SELECT category_id FROM categories WHERE code='F0000'), '從初代教會到近代宣教運動,精要勾勒兩千年教會的興衰與更新,是教會歷史入門讀本。', 'seed'),
  ('愛之語', '巧門', '王雲良', '中國主日學協會', '1998', (SELECT category_id FROM categories WHERE code='G0000'), '提出肯定言詞、精心時刻、接受禮物、服務行動與身體接觸五種愛的語言,幫助夫妻說對方聽得懂的愛。', 'seed'),
  ('布道神學', '斯托得', '劉良淑', '校園書房出版社', '1990', (SELECT category_id FROM categories WHERE code='H0000'), '斯托得闡明佈道的聖經根基與當代實踐,呼籲教會以整全的福音回應世界的需要。', 'seed');
