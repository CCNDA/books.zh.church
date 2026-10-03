-- persons 表清理 —— 由 tools/check_person_names.php rev 2026-10-03.7 產生
-- 產生時間:2026-10-03 16:31:40    Asana 1218277971470042
--
-- ★ 跑法:Navicat 對遠端 DB,**一段一段跑**,每段跑完看回查數字再跑下一段。
-- ★ book_persons 對 persons 有 ON DELETE CASCADE,所以 DELETE FROM persons
--   會順手清掉 UPDATE IGNORE 撞 uq_book_person_role 而留下的那幾列。這是刻意的。
-- ★ 本檔**不含** long_text 與「未知差異」的變體 —— 那兩類沒有安全的自動解,人工處理。
-- ★★ 合併/改名(B、C 段)只含**放行過的剝除詞**:著、主編、編著、口述、原著、編選、等編、主編:、等合編、總編輯、等編著、總審訂、編註、彙編、撰、等人合著、譯者、文\圖:、繪圖:、編撰:、總編輯:、譯者:、編輯:、撰著
--    另有 1148 列因為剝除詞未放行而**沒有**產生 SQL。
--    放行方式:看過報表最後那張「剝除詞分布」,挑比例高、例子無誤的詞,
--    再跑 --notes=著,主編,編著 這樣(詞之間用逗號)。
-- ★ 本檔**不改 book_persons.role**:「黃伯和編」併進「黃伯和」之後,那 3 筆關聯
--   仍然記成 author(它本來就是從 authors_raw 進來的)。角色正確化只對
--   **之後新匯入的書**生效(import.php 改用署名裡的角色)。既有關聯的角色
--   要不要回頭修,是另一件事,本票不處理。

-- ★★ 整檔直接執行(Navicat:開檔 → 執行),不要只選一段。
-- ★★ 跑完**先不要 COMMIT** —— 看最後那句回查的數字,對了再在**同一個查詢視窗**
--    手動下 COMMIT;  數字不對就下 ROLLBACK;
-- ★★ 回查一定要在同一個視窗跑:Navicat 每個視窗是獨立連線,
--    別的連線看不到還沒 COMMIT 的修改(2026-10-03 實際踩到)。

-- ═══ 第 1 段:併入既有正規列(949 組,1898 句)═══
-- 預期:persons 36883 → **35934**(整整少 949);
--       book_persons 81040 → 少一點點(重複關聯撞 uq_book_person_role,CASCADE 清掉)。

START TRANSACTION;

-- ═══ B. 併入既有正規列:949 列 ═══
UPDATE IGNORE book_persons SET person_id = 30797 WHERE person_id = 16778;
DELETE FROM persons WHERE person_id = 16778;  -- 詹遜著 → 詹遜 (30797)
UPDATE IGNORE book_persons SET person_id = 16794 WHERE person_id = 16804;
DELETE FROM persons WHERE person_id = 16804;  -- 歐爾德著 → 歐爾德 (16794)
UPDATE IGNORE book_persons SET person_id = 20625 WHERE person_id = 16842;
DELETE FROM persons WHERE person_id = 16842;  -- 王正中主編 → 王正中 (20625)
UPDATE IGNORE book_persons SET person_id = 16757 WHERE person_id = 16898;
DELETE FROM persons WHERE person_id = 16898;  -- 唐佑之著 → 唐佑之 (16757)
UPDATE IGNORE book_persons SET person_id = 18832 WHERE person_id = 16927;
DELETE FROM persons WHERE person_id = 16927;  -- 何崇謙主編 → 何崇謙 (18832)
UPDATE IGNORE book_persons SET person_id = 17062 WHERE person_id = 16928;
DELETE FROM persons WHERE person_id = 16928;  -- 高偉勳著 → 高偉勳 (17062)
UPDATE IGNORE book_persons SET person_id = 45371 WHERE person_id = 16945;
DELETE FROM persons WHERE person_id = 16945;  -- 葉劍華著 → 葉劍華 (45371)
UPDATE IGNORE book_persons SET person_id = 32554 WHERE person_id = 16954;
DELETE FROM persons WHERE person_id = 16954;  -- 蘇克著 → 蘇克 (32554)
UPDATE IGNORE book_persons SET person_id = 34861 WHERE person_id = 16955;
DELETE FROM persons WHERE person_id = 16955;  -- 華德.凱瑟著 → 華德.凱瑟 (34861)
UPDATE IGNORE book_persons SET person_id = 17495 WHERE person_id = 16959;
DELETE FROM persons WHERE person_id = 16959;  -- 于中旻著 → 于中旻 (17495)
UPDATE IGNORE book_persons SET person_id = 34632 WHERE person_id = 17053;
DELETE FROM persons WHERE person_id = 17053;  -- 波特/主編 → 波特 (34632)
UPDATE IGNORE book_persons SET person_id = 28506 WHERE person_id = 17155;
DELETE FROM persons WHERE person_id = 17155;  -- 黃迦勒主編 → 黃迦勒 (28506)
UPDATE IGNORE book_persons SET person_id = 32868 WHERE person_id = 17160;
DELETE FROM persons WHERE person_id = 17160;  -- 培恩著 → 培恩 (32868)
UPDATE IGNORE book_persons SET person_id = 16775 WHERE person_id = 17166;
DELETE FROM persons WHERE person_id = 17166;  -- 丘恩處著 → 丘恩處 (16775)
UPDATE IGNORE book_persons SET person_id = 46424 WHERE person_id = 17167;
DELETE FROM persons WHERE person_id = 17167;  -- Edward P. Blair著 → Edward P. Blair (46424)
UPDATE IGNORE book_persons SET person_id = 17262 WHERE person_id = 17194;
DELETE FROM persons WHERE person_id = 17194;  -- 漆立平.漆哈拿編著 → 漆立平.漆哈拿 (17262)
UPDATE IGNORE book_persons SET person_id = 16953 WHERE person_id = 17202;
DELETE FROM persons WHERE person_id = 17202;  -- 黃錫木主編 → 黃錫木 (16953)
UPDATE IGNORE book_persons SET person_id = 36240 WHERE person_id = 17203;
DELETE FROM persons WHERE person_id = 17203;  -- 魏立健著 → 魏立健 (36240)
UPDATE IGNORE book_persons SET person_id = 16894 WHERE person_id = 17207;
DELETE FROM persons WHERE person_id = 17207;  -- 馬有藻著 → 馬有藻 (16894)
UPDATE IGNORE book_persons SET person_id = 17702 WHERE person_id = 17208;
DELETE FROM persons WHERE person_id = 17208;  -- 周聯華著 → 周聯華 (17702)
UPDATE IGNORE book_persons SET person_id = 16729 WHERE person_id = 17211;
DELETE FROM persons WHERE person_id = 17211;  -- 江守道著 → 江守道 (16729)
UPDATE IGNORE book_persons SET person_id = 32919 WHERE person_id = 17212;
DELETE FROM persons WHERE person_id = 17212;  -- 司徒德著 → 司徒德 (32919)
UPDATE IGNORE book_persons SET person_id = 17159 WHERE person_id = 17247;
DELETE FROM persons WHERE person_id = 17247;  -- 蔡忠梅著 → 蔡忠梅 (17159)
UPDATE IGNORE book_persons SET person_id = 32256 WHERE person_id = 17248;
DELETE FROM persons WHERE person_id = 17248;  -- 慕聖著 → 慕聖 (32256)
UPDATE IGNORE book_persons SET person_id = 18638 WHERE person_id = 17261;
DELETE FROM persons WHERE person_id = 17261;  -- 盧德 主編 → 盧德 (18638)
UPDATE IGNORE book_persons SET person_id = 18680 WHERE person_id = 17270;
DELETE FROM persons WHERE person_id = 17270;  -- 路益師著 → 路益師 (18680)
UPDATE IGNORE book_persons SET person_id = 22347 WHERE person_id = 17306;
DELETE FROM persons WHERE person_id = 17306;  -- 陳耀南著 → 陳耀南 (22347)
UPDATE IGNORE book_persons SET person_id = 16760 WHERE person_id = 17324;
DELETE FROM persons WHERE person_id = 17324;  -- 賴特著 → 賴特 (16760)
UPDATE IGNORE book_persons SET person_id = 36972 WHERE person_id = 17325;
DELETE FROM persons WHERE person_id = 17325;  -- 赫戴維著 → 赫戴維 (36972)
UPDATE IGNORE book_persons SET person_id = 22171 WHERE person_id = 17328;
DELETE FROM persons WHERE person_id = 17328;  -- 曾立煌著 → 曾立煌 (22171)
UPDATE IGNORE book_persons SET person_id = 32028 WHERE person_id = 17329;
DELETE FROM persons WHERE person_id = 17329;  -- 許公遂著 → 許公遂 (32028)
UPDATE IGNORE book_persons SET person_id = 16899 WHERE person_id = 17330;
DELETE FROM persons WHERE person_id = 17330;  -- 倪柝聲著 → 倪柝聲 (16899)
UPDATE IGNORE book_persons SET person_id = 17562 WHERE person_id = 17387;
DELETE FROM persons WHERE person_id = 17387;  -- 賈玉銘著 → 賈玉銘 (17562)
UPDATE IGNORE book_persons SET person_id = 34359 WHERE person_id = 17389;
DELETE FROM persons WHERE person_id = 17389;  -- 華恩德著 → 華恩德 (34359)
UPDATE IGNORE book_persons SET person_id = 36966 WHERE person_id = 17392;
DELETE FROM persons WHERE person_id = 17392;  -- 坎伯.摩根著 → 坎伯.摩根 (36966)
UPDATE IGNORE book_persons SET person_id = 32983 WHERE person_id = 17427;
DELETE FROM persons WHERE person_id = 17427;  -- 弗里曼著 → 弗里曼 (32983)
UPDATE IGNORE book_persons SET person_id = 16953 WHERE person_id = 17527;
DELETE FROM persons WHERE person_id = 17527;  -- 黃錫木編著 → 黃錫木 (16953)
UPDATE IGNORE book_persons SET person_id = 17312 WHERE person_id = 17537;
DELETE FROM persons WHERE person_id = 17537;  -- 梁家麟著 → 梁家麟 (17312)
UPDATE IGNORE book_persons SET person_id = 17200 WHERE person_id = 17559;
DELETE FROM persons WHERE person_id = 17559;  -- 梁廷益 等編 → 梁廷益 (17200)
UPDATE IGNORE book_persons SET person_id = 17545 WHERE person_id = 17564;
DELETE FROM persons WHERE person_id = 17564;  -- 陳嘉式著 → 陳嘉式 (17545)
UPDATE IGNORE book_persons SET person_id = 17025 WHERE person_id = 17630;
DELETE FROM persons WHERE person_id = 17630;  -- 盧龍光主編 → 盧龍光 (17025)
UPDATE IGNORE book_persons SET person_id = 24390 WHERE person_id = 17634;
DELETE FROM persons WHERE person_id = 17634;  -- 沈保羅著 → 沈保羅 (24390)
UPDATE IGNORE book_persons SET person_id = 17153 WHERE person_id = 17665;
DELETE FROM persons WHERE person_id = 17665;  -- 陳尊德著 → 陳尊德 (17153)
UPDATE IGNORE book_persons SET person_id = 17671 WHERE person_id = 17673;
DELETE FROM persons WHERE person_id = 17673;  -- 鍾馬田著 → 鍾馬田 (17671)
UPDATE IGNORE book_persons SET person_id = 17687 WHERE person_id = 17676;
DELETE FROM persons WHERE person_id = 17676;  -- 楊濬哲著 → 楊濬哲 (17687)
UPDATE IGNORE book_persons SET person_id = 17616 WHERE person_id = 17677;
DELETE FROM persons WHERE person_id = 17677;  -- 鮑會園著 → 鮑會園 (17616)
UPDATE IGNORE book_persons SET person_id = 28506 WHERE person_id = 17678;
DELETE FROM persons WHERE person_id = 17678;  -- 黃迦勒 主編 → 黃迦勒 (28506)
UPDATE IGNORE book_persons SET person_id = 17345 WHERE person_id = 17713;
DELETE FROM persons WHERE person_id = 17713;  -- 楊世禮 著 → 楊世禮 (17345)
UPDATE IGNORE book_persons SET person_id = 49830 WHERE person_id = 17721;
DELETE FROM persons WHERE person_id = 17721;  -- 凱沙著 → 凱沙 (49830)
UPDATE IGNORE book_persons SET person_id = 20053 WHERE person_id = 17738;
DELETE FROM persons WHERE person_id = 17738;  -- 滕近輝著 → 滕近輝 (20053)
UPDATE IGNORE book_persons SET person_id = 16893 WHERE person_id = 17750;
DELETE FROM persons WHERE person_id = 17750;  -- 陳終道著 → 陳終道 (16893)
UPDATE IGNORE book_persons SET person_id = 17164 WHERE person_id = 17754;
DELETE FROM persons WHERE person_id = 17754;  -- 吳理恩著 → 吳理恩 (17164)
UPDATE IGNORE book_persons SET person_id = 27930 WHERE person_id = 17769;
DELETE FROM persons WHERE person_id = 17769;  -- 莊進源編著 → 莊進源 (27930)
UPDATE IGNORE book_persons SET person_id = 17084 WHERE person_id = 17779;
DELETE FROM persons WHERE person_id = 17779;  -- 梁潔瓊著 → 梁潔瓊 (17084)
UPDATE IGNORE book_persons SET person_id = 36239 WHERE person_id = 17789;
DELETE FROM persons WHERE person_id = 17789;  -- 郝思韋恩著 → 郝思韋恩 (36239)
UPDATE IGNORE book_persons SET person_id = 17808 WHERE person_id = 17824;
DELETE FROM persons WHERE person_id = 17824;  -- 郭乃惇 編著 → 郭乃惇 (17808)
UPDATE IGNORE book_persons SET person_id = 26585 WHERE person_id = 17835;
DELETE FROM persons WHERE person_id = 17835;  -- 邁爾著 → 邁爾 (26585)
UPDATE IGNORE book_persons SET person_id = 33013 WHERE person_id = 17841;
DELETE FROM persons WHERE person_id = 17841;  -- 史托斯著 → 史托斯 (33013)
UPDATE IGNORE book_persons SET person_id = 17803 WHERE person_id = 17842;
DELETE FROM persons WHERE person_id = 17842;  -- 王明道著 → 王明道 (17803)
UPDATE IGNORE book_persons SET person_id = 17553 WHERE person_id = 17854;
DELETE FROM persons WHERE person_id = 17854;  -- 區伯平著 → 區伯平 (17553)
UPDATE IGNORE book_persons SET person_id = 28276 WHERE person_id = 17869;
DELETE FROM persons WHERE person_id = 17869;  -- 唐崇平著 → 唐崇平 (28276)
UPDATE IGNORE book_persons SET person_id = 31826 WHERE person_id = 17870;
DELETE FROM persons WHERE person_id = 17870;  -- 倪克遜著 → 倪克遜 (31826)
UPDATE IGNORE book_persons SET person_id = 33147 WHERE person_id = 17871;
DELETE FROM persons WHERE person_id = 17871;  -- 丁良才著 → 丁良才 (33147)
UPDATE IGNORE book_persons SET person_id = 17836 WHERE person_id = 17882;
DELETE FROM persons WHERE person_id = 17882;  -- 駱其雅著 → 駱其雅 (17836)
UPDATE IGNORE book_persons SET person_id = 40972 WHERE person_id = 17889;
DELETE FROM persons WHERE person_id = 17889;  -- 劉遠見等合編 → 劉遠見 (40972)
UPDATE IGNORE book_persons SET person_id = 18730 WHERE person_id = 17899;
DELETE FROM persons WHERE person_id = 17899;  -- 劉梓濠 主編 → 劉梓濠 (18730)
UPDATE IGNORE book_persons SET person_id = 18375 WHERE person_id = 17904;
DELETE FROM persons WHERE person_id = 17904;  -- 趙崇明主編 → 趙崇明 (18375)
UPDATE IGNORE book_persons SET person_id = 19649 WHERE person_id = 17906;
DELETE FROM persons WHERE person_id = 17906;  -- 陳智衡主編 → 陳智衡 (19649)
UPDATE IGNORE book_persons SET person_id = 17915 WHERE person_id = 17914;
DELETE FROM persons WHERE person_id = 17914;  -- 林治平主編 → 林治平 (17915)
UPDATE IGNORE book_persons SET person_id = 37795 WHERE person_id = 17932;
DELETE FROM persons WHERE person_id = 17932;  -- 姚西伊.宋軍主編 → 姚西伊.宋軍 (37795)
UPDATE IGNORE book_persons SET person_id = 46359 WHERE person_id = 17970;
DELETE FROM persons WHERE person_id = 17970;  -- 貝查著 → 貝查 (46359)
UPDATE IGNORE book_persons SET person_id = 17503 WHERE person_id = 17976;
DELETE FROM persons WHERE person_id = 17976;  -- 謝家樹著 → 謝家樹 (17503)
UPDATE IGNORE book_persons SET person_id = 17010 WHERE person_id = 17979;
DELETE FROM persons WHERE person_id = 17979;  -- 陳希曾著 → 陳希曾 (17010)
UPDATE IGNORE book_persons SET person_id = 32989 WHERE person_id = 17980;
DELETE FROM persons WHERE person_id = 17980;  -- 索斯著 → 索斯 (32989)
UPDATE IGNORE book_persons SET person_id = 18173 WHERE person_id = 17981;
DELETE FROM persons WHERE person_id = 17981;  -- 唐崇榮著 → 唐崇榮 (18173)
UPDATE IGNORE book_persons SET person_id = 40672 WHERE person_id = 17986;
DELETE FROM persons WHERE person_id = 17986;  -- 何大衛著 → 何大衛 (40672)
UPDATE IGNORE book_persons SET person_id = 16793 WHERE person_id = 17987;
DELETE FROM persons WHERE person_id = 17987;  -- 王國顯著 → 王國顯 (16793)
UPDATE IGNORE book_persons SET person_id = 17234 WHERE person_id = 18005;
DELETE FROM persons WHERE person_id = 18005;  -- 周永健 主編 → 周永健 (17234)
UPDATE IGNORE book_persons SET person_id = 17523 WHERE person_id = 18015;
DELETE FROM persons WHERE person_id = 18015;  -- 黃根春主編 → 黃根春 (17523)
UPDATE IGNORE book_persons SET person_id = 23929 WHERE person_id = 18020;
DELETE FROM persons WHERE person_id = 18020;  -- 任以撒著 → 任以撒 (23929)
UPDATE IGNORE book_persons SET person_id = 18019 WHERE person_id = 18037;
DELETE FROM persons WHERE person_id = 18037;  -- 巴刻著 → 巴刻 (18019)
UPDATE IGNORE book_persons SET person_id = 17454 WHERE person_id = 18040;
DELETE FROM persons WHERE person_id = 18040;  -- 沈介山著 → 沈介山 (17454)
UPDATE IGNORE book_persons SET person_id = 17110 WHERE person_id = 18073;
DELETE FROM persons WHERE person_id = 18073;  -- 鄺炳釗 編著 → 鄺炳釗 (17110)
UPDATE IGNORE book_persons SET person_id = 39339 WHERE person_id = 18084;
DELETE FROM persons WHERE person_id = 18084;  -- 中主著 → 中主 (39339)
UPDATE IGNORE book_persons SET person_id = 46809 WHERE person_id = 18114;
DELETE FROM persons WHERE person_id = 18114;  -- 戴馬雷著 → 戴馬雷 (46809)
UPDATE IGNORE book_persons SET person_id = 43279 WHERE person_id = 18117;
DELETE FROM persons WHERE person_id = 18117;  -- 周文同著 → 周文同 (43279)
UPDATE IGNORE book_persons SET person_id = 48541 WHERE person_id = 18118;
DELETE FROM persons WHERE person_id = 18118;  -- 吳達維著 → 吳達維 (48541)
UPDATE IGNORE book_persons SET person_id = 20927 WHERE person_id = 18132;
DELETE FROM persons WHERE person_id = 18132;  -- 柯希能著 → 柯希能 (20927)
UPDATE IGNORE book_persons SET person_id = 37925 WHERE person_id = 18154;
DELETE FROM persons WHERE person_id = 18154;  -- 屠由信編著 → 屠由信 (37925)
UPDATE IGNORE book_persons SET person_id = 17965 WHERE person_id = 18168;
DELETE FROM persons WHERE person_id = 18168;  -- 鄧紹光主編 → 鄧紹光 (17965)
UPDATE IGNORE book_persons SET person_id = 18056 WHERE person_id = 18174;
DELETE FROM persons WHERE person_id = 18174;  -- 陶恕著 → 陶恕 (18056)
UPDATE IGNORE book_persons SET person_id = 18149 WHERE person_id = 18178;
DELETE FROM persons WHERE person_id = 18178;  -- 葉光明著 → 葉光明 (18149)
UPDATE IGNORE book_persons SET person_id = 46114 WHERE person_id = 18179;
DELETE FROM persons WHERE person_id = 18179;  -- 貝珍珠編著 → 貝珍珠 (46114)
UPDATE IGNORE book_persons SET person_id = 19513 WHERE person_id = 18180;
DELETE FROM persons WHERE person_id = 18180;  -- 林道亮著 → 林道亮 (19513)
UPDATE IGNORE book_persons SET person_id = 17323 WHERE person_id = 18221;
DELETE FROM persons WHERE person_id = 18221;  -- 賓路易師母著 → 賓路易師母 (17323)
UPDATE IGNORE book_persons SET person_id = 32916 WHERE person_id = 18223;
DELETE FROM persons WHERE person_id = 18223;  -- 孫大信著 → 孫大信 (32916)
UPDATE IGNORE book_persons SET person_id = 27987 WHERE person_id = 18225;
DELETE FROM persons WHERE person_id = 18225;  -- 史百克著 → 史百克 (27987)
UPDATE IGNORE book_persons SET person_id = 36122 WHERE person_id = 18231;
DELETE FROM persons WHERE person_id = 18231;  -- 慕塞梭著 → 慕塞梭 (36122)
UPDATE IGNORE book_persons SET person_id = 32986 WHERE person_id = 18232;
DELETE FROM persons WHERE person_id = 18232;  -- 傅來恩著 → 傅來恩 (32986)
UPDATE IGNORE book_persons SET person_id = 32634 WHERE person_id = 18267;
DELETE FROM persons WHERE person_id = 18267;  -- 劉達芳著 → 劉達芳 (32634)
UPDATE IGNORE book_persons SET person_id = 51178 WHERE person_id = 18269;
DELETE FROM persons WHERE person_id = 18269;  -- 愛德華米勒著 → 愛德華米勒 (51178)
UPDATE IGNORE book_persons SET person_id = 33016 WHERE person_id = 18271;
DELETE FROM persons WHERE person_id = 18271;  -- 柯樓士著 → 柯樓士 (33016)
UPDATE IGNORE book_persons SET person_id = 28081 WHERE person_id = 18310;
DELETE FROM persons WHERE person_id = 18310;  -- 卜禮門著 → 卜禮門 (28081)
UPDATE IGNORE book_persons SET person_id = 33039 WHERE person_id = 18328;
DELETE FROM persons WHERE person_id = 18328;  -- 蘇姆洛著 → 蘇姆洛 (33039)
UPDATE IGNORE book_persons SET person_id = 26852 WHERE person_id = 18329;
DELETE FROM persons WHERE person_id = 18329;  -- 羅林斯著 → 羅林斯 (26852)
UPDATE IGNORE book_persons SET person_id = 36165 WHERE person_id = 18331;
DELETE FROM persons WHERE person_id = 18331;  -- 謝仁道著 → 謝仁道 (36165)
UPDATE IGNORE book_persons SET person_id = 36036 WHERE person_id = 18332;
DELETE FROM persons WHERE person_id = 18332;  -- 鮑斐森著 → 鮑斐森 (36036)
UPDATE IGNORE book_persons SET person_id = 33049 WHERE person_id = 18334;
DELETE FROM persons WHERE person_id = 18334;  -- 葛蘭特著 → 葛蘭特 (33049)
UPDATE IGNORE book_persons SET person_id = 32994 WHERE person_id = 18337;
DELETE FROM persons WHERE person_id = 18337;  -- 麥卓娜著 → 麥卓娜 (32994)
UPDATE IGNORE book_persons SET person_id = 32835 WHERE person_id = 18338;
DELETE FROM persons WHERE person_id = 18338;  -- 高科爾著 → 高科爾 (32835)
UPDATE IGNORE book_persons SET person_id = 36153 WHERE person_id = 18340;
DELETE FROM persons WHERE person_id = 18340;  -- 柯柏西著 → 柯柏西 (36153)
UPDATE IGNORE book_persons SET person_id = 46483 WHERE person_id = 18341;
DELETE FROM persons WHERE person_id = 18341;  -- 查特.胡辛夫婦著 → 查特.胡辛夫婦 (46483)
UPDATE IGNORE book_persons SET person_id = 21195 WHERE person_id = 18343;
DELETE FROM persons WHERE person_id = 18343;  -- 拉赫田著 → 拉赫田 (21195)
UPDATE IGNORE book_persons SET person_id = 18345 WHERE person_id = 18344;
DELETE FROM persons WHERE person_id = 18344;  -- Rebecca Brown著 → Rebecca Brown (18345)
UPDATE IGNORE book_persons SET person_id = 17297 WHERE person_id = 18381;
DELETE FROM persons WHERE person_id = 18381;  -- 蘇穎睿著 → 蘇穎睿 (17297)
UPDATE IGNORE book_persons SET person_id = 18709 WHERE person_id = 18386;
DELETE FROM persons WHERE person_id = 18386;  -- 夏雨人著 → 夏雨人 (18709)
UPDATE IGNORE book_persons SET person_id = 17645 WHERE person_id = 18387;
DELETE FROM persons WHERE person_id = 18387;  -- 加爾文約翰著 → 加爾文約翰 (17645)
UPDATE IGNORE book_persons SET person_id = 18480 WHERE person_id = 18423;
DELETE FROM persons WHERE person_id = 18423;  -- 梁燕城 主編 → 梁燕城 (18480)
UPDATE IGNORE book_persons SET person_id = 27392 WHERE person_id = 18436;
DELETE FROM persons WHERE person_id = 18436;  -- 張國良主編 → 張國良 (27392)
UPDATE IGNORE book_persons SET person_id = 16938 WHERE person_id = 18447;
DELETE FROM persons WHERE person_id = 18447;  -- 賴若瀚主編 → 賴若瀚 (16938)
UPDATE IGNORE book_persons SET person_id = 29754 WHERE person_id = 18504;
DELETE FROM persons WHERE person_id = 18504;  -- 馬利編著 → 馬利 (29754)
UPDATE IGNORE book_persons SET person_id = 16967 WHERE person_id = 18539;
DELETE FROM persons WHERE person_id = 18539;  -- 彭盛有主編 → 彭盛有 (16967)
UPDATE IGNORE book_persons SET person_id = 24634 WHERE person_id = 18567;
DELETE FROM persons WHERE person_id = 18567;  -- 謝華 主編 → 謝華 (24634)
UPDATE IGNORE book_persons SET person_id = 19123 WHERE person_id = 18574;
DELETE FROM persons WHERE person_id = 18574;  -- 吳昶興主編 → 吳昶興 (19123)
UPDATE IGNORE book_persons SET person_id = 27327 WHERE person_id = 18598;
DELETE FROM persons WHERE person_id = 18598;  -- 陸敬忠 主編 → 陸敬忠 (27327)
UPDATE IGNORE book_persons SET person_id = 18054 WHERE person_id = 18609;
DELETE FROM persons WHERE person_id = 18609;  -- 黃伯和主編 → 黃伯和 (18054)
UPDATE IGNORE book_persons SET person_id = 17911 WHERE person_id = 18636;
DELETE FROM persons WHERE person_id = 18636;  -- 陳韋安主編 → 陳韋安 (17911)
UPDATE IGNORE book_persons SET person_id = 27880 WHERE person_id = 18670;
DELETE FROM persons WHERE person_id = 18670;  -- 戴德爾．金．海恩斯沃思 等合編 → 戴德爾．金．海恩斯沃思 (27880)
UPDATE IGNORE book_persons SET person_id = 17414 WHERE person_id = 18676;
DELETE FROM persons WHERE person_id = 18676;  -- 林榮洪著 → 林榮洪 (17414)
UPDATE IGNORE book_persons SET person_id = 33110 WHERE person_id = 18704;
DELETE FROM persons WHERE person_id = 18704;  -- 謝扶雅著 → 謝扶雅 (33110)
UPDATE IGNORE book_persons SET person_id = 19130 WHERE person_id = 18705;
DELETE FROM persons WHERE person_id = 18705;  -- 蕭克諧著 → 蕭克諧 (19130)
UPDATE IGNORE book_persons SET person_id = 17943 WHERE person_id = 18706;
DELETE FROM persons WHERE person_id = 18706;  -- 胡忠銘著 → 胡忠銘 (17943)
UPDATE IGNORE book_persons SET person_id = 43651 WHERE person_id = 18707;
DELETE FROM persons WHERE person_id = 18707;  -- 約瑟夫森著 → 約瑟夫森 (43651)
UPDATE IGNORE book_persons SET person_id = 19156 WHERE person_id = 18788;
DELETE FROM persons WHERE person_id = 18788;  -- 邱凱莉主編 → 邱凱莉 (19156)
UPDATE IGNORE book_persons SET person_id = 18793 WHERE person_id = 18800;
DELETE FROM persons WHERE person_id = 18800;  -- 陳琇玟編著 → 陳琇玟 (18793)
UPDATE IGNORE book_persons SET person_id = 18719 WHERE person_id = 18858;
DELETE FROM persons WHERE person_id = 18858;  -- 李文耀 主編 → 李文耀 (18719)
UPDATE IGNORE book_persons SET person_id = 17079 WHERE person_id = 18876;
DELETE FROM persons WHERE person_id = 18876;  -- 張西平編著 → 張西平 (17079)
UPDATE IGNORE book_persons SET person_id = 18822 WHERE person_id = 18878;
DELETE FROM persons WHERE person_id = 18878;  -- 羅賓森著 → 羅賓森 (18822)
UPDATE IGNORE book_persons SET person_id = 17491 WHERE person_id = 18879;
DELETE FROM persons WHERE person_id = 18879;  -- 施達雄著 → 施達雄 (17491)
UPDATE IGNORE book_persons SET person_id = 33009 WHERE person_id = 18889;
DELETE FROM persons WHERE person_id = 18889;  -- 艾姆斯著 → 艾姆斯 (33009)
UPDATE IGNORE book_persons SET person_id = 27528 WHERE person_id = 18982;
DELETE FROM persons WHERE person_id = 18982;  -- 凱斯．斯沃特利 編著 → 凱斯．斯沃特利 (27528)
UPDATE IGNORE book_persons SET person_id = 17051 WHERE person_id = 18987;
DELETE FROM persons WHERE person_id = 18987;  -- 連達傑主編 → 連達傑 (17051)
UPDATE IGNORE book_persons SET person_id = 28492 WHERE person_id = 18993;
DELETE FROM persons WHERE person_id = 18993;  -- 張志江著 → 張志江 (28492)
UPDATE IGNORE book_persons SET person_id = 18481 WHERE person_id = 18996;
DELETE FROM persons WHERE person_id = 18996;  -- 嚴鳳山著 → 嚴鳳山 (18481)
UPDATE IGNORE book_persons SET person_id = 32352 WHERE person_id = 19018;
DELETE FROM persons WHERE person_id = 19018;  -- 林證耶著 → 林證耶 (32352)
UPDATE IGNORE book_persons SET person_id = 32081 WHERE person_id = 19022;
DELETE FROM persons WHERE person_id = 19022;  -- 招鶴齡著 → 招鶴齡 (32081)
UPDATE IGNORE book_persons SET person_id = 19029 WHERE person_id = 19028;
DELETE FROM persons WHERE person_id = 19028;  -- 賈禮榮原著 → 賈禮榮 (19029)
UPDATE IGNORE book_persons SET person_id = 46363 WHERE person_id = 19031;
DELETE FROM persons WHERE person_id = 19031;  -- 黃賜貽著 → 黃賜貽 (46363)
UPDATE IGNORE book_persons SET person_id = 32839 WHERE person_id = 19035;
DELETE FROM persons WHERE person_id = 19035;  -- 章力生著 → 章力生 (32839)
UPDATE IGNORE book_persons SET person_id = 29266 WHERE person_id = 19036;
DELETE FROM persons WHERE person_id = 19036;  -- 陳繼德著 → 陳繼德 (29266)
UPDATE IGNORE book_persons SET person_id = 19009 WHERE person_id = 19043;
DELETE FROM persons WHERE person_id = 19043;  -- 林安國著 → 林安國 (19009)
UPDATE IGNORE book_persons SET person_id = 18375 WHERE person_id = 19066;
DELETE FROM persons WHERE person_id = 19066;  -- 趙崇明著 → 趙崇明 (18375)
UPDATE IGNORE book_persons SET person_id = 37428 WHERE person_id = 19075;
DELETE FROM persons WHERE person_id = 19075;  -- 基督教客家福音協會編著 → 基督教客家福音協會 (37428)
UPDATE IGNORE book_persons SET person_id = 19077 WHERE person_id = 19088;
DELETE FROM persons WHERE person_id = 19088;  -- 韋柏著 → 韋柏 (19077)
UPDATE IGNORE book_persons SET person_id = 18220 WHERE person_id = 19092;
DELETE FROM persons WHERE person_id = 19092;  -- 慕安得烈著 → 慕安得烈 (18220)
UPDATE IGNORE book_persons SET person_id = 36630 WHERE person_id = 19093;
DELETE FROM persons WHERE person_id = 19093;  -- 傑克.海福德著 → 傑克.海福德 (36630)
UPDATE IGNORE book_persons SET person_id = 37119 WHERE person_id = 19094;
DELETE FROM persons WHERE person_id = 19094;  -- 莫林.凱勒斯著 → 莫林.凱勒斯 (37119)
UPDATE IGNORE book_persons SET person_id = 18650 WHERE person_id = 19102;
DELETE FROM persons WHERE person_id = 19102;  -- 石素英主編 → 石素英 (18650)
UPDATE IGNORE book_persons SET person_id = 17818 WHERE person_id = 19105;
DELETE FROM persons WHERE person_id = 19105;  -- 胡恩德著 → 胡恩德 (17818)
UPDATE IGNORE book_persons SET person_id = 17748 WHERE person_id = 19109;
DELETE FROM persons WHERE person_id = 19109;  -- 黃彼得著 → 黃彼得 (17748)
UPDATE IGNORE book_persons SET person_id = 32959 WHERE person_id = 19110;
DELETE FROM persons WHERE person_id = 19110;  -- 索拉爾著 → 索拉爾 (32959)
UPDATE IGNORE book_persons SET person_id = 17915 WHERE person_id = 19133;
DELETE FROM persons WHERE person_id = 19133;  -- 林治平 主編 → 林治平 (17915)
UPDATE IGNORE book_persons SET person_id = 26851 WHERE person_id = 19139;
DELETE FROM persons WHERE person_id = 19139;  -- 盧宏博著 → 盧宏博 (26851)
UPDATE IGNORE book_persons SET person_id = 32924 WHERE person_id = 19143;
DELETE FROM persons WHERE person_id = 19143;  -- 陳道明著 → 陳道明 (32924)
UPDATE IGNORE book_persons SET person_id = 32832 WHERE person_id = 19146;
DELETE FROM persons WHERE person_id = 19146;  -- 金培文著 → 金培文 (32832)
UPDATE IGNORE book_persons SET person_id = 32963 WHERE person_id = 19148;
DELETE FROM persons WHERE person_id = 19148;  -- 何世明著 → 何世明 (32963)
UPDATE IGNORE book_persons SET person_id = 46284 WHERE person_id = 19179;
DELETE FROM persons WHERE person_id = 19179;  -- 胡國禎主編 → 胡國禎 (46284)
UPDATE IGNORE book_persons SET person_id = 20158 WHERE person_id = 19187;
DELETE FROM persons WHERE person_id = 19187;  -- 劉炳熹主編 → 劉炳熹 (20158)
UPDATE IGNORE book_persons SET person_id = 22057 WHERE person_id = 19205;
DELETE FROM persons WHERE person_id = 19205;  -- 莊信德主編 → 莊信德 (22057)
UPDATE IGNORE book_persons SET person_id = 19185 WHERE person_id = 19209;
DELETE FROM persons WHERE person_id = 19209;  -- 郭偉聯著 → 郭偉聯 (19185)
UPDATE IGNORE book_persons SET person_id = 17148 WHERE person_id = 19215;
DELETE FROM persons WHERE person_id = 19215;  -- 陳文珊 編著 → 陳文珊 (17148)
UPDATE IGNORE book_persons SET person_id = 17522 WHERE person_id = 19230;
DELETE FROM persons WHERE person_id = 19230;  -- 湯樸威廉著 → 湯樸威廉 (17522)
UPDATE IGNORE book_persons SET person_id = 25373 WHERE person_id = 19239;
DELETE FROM persons WHERE person_id = 19239;  -- 林來慰著 → 林來慰 (25373)
UPDATE IGNORE book_persons SET person_id = 18965 WHERE person_id = 19240;
DELETE FROM persons WHERE person_id = 19240;  -- 余也魯著 → 余也魯 (18965)
UPDATE IGNORE book_persons SET person_id = 35144 WHERE person_id = 19345;
DELETE FROM persons WHERE person_id = 19345;  -- 蔡宇哲主編 → 蔡宇哲 (35144)
UPDATE IGNORE book_persons SET person_id = 18356 WHERE person_id = 19371;
DELETE FROM persons WHERE person_id = 19371;  -- 陳若愚主編 → 陳若愚 (18356)
UPDATE IGNORE book_persons SET person_id = 46692 WHERE person_id = 19377;
DELETE FROM persons WHERE person_id = 19377;  -- 萬爾斯著 → 萬爾斯 (46692)
UPDATE IGNORE book_persons SET person_id = 29472 WHERE person_id = 19410;
DELETE FROM persons WHERE person_id = 19410;  -- 嚴鎮國著 → 嚴鎮國 (29472)
UPDATE IGNORE book_persons SET person_id = 28172 WHERE person_id = 19421;
DELETE FROM persons WHERE person_id = 19421;  -- 藍道．孫德思主編 → 藍道．孫德思 (28172)
UPDATE IGNORE book_persons SET person_id = 34189 WHERE person_id = 19433;
DELETE FROM persons WHERE person_id = 19433;  -- 台北真理堂 編著 → 台北真理堂 (34189)
UPDATE IGNORE book_persons SET person_id = 30208 WHERE person_id = 19540;
DELETE FROM persons WHERE person_id = 19540;  -- 王敬弘 著 → 王敬弘 (30208)
UPDATE IGNORE book_persons SET person_id = 19532 WHERE person_id = 19579;
DELETE FROM persons WHERE person_id = 19579;  -- 薩拉著 → 薩拉 (19532)
UPDATE IGNORE book_persons SET person_id = 19458 WHERE person_id = 19581;
DELETE FROM persons WHERE person_id = 19581;  -- 麥格納著 → 麥格納 (19458)
UPDATE IGNORE book_persons SET person_id = 27455 WHERE person_id = 19611;
DELETE FROM persons WHERE person_id = 19611;  -- 陶理博士主編 → 陶理博士 (27455)
UPDATE IGNORE book_persons SET person_id = 17368 WHERE person_id = 19624;
DELETE FROM persons WHERE person_id = 19624;  -- 熊潤榮 主編 → 熊潤榮 (17368)
UPDATE IGNORE book_persons SET person_id = 26203 WHERE person_id = 19630;
DELETE FROM persons WHERE person_id = 19630;  -- 穆啟蒙編著 → 穆啟蒙 (26203)
UPDATE IGNORE book_persons SET person_id = 33111 WHERE person_id = 19639;
DELETE FROM persons WHERE person_id = 19639;  -- 華爾克著 → 華爾克 (33111)
UPDATE IGNORE book_persons SET person_id = 32101 WHERE person_id = 19640;
DELETE FROM persons WHERE person_id = 19640;  -- 谷勒本著 → 谷勒本 (32101)
UPDATE IGNORE book_persons SET person_id = 32903 WHERE person_id = 19641;
DELETE FROM persons WHERE person_id = 19641;  -- 李茂政著 → 李茂政 (32903)
UPDATE IGNORE book_persons SET person_id = 20159 WHERE person_id = 19642;
DELETE FROM persons WHERE person_id = 19642;  -- 李孟哲著 → 李孟哲 (20159)
UPDATE IGNORE book_persons SET person_id = 32730 WHERE person_id = 19643;
DELETE FROM persons WHERE person_id = 19643;  -- 約翰．阿伯特原著 → 約翰．阿伯特 (32730)
UPDATE IGNORE book_persons SET person_id = 20886 WHERE person_id = 19658;
DELETE FROM persons WHERE person_id = 19658;  -- 余俊銓 編著 → 余俊銓 (20886)
UPDATE IGNORE book_persons SET person_id = 18689 WHERE person_id = 19665;
DELETE FROM persons WHERE person_id = 19665;  -- 邢福增主編 → 邢福增 (18689)
UPDATE IGNORE book_persons SET person_id = 29316 WHERE person_id = 19670;
DELETE FROM persons WHERE person_id = 19670;  -- 黃智奇著 → 黃智奇 (29316)
UPDATE IGNORE book_persons SET person_id = 18971 WHERE person_id = 19672;
DELETE FROM persons WHERE person_id = 19672;  -- 魏外揚著 → 魏外揚 (18971)
UPDATE IGNORE book_persons SET person_id = 34132 WHERE person_id = 19675;
DELETE FROM persons WHERE person_id = 19675;  -- 艾得理著 → 艾得理 (34132)
UPDATE IGNORE book_persons SET person_id = 18456 WHERE person_id = 19690;
DELETE FROM persons WHERE person_id = 19690;  -- 滕張佳音 主編 → 滕張佳音 (18456)
UPDATE IGNORE book_persons SET person_id = 27526 WHERE person_id = 19695;
DELETE FROM persons WHERE person_id = 19695;  -- 華夏好牧人關懷協會 編著 → 華夏好牧人關懷協會 (27526)
UPDATE IGNORE book_persons SET person_id = 32940 WHERE person_id = 19696;
DELETE FROM persons WHERE person_id = 19696;  -- 蘇華勒著 → 蘇華勒 (32940)
UPDATE IGNORE book_persons SET person_id = 19707 WHERE person_id = 19705;
DELETE FROM persons WHERE person_id = 19705;  -- 葉泰昌主編 → 葉泰昌 (19707)
UPDATE IGNORE book_persons SET person_id = 20551 WHERE person_id = 19709;
DELETE FROM persons WHERE person_id = 19709;  -- 廖元威主編 → 廖元威 (20551)
UPDATE IGNORE book_persons SET person_id = 33112 WHERE person_id = 19715;
DELETE FROM persons WHERE person_id = 19715;  -- 許革勒著 → 許革勒 (33112)
UPDATE IGNORE book_persons SET person_id = 32015 WHERE person_id = 19716;
DELETE FROM persons WHERE person_id = 19716;  -- 紐曼著 → 紐曼 (32015)
UPDATE IGNORE book_persons SET person_id = 33117 WHERE person_id = 19719;
DELETE FROM persons WHERE person_id = 19719;  -- 士來馬赫著 → 士來馬赫 (33117)
UPDATE IGNORE book_persons SET person_id = 30600 WHERE person_id = 19720;
DELETE FROM persons WHERE person_id = 19720;  -- 龔天民著 → 龔天民 (30600)
UPDATE IGNORE book_persons SET person_id = 44688 WHERE person_id = 19723;
DELETE FROM persons WHERE person_id = 19723;  -- 吳世芳著 → 吳世芳 (44688)
UPDATE IGNORE book_persons SET person_id = 37988 WHERE person_id = 19726;
DELETE FROM persons WHERE person_id = 19726;  -- 王光賜著 → 王光賜 (37988)
UPDATE IGNORE book_persons SET person_id = 18335 WHERE person_id = 19728;
DELETE FROM persons WHERE person_id = 19728;  -- 愛德華．米勒著 → 愛德華．米勒 (18335)
UPDATE IGNORE book_persons SET person_id = 32982 WHERE person_id = 19740;
DELETE FROM persons WHERE person_id = 19740;  -- 葛林腓著 → 葛林腓 (32982)
UPDATE IGNORE book_persons SET person_id = 38070 WHERE person_id = 19741;
DELETE FROM persons WHERE person_id = 19741;  -- 溫森.賽南著 → 溫森.賽南 (38070)
UPDATE IGNORE book_persons SET person_id = 36096 WHERE person_id = 19742;
DELETE FROM persons WHERE person_id = 19742;  -- 喬彼得著 → 喬彼得 (36096)
UPDATE IGNORE book_persons SET person_id = 18738 WHERE person_id = 19743;
DELETE FROM persons WHERE person_id = 19743;  -- 柯渥著 → 柯渥 (18738)
UPDATE IGNORE book_persons SET person_id = 16854 WHERE person_id = 19759;
DELETE FROM persons WHERE person_id = 19759;  -- 曾宗盛  主編 → 曾宗盛 (16854)
UPDATE IGNORE book_persons SET person_id = 46725 WHERE person_id = 19766;
DELETE FROM persons WHERE person_id = 19766;  -- 威廉森著 → 威廉森 (46725)
UPDATE IGNORE book_persons SET person_id = 32843 WHERE person_id = 19774;
DELETE FROM persons WHERE person_id = 19774;  -- 吳明節著 → 吳明節 (32843)
UPDATE IGNORE book_persons SET person_id = 17068 WHERE person_id = 19799;
DELETE FROM persons WHERE person_id = 19799;  -- 程蒙恩著 → 程蒙恩 (17068)
UPDATE IGNORE book_persons SET person_id = 30328 WHERE person_id = 19802;
DELETE FROM persons WHERE person_id = 19802;  -- 吳彼得編著 → 吳彼得 (30328)
UPDATE IGNORE book_persons SET person_id = 30917 WHERE person_id = 19807;
DELETE FROM persons WHERE person_id = 19807;  -- 包樂著 → 包樂 (30917)
UPDATE IGNORE book_persons SET person_id = 43616 WHERE person_id = 19809;
DELETE FROM persons WHERE person_id = 19809;  -- 戴懷仁著 → 戴懷仁 (43616)
UPDATE IGNORE book_persons SET person_id = 18909 WHERE person_id = 19812;
DELETE FROM persons WHERE person_id = 19812;  -- 夏忠堅編著 → 夏忠堅 (18909)
UPDATE IGNORE book_persons SET person_id = 32553 WHERE person_id = 19813;
DELETE FROM persons WHERE person_id = 19813;  -- 吳迺恭 編著 → 吳迺恭 (32553)
UPDATE IGNORE book_persons SET person_id = 20625 WHERE person_id = 19815;
DELETE FROM persons WHERE person_id = 19815;  -- 王正中著 → 王正中 (20625)
UPDATE IGNORE book_persons SET person_id = 17311 WHERE person_id = 19850;
DELETE FROM persons WHERE person_id = 19850;  -- 戴浩輝 總編輯 → 戴浩輝 (17311)
UPDATE IGNORE book_persons SET person_id = 19962 WHERE person_id = 19855;
DELETE FROM persons WHERE person_id = 19855;  -- 哈列斯比著 → 哈列斯比 (19962)
UPDATE IGNORE book_persons SET person_id = 18268 WHERE person_id = 19935;
DELETE FROM persons WHERE person_id = 19935;  -- 葛培理著 → 葛培理 (18268)
UPDATE IGNORE book_persons SET person_id = 19937 WHERE person_id = 19954;
DELETE FROM persons WHERE person_id = 19954;  -- 考門夫人著 → 考門夫人 (19937)
UPDATE IGNORE book_persons SET person_id = 32437 WHERE person_id = 20007;
DELETE FROM persons WHERE person_id = 20007;  -- 賈艾梅著 → 賈艾梅 (32437)
UPDATE IGNORE book_persons SET person_id = 18120 WHERE person_id = 20037;
DELETE FROM persons WHERE person_id = 20037;  -- 盧雲著 → 盧雲 (18120)
UPDATE IGNORE book_persons SET person_id = 32866 WHERE person_id = 20061;
DELETE FROM persons WHERE person_id = 20061;  -- 羅學川著 → 羅學川 (32866)
UPDATE IGNORE book_persons SET person_id = 20225 WHERE person_id = 20062;
DELETE FROM persons WHERE person_id = 20062;  -- 薛華著 → 薛華 (20225)
UPDATE IGNORE book_persons SET person_id = 20471 WHERE person_id = 20064;
DELETE FROM persons WHERE person_id = 20064;  -- 蓋恩夫人著 → 蓋恩夫人 (20471)
UPDATE IGNORE book_persons SET person_id = 37562 WHERE person_id = 20065;
DELETE FROM persons WHERE person_id = 20065;  -- 福音書房著 → 福音書房 (37562)
UPDATE IGNORE book_persons SET person_id = 37253 WHERE person_id = 20068;
DELETE FROM persons WHERE person_id = 20068;  -- 喬伊.道生著 → 喬伊.道生 (37253)
UPDATE IGNORE book_persons SET person_id = 18047 WHERE person_id = 20069;
DELETE FROM persons WHERE person_id = 20069;  -- 勞威廉著 → 勞威廉 (18047)
UPDATE IGNORE book_persons SET person_id = 18294 WHERE person_id = 20070;
DELETE FROM persons WHERE person_id = 20070;  -- 傅蘭吉著 → 傅蘭吉 (18294)
UPDATE IGNORE book_persons SET person_id = 33840 WHERE person_id = 20072;
DELETE FROM persons WHERE person_id = 20072;  -- 許格爾著 → 許格爾 (33840)
UPDATE IGNORE book_persons SET person_id = 20054 WHERE person_id = 20073;
DELETE FROM persons WHERE person_id = 20073;  -- 畢哲思著 → 畢哲思 (20054)
UPDATE IGNORE book_persons SET person_id = 17081 WHERE person_id = 20074;
DELETE FROM persons WHERE person_id = 20074;  -- 康錫慶著 → 康錫慶 (17081)
UPDATE IGNORE book_persons SET person_id = 20009 WHERE person_id = 20075;
DELETE FROM persons WHERE person_id = 20075;  -- 孫德生著 → 孫德生 (20009)
UPDATE IGNORE book_persons SET person_id = 20594 WHERE person_id = 20076;
DELETE FROM persons WHERE person_id = 20076;  -- 邵慶彰著 → 邵慶彰 (20594)
UPDATE IGNORE book_persons SET person_id = 33176 WHERE person_id = 20077;
DELETE FROM persons WHERE person_id = 20077;  -- 芬乃倫著 → 芬乃倫 (33176)
UPDATE IGNORE book_persons SET person_id = 17558 WHERE person_id = 20079;
DELETE FROM persons WHERE person_id = 20079;  -- 周神助著 → 周神助 (17558)
UPDATE IGNORE book_persons SET person_id = 32024 WHERE person_id = 20082;
DELETE FROM persons WHERE person_id = 20082;  -- 李定武著 → 李定武 (32024)
UPDATE IGNORE book_persons SET person_id = 32553 WHERE person_id = 20083;
DELETE FROM persons WHERE person_id = 20083;  -- 吳迺恭著 → 吳迺恭 (32553)
UPDATE IGNORE book_persons SET person_id = 26033 WHERE person_id = 20090;
DELETE FROM persons WHERE person_id = 20090;  -- 中國神學研究院著 → 中國神學研究院 (26033)
UPDATE IGNORE book_persons SET person_id = 32785 WHERE person_id = 20138;
DELETE FROM persons WHERE person_id = 20138;  -- 麥瑞福著 → 麥瑞福 (32785)
UPDATE IGNORE book_persons SET person_id = 26575 WHERE person_id = 20140;
DELETE FROM persons WHERE person_id = 20140;  -- 高爾文著 → 高爾文 (26575)
UPDATE IGNORE book_persons SET person_id = 19119 WHERE person_id = 20142;
DELETE FROM persons WHERE person_id = 20142;  -- 吳思源著 → 吳思源 (19119)
UPDATE IGNORE book_persons SET person_id = 33412 WHERE person_id = 20145;
DELETE FROM persons WHERE person_id = 20145;  -- 比爾索布里斯基著 → 比爾索布里斯基 (33412)
UPDATE IGNORE book_persons SET person_id = 36053 WHERE person_id = 20146;
DELETE FROM persons WHERE person_id = 20146;  -- Ron Ryan著 → Ron Ryan (36053)
UPDATE IGNORE book_persons SET person_id = 16989 WHERE person_id = 20169;
DELETE FROM persons WHERE person_id = 20169;  -- 王礽福主編 → 王礽福 (16989)
UPDATE IGNORE book_persons SET person_id = 19445 WHERE person_id = 20171;
DELETE FROM persons WHERE person_id = 20171;  -- 陳榮爝 編著 → 陳榮爝 (19445)
UPDATE IGNORE book_persons SET person_id = 20184 WHERE person_id = 20199;
DELETE FROM persons WHERE person_id = 20199;  -- 董芳苑著 → 董芳苑 (20184)
UPDATE IGNORE book_persons SET person_id = 20093 WHERE person_id = 20201;
DELETE FROM persons WHERE person_id = 20201;  -- 宋尚節著 → 宋尚節 (20093)
UPDATE IGNORE book_persons SET person_id = 29626 WHERE person_id = 20210;
DELETE FROM persons WHERE person_id = 20210;  -- 王少勇主編 → 王少勇 (29626)
UPDATE IGNORE book_persons SET person_id = 25185 WHERE person_id = 20219;
DELETE FROM persons WHERE person_id = 20219;  -- 鄭政恆 主編 → 鄭政恆 (25185)
UPDATE IGNORE book_persons SET person_id = 20677 WHERE person_id = 20239;
DELETE FROM persons WHERE person_id = 20239;  -- 蘇文峰主編 → 蘇文峰 (20677)
UPDATE IGNORE book_persons SET person_id = 18650 WHERE person_id = 20252;
DELETE FROM persons WHERE person_id = 20252;  -- 石素英 主編 → 石素英 (18650)
UPDATE IGNORE book_persons SET person_id = 19123 WHERE person_id = 20253;
DELETE FROM persons WHERE person_id = 20253;  -- 吳昶興 主編 → 吳昶興 (19123)
UPDATE IGNORE book_persons SET person_id = 17234 WHERE person_id = 20279;
DELETE FROM persons WHERE person_id = 20279;  -- 周永健著 → 周永健 (17234)
UPDATE IGNORE book_persons SET person_id = 30740 WHERE person_id = 20282;
DELETE FROM persons WHERE person_id = 20282;  -- 李焯仁 主編 → 李焯仁 (30740)
UPDATE IGNORE book_persons SET person_id = 46029 WHERE person_id = 20283;
DELETE FROM persons WHERE person_id = 20283;  -- ARISE 5 編著 → ARISE 5 (46029)
UPDATE IGNORE book_persons SET person_id = 17808 WHERE person_id = 20309;
DELETE FROM persons WHERE person_id = 20309;  -- 郭乃惇編著 → 郭乃惇 (17808)
UPDATE IGNORE book_persons SET person_id = 45989 WHERE person_id = 20326;
DELETE FROM persons WHERE person_id = 20326;  -- 劉益安著 → 劉益安 (45989)
UPDATE IGNORE book_persons SET person_id = 25290 WHERE person_id = 20329;
DELETE FROM persons WHERE person_id = 20329;  -- 楊宓貴靈著 → 楊宓貴靈 (25290)
UPDATE IGNORE book_persons SET person_id = 22634 WHERE person_id = 20330;
DELETE FROM persons WHERE person_id = 20330;  -- 林曾秀芬著 → 林曾秀芬 (22634)
UPDATE IGNORE book_persons SET person_id = 46221 WHERE person_id = 20376;
DELETE FROM persons WHERE person_id = 20376;  -- 施芬德著 → 施芬德 (46221)
UPDATE IGNORE book_persons SET person_id = 35366 WHERE person_id = 20378;
DELETE FROM persons WHERE person_id = 20378;  -- 玖妮.艾力生著 → 玖妮.艾力生 (35366)
UPDATE IGNORE book_persons SET person_id = 35376 WHERE person_id = 20427;
DELETE FROM persons WHERE person_id = 20427;  -- 劉建珠 口述 → 劉建珠 (35376)
UPDATE IGNORE book_persons SET person_id = 33962 WHERE person_id = 20429;
DELETE FROM persons WHERE person_id = 20429;  -- 丹云著 → 丹云 (33962)
UPDATE IGNORE book_persons SET person_id = 32399 WHERE person_id = 20440;
DELETE FROM persons WHERE person_id = 20440;  -- 鄔添松著 → 鄔添松 (32399)
UPDATE IGNORE book_persons SET person_id = 53490 WHERE person_id = 20442;
DELETE FROM persons WHERE person_id = 20442;  -- 劉福群著 → 劉福群 (53490)
UPDATE IGNORE book_persons SET person_id = 30999 WHERE person_id = 20443;
DELETE FROM persons WHERE person_id = 20443;  -- 滌然著 → 滌然 (30999)
UPDATE IGNORE book_persons SET person_id = 20434 WHERE person_id = 20445;
DELETE FROM persons WHERE person_id = 20445;  -- 葉特生著 → 葉特生 (20434)
UPDATE IGNORE book_persons SET person_id = 27336 WHERE person_id = 20450;
DELETE FROM persons WHERE person_id = 20450;  -- 麥喬治著 → 麥喬治 (27336)
UPDATE IGNORE book_persons SET person_id = 32777 WHERE person_id = 20455;
DELETE FROM persons WHERE person_id = 20455;  -- 林鏡初著 → 林鏡初 (32777)
UPDATE IGNORE book_persons SET person_id = 17915 WHERE person_id = 20456;
DELETE FROM persons WHERE person_id = 20456;  -- 林治平著 → 林治平 (17915)
UPDATE IGNORE book_persons SET person_id = 21398 WHERE person_id = 20476;
DELETE FROM persons WHERE person_id = 20476;  -- 張蓮娣 總編輯 → 張蓮娣 (21398)
UPDATE IGNORE book_persons SET person_id = 25511 WHERE person_id = 20536;
DELETE FROM persons WHERE person_id = 20536;  -- 葛拉柏著 → 葛拉柏 (25511)
UPDATE IGNORE book_persons SET person_id = 49097 WHERE person_id = 20608;
DELETE FROM persons WHERE person_id = 20608;  -- 編輯委員會編著 → 編輯委員會 (49097)
UPDATE IGNORE book_persons SET person_id = 30539 WHERE person_id = 20621;
DELETE FROM persons WHERE person_id = 20621;  -- 李非吾著 → 李非吾 (30539)
UPDATE IGNORE book_persons SET person_id = 46204 WHERE person_id = 20633;
DELETE FROM persons WHERE person_id = 20633;  -- 廖美喜 口述 → 廖美喜 (46204)
UPDATE IGNORE book_persons SET person_id = 32673 WHERE person_id = 20637;
DELETE FROM persons WHERE person_id = 20637;  -- 包忠傑著 → 包忠傑 (32673)
UPDATE IGNORE book_persons SET person_id = 24345 WHERE person_id = 20638;
DELETE FROM persons WHERE person_id = 20638;  -- 校園編輯小組/編著 → 校園編輯小組 (24345)
UPDATE IGNORE book_persons SET person_id = 20698 WHERE person_id = 20691;
DELETE FROM persons WHERE person_id = 20691;  -- 許郭美員著 → 許郭美員 (20698)
UPDATE IGNORE book_persons SET person_id = 18908 WHERE person_id = 20747;
DELETE FROM persons WHERE person_id = 20747;  -- 王成勉 主編 → 王成勉 (18908)
UPDATE IGNORE book_persons SET person_id = 38388 WHERE person_id = 20755;
DELETE FROM persons WHERE person_id = 20755;  -- 徐劉玉棠 口述 → 徐劉玉棠 (38388)
UPDATE IGNORE book_persons SET person_id = 30783 WHERE person_id = 20779;
DELETE FROM persons WHERE person_id = 20779;  -- 劉麗紅編著 → 劉麗紅 (30783)
UPDATE IGNORE book_persons SET person_id = 39807 WHERE person_id = 20785;
DELETE FROM persons WHERE person_id = 20785;  -- 陳路得(陳收)口述 → 陳路得(陳收) (39807)
UPDATE IGNORE book_persons SET person_id = 38417 WHERE person_id = 20807;
DELETE FROM persons WHERE person_id = 20807;  -- 時代論壇主編 → 時代論壇 (38417)
UPDATE IGNORE book_persons SET person_id = 18322 WHERE person_id = 20847;
DELETE FROM persons WHERE person_id = 20847;  -- 鄭國治 編著 → 鄭國治 (18322)
UPDATE IGNORE book_persons SET person_id = 21325 WHERE person_id = 20857;
DELETE FROM persons WHERE person_id = 20857;  -- 翁麗玉編著 → 翁麗玉 (21325)
UPDATE IGNORE book_persons SET person_id = 27126 WHERE person_id = 20888;
DELETE FROM persons WHERE person_id = 20888;  -- 史托德著 → 史托德 (27126)
UPDATE IGNORE book_persons SET person_id = 28523 WHERE person_id = 20893;
DELETE FROM persons WHERE person_id = 20893;  -- 沈珊著 → 沈珊 (28523)
UPDATE IGNORE book_persons SET person_id = 30346 WHERE person_id = 20902;
DELETE FROM persons WHERE person_id = 20902;  -- 鄭新教 等編著 → 鄭新教 (30346)
UPDATE IGNORE book_persons SET person_id = 46369 WHERE person_id = 20911;
DELETE FROM persons WHERE person_id = 20911;  -- 傅禮敦著 → 傅禮敦 (46369)
UPDATE IGNORE book_persons SET person_id = 20625 WHERE person_id = 20966;
DELETE FROM persons WHERE person_id = 20966;  -- 王正中 編著 → 王正中 (20625)
UPDATE IGNORE book_persons SET person_id = 32974 WHERE person_id = 20975;
DELETE FROM persons WHERE person_id = 20975;  -- 霍士達著 → 霍士達 (32974)
UPDATE IGNORE book_persons SET person_id = 20005 WHERE person_id = 20976;
DELETE FROM persons WHERE person_id = 20976;  -- 魯益師著 → 魯益師 (20005)
UPDATE IGNORE book_persons SET person_id = 19803 WHERE person_id = 20977;
DELETE FROM persons WHERE person_id = 20977;  -- 趙鏞基著 → 趙鏞基 (19803)
UPDATE IGNORE book_persons SET person_id = 20964 WHERE person_id = 20978;
DELETE FROM persons WHERE person_id = 20978;  -- 黃友玲著 → 黃友玲 (20964)
UPDATE IGNORE book_persons SET person_id = 31984 WHERE person_id = 20982;
DELETE FROM persons WHERE person_id = 20982;  -- 狄樂恩著 → 狄樂恩 (31984)
UPDATE IGNORE book_persons SET person_id = 32447 WHERE person_id = 20986;
DELETE FROM persons WHERE person_id = 20986;  -- 王峙著 → 王峙 (32447)
UPDATE IGNORE book_persons SET person_id = 21353 WHERE person_id = 21011;
DELETE FROM persons WHERE person_id = 21011;  -- 霍玉蓮著 → 霍玉蓮 (21353)
UPDATE IGNORE book_persons SET person_id = 46176 WHERE person_id = 21014;
DELETE FROM persons WHERE person_id = 21014;  -- 何天擇著 → 何天擇 (46176)
UPDATE IGNORE book_persons SET person_id = 29143 WHERE person_id = 21023;
DELETE FROM persons WHERE person_id = 21023;  -- 蘇美靈著 → 蘇美靈 (29143)
UPDATE IGNORE book_persons SET person_id = 18320 WHERE person_id = 21028;
DELETE FROM persons WHERE person_id = 21028;  -- 薛查理著 → 薛查理 (18320)
UPDATE IGNORE book_persons SET person_id = 38787 WHERE person_id = 21149;
DELETE FROM persons WHERE person_id = 21149;  -- 布萊恩.哈柏著 → 布萊恩.哈柏 (38787)
UPDATE IGNORE book_persons SET person_id = 26660 WHERE person_id = 21156;
DELETE FROM persons WHERE person_id = 21156;  -- 李陳永鈿著 → 李陳永鈿 (26660)
UPDATE IGNORE book_persons SET person_id = 21061 WHERE person_id = 21168;
DELETE FROM persons WHERE person_id = 21168;  -- 丹尼斯.雷尼總編輯 → 丹尼斯.雷尼 (21061)
UPDATE IGNORE book_persons SET person_id = 30049 WHERE person_id = 21176;
DELETE FROM persons WHERE person_id = 21176;  -- 潘國華著 → 潘國華 (30049)
UPDATE IGNORE book_persons SET person_id = 21194 WHERE person_id = 21183;
DELETE FROM persons WHERE person_id = 21183;  -- 羅曼.賴特著 → 羅曼.賴特 (21194)
UPDATE IGNORE book_persons SET person_id = 36028 WHERE person_id = 21187;
DELETE FROM persons WHERE person_id = 21187;  -- 琳達.戴維斯著 → 琳達.戴維斯 (36028)
UPDATE IGNORE book_persons SET person_id = 35397 WHERE person_id = 21189;
DELETE FROM persons WHERE person_id = 21189;  -- 傑伊.亞當斯著 → 傑伊.亞當斯 (35397)
UPDATE IGNORE book_persons SET person_id = 32858 WHERE person_id = 21193;
DELETE FROM persons WHERE person_id = 21193;  -- 柯蓋瑞著 → 柯蓋瑞 (32858)
UPDATE IGNORE book_persons SET person_id = 18516 WHERE person_id = 21197;
DELETE FROM persons WHERE person_id = 21197;  -- 周美德著 → 周美德 (18516)
UPDATE IGNORE book_persons SET person_id = 32526 WHERE person_id = 21198;
DELETE FROM persons WHERE person_id = 21198;  -- 周李玉珍著 → 周李玉珍 (32526)
UPDATE IGNORE book_persons SET person_id = 46493 WHERE person_id = 21204;
DELETE FROM persons WHERE person_id = 21204;  -- 艾德.惠特著 → 艾德.惠特 (46493)
UPDATE IGNORE book_persons SET person_id = 17052 WHERE person_id = 21206;
DELETE FROM persons WHERE person_id = 21206;  -- 艾文斯著 → 艾文斯 (17052)
UPDATE IGNORE book_persons SET person_id = 46383 WHERE person_id = 21207;
DELETE FROM persons WHERE person_id = 21207;  -- J. Allen Peter著 → J. Allen Peter (46383)
UPDATE IGNORE book_persons SET person_id = 19444 WHERE person_id = 21228;
DELETE FROM persons WHERE person_id = 21228;  -- 林淑美 編著 → 林淑美 (19444)
UPDATE IGNORE book_persons SET person_id = 45788 WHERE person_id = 21306;
DELETE FROM persons WHERE person_id = 21306;  -- 迪克‧戴依著 → 迪克‧戴依 (45788)
UPDATE IGNORE book_persons SET person_id = 23355 WHERE person_id = 21307;
DELETE FROM persons WHERE person_id = 21307;  -- 歐偉民著 → 歐偉民 (23355)
UPDATE IGNORE book_persons SET person_id = 22891 WHERE person_id = 21319;
DELETE FROM persons WHERE person_id = 21319;  -- 葉應霖著 → 葉應霖 (22891)
UPDATE IGNORE book_persons SET person_id = 20724 WHERE person_id = 21341;
DELETE FROM persons WHERE person_id = 21341;  -- 陳彥琳著 → 陳彥琳 (20724)
UPDATE IGNORE book_persons SET person_id = 21302 WHERE person_id = 21343;
DELETE FROM persons WHERE person_id = 21343;  -- 李賢國 編著 → 李賢國 (21302)
UPDATE IGNORE book_persons SET person_id = 22158 WHERE person_id = 21344;
DELETE FROM persons WHERE person_id = 21344;  -- 王祈編著 → 王祈 (22158)
UPDATE IGNORE book_persons SET person_id = 52207 WHERE person_id = 21588;
DELETE FROM persons WHERE person_id = 21588;  -- 何義思原著 → 何義思 (52207)
UPDATE IGNORE book_persons SET person_id = 29077 WHERE person_id = 21605;
DELETE FROM persons WHERE person_id = 21605;  -- 鄧萊編著 → 鄧萊 (29077)
UPDATE IGNORE book_persons SET person_id = 27069 WHERE person_id = 21626;
DELETE FROM persons WHERE person_id = 21626;  -- 盧月雲著 → 盧月雲 (27069)
UPDATE IGNORE book_persons SET person_id = 38993 WHERE person_id = 21646;
DELETE FROM persons WHERE person_id = 21646;  -- 喬懷德著 → 喬懷德 (38993)
UPDATE IGNORE book_persons SET person_id = 32462 WHERE person_id = 21647;
DELETE FROM persons WHERE person_id = 21647;  -- 通口雅一著 → 通口雅一 (32462)
UPDATE IGNORE book_persons SET person_id = 32896 WHERE person_id = 21649;
DELETE FROM persons WHERE person_id = 21649;  -- 徐建正著 → 徐建正 (32896)
UPDATE IGNORE book_persons SET person_id = 30361 WHERE person_id = 21654;
DELETE FROM persons WHERE person_id = 21654;  -- 奧斯本著 → 奧斯本 (30361)
UPDATE IGNORE book_persons SET person_id = 47499 WHERE person_id = 21668;
DELETE FROM persons WHERE person_id = 21668;  -- 金成洪 等編著 → 金成洪 (47499)
UPDATE IGNORE book_persons SET person_id = 33000 WHERE person_id = 21673;
DELETE FROM persons WHERE person_id = 21673;  -- 陳李穎著 → 陳李穎 (33000)
UPDATE IGNORE book_persons SET person_id = 32458 WHERE person_id = 21676;
DELETE FROM persons WHERE person_id = 21676;  -- 杜博生著 → 杜博生 (32458)
UPDATE IGNORE book_persons SET person_id = 19330 WHERE person_id = 21714;
DELETE FROM persons WHERE person_id = 21714;  -- 陳一華著 → 陳一華 (19330)
UPDATE IGNORE book_persons SET person_id = 22075 WHERE person_id = 21738;
DELETE FROM persons WHERE person_id = 21738;  -- CBMC美國總會編著 → CBMC美國總會 (22075)
UPDATE IGNORE book_persons SET person_id = 51237 WHERE person_id = 21739;
DELETE FROM persons WHERE person_id = 21739;  -- 希爾著 → 希爾 (51237)
UPDATE IGNORE book_persons SET person_id = 36058 WHERE person_id = 21742;
DELETE FROM persons WHERE person_id = 21742;  -- 麥農路希著 → 麥農路希 (36058)
UPDATE IGNORE book_persons SET person_id = 36121 WHERE person_id = 21743;
DELETE FROM persons WHERE person_id = 21743;  -- 畢海伯著 → 畢海伯 (36121)
UPDATE IGNORE book_persons SET person_id = 47036 WHERE person_id = 21744;
DELETE FROM persons WHERE person_id = 21744;  -- 康來斯德著 → 康來斯德 (47036)
UPDATE IGNORE book_persons SET person_id = 17891 WHERE person_id = 21752;
DELETE FROM persons WHERE person_id = 21752;  -- 財團法人禧年經濟倫理文教基金會 編著 → 財團法人禧年經濟倫理文教基金會 (17891)
UPDATE IGNORE book_persons SET person_id = 19201 WHERE person_id = 21797;
DELETE FROM persons WHERE person_id = 21797;  -- 陳佐才主編 → 陳佐才 (19201)
UPDATE IGNORE book_persons SET person_id = 17968 WHERE person_id = 21839;
DELETE FROM persons WHERE person_id = 21839;  -- 關啟文著 → 關啟文 (17968)
UPDATE IGNORE book_persons SET person_id = 19310 WHERE person_id = 21848;
DELETE FROM persons WHERE person_id = 21848;  -- 莎倫．達迪斯 等編 → 莎倫．達迪斯 (19310)
UPDATE IGNORE book_persons SET person_id = 32746 WHERE person_id = 21858;
DELETE FROM persons WHERE person_id = 21858;  -- 康維夫婦著 → 康維夫婦 (32746)
UPDATE IGNORE book_persons SET person_id = 47144 WHERE person_id = 21943;
DELETE FROM persons WHERE person_id = 21943;  -- 嚴惠來主編 → 嚴惠來 (47144)
UPDATE IGNORE book_persons SET person_id = 47378 WHERE person_id = 21946;
DELETE FROM persons WHERE person_id = 21946;  -- 台灣冠冕真道理財協會 編著 → 台灣冠冕真道理財協會 (47378)
UPDATE IGNORE book_persons SET person_id = 38905 WHERE person_id = 21955;
DELETE FROM persons WHERE person_id = 21955;  -- HONOR BOOKS 編著 → HONOR BOOKS (38905)
UPDATE IGNORE book_persons SET person_id = 31533 WHERE person_id = 21957;
DELETE FROM persons WHERE person_id = 21957;  -- 郁尼科著 → 郁尼科 (31533)
UPDATE IGNORE book_persons SET person_id = 17158 WHERE person_id = 22019;
DELETE FROM persons WHERE person_id = 22019;  -- 蘇穎智著 → 蘇穎智 (17158)
UPDATE IGNORE book_persons SET person_id = 46041 WHERE person_id = 22070;
DELETE FROM persons WHERE person_id = 22070;  -- 約翰．里奇斯 主編 → 約翰．里奇斯 (46041)
UPDATE IGNORE book_persons SET person_id = 27825 WHERE person_id = 22087;
DELETE FROM persons WHERE person_id = 22087;  -- 李漢文著 → 李漢文 (27825)
UPDATE IGNORE book_persons SET person_id = 22079 WHERE person_id = 22093;
DELETE FROM persons WHERE person_id = 22093;  -- 導航會著 → 導航會 (22079)
UPDATE IGNORE book_persons SET person_id = 33062 WHERE person_id = 22094;
DELETE FROM persons WHERE person_id = 22094;  -- 曾燊著 → 曾燊 (33062)
UPDATE IGNORE book_persons SET person_id = 34642 WHERE person_id = 22128;
DELETE FROM persons WHERE person_id = 22128;  -- 朗根霍斯特/著 → 朗根霍斯特 (34642)
UPDATE IGNORE book_persons SET person_id = 30549 WHERE person_id = 22139;
DELETE FROM persons WHERE person_id = 22139;  -- 李秀芳編著 → 李秀芳 (30549)
UPDATE IGNORE book_persons SET person_id = 33061 WHERE person_id = 22150;
DELETE FROM persons WHERE person_id = 22150;  -- 易格美著 → 易格美 (33061)
UPDATE IGNORE book_persons SET person_id = 35849 WHERE person_id = 22151;
DELETE FROM persons WHERE person_id = 22151;  -- 吳品著 → 吳品 (35849)
UPDATE IGNORE book_persons SET person_id = 22040 WHERE person_id = 22160;
DELETE FROM persons WHERE person_id = 22160;  -- 吳碧春 編著 → 吳碧春 (22040)
UPDATE IGNORE book_persons SET person_id = 32985 WHERE person_id = 22169;
DELETE FROM persons WHERE person_id = 22169;  -- 薛利著 → 薛利 (32985)
UPDATE IGNORE book_persons SET person_id = 32988 WHERE person_id = 22170;
DELETE FROM persons WHERE person_id = 22170;  -- 龍雅各著 → 龍雅各 (32988)
UPDATE IGNORE book_persons SET person_id = 18350 WHERE person_id = 22188;
DELETE FROM persons WHERE person_id = 22188;  -- 邱清萍編著 → 邱清萍 (18350)
UPDATE IGNORE book_persons SET person_id = 21254 WHERE person_id = 22190;
DELETE FROM persons WHERE person_id = 22190;  -- 陳芝瑛編著 → 陳芝瑛 (21254)
UPDATE IGNORE book_persons SET person_id = 32864 WHERE person_id = 22202;
DELETE FROM persons WHERE person_id = 22202;  -- 鄧敏著 → 鄧敏 (32864)
UPDATE IGNORE book_persons SET person_id = 37659 WHERE person_id = 22207;
DELETE FROM persons WHERE person_id = 22207;  -- 吉姆.拉森著 → 吉姆.拉森 (37659)
UPDATE IGNORE book_persons SET person_id = 22254 WHERE person_id = 22219;
DELETE FROM persons WHERE person_id = 22219;  -- 托爾斯泰著 → 托爾斯泰 (22254)
UPDATE IGNORE book_persons SET person_id = 26935 WHERE person_id = 22249;
DELETE FROM persons WHERE person_id = 22249;  -- 麥耀安著 → 麥耀安 (26935)
UPDATE IGNORE book_persons SET person_id = 30806 WHERE person_id = 22284;
DELETE FROM persons WHERE person_id = 22284;  -- 艾莉森．波克 編著 → 艾莉森．波克 (30806)
UPDATE IGNORE book_persons SET person_id = 20818 WHERE person_id = 22298;
DELETE FROM persons WHERE person_id = 22298;  -- 蘇文安著 → 蘇文安 (20818)
UPDATE IGNORE book_persons SET person_id = 31623 WHERE person_id = 22299;
DELETE FROM persons WHERE person_id = 22299;  -- 樊大克著 → 樊大克 (31623)
UPDATE IGNORE book_persons SET person_id = 48547 WHERE person_id = 22301;
DELETE FROM persons WHERE person_id = 22301;  -- 許純欣著 → 許純欣 (48547)
UPDATE IGNORE book_persons SET person_id = 35497 WHERE person_id = 22353;
DELETE FROM persons WHERE person_id = 22353;  -- 朱錫林編著 → 朱錫林 (35497)
UPDATE IGNORE book_persons SET person_id = 18529 WHERE person_id = 22359;
DELETE FROM persons WHERE person_id = 22359;  -- 曾慶豹主編 → 曾慶豹 (18529)
UPDATE IGNORE book_persons SET person_id = 47062 WHERE person_id = 22371;
DELETE FROM persons WHERE person_id = 22371;  -- 馮鄭珍妮主編 → 馮鄭珍妮 (47062)
UPDATE IGNORE book_persons SET person_id = 21770 WHERE person_id = 22382;
DELETE FROM persons WHERE person_id = 22382;  -- 林淑芬主編 → 林淑芬 (21770)
UPDATE IGNORE book_persons SET person_id = 16756 WHERE person_id = 22394;
DELETE FROM persons WHERE person_id = 22394;  -- 楊文立著 → 楊文立 (16756)
UPDATE IGNORE book_persons SET person_id = 17963 WHERE person_id = 22417;
DELETE FROM persons WHERE person_id = 22417;  -- 蕭家興 編著 → 蕭家興 (17963)
UPDATE IGNORE book_persons SET person_id = 31939 WHERE person_id = 22450;
DELETE FROM persons WHERE person_id = 22450;  -- 庫克集團編著 → 庫克集團 (31939)
UPDATE IGNORE book_persons SET person_id = 51208 WHERE person_id = 22452;
DELETE FROM persons WHERE person_id = 22452;  -- 魏喜樂著 → 魏喜樂 (51208)
UPDATE IGNORE book_persons SET person_id = 22149 WHERE person_id = 22454;
DELETE FROM persons WHERE person_id = 22454;  -- 飛越編輯組著 → 飛越編輯組 (22149)
UPDATE IGNORE book_persons SET person_id = 51697 WHERE person_id = 22456;
DELETE FROM persons WHERE person_id = 22456;  -- 呂仁秀著 → 呂仁秀 (51697)
UPDATE IGNORE book_persons SET person_id = 22674 WHERE person_id = 22478;
DELETE FROM persons WHERE person_id = 22478;  -- 余秋芝 編著 → 余秋芝 (22674)
UPDATE IGNORE book_persons SET person_id = 33192 WHERE person_id = 22481;
DELETE FROM persons WHERE person_id = 22481;  -- 何守誠編著 → 何守誠 (33192)
UPDATE IGNORE book_persons SET person_id = 22515 WHERE person_id = 22508;
DELETE FROM persons WHERE person_id = 22508;  -- 天韻詩班著 → 天韻詩班 (22515)
UPDATE IGNORE book_persons SET person_id = 22465 WHERE person_id = 22521;
DELETE FROM persons WHERE person_id = 22521;  -- 林淑娜編著 → 林淑娜 (22465)
UPDATE IGNORE book_persons SET person_id = 19969 WHERE person_id = 22532;
DELETE FROM persons WHERE person_id = 22532;  -- 李鴻志編著 → 李鴻志 (19969)
UPDATE IGNORE book_persons SET person_id = 20630 WHERE person_id = 22549;
DELETE FROM persons WHERE person_id = 22549;  -- 陳茂生 編選 → 陳茂生 (20630)
UPDATE IGNORE book_persons SET person_id = 17808 WHERE person_id = 22552;
DELETE FROM persons WHERE person_id = 22552;  -- 郭乃惇主編 → 郭乃惇 (17808)
UPDATE IGNORE book_persons SET person_id = 19650 WHERE person_id = 22708;
DELETE FROM persons WHERE person_id = 22708;  -- 倪步曉 主編 → 倪步曉 (19650)
UPDATE IGNORE book_persons SET person_id = 52476 WHERE person_id = 22726;
DELETE FROM persons WHERE person_id = 22726;  -- 播道會文字部 編著 → 播道會文字部 (52476)
UPDATE IGNORE book_persons SET person_id = 18520 WHERE person_id = 22735;
DELETE FROM persons WHERE person_id = 22735;  -- 董家驊 主編 → 董家驊 (18520)
UPDATE IGNORE book_persons SET person_id = 16967 WHERE person_id = 22787;
DELETE FROM persons WHERE person_id = 22787;  -- 彭盛有 主編 → 彭盛有 (16967)
UPDATE IGNORE book_persons SET person_id = 23419 WHERE person_id = 22794;
DELETE FROM persons WHERE person_id = 22794;  -- 蔣慧民 主編 → 蔣慧民 (23419)
UPDATE IGNORE book_persons SET person_id = 19943 WHERE person_id = 22808;
DELETE FROM persons WHERE person_id = 22808;  -- 2026年大齋期靈修手冊編輯委員會 編著 → 2026年大齋期靈修手冊編輯委員會 (19943)
UPDATE IGNORE book_persons SET person_id = 19156 WHERE person_id = 22815;
DELETE FROM persons WHERE person_id = 22815;  -- 邱凱莉 主編 → 邱凱莉 (19156)
UPDATE IGNORE book_persons SET person_id = 22214 WHERE person_id = 22873;
DELETE FROM persons WHERE person_id = 22873;  -- 德國合一弟兄會 編著 → 德國合一弟兄會 (22214)
UPDATE IGNORE book_persons SET person_id = 19153 WHERE person_id = 22893;
DELETE FROM persons WHERE person_id = 22893;  -- 林榮鈞 主編 → 林榮鈞 (19153)
UPDATE IGNORE book_persons SET person_id = 16866 WHERE person_id = 22895;
DELETE FROM persons WHERE person_id = 22895;  -- 何翰庭 編著 → 何翰庭 (16866)
UPDATE IGNORE book_persons SET person_id = 17354 WHERE person_id = 22909;
DELETE FROM persons WHERE person_id = 22909;  -- 李穎婷 主編 → 李穎婷 (17354)
UPDATE IGNORE book_persons SET person_id = 18472 WHERE person_id = 22924;
DELETE FROM persons WHERE person_id = 22924;  -- 李志剛 主編 → 李志剛 (18472)
UPDATE IGNORE book_persons SET person_id = 21216 WHERE person_id = 22959;
DELETE FROM persons WHERE person_id = 22959;  -- 鄧震宇 編著 → 鄧震宇 (21216)
UPDATE IGNORE book_persons SET person_id = 19967 WHERE person_id = 23029;
DELETE FROM persons WHERE person_id = 23029;  -- 上智文化事業 編著 → 上智文化事業 (19967)
UPDATE IGNORE book_persons SET person_id = 24015 WHERE person_id = 23066;
DELETE FROM persons WHERE person_id = 23066;  -- 譚靜芝 主編 → 譚靜芝 (24015)
UPDATE IGNORE book_persons SET person_id = 53701 WHERE person_id = 23109;
DELETE FROM persons WHERE person_id = 23109;  -- 鍾芸 編著 → 鍾芸 (53701)
UPDATE IGNORE book_persons SET person_id = 18759 WHERE person_id = 23119;
DELETE FROM persons WHERE person_id = 23119;  -- 彭培剛法政牧師 編選 → 彭培剛法政牧師 (18759)
UPDATE IGNORE book_persons SET person_id = 22468 WHERE person_id = 23135;
DELETE FROM persons WHERE person_id = 23135;  -- 禮拜委員會 編著 → 禮拜委員會 (22468)
UPDATE IGNORE book_persons SET person_id = 18375 WHERE person_id = 23162;
DELETE FROM persons WHERE person_id = 23162;  -- 趙崇明 主編 → 趙崇明 (18375)
UPDATE IGNORE book_persons SET person_id = 19982 WHERE person_id = 23187;
DELETE FROM persons WHERE person_id = 23187;  -- 理查．羅斯 編著 → 理查．羅斯 (19982)
UPDATE IGNORE book_persons SET person_id = 18092 WHERE person_id = 23258;
DELETE FROM persons WHERE person_id = 23258;  -- 姚錦燊 編著 → 姚錦燊 (18092)
UPDATE IGNORE book_persons SET person_id = 20470 WHERE person_id = 23272;
DELETE FROM persons WHERE person_id = 23272;  -- 郭忠吉 編著 → 郭忠吉 (20470)
UPDATE IGNORE book_persons SET person_id = 17015 WHERE person_id = 23281;
DELETE FROM persons WHERE person_id = 23281;  -- 黃瑞西 編著 → 黃瑞西 (17015)
UPDATE IGNORE book_persons SET person_id = 23699 WHERE person_id = 23291;
DELETE FROM persons WHERE person_id = 23291;  -- 韓樂憫 主編 → 韓樂憫 (23699)
UPDATE IGNORE book_persons SET person_id = 34206 WHERE person_id = 23386;
DELETE FROM persons WHERE person_id = 23386;  -- Willem A. VanGemeren 主編 → Willem A. VanGemeren (34206)
UPDATE IGNORE book_persons SET person_id = 17915 WHERE person_id = 23414;
DELETE FROM persons WHERE person_id = 23414;  -- 林治平 口述 → 林治平 (17915)
UPDATE IGNORE book_persons SET person_id = 31797 WHERE person_id = 23438;
DELETE FROM persons WHERE person_id = 23438;  -- 翁傳鏗 主編 → 翁傳鏗 (31797)
UPDATE IGNORE book_persons SET person_id = 20483 WHERE person_id = 23459;
DELETE FROM persons WHERE person_id = 23459;  -- 呂代豪口述 → 呂代豪 (20483)
UPDATE IGNORE book_persons SET person_id = 17911 WHERE person_id = 23478;
DELETE FROM persons WHERE person_id = 23478;  -- 陳韋安 主編 → 陳韋安 (17911)
UPDATE IGNORE book_persons SET person_id = 18054 WHERE person_id = 23507;
DELETE FROM persons WHERE person_id = 23507;  -- 黃伯和 主編 → 黃伯和 (18054)
UPDATE IGNORE book_persons SET person_id = 18360 WHERE person_id = 23531;
DELETE FROM persons WHERE person_id = 23531;  -- 歐偉昌 主編 → 歐偉昌 (18360)
UPDATE IGNORE book_persons SET person_id = 27411 WHERE person_id = 23616;
DELETE FROM persons WHERE person_id = 23616;  -- 林金水 主編 → 林金水 (27411)
UPDATE IGNORE book_persons SET person_id = 19977 WHERE person_id = 23638;
DELETE FROM persons WHERE person_id = 23638;  -- 伍國榮 主編 → 伍國榮 (19977)
UPDATE IGNORE book_persons SET person_id = 30454 WHERE person_id = 23742;
DELETE FROM persons WHERE person_id = 23742;  -- 道聲出版社 編著 → 道聲出版社 (30454)
UPDATE IGNORE book_persons SET person_id = 48056 WHERE person_id = 23754;
DELETE FROM persons WHERE person_id = 23754;  -- 愛德華．費雪 著 → 愛德華．費雪 (48056)
UPDATE IGNORE book_persons SET person_id = 18056 WHERE person_id = 23759;
DELETE FROM persons WHERE person_id = 23759;  -- 陶恕 著 → 陶恕 (18056)
UPDATE IGNORE book_persons SET person_id = 20531 WHERE person_id = 23760;
DELETE FROM persons WHERE person_id = 23760;  -- 斯奈德 彙編 → 斯奈德 (20531)
UPDATE IGNORE book_persons SET person_id = 24830 WHERE person_id = 23787;
DELETE FROM persons WHERE person_id = 23787;  -- 黃幹知 編著 → 黃幹知 (24830)
UPDATE IGNORE book_persons SET person_id = 20208 WHERE person_id = 23789;
DELETE FROM persons WHERE person_id = 23789;  -- 大衛．泰勒 編著 → 大衛．泰勒 (20208)
UPDATE IGNORE book_persons SET person_id = 24546 WHERE person_id = 23819;
DELETE FROM persons WHERE person_id = 23819;  -- Lo’s Psychology 編著 → Lo’s Psychology (24546)
UPDATE IGNORE book_persons SET person_id = 18977 WHERE person_id = 23826;
DELETE FROM persons WHERE person_id = 23826;  -- 莫陳詠恩 主編 → 莫陳詠恩 (18977)
UPDATE IGNORE book_persons SET person_id = 34632 WHERE person_id = 23842;
DELETE FROM persons WHERE person_id = 23842;  -- 波特 主編 → 波特 (34632)
UPDATE IGNORE book_persons SET person_id = 40501 WHERE person_id = 23884;
DELETE FROM persons WHERE person_id = 23884;  -- 張憶家 主編 → 張憶家 (40501)
UPDATE IGNORE book_persons SET person_id = 17477 WHERE person_id = 23942;
DELETE FROM persons WHERE person_id = 23942;  -- 黃漢輝 主編 → 黃漢輝 (17477)
UPDATE IGNORE book_persons SET person_id = 16918 WHERE person_id = 23983;
DELETE FROM persons WHERE person_id = 23983;  -- 理察．海斯 主編 → 理察．海斯 (16918)
UPDATE IGNORE book_persons SET person_id = 18472 WHERE person_id = 23985;
DELETE FROM persons WHERE person_id = 23985;  -- 李志剛 著 → 李志剛 (18472)
UPDATE IGNORE book_persons SET person_id = 28689 WHERE person_id = 23996;
DELETE FROM persons WHERE person_id = 23996;  -- 司務道 口述 → 司務道 (28689)
UPDATE IGNORE book_persons SET person_id = 39076 WHERE person_id = 23997;
DELETE FROM persons WHERE person_id = 23997;  -- 尚維瑞 撰 → 尚維瑞 (39076)
UPDATE IGNORE book_persons SET person_id = 20826 WHERE person_id = 24000;
DELETE FROM persons WHERE person_id = 24000;  -- 黃志成 著 → 黃志成 (20826)
UPDATE IGNORE book_persons SET person_id = 16886 WHERE person_id = 24087;
DELETE FROM persons WHERE person_id = 24087;  -- 王天佑 著 → 王天佑 (16886)
UPDATE IGNORE book_persons SET person_id = 19355 WHERE person_id = 24135;
DELETE FROM persons WHERE person_id = 24135;  -- 羅乃萱 著 → 羅乃萱 (19355)
UPDATE IGNORE book_persons SET person_id = 23370 WHERE person_id = 24288;
DELETE FROM persons WHERE person_id = 24288;  -- 馬秀娟 編著 → 馬秀娟 (23370)
UPDATE IGNORE book_persons SET person_id = 25047 WHERE person_id = 24417;
DELETE FROM persons WHERE person_id = 24417;  -- 黃文江 主編 → 黃文江 (25047)
UPDATE IGNORE book_persons SET person_id = 33604 WHERE person_id = 24438;
DELETE FROM persons WHERE person_id = 24438;  -- 賴弘專 主編 → 賴弘專 (33604)
UPDATE IGNORE book_persons SET person_id = 21361 WHERE person_id = 24529;
DELETE FROM persons WHERE person_id = 24529;  -- 陳淑娟 著 → 陳淑娟 (21361)
UPDATE IGNORE book_persons SET person_id = 18120 WHERE person_id = 24536;
DELETE FROM persons WHERE person_id = 24536;  -- 盧雲 著 → 盧雲 (18120)
UPDATE IGNORE book_persons SET person_id = 40583 WHERE person_id = 24545;
DELETE FROM persons WHERE person_id = 24545;  -- 束濟良 編著 → 束濟良 (40583)
UPDATE IGNORE book_persons SET person_id = 34642 WHERE person_id = 24561;
DELETE FROM persons WHERE person_id = 24561;  -- 朗根霍斯特 著 → 朗根霍斯特 (34642)
UPDATE IGNORE book_persons SET person_id = 20001 WHERE person_id = 24572;
DELETE FROM persons WHERE person_id = 24572;  -- 張證豪 主編 → 張證豪 (20001)
UPDATE IGNORE book_persons SET person_id = 23134 WHERE person_id = 24594;
DELETE FROM persons WHERE person_id = 24594;  -- 林郁 主編 → 林郁 (23134)
UPDATE IGNORE book_persons SET person_id = 19252 WHERE person_id = 24683;
DELETE FROM persons WHERE person_id = 24683;  -- 區祥江 主編 → 區祥江 (19252)
UPDATE IGNORE book_persons SET person_id = 19649 WHERE person_id = 24749;
DELETE FROM persons WHERE person_id = 24749;  -- 陳智衡 主編 → 陳智衡 (19649)
UPDATE IGNORE book_persons SET person_id = 30809 WHERE person_id = 24949;
DELETE FROM persons WHERE person_id = 24949;  -- 連上恩 著 → 連上恩 (30809)
UPDATE IGNORE book_persons SET person_id = 22314 WHERE person_id = 24956;
DELETE FROM persons WHERE person_id = 24956;  -- 余杰 主編 → 余杰 (22314)
UPDATE IGNORE book_persons SET person_id = 19707 WHERE person_id = 24966;
DELETE FROM persons WHERE person_id = 24966;  -- 葉泰昌 主編 → 葉泰昌 (19707)
UPDATE IGNORE book_persons SET person_id = 37028 WHERE person_id = 25024;
DELETE FROM persons WHERE person_id = 25024;  -- 盧雲(Henri J. M. Nouwen) 著 → 盧雲(Henri J. M. Nouwen) (37028)
UPDATE IGNORE book_persons SET person_id = 18096 WHERE person_id = 25057;
DELETE FROM persons WHERE person_id = 25057;  -- 約翰．歐文 著 → 約翰．歐文 (18096)
UPDATE IGNORE book_persons SET person_id = 27826 WHERE person_id = 25160;
DELETE FROM persons WHERE person_id = 25160;  -- 靖保路 主編 → 靖保路 (27826)
UPDATE IGNORE book_persons SET person_id = 26587 WHERE person_id = 25164;
DELETE FROM persons WHERE person_id = 25164;  -- 蘇美智 著 → 蘇美智 (26587)
UPDATE IGNORE book_persons SET person_id = 52724 WHERE person_id = 25199;
DELETE FROM persons WHERE person_id = 25199;  -- 李．羅伊．馬丁 主編 → 李．羅伊．馬丁 (52724)
UPDATE IGNORE book_persons SET person_id = 22953 WHERE person_id = 25246;
DELETE FROM persons WHERE person_id = 25246;  -- 楊慧林 主編 → 楊慧林 (22953)
UPDATE IGNORE book_persons SET person_id = 26400 WHERE person_id = 25282;
DELETE FROM persons WHERE person_id = 25282;  -- 高師寧 主編 → 高師寧 (26400)
UPDATE IGNORE book_persons SET person_id = 20158 WHERE person_id = 25303;
DELETE FROM persons WHERE person_id = 25303;  -- 劉炳熹 主編 → 劉炳熹 (20158)
UPDATE IGNORE book_persons SET person_id = 18388 WHERE person_id = 25307;
DELETE FROM persons WHERE person_id = 25307;  -- 楊錫儒 口述 → 楊錫儒 (18388)
UPDATE IGNORE book_persons SET person_id = 24402 WHERE person_id = 25326;
DELETE FROM persons WHERE person_id = 25326;  -- 賴品超 主編 → 賴品超 (24402)
UPDATE IGNORE book_persons SET person_id = 19517 WHERE person_id = 25327;
DELETE FROM persons WHERE person_id = 25327;  -- 王家輝 主編 → 王家輝 (19517)
UPDATE IGNORE book_persons SET person_id = 17915 WHERE person_id = 25359;
DELETE FROM persons WHERE person_id = 25359;  -- 林治平 著 → 林治平 (17915)
UPDATE IGNORE book_persons SET person_id = 43407 WHERE person_id = 25363;
DELETE FROM persons WHERE person_id = 25363;  -- 阮耀啟 主編 → 阮耀啟 (43407)
UPDATE IGNORE book_persons SET person_id = 27392 WHERE person_id = 25367;
DELETE FROM persons WHERE person_id = 25367;  -- 張國良 主編 → 張國良 (27392)
UPDATE IGNORE book_persons SET person_id = 19988 WHERE person_id = 25395;
DELETE FROM persons WHERE person_id = 25395;  -- 潘怡蓉 著 → 潘怡蓉 (19988)
UPDATE IGNORE book_persons SET person_id = 35144 WHERE person_id = 25531;
DELETE FROM persons WHERE person_id = 25531;  -- 蔡宇哲 主編 → 蔡宇哲 (35144)
UPDATE IGNORE book_persons SET person_id = 17265 WHERE person_id = 25590;
DELETE FROM persons WHERE person_id = 25590;  -- 陳廷忠 主編 → 陳廷忠 (17265)
UPDATE IGNORE book_persons SET person_id = 19481 WHERE person_id = 25604;
DELETE FROM persons WHERE person_id = 25604;  -- 舍禾 主編 → 舍禾 (19481)
UPDATE IGNORE book_persons SET person_id = 23350 WHERE person_id = 25629;
DELETE FROM persons WHERE person_id = 25629;  -- 陳嘉銘 主編 → 陳嘉銘 (23350)
UPDATE IGNORE book_persons SET person_id = 27240 WHERE person_id = 25659;
DELETE FROM persons WHERE person_id = 25659;  -- 艾倫．狄波頓 /主編 → 艾倫．狄波頓 (27240)
UPDATE IGNORE book_persons SET person_id = 18851 WHERE person_id = 25691;
DELETE FROM persons WHERE person_id = 25691;  -- 楊秀珠 編著 → 楊秀珠 (18851)
UPDATE IGNORE book_persons SET person_id = 22265 WHERE person_id = 25712;
DELETE FROM persons WHERE person_id = 25712;  -- 陳潔心 著 → 陳潔心 (22265)
UPDATE IGNORE book_persons SET person_id = 26734 WHERE person_id = 25719;
DELETE FROM persons WHERE person_id = 25719;  -- 阿丁 編著 → 阿丁 (26734)
UPDATE IGNORE book_persons SET person_id = 30503 WHERE person_id = 25726;
DELETE FROM persons WHERE person_id = 25726;  -- 劉家峰主編 → 劉家峰 (30503)
UPDATE IGNORE book_persons SET person_id = 18681 WHERE person_id = 25807;
DELETE FROM persons WHERE person_id = 25807;  -- 鄒崇銘 編著 → 鄒崇銘 (18681)
UPDATE IGNORE book_persons SET person_id = 19929 WHERE person_id = 25813;
DELETE FROM persons WHERE person_id = 25813;  -- 楊克勤 主編 → 楊克勤 (19929)
UPDATE IGNORE book_persons SET person_id = 18861 WHERE person_id = 25905;
DELETE FROM persons WHERE person_id = 25905;  -- 林榮樹 主編 → 林榮樹 (18861)
UPDATE IGNORE book_persons SET person_id = 37107 WHERE person_id = 25912;
DELETE FROM persons WHERE person_id = 25912;  -- 林梅枝 著 → 林梅枝 (37107)
UPDATE IGNORE book_persons SET person_id = 30515 WHERE person_id = 25949;
DELETE FROM persons WHERE person_id = 25949;  -- 陶飛亞 主編 → 陶飛亞 (30515)
UPDATE IGNORE book_persons SET person_id = 24465 WHERE person_id = 25951;
DELETE FROM persons WHERE person_id = 25951;  -- 陳凌軒 著 → 陳凌軒 (24465)
UPDATE IGNORE book_persons SET person_id = 46591 WHERE person_id = 25963;
DELETE FROM persons WHERE person_id = 25963;  -- 林佳伶 著 → 林佳伶 (46591)
UPDATE IGNORE book_persons SET person_id = 17965 WHERE person_id = 25993;
DELETE FROM persons WHERE person_id = 25993;  -- 鄧紹光 主編 → 鄧紹光 (17965)
UPDATE IGNORE book_persons SET person_id = 17863 WHERE person_id = 26037;
DELETE FROM persons WHERE person_id = 26037;  -- 劉清虔 主編 → 劉清虔 (17863)
UPDATE IGNORE book_persons SET person_id = 21912 WHERE person_id = 26080;
DELETE FROM persons WHERE person_id = 26080;  -- 陸亮 主編 → 陸亮 (21912)
UPDATE IGNORE book_persons SET person_id = 22214 WHERE person_id = 26139;
DELETE FROM persons WHERE person_id = 26139;  -- 德國合一弟兄會/編著 → 德國合一弟兄會 (22214)
UPDATE IGNORE book_persons SET person_id = 36655 WHERE person_id = 26147;
DELETE FROM persons WHERE person_id = 26147;  -- 艾咪 著 → 艾咪 (36655)
UPDATE IGNORE book_persons SET person_id = 20223 WHERE person_id = 26155;
DELETE FROM persons WHERE person_id = 26155;  -- 蔡貴恆 主編 → 蔡貴恆 (20223)
UPDATE IGNORE book_persons SET person_id = 18457 WHERE person_id = 26198;
DELETE FROM persons WHERE person_id = 26198;  -- 鄺偉志 編著 → 鄺偉志 (18457)
UPDATE IGNORE book_persons SET person_id = 44876 WHERE person_id = 26241;
DELETE FROM persons WHERE person_id = 26241;  -- 芭芭拉．波特納 著 → 芭芭拉．波特納 (44876)
UPDATE IGNORE book_persons SET person_id = 18832 WHERE person_id = 26246;
DELETE FROM persons WHERE person_id = 26246;  -- 何崇謙 主編 → 何崇謙 (18832)
UPDATE IGNORE book_persons SET person_id = 16882 WHERE person_id = 26247;
DELETE FROM persons WHERE person_id = 26247;  -- 黎永明 主編 → 黎永明 (16882)
UPDATE IGNORE book_persons SET person_id = 22842 WHERE person_id = 26251;
DELETE FROM persons WHERE person_id = 26251;  -- 高蘇珊娜 著 → 高蘇珊娜 (22842)
UPDATE IGNORE book_persons SET person_id = 45609 WHERE person_id = 26295;
DELETE FROM persons WHERE person_id = 26295;  -- 卡琳．朱爾 著 → 卡琳．朱爾 (45609)
UPDATE IGNORE book_persons SET person_id = 26381 WHERE person_id = 26380;
DELETE FROM persons WHERE person_id = 26380;  -- 邱世崇 著 → 邱世崇 (26381)
UPDATE IGNORE book_persons SET person_id = 26493 WHERE person_id = 26401;
DELETE FROM persons WHERE person_id = 26401;  -- 李向平 主編 → 李向平 (26493)
UPDATE IGNORE book_persons SET person_id = 20715 WHERE person_id = 26405;
DELETE FROM persons WHERE person_id = 26405;  -- 李繼吾/口述 → 李繼吾 (20715)
UPDATE IGNORE book_persons SET person_id = 22286 WHERE person_id = 26445;
DELETE FROM persons WHERE person_id = 26445;  -- 三浦綾子 著 → 三浦綾子 (22286)
UPDATE IGNORE book_persons SET person_id = 26367 WHERE person_id = 26476;
DELETE FROM persons WHERE person_id = 26476;  -- 胡維勤  主編 → 胡維勤 (26367)
UPDATE IGNORE book_persons SET person_id = 18661 WHERE person_id = 26497;
DELETE FROM persons WHERE person_id = 26497;  -- 凱瑟琳．拉庫娜 主編 → 凱瑟琳．拉庫娜 (18661)
UPDATE IGNORE book_persons SET person_id = 22874 WHERE person_id = 26518;
DELETE FROM persons WHERE person_id = 26518;  -- 宋軍 主編 → 宋軍 (22874)
UPDATE IGNORE book_persons SET person_id = 17012 WHERE person_id = 26546;
DELETE FROM persons WHERE person_id = 26546;  -- 李思敬主編 → 李思敬 (17012)
UPDATE IGNORE book_persons SET person_id = 17674 WHERE person_id = 26558;
DELETE FROM persons WHERE person_id = 26558;  -- 馬挺 主編 → 馬挺 (17674)
UPDATE IGNORE book_persons SET person_id = 18303 WHERE person_id = 26590;
DELETE FROM persons WHERE person_id = 26590;  -- 亨利．葛洛法 口述 → 亨利．葛洛法 (18303)
UPDATE IGNORE book_persons SET person_id = 17523 WHERE person_id = 26641;
DELETE FROM persons WHERE person_id = 26641;  -- 黃根春 主編 → 黃根春 (17523)
UPDATE IGNORE book_persons SET person_id = 35678 WHERE person_id = 26655;
DELETE FROM persons WHERE person_id = 26655;  -- 馬彼得 著 → 馬彼得 (35678)
UPDATE IGNORE book_persons SET person_id = 20551 WHERE person_id = 26700;
DELETE FROM persons WHERE person_id = 26700;  -- 廖元威 主編 → 廖元威 (20551)
UPDATE IGNORE book_persons SET person_id = 35993 WHERE person_id = 26718;
DELETE FROM persons WHERE person_id = 26718;  -- 凱琳．馬肯茲 著 → 凱琳．馬肯茲 (35993)
UPDATE IGNORE book_persons SET person_id = 23661 WHERE person_id = 26742;
DELETE FROM persons WHERE person_id = 26742;  -- 黃國煜 編著 → 黃國煜 (23661)
UPDATE IGNORE book_persons SET person_id = 16725 WHERE person_id = 26778;
DELETE FROM persons WHERE person_id = 26778;  -- 戴維茲主編 → 戴維茲 (16725)
UPDATE IGNORE book_persons SET person_id = 24420 WHERE person_id = 26807;
DELETE FROM persons WHERE person_id = 26807;  -- 林羿翧／著 → 林羿翧 (24420)
UPDATE IGNORE book_persons SET person_id = 25795 WHERE person_id = 26853;
DELETE FROM persons WHERE person_id = 26853;  -- 姚松炎 主編 → 姚松炎 (25795)
UPDATE IGNORE book_persons SET person_id = 21008 WHERE person_id = 26879;
DELETE FROM persons WHERE person_id = 26879;  -- 吳雷川 著 → 吳雷川 (21008)
UPDATE IGNORE book_persons SET person_id = 21308 WHERE person_id = 26929;
DELETE FROM persons WHERE person_id = 26929;  -- 上官賢恩 編著 → 上官賢恩 (21308)
UPDATE IGNORE book_persons SET person_id = 26489 WHERE person_id = 27007;
DELETE FROM persons WHERE person_id = 27007;  -- 凱琳‧馬肯茲 著 → 凱琳‧馬肯茲 (26489)
UPDATE IGNORE book_persons SET person_id = 16745 WHERE person_id = 27110;
DELETE FROM persons WHERE person_id = 27110;  -- 高銘謙 主編 → 高銘謙 (16745)
UPDATE IGNORE book_persons SET person_id = 38917 WHERE person_id = 27215;
DELETE FROM persons WHERE person_id = 27215;  -- 林浣心校長 口述 → 林浣心校長 (38917)
UPDATE IGNORE book_persons SET person_id = 46725 WHERE person_id = 27267;
DELETE FROM persons WHERE person_id = 27267;  -- 威廉森 著 → 威廉森 (46725)
UPDATE IGNORE book_persons SET person_id = 23589 WHERE person_id = 27289;
DELETE FROM persons WHERE person_id = 27289;  -- 洪蘭 著 → 洪蘭 (23589)
UPDATE IGNORE book_persons SET person_id = 46163 WHERE person_id = 27312;
DELETE FROM persons WHERE person_id = 27312;  -- 林日峰 編著 → 林日峰 (46163)
UPDATE IGNORE book_persons SET person_id = 37903 WHERE person_id = 27380;
DELETE FROM persons WHERE person_id = 27380;  -- 王徵原著 → 王徵 (37903)
UPDATE IGNORE book_persons SET person_id = 16923 WHERE person_id = 27410;
DELETE FROM persons WHERE person_id = 27410;  -- 周兆真 主編 → 周兆真 (16923)
UPDATE IGNORE book_persons SET person_id = 18356 WHERE person_id = 27414;
DELETE FROM persons WHERE person_id = 27414;  -- 陳若愚 主編 → 陳若愚 (18356)
UPDATE IGNORE book_persons SET person_id = 27313 WHERE person_id = 27499;
DELETE FROM persons WHERE person_id = 27499;  -- 梅思道 著 → 梅思道 (27313)
UPDATE IGNORE book_persons SET person_id = 18861 WHERE person_id = 27544;
DELETE FROM persons WHERE person_id = 27544;  -- 林榮樹  主編 → 林榮樹 (18861)
UPDATE IGNORE book_persons SET person_id = 21036 WHERE person_id = 27608;
DELETE FROM persons WHERE person_id = 27608;  -- 升登 編著 → 升登 (21036)
UPDATE IGNORE book_persons SET person_id = 20478 WHERE person_id = 27634;
DELETE FROM persons WHERE person_id = 27634;  -- 魏克利 主編 → 魏克利 (20478)
UPDATE IGNORE book_persons SET person_id = 16968 WHERE person_id = 27671;
DELETE FROM persons WHERE person_id = 27671;  -- 陸可鐸 著 → 陸可鐸 (16968)
UPDATE IGNORE book_persons SET person_id = 38010 WHERE person_id = 27698;
DELETE FROM persons WHERE person_id = 27698;  -- 麥卡錫 編著 → 麥卡錫 (38010)
UPDATE IGNORE book_persons SET person_id = 17702 WHERE person_id = 27784;
DELETE FROM persons WHERE person_id = 27784;  -- 周聯華 編著 → 周聯華 (17702)
UPDATE IGNORE book_persons SET person_id = 20753 WHERE person_id = 27791;
DELETE FROM persons WHERE person_id = 27791;  -- 何美意口述 → 何美意 (20753)
UPDATE IGNORE book_persons SET person_id = 21337 WHERE person_id = 27793;
DELETE FROM persons WHERE person_id = 27793;  -- 小麥子著 → 小麥子 (21337)
UPDATE IGNORE book_persons SET person_id = 19119 WHERE person_id = 27797;
DELETE FROM persons WHERE person_id = 27797;  -- 吳思源 主編 → 吳思源 (19119)
UPDATE IGNORE book_persons SET person_id = 36570 WHERE person_id = 27840;
DELETE FROM persons WHERE person_id = 27840;  -- 周嘉慧 主編 → 周嘉慧 (36570)
UPDATE IGNORE book_persons SET person_id = 28245 WHERE person_id = 27902;
DELETE FROM persons WHERE person_id = 27902;  -- 派翠希亞．聖約翰 著 → 派翠希亞．聖約翰 (28245)
UPDATE IGNORE book_persons SET person_id = 39059 WHERE person_id = 27913;
DELETE FROM persons WHERE person_id = 27913;  -- 泰澤的以馬內利弟兄著 → 泰澤的以馬內利弟兄 (39059)
UPDATE IGNORE book_persons SET person_id = 17928 WHERE person_id = 27916;
DELETE FROM persons WHERE person_id = 27916;  -- 吳耀宗著 → 吳耀宗 (17928)
UPDATE IGNORE book_persons SET person_id = 20576 WHERE person_id = 27924;
DELETE FROM persons WHERE person_id = 27924;  -- 彭順強 主編 → 彭順強 (20576)
UPDATE IGNORE book_persons SET person_id = 17587 WHERE person_id = 27925;
DELETE FROM persons WHERE person_id = 27925;  -- 梁美心 主編 → 梁美心 (17587)
UPDATE IGNORE book_persons SET person_id = 18489 WHERE person_id = 27941;
DELETE FROM persons WHERE person_id = 27941;  -- 李耀全 主編 → 李耀全 (18489)
UPDATE IGNORE book_persons SET person_id = 25522 WHERE person_id = 27962;
DELETE FROM persons WHERE person_id = 27962;  -- 徐濟時著 → 徐濟時 (25522)
UPDATE IGNORE book_persons SET person_id = 18457 WHERE person_id = 28002;
DELETE FROM persons WHERE person_id = 28002;  -- 鄺偉志 主編 → 鄺偉志 (18457)
UPDATE IGNORE book_persons SET person_id = 21611 WHERE person_id = 28028;
DELETE FROM persons WHERE person_id = 28028;  -- 李錦洪 主編 → 李錦洪 (21611)
UPDATE IGNORE book_persons SET person_id = 38191 WHERE person_id = 28041;
DELETE FROM persons WHERE person_id = 28041;  -- 保羅編輯小組 主編 → 保羅編輯小組 (38191)
UPDATE IGNORE book_persons SET person_id = 23605 WHERE person_id = 28170;
DELETE FROM persons WHERE person_id = 28170;  -- 德蕾莎修女 著 → 德蕾莎修女 (23605)
UPDATE IGNORE book_persons SET person_id = 18529 WHERE person_id = 28197;
DELETE FROM persons WHERE person_id = 28197;  -- 曾慶豹 主編 → 曾慶豹 (18529)
UPDATE IGNORE book_persons SET person_id = 21970 WHERE person_id = 28213;
DELETE FROM persons WHERE person_id = 28213;  -- 黃慶雲 編著 → 黃慶雲 (21970)
UPDATE IGNORE book_persons SET person_id = 38417 WHERE person_id = 28217;
DELETE FROM persons WHERE person_id = 28217;  -- 時代論壇 主編 → 時代論壇 (38417)
UPDATE IGNORE book_persons SET person_id = 26983 WHERE person_id = 28270;
DELETE FROM persons WHERE person_id = 28270;  -- 史丹．詹茲著 → 史丹．詹茲 (26983)
UPDATE IGNORE book_persons SET person_id = 35859 WHERE person_id = 28647;
DELETE FROM persons WHERE person_id = 28647;  -- 錢錕 總審訂 → 錢錕 (35859)
UPDATE IGNORE book_persons SET person_id = 49210 WHERE person_id = 28648;
DELETE FROM persons WHERE person_id = 28648;  -- 吳小新 主編 → 吳小新 (49210)
UPDATE IGNORE book_persons SET person_id = 21325 WHERE person_id = 28656;
DELETE FROM persons WHERE person_id = 28656;  -- 翁麗玉 編著 → 翁麗玉 (21325)
UPDATE IGNORE book_persons SET person_id = 37428 WHERE person_id = 28675;
DELETE FROM persons WHERE person_id = 28675;  -- 基督教客家福音協會 編著 → 基督教客家福音協會 (37428)
UPDATE IGNORE book_persons SET person_id = 18546 WHERE person_id = 28683;
DELETE FROM persons WHERE person_id = 28683;  -- 龔立人 主編 → 龔立人 (18546)
UPDATE IGNORE book_persons SET person_id = 17025 WHERE person_id = 28714;
DELETE FROM persons WHERE person_id = 28714;  -- 盧龍光 主編 → 盧龍光 (17025)
UPDATE IGNORE book_persons SET person_id = 17371 WHERE person_id = 28752;
DELETE FROM persons WHERE person_id = 28752;  -- 何傑 主編 → 何傑 (17371)
UPDATE IGNORE book_persons SET person_id = 19931 WHERE person_id = 28879;
DELETE FROM persons WHERE person_id = 28879;  -- 傅士德 主編 → 傅士德 (19931)
UPDATE IGNORE book_persons SET person_id = 18391 WHERE person_id = 28881;
DELETE FROM persons WHERE person_id = 28881;  -- 蘇緋雲 著 → 蘇緋雲 (18391)
UPDATE IGNORE book_persons SET person_id = 30213 WHERE person_id = 29042;
DELETE FROM persons WHERE person_id = 29042;  -- 郭明璋  主編 → 郭明璋 (30213)
UPDATE IGNORE book_persons SET person_id = 30106 WHERE person_id = 29112;
DELETE FROM persons WHERE person_id = 29112;  -- 莫約翰 著 → 莫約翰 (30106)
UPDATE IGNORE book_persons SET person_id = 20359 WHERE person_id = 29335;
DELETE FROM persons WHERE person_id = 29335;  -- 林偕明穱 口述 → 林偕明穱 (20359)
UPDATE IGNORE book_persons SET person_id = 21460 WHERE person_id = 29364;
DELETE FROM persons WHERE person_id = 29364;  -- 吳嘉儀 主編 → 吳嘉儀 (21460)
UPDATE IGNORE book_persons SET person_id = 16938 WHERE person_id = 29376;
DELETE FROM persons WHERE person_id = 29376;  -- 賴若瀚 主編 → 賴若瀚 (16938)
UPDATE IGNORE book_persons SET person_id = 29361 WHERE person_id = 29387;
DELETE FROM persons WHERE person_id = 29387;  -- 黃少芬 著 → 黃少芬 (29361)
UPDATE IGNORE book_persons SET person_id = 18673 WHERE person_id = 29409;
DELETE FROM persons WHERE person_id = 29409;  -- 曹偉彤 主編 → 曹偉彤 (18673)
UPDATE IGNORE book_persons SET person_id = 19991 WHERE person_id = 29420;
DELETE FROM persons WHERE person_id = 29420;  -- 莎拉揚 著 → 莎拉揚 (19991)
UPDATE IGNORE book_persons SET person_id = 17273 WHERE person_id = 29727;
DELETE FROM persons WHERE person_id = 29727;  -- 謝慧兒 主編 → 謝慧兒 (17273)
UPDATE IGNORE book_persons SET person_id = 23457 WHERE person_id = 29771;
DELETE FROM persons WHERE person_id = 29771;  -- 郭豫斌 主編 → 郭豫斌 (23457)
UPDATE IGNORE book_persons SET person_id = 29359 WHERE person_id = 29830;
DELETE FROM persons WHERE person_id = 29830;  -- 陳家琳 主編 → 陳家琳 (29359)
UPDATE IGNORE book_persons SET person_id = 21254 WHERE person_id = 29848;
DELETE FROM persons WHERE person_id = 29848;  -- 陳芝瑛 編著 → 陳芝瑛 (21254)
UPDATE IGNORE book_persons SET person_id = 28237 WHERE person_id = 29926;
DELETE FROM persons WHERE person_id = 29926;  -- 丁新豹 主編 → 丁新豹 (28237)
UPDATE IGNORE book_persons SET person_id = 48546 WHERE person_id = 29943;
DELETE FROM persons WHERE person_id = 29943;  -- 盧立編著 → 盧立 (48546)
UPDATE IGNORE book_persons SET person_id = 16942 WHERE person_id = 29955;
DELETE FROM persons WHERE person_id = 29955;  -- 柯志明　主編 → 柯志明 (16942)
UPDATE IGNORE book_persons SET person_id = 32539 WHERE person_id = 29995;
DELETE FROM persons WHERE person_id = 29995;  -- 吳勇 口述 → 吳勇 (32539)
UPDATE IGNORE book_persons SET person_id = 18199 WHERE person_id = 30023;
DELETE FROM persons WHERE person_id = 30023;  -- 蘇遠泰 主編 → 蘇遠泰 (18199)
UPDATE IGNORE book_persons SET person_id = 46989 WHERE person_id = 30044;
DELETE FROM persons WHERE person_id = 30044;  -- 翟兆平 主編 → 翟兆平 (46989)
UPDATE IGNORE book_persons SET person_id = 28420 WHERE person_id = 30105;
DELETE FROM persons WHERE person_id = 30105;  -- 張修齊 主編 → 張修齊 (28420)
UPDATE IGNORE book_persons SET person_id = 25917 WHERE person_id = 30143;
DELETE FROM persons WHERE person_id = 30143;  -- 朱綽婷 著 → 朱綽婷 (25917)
UPDATE IGNORE book_persons SET person_id = 16899 WHERE person_id = 30249;
DELETE FROM persons WHERE person_id = 30249;  -- 倪柝聲 著 → 倪柝聲 (16899)
UPDATE IGNORE book_persons SET person_id = 22220 WHERE person_id = 30258;
DELETE FROM persons WHERE person_id = 30258;  -- 本仁約翰 著 → 本仁約翰 (22220)
UPDATE IGNORE book_persons SET person_id = 51133 WHERE person_id = 30310;
DELETE FROM persons WHERE person_id = 30310;  -- 真愛家庭協會編著 → 真愛家庭協會 (51133)
UPDATE IGNORE book_persons SET person_id = 18687 WHERE person_id = 30372;
DELETE FROM persons WHERE person_id = 30372;  -- 李錦綸　著 → 李錦綸 (18687)
UPDATE IGNORE book_persons SET person_id = 22118 WHERE person_id = 30391;
DELETE FROM persons WHERE person_id = 30391;  -- 眼核樹 著 → 眼核樹 (22118)
UPDATE IGNORE book_persons SET person_id = 21568 WHERE person_id = 30423;
DELETE FROM persons WHERE person_id = 30423;  -- 張文偉 著 → 張文偉 (21568)
UPDATE IGNORE book_persons SET person_id = 46799 WHERE person_id = 30487;
DELETE FROM persons WHERE person_id = 30487;  -- 凱倫．蓮．威廉斯 著 → 凱倫．蓮．威廉斯 (46799)
UPDATE IGNORE book_persons SET person_id = 35838 WHERE person_id = 30522;
DELETE FROM persons WHERE person_id = 30522;  -- 張明哲口述 → 張明哲 (35838)
UPDATE IGNORE book_persons SET person_id = 46800 WHERE person_id = 30570;
DELETE FROM persons WHERE person_id = 30570;  -- 瑪麗．蓮．芮 著 → 瑪麗．蓮．芮 (46800)
UPDATE IGNORE book_persons SET person_id = 49879 WHERE person_id = 30588;
DELETE FROM persons WHERE person_id = 30588;  -- 秋澤公二 著 → 秋澤公二 (49879)
UPDATE IGNORE book_persons SET person_id = 17362 WHERE person_id = 30609;
DELETE FROM persons WHERE person_id = 30609;  -- 陳南州  主編 → 陳南州 (17362)
UPDATE IGNORE book_persons SET person_id = 21571 WHERE person_id = 30629;
DELETE FROM persons WHERE person_id = 30629;  -- 馬克．吉爾曼 著 → 馬克．吉爾曼 (21571)
UPDATE IGNORE book_persons SET person_id = 27729 WHERE person_id = 30656;
DELETE FROM persons WHERE person_id = 30656;  -- 伊芙．邦婷 著 → 伊芙．邦婷 (27729)
UPDATE IGNORE book_persons SET person_id = 24015 WHERE person_id = 30678;
DELETE FROM persons WHERE person_id = 30678;  -- 譚靜芝主編 → 譚靜芝 (24015)
UPDATE IGNORE book_persons SET person_id = 26484 WHERE person_id = 30741;
DELETE FROM persons WHERE person_id = 30741;  -- 陳志華 等編著 → 陳志華 (26484)
UPDATE IGNORE book_persons SET person_id = 32259 WHERE person_id = 30745;
DELETE FROM persons WHERE person_id = 30745;  -- 葉敬德 主編 → 葉敬德 (32259)
UPDATE IGNORE book_persons SET person_id = 22266 WHERE person_id = 30983;
DELETE FROM persons WHERE person_id = 30983;  -- 彼得．赫爾德林 著 → 彼得．赫爾德林 (22266)
UPDATE IGNORE book_persons SET person_id = 22870 WHERE person_id = 31106;
DELETE FROM persons WHERE person_id = 31106;  -- 李金強 主編 → 李金強 (22870)
UPDATE IGNORE book_persons SET person_id = 16953 WHERE person_id = 31118;
DELETE FROM persons WHERE person_id = 31118;  -- 黃錫木 主編 → 黃錫木 (16953)
UPDATE IGNORE book_persons SET person_id = 21283 WHERE person_id = 31119;
DELETE FROM persons WHERE person_id = 31119;  -- 蘇文隆 主編 → 蘇文隆 (21283)
UPDATE IGNORE book_persons SET person_id = 46802 WHERE person_id = 31177;
DELETE FROM persons WHERE person_id = 31177;  -- 潔若婷．麥考琳 著 → 潔若婷．麥考琳 (46802)
UPDATE IGNORE book_persons SET person_id = 32230 WHERE person_id = 31189;
DELETE FROM persons WHERE person_id = 31189;  -- 梁工 主編 → 梁工 (32230)
UPDATE IGNORE book_persons SET person_id = 29187 WHERE person_id = 31223;
DELETE FROM persons WHERE person_id = 31223;  -- 呂焯安主編 → 呂焯安 (29187)
UPDATE IGNORE book_persons SET person_id = 31313 WHERE person_id = 31308;
DELETE FROM persons WHERE person_id = 31308;  -- 曾文星 編著 → 曾文星 (31313)
UPDATE IGNORE book_persons SET person_id = 30805 WHERE person_id = 31368;
DELETE FROM persons WHERE person_id = 31368;  -- 林兆源 主編 → 林兆源 (30805)
UPDATE IGNORE book_persons SET person_id = 28320 WHERE person_id = 31408;
DELETE FROM persons WHERE person_id = 31408;  -- 齊德芳 主編 → 齊德芳 (28320)
UPDATE IGNORE book_persons SET person_id = 18313 WHERE person_id = 31437;
DELETE FROM persons WHERE person_id = 31437;  -- 陳俊偉 主編 → 陳俊偉 (18313)
UPDATE IGNORE book_persons SET person_id = 24402 WHERE person_id = 31500;
DELETE FROM persons WHERE person_id = 31500;  -- 賴品超 編著 → 賴品超 (24402)
UPDATE IGNORE book_persons SET person_id = 23174 WHERE person_id = 31560;
DELETE FROM persons WHERE person_id = 31560;  -- 劉義章 主編 → 劉義章 (23174)
UPDATE IGNORE book_persons SET person_id = 31460 WHERE person_id = 31585;
DELETE FROM persons WHERE person_id = 31585;  -- 陳漁 主編 → 陳漁 (31460)
UPDATE IGNORE book_persons SET person_id = 49191 WHERE person_id = 31617;
DELETE FROM persons WHERE person_id = 31617;  -- 江丕盛 主編 → 江丕盛 (49191)
UPDATE IGNORE book_persons SET person_id = 35247 WHERE person_id = 31639;
DELETE FROM persons WHERE person_id = 31639;  -- 史馬提 編著 → 史馬提 (35247)
UPDATE IGNORE book_persons SET person_id = 18687 WHERE person_id = 31752;
DELETE FROM persons WHERE person_id = 31752;  -- 李錦綸 主編 → 李錦綸 (18687)
UPDATE IGNORE book_persons SET person_id = 20590 WHERE person_id = 31831;
DELETE FROM persons WHERE person_id = 31831;  -- 金明瑋 主編 → 金明瑋 (20590)
UPDATE IGNORE book_persons SET person_id = 18492 WHERE person_id = 31940;
DELETE FROM persons WHERE person_id = 31940;  -- 楊慶球 主編 → 楊慶球 (18492)
UPDATE IGNORE book_persons SET person_id = 17636 WHERE person_id = 31947;
DELETE FROM persons WHERE person_id = 31947;  -- 路卡斯 主編 → 路卡斯 (17636)
UPDATE IGNORE book_persons SET person_id = 30445 WHERE person_id = 31986;
DELETE FROM persons WHERE person_id = 31986;  -- 何恭上 編著 → 何恭上 (30445)
UPDATE IGNORE book_persons SET person_id = 18825 WHERE person_id = 32036;
DELETE FROM persons WHERE person_id = 32036;  -- 彼得．魏格納 編著 → 彼得．魏格納 (18825)
UPDATE IGNORE book_persons SET person_id = 17357 WHERE person_id = 32087;
DELETE FROM persons WHERE person_id = 32087;  -- 曾立華主編 → 曾立華 (17357)
UPDATE IGNORE book_persons SET person_id = 31943 WHERE person_id = 32283;
DELETE FROM persons WHERE person_id = 32283;  -- 多湖輝 著 → 多湖輝 (31943)
UPDATE IGNORE book_persons SET person_id = 32925 WHERE person_id = 32347;
DELETE FROM persons WHERE person_id = 32347;  -- 封志理 主編 → 封志理 (32925)
UPDATE IGNORE book_persons SET person_id = 34175 WHERE person_id = 32356;
DELETE FROM persons WHERE person_id = 32356;  -- 曾念粵 主編 → 曾念粵 (34175)
UPDATE IGNORE book_persons SET person_id = 19229 WHERE person_id = 32373;
DELETE FROM persons WHERE person_id = 32373;  -- 鄭仰恩 主編 → 鄭仰恩 (19229)
UPDATE IGNORE book_persons SET person_id = 24019 WHERE person_id = 32459;
DELETE FROM persons WHERE person_id = 32459;  -- 梁國權 主編 → 梁國權 (24019)
UPDATE IGNORE book_persons SET person_id = 17362 WHERE person_id = 32505;
DELETE FROM persons WHERE person_id = 32505;  -- 陳南州 主編 → 陳南州 (17362)
UPDATE IGNORE book_persons SET person_id = 17866 WHERE person_id = 32514;
DELETE FROM persons WHERE person_id = 32514;  -- 楊牧谷 主編 → 楊牧谷 (17866)
UPDATE IGNORE book_persons SET person_id = 17264 WHERE person_id = 32722;
DELETE FROM persons WHERE person_id = 32722;  -- 謝品然 主編 → 謝品然 (17264)
UPDATE IGNORE book_persons SET person_id = 31167 WHERE person_id = 32749;
DELETE FROM persons WHERE person_id = 32749;  -- 李熾昌著 → 李熾昌 (31167)
UPDATE IGNORE book_persons SET person_id = 36458 WHERE person_id = 32779;
DELETE FROM persons WHERE person_id = 32779;  -- 張老師 主編 → 張老師 (36458)
UPDATE IGNORE book_persons SET person_id = 30528 WHERE person_id = 32814;
DELETE FROM persons WHERE person_id = 32814;  -- 楊森富 編著 → 楊森富 (30528)
UPDATE IGNORE book_persons SET person_id = 17371 WHERE person_id = 33293;
DELETE FROM persons WHERE person_id = 33293;  -- 何傑　主編 → 何傑 (17371)
UPDATE IGNORE book_persons SET person_id = 21436 WHERE person_id = 33337;
DELETE FROM persons WHERE person_id = 33337;  -- 周秀芳 著 → 周秀芳 (21436)
UPDATE IGNORE book_persons SET person_id = 20625 WHERE person_id = 33395;
DELETE FROM persons WHERE person_id = 33395;  -- 王正中 主編 → 王正中 (20625)
UPDATE IGNORE book_persons SET person_id = 18911 WHERE person_id = 33494;
DELETE FROM persons WHERE person_id = 33494;  -- 洪中夫 主編 → 洪中夫 (18911)
UPDATE IGNORE book_persons SET person_id = 20341 WHERE person_id = 33514;
DELETE FROM persons WHERE person_id = 33514;  -- 卓天仁 主編 → 卓天仁 (20341)
UPDATE IGNORE book_persons SET person_id = 29833 WHERE person_id = 33521;
DELETE FROM persons WHERE person_id = 33521;  -- 謝品彰 主編 → 謝品彰 (29833)
UPDATE IGNORE book_persons SET person_id = 34688 WHERE person_id = 33554;
DELETE FROM persons WHERE person_id = 33554;  -- 彭海瑩 主編 → 彭海瑩 (34688)
UPDATE IGNORE book_persons SET person_id = 18016 WHERE person_id = 33720;
DELETE FROM persons WHERE person_id = 33720;  -- 林鴻信 主編 → 林鴻信 (18016)
UPDATE IGNORE book_persons SET person_id = 18793 WHERE person_id = 33807;
DELETE FROM persons WHERE person_id = 33807;  -- 陳琇玟 編著 → 陳琇玟 (18793)
UPDATE IGNORE book_persons SET person_id = 19702 WHERE person_id = 33858;
DELETE FROM persons WHERE person_id = 33858;  -- 保羅麥肯 主編 → 保羅麥肯 (19702)
UPDATE IGNORE book_persons SET person_id = 16854 WHERE person_id = 33883;
DELETE FROM persons WHERE person_id = 33883;  -- 曾宗盛 主編 → 曾宗盛 (16854)
UPDATE IGNORE book_persons SET person_id = 22033 WHERE person_id = 34212;
DELETE FROM persons WHERE person_id = 34212;  -- 莊捷安  主編 → 莊捷安 (22033)
UPDATE IGNORE book_persons SET person_id = 22879 WHERE person_id = 34344;
DELETE FROM persons WHERE person_id = 34344;  -- 伍渭文 主編 → 伍渭文 (22879)
UPDATE IGNORE book_persons SET person_id = 43602 WHERE person_id = 34345;
DELETE FROM persons WHERE person_id = 34345;  -- 雷雨田 主編 → 雷雨田 (43602)
UPDATE IGNORE book_persons SET person_id = 34697 WHERE person_id = 34420;
DELETE FROM persons WHERE person_id = 34420;  -- 劉昭雋 主編 → 劉昭雋 (34697)
UPDATE IGNORE book_persons SET person_id = 16966 WHERE person_id = 34640;
DELETE FROM persons WHERE person_id = 34640;  -- 蔡春曦 編著 → 蔡春曦 (16966)
UPDATE IGNORE book_persons SET person_id = 38168 WHERE person_id = 34727;
DELETE FROM persons WHERE person_id = 34727;  -- 王子芳 主編 → 王子芳 (38168)
UPDATE IGNORE book_persons SET person_id = 22035 WHERE person_id = 34802;
DELETE FROM persons WHERE person_id = 34802;  -- 馮珮  主編 → 馮珮 (22035)
UPDATE IGNORE book_persons SET person_id = 33324 WHERE person_id = 34971;
DELETE FROM persons WHERE person_id = 34971;  -- 陳敬智 主編 → 陳敬智 (33324)
UPDATE IGNORE book_persons SET person_id = 17199 WHERE person_id = 34976;
DELETE FROM persons WHERE person_id = 34976;  -- 莫特雅 著 → 莫特雅 (17199)
UPDATE IGNORE book_persons SET person_id = 48020 WHERE person_id = 34984;
DELETE FROM persons WHERE person_id = 34984;  -- 方敏英 編著 → 方敏英 (48020)
UPDATE IGNORE book_persons SET person_id = 35278 WHERE person_id = 35004;
DELETE FROM persons WHERE person_id = 35004;  -- 彼得.魏格納 編著 → 彼得.魏格納 (35278)
UPDATE IGNORE book_persons SET person_id = 53455 WHERE person_id = 35116;
DELETE FROM persons WHERE person_id = 35116;  -- 凱斯.斯沃特利 編著 → 凱斯.斯沃特利 (53455)
UPDATE IGNORE book_persons SET person_id = 16968 WHERE person_id = 35191;
DELETE FROM persons WHERE person_id = 35191;  -- 陸可鐸 原著 → 陸可鐸 (16968)
UPDATE IGNORE book_persons SET person_id = 21926 WHERE person_id = 35193;
DELETE FROM persons WHERE person_id = 35193;  -- 黃乃寬 著 → 黃乃寬 (21926)
UPDATE IGNORE book_persons SET person_id = 32227 WHERE person_id = 35238;
DELETE FROM persons WHERE person_id = 35238;  -- 賽爾哈默著 → 賽爾哈默 (32227)
UPDATE IGNORE book_persons SET person_id = 16881 WHERE person_id = 35311;
DELETE FROM persons WHERE person_id = 35311;  -- 蔡錦圖 主編 → 蔡錦圖 (16881)
UPDATE IGNORE book_persons SET person_id = 19969 WHERE person_id = 35473;
DELETE FROM persons WHERE person_id = 35473;  -- 李鴻志 著 → 李鴻志 (19969)
UPDATE IGNORE book_persons SET person_id = 21042 WHERE person_id = 35478;
DELETE FROM persons WHERE person_id = 35478;  -- 朴秀雄 著 → 朴秀雄 (21042)
UPDATE IGNORE book_persons SET person_id = 16881 WHERE person_id = 35483;
DELETE FROM persons WHERE person_id = 35483;  -- 蔡錦圖 編著 → 蔡錦圖 (16881)
UPDATE IGNORE book_persons SET person_id = 43591 WHERE person_id = 35489;
DELETE FROM persons WHERE person_id = 35489;  -- 廖明發口述 → 廖明發 (43591)
UPDATE IGNORE book_persons SET person_id = 31908 WHERE person_id = 35502;
DELETE FROM persons WHERE person_id = 35502;  -- 羅少莉著 → 羅少莉 (31908)
UPDATE IGNORE book_persons SET person_id = 21283 WHERE person_id = 35527;
DELETE FROM persons WHERE person_id = 35527;  -- 蘇文隆主編 → 蘇文隆 (21283)
UPDATE IGNORE book_persons SET person_id = 21448 WHERE person_id = 35535;
DELETE FROM persons WHERE person_id = 35535;  -- 劉清彥著 → 劉清彥 (21448)
UPDATE IGNORE book_persons SET person_id = 20713 WHERE person_id = 35609;
DELETE FROM persons WHERE person_id = 35609;  -- 蘇金妹 口述 → 蘇金妹 (20713)
UPDATE IGNORE book_persons SET person_id = 17539 WHERE person_id = 35664;
DELETE FROM persons WHERE person_id = 35664;  -- 法蘭士著 → 法蘭士 (17539)
UPDATE IGNORE book_persons SET person_id = 22898 WHERE person_id = 35737;
DELETE FROM persons WHERE person_id = 35737;  -- 黃葳威主編 → 黃葳威 (22898)
UPDATE IGNORE book_persons SET person_id = 31636 WHERE person_id = 35786;
DELETE FROM persons WHERE person_id = 35786;  -- 彭美秀著 → 彭美秀 (31636)
UPDATE IGNORE book_persons SET person_id = 18078 WHERE person_id = 35844;
DELETE FROM persons WHERE person_id = 35844;  -- 麥葛福著 → 麥葛福 (18078)
UPDATE IGNORE book_persons SET person_id = 35964 WHERE person_id = 35892;
DELETE FROM persons WHERE person_id = 35892;  -- 賴瑞‧葛福偉 著 → 賴瑞‧葛福偉 (35964)
UPDATE IGNORE book_persons SET person_id = 47383 WHERE person_id = 35912;
DELETE FROM persons WHERE person_id = 35912;  -- 夏蓉‧赫胥 著 → 夏蓉‧赫胥 (47383)
UPDATE IGNORE book_persons SET person_id = 44443 WHERE person_id = 35917;
DELETE FROM persons WHERE person_id = 35917;  -- 傑夫‧菲德翰 著 → 傑夫‧菲德翰 (44443)
UPDATE IGNORE book_persons SET person_id = 19767 WHERE person_id = 35931;
DELETE FROM persons WHERE person_id = 35931;  -- 華理克著 → 華理克 (19767)
UPDATE IGNORE book_persons SET person_id = 17227 WHERE person_id = 35934;
DELETE FROM persons WHERE person_id = 35934;  -- 劉志雄 著 → 劉志雄 (17227)
UPDATE IGNORE book_persons SET person_id = 28859 WHERE person_id = 35952;
DELETE FROM persons WHERE person_id = 35952;  -- 賴瑞．福樂 著 → 賴瑞．福樂 (28859)
UPDATE IGNORE book_persons SET person_id = 24904 WHERE person_id = 35954;
DELETE FROM persons WHERE person_id = 35954;  -- 黃國倫 著 → 黃國倫 (24904)
UPDATE IGNORE book_persons SET person_id = 32899 WHERE person_id = 35963;
DELETE FROM persons WHERE person_id = 35963;  -- 浩義．克萊貝爾 著 → 浩義．克萊貝爾 (32899)
UPDATE IGNORE book_persons SET person_id = 17883 WHERE person_id = 35973;
DELETE FROM persons WHERE person_id = 35973;  -- 古倫神父 著 → 古倫神父 (17883)
UPDATE IGNORE book_persons SET person_id = 30283 WHERE person_id = 36017;
DELETE FROM persons WHERE person_id = 36017;  -- 崔子實著 → 崔子實 (30283)
UPDATE IGNORE book_persons SET person_id = 22220 WHERE person_id = 36046;
DELETE FROM persons WHERE person_id = 36046;  -- 本仁約翰著 → 本仁約翰 (22220)
UPDATE IGNORE book_persons SET person_id = 31491 WHERE person_id = 36114;
DELETE FROM persons WHERE person_id = 36114;  -- 鮑伯葛斯著 → 鮑伯葛斯 (31491)
UPDATE IGNORE book_persons SET person_id = 52314 WHERE person_id = 36269;
DELETE FROM persons WHERE person_id = 36269;  -- 朱汪佩錦口述 → 朱汪佩錦 (52314)
UPDATE IGNORE book_persons SET person_id = 16780 WHERE person_id = 36309;
DELETE FROM persons WHERE person_id = 36309;  -- 晏保羅 著 → 晏保羅 (16780)
UPDATE IGNORE book_persons SET person_id = 16758 WHERE person_id = 36311;
DELETE FROM persons WHERE person_id = 36311;  -- 高路易 著 → 高路易 (16758)
UPDATE IGNORE book_persons SET person_id = 16982 WHERE person_id = 36380;
DELETE FROM persons WHERE person_id = 36380;  -- 張永信著 → 張永信 (16982)
UPDATE IGNORE book_persons SET person_id = 17561 WHERE person_id = 36397;
DELETE FROM persons WHERE person_id = 36397;  -- 李斐德著 → 李斐德 (17561)
UPDATE IGNORE book_persons SET person_id = 28682 WHERE person_id = 36399;
DELETE FROM persons WHERE person_id = 36399;  -- 史丹利著 → 史丹利 (28682)
UPDATE IGNORE book_persons SET person_id = 38393 WHERE person_id = 36494;
DELETE FROM persons WHERE person_id = 36494;  -- 單國璽口述 → 單國璽 (38393)
UPDATE IGNORE book_persons SET person_id = 17931 WHERE person_id = 36517;
DELETE FROM persons WHERE person_id = 36517;  -- 黎子鵬 編註 → 黎子鵬 (17931)
UPDATE IGNORE book_persons SET person_id = 36570 WHERE person_id = 36573;
DELETE FROM persons WHERE person_id = 36573;  -- 周嘉慧主編 → 周嘉慧 (36570)
UPDATE IGNORE book_persons SET person_id = 45677 WHERE person_id = 36678;
DELETE FROM persons WHERE person_id = 36678;  -- 陳鄭彥 口述 → 陳鄭彥 (45677)
UPDATE IGNORE book_persons SET person_id = 29758 WHERE person_id = 36698;
DELETE FROM persons WHERE person_id = 36698;  -- 劉大偉 口述 → 劉大偉 (29758)
UPDATE IGNORE book_persons SET person_id = 51051 WHERE person_id = 36820;
DELETE FROM persons WHERE person_id = 36820;  -- 何凱倫著 → 何凱倫 (51051)
UPDATE IGNORE book_persons SET person_id = 19553 WHERE person_id = 36861;
DELETE FROM persons WHERE person_id = 36861;  -- 郭美江口述 → 郭美江 (19553)
UPDATE IGNORE book_persons SET person_id = 18148 WHERE person_id = 36931;
DELETE FROM persons WHERE person_id = 36931;  -- 孫揚光口述 → 孫揚光 (18148)
UPDATE IGNORE book_persons SET person_id = 21899 WHERE person_id = 36945;
DELETE FROM persons WHERE person_id = 36945;  -- 樊鴻台口述 → 樊鴻台 (21899)
UPDATE IGNORE book_persons SET person_id = 18489 WHERE person_id = 37086;
DELETE FROM persons WHERE person_id = 37086;  -- 李耀全主編 → 李耀全 (18489)
UPDATE IGNORE book_persons SET person_id = 18546 WHERE person_id = 37444;
DELETE FROM persons WHERE person_id = 37444;  -- 龔立人主編 → 龔立人 (18546)
UPDATE IGNORE book_persons SET person_id = 31265 WHERE person_id = 37550;
DELETE FROM persons WHERE person_id = 37550;  -- 林國亮著 → 林國亮 (31265)
UPDATE IGNORE book_persons SET person_id = 43255 WHERE person_id = 37596;
DELETE FROM persons WHERE person_id = 37596;  -- 羅文謙博士著 → 羅文謙博士 (43255)
UPDATE IGNORE book_persons SET person_id = 16926 WHERE person_id = 37613;
DELETE FROM persons WHERE person_id = 37613;  -- 范浩沙 等編 → 范浩沙 (16926)
UPDATE IGNORE book_persons SET person_id = 37741 WHERE person_id = 37647;
DELETE FROM persons WHERE person_id = 37647;  -- 漆立平 漆哈拿 著 → 漆立平 漆哈拿 (37741)
UPDATE IGNORE book_persons SET person_id = 16966 WHERE person_id = 37756;
DELETE FROM persons WHERE person_id = 37756;  -- 蔡春曦  編著 → 蔡春曦 (16966)
UPDATE IGNORE book_persons SET person_id = 21021 WHERE person_id = 37789;
DELETE FROM persons WHERE person_id = 37789;  -- 張慧嫈 主編 → 張慧嫈 (21021)
UPDATE IGNORE book_persons SET person_id = 16744 WHERE person_id = 37844;
DELETE FROM persons WHERE person_id = 37844;  -- 喬美倫著 → 喬美倫 (16744)
UPDATE IGNORE book_persons SET person_id = 45974 WHERE person_id = 37885;
DELETE FROM persons WHERE person_id = 37885;  -- 蔡興士主編 → 蔡興士 (45974)
UPDATE IGNORE book_persons SET person_id = 24475 WHERE person_id = 37908;
DELETE FROM persons WHERE person_id = 37908;  -- 黃明鎮主編 → 黃明鎮 (24475)
UPDATE IGNORE book_persons SET person_id = 17152 WHERE person_id = 37915;
DELETE FROM persons WHERE person_id = 37915;  -- 張振華著 → 張振華 (17152)
UPDATE IGNORE book_persons SET person_id = 30445 WHERE person_id = 37983;
DELETE FROM persons WHERE person_id = 37983;  -- 何恭上 主編 → 何恭上 (30445)
UPDATE IGNORE book_persons SET person_id = 51640 WHERE person_id = 38050;
DELETE FROM persons WHERE person_id = 38050;  -- 李文輝  主編 → 李文輝 (51640)
UPDATE IGNORE book_persons SET person_id = 38075 WHERE person_id = 38067;
DELETE FROM persons WHERE person_id = 38067;  -- 溫德 & 賀思德 編著 → 溫德 & 賀思德 (38075)
UPDATE IGNORE book_persons SET person_id = 19001 WHERE person_id = 38163;
DELETE FROM persons WHERE person_id = 38163;  -- 龍蕭念全 主編 → 龍蕭念全 (19001)
UPDATE IGNORE book_persons SET person_id = 37316 WHERE person_id = 38167;
DELETE FROM persons WHERE person_id = 38167;  -- 台北靈糧堂 編著 → 台北靈糧堂 (37316)
UPDATE IGNORE book_persons SET person_id = 20362 WHERE person_id = 38257;
DELETE FROM persons WHERE person_id = 38257;  -- 林注進 口述 → 林注進 (20362)
UPDATE IGNORE book_persons SET person_id = 21005 WHERE person_id = 38283;
DELETE FROM persons WHERE person_id = 38283;  -- 曾敬恩 著 → 曾敬恩 (21005)
UPDATE IGNORE book_persons SET person_id = 52221 WHERE person_id = 38315;
DELETE FROM persons WHERE person_id = 38315;  -- 林慶台 口述 → 林慶台 (52221)
UPDATE IGNORE book_persons SET person_id = 17068 WHERE person_id = 38320;
DELETE FROM persons WHERE person_id = 38320;  -- 程蒙恩 編著 → 程蒙恩 (17068)
UPDATE IGNORE book_persons SET person_id = 27206 WHERE person_id = 38326;
DELETE FROM persons WHERE person_id = 38326;  -- 池昊晉著 → 池昊晉 (27206)
UPDATE IGNORE book_persons SET person_id = 39685 WHERE person_id = 38428;
DELETE FROM persons WHERE person_id = 38428;  -- 趙鏞基牧師著 → 趙鏞基牧師 (39685)
UPDATE IGNORE book_persons SET person_id = 17884 WHERE person_id = 38443;
DELETE FROM persons WHERE person_id = 38443;  -- 吳信如主編 → 吳信如 (17884)
UPDATE IGNORE book_persons SET person_id = 27026 WHERE person_id = 38475;
DELETE FROM persons WHERE person_id = 38475;  -- 蘿樂 編選 → 蘿樂 (27026)
UPDATE IGNORE book_persons SET person_id = 40784 WHERE person_id = 38521;
DELETE FROM persons WHERE person_id = 38521;  -- 李秀玉編著 → 李秀玉 (40784)
UPDATE IGNORE book_persons SET person_id = 23440 WHERE person_id = 38677;
DELETE FROM persons WHERE person_id = 38677;  -- 禧年經濟倫理文教基金會 編著 → 禧年經濟倫理文教基金會 (23440)
UPDATE IGNORE book_persons SET person_id = 46670 WHERE person_id = 38784;
DELETE FROM persons WHERE person_id = 38784;  -- 傑瑞懷特著 → 傑瑞懷特 (46670)
UPDATE IGNORE book_persons SET person_id = 32775 WHERE person_id = 38803;
DELETE FROM persons WHERE person_id = 38803;  -- 何百倫著 → 何百倫 (32775)
UPDATE IGNORE book_persons SET person_id = 30938 WHERE person_id = 38912;
DELETE FROM persons WHERE person_id = 38912;  -- 孫玉芝主編 → 孫玉芝 (30938)
UPDATE IGNORE book_persons SET person_id = 28142 WHERE person_id = 39102;
DELETE FROM persons WHERE person_id = 39102;  -- 張冬淘 編著 → 張冬淘 (28142)
UPDATE IGNORE book_persons SET person_id = 29361 WHERE person_id = 39186;
DELETE FROM persons WHERE person_id = 39186;  -- 黃少芬  著 → 黃少芬 (29361)
UPDATE IGNORE book_persons SET person_id = 36665 WHERE person_id = 39266;
DELETE FROM persons WHERE person_id = 39266;  -- 派翠希亞.聖約翰 著 → 派翠希亞.聖約翰 (36665)
UPDATE IGNORE book_persons SET person_id = 19200 WHERE person_id = 39454;
DELETE FROM persons WHERE person_id = 39454;  -- 周淑屏 編選 → 周淑屏 (19200)
UPDATE IGNORE book_persons SET person_id = 37570 WHERE person_id = 39540;
DELETE FROM persons WHERE person_id = 39540;  -- 唐佑之博士著 → 唐佑之博士 (37570)
UPDATE IGNORE book_persons SET person_id = 16933 WHERE person_id = 39562;
DELETE FROM persons WHERE person_id = 39562;  -- 彭國瑋  主編 → 彭國瑋 (16933)
UPDATE IGNORE book_persons SET person_id = 29142 WHERE person_id = 39573;
DELETE FROM persons WHERE person_id = 39573;  -- 約翰麥斯威爾 編註 → 約翰麥斯威爾 (29142)
UPDATE IGNORE book_persons SET person_id = 23609 WHERE person_id = 39588;
DELETE FROM persons WHERE person_id = 39588;  -- 鄧英善編著 → 鄧英善 (23609)
UPDATE IGNORE book_persons SET person_id = 31010 WHERE person_id = 39756;
DELETE FROM persons WHERE person_id = 39756;  -- Dave Witmer編著 → Dave Witmer (31010)
UPDATE IGNORE book_persons SET person_id = 17266 WHERE person_id = 39777;
DELETE FROM persons WHERE person_id = 39777;  -- 曾裕榮編著 → 曾裕榮 (17266)
UPDATE IGNORE book_persons SET person_id = 39930 WHERE person_id = 39818;
DELETE FROM persons WHERE person_id = 39818;  -- 莫里斯‧史汝樂 Morris Cerullo 著 → 莫里斯‧史汝樂 Morris Cerullo (39930)
UPDATE IGNORE book_persons SET person_id = 22674 WHERE person_id = 39838;
DELETE FROM persons WHERE person_id = 39838;  -- 余秋芝編著 → 余秋芝 (22674)
UPDATE IGNORE book_persons SET person_id = 36862 WHERE person_id = 39842;
DELETE FROM persons WHERE person_id = 39842;  -- 李思聰著 → 李思聰 (36862)
UPDATE IGNORE book_persons SET person_id = 19730 WHERE person_id = 40486;
DELETE FROM persons WHERE person_id = 40486;  -- 張真道口述 → 張真道 (19730)
UPDATE IGNORE book_persons SET person_id = 19731 WHERE person_id = 40487;
DELETE FROM persons WHERE person_id = 40487;  -- 尹可名撰著 → 尹可名 (19731)
UPDATE IGNORE book_persons SET person_id = 16883 WHERE person_id = 40538;
DELETE FROM persons WHERE person_id = 40538;  -- 許宏度 主編 → 許宏度 (16883)
UPDATE IGNORE book_persons SET person_id = 20677 WHERE person_id = 40773;
DELETE FROM persons WHERE person_id = 40773;  -- 蘇文峰 主編 → 蘇文峰 (20677)
UPDATE IGNORE book_persons SET person_id = 40868 WHERE person_id = 41610;
DELETE FROM persons WHERE person_id = 41610;  -- 黃子 著 → 黃子 (40868)
UPDATE IGNORE book_persons SET person_id = 35678 WHERE person_id = 41652;
DELETE FROM persons WHERE person_id = 41652;  -- 馬彼得著 → 馬彼得 (35678)
UPDATE IGNORE book_persons SET person_id = 19252 WHERE person_id = 41691;
DELETE FROM persons WHERE person_id = 41691;  -- 區祥江 著 → 區祥江 (19252)
UPDATE IGNORE book_persons SET person_id = 18257 WHERE person_id = 42029;
DELETE FROM persons WHERE person_id = 42029;  -- 黎曦庭著 → 黎曦庭 (18257)
UPDATE IGNORE book_persons SET person_id = 23668 WHERE person_id = 42231;
DELETE FROM persons WHERE person_id = 42231;  -- C. S. 路易斯 著 → C. S. 路易斯 (23668)
UPDATE IGNORE book_persons SET person_id = 26521 WHERE person_id = 42326;
DELETE FROM persons WHERE person_id = 42326;  -- 葉素珍等人合著 → 葉素珍 (26521)
UPDATE IGNORE book_persons SET person_id = 31450 WHERE person_id = 42437;
DELETE FROM persons WHERE person_id = 42437;  -- 何允聖 著 → 何允聖 (31450)
UPDATE IGNORE book_persons SET person_id = 19429 WHERE person_id = 42453;
DELETE FROM persons WHERE person_id = 42453;  -- 曹敏敬著 → 曹敏敬 (19429)
UPDATE IGNORE book_persons SET person_id = 28843 WHERE person_id = 42458;
DELETE FROM persons WHERE person_id = 42458;  -- 何張沛然著 → 何張沛然 (28843)
UPDATE IGNORE book_persons SET person_id = 19252 WHERE person_id = 42468;
DELETE FROM persons WHERE person_id = 42468;  -- 區祥江主編 → 區祥江 (19252)
UPDATE IGNORE book_persons SET person_id = 40384 WHERE person_id = 42472;
DELETE FROM persons WHERE person_id = 42472;  -- 袁鳳珠 著 → 袁鳳珠 (40384)
UPDATE IGNORE book_persons SET person_id = 24345 WHERE person_id = 42484;
DELETE FROM persons WHERE person_id = 42484;  -- 校園編輯小組 / 編著 → 校園編輯小組 (24345)
UPDATE IGNORE book_persons SET person_id = 40972 WHERE person_id = 42581;
DELETE FROM persons WHERE person_id = 42581;  -- 劉遠見 等合編 → 劉遠見 (40972)
UPDATE IGNORE book_persons SET person_id = 17467 WHERE person_id = 42624;
DELETE FROM persons WHERE person_id = 42624;  -- 蕭壽華著 → 蕭壽華 (17467)
UPDATE IGNORE book_persons SET person_id = 17590 WHERE person_id = 42758;
DELETE FROM persons WHERE person_id = 42758;  -- 馮蔭坤 著 → 馮蔭坤 (17590)
UPDATE IGNORE book_persons SET person_id = 17110 WHERE person_id = 42760;
DELETE FROM persons WHERE person_id = 42760;  -- 鄺炳釗 著 → 鄺炳釗 (17110)
UPDATE IGNORE book_persons SET person_id = 21283 WHERE person_id = 42833;
DELETE FROM persons WHERE person_id = 42833;  -- 蘇文隆編著 → 蘇文隆 (21283)
UPDATE IGNORE book_persons SET person_id = 31399 WHERE person_id = 42875;
DELETE FROM persons WHERE person_id = 42875;  -- 愛德華滋著 → 愛德華滋 (31399)
UPDATE IGNORE book_persons SET person_id = 32806 WHERE person_id = 42879;
DELETE FROM persons WHERE person_id = 42879;  -- 高集樂著 → 高集樂 (32806)
UPDATE IGNORE book_persons SET person_id = 35144 WHERE person_id = 43213;
DELETE FROM persons WHERE person_id = 43213;  -- 蔡宇哲　主編 → 蔡宇哲 (35144)
UPDATE IGNORE book_persons SET person_id = 43554 WHERE person_id = 43567;
DELETE FROM persons WHERE person_id = 43567;  -- 大衛・鮑森牧師 著 → 大衛・鮑森牧師 (43554)
UPDATE IGNORE book_persons SET person_id = 19130 WHERE person_id = 43638;
DELETE FROM persons WHERE person_id = 43638;  -- 蕭克諧 主編 → 蕭克諧 (19130)
UPDATE IGNORE book_persons SET person_id = 44176 WHERE person_id = 44177;
DELETE FROM persons WHERE person_id = 44177;  -- 台灣神學院雙連宣教研究與發展中心編著 → 台灣神學院雙連宣教研究與發展中心 (44176)
UPDATE IGNORE book_persons SET person_id = 19730 WHERE person_id = 44194;
DELETE FROM persons WHERE person_id = 44194;  -- 張真道 口述 → 張真道 (19730)
UPDATE IGNORE book_persons SET person_id = 19731 WHERE person_id = 44195;
DELETE FROM persons WHERE person_id = 44195;  -- 尹可名 撰著 → 尹可名 (19731)
UPDATE IGNORE book_persons SET person_id = 21274 WHERE person_id = 44449;
DELETE FROM persons WHERE person_id = 44449;  -- 黃迺毓著 → 黃迺毓 (21274)
UPDATE IGNORE book_persons SET person_id = 20270 WHERE person_id = 44452;
DELETE FROM persons WHERE person_id = 44452;  -- 廖美惠著 → 廖美惠 (20270)
UPDATE IGNORE book_persons SET person_id = 19126 WHERE person_id = 44503;
DELETE FROM persons WHERE person_id = 44503;  -- 王秀園著 → 王秀園 (19126)
UPDATE IGNORE book_persons SET person_id = 46151 WHERE person_id = 44509;
DELETE FROM persons WHERE person_id = 44509;  -- 鄭又慧著 → 鄭又慧 (46151)
UPDATE IGNORE book_persons SET person_id = 20590 WHERE person_id = 44538;
DELETE FROM persons WHERE person_id = 44538;  -- 金明瑋著 → 金明瑋 (20590)
UPDATE IGNORE book_persons SET person_id = 19271 WHERE person_id = 44539;
DELETE FROM persons WHERE person_id = 44539;  -- 陳韻琳著 → 陳韻琳 (19271)
UPDATE IGNORE book_persons SET person_id = 20486 WHERE person_id = 44540;
DELETE FROM persons WHERE person_id = 44540;  -- 張曉風著 → 張曉風 (20486)
UPDATE IGNORE book_persons SET person_id = 22330 WHERE person_id = 44541;
DELETE FROM persons WHERE person_id = 44541;  -- 白培英著 → 白培英 (22330)
UPDATE IGNORE book_persons SET person_id = 20653 WHERE person_id = 44542;
DELETE FROM persons WHERE person_id = 44542;  -- 馬睿欣著 → 馬睿欣 (20653)
UPDATE IGNORE book_persons SET person_id = 22215 WHERE person_id = 44543;
DELETE FROM persons WHERE person_id = 44543;  -- 依品凡著 → 依品凡 (22215)
UPDATE IGNORE book_persons SET person_id = 52494 WHERE person_id = 44570;
DELETE FROM persons WHERE person_id = 44570;  -- 張育明著 → 張育明 (52494)
UPDATE IGNORE book_persons SET person_id = 20970 WHERE person_id = 44575;
DELETE FROM persons WHERE person_id = 44575;  -- 邵正宏著 → 邵正宏 (20970)
UPDATE IGNORE book_persons SET person_id = 31740 WHERE person_id = 44580;
DELETE FROM persons WHERE person_id = 44580;  -- Hal Urban著 → Hal Urban (31740)
UPDATE IGNORE book_persons SET person_id = 22214 WHERE person_id = 44635;
DELETE FROM persons WHERE person_id = 44635;  -- 德國合一弟兄會編著 → 德國合一弟兄會 (22214)
UPDATE IGNORE book_persons SET person_id = 17994 WHERE person_id = 44666;
DELETE FROM persons WHERE person_id = 44666;  -- 范義雄著 → 范義雄 (17994)
UPDATE IGNORE book_persons SET person_id = 34448 WHERE person_id = 44743;
DELETE FROM persons WHERE person_id = 44743;  -- 彭蒙惠口述 → 彭蒙惠 (34448)
UPDATE IGNORE book_persons SET person_id = 19791 WHERE person_id = 44745;
DELETE FROM persons WHERE person_id = 44745;  -- 曹永杉著 → 曹永杉 (19791)
UPDATE IGNORE book_persons SET person_id = 18861 WHERE person_id = 44777;
DELETE FROM persons WHERE person_id = 44777;  -- 林榮樹主編 → 林榮樹 (18861)
UPDATE IGNORE book_persons SET person_id = 22874 WHERE person_id = 44780;
DELETE FROM persons WHERE person_id = 44780;  -- 宋軍主編 → 宋軍 (22874)
UPDATE IGNORE book_persons SET person_id = 23174 WHERE person_id = 44781;
DELETE FROM persons WHERE person_id = 44781;  -- 劉義章主編 → 劉義章 (23174)
UPDATE IGNORE book_persons SET person_id = 18427 WHERE person_id = 44798;
DELETE FROM persons WHERE person_id = 44798;  -- 黃小石著 → 黃小石 (18427)
UPDATE IGNORE book_persons SET person_id = 18529 WHERE person_id = 44799;
DELETE FROM persons WHERE person_id = 44799;  -- 曾慶豹著 → 曾慶豹 (18529)
UPDATE IGNORE book_persons SET person_id = 20946 WHERE person_id = 44816;
DELETE FROM persons WHERE person_id = 44816;  -- 陳映瞳著 → 陳映瞳 (20946)
UPDATE IGNORE book_persons SET person_id = 43407 WHERE person_id = 44818;
DELETE FROM persons WHERE person_id = 44818;  -- 阮耀啟主編 → 阮耀啟 (43407)
UPDATE IGNORE book_persons SET person_id = 32259 WHERE person_id = 44820;
DELETE FROM persons WHERE person_id = 44820;  -- 葉敬德主編 → 葉敬德 (32259)
UPDATE IGNORE book_persons SET person_id = 20599 WHERE person_id = 44821;
DELETE FROM persons WHERE person_id = 44821;  -- 金幼竹著 → 金幼竹 (20599)
UPDATE IGNORE book_persons SET person_id = 18908 WHERE person_id = 44828;
DELETE FROM persons WHERE person_id = 44828;  -- 王成勉主編 → 王成勉 (18908)
UPDATE IGNORE book_persons SET person_id = 45888 WHERE person_id = 44829;
DELETE FROM persons WHERE person_id = 44829;  -- 影子長策會 編著 → 影子長策會 (45888)
UPDATE IGNORE book_persons SET person_id = 20591 WHERE person_id = 44830;
DELETE FROM persons WHERE person_id = 44830;  -- 黃堅厚著 → 黃堅厚 (20591)
UPDATE IGNORE book_persons SET person_id = 18472 WHERE person_id = 44842;
DELETE FROM persons WHERE person_id = 44842;  -- 李志剛著 → 李志剛 (18472)
UPDATE IGNORE book_persons SET person_id = 32732 WHERE person_id = 44843;
DELETE FROM persons WHERE person_id = 44843;  -- 黃昭弘著 → 黃昭弘 (32732)
UPDATE IGNORE book_persons SET person_id = 18908 WHERE person_id = 44844;
DELETE FROM persons WHERE person_id = 44844;  -- 王成勉著 → 王成勉 (18908)
UPDATE IGNORE book_persons SET person_id = 21448 WHERE person_id = 44925;
DELETE FROM persons WHERE person_id = 44925;  -- 劉清彥編著 → 劉清彥 (21448)
UPDATE IGNORE book_persons SET person_id = 19646 WHERE person_id = 45068;
DELETE FROM persons WHERE person_id = 45068;  -- 姚西伊著 → 姚西伊 (19646)
UPDATE IGNORE book_persons SET person_id = 24015 WHERE person_id = 45077;
DELETE FROM persons WHERE person_id = 45077;  -- 譚靜芝編著 → 譚靜芝 (24015)
UPDATE IGNORE book_persons SET person_id = 27708 WHERE person_id = 45079;
DELETE FROM persons WHERE person_id = 45079;  -- 薛天棟著 → 薛天棟 (27708)
UPDATE IGNORE book_persons SET person_id = 31185 WHERE person_id = 45082;
DELETE FROM persons WHERE person_id = 45082;  -- 李碧如著 → 李碧如 (31185)
UPDATE IGNORE book_persons SET person_id = 30619 WHERE person_id = 45083;
DELETE FROM persons WHERE person_id = 45083;  -- 馬革順著 → 馬革順 (30619)
UPDATE IGNORE book_persons SET person_id = 18561 WHERE person_id = 45086;
DELETE FROM persons WHERE person_id = 45086;  -- 吳國安著 → 吳國安 (18561)
UPDATE IGNORE book_persons SET person_id = 18689 WHERE person_id = 45087;
DELETE FROM persons WHERE person_id = 45087;  -- 邢福增著 → 邢福增 (18689)
UPDATE IGNORE book_persons SET person_id = 17312 WHERE person_id = 45088;
DELETE FROM persons WHERE person_id = 45088;  -- 梁家麟編著 → 梁家麟 (17312)
UPDATE IGNORE book_persons SET person_id = 22871 WHERE person_id = 45089;
DELETE FROM persons WHERE person_id = 45089;  -- 黃彩蓮著 → 黃彩蓮 (22871)
UPDATE IGNORE book_persons SET person_id = 21359 WHERE person_id = 45092;
DELETE FROM persons WHERE person_id = 45092;  -- 梁永善著 → 梁永善 (21359)
UPDATE IGNORE book_persons SET person_id = 19929 WHERE person_id = 45093;
DELETE FROM persons WHERE person_id = 45093;  -- 楊克勤著 → 楊克勤 (19929)
UPDATE IGNORE book_persons SET person_id = 30751 WHERE person_id = 45094;
DELETE FROM persons WHERE person_id = 45094;  -- 李衛銘著 → 李衛銘 (30751)
UPDATE IGNORE book_persons SET person_id = 18795 WHERE person_id = 45097;
DELETE FROM persons WHERE person_id = 45097;  -- 黎本正著 → 黎本正 (18795)
UPDATE IGNORE book_persons SET person_id = 17357 WHERE person_id = 45098;
DELETE FROM persons WHERE person_id = 45098;  -- 曾立華著 → 曾立華 (17357)
UPDATE IGNORE book_persons SET person_id = 20177 WHERE person_id = 45099;
DELETE FROM persons WHERE person_id = 45099;  -- 張慕皚著 → 張慕皚 (20177)
UPDATE IGNORE book_persons SET person_id = 24525 WHERE person_id = 45100;
DELETE FROM persons WHERE person_id = 45100;  -- 鄞穎翹著 → 鄞穎翹 (24525)
UPDATE IGNORE book_persons SET person_id = 16787 WHERE person_id = 45103;
DELETE FROM persons WHERE person_id = 45103;  -- 袁海生著 → 袁海生 (16787)
UPDATE IGNORE book_persons SET person_id = 28157 WHERE person_id = 45120;
DELETE FROM persons WHERE person_id = 45120;  -- 舒仁度著 → 舒仁度 (28157)
UPDATE IGNORE book_persons SET person_id = 28177 WHERE person_id = 45121;
DELETE FROM persons WHERE person_id = 45121;  -- 腓德著 → 腓德 (28177)
UPDATE IGNORE book_persons SET person_id = 28155 WHERE person_id = 45122;
DELETE FROM persons WHERE person_id = 45122;  -- 戴存義夫婦著 → 戴存義夫婦 (28155)
UPDATE IGNORE book_persons SET person_id = 29423 WHERE person_id = 45123;
DELETE FROM persons WHERE person_id = 45123;  -- 王連俊著 → 王連俊 (29423)
UPDATE IGNORE book_persons SET person_id = 33529 WHERE person_id = 45124;
DELETE FROM persons WHERE person_id = 45124;  -- 福拉西狄著 → 福拉西狄 (33529)
UPDATE IGNORE book_persons SET person_id = 24467 WHERE person_id = 45125;
DELETE FROM persons WHERE person_id = 45125;  -- 小德蘭著 → 小德蘭 (24467)
UPDATE IGNORE book_persons SET person_id = 20912 WHERE person_id = 45131;
DELETE FROM persons WHERE person_id = 45131;  -- 甘雅各著 → 甘雅各 (20912)
UPDATE IGNORE book_persons SET person_id = 33403 WHERE person_id = 45132;
DELETE FROM persons WHERE person_id = 45132;  -- 顏路裔著 → 顏路裔 (33403)
UPDATE IGNORE book_persons SET person_id = 28483 WHERE person_id = 45134;
DELETE FROM persons WHERE person_id = 45134;  -- 王廖愛梅著 → 王廖愛梅 (28483)
UPDATE IGNORE book_persons SET person_id = 28591 WHERE person_id = 45146;
DELETE FROM persons WHERE person_id = 45146;  -- 董淑貞著 → 董淑貞 (28591)
UPDATE IGNORE book_persons SET person_id = 28512 WHERE person_id = 45147;
DELETE FROM persons WHERE person_id = 45147;  -- 陳宇鋒著 → 陳宇鋒 (28512)
UPDATE IGNORE book_persons SET person_id = 27485 WHERE person_id = 45342;
DELETE FROM persons WHERE person_id = 45342;  -- 杏林子著 → 杏林子 (27485)
UPDATE IGNORE book_persons SET person_id = 30535 WHERE person_id = 45353;
DELETE FROM persons WHERE person_id = 45353;  -- 陳紫蘭著 → 陳紫蘭 (30535)
UPDATE IGNORE book_persons SET person_id = 52288 WHERE person_id = 45356;
DELETE FROM persons WHERE person_id = 45356;  -- 黃安倫著 → 黃安倫 (52288)
UPDATE IGNORE book_persons SET person_id = 17531 WHERE person_id = 45358;
DELETE FROM persons WHERE person_id = 45358;  -- 管瑪蓮著 → 管瑪蓮 (17531)
UPDATE IGNORE book_persons SET person_id = 32909 WHERE person_id = 45362;
DELETE FROM persons WHERE person_id = 45362;  -- 賴爾夫著 → 賴爾夫 (32909)
UPDATE IGNORE book_persons SET person_id = 31427 WHERE person_id = 45364;
DELETE FROM persons WHERE person_id = 45364;  -- 羅腓力著 → 羅腓力 (31427)
UPDATE IGNORE book_persons SET person_id = 22415 WHERE person_id = 45424;
DELETE FROM persons WHERE person_id = 45424;  -- 麥陳永萱著 → 麥陳永萱 (22415)
UPDATE IGNORE book_persons SET person_id = 25346 WHERE person_id = 45481;
DELETE FROM persons WHERE person_id = 45481;  -- 福音橋編輯委員會編著 → 福音橋編輯委員會 (25346)
UPDATE IGNORE book_persons SET person_id = 26493 WHERE person_id = 45655;
DELETE FROM persons WHERE person_id = 45655;  -- 李向平主編 → 李向平 (26493)
UPDATE IGNORE book_persons SET person_id = 20715 WHERE person_id = 45662;
DELETE FROM persons WHERE person_id = 45662;  -- 李繼吾口述 → 李繼吾 (20715)
UPDATE IGNORE book_persons SET person_id = 18131 WHERE person_id = 45766;
DELETE FROM persons WHERE person_id = 45766;  -- 宣信著 → 宣信 (18131)
UPDATE IGNORE book_persons SET person_id = 28449 WHERE person_id = 45767;
DELETE FROM persons WHERE person_id = 45767;  -- 湯普信著 → 湯普信 (28449)
UPDATE IGNORE book_persons SET person_id = 24396 WHERE person_id = 45827;
DELETE FROM persons WHERE person_id = 45827;  -- 劉永明著 → 劉永明 (24396)
UPDATE IGNORE book_persons SET person_id = 28343 WHERE person_id = 45828;
DELETE FROM persons WHERE person_id = 45828;  -- 莊傑夫著 → 莊傑夫 (28343)
UPDATE IGNORE book_persons SET person_id = 45857 WHERE person_id = 45837;
DELETE FROM persons WHERE person_id = 45837;  -- 劉利未主編 → 劉利未 (45857)
UPDATE IGNORE book_persons SET person_id = 16745 WHERE person_id = 45850;
DELETE FROM persons WHERE person_id = 45850;  -- 高銘謙主編 → 高銘謙 (16745)
UPDATE IGNORE book_persons SET person_id = 30763 WHERE person_id = 45954;
DELETE FROM persons WHERE person_id = 45954;  -- 楊紹唐著 → 楊紹唐 (30763)
UPDATE IGNORE book_persons SET person_id = 28344 WHERE person_id = 46280;
DELETE FROM persons WHERE person_id = 46280;  -- 劉小楓編選 → 劉小楓 (28344)
UPDATE IGNORE book_persons SET person_id = 20190 WHERE person_id = 46307;
DELETE FROM persons WHERE person_id = 46307;  -- 劉承業著 → 劉承業 (20190)
UPDATE IGNORE book_persons SET person_id = 24386 WHERE person_id = 46316;
DELETE FROM persons WHERE person_id = 46316;  -- 廖炳堂主編 → 廖炳堂 (24386)
UPDATE IGNORE book_persons SET person_id = 17312 WHERE person_id = 46317;
DELETE FROM persons WHERE person_id = 46317;  -- 梁家麟主編 → 梁家麟 (17312)
UPDATE IGNORE book_persons SET person_id = 46319 WHERE person_id = 46320;
DELETE FROM persons WHERE person_id = 46320;  -- 陳姿華主編 → 陳姿華 (46319)
UPDATE IGNORE book_persons SET person_id = 17264 WHERE person_id = 46325;
DELETE FROM persons WHERE person_id = 46325;  -- 謝品然著 → 謝品然 (17264)
UPDATE IGNORE book_persons SET person_id = 20892 WHERE person_id = 46345;
DELETE FROM persons WHERE person_id = 46345;  -- 顧百利著 → 顧百利 (20892)
UPDATE IGNORE book_persons SET person_id = 29282 WHERE person_id = 46348;
DELETE FROM persons WHERE person_id = 46348;  -- 寧心著 → 寧心 (29282)
UPDATE IGNORE book_persons SET person_id = 17345 WHERE person_id = 46352;
DELETE FROM persons WHERE person_id = 46352;  -- 楊世禮著 → 楊世禮 (17345)
UPDATE IGNORE book_persons SET person_id = 29967 WHERE person_id = 46366;
DELETE FROM persons WHERE person_id = 46366;  -- 吳恩溥著 → 吳恩溥 (29967)
UPDATE IGNORE book_persons SET person_id = 20900 WHERE person_id = 46430;
DELETE FROM persons WHERE person_id = 46430;  -- 祈安主編 → 祈安 (20900)
UPDATE IGNORE book_persons SET person_id = 30050 WHERE person_id = 46682;
DELETE FROM persons WHERE person_id = 46682;  -- 胡國楨主編 → 胡國楨 (30050)
UPDATE IGNORE book_persons SET person_id = 17068 WHERE person_id = 46694;
DELETE FROM persons WHERE person_id = 46694;  -- 程蒙恩編著 → 程蒙恩 (17068)
UPDATE IGNORE book_persons SET person_id = 18313 WHERE person_id = 46707;
DELETE FROM persons WHERE person_id = 46707;  -- 陳俊偉主編 → 陳俊偉 (18313)
UPDATE IGNORE book_persons SET person_id = 18527 WHERE person_id = 46905;
DELETE FROM persons WHERE person_id = 46905;  -- 鄧瑞強等編 → 鄧瑞強 (18527)
UPDATE IGNORE book_persons SET person_id = 28320 WHERE person_id = 46918;
DELETE FROM persons WHERE person_id = 46918;  -- 齊德芳主編 → 齊德芳 (28320)
UPDATE IGNORE book_persons SET person_id = 34627 WHERE person_id = 46929;
DELETE FROM persons WHERE person_id = 46929;  -- 陳攸華口述 → 陳攸華 (34627)
UPDATE IGNORE book_persons SET person_id = 21333 WHERE person_id = 47193;
DELETE FROM persons WHERE person_id = 47193;  -- 陳嘉璐著 → 陳嘉璐 (21333)
UPDATE IGNORE book_persons SET person_id = 29603 WHERE person_id = 47195;
DELETE FROM persons WHERE person_id = 47195;  -- 叨雷著 → 叨雷 (29603)
UPDATE IGNORE book_persons SET person_id = 17702 WHERE person_id = 47221;
DELETE FROM persons WHERE person_id = 47221;  -- 周聯華主編 → 周聯華 (17702)
UPDATE IGNORE book_persons SET person_id = 17781 WHERE person_id = 47307;
DELETE FROM persons WHERE person_id = 47307;  -- 陳潤棠 著 → 陳潤棠 (17781)
UPDATE IGNORE book_persons SET person_id = 17285 WHERE person_id = 47531;
DELETE FROM persons WHERE person_id = 47531;  -- 李鴻標編著 → 李鴻標 (17285)
UPDATE IGNORE book_persons SET person_id = 22347 WHERE person_id = 47587;
DELETE FROM persons WHERE person_id = 47587;  -- 陳耀南編著 → 陳耀南 (22347)
UPDATE IGNORE book_persons SET person_id = 38393 WHERE person_id = 47717;
DELETE FROM persons WHERE person_id = 47717;  -- 單國璽 口述 → 單國璽 (38393)
UPDATE IGNORE book_persons SET person_id = 25909 WHERE person_id = 47720;
DELETE FROM persons WHERE person_id = 47720;  -- 林國璋編著 → 林國璋 (25909)
UPDATE IGNORE book_persons SET person_id = 32552 WHERE person_id = 47721;
DELETE FROM persons WHERE person_id = 47721;  -- 黃幗坤主編 → 黃幗坤 (32552)
UPDATE IGNORE book_persons SET person_id = 50985 WHERE person_id = 47728;
DELETE FROM persons WHERE person_id = 47728;  -- 尼萊艾斯著 → 尼萊艾斯 (50985)
UPDATE IGNORE book_persons SET person_id = 23758 WHERE person_id = 47729;
DELETE FROM persons WHERE person_id = 47729;  -- 初信靈修系列編輯組著 → 初信靈修系列編輯組 (23758)
UPDATE IGNORE book_persons SET person_id = 39310 WHERE person_id = 47782;
DELETE FROM persons WHERE person_id = 47782;  -- GEORGE BARNA編著 → GEORGE BARNA (39310)
UPDATE IGNORE book_persons SET person_id = 17803 WHERE person_id = 47810;
DELETE FROM persons WHERE person_id = 47810;  -- 王明道 編著 → 王明道 (17803)
UPDATE IGNORE book_persons SET person_id = 32061 WHERE person_id = 47820;
DELETE FROM persons WHERE person_id = 47820;  -- 孟惠霖原著 → 孟惠霖 (32061)
UPDATE IGNORE book_persons SET person_id = 20470 WHERE person_id = 47865;
DELETE FROM persons WHERE person_id = 47865;  -- 郭忠吉編著 → 郭忠吉 (20470)
UPDATE IGNORE book_persons SET person_id = 24318 WHERE person_id = 47876;
DELETE FROM persons WHERE person_id = 47876;  -- 戴德正等編 → 戴德正 (24318)
UPDATE IGNORE book_persons SET person_id = 31797 WHERE person_id = 47941;
DELETE FROM persons WHERE person_id = 47941;  -- 翁傳鏗主編 → 翁傳鏗 (31797)
UPDATE IGNORE book_persons SET person_id = 33290 WHERE person_id = 48234;
DELETE FROM persons WHERE person_id = 48234;  -- 邱善雄主編 → 邱善雄 (33290)
UPDATE IGNORE book_persons SET person_id = 24708 WHERE person_id = 48281;
DELETE FROM persons WHERE person_id = 48281;  -- 曾如芳著 → 曾如芳 (24708)
UPDATE IGNORE book_persons SET person_id = 28528 WHERE person_id = 48313;
DELETE FROM persons WHERE person_id = 48313;  -- 張秋生 原著 → 張秋生 (28528)
UPDATE IGNORE book_persons SET person_id = 24402 WHERE person_id = 48419;
DELETE FROM persons WHERE person_id = 48419;  -- 賴品超主編 → 賴品超 (24402)
UPDATE IGNORE book_persons SET person_id = 27404 WHERE person_id = 48446;
DELETE FROM persons WHERE person_id = 48446;  -- 雷亞蘭著 → 雷亞蘭 (27404)
UPDATE IGNORE book_persons SET person_id = 16977 WHERE person_id = 48466;
DELETE FROM persons WHERE person_id = 48466;  -- 李佳民著 → 李佳民 (16977)
UPDATE IGNORE book_persons SET person_id = 19112 WHERE person_id = 48536;
DELETE FROM persons WHERE person_id = 48536;  -- 韋約翰著 → 韋約翰 (19112)
UPDATE IGNORE book_persons SET person_id = 17903 WHERE person_id = 48630;
DELETE FROM persons WHERE person_id = 48630;  -- 胡武傑 主編 → 胡武傑 (17903)
UPDATE IGNORE book_persons SET person_id = 17898 WHERE person_id = 48635;
DELETE FROM persons WHERE person_id = 48635;  -- 梁國全 主編 → 梁國全 (17898)
UPDATE IGNORE book_persons SET person_id = 28790 WHERE person_id = 48941;
DELETE FROM persons WHERE person_id = 48941;  -- 麥家輝編著 → 麥家輝 (28790)
UPDATE IGNORE book_persons SET person_id = 18380 WHERE person_id = 49182;
DELETE FROM persons WHERE person_id = 49182;  -- 根頓 主編 → 根頓 (18380)
UPDATE IGNORE book_persons SET person_id = 19646 WHERE person_id = 49184;
DELETE FROM persons WHERE person_id = 49184;  -- 姚西伊主編 → 姚西伊 (19646)
UPDATE IGNORE book_persons SET person_id = 49212 WHERE person_id = 49213;
DELETE FROM persons WHERE person_id = 49213;  -- 楊耀威 主編 → 楊耀威 (49212)
UPDATE IGNORE book_persons SET person_id = 40964 WHERE person_id = 49770;
DELETE FROM persons WHERE person_id = 49770;  -- 李安琴 譯者 → 李安琴 (40964)
UPDATE IGNORE book_persons SET person_id = 17639 WHERE person_id = 49773;
DELETE FROM persons WHERE person_id = 49773;  -- 彼得．郭爾迪主編 → 彼得．郭爾迪 (17639)
UPDATE IGNORE book_persons SET person_id = 16966 WHERE person_id = 49848;
DELETE FROM persons WHERE person_id = 49848;  -- 蔡春曦 主編 → 蔡春曦 (16966)
UPDATE IGNORE book_persons SET person_id = 41186 WHERE person_id = 51278;
DELETE FROM persons WHERE person_id = 51278;  -- 王愛君 著 → 王愛君 (41186)
UPDATE IGNORE book_persons SET person_id = 27335 WHERE person_id = 51714;
DELETE FROM persons WHERE person_id = 51714;  -- 香港差傳事工聯會 編著 → 香港差傳事工聯會 (27335)
UPDATE IGNORE book_persons SET person_id = 16989 WHERE person_id = 51741;
DELETE FROM persons WHERE person_id = 51741;  -- 王礽福 主編 → 王礽福 (16989)
UPDATE IGNORE book_persons SET person_id = 30050 WHERE person_id = 51934;
DELETE FROM persons WHERE person_id = 51934;  -- 胡國楨 主編 → 胡國楨 (30050)
UPDATE IGNORE book_persons SET person_id = 34448 WHERE person_id = 52259;
DELETE FROM persons WHERE person_id = 52259;  -- 彭蒙惠 口述 → 彭蒙惠 (34448)
UPDATE IGNORE book_persons SET person_id = 26960 WHERE person_id = 52263;
DELETE FROM persons WHERE person_id = 52263;  -- 余妙雲編著 → 余妙雲 (26960)
UPDATE IGNORE book_persons SET person_id = 24599 WHERE person_id = 52497;
DELETE FROM persons WHERE person_id = 52497;  -- 朱裕文主編 → 朱裕文 (24599)
UPDATE IGNORE book_persons SET person_id = 18687 WHERE person_id = 52498;
DELETE FROM persons WHERE person_id = 52498;  -- 李錦綸 著 → 李錦綸 (18687)
UPDATE IGNORE book_persons SET person_id = 25765 WHERE person_id = 52558;
DELETE FROM persons WHERE person_id = 52558;  -- 徐玉琼著 → 徐玉琼 (25765)
UPDATE IGNORE book_persons SET person_id = 19200 WHERE person_id = 52559;
DELETE FROM persons WHERE person_id = 52559;  -- 周淑屏著 → 周淑屏 (19200)
UPDATE IGNORE book_persons SET person_id = 22242 WHERE person_id = 52561;
DELETE FROM persons WHERE person_id = 52561;  -- 阿濃著 → 阿濃 (22242)
UPDATE IGNORE book_persons SET person_id = 22241 WHERE person_id = 52569;
DELETE FROM persons WHERE person_id = 52569;  -- 關麗珊著 → 關麗珊 (22241)
UPDATE IGNORE book_persons SET person_id = 19200 WHERE person_id = 52570;
DELETE FROM persons WHERE person_id = 52570;  -- 周淑屏編著 → 周淑屏 (19200)
UPDATE IGNORE book_persons SET person_id = 19200 WHERE person_id = 52572;
DELETE FROM persons WHERE person_id = 52572;  -- 周淑屏編選 → 周淑屏 (19200)
UPDATE IGNORE book_persons SET person_id = 21308 WHERE person_id = 52573;
DELETE FROM persons WHERE person_id = 52573;  -- 上官賢恩編著 → 上官賢恩 (21308)
UPDATE IGNORE book_persons SET person_id = 52610 WHERE person_id = 52590;
DELETE FROM persons WHERE person_id = 52590;  -- 羅素‧史丹勒著 → 羅素‧史丹勒 (52610)
UPDATE IGNORE book_persons SET person_id = 19891 WHERE person_id = 52604;
DELETE FROM persons WHERE person_id = 52604;  -- 蔡元雲著 → 蔡元雲 (19891)
UPDATE IGNORE book_persons SET person_id = 22292 WHERE person_id = 52605;
DELETE FROM persons WHERE person_id = 52605;  -- 畢華流著 → 畢華流 (22292)
UPDATE IGNORE book_persons SET person_id = 20248 WHERE person_id = 52609;
DELETE FROM persons WHERE person_id = 52609;  -- 梁永泰著 → 梁永泰 (20248)
UPDATE IGNORE book_persons SET person_id = 53677 WHERE person_id = 53109;
DELETE FROM persons WHERE person_id = 53109;  -- 祝希虔,麥希真編著 → 祝希虔,麥希真 (53677)
UPDATE IGNORE book_persons SET person_id = 25740 WHERE person_id = 53272;
DELETE FROM persons WHERE person_id = 53272;  -- 高莘著 → 高莘 (25740)
UPDATE IGNORE book_persons SET person_id = 26781 WHERE person_id = 53283;
DELETE FROM persons WHERE person_id = 53283;  -- 王永信 主編 → 王永信 (26781)
UPDATE IGNORE book_persons SET person_id = 53318 WHERE person_id = 53317;
DELETE FROM persons WHERE person_id = 53317;  -- 黃克鑣, 盧德/主編 → 黃克鑣, 盧德 (53318)
UPDATE IGNORE book_persons SET person_id = 53429 WHERE person_id = 53433;
DELETE FROM persons WHERE person_id = 53433;  -- 簡楚瑛主編 → 簡楚瑛 (53429)
UPDATE IGNORE book_persons SET person_id = 37568 WHERE person_id = 53457;
DELETE FROM persons WHERE person_id = 53457;  -- 特里.史密斯.安德森主編 → 特里.史密斯.安德森 (37568)
UPDATE IGNORE book_persons SET person_id = 19201 WHERE person_id = 53485;
DELETE FROM persons WHERE person_id = 53485;  -- 陳佐才 主編 → 陳佐才 (19201)
UPDATE IGNORE book_persons SET person_id = 17265 WHERE person_id = 18136;
DELETE FROM persons WHERE person_id = 18136;  -- 主編：陳廷忠 → 陳廷忠 (17265)
UPDATE IGNORE book_persons SET person_id = 24402 WHERE person_id = 18623;
DELETE FROM persons WHERE person_id = 18623;  -- 主編:賴品超 → 賴品超 (24402)
UPDATE IGNORE book_persons SET person_id = 17265 WHERE person_id = 18637;
DELETE FROM persons WHERE person_id = 18637;  -- 主編:陳廷忠 → 陳廷忠 (17265)
UPDATE IGNORE book_persons SET person_id = 22402 WHERE person_id = 21003;
DELETE FROM persons WHERE person_id = 21003;  -- 編撰：林培松 → 林培松 (22402)
UPDATE IGNORE book_persons SET person_id = 21433 WHERE person_id = 21511;
DELETE FROM persons WHERE person_id = 21511;  -- 文\圖:陳嘉鈴 → 陳嘉鈴 (21433)
UPDATE IGNORE book_persons SET person_id = 21485 WHERE person_id = 21512;
DELETE FROM persons WHERE person_id = 21512;  -- 文\圖:盧恩鈴 → 盧恩鈴 (21485)
UPDATE IGNORE book_persons SET person_id = 19119 WHERE person_id = 26583;
DELETE FROM persons WHERE person_id = 26583;  -- 主編：吳思源 → 吳思源 (19119)
UPDATE IGNORE book_persons SET person_id = 19662 WHERE person_id = 29311;
DELETE FROM persons WHERE person_id = 29311;  -- 主編：林慈信 → 林慈信 (19662)
UPDATE IGNORE book_persons SET person_id = 19977 WHERE person_id = 34005;
DELETE FROM persons WHERE person_id = 34005;  -- 主編：伍國榮 → 伍國榮 (19977)
UPDATE IGNORE book_persons SET person_id = 21159 WHERE person_id = 34690;
DELETE FROM persons WHERE person_id = 34690;  -- 總編輯：郝萬以嘉 → 郝萬以嘉 (21159)
UPDATE IGNORE book_persons SET person_id = 21486 WHERE person_id = 38240;
DELETE FROM persons WHERE person_id = 38240;  -- 繪圖：蔡兆倫 → 蔡兆倫 (21486)
UPDATE IGNORE book_persons SET person_id = 48197 WHERE person_id = 38489;
DELETE FROM persons WHERE person_id = 38489;  -- 繪圖：梁翠萍 → 梁翠萍 (48197)
UPDATE IGNORE book_persons SET person_id = 25362 WHERE person_id = 43406;
DELETE FROM persons WHERE person_id = 43406;  -- 主編：邱林川 → 邱林川 (25362)
UPDATE IGNORE book_persons SET person_id = 33523 WHERE person_id = 43495;
DELETE FROM persons WHERE person_id = 43495;  -- 譯者：劉如菁 → 劉如菁 (33523)
UPDATE IGNORE book_persons SET person_id = 19517 WHERE person_id = 48418;
DELETE FROM persons WHERE person_id = 48418;  -- 主編:王家輝 → 王家輝 (19517)
UPDATE IGNORE book_persons SET person_id = 48893 WHERE person_id = 48942;
DELETE FROM persons WHERE person_id = 48942;  -- 主編：貝內爾（David Benner） → 貝內爾（David Benner） (48893)
UPDATE IGNORE book_persons SET person_id = 34061 WHERE person_id = 53198;
DELETE FROM persons WHERE person_id = 53198;  -- 編輯: 黃婉蓮 → 黃婉蓮 (34061)
UPDATE IGNORE book_persons SET person_id = 16979 WHERE person_id = 22711;
DELETE FROM persons WHERE person_id = 22711;  -- 古普塔 主編 → 古普塔 (16979)
UPDATE IGNORE book_persons SET person_id = 19689 WHERE person_id = 27684;
DELETE FROM persons WHERE person_id = 27684;  -- 維也納總主教舒安邦樞機 主編 → 維也納總主教舒安邦樞機 (19689)
UPDATE IGNORE book_persons SET person_id = 25771 WHERE person_id = 45091;
DELETE FROM persons WHERE person_id = 45091;  -- 建道神學院跨越文化研究系主編 → 建道神學院跨越文化研究系 (25771)
UPDATE IGNORE book_persons SET person_id = 25775 WHERE person_id = 40621;
DELETE FROM persons WHERE person_id = 40621;  -- 何述群 主編 → 何述群 (25775)
UPDATE IGNORE book_persons SET person_id = 28337 WHERE person_id = 29653;
DELETE FROM persons WHERE person_id = 29653;  -- 陳明斌 編著 → 陳明斌 (28337)
UPDATE IGNORE book_persons SET person_id = 28692 WHERE person_id = 46912;
DELETE FROM persons WHERE person_id = 46912;  -- 羅恩．克盧格主編 → 羅恩．克盧格 (28692)
UPDATE IGNORE book_persons SET person_id = 32787 WHERE person_id = 51364;
DELETE FROM persons WHERE person_id = 51364;  -- 郭桂明主編 → 郭桂明 (32787)

-- ═══ 回查(COMMIT 之前跑,**跟這個檔在同一個查詢視窗**) ═══
SELECT (SELECT COUNT(*) FROM persons)      AS persons_total,
       (SELECT COUNT(*) FROM book_persons) AS book_persons_total;
