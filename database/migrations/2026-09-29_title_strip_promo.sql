-- ============================================================================
-- 2026-09-29_title_strip_promo.sql
-- 剝除書名裡的促銷詞:AUTO 24 筆 + 人工判定 9 筆,共 33 筆。
-- Asana 1218961224853655。熊哥 2026-09-29 裁示:AUTO 整批通過、HOLD 照建議、預購不納入。
--
-- ★ 每一句都帶 `AND title = '原書名'`:
--   對得上才改,對不上就是 0 列 —— 重跑安全,而且書名若已被別的工具動過會當場看出來。
--   Navicat 逐句回報影響列數,**33 句應該全部是 1**。哪一句是 0 就把那一句貼回來。
--
-- ★ 原書名不必另存 title_raw:import.php 的 `$extraRec = $raw`(整筆爬蟲原始紀錄)
--   已經存進 books.extra[來源],站方原始 title 一直在(第 3 段 (a) 驗它)。
--   多寫一份只是多一個要同步的地方 —— 本專案吃過「兩份資料要同步」的虧。
--
-- ★ 這 33 筆**不會被每日排程改回去**,兩道都查過原始碼、不是推測:
--   (1) import.php 合併時的 UPDATE 語句裡**沒有 title**(只 COALESCE 補空欄);
--   (2) 第 490 行 `if (isset($doneUrls[$m['source_url']])) continue;` ——
--       source_url 已存在的紀錄根本不會進到比對邏輯。
--
-- ★ 89749 是唯一一筆**改了促銷詞以外的字元**:原書名「…回憶錄 (簡）新書79折）」
--   括號半形開、全形關不對稱,剝完補成「(簡)」。其餘 32 筆只剝、不改任何字。
--
-- 執行順序:第 1 段看清單 → 第 2 段改 → 第 3 段驗 → 第 4 段在主機跑工具複驗。
-- ============================================================================


-- ─── 第 1 段:動手前先看一次要改哪 33 筆(唯讀) ──────────────────────────
SELECT b.book_id, b.title, GROUP_CONCAT(DISTINCT e.source) AS 來源, b.is_published
  FROM books b LEFT JOIN editions e ON e.book_id = b.book_id
 WHERE b.book_id IN (
          89740,89741,89534,89546,74750,79558,79620,79674,79675,80954,81038,87127,88945,89746,
          89748,89860,105503,105504,105505,105506,105517,96943,96945,98710,78006,76863,76882,
          87839,88355,89749,105223,83611,86540
 )
 GROUP BY b.book_id, b.title, b.is_published
 ORDER BY b.book_id;


-- ─── 第 2 段:改書名(33 句,每句應影響 1 列) ────────────────────────────
UPDATE books SET title = '跑贏魔鬼的女人' WHERE book_id = 89740 AND title = '跑贏魔鬼的女人(新書79折）';  -- AUTO
UPDATE books SET title = '聖經詩繹（繁體／平裝）' WHERE book_id = 89741 AND title = '聖經詩繹（繁體／平裝）(新書79折）';  -- AUTO
UPDATE books SET title = '2025年12月號宇宙光雜誌-聖誕特刊' WHERE book_id = 89534 AND title = '2025年12月號宇宙光雜誌-聖誕特刊(免運費)';  -- AUTO
UPDATE books SET title = '2024年12月號宇宙光雜誌-聖誕特刊' WHERE book_id = 89546 AND title = '2024年12月號宇宙光雜誌-聖誕特刊(免運費)';  -- AUTO
UPDATE books SET title = '傅立德牧師套裝7本(道聲出版)' WHERE book_id = 74750 AND title = '傅立德牧師套裝7本(道聲出版)優惠';  -- AUTO
UPDATE books SET title = '555收割禱告手冊(同工版)' WHERE book_id = 79558 AND title = '555收割禱告手冊(同工版)特價';  -- AUTO
UPDATE books SET title = '初信靈修30' WHERE book_id = 79620 AND title = '初信靈修30(特價品)';  -- AUTO
UPDATE books SET title = '禱告探訪手冊' WHERE book_id = 79674 AND title = '禱告探訪手冊(特價)';  -- AUTO
UPDATE books SET title = '釋放潔淨禱告手冊' WHERE book_id = 79675 AND title = '釋放潔淨禱告手冊(特價)';  -- AUTO
UPDATE books SET title = '教會名錄(2015、2016台閩地區)' WHERE book_id = 80954 AND title = '教會名錄(2015、2016台閩地區)特價';  -- AUTO
UPDATE books SET title = '教會名錄(2013、2014台閩地區)' WHERE book_id = 81038 AND title = '教會名錄(2013、2014台閩地區)特價';  -- AUTO
UPDATE books SET title = '寶寶奇妙之旅全套(1-5)' WHERE book_id = 87127 AND title = '寶寶奇妙之旅全套(1-5)【瑕疵特價商品】';  -- AUTO
UPDATE books SET title = '100個至愛聖經故事' WHERE book_id = 88945 AND title = '100個至愛聖經故事【瑕疵商品特價】';  -- AUTO
UPDATE books SET title = '聖經詩繹（簡體／精裝）' WHERE book_id = 89746 AND title = '聖經詩繹（簡體／精裝）(新書79折）';  -- AUTO
UPDATE books SET title = '聖經詩繹（簡體／平裝）' WHERE book_id = 89748 AND title = '聖經詩繹（簡體／平裝）(新書79折）';  -- AUTO
UPDATE books SET title = '每天多愛祢一點+每天多像祢一點' WHERE book_id = 89860 AND title = '每天多愛祢一點+每天多像祢一點 特價360元';  -- AUTO
UPDATE books SET title = '從跨性別到生命更新 : 一段帶來真自由的轉變故事' WHERE book_id = 105503 AND title = '從跨性別到生命更新 : 一段帶來真自由的轉變故事 (新書79折)';  -- AUTO
UPDATE books SET title = '普世教會歷史' WHERE book_id = 105504 AND title = '普世教會歷史（新書79折）';  -- AUTO
UPDATE books SET title = '不要再让我成为属灵的孤儿 : 一位宣教士从孤儿感走向儿子身份的生命见证' WHERE book_id = 105505 AND title = '不要再让我成为属灵的孤儿 : 一位宣教士从孤儿感走向儿子身份的生命见证（新書79折）';  -- AUTO
UPDATE books SET title = '布衣神僕的使命 : 當工作成為跨文化的呼召' WHERE book_id = 105506 AND title = '布衣神僕的使命 : 當工作成為跨文化的呼召（新書79折）';  -- AUTO
UPDATE books SET title = '上帝為什麼要創造世界(分析訓練)' WHERE book_id = 105517 AND title = '上帝為什麼要創造世界(分析訓練)（預購79折）';  -- AUTO
UPDATE books SET title = 'Good TV DVD' WHERE book_id = 96943 AND title = 'Good TV DVD 特價199元';  -- AUTO
UPDATE books SET title = 'Good TV DVD' WHERE book_id = 96945 AND title = 'Good TV DVD 特價99元';  -- AUTO
UPDATE books SET title = '小麥子作品套裝' WHERE book_id = 98710 AND title = '小麥子作品套裝優惠';  -- AUTO
UPDATE books SET title = '天國與財利' WHERE book_id = 78006 AND title = '天國與財利(特價不折)';  -- 人工
UPDATE books SET title = '瘟疫是讓人學習彼此相愛-利未記與瘟疫學' WHERE book_id = 76863 AND title = '(限量特價)瘟疫是讓人學習彼此相愛-利未記與瘟疫學';  -- 人工
UPDATE books SET title = '寇世遠研經集-全套50本' WHERE book_id = 76882 AND title = '寇世遠研經集-全套50本合購優惠';  -- 人工
UPDATE books SET title = '跟耶穌學安靜：戒除空虛的忙碌，活出輕省負軛的人生' WHERE book_id = 87839 AND title = '跟耶穌學安靜：戒除空虛的忙碌，活出輕省負軛的人生(可另選合購優惠:誰偷走了我的平安?)';  -- 人工
UPDATE books SET title = '復興每一天2024年3-5月' WHERE book_id = 88355 AND title = '【組合優惠】復興每一天2024年3-5月';  -- 人工
UPDATE books SET title = '春江日月明：八十年代回憶錄 (簡)' WHERE book_id = 89749 AND title = '春江日月明：八十年代回憶錄 (簡）新書79折）';  -- 人工
UPDATE books SET title = 'Suối Nguồn Trong Hoang Mạc 荒漠甘泉(越文版)' WHERE book_id = 105223 AND title = 'Suối Nguồn Trong Hoang Mạc 荒漠甘泉(越文版)(預購79折，十月中旬出版）';  -- 人工
UPDATE books SET title = '教會詩班頌讚集1' WHERE book_id = 83611 AND title = '教會詩班頌讚集1【書內有黃斑微瑕疵，不影響內文閱讀】';  -- 人工
UPDATE books SET title = '從藝術到靈性' WHERE book_id = 86540 AND title = '從藝術到靈性 (**注意：書有瑕疵，介意者請不要下單）';  -- 人工


-- ─── 第 3 段:驗證(唯讀,改完一定要跑) ──────────────────────────────────
-- (a) 站方原始書名還在不在 extra 裡?33 本都應該有值;有 NULL 就回報,不要當沒事。
SELECT b.book_id, b.title AS 現在的書名,
       JSON_UNQUOTE(JSON_EXTRACT(b.extra, CONCAT('$.', e.source, '.title'))) AS extra裡的原書名
  FROM books b
  JOIN editions e ON e.book_id = b.book_id
 WHERE b.book_id IN (
          89740,89741,89534,89546,74750,79558,79620,79674,79675,80954,81038,87127,88945,89746,
          89748,89860,105503,105504,105505,105506,105517,96943,96945,98710,78006,76863,76882,
          87839,88355,89749,105223,83611,86540
 )
 ORDER BY b.book_id;

-- (b) 全庫還剩幾本書名帶白名單促銷詞?
--     預期 **15 本**(命中 48 本 − 已處理 33 本),就是還沒處理的 HOLD。
--     不是 15 就有句沒跑到,或我算錯,停下來對。
SELECT COUNT(*) AS 還帶促銷詞的書數
  FROM books
 WHERE title REGEXP '[0-9]+[[:space:]]*折|特價|免運|瑕疵|優惠|限時';

-- (c) ★ 剝完之後這 33 本有沒有跟站上別本同名?這是**候選**,不是合併清單。
--     合併一律走 find_*_duplicates → 人工複核 → merge_duplicate_books.php --pairs。
--     已知《禱告探訪手冊》站上有三本(79674、45452、83271),而 45452 與 83271
--     書名本來就乾淨卻也沒併 —— **剝掉促銷詞不等於併得起來**,不可依這段直接併。
SELECT b.title, COUNT(*) AS 幾本, GROUP_CONCAT(b.book_id ORDER BY b.book_id) AS book_ids
  FROM books b
 WHERE b.title IN (
       SELECT title FROM books
        WHERE book_id IN (
          89740,89741,89534,89546,74750,79558,79620,79674,79675,80954,81038,87127,88945,89746,
          89748,89860,105503,105504,105505,105506,105517,96943,96945,98710,78006,76863,76882,
          87839,88355,89749,105223,83611,86540
        )
 )
 GROUP BY b.title
HAVING COUNT(*) > 1
 ORDER BY b.title;


-- ─── 第 4 段:主機上再跑一次盤點工具(不是 SQL,貼在 SSH) ─────────────────
--   php tools/check_title_promo.php
--   預期:AUTO **0**、HOLD 15、EXCLUDED 70、SQL 撈到 118(總數不變)
--   ★ AUTO 不是 0 → 有句沒跑到或跑錯。停下來回報,不要繼續往下做去重。
