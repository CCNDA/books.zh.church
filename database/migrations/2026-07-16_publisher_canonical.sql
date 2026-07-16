-- 出版社正名合併(可逆):同一出版社的全稱/簡稱/「出版社」後綴變體,指到同一正規列。
-- 做法:publishers 加 canonical_id;變體列 canonical_id = 正規列 publisher_id;正規列 canonical_id 為 NULL。
-- canonical 選「群組內收錄書數最多」者(顯示名沿用該列 name_zh)。
-- 排除:X代理/總代理(代理關係,另欄處理)、X、Y(聯合出版,階段三多對多)、
--       神學院/醫院/書局/堂/會/中心/傳播 等不同機構。
-- 回滾:UPDATE publishers SET canonical_id=NULL;(全部還原)

ALTER TABLE publishers ADD COLUMN IF NOT EXISTS canonical_id INT UNSIGNED NULL
  COMMENT '正規出版社id;非NULL表本列為別名,指向正規列' AFTER name_en;
CREATE INDEX IF NOT EXISTS idx_pub_canonical ON publishers (canonical_id);

-- ── 各群組:UPDATE 變體 → canonical ──────────────────────────
UPDATE publishers SET canonical_id = 2748 WHERE publisher_id IN (2259);                 -- 校園書房 ← 校園書房出版社
UPDATE publishers SET canonical_id = 2810 WHERE publisher_id IN (2283, 3449);            -- 道聲 ← 道聲出版社/道聲出版
UPDATE publishers SET canonical_id = 2275 WHERE publisher_id IN (2754, 4114);            -- 天恩出版社 ← 天恩/天恩出版
UPDATE publishers SET canonical_id = 2743 WHERE publisher_id IN (2285);                  -- 基道 ← 基道出版社
UPDATE publishers SET canonical_id = 2749 WHERE publisher_id IN (2375, 2991);            -- 突破 ← 突破機構/突破出版社
UPDATE publishers SET canonical_id = 2755 WHERE publisher_id IN (2267, 3522);            -- 橄欖 ← 橄欖基金會/橄欖出版有限公司
UPDATE publishers SET canonical_id = 2768 WHERE publisher_id IN (2332);                  -- 宇宙光 ← 宇宙光出版社
UPDATE publishers SET canonical_id = 2745 WHERE publisher_id IN (2282);                  -- 基督教文藝 ← 基督教文藝出版社
UPDATE publishers SET canonical_id = 2763 WHERE publisher_id IN (2262);                  -- 光啟文化 ← 光啟文化事業
UPDATE publishers SET canonical_id = 2817 WHERE publisher_id IN (2265, 4021);            -- 天道書樓 ← 天道/天道出版社
UPDATE publishers SET canonical_id = 2838 WHERE publisher_id IN (3865);                  -- 漢語聖經協會 ← ...有限公司
UPDATE publishers SET canonical_id = 2800 WHERE publisher_id IN (2271);                  -- 明道社 ← 明道社有限公司
UPDATE publishers SET canonical_id = 2764 WHERE publisher_id IN (2465);                  -- 亮光文化 ← 亮光文化有限公司
UPDATE publishers SET canonical_id = 2248 WHERE publisher_id IN (3505);                  -- 環球聖經公會 ← ...有限公司
UPDATE publishers SET canonical_id = 2789 WHERE publisher_id IN (2289);                  -- 改革宗 ← 改革宗出版有限公司
UPDATE publishers SET canonical_id = 2840 WHERE publisher_id IN (2353);                  -- 上智 ← 上智文化事業
UPDATE publishers SET canonical_id = 3027 WHERE publisher_id IN (2279);                  -- 更新資源 ← 更新資源有限公司
UPDATE publishers SET canonical_id = 2968 WHERE publisher_id IN (2577);                  -- 種籽 ← 種籽出版社
UPDATE publishers SET canonical_id = 2325 WHERE publisher_id IN (2826, 3530);            -- 台灣基督教文藝出版社 ← 台灣基督教文藝/社
UPDATE publishers SET canonical_id = 2760 WHERE publisher_id IN (2350);                  -- 啟示 ← 啟示出版
UPDATE publishers SET canonical_id = 2848 WHERE publisher_id IN (2600);                  -- 天下文化 ← 天下文化出版公司
UPDATE publishers SET canonical_id = 2788 WHERE publisher_id IN (2587, 3708, 2925);      -- 商周 ← 商周文化事業/商周文化/商周出版
UPDATE publishers SET canonical_id = 2843 WHERE publisher_id IN (3996);                  -- 大塊文化 ← 大塊文化出版
UPDATE publishers SET canonical_id = 2775 WHERE publisher_id IN (3041);                  -- 遠流 ← 遠流出版
UPDATE publishers SET canonical_id = 2969 WHERE publisher_id IN (3621);                  -- 三聯書店 ← 三聯書店(香港)有限公司
UPDATE publishers SET canonical_id = 2287 WHERE publisher_id IN (3587);                  -- 宣道 ← 宣道出版社(僅出版性質;堂/書局/處/區聯會保留)
UPDATE publishers SET canonical_id = 2752 WHERE publisher_id IN (4205);                  -- 浸信會 ← 浸信會出版社(文字中心/大眾傳播保留)

-- 保險:避免自我指向或指向別名(canonical 必須是正規列)
UPDATE publishers SET canonical_id = NULL WHERE canonical_id = publisher_id;
