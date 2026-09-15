-- 麥種(akow)購書連結加註正體/簡體(2026-09-15)
--
-- ═══ 為什麼需要 ═══
-- 9/14 線上抽查 book_id 60911《麥種基督教要義》,底下掛著**兩條一模一樣的
-- 「麥種傳道會」購書連結** —— 那是正體版與簡體版(站方給了同一個 ISBN,
-- 因此併成一本書的兩個版本),但使用者看到兩個同名按鈕,分不出哪個是簡體。
-- 影響:簡繁共用 ISBN 的 4 本書(麥種基督教要義/伯克富系統神學/
--       聖經的偉大教義/基督徒的信仰)會出現同名雙按鈕。
--
-- import.php 已於 9/14 加上標註邏輯,但那 144 條連結是**加之前**建的,
-- 重跑 import 也救不回來(以 source_url 判重會整筆跳過),故需本次一次性更新。
--
-- ═══ 為什麼可以用 SQL、而且只動 links ═══
-- api/index.php 的購書彙整以 **URL 去重**,且 links 先處理、books.buy_links 後處理:
--     $addBuy($r['platform'], ...)          // links(正規層)先入
--     if (isset($buy[$url])) return;        // 同 URL 的 buy_links 那筆被擋掉
-- 且 books.buy_links 的 platform 存的是來源代碼 'akow'(非顯示名,電子書才另存 label)。
-- → **顯示名稱只認 links.platform**,buy_links 不必動。
-- 另本次以 editions.source_url 精準定位(不是字串替換),簡繁兩條可以分開處理。
--
-- ═══ 簡體版判定 ═══
-- 下列 9 筆由 akow.org Store API 實查(商品名含「簡體/简体/(简)」或簡化字),
-- 與爬蟲 guess_script() 的判定一致。其餘 135 筆一律正體。

-- 1) 先全部標正體(只動還沒標註過的,可重跑)
UPDATE links l
  JOIN editions e ON e.edition_id = l.edition_id
   SET l.platform = '麥種傳道會(正體)'
 WHERE e.source = 'akow' AND l.link_type = 'buy' AND l.platform = '麥種傳道會';

-- 2) 再把 9 筆簡體版覆蓋掉
UPDATE links l
  JOIN editions e ON e.edition_id = l.edition_id
   SET l.platform = '麥種傳道會(簡體)'
 WHERE e.source = 'akow' AND l.link_type = 'buy'
   AND e.source_url IN (
  'https://akow.org/product/%e8%80%b6%e7%a9%8c%e6%89%80%e5%82%b3%e7%9a%84%e7%a6%8f%e9%9f%b3%e7%b0%a1%e9%ab%94/',
  'https://akow.org/product/%e6%9c%80%e4%bc%9f%e5%a4%a7%e7%9a%84%e6%95%85%e4%ba%8b%ef%bc%88%e7%ae%80%e4%bd%93%ef%bc%89/',
  'https://akow.org/product/%e5%9c%a3%e7%bb%8f%e7%9a%84%e4%bc%9f%e5%a4%a7%e6%95%99%e4%b9%89/',
  'https://akow.org/product/%e9%ba%a5%e7%a8%ae%e5%9f%ba%e7%9d%a3%e6%95%99%e8%a6%81%e7%be%a9%ef%bc%88%e7%b0%a1%e9%ab%94%ef%bc%89/',
  'https://akow.org/product/%e4%bc%af%e5%85%8b%e5%af%8c%e7%b3%bb%e7%b5%b1%e7%a5%9e%e5%ad%b8%ef%bc%88%e7%b0%a1%e9%ab%94%ef%bc%89/',
  'https://akow.org/product/%e5%9f%ba%e7%9d%a3%e5%be%92%e7%9a%84%e4%bf%a1%e4%bb%b0%ef%bc%88%e7%b0%a1%ef%bc%89/',
  'https://akow.org/product/%e7%89%a7%e5%b8%88%e5%85%ac%e4%bc%97%e7%a5%9e%e5%ad%a6%e5%ae%b6%e7%ae%80/',
  'https://akow.org/product/%e5%9c%a3%e7%bb%8f%e4%ba%ba%e7%89%a9%e7%b4%a0%e6%8f%8f%e4%b8%8b%e7%ae%80%e4%bd%93/',
  'https://akow.org/product/%e6%97%a7%e7%ba%a6%e5%85%88%e7%9f%a5%e4%b9%a6%e5%af%bc%e8%ae%ba%e7%ae%80%e4%bd%93/'
);

-- ═══ 驗證(三條都要跑)═══

-- 驗證一:標註分布 —— 應為 正體 135、簡體 9,且「麥種傳道會」(無標註)為 0
SELECT l.platform, COUNT(*) AS 連結數
  FROM links l JOIN editions e ON e.edition_id = l.edition_id
 WHERE e.source = 'akow' AND l.link_type = 'buy'
 GROUP BY l.platform ORDER BY 連結數 DESC;

-- 驗證二:簡繁共用 ISBN 那 4 本,底下兩條連結應一正一簡(不再同名)
SELECT b.book_id, b.title, l.platform, e.source_url
  FROM books b
  JOIN editions e ON e.book_id = b.book_id AND e.source = 'akow'
  JOIN links    l ON l.edition_id = e.edition_id AND l.link_type = 'buy'
 WHERE b.book_id IN (
       SELECT e2.book_id FROM editions e2 WHERE e2.source = 'akow'
        GROUP BY e2.book_id HAVING COUNT(*) > 1)
 ORDER BY b.book_id, l.platform;

-- 驗證三:確認 books.buy_links 未被動到(platform 應仍是來源代碼 akow)
SELECT COUNT(*) AS 仍為來源代碼的筆數 FROM books
 WHERE buy_links LIKE '%"platform":"akow"%';
