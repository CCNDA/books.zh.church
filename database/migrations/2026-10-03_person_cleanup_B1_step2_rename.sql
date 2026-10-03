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

-- ═══ 第 2 段:剝乾淨但沒有既有正規列,直接改名(337 列)═══
-- ★ 前提:第 1 段已經 COMMIT。
-- 預期:persons **35934 不變**(改名不增不減);book_persons 也不變。

START TRANSACTION;

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

-- ═══ 回查(COMMIT 之前跑,**跟這個檔在同一個查詢視窗**) ═══
SELECT (SELECT COUNT(*) FROM persons)      AS persons_total,
       (SELECT COUNT(*) FROM book_persons) AS book_persons_total;
