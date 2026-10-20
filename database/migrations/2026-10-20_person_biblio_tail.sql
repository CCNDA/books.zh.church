-- 作者欄書目尾巴清理（只動 107 個 person／114 本書）—— 由 tools/gen_scoped_person_sql.py 從
--   docs/review/2026-10-19_person_biblio_tail.tsv ＋ check_person_names.php --emit-sql 產生
-- Asana 1219613623198535；裁示：熊哥 2026-10-20 TG（訊息 944～947）
-- 刪 8 列只有 ISBN 的、併 66 列、改名 12 列、「哥本哈根出版社」(41456) 從作者刪；
-- 重切 8 列另跑 fix_person_names.php --book；人工 12 列不動。
-- ★ 跑法：Navicat 一段一段跑；A0 先跑、應回 0 列；最後 E 段每句應回 0 列再 COMMIT。
-- ★ book_persons 對 persons 有 ON DELETE CASCADE。

START TRANSACTION;

-- ═══ A0. 前置檢查（應回 0 列）：刪掉這些之後會變成沒有作者的書 ═══
SELECT bp.book_id, b.title, COUNT(*) AS 只剩這些關聯
  FROM book_persons bp JOIN books b ON b.book_id = bp.book_id
 WHERE bp.book_id IN (SELECT book_id FROM book_persons WHERE person_id IN (38830,44346,44812,44942,44943,44944,44972,44973,41456))
 GROUP BY bp.book_id, b.title
HAVING SUM(bp.person_id NOT IN (38830,44346,44812,44942,44943,44944,44972,44973,41456)) = 0;

-- ═══ A. 整列不是人（只有 ISBN）：8 列 ═══
DELETE FROM persons WHERE person_id = 38830;  -- 9789864001682(1 本,CASCADE 連 book_persons 一起清)
DELETE FROM persons WHERE person_id = 44346;  -- 9789579185158(1 本,CASCADE 連 book_persons 一起清)
DELETE FROM persons WHERE person_id = 44812;  -- 9789887844518(1 本,CASCADE 連 book_persons 一起清)
DELETE FROM persons WHERE person_id = 44942;  -- ISBN 9789626737262(1 本,CASCADE 連 book_persons 一起清)
DELETE FROM persons WHERE person_id = 44943;  -- ISBN 9789888788040(1 本,CASCADE 連 book_persons 一起清)
DELETE FROM persons WHERE person_id = 44944;  -- ISBN 9789626735206(1 本,CASCADE 連 book_persons 一起清)
DELETE FROM persons WHERE person_id = 44972;  -- ISBN 9789888642106(1 本,CASCADE 連 book_persons 一起清)
DELETE FROM persons WHERE person_id = 44973;  -- ISBN 9789888642168(1 本,CASCADE 連 book_persons 一起清)

-- ═══ A2. 「哥本哈根出版社 出版：漢語聖經協會」是出版社不是人：從作者刪（裁示 3） ═══
DELETE FROM persons WHERE person_id = 41456;  -- 哥本哈根出版社 出版：漢語聖經協會（2 本）

-- ═══ B. 併到既有同名列：66 列 ═══
UPDATE IGNORE book_persons SET person_id = 33677 WHERE person_id = 41479;
DELETE FROM persons WHERE person_id = 41479;  -- 林政嘉 出版：學園 → 林政嘉 (33677)
UPDATE IGNORE book_persons SET person_id = 33545 WHERE person_id = 41492;
DELETE FROM persons WHERE person_id = 41492;  -- 王建玫 出版：提攜文創 日期：2025年8月 → 王建玫 (33545)
UPDATE IGNORE book_persons SET person_id = 40699 WHERE person_id = 41511;
DELETE FROM persons WHERE person_id = 41511;  -- 金洪臣 出版：Greenhouse Publishing 日期：2013年 → 金洪臣 (40699)
UPDATE IGNORE book_persons SET person_id = 40669 WHERE person_id = 41514;
DELETE FROM persons WHERE person_id = 41514;  -- 張雲軒 書號：9781951474317 出版：Greenhouse Publishing 日期：2018年 → 張雲軒 (40669)
UPDATE IGNORE book_persons SET person_id = 16784 WHERE person_id = 41530;
DELETE FROM persons WHERE person_id = 41530;  -- 吳獻章 出版：道聲 → 吳獻章 (16784)
UPDATE IGNORE book_persons SET person_id = 33616 WHERE person_id = 41541;
DELETE FROM persons WHERE person_id = 41541;  -- 顧華德 出版：道聲 → 顧華德 (33616)
UPDATE IGNORE book_persons SET person_id = 33584 WHERE person_id = 41544;
DELETE FROM persons WHERE person_id = 41544;  -- 薛豐旻 出版：校園 日期：2026年1月10日 → 薛豐旻 (33584)
UPDATE IGNORE book_persons SET person_id = 22843 WHERE person_id = 41545;
DELETE FROM persons WHERE person_id = 41545;  -- 魏悌香 出版：啟示 → 魏悌香 (22843)
UPDATE IGNORE book_persons SET person_id = 36732 WHERE person_id = 41549;
DELETE FROM persons WHERE person_id = 41549;  -- 郭大維 出版：橄欖 → 郭大維 (36732)
UPDATE IGNORE book_persons SET person_id = 19443 WHERE person_id = 41551;
DELETE FROM persons WHERE person_id = 41551;  -- 雷健生 出版：文藝 日期：2025年12月 → 雷健生 (19443)
UPDATE IGNORE book_persons SET person_id = 33534 WHERE person_id = 41554;
DELETE FROM persons WHERE person_id = 41554;  -- 張聰 出版：橄欖 → 張聰 (33534)
UPDATE IGNORE book_persons SET person_id = 33523 WHERE person_id = 41560;
DELETE FROM persons WHERE person_id = 41560;  -- 劉如菁 出版：校園 日期：2024年3月 → 劉如菁 (33523)
UPDATE IGNORE book_persons SET person_id = 34631 WHERE person_id = 41567;
DELETE FROM persons WHERE person_id = 41567;  -- 俞一菱 出版：以琳 日期：2022年4月 → 俞一菱 (34631)
UPDATE IGNORE book_persons SET person_id = 19953 WHERE person_id = 41572;
DELETE FROM persons WHERE person_id = 41572;  -- 許陳慧勤 出版：許志明 日期：2025年11月 → 許陳慧勤 (19953)
UPDATE IGNORE book_persons SET person_id = 16942 WHERE person_id = 41587;
DELETE FROM persons WHERE person_id = 41587;  -- 柯志明 出版：聖資伊利亞 → 柯志明 (16942)
UPDATE IGNORE book_persons SET person_id = 33662 WHERE person_id = 41593;
DELETE FROM persons WHERE person_id = 41593;  -- 王怡方 出版：校園 日期：2025年12月25日 → 王怡方 (33662)
UPDATE IGNORE book_persons SET person_id = 18016 WHERE person_id = 41595;
DELETE FROM persons WHERE person_id = 41595;  -- 林鴻信 出版：橄欖 日期：2025年10月 → 林鴻信 (18016)
UPDATE IGNORE book_persons SET person_id = 34539 WHERE person_id = 41605;
DELETE FROM persons WHERE person_id = 41605;  -- 邱昭文 出版：華神 → 邱昭文 (34539)
UPDATE IGNORE book_persons SET person_id = 21213 WHERE person_id = 41607;
DELETE FROM persons WHERE person_id = 41607;  -- 陳子苓 出版：橄欖 日期：2026年1月 → 陳子苓 (21213)
UPDATE IGNORE book_persons SET person_id = 22834 WHERE person_id = 41618;
DELETE FROM persons WHERE person_id = 41618;  -- 校園同工團隊 出版：校園 日期：2026年1月 → 校園同工團隊 (22834)
UPDATE IGNORE book_persons SET person_id = 33626 WHERE person_id = 41622;
DELETE FROM persons WHERE person_id = 41622;  -- 王玉楷 出版：校園 日期：2025年12月25日 → 王玉楷 (33626)
UPDATE IGNORE book_persons SET person_id = 35127 WHERE person_id = 41636;
DELETE FROM persons WHERE person_id = 41636;  -- 李明夏 出版：以琳 日期：2019年10月 → 李明夏 (35127)
UPDATE IGNORE book_persons SET person_id = 18173 WHERE person_id = 41647;
DELETE FROM persons WHERE person_id = 41647;  -- 唐崇榮 出版：STEMI → 唐崇榮 (18173)
UPDATE IGNORE book_persons SET person_id = 17616 WHERE person_id = 41676;
DELETE FROM persons WHERE person_id = 41676;  -- 鮑會園 出版： 天道書樓 初版： 2004–4 | 2013–5（第3次印刷） → 鮑會園 (17616)
UPDATE IGNORE book_persons SET person_id = 33334 WHERE person_id = 41688;
DELETE FROM persons WHERE person_id = 41688;  -- 莊國祥 出版：以琳 日期：2020年5月 (二版一刷) → 莊國祥 (33334)
UPDATE IGNORE book_persons SET person_id = 23092 WHERE person_id = 41722;
DELETE FROM persons WHERE person_id = 41722;  -- 何沛禮 出版：德慧文化 日期：2025年5月 → 何沛禮 (23092)
UPDATE IGNORE book_persons SET person_id = 35132 WHERE person_id = 41724;
DELETE FROM persons WHERE person_id = 41724;  -- 吳葭 出版：以琳 日期：2019年2月 (二版一刷) → 吳葭 (35132)
UPDATE IGNORE book_persons SET person_id = 16967 WHERE person_id = 41734;
DELETE FROM persons WHERE person_id = 41734;  -- 彭盛有等 出版：橄欖 → 彭盛有 (16967)
UPDATE IGNORE book_persons SET person_id = 51329 WHERE person_id = 41749;
DELETE FROM persons WHERE person_id = 41749;  -- 李華民 出版：文橋 → 李華民 (51329)
UPDATE IGNORE book_persons SET person_id = 16750 WHERE person_id = 41758;
DELETE FROM persons WHERE person_id = 41758;  -- 何照洪 出版：天恩 日期：2025年12月 → 何照洪 (16750)
UPDATE IGNORE book_persons SET person_id = 33400 WHERE person_id = 41763;
DELETE FROM persons WHERE person_id = 41763;  -- 橄欖編輯部 出版：橄欖 日期：2026年1月 → 橄欖編輯部 (33400)
UPDATE IGNORE book_persons SET person_id = 18287 WHERE person_id = 41789;
DELETE FROM persons WHERE person_id = 41789;  -- 張輝道 出版：道聲 → 張輝道 (18287)
UPDATE IGNORE book_persons SET person_id = 19432 WHERE person_id = 41795;
DELETE FROM persons WHERE person_id = 41795;  -- 高曼蓉 出版：以琳 → 高曼蓉 (19432)
UPDATE IGNORE book_persons SET person_id = 33534 WHERE person_id = 41814;
DELETE FROM persons WHERE person_id = 41814;  -- 張聰 出版：聖經資源中心 日期：2023年1月 → 張聰 (33534)
UPDATE IGNORE book_persons SET person_id = 33711 WHERE person_id = 41829;
DELETE FROM persons WHERE person_id = 41829;  -- 鄧揚品 出版：基督使者協會 → 鄧揚品 (33711)
UPDATE IGNORE book_persons SET person_id = 33509 WHERE person_id = 41846;
DELETE FROM persons WHERE person_id = 41846;  -- 以琳書房編譯小組 出版：Global Trust Partners / 以琳總代理 → 以琳書房編譯小組 (33509)
UPDATE IGNORE book_persons SET person_id = 33384 WHERE person_id = 41933;
DELETE FROM persons WHERE person_id = 41933;  -- 馮凱稜 出版：漢語聖經協會 → 馮凱稜 (33384)
UPDATE IGNORE book_persons SET person_id = 26211 WHERE person_id = 41949;
DELETE FROM persons WHERE person_id = 41949;  -- Tim Dowley 出版：漢語聖經協會 → Tim Dowley (26211)
UPDATE IGNORE book_persons SET person_id = 33444 WHERE person_id = 42014;
DELETE FROM persons WHERE person_id = 42014;  -- 麥穗 出版：宗教教育中心 日期：2024年5月 → 麥穗 (33444)
UPDATE IGNORE book_persons SET person_id = 33444 WHERE person_id = 42016;
DELETE FROM persons WHERE person_id = 42016;  -- 麥穗 出版：宗教教育中心 日期：2024年9月 → 麥穗 (33444)
UPDATE IGNORE book_persons SET person_id = 19769 WHERE person_id = 42089;
DELETE FROM persons WHERE person_id = 42089;  -- 編輯小組 出版：宣道傳意 日期：2023年6月 → 編輯小組 (19769)
UPDATE IGNORE book_persons SET person_id = 33444 WHERE person_id = 42099;
DELETE FROM persons WHERE person_id = 42099;  -- 麥穗 出版：宗教教育中心 日期：2024年6月 → 麥穗 (33444)
UPDATE IGNORE book_persons SET person_id = 17149 WHERE person_id = 42103;
DELETE FROM persons WHERE person_id = 42103;  -- 郭鴻標 出版：文藝 日期：2024年7月 → 郭鴻標 (17149)
UPDATE IGNORE book_persons SET person_id = 17294 WHERE person_id = 42146;
DELETE FROM persons WHERE person_id = 42146;  -- 溫偉耀 出版： 明風 日期： 2024年10月 → 溫偉耀 (17294)
UPDATE IGNORE book_persons SET person_id = 33912 WHERE person_id = 42151;
DELETE FROM persons WHERE person_id = 42151;  -- 何國強 書號： 9786267136539 出版：以琳 日期： 2023 年 4 月（三版） → 何國強 (33912)
UPDATE IGNORE book_persons SET person_id = 23205 WHERE person_id = 42310;
DELETE FROM persons WHERE person_id = 42310;  -- 陳衍昌 出版：文藝 日期：2024年7月 → 陳衍昌 (23205)
UPDATE IGNORE book_persons SET person_id = 16745 WHERE person_id = 42322;
DELETE FROM persons WHERE person_id = 42322;  -- 高銘謙 出版： 宣道 日期： 2024年5月 → 高銘謙 (16745)
UPDATE IGNORE book_persons SET person_id = 18427 WHERE person_id = 42383;
DELETE FROM persons WHERE person_id = 42383;  -- 黃小石 ISBN ： 9789577274281 → 黃小石 (18427)
UPDATE IGNORE book_persons SET person_id = 19283 WHERE person_id = 42432;
DELETE FROM persons WHERE person_id = 42432;  -- 延玲珍 出版：天恩 日期：2023年10月 → 延玲珍 (19283)
UPDATE IGNORE book_persons SET person_id = 36331 WHERE person_id = 42579;
DELETE FROM persons WHERE person_id = 42579;  -- 陳群英 出版：文藝 日期：2024年12月 → 陳群英 (36331)
UPDATE IGNORE book_persons SET person_id = 21970 WHERE person_id = 42612;
DELETE FROM persons WHERE person_id = 42612;  -- 黃慶雲 出版： 中國主日學協會 日期： 2025年12月 → 黃慶雲 (21970)
UPDATE IGNORE book_persons SET person_id = 23425 WHERE person_id = 42651;
DELETE FROM persons WHERE person_id = 42651;  -- 思路．豐 出版：宣道 → 思路．豐 (23425)
UPDATE IGNORE book_persons SET person_id = 23009 WHERE person_id = 42670;
DELETE FROM persons WHERE person_id = 42670;  -- 宣道會宣愛堂同工 出版：宣道 → 宣道會宣愛堂同工 (23009)
UPDATE IGNORE book_persons SET person_id = 45391 WHERE person_id = 42714;
DELETE FROM persons WHERE person_id = 42714;  -- 區伯平 (Au Pak Ping) 出版：宣道 日期：2015年12月 → 區伯平 (Au Pak Ping) (45391)
UPDATE IGNORE book_persons SET person_id = 18750 WHERE person_id = 42809;
DELETE FROM persons WHERE person_id = 42809;  -- 劉志良 出版：播道會 → 劉志良 (18750)
UPDATE IGNORE book_persons SET person_id = 22389 WHERE person_id = 42828;
DELETE FROM persons WHERE person_id = 42828;  -- 陳恩明 ISBN ： 9789888562442 出版：突破 → 陳恩明 (22389)
UPDATE IGNORE book_persons SET person_id = 33377 WHERE person_id = 42872;
DELETE FROM persons WHERE person_id = 42872;  -- 郭熙安 書號：9786269622818 → 郭熙安 (33377)
UPDATE IGNORE book_persons SET person_id = 29267 WHERE person_id = 42937;
DELETE FROM persons WHERE person_id = 42937;  -- 張俊明 書號： 9789670536903 出版： Faith Books 日期： 2023 年 → 張俊明 (29267)
UPDATE IGNORE book_persons SET person_id = 41341 WHERE person_id = 42952;
DELETE FROM persons WHERE person_id = 42952;  -- 周怡 出版：Greenhouse Publishing 日期：2014年 → 周怡 (41341)
UPDATE IGNORE book_persons SET person_id = 41932 WHERE person_id = 43816;
DELETE FROM persons WHERE person_id = 43816;  -- 戴肯尼 (Kenneth N. Taylor) 出版 : 漢語聖經公會 Chinese Bible International → 戴肯尼 (Kenneth N. Taylor) (41932)
UPDATE IGNORE book_persons SET person_id = 19944 WHERE person_id = 43822;
DELETE FROM persons WHERE person_id = 43822;  -- 蔡珮珺 出版：真哪噠 日期：2025年12月 → 蔡珮珺 (19944)
UPDATE IGNORE book_persons SET person_id = 33607 WHERE person_id = 43824;
DELETE FROM persons WHERE person_id = 43824;  -- 周明芹 出版：啟示 → 周明芹 (33607)
UPDATE IGNORE book_persons SET person_id = 16881 WHERE person_id = 47641;
DELETE FROM persons WHERE person_id = 47641;  -- 蔡錦圖 出版：浸信會 → 蔡錦圖 (16881)
UPDATE IGNORE book_persons SET person_id = 18359 WHERE person_id = 47642;
DELETE FROM persons WHERE person_id = 47642;  -- 潘柏昌 出版： 浸信會 日期： 2025年12月 → 潘柏昌 (18359)
UPDATE IGNORE book_persons SET person_id = 16856 WHERE person_id = 47648;
DELETE FROM persons WHERE person_id = 47648;  -- 李常受ISBN：978-986-166-114-8 → 李常受 (16856)
UPDATE IGNORE book_persons SET person_id = 33538 WHERE person_id = 52729;
DELETE FROM persons WHERE person_id = 52729;  -- 潘嘉媚 出版：宗教教育中心 日期：2022年7月 → 潘嘉媚 (33538)

-- ═══ C. 改名：12 列 ═══
UPDATE persons SET name = '衛理牧者' WHERE person_id = 41428;  -- 原「衛理牧者 出版：馬來西亞基督教衛理公會砂拉越華人年議會文字事業部 發行：衛理福音書局」
UPDATE persons SET name = '賴婉君' WHERE person_id = 41460;  -- 原「賴婉君 出版：宗教教育中心」
UPDATE persons SET name = '餘晨揚' WHERE person_id = 41517;  -- 原「餘晨揚 出版：道聲」
UPDATE persons SET name = '候淑婧' WHERE person_id = 41616;  -- 原「候淑婧 出版：Greenhouse Publishing 日期：2014年」
UPDATE persons SET name = '點滴' WHERE person_id = 41714;  -- 原「點滴 出版： 天道 日期： 2023年4月」
UPDATE persons SET name = '唐亦幹' WHERE person_id = 42284;  -- 原「唐亦幹 出版： 中國學園傳道會」
UPDATE persons SET name = '辛西亞·湯白斯 / 卡羅爾·芬克' WHERE person_id = 42355;  -- 原「辛西亞·湯白斯 / 卡羅爾·芬克 ISBN ： 9787510857294」
UPDATE persons SET name = '黃侯民' WHERE person_id = 42372;  -- 原「黃侯民 出版：馬來西亞真愛家庭協會」
UPDATE persons SET name = '唐澄暐' WHERE person_id = 42395;  -- 原「唐澄暐 ISBN ： 9789865524272」
UPDATE persons SET name = '柯哲輝' WHERE person_id = 42945;  -- 原「柯哲輝 出版：協傳培訓中心」
UPDATE persons SET name = '熊小瑩' WHERE person_id = 42966;  -- 原「熊小瑩 出版： 馬來西亞基督教衛理公會 砂拉越華人年議會文字事業部」
UPDATE persons SET name = '廖潔嫻' WHERE person_id = 47640;  -- 原「廖潔嫻 出版：環球傳播」

-- ═══ E. 回查（COMMIT 之前，每一句應回 0 列） ═══
-- E1. 要刪／要併的 person_id 都不在了
SELECT person_id, name FROM persons WHERE person_id IN (38830,44346,44812,44942,44943,44944,44972,44973,41479,41492,41511,41514,41530,41541,41544,41545,41549,41551,41554,41560,41567,41572,41587,41593,41595,41605,41607,41618,41622,41636,41647,41676,41688,41722,41724,41734,41749,41758,41763,41789,41795,41814,41829,41846,41933,41949,42014,42016,42089,42099,42103,42146,42151,42310,42322,42383,42432,42579,42612,42651,42670,42714,42809,42828,42872,42937,42952,43816,43822,43824,47641,47642,47648,52729,41456);
-- E2. 沒有孤兒關聯
SELECT bp.person_id, COUNT(*) FROM book_persons bp LEFT JOIN persons p ON p.person_id = bp.person_id
 WHERE p.person_id IS NULL GROUP BY bp.person_id;
-- E3. 這批 person 的名字不該再有 ISBN／書號／出版：
SELECT person_id, name FROM persons WHERE person_id IN (41428,41460,41517,41616,41714,42284,42355,42372,42395,42945,42966,47640)
   AND (name REGEXP '97[89][0-9]{10}' OR name LIKE '%ISBN%' OR name LIKE '%書號%' OR name LIKE '%出版：%');
-- E4. 總量（記下來寫進 Asana）
SELECT (SELECT COUNT(*) FROM persons) AS persons_total, (SELECT COUNT(*) FROM book_persons) AS book_persons_total;

-- 紀錄
INSERT INTO schema_migrations (version, applied_at, note) VALUES
  ('2026-10-20_person_biblio_tail.sql', NOW(), '作者欄書目尾巴清理：刪 9、併 66、改名 12（107 person／114 本）')
ON DUPLICATE KEY UPDATE note = VALUES(note);

-- 以上全部 0 列：COMMIT;   有任何一句回了列：ROLLBACK;
