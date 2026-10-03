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

START TRANSACTION;

-- ═══ A0. 前置檢查(★ 跑 A 之前必跑,**應回 0 列**) ═══
-- 刪掉角色詞那幾列會 CASCADE 掉它們的 book_persons。
-- 這句找出「刪完之後就一個人都不剩」的書 —— 有列回來就表示那本書的
-- 真正作者從來沒被建進去,直接刪會讓那本書變成無作者。先處理那幾本再回來。
-- (本次沒有角色詞列)

-- ═══ A. 整列不是人(角色詞 / 填充詞):0 列 ═══

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

-- ═══ C. 剝乾淨但沒有既有正規列,直接改名:337 列 ═══
UPDATE persons SET name = '曾慶豹.謝品彰' WHERE person_id = 16891;  -- 原「曾慶豹.謝品彰 主編」
UPDATE persons SET name = '巫海丁' WHERE person_id = 16900;  -- 原「巫海丁著」
UPDATE persons SET name = '古普塔' WHERE person_id = 16979;  -- 原「古普塔主編」
UPDATE persons SET name = '西瓦' WHERE person_id = 17018;  -- 原「西瓦主編」
UPDATE persons SET name = '羅慶才.黃錫木' WHERE person_id = 17198;  -- 原「羅慶才.黃錫木 主編」
UPDATE persons SET name = '舒邦鐸' WHERE person_id = 17390;  -- 原「舒邦鐸著」
UPDATE persons SET name = '約珥．埃洛斯基' WHERE person_id = 17469;  -- 原「約珥．埃洛斯基主編」
UPDATE persons SET name = '蕭嘉蓮' WHERE person_id = 17532;  -- 原「蕭嘉蓮著」
UPDATE persons SET name = 'DONALD G. MILLER' WHERE person_id = 17544;  -- 原「DONALD G. MILLER著」
UPDATE persons SET name = '周天和.方恩伯' WHERE person_id = 17753;  -- 原「周天和.方恩伯著」
UPDATE persons SET name = '彭廷亮' WHERE person_id = 17837;  -- 原「彭廷亮著」
UPDATE persons SET name = '劉立意' WHERE person_id = 17864;  -- 原「劉立意編著」
UPDATE persons SET name = '劉義章&張雲開&陳智衡' WHERE person_id = 17912;  -- 原「劉義章&張雲開&陳智衡主編」
UPDATE persons SET name = '楊鳳崗.高詩寧.李向平' WHERE person_id = 17933;  -- 原「楊鳳崗.高詩寧.李向平主編」
UPDATE persons SET name = '林誠' WHERE person_id = 17949;  -- 原「林誠口述」
UPDATE persons SET name = '施蘊道' WHERE person_id = 18080;  -- 原「施蘊道著」
UPDATE persons SET name = '狄漢丹' WHERE person_id = 18081;  -- 原「狄漢丹著」
UPDATE persons SET name = '廖炳堂&黃漢輝' WHERE person_id = 18147;  -- 原「廖炳堂&黃漢輝主編」
UPDATE persons SET name = '葛理齊' WHERE person_id = 18177;  -- 原「葛理齊著」
UPDATE persons SET name = '戈登' WHERE person_id = 18183;  -- 原「戈登著」
UPDATE persons SET name = '唐國本' WHERE person_id = 18233;  -- 原「唐國本著」
UPDATE persons SET name = '雅斯頓' WHERE person_id = 18336;  -- 原「雅斯頓著」
UPDATE persons SET name = '胡國禎.丁立偉.詹嫦慧' WHERE person_id = 18486;  -- 原「胡國禎.丁立偉.詹嫦慧主編」
UPDATE persons SET name = '戴夫韓特.麥克馬宏' WHERE person_id = 18519;  -- 原「戴夫韓特.麥克馬宏著」
UPDATE persons SET name = '高師寧&袁浩' WHERE person_id = 18658;  -- 原「高師寧&袁浩主編」
UPDATE persons SET name = '何笑馨' WHERE person_id = 18708;  -- 原「何笑馨著」
UPDATE persons SET name = '陳敏斯' WHERE person_id = 18769;  -- 原「陳敏斯 主編」
UPDATE persons SET name = '總會法規委員會' WHERE person_id = 18853;  -- 原「總會法規委員會著」
UPDATE persons SET name = '羅賓森及拉遜' WHERE person_id = 18872;  -- 原「羅賓森及拉遜主編」
UPDATE persons SET name = '約翰.亞力山大' WHERE person_id = 18887;  -- 原「約翰.亞力山大著」
UPDATE persons SET name = '李寶琳' WHERE person_id = 18888;  -- 原「李寶琳著」
UPDATE persons SET name = '溫德&賀斯德' WHERE person_id = 18935;  -- 原「溫德&賀斯德著」
UPDATE persons SET name = '溫德&賀思德' WHERE person_id = 18937;  -- 原「溫德&賀思德編著」
UPDATE persons SET name = '羅拔‧尼告洛等人' WHERE person_id = 19025;  -- 原「羅拔‧尼告洛等人著」
UPDATE persons SET name = '盧家駇等人' WHERE person_id = 19026;  -- 原「盧家駇等人著」
UPDATE persons SET name = '華艾富.格索色' WHERE person_id = 19032;  -- 原「華艾富.格索色著」
UPDATE persons SET name = '曾道行' WHERE person_id = 19034;  -- 原「曾道行著」
UPDATE persons SET name = '陳延忠' WHERE person_id = 19037;  -- 原「陳延忠著」
UPDATE persons SET name = '宣道會香港區聯會   崇拜模式指引委員會' WHERE person_id = 19079;  -- 原「宣道會香港區聯會   崇拜模式指引委員會著」
UPDATE persons SET name = '北角堂崇拜委員會' WHERE person_id = 19087;  -- 原「北角堂崇拜委員會編著」
UPDATE persons SET name = '秋彼得' WHERE person_id = 19095;  -- 原「秋彼得著」
UPDATE persons SET name = '羅斯.坎培爾' WHERE person_id = 19137;  -- 原「羅斯.坎培爾著」
UPDATE persons SET name = '新新生命雜誌社' WHERE person_id = 19140;  -- 原「新新生命雜誌社著」
UPDATE persons SET name = '拜倫.亞理傑' WHERE person_id = 19145;  -- 原「拜倫.亞理傑著」
UPDATE persons SET name = '邱瓊苑' WHERE person_id = 19147;  -- 原「邱瓊苑編著」
UPDATE persons SET name = '蘇文峰.蘇文安' WHERE person_id = 19233;  -- 原「蘇文峰.蘇文安著」
UPDATE persons SET name = '葛原隆.吳瑩瑛' WHERE person_id = 19400;  -- 原「葛原隆.吳瑩瑛 編著」
UPDATE persons SET name = '梅理查' WHERE person_id = 19582;  -- 原「梅理查著」
UPDATE persons SET name = '羅伯.華爾頓' WHERE person_id = 19613;  -- 原「羅伯.華爾頓著」
UPDATE persons SET name = '黃文江.郭偉聯.劉義章' WHERE person_id = 19633;  -- 原「黃文江.郭偉聯.劉義章 主編」
UPDATE persons SET name = '林治平主' WHERE person_id = 19648;  -- 原「林治平主編著」
UPDATE persons SET name = '維也納總主教舒安邦樞機' WHERE person_id = 19689;  -- 原「維也納總主教舒安邦樞機主編」
UPDATE persons SET name = '喬.赫維特' WHERE person_id = 19698;  -- 原「喬.赫維特著」
UPDATE persons SET name = '佛洛.麥克艾文' WHERE person_id = 19699;  -- 原「佛洛.麥克艾文著」
UPDATE persons SET name = '伍謂文' WHERE person_id = 19712;  -- 原「伍謂文主編」
UPDATE persons SET name = '勃賴德門' WHERE person_id = 19717;  -- 原「勃賴德門著」
UPDATE persons SET name = '法蘭克．巴特曼' WHERE person_id = 19745;  -- 原「法蘭克．巴特曼著」
UPDATE persons SET name = '甘鄧肯' WHERE person_id = 19748;  -- 原「甘鄧肯著」
UPDATE persons SET name = '陳源雄' WHERE person_id = 19806;  -- 原「陳源雄編著」
UPDATE persons SET name = '麥海洛' WHERE person_id = 19810;  -- 原「麥海洛著」
UPDATE persons SET name = '理查.班奈德' WHERE person_id = 19811;  -- 原「理查.班奈德著」
UPDATE persons SET name = '濟爾．倫格克&多特．史若濟斯' WHERE person_id = 19892;  -- 原「濟爾．倫格克&多特．史若濟斯 編著」
UPDATE persons SET name = '萬迪克&沃莫莉' WHERE person_id = 19915;  -- 原「萬迪克&沃莫莉主編」
UPDATE persons SET name = '喬艾絲' WHERE person_id = 20067;  -- 原「喬艾絲著」
UPDATE persons SET name = '汪兆翔' WHERE person_id = 20080;  -- 原「汪兆翔著」
UPDATE persons SET name = '艾鍾妮' WHERE person_id = 20085;  -- 原「艾鍾妮著」
UPDATE persons SET name = '高卓傑' WHERE person_id = 20141;  -- 原「高卓傑著」
UPDATE persons SET name = '王捷' WHERE person_id = 20144;  -- 原「王捷著」
UPDATE persons SET name = '斯克倫.麥卡錫' WHERE person_id = 20287;  -- 原「斯克倫.麥卡錫 編著」
UPDATE persons SET name = '朱林梅英' WHERE person_id = 20291;  -- 原「朱林梅英 編著」
UPDATE persons SET name = '麥克‧丹尼森' WHERE person_id = 20325;  -- 原「麥克‧丹尼森著」
UPDATE persons SET name = '嘉菲.高尤一.瑪尤' WHERE person_id = 20327;  -- 原「嘉菲.高尤一.瑪尤著」
UPDATE persons SET name = '歌羅莉亞.郝斯' WHERE person_id = 20372;  -- 原「歌羅莉亞.郝斯著」
UPDATE persons SET name = '洪嘉露' WHERE person_id = 20375;  -- 原「洪嘉露著」
UPDATE persons SET name = '達拉斯.巴恩斯' WHERE person_id = 20444;  -- 原「達拉斯.巴恩斯著」
UPDATE persons SET name = '黃麗貞' WHERE person_id = 20447;  -- 原「黃麗貞著」
UPDATE persons SET name = '黃禮聰' WHERE person_id = 20448;  -- 原「黃禮聰編著」
UPDATE persons SET name = '陳宏時' WHERE person_id = 20451;  -- 原「陳宏時著」
UPDATE persons SET name = '高安德.詹艾爾' WHERE person_id = 20452;  -- 原「高安德.詹艾爾著」
UPDATE persons SET name = '桑斯特' WHERE person_id = 20453;  -- 原「桑斯特著」
UPDATE persons SET name = '恩育' WHERE person_id = 20454;  -- 原「恩育著」
UPDATE persons SET name = '李巧玲.廖玉珍' WHERE person_id = 20457;  -- 原「李巧玲.廖玉珍著」
UPDATE persons SET name = '三一基督徒中心' WHERE person_id = 20827;  -- 原「三一基督徒中心 編著」
UPDATE persons SET name = '環球領袖網路' WHERE person_id = 20874;  -- 原「環球領袖網路 編著」
UPDATE persons SET name = '曾振錨' WHERE person_id = 20910;  -- 原「曾振錨著」
UPDATE persons SET name = '韋華' WHERE person_id = 20980;  -- 原「韋華著」
UPDATE persons SET name = '李應揚.丁立福' WHERE person_id = 20983;  -- 原「李應揚.丁立福著」
UPDATE persons SET name = '莊頌祺' WHERE person_id = 21022;  -- 原「莊頌祺編著」
UPDATE persons SET name = '梅志' WHERE person_id = 21024;  -- 原「梅志著」
UPDATE persons SET name = '陳南洲' WHERE person_id = 21053;  -- 原「陳南洲主編」
UPDATE persons SET name = '成文' WHERE person_id = 21180;  -- 原「成文編著」
UPDATE persons SET name = '詹姆斯.道森' WHERE person_id = 21185;  -- 原「詹姆斯.道森著」
UPDATE persons SET name = '喬.貝莉' WHERE person_id = 21188;  -- 原「喬.貝莉著」
UPDATE persons SET name = '莊文生' WHERE person_id = 21192;  -- 原「莊文生著」
UPDATE persons SET name = '克汶.雷蒙' WHERE person_id = 21201;  -- 原「克汶.雷蒙著」
UPDATE persons SET name = '賴利．克利斯頓夫婦' WHERE person_id = 21202;  -- 原「賴利．克利斯頓夫婦著」
UPDATE persons SET name = '大光出版部' WHERE person_id = 21205;  -- 原「大光出版部編著」
UPDATE persons SET name = 'MOPS & 愛麗莎摩根' WHERE person_id = 21355;  -- 原「MOPS & 愛麗莎摩根編著」
UPDATE persons SET name = '賀德.司達爾' WHERE person_id = 21645;  -- 原「賀德.司達爾著」
UPDATE persons SET name = '桶口雃一' WHERE person_id = 21648;  -- 原「桶口雃一著」
UPDATE persons SET name = '富蘭克.米尼斯' WHERE person_id = 21672;  -- 原「富蘭克.米尼斯著」
UPDATE persons SET name = '傑利.鄧' WHERE person_id = 21857;  -- 原「傑利.鄧著」
UPDATE persons SET name = 'CBMC總會' WHERE person_id = 21990;  -- 原「CBMC總會編著」
UPDATE persons SET name = '卓李凱倫' WHERE person_id = 22014;  -- 原「卓李凱倫著」
UPDATE persons SET name = '季賽生' WHERE person_id = 22020;  -- 原「季賽生著」
UPDATE persons SET name = '霍華．韓君時' WHERE person_id = 22172;  -- 原「霍華．韓君時著」
UPDATE persons SET name = '魏斯.海斯丹' WHERE person_id = 22199;  -- 原「魏斯.海斯丹著」
UPDATE persons SET name = '蓮恩強生' WHERE person_id = 22203;  -- 原「蓮恩強生著」
UPDATE persons SET name = '路得畢區克' WHERE person_id = 22204;  -- 原「路得畢區克著」
UPDATE persons SET name = '凱茲.湯姆遜' WHERE person_id = 22205;  -- 原「凱茲.湯姆遜著」
UPDATE persons SET name = '法蘭.羅特門' WHERE person_id = 22206;  -- 原「法蘭.羅特門著」
UPDATE persons SET name = '莊恩' WHERE person_id = 22302;  -- 原「莊恩著」
UPDATE persons SET name = '保羅卜納' WHERE person_id = 22395;  -- 原「保羅卜納著」
UPDATE persons SET name = '小羊' WHERE person_id = 22422;  -- 原「小羊 編著」
UPDATE persons SET name = '任柏良' WHERE person_id = 22429;  -- 原「任柏良 編著」
UPDATE persons SET name = '魏喜樂.華比爾' WHERE person_id = 22453;  -- 原「魏喜樂.華比爾著」
UPDATE persons SET name = '吳仁瑟' WHERE person_id = 22483;  -- 原「吳仁瑟編著」
UPDATE persons SET name = '李茂松' WHERE person_id = 22530;  -- 原「李茂松編著」
UPDATE persons SET name = '啞歌' WHERE person_id = 22585;  -- 原「啞歌著」
UPDATE persons SET name = '李哈利' WHERE person_id = 22796;  -- 原「李哈利 主編」
UPDATE persons SET name = '岑建基' WHERE person_id = 22812;  -- 原「岑建基 主編」
UPDATE persons SET name = '連山' WHERE person_id = 22847;  -- 原「連山 編著」
UPDATE persons SET name = '冼維正醫生' WHERE person_id = 22970;  -- 原「冼維正醫生 主編」
UPDATE persons SET name = '關詠賢' WHERE person_id = 22971;  -- 原「關詠賢 著」
UPDATE persons SET name = '詹姆斯．拉文' WHERE person_id = 23166;  -- 原「詹姆斯．拉文 主編」
UPDATE persons SET name = '塞韋爾．沃伊庫' WHERE person_id = 23439;  -- 原「塞韋爾．沃伊庫 主編」
UPDATE persons SET name = '李榮漢' WHERE person_id = 23711;  -- 原「李榮漢 著」
UPDATE persons SET name = '吳日嵐' WHERE person_id = 23735;  -- 原「吳日嵐 主編」
UPDATE persons SET name = '約珥．薩頓' WHERE person_id = 23836;  -- 原「約珥．薩頓 主編」
UPDATE persons SET name = 'Moisés Silva' WHERE person_id = 23837;  -- 原「Moisés Silva 主編」
UPDATE persons SET name = '區靖彤' WHERE person_id = 23863;  -- 原「區靖彤 主編」
UPDATE persons SET name = '劉佳昊' WHERE person_id = 23891;  -- 原「劉佳昊 主編」
UPDATE persons SET name = '丁麗芬' WHERE person_id = 24018;  -- 原「丁麗芬 主編」
UPDATE persons SET name = '劉卓聰' WHERE person_id = 24129;  -- 原「劉卓聰 編著」
UPDATE persons SET name = '香港文學館' WHERE person_id = 24182;  -- 原「香港文學館 主編」
UPDATE persons SET name = '黃秋生' WHERE person_id = 24274;  -- 原「黃秋生 口述」
UPDATE persons SET name = '戴安．施多茲' WHERE person_id = 24450;  -- 原「戴安．施多茲 著」
UPDATE persons SET name = '謝翠婷' WHERE person_id = 24618;  -- 原「謝翠婷 主編」
UPDATE persons SET name = '詹姆斯．伊斯泰普' WHERE person_id = 24816;  -- 原「詹姆斯．伊斯泰普  主編」
UPDATE persons SET name = '安東妮．許奈德' WHERE person_id = 25032;  -- 原「安東妮．許奈德 著」
UPDATE persons SET name = '楊瑾' WHERE person_id = 25352;  -- 原「楊瑾 著」
UPDATE persons SET name = 'Bereshith' WHERE person_id = 25403;  -- 原「Bereshith 主編」
UPDATE persons SET name = '李連江' WHERE person_id = 25470;  -- 原「李連江 著」
UPDATE persons SET name = '黃愛恩' WHERE person_id = 25718;  -- 原「黃愛恩 口述」
UPDATE persons SET name = '建道神學院跨越文化研究系' WHERE person_id = 25771;  -- 原「建道神學院跨越文化研究系 主編」
UPDATE persons SET name = '何述群' WHERE person_id = 25775;  -- 原「何述群主編」
UPDATE persons SET name = '梁寶珠' WHERE person_id = 25776;  -- 原「梁寶珠 主編」
UPDATE persons SET name = '蘇文英 義務' WHERE person_id = 25796;  -- 原「蘇文英 義務編著」
UPDATE persons SET name = '趙祟明' WHERE person_id = 26140;  -- 原「趙祟明 主編」
UPDATE persons SET name = '威頓' WHERE person_id = 26145;  -- 原「威頓 編著」
UPDATE persons SET name = '何陳佩英' WHERE person_id = 26202;  -- 原「何陳佩英 主編」
UPDATE persons SET name = '同光同志長老教會' WHERE person_id = 26284;  -- 原「同光同志長老教會 編著」
UPDATE persons SET name = '基督教靈實協會' WHERE person_id = 26306;  -- 原「基督教靈實協會 著」
UPDATE persons SET name = '倪誠' WHERE person_id = 26309;  -- 原「倪誠 主編」
UPDATE persons SET name = '黃曉紅 等21人' WHERE person_id = 26361;  -- 原「黃曉紅 等21人著」
UPDATE persons SET name = '翟煦' WHERE person_id = 26499;  -- 原「翟煦 編著」
UPDATE persons SET name = '周鴻奇博士' WHERE person_id = 26584;  -- 原「周鴻奇博士 主編」
UPDATE persons SET name = '羅遠婷' WHERE person_id = 26618;  -- 原「羅遠婷 主編」
UPDATE persons SET name = '黃彰輝牧師百歲紀念活動委員會' WHERE person_id = 26711;  -- 原「黃彰輝牧師百歲紀念活動委員會主編」
UPDATE persons SET name = '宋恩榮' WHERE person_id = 26757;  -- 原「宋恩榮 主編」
UPDATE persons SET name = '羅杰才' WHERE person_id = 27073;  -- 原「羅杰才 主編」
UPDATE persons SET name = '克萊兒．克蕾芒' WHERE person_id = 27398;  -- 原「克萊兒．克蕾芒 著」
UPDATE persons SET name = '吳有能' WHERE person_id = 27552;  -- 原「吳有能 主編」
UPDATE persons SET name = '岳清華' WHERE person_id = 27577;  -- 原「岳清華著」
UPDATE persons SET name = '凱利．派特森等五位' WHERE person_id = 27775;  -- 原「凱利．派特森等五位 著」
UPDATE persons SET name = '姚鏡鴻' WHERE person_id = 28314;  -- 原「姚鏡鴻 主編」
UPDATE persons SET name = '陳明斌' WHERE person_id = 28337;  -- 原「陳明斌編著」
UPDATE persons SET name = '羅恩．克盧格' WHERE person_id = 28692;  -- 原「羅恩．克盧格 主編」
UPDATE persons SET name = '林律光' WHERE person_id = 28919;  -- 原「林律光 主編」
UPDATE persons SET name = '愛維林．柏西蘇－貝容' WHERE person_id = 29059;  -- 原「愛維林．柏西蘇－貝容 著」
UPDATE persons SET name = '《根基．親子》雜誌編輯部' WHERE person_id = 29139;  -- 原「《根基．親子》雜誌編輯部編著」
UPDATE persons SET name = '約瑟哈林德' WHERE person_id = 29149;  -- 原「約瑟哈林德 主編」
UPDATE persons SET name = '巴頓' WHERE person_id = 29175;  -- 原「巴頓 主編」
UPDATE persons SET name = '明愛曉暉計劃－童年創傷輔導服務' WHERE person_id = 29258;  -- 原「明愛曉暉計劃－童年創傷輔導服務 編著」
UPDATE persons SET name = '馬可謝雷登' WHERE person_id = 29313;  -- 原「馬可謝雷登  主編」
UPDATE persons SET name = '克莉絲朵．包曼' WHERE person_id = 29493;  -- 原「克莉絲朵．包曼 著」
UPDATE persons SET name = '吳潔儀' WHERE person_id = 29646;  -- 原「吳潔儀著」
UPDATE persons SET name = '李詠研' WHERE person_id = 29651;  -- 原「李詠研 主編」
UPDATE persons SET name = '藍復春' WHERE person_id = 29795;  -- 原「藍復春 口述」
UPDATE persons SET name = '莫迪根．摩斯坦' WHERE person_id = 30399;  -- 原「莫迪根．摩斯坦 著」
UPDATE persons SET name = '阿玆拉．喬玆坦尼' WHERE person_id = 30403;  -- 原「阿玆拉．喬玆坦尼 著」
UPDATE persons SET name = '陳希明' WHERE person_id = 30416;  -- 原「陳希明 著」
UPDATE persons SET name = '蘇珊．泰格蒂絲' WHERE person_id = 30458;  -- 原「蘇珊．泰格蒂絲 著」
UPDATE persons SET name = '蜜亞．凱利' WHERE person_id = 30596;  -- 原「蜜亞．凱利 著」
UPDATE persons SET name = '胡伯特．席爾聶克' WHERE person_id = 30649;  -- 原「胡伯特．席爾聶克 著」
UPDATE persons SET name = '鄭彼得' WHERE person_id = 30664;  -- 原「鄭彼得編著」
UPDATE persons SET name = '鄭明淑' WHERE person_id = 30668;  -- 原「鄭明淑口述」
UPDATE persons SET name = '薰久美子' WHERE person_id = 30702;  -- 原「薰久美子 著」
UPDATE persons SET name = '艾倫．都蘭' WHERE person_id = 30718;  -- 原「艾倫．都蘭 著」
UPDATE persons SET name = '奧斯卡．王爾德' WHERE person_id = 30722;  -- 原「奧斯卡．王爾德 著」
UPDATE persons SET name = '聖修伯理' WHERE person_id = 30748;  -- 原「聖修伯理 原著」
UPDATE persons SET name = '森繪都' WHERE person_id = 30771;  -- 原「森繪都著」
UPDATE persons SET name = '露芭．崔森斯卡．費德瑞克' WHERE person_id = 30821;  -- 原「露芭．崔森斯卡．費德瑞克 口述」
UPDATE persons SET name = '區方悅' WHERE person_id = 30846;  -- 原「區方悅 主編」
UPDATE persons SET name = '佩特拉．夢特' WHERE person_id = 30868;  -- 原「佩特拉．夢特 著」
UPDATE persons SET name = '奈思卓' WHERE person_id = 30930;  -- 原「奈思卓 著」
UPDATE persons SET name = '王愛敏' WHERE person_id = 31002;  -- 原「王愛敏口述」
UPDATE persons SET name = '瑪麗．郭德堡' WHERE person_id = 31046;  -- 原「瑪麗．郭德堡 著」
UPDATE persons SET name = '理察．喬根森' WHERE person_id = 31121;  -- 原「理察．喬根森 著」
UPDATE persons SET name = '顧素冊' WHERE person_id = 31128;  -- 原「顧素冊 編選」
UPDATE persons SET name = '布羅米利' WHERE person_id = 31162;  -- 原「布羅米利 主編」
UPDATE persons SET name = '許志偉' WHERE person_id = 31345;  -- 原「許志偉 主編」
UPDATE persons SET name = '何除' WHERE person_id = 31370;  -- 原「何除 主編」
UPDATE persons SET name = '黃錫木中文版' WHERE person_id = 31531;  -- 原「黃錫木中文版 主編」
UPDATE persons SET name = '克莉絲朵柯吉絲' WHERE person_id = 31551;  -- 原「克莉絲朵柯吉絲著」
UPDATE persons SET name = '洪子雲' WHERE person_id = 31561;  -- 原「洪子雲主編」
UPDATE persons SET name = '莫妮卡．菲特' WHERE person_id = 31679;  -- 原「莫妮卡．菲特 著」
UPDATE persons SET name = '保羅．德里歐斯' WHERE person_id = 32018;  -- 原「保羅．德里歐斯 編著」
UPDATE persons SET name = '劉重明' WHERE person_id = 32214;  -- 原「劉重明 主編」
UPDATE persons SET name = '吳斯拉' WHERE person_id = 32659;  -- 原「吳斯拉 著」
UPDATE persons SET name = '韓大輝' WHERE person_id = 32691;  -- 原「韓大輝 主編」
UPDATE persons SET name = '吳嫦娥' WHERE person_id = 32750;  -- 原「吳嫦娥 編著」
UPDATE persons SET name = '郭桂明' WHERE person_id = 32787;  -- 原「郭桂明 主編」
UPDATE persons SET name = '涂鄒維真' WHERE person_id = 33161;  -- 原「涂鄒維真 口述」
UPDATE persons SET name = '何曉鳳' WHERE person_id = 33761;  -- 原「何曉鳳 口述」
UPDATE persons SET name = '梅樂蒂福克斯' WHERE person_id = 33762;  -- 原「梅樂蒂福克斯 著」
UPDATE persons SET name = 'Jim Morud' WHERE person_id = 33823;  -- 原「Jim Morud主編」
UPDATE persons SET name = '王怡婷' WHERE person_id = 34479;  -- 原「王怡婷 主編」
UPDATE persons SET name = '愛德華費雪' WHERE person_id = 34484;  -- 原「愛德華費雪 著」
UPDATE persons SET name = '趙少傑' WHERE person_id = 34698;  -- 原「趙少傑 主編」
UPDATE persons SET name = '周巽倩' WHERE person_id = 35067;  -- 原「周巽倩主編」
UPDATE persons SET name = '郭榮剛 共同' WHERE person_id = 35343;  -- 原「郭榮剛 共同主編」
UPDATE persons SET name = '韓梅爾' WHERE person_id = 35821;  -- 原「韓梅爾著」
UPDATE persons SET name = '茱蒂‧葛福偉' WHERE person_id = 35965;  -- 原「茱蒂‧葛福偉 著」
UPDATE persons SET name = '信息本' WHERE person_id = 36055;  -- 原「信息本原著」
UPDATE persons SET name = '周聯華博士' WHERE person_id = 36539;  -- 原「周聯華博士著」
UPDATE persons SET name = '楊鳳崗.高師寧.李向平' WHERE person_id = 36673;  -- 原「楊鳳崗.高師寧.李向平 主編」
UPDATE persons SET name = '曾寬' WHERE person_id = 36679;  -- 原「曾寬  著」
UPDATE persons SET name = '環球領袖網絡' WHERE person_id = 36849;  -- 原「環球領袖網絡 編著」
UPDATE persons SET name = '吳勇長老' WHERE person_id = 36970;  -- 原「吳勇長老口述」
UPDATE persons SET name = '保羅.德里歐斯' WHERE person_id = 37227;  -- 原「保羅.德里歐斯 編著」
UPDATE persons SET name = '陳國溪&麥家輝' WHERE person_id = 37241;  -- 原「陳國溪&麥家輝編著」
UPDATE persons SET name = '國立成奶j學 台灣語文測驗中心' WHERE person_id = 37305;  -- 原「國立成奶j學 台灣語文測驗中心 編著」
UPDATE persons SET name = '拉遜' WHERE person_id = 37325;  -- 原「拉遜 主編」
UPDATE persons SET name = '羅恩.克盧格' WHERE person_id = 37405;  -- 原「羅恩.克盧格 主編」
UPDATE persons SET name = '王志勇.余杰' WHERE person_id = 37662;  -- 原「王志勇.余杰 主編」
UPDATE persons SET name = '馬挺.戴維茲' WHERE person_id = 37805;  -- 原「馬挺.戴維茲 主編」
UPDATE persons SET name = '程玲' WHERE person_id = 37811;  -- 原「程玲編著」
UPDATE persons SET name = '昆廷.維素舒密' WHERE person_id = 37834;  -- 原「昆廷.維素舒密 主編」
UPDATE persons SET name = '卡門.哈丁' WHERE person_id = 37837;  -- 原「卡門.哈丁 主編」
UPDATE persons SET name = '林治平.吳昶興' WHERE person_id = 38053;  -- 原「林治平.吳昶興  主編」
UPDATE persons SET name = '林培松牧師' WHERE person_id = 38261;  -- 原「林培松牧師主編」
UPDATE persons SET name = '溫德.賀思德' WHERE person_id = 38778;  -- 原「溫德.賀思德編著」
UPDATE persons SET name = '約翰．班楊' WHERE person_id = 38862;  -- 原「約翰．班楊著」
UPDATE persons SET name = 'Arise 5' WHERE person_id = 38971;  -- 原「Arise 5 編著」
UPDATE persons SET name = '阿濃(朱漙生)' WHERE person_id = 39067;  -- 原「阿濃(朱漙生)著」
UPDATE persons SET name = '楊吉宗' WHERE person_id = 39184;  -- 原「楊吉宗 著」
UPDATE persons SET name = '袓利艾.大衛' WHERE person_id = 39620;  -- 原「袓利艾.大衛 著」
UPDATE persons SET name = '丁祖潘' WHERE person_id = 40163;  -- 原「丁祖潘 主編」
UPDATE persons SET name = 'Gospel Light' WHERE person_id = 40551;  -- 原「Gospel Light著」
UPDATE persons SET name = '阿瑟·貝內特（Arthur Bennett）' WHERE person_id = 40555;  -- 原「阿瑟·貝內特（Arthur Bennett）編著」
UPDATE persons SET name = '喬治·慕勒（George Müller）' WHERE person_id = 40569;  -- 原「喬治·慕勒（George Müller）著」
UPDATE persons SET name = '波特（Stanley E. Porter)' WHERE person_id = 40741;  -- 原「波特（Stanley E. Porter) 主編」
UPDATE persons SET name = '李瑋國' WHERE person_id = 41464;  -- 原「李瑋國 著」
UPDATE persons SET name = '鄧福祥' WHERE person_id = 41586;  -- 原「鄧福祥 著」
UPDATE persons SET name = '[美]傅士德' WHERE person_id = 41732;  -- 原「[美]傅士德 著」
UPDATE persons SET name = '(美)布拉福德' WHERE person_id = 41904;  -- 原「(美)布拉福德 著」
UPDATE persons SET name = '邁克爾·霍頓' WHERE person_id = 42021;  -- 原「邁克爾·霍頓 著」
UPDATE persons SET name = '(荷蘭)赫爾曼·巴文克' WHERE person_id = 42026;  -- 原「(荷蘭)赫爾曼·巴文克 著」
UPDATE persons SET name = '[美]魏樂德' WHERE person_id = 42044;  -- 原「[美]魏樂德 著」
UPDATE persons SET name = '[丹麥]祁克果' WHERE person_id = 42060;  -- 原「[丹麥]祁克果 著」
UPDATE persons SET name = '彼得·克雷夫特' WHERE person_id = 42079;  -- 原「彼得·克雷夫特 著」
UPDATE persons SET name = '盧雲（Henri J.M. Nouwen）' WHERE person_id = 42257;  -- 原「盧雲（Henri J.M. Nouwen） 著」
UPDATE persons SET name = '斯蒂芬·曼斯菲爾德' WHERE person_id = 42381;  -- 原「斯蒂芬·曼斯菲爾德 著」
UPDATE persons SET name = 'R.斯科特·羅丁' WHERE person_id = 42415;  -- 原「R.斯科特·羅丁 著」
UPDATE persons SET name = '[美]馬睿欣' WHERE person_id = 42461;  -- 原「[美]馬睿欣 著」
UPDATE persons SET name = '臨風' WHERE person_id = 42535;  -- 原「臨風 著」
UPDATE persons SET name = '羅秉祥 ,江丕盛' WHERE person_id = 42596;  -- 原「羅秉祥 ,江丕盛 主編」
UPDATE persons SET name = '[澳]萊昂·莫里斯（Leon Morris）' WHERE person_id = 42628;  -- 原「[澳]萊昂·莫里斯（Leon Morris） 著」
UPDATE persons SET name = '張永信 張略' WHERE person_id = 42759;  -- 原「張永信 張略 著」
UPDATE persons SET name = '冉道夫·孫德司' WHERE person_id = 42798;  -- 原「冉道夫·孫德司主編」
UPDATE persons SET name = '[韓]鄭弼禱' WHERE person_id = 42878;  -- 原「[韓]鄭弼禱 著」
UPDATE persons SET name = '蓋瑞・湯瑪斯 Gary Thomas' WHERE person_id = 43110;  -- 原「蓋瑞・湯瑪斯 Gary Thomas 著」
UPDATE persons SET name = '瑪達蓮娜‧伯格納吉&古倫神父' WHERE person_id = 43323;  -- 原「瑪達蓮娜‧伯格納吉&古倫神父 著」
UPDATE persons SET name = '裴連山' WHERE person_id = 43507;  -- 原「裴連山 主編」
UPDATE persons SET name = '本仁約翰  John Bunyan' WHERE person_id = 43609;  -- 原「本仁約翰  John Bunyan 著」
UPDATE persons SET name = '吳德聖' WHERE person_id = 43686;  -- 原「吳德聖口述」
UPDATE persons SET name = '諾曼‧萊特 H. Norman Wright' WHERE person_id = 44495;  -- 原「諾曼‧萊特 H. Norman Wright著」
UPDATE persons SET name = 'Larry Burkett' WHERE person_id = 44504;  -- 原「Larry Burkett著」
UPDATE persons SET name = '張談掌珠' WHERE person_id = 44569;  -- 原「張談掌珠著」
UPDATE persons SET name = '卲正宏' WHERE person_id = 44573;  -- 原「卲正宏著」
UPDATE persons SET name = '約翰．哈勒戴 (John Holliday)' WHERE person_id = 44811;  -- 原「約翰．哈勒戴 (John Holliday) 著」
UPDATE persons SET name = '劉丁衡' WHERE person_id = 44817;  -- 原「劉丁衡著」
UPDATE persons SET name = '何荔璇' WHERE person_id = 44926;  -- 原「何荔璇著」
UPDATE persons SET name = '錢劉善言' WHERE person_id = 45069;  -- 原「錢劉善言著」
UPDATE persons SET name = '雷亞蘭(Alan Redpath)' WHERE person_id = 45071;  -- 原「雷亞蘭(Alan Redpath) 著」
UPDATE persons SET name = '黃原素' WHERE person_id = 45081;  -- 原「黃原素著」
UPDATE persons SET name = '何爾登' WHERE person_id = 45118;  -- 原「何爾登著」
UPDATE persons SET name = '宣道會北角堂崇拜委員會' WHERE person_id = 45133;  -- 原「宣道會北角堂崇拜委員會編著」
UPDATE persons SET name = '黃玉明&劉家峰' WHERE person_id = 45144;  -- 原「黃玉明&劉家峰 主編」
UPDATE persons SET name = '胡蘊琳' WHERE person_id = 45337;  -- 原「胡蘊琳著」
UPDATE persons SET name = '欣靈' WHERE person_id = 45343;  -- 原「欣靈著」
UPDATE persons SET name = '賴建鵬等人' WHERE person_id = 45363;  -- 原「賴建鵬等人著」
UPDATE persons SET name = '劉錦棠' WHERE person_id = 45367;  -- 原「劉錦棠著」
UPDATE persons SET name = '林 郁' WHERE person_id = 45412;  -- 原「林 郁主編」
UPDATE persons SET name = '克雷格‧奧斯頓' WHERE person_id = 45830;  -- 原「克雷格‧奧斯頓著」
UPDATE persons SET name = '徐秀蘭等多位老師' WHERE person_id = 46243;  -- 原「徐秀蘭等多位老師編著」
UPDATE persons SET name = '郭奕宏' WHERE person_id = 46305;  -- 原「郭奕宏主編」
UPDATE persons SET name = '法蘭斯‧巴克' WHERE person_id = 46350;  -- 原「法蘭斯‧巴克著」
UPDATE persons SET name = '蕭壽華等人' WHERE person_id = 46351;  -- 原「蕭壽華等人著」
UPDATE persons SET name = '德尼坦里' WHERE person_id = 46364;  -- 原「德尼坦里著」
UPDATE persons SET name = '林寶' WHERE person_id = 46371;  -- 原「林寶口述」
UPDATE persons SET name = '王正中牧師' WHERE person_id = 46489;  -- 原「王正中牧師 主編」
UPDATE persons SET name = '方淑芬' WHERE person_id = 47278;  -- 原「方淑芬著」
UPDATE persons SET name = '季實生' WHERE person_id = 47478;  -- 原「季實生著」
UPDATE persons SET name = '昆廷‧維素舒密' WHERE person_id = 47549;  -- 原「昆廷‧維素舒密 主編」
UPDATE persons SET name = '傅士德主編,蘿樂' WHERE person_id = 47555;  -- 原「傅士德主編,蘿樂編選」
UPDATE persons SET name = '楊慧林  (YANG Huilin)' WHERE person_id = 47677;  -- 原「楊慧林  (YANG Huilin) 著」
UPDATE persons SET name = '梁維熙' WHERE person_id = 47722;  -- 原「梁維熙主編」
UPDATE persons SET name = '李秀全 周美德' WHERE person_id = 47764;  -- 原「李秀全 周美德著」
UPDATE persons SET name = '馮 煒 文' WHERE person_id = 47813;  -- 原「馮 煒 文 著」
UPDATE persons SET name = '李耀泉' WHERE person_id = 47838;  -- 原「李耀泉主編」
UPDATE persons SET name = '臺灣基督長老教會總會臺灣族群母語推行委員會' WHERE person_id = 47921;  -- 原「臺灣基督長老教會總會臺灣族群母語推行委員會編著」
UPDATE persons SET name = '關俊棠神父' WHERE person_id = 47940;  -- 原「關俊棠神父 主編」
UPDATE persons SET name = '黃昭輝' WHERE person_id = 48206;  -- 原「黃昭輝口述」
UPDATE persons SET name = '梁 淑 慧' WHERE person_id = 48293;  -- 原「梁 淑 慧 著」
UPDATE persons SET name = 'Letty M. Russell' WHERE person_id = 48472;  -- 原「Letty M. Russell編著」
UPDATE persons SET name = '李焯仁博士' WHERE person_id = 48539;  -- 原「李焯仁博士 主編」
UPDATE persons SET name = '巴頓（John Barton）' WHERE person_id = 48815;  -- 原「巴頓（John Barton）主編」
UPDATE persons SET name = '梁惠儀' WHERE person_id = 50851;  -- 原「梁惠儀 編著」
UPDATE persons SET name = '沙立文．休斯' WHERE person_id = 51016;  -- 原「沙立文．休斯著」
UPDATE persons SET name = '陳逹' WHERE person_id = 51450;  -- 原「陳逹 著」
UPDATE persons SET name = '鍾倩雯' WHERE person_id = 51748;  -- 原「鍾倩雯等編」
UPDATE persons SET name = '盧惠銓' WHERE person_id = 51749;  -- 原「盧惠銓主編」
UPDATE persons SET name = '羅致光' WHERE person_id = 51897;  -- 原「羅致光 主編」
UPDATE persons SET name = '金宜久' WHERE person_id = 52074;  -- 原「金宜久 主編」
UPDATE persons SET name = '潘稀祺（打必里．大宇）' WHERE person_id = 52198;  -- 原「潘稀祺（打必里．大宇） 編著」
UPDATE persons SET name = '歐陽飛鶯' WHERE person_id = 52257;  -- 原「歐陽飛鶯口述」
UPDATE persons SET name = '范晉豪 譚家齊 梁永泰' WHERE person_id = 52606;  -- 原「范晉豪 譚家齊 梁永泰著」
UPDATE persons SET name = '莎莉•邁克（Sally Michael）' WHERE person_id = 52660;  -- 原「莎莉•邁克（Sally Michael）著」
UPDATE persons SET name = '麥卡瑟（John MacArthur）' WHERE person_id = 52665;  -- 原「麥卡瑟（John MacArthur）著」
UPDATE persons SET name = '王興基.王廖愛梅' WHERE person_id = 52891;  -- 原「王興基.王廖愛梅著」
UPDATE persons SET name = '泰澤團體Taize Community' WHERE person_id = 52912;  -- 原「泰澤團體Taize Community編著」
UPDATE persons SET name = '賴品超.郭鴻標.龔立人' WHERE person_id = 53060;  -- 原「賴品超.郭鴻標.龔立人著」
UPDATE persons SET name = '李金強.湯紹源.梁家麟' WHERE person_id = 53306;  -- 原「李金強.湯紹源.梁家麟主編」
UPDATE persons SET name = '邱林川&阮耀啟' WHERE person_id = 19191;  -- 原「主編:邱林川&阮耀啟」
UPDATE persons SET name = '林慈信 (Samuel Ling)' WHERE person_id = 42735;  -- 原「主編：林慈信 (Samuel Ling)」

-- ═══ D. 異體字合併:44 對 ═══
-- ★★ 這一段**預設是註解掉的**。候選是機器找的,「只差一個已知異體字」
--    仍可能是兩個不同的人。逐對看過、確認是同一人,才把該行的 -- 拿掉。
-- ★  併的方向:書多的留下,書少的併過去,舊寫法寫進保留列的 aka。
--   李淑眞(8 本,留)  ←  李淑真(4 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '李淑真') WHERE person_id = 17040;
-- UPDATE IGNORE book_persons SET person_id = 17040 WHERE person_id = 18091;
-- DELETE FROM persons WHERE person_id = 18091;
--   杰克斯(2 本,留)  ←  傑克斯(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '傑克斯') WHERE person_id = 30269;
-- UPDATE IGNORE book_persons SET person_id = 30269 WHERE person_id = 29418;
-- DELETE FROM persons WHERE person_id = 29418;
--   于厚恩(1 本,留)  ←  於厚恩(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '於厚恩') WHERE person_id = 17450;
-- UPDATE IGNORE book_persons SET person_id = 17450 WHERE person_id = 40299;
-- DELETE FROM persons WHERE person_id = 40299;
--   黃杰輝(6 本,留)  ←  黃傑輝(4 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '黃傑輝') WHERE person_id = 19655;
-- UPDATE IGNORE book_persons SET person_id = 19655 WHERE person_id = 42677;
-- DELETE FROM persons WHERE person_id = 42677;
--   于中旻(27 本,留)  ←  於中旻(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '於中旻') WHERE person_id = 17495;
-- UPDATE IGNORE book_persons SET person_id = 17495 WHERE person_id = 42799;
-- DELETE FROM persons WHERE person_id = 42799;
--   劉清峰(1 本,留)  ←  劉清峯(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '劉清峯') WHERE person_id = 37757;
-- UPDATE IGNORE book_persons SET person_id = 37757 WHERE person_id = 46488;
-- DELETE FROM persons WHERE person_id = 46488;
--   邱恆德(4 本,留)  ←  邱恒德(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '邱恒德') WHERE person_id = 18198;
-- UPDATE IGNORE book_persons SET person_id = 18198 WHERE person_id = 29705;
-- DELETE FROM persons WHERE person_id = 29705;
--   張杰克(1 本,留)  ←  張傑克(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '張傑克') WHERE person_id = 18581;
-- UPDATE IGNORE book_persons SET person_id = 18581 WHERE person_id = 40753;
-- DELETE FROM persons WHERE person_id = 40753;
--   劉漢傑(2 本,留)  ←  劉漢杰(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '劉漢杰') WHERE person_id = 20389;
-- UPDATE IGNORE book_persons SET person_id = 20389 WHERE person_id = 20351;
-- DELETE FROM persons WHERE person_id = 20351;
--   王于漸(1 本,留)  ←  王於漸(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '王於漸') WHERE person_id = 19184;
-- UPDATE IGNORE book_persons SET person_id = 19184 WHERE person_id = 42192;
-- DELETE FROM persons WHERE person_id = 42192;
--   林少峰(2 本,留)  ←  林少峯(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '林少峯') WHERE person_id = 24555;
-- UPDATE IGNORE book_persons SET person_id = 24555 WHERE person_id = 26279;
-- DELETE FROM persons WHERE person_id = 26279;
--   麥啟新(10 本,留)  ←  麥啓新(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '麥啓新') WHERE person_id = 19533;
-- UPDATE IGNORE book_persons SET person_id = 19533 WHERE person_id = 49796;
-- DELETE FROM persons WHERE person_id = 49796;
--   台灣神學研究院基督教思想研究中心(2 本,留)  ←  臺灣神學研究院基督教思想研究中心(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '臺灣神學研究院基督教思想研究中心') WHERE person_id = 19727;
-- UPDATE IGNORE book_persons SET person_id = 19727 WHERE person_id = 42927;
-- DELETE FROM persons WHERE person_id = 42927;
--   于宏潔主講 / SVCA文字編輯小組(2 本,留)  ←  於宏潔主講 / SVCA文字編輯小組(2 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '於宏潔主講 / SVCA文字編輯小組') WHERE person_id = 19773;
-- UPDATE IGNORE book_persons SET person_id = 19773 WHERE person_id = 41489;
-- DELETE FROM persons WHERE person_id = 41489;
--   臺灣福音書房編輯部(520 本,留)  ←  台灣福音書房編輯部(2 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '台灣福音書房編輯部') WHERE person_id = 33644;
-- UPDATE IGNORE book_persons SET person_id = 33644 WHERE person_id = 20041;
-- DELETE FROM persons WHERE person_id = 20041;
--   司布真(17 本,留)  ←  司布眞(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '司布眞') WHERE person_id = 20147;
-- UPDATE IGNORE book_persons SET person_id = 20147 WHERE person_id = 32633;
-- DELETE FROM persons WHERE person_id = 32633;
--   蔡貴恆(20 本,留)  ←  蔡貴恒(8 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '蔡貴恒') WHERE person_id = 20223;
-- UPDATE IGNORE book_persons SET person_id = 20223 WHERE person_id = 29039;
-- DELETE FROM persons WHERE person_id = 29039;
--   柏大衛(5 本,留)  ←  柏大衞(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '柏大衞') WHERE person_id = 20521;
-- UPDATE IGNORE book_persons SET person_id = 20521 WHERE person_id = 24936;
-- DELETE FROM persons WHERE person_id = 24936;
--   黃岳永(2 本,留)  ←  黄岳永(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '黄岳永') WHERE person_id = 21280;
-- UPDATE IGNORE book_persons SET person_id = 21280 WHERE person_id = 43402;
-- DELETE FROM persons WHERE person_id = 43402;
--   麥道衛(6 本,留)  ←  麥道衞(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '麥道衞') WHERE person_id = 21292;
-- UPDATE IGNORE book_persons SET person_id = 21292 WHERE person_id = 33086;
-- DELETE FROM persons WHERE person_id = 33086;
--   樊鴻台(4 本,留)  ←  樊鴻臺(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '樊鴻臺') WHERE person_id = 21899;
-- UPDATE IGNORE book_persons SET person_id = 21899 WHERE person_id = 42109;
-- DELETE FROM persons WHERE person_id = 42109;
--   奧古斯丁(20 本,留)  ←  奥古斯丁(2 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '奥古斯丁') WHERE person_id = 22288;
-- UPDATE IGNORE book_persons SET person_id = 22288 WHERE person_id = 22223;
-- DELETE FROM persons WHERE person_id = 22223;
--   衛國強(4 本,留)  ←  衞國強(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '衞國強') WHERE person_id = 42621;
-- UPDATE IGNORE book_persons SET person_id = 42621 WHERE person_id = 50854;
-- DELETE FROM persons WHERE person_id = 50854;
--   陳啟興(1 本,留)  ←  陳啓興(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '陳啓興') WHERE person_id = 23619;
-- UPDATE IGNORE book_persons SET person_id = 23619 WHERE person_id = 53362;
-- DELETE FROM persons WHERE person_id = 53362;
--   吳麗恆(16 本,留)  ←  吳麗恒(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '吳麗恒') WHERE person_id = 36369;
-- UPDATE IGNORE book_persons SET person_id = 36369 WHERE person_id = 48889;
-- DELETE FROM persons WHERE person_id = 48889;
--   鄒永恒(2 本,留)  ←  鄒永恆(2 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '鄒永恆') WHERE person_id = 27405;
-- UPDATE IGNORE book_persons SET person_id = 27405 WHERE person_id = 30845;
-- DELETE FROM persons WHERE person_id = 30845;
--   曾景恒(30 本,留)  ←  曾景恆(24 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '曾景恆') WHERE person_id = 27574;
-- UPDATE IGNORE book_persons SET person_id = 27574 WHERE person_id = 33896;
-- DELETE FROM persons WHERE person_id = 33896;
--   臺灣福音書房(146 本,留)  ←  台灣福音書房(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '台灣福音書房') WHERE person_id = 28289;
-- UPDATE IGNORE book_persons SET person_id = 28289 WHERE person_id = 47216;
-- DELETE FROM persons WHERE person_id = 47216;
--   布恆瑞(2 本,留)  ←  布恒瑞(2 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '布恒瑞') WHERE person_id = 32628;
-- UPDATE IGNORE book_persons SET person_id = 32628 WHERE person_id = 45732;
-- DELETE FROM persons WHERE person_id = 45732;
--   李書傑(2 本,留)  ←  李書杰(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '李書杰') WHERE person_id = 33717;
-- UPDATE IGNORE book_persons SET person_id = 33717 WHERE person_id = 33888;
-- DELETE FROM persons WHERE person_id = 33888;
--   劉漢杰 (James Lau, MD)(1 本,留)  ←  劉漢傑 (James Lau, MD)(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '劉漢傑 (James Lau, MD)') WHERE person_id = 34568;
-- UPDATE IGNORE book_persons SET person_id = 34568 WHERE person_id = 42532;
-- DELETE FROM persons WHERE person_id = 42532;
--   台灣神學研究學院基督教思想研究中心(2 本,留)  ←  臺灣神學研究學院基督教思想研究中心(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '臺灣神學研究學院基督教思想研究中心') WHERE person_id = 34781;
-- UPDATE IGNORE book_persons SET person_id = 34781 WHERE person_id = 40742;
-- DELETE FROM persons WHERE person_id = 40742;
--   李台鶯(4 本,留)  ←  李臺鶯(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '李臺鶯') WHERE person_id = 36959;
-- UPDATE IGNORE book_persons SET person_id = 36959 WHERE person_id = 42626;
-- DELETE FROM persons WHERE person_id = 42626;
--   台北靈糧堂(4 本,留)  ←  臺北靈糧堂(2 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '臺北靈糧堂') WHERE person_id = 37316;
-- UPDATE IGNORE book_persons SET person_id = 37316 WHERE person_id = 42177;
-- DELETE FROM persons WHERE person_id = 42177;
--   蔡昇達(7 本,留)  ←  蔡升達(2 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '蔡升達') WHERE person_id = 37644;
-- UPDATE IGNORE book_persons SET person_id = 37644 WHERE person_id = 40802;
-- DELETE FROM persons WHERE person_id = 40802;
--   王舜傑(2 本,留)  ←  王舜杰(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '王舜杰') WHERE person_id = 39304;
-- UPDATE IGNORE book_persons SET person_id = 39304 WHERE person_id = 44187;
-- DELETE FROM persons WHERE person_id = 44187;
--   臺灣神學研究學院(1 本,留)  ←  台灣神學研究學院(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '台灣神學研究學院') WHERE person_id = 40320;
-- UPDATE IGNORE book_persons SET person_id = 40320 WHERE person_id = 44197;
-- DELETE FROM persons WHERE person_id = 44197;
--   託馬斯·伯納德（Thomas Dehany Bernard）(1 本,留)  ←  托馬斯·伯納德（Thomas Dehany Bernard）(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '托馬斯·伯納德（Thomas Dehany Bernard）') WHERE person_id = 40367;
-- UPDATE IGNORE book_persons SET person_id = 40367 WHERE person_id = 40744;
-- DELETE FROM persons WHERE person_id = 40744;
--   托馬斯·波士頓（Thomas Boston）(2 本,留)  ←  託馬斯·波士頓（Thomas Boston）(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '託馬斯·波士頓（Thomas Boston）') WHERE person_id = 40764;
-- UPDATE IGNORE book_persons SET person_id = 40764 WHERE person_id = 41355;
-- DELETE FROM persons WHERE person_id = 41355;
--   托馬斯‧奧登（Thomas C. Oden）(27 本,留)  ←  託馬斯‧奧登（Thomas C. Oden）(24 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '託馬斯‧奧登（Thomas C. Oden）') WHERE person_id = 41017;
-- UPDATE IGNORE book_persons SET person_id = 41017 WHERE person_id = 41020;
-- DELETE FROM persons WHERE person_id = 41020;
--   社團法人台灣基督教兒童青少年關懷協會(2 本,留)  ←  社團法人臺灣基督教兒童青少年關懷協會(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '社團法人臺灣基督教兒童青少年關懷協會') WHERE person_id = 48081;
-- UPDATE IGNORE book_persons SET person_id = 48081 WHERE person_id = 41819;
-- DELETE FROM persons WHERE person_id = 41819;
--   柏大衞 (David V. Plymire)(1 本,留)  ←  柏大衛 (David V. Plymire)(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '柏大衛 (David V. Plymire)') WHERE person_id = 44621;
-- UPDATE IGNORE book_persons SET person_id = 44621 WHERE person_id = 47840;
-- DELETE FROM persons WHERE person_id = 47840;
--   哈傑夫 Jeffrey J. Harrison(1 本,留)  ←  哈杰夫 Jeffrey J. Harrison(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '哈杰夫 Jeffrey J. Harrison') WHERE person_id = 44970;
-- UPDATE IGNORE book_persons SET person_id = 44970 WHERE person_id = 44971;
-- DELETE FROM persons WHERE person_id = 44971;
--   麥道衞 (Josh McDowell)(1 本,留)  ←  麥道衛 (Josh McDowell)(1 本,併)
-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), '麥道衛 (Josh McDowell)') WHERE person_id = 49032;
-- UPDATE IGNORE book_persons SET person_id = 49032 WHERE person_id = 53421;
-- DELETE FROM persons WHERE person_id = 53421;

-- ═══ E. 回查驗證(COMMIT 之前先跑,**每一句都應回 0 列**) ═══
-- ★ 工具自印的數字不算證據,以下才算。

-- E1. 本次要刪/要併的 person_id 應該都不存在了
SELECT person_id, name FROM persons WHERE person_id IN (16778,16804,16842,16898,16927,16928,16945,16954,16955,16959,17053,17155,17160,17166,17167,17194,17202,17203,17207,17208,17211,17212,17247,17248,17261,17270,17306,17324,17325,17328,17329,17330,17387,17389,17392,17427,17527,17537,17559,17564,17630,17634,17665,17673,17676,17677,17678,17713,17721,17738,17750,17754,17769,17779,17789,17824,17835,17841,17842,17854,17869,17870,17871,17882,17889,17899,17904,17906,17914,17932,17970,17976,17979,17980,17981,17986,17987,18005,18015,18020,18037,18040,18073,18084,18114,18117,18118,18132,18154,18168,18174,18178,18179,18180,18221,18223,18225,18231,18232,18267,18269,18271,18310,18328,18329,18331,18332,18334,18337,18338,18340,18341,18343,18344,18381,18386,18387,18423,18436,18447,18504,18539,18567,18574,18598,18609,18636,18670,18676,18704,18705,18706,18707,18788,18800,18858,18876,18878,18879,18889,18982,18987,18993,18996,19018,19022,19028,19031,19035,19036,19043,19066,19075,19088,19092,19093,19094,19102,19105,19109,19110,19133,19139,19143,19146,19148,19179,19187,19205,19209,19215,19230,19239,19240,19345,19371,19377,19410,19421,19433,19540,19579,19581,19611,19624,19630,19639,19640,19641,19642,19643,19658,19665,19670,19672,19675,19690,19695,19696,19705,19709,19715,19716,19719,19720,19723,19726,19728,19740,19741,19742,19743,19759,19766,19774,19799,19802,19807,19809,19812,19813,19815,19850,19855,19935,19954,20007,20037,20061,20062,20064,20065,20068,20069,20070,20072,20073,20074,20075,20076,20077,20079,20082,20083,20090,20138,20140,20142,20145,20146,20169,20171,20199,20201,20210,20219,20239,20252,20253,20279,20282,20283,20309,20326,20329,20330,20376,20378,20427,20429,20440,20442,20443,20445,20450,20455,20456,20476,20536,20608,20621,20633,20637,20638,20691,20747,20755,20779,20785,20807,20847,20857,20888,20893,20902,20911,20966,20975,20976,20977,20978,20982,20986,21011,21014,21023,21028,21149,21156,21168,21176,21183,21187,21189,21193,21197,21198,21204,21206,21207,21228,21306,21307,21319,21341,21343,21344,21588,21605,21626,21646,21647,21649,21654,21668,21673,21676,21714,21738,21739,21742,21743,21744,21752,21797,21839,21848,21858,21943,21946,21955,21957,22019,22070,22087,22093,22094,22128,22139,22150,22151,22160,22169,22170,22188,22190,22202,22207,22219,22249,22284,22298,22299,22301,22353,22359,22371,22382,22394,22417,22450,22452,22454,22456,22478,22481,22508,22521,22532,22549,22552,22708,22726,22735,22787,22794,22808,22815,22873,22893,22895,22909,22924,22959,23029,23066,23109,23119,23135,23162,23187,23258,23272,23281,23291,23386,23414,23438,23459,23478,23507,23531,23616,23638,23742,23754,23759,23760,23787,23789,23819,23826,23842,23884,23942,23983,23985,23996,23997,24000,24087,24135,24288,24417,24438,24529,24536,24545,24561,24572,24594,24683,24749,24949,24956,24966,25024,25057,25160,25164,25199,25246,25282,25303,25307,25326,25327,25359,25363,25367,25395,25531,25590,25604,25629,25659,25691,25712,25719,25726,25807,25813,25905,25912,25949,25951,25963,25993,26037,26080,26139,26147,26155,26198,26241,26246,26247,26251,26295,26380,26401,26405,26445,26476,26497,26518,26546,26558,26590,26641,26655,26700,26718,26742,26778,26807,26853,26879,26929,27007,27110,27215,27267,27289,27312,27380,27410,27414,27499,27544,27608,27634,27671,27698,27784,27791,27793,27797,27840,27902,27913,27916,27924,27925,27941,27962,28002,28028,28041,28170,28197,28213,28217,28270,28647,28648,28656,28675,28683,28714,28752,28879,28881,29042,29112,29335,29364,29376,29387,29409,29420,29727,29771,29830,29848,29926,29943,29955,29995,30023,30044,30105,30143,30249,30258,30310,30372,30391,30423,30487,30522,30570,30588,30609,30629,30656,30678,30741,30745,30983,31106,31118,31119,31177,31189,31223,31308,31368,31408,31437,31500,31560,31585,31617,31639,31752,31831,31940,31947,31986,32036,32087,32283,32347,32356,32373,32459,32505,32514,32722,32749,32779,32814,33293,33337,33395,33494,33514,33521,33554,33720,33807,33858,33883,34212,34344,34345,34420,34640,34727,34802,34971,34976,34984,35004,35116,35191,35193,35238,35311,35473,35478,35483,35489,35502,35527,35535,35609,35664,35737,35786,35844,35892,35912,35917,35931,35934,35952,35954,35963,35973,36017,36046,36114,36269,36309,36311,36380,36397,36399,36494,36517,36573,36678,36698,36820,36861,36931,36945,37086,37444,37550,37596,37613,37647,37756,37789,37844,37885,37908,37915,37983,38050,38067,38163,38167,38257,38283,38315,38320,38326,38428,38443,38475,38521,38677,38784,38803,38912,39102,39186,39266,39454,39540,39562,39573,39588,39756,39777,39818,39838,39842,40486,40487,40538,40773,41610,41652,41691,42029,42231,42326,42437,42453,42458,42468,42472,42484,42581,42624,42758,42760,42833,42875,42879,43213,43567,43638,44177,44194,44195,44449,44452,44503,44509,44538,44539,44540,44541,44542,44543,44570,44575,44580,44635,44666,44743,44745,44777,44780,44781,44798,44799,44816,44818,44820,44821,44828,44829,44830,44842,44843,44844,44925,45068,45077,45079,45082,45083,45086,45087,45088,45089,45092,45093,45094,45097,45098,45099,45100,45103,45120,45121,45122,45123,45124,45125,45131,45132,45134,45146,45147,45342,45353,45356,45358,45362,45364,45424,45481,45655,45662,45766,45767,45827,45828,45837,45850,45954,46280,46307,46316,46317,46320,46325,46345,46348,46352,46366,46430,46682,46694,46707,46905,46918,46929,47193,47195,47221,47307,47531,47587,47717,47720,47721,47728,47729,47782,47810,47820,47865,47876,47941,48234,48281,48313,48419,48446,48466,48536,48630,48635,48941,49182,49184,49213,49770,49773,49848,51278,51714,51741,51934,52259,52263,52497,52498,52558,52559,52561,52569,52570,52572,52573,52590,52604,52605,52609,53109,53272,53283,53317,53433,53457,53485,18136,18623,18637,21003,21511,21512,26583,29311,34005,34690,38240,38489,43406,43495,48418,48942,53198,22711,27684,45091,40621,29653,46912,51364);

-- E2. 沒有孤兒關聯(FK 有 CASCADE,這句是確認 CASCADE 真的生效)
SELECT bp.person_id, COUNT(*) FROM book_persons bp
  LEFT JOIN persons p ON p.person_id = bp.person_id
 WHERE p.person_id IS NULL GROUP BY bp.person_id;

-- E3. 角色詞不該再有獨立人名列
SELECT person_id, name FROM persons
 WHERE name IN ('文', '著', '作', '著者', '作者', '編著', '合著', '口述', '撰', '編', '主編', '編輯', '編者', '總編輯', '責任編輯', '校訂', '審訂', '選編', '彙編', '編撰', '編寫', '譯', '譯者', '翻譯', '編譯', '譯著', '圖', '繪', '繪圖', '插圖', '插畫', '漫畫', '攝影');

-- E4. persons.name 不該再有全形分號(U+FF1B);這一類要先跑 fix_person_names.php
SELECT person_id, name FROM persons
 WHERE name COLLATE utf8mb4_bin LIKE CONCAT('%', CHAR(0xEFBC9B USING utf8mb4), '%');

-- E5. persons.name 不該再有 HTML entity 殘骸
SELECT person_id, name FROM persons WHERE name REGEXP '&(#[0-9]+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]+);?';

-- E6. 清理前後的總量(記下來寫進 Asana)
SELECT (SELECT COUNT(*) FROM persons) AS persons_total,
       (SELECT COUNT(*) FROM book_persons) AS book_persons_total;

-- 以上全部確認後:
-- COMMIT;
-- 有任何一句回了列:ROLLBACK;
