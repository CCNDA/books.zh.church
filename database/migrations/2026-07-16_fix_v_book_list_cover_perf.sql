-- 修正 v_book_list 封面子查詢效能(出版社/大結果集篩選 504 的真因)。
-- 原封面子查詢用 (m.book_id=b.book_id OR m.edition_id IN (...)),OR+子查詢使 media 無法用索引,
-- 每筆候選書都對 media 全表掃描 41k + filesort → 結果集大就 504。
-- 修法:改成 media JOIN editions(封面一律掛 edition 層,m.book_id 分支實務上為 NULL 不影響),
-- 並補索引讓子查詢走索引、免全掃免 filesort。此修一次加速所有 /api/books 篩選與搜尋。

-- 1) 支援封面子查詢的索引(以 edition_id 為主,含 media_type/is_primary/media_id 供排序取首張)
CREATE INDEX IF NOT EXISTS idx_media_cover ON media (edition_id, media_type, is_primary, media_id);

-- 2) 重建 view:僅改寫 cover_url 子查詢,其餘欄位與順序完全不變
CREATE OR REPLACE VIEW v_book_list AS
SELECT
  b.book_id       AS book_id,
  b.title         AS title,
  b.subtitle      AS subtitle,
  b.original_title AS original_title,
  coalesce((select group_concat(p.name order by bp.role_order separator '、')
            from book_persons bp join persons p on p.person_id = bp.person_id
            where bp.book_id = b.book_id and bp.role = 'author'), b.author) AS author,
  coalesce((select group_concat(p.name order by bp.role_order separator '、')
            from book_persons bp join persons p on p.person_id = bp.person_id
            where bp.book_id = b.book_id and bp.role = 'translator'), b.translator) AS translator,
  coalesce((select pub.name_zh from editions e join publishers pub on pub.publisher_id = e.publisher_id
            where e.book_id = b.book_id order by e.edition_id limit 1), b.publisher) AS publisher,
  coalesce((select e.publish_date from editions e
            where e.book_id = b.book_id and e.publish_date is not null
            order by e.publish_date desc limit 1), b.publish_date) AS publish_date,
  coalesce((select i.id_value from identifiers i join editions e on e.edition_id = i.edition_id
            where e.book_id = b.book_id and i.id_type = 'ISBN13'
            order by i.identifier_id limit 1), b.isbn13) AS isbn13,
  coalesce((select m.url_or_path from media m join editions e2 on e2.edition_id = m.edition_id
            where e2.book_id = b.book_id and m.media_type = 'cover'
            order by m.is_primary desc, m.media_id limit 1), b.cover_url) AS cover_url,
  b.summary_short AS summary_short,
  b.summary       AS summary,
  b.category_id   AS category_id,
  b.source        AS source,
  b.is_published  AS is_published,
  b.created_at    AS created_at
FROM books b;

-- 3)(可選)移除今日誤加的重複索引(與既有 idx_publisher 重複)
-- DROP INDEX idx_editions_publisher ON editions;
