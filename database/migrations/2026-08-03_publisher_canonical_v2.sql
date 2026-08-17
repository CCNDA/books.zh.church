-- 2026-08-03 出版社正名合併第二批(以琳 7/31 匯入後新增變體)+ 顯示層改走 canonical。
-- 起因:book 60857(logos,「新加坡證主協會」id 3406)與 75795(elim,「新加坡證主」id 4468)
--       同書同社卻顯示不同社名。canonical 機制(2026-07-16)已存在,本檔補新變體並讓
--       v_book_list 與詳情頁版本列表也顯示正規名。
-- 回滾:UPDATE publishers SET canonical_id=NULL WHERE publisher_id IN (4468);
--       view 可用 2026-07-16_fix_v_book_list_cover_perf.sql 重建回原版。

-- ── 1) 新變體 → canonical ───────────────────────────────────
UPDATE publishers SET canonical_id = 3406 WHERE publisher_id IN (4468);  -- 新加坡證主協會 ← 新加坡證主
-- (注意:香港「福音證主協會」為不同機構,不併入)

-- 保險:避免自我指向、或別名指向別名
UPDATE publishers SET canonical_id = NULL WHERE canonical_id = publisher_id;
UPDATE publishers p
  JOIN publishers c ON c.publisher_id = p.canonical_id AND c.canonical_id IS NOT NULL
   SET p.canonical_id = c.canonical_id;

-- ── 2) v_book_list:publisher 欄改顯示正規社名 ──────────────
-- 僅改寫 publisher 子查詢(多 JOIN 一層 canonical),其餘欄位與順序完全不變。
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
  coalesce((select cano.name_zh
            from editions e
            join publishers pub  on pub.publisher_id  = e.publisher_id
            join publishers cano on cano.publisher_id = coalesce(pub.canonical_id, pub.publisher_id)
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
