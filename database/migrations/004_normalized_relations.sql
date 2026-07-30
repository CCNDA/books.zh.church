-- 004:正規化關聯結構(依 整合_完整書籍資料庫模板 14 表 / database_schema.svg)
-- 於 003 之後執行。books 留原欄位作為過渡後備(fallback),權威資料改存關聯表。
-- 匯入器(7/12 起)直接寫入關聯表;v_book_list 檢視表讓 API 兩者皆可讀。

SET NAMES utf8mb4;

-- ── 人物與角色(多作者/譯者/插畫/序/顧問/校對) ──────────────
CREATE TABLE IF NOT EXISTS persons (
  person_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(150) NOT NULL,
  name_en    VARCHAR(150) NULL,
  aka        VARCHAR(255) NULL COMMENT '別名/常用譯名(;分隔)',
  bio        TEXT NULL,
  website    VARCHAR(500) NULL,
  PRIMARY KEY (person_id),
  KEY idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS book_persons (
  book_person_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id     INT UNSIGNED NOT NULL,
  person_id   INT UNSIGNED NOT NULL,
  role        VARCHAR(20)  NOT NULL COMMENT 'author/editor/translator/illustrator/foreword/advisor/proofreader/contributor',
  role_order  INT NOT NULL DEFAULT 0 COMMENT '同角色多人排序',
  credit_text VARCHAR(255) NULL COMMENT '封面署名原文(保留排版/全形)',
  PRIMARY KEY (book_person_id),
  UNIQUE KEY uq_book_person_role (book_id, person_id, role),
  KEY idx_person (person_id),
  CONSTRAINT fk_bp_book   FOREIGN KEY (book_id)   REFERENCES books (book_id)     ON DELETE CASCADE,
  CONSTRAINT fk_bp_person FOREIGN KEY (person_id) REFERENCES persons (person_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 出版者 ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS publishers (
  publisher_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name_zh    VARCHAR(150) NOT NULL,
  name_en    VARCHAR(150) NULL,
  org_ref_id VARCHAR(50)  NULL COMMENT '教會機構名錄機構ID',
  website    VARCHAR(500) NULL,
  contact    VARCHAR(255) NULL,
  PRIMARY KEY (publisher_id),
  UNIQUE KEY uq_name_zh (name_zh)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 版本(一書多版:不同 ISBN/年份/裝幀) ─────────────────
CREATE TABLE IF NOT EXISTS editions (
  edition_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id    INT UNSIGNED NOT NULL,
  publisher_id INT UNSIGNED NULL,
  edition_statement VARCHAR(100) NULL COMMENT '初版/修訂版/再版',
  publish_date VARCHAR(10) NULL COMMENT 'YYYY / YYYY-MM / YYYY-MM-DD',
  place_of_publication VARCHAR(100) NULL,
  print_run  VARCHAR(50) NULL COMMENT '印次/刷次',
  page_count INT UNSIGNED NULL,
  binding    VARCHAR(50) NULL,
  dimensions VARCHAR(50) NULL,
  weight_g   INT UNSIGNED NULL,
  copyright  VARCHAR(255) NULL,
  source     VARCHAR(50) NULL COMMENT '資料來源(campus/logos/manual/publisher)',
  source_url VARCHAR(500) NULL COMMENT '來源頁面(標注出處)',
  PRIMARY KEY (edition_id),
  KEY idx_book (book_id),
  KEY idx_publisher (publisher_id),
  CONSTRAINT fk_ed_book FOREIGN KEY (book_id) REFERENCES books (book_id) ON DELETE CASCADE,
  CONSTRAINT fk_ed_pub  FOREIGN KEY (publisher_id) REFERENCES publishers (publisher_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 識別碼(一版多碼:ISBN13/ISBN10/EAN/ISSN/DOI) ────────
CREATE TABLE IF NOT EXISTS identifiers (
  identifier_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  edition_id INT UNSIGNED NOT NULL,
  id_type    VARCHAR(10) NOT NULL COMMENT 'ISBN13/ISBN10/EAN/UPC/ISSN/DOI',
  id_value   VARCHAR(30) NOT NULL,
  note       VARCHAR(100) NULL COMMENT '例:精裝/平裝對應',
  PRIMARY KEY (identifier_id),
  UNIQUE KEY uq_type_value_edition (edition_id, id_type, id_value),
  KEY idx_value (id_value),
  CONSTRAINT fk_id_edition FOREIGN KEY (edition_id) REFERENCES editions (edition_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 系列(一書可屬多系列) ───────────────────────────────
CREATE TABLE IF NOT EXISTS series (
  series_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  series_name VARCHAR(150) NOT NULL,
  series_note TEXT NULL,
  PRIMARY KEY (series_id),
  UNIQUE KEY uq_series_name (series_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS book_series (
  book_series_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id   INT UNSIGNED NOT NULL,
  series_id INT UNSIGNED NOT NULL,
  series_number VARCHAR(20) NULL,
  PRIMARY KEY (book_series_id),
  UNIQUE KEY uq_book_series (book_id, series_id),
  CONSTRAINT fk_bs_book   FOREIGN KEY (book_id)   REFERENCES books (book_id)   ON DELETE CASCADE,
  CONSTRAINT fk_bs_series FOREIGN KEY (series_id) REFERENCES series (series_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 主題/分類(可混用多套系統:CategoryV11/中圖/來源站分類) ──
CREATE TABLE IF NOT EXISTS subjects (
  subject_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scheme VARCHAR(20) NOT NULL COMMENT 'categoryv11/campus/logos/ddc/custom',
  code   VARCHAR(20) NULL,
  label  VARCHAR(150) NOT NULL,
  PRIMARY KEY (subject_id),
  UNIQUE KEY uq_scheme_code_label (scheme, code, label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS book_subjects (
  book_subject_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id    INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  weight     INT NOT NULL DEFAULT 0,
  PRIMARY KEY (book_subject_id),
  UNIQUE KEY uq_book_subject (book_id, subject_id),
  KEY idx_subject (subject_id),
  CONSTRAINT fk_bsub_book    FOREIGN KEY (book_id)    REFERENCES books (book_id)      ON DELETE CASCADE,
  CONSTRAINT fk_bsub_subject FOREIGN KEY (subject_id) REFERENCES subjects (subject_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 格式與價格(一版多格式:平裝/精裝/電子/有聲,各有價) ────
CREATE TABLE IF NOT EXISTS formats_prices (
  format_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  edition_id INT UNSIGNED NOT NULL,
  media_type VARCHAR(10) NOT NULL COMMENT 'print/ebook/audio',
  file_format VARCHAR(20) NULL COMMENT 'EPUB/PDF/MP3',
  sku        VARCHAR(50) NULL,
  price      DECIMAL(10,2) NULL,
  currency   VARCHAR(5) NULL COMMENT 'TWD/HKD/USD',
  availability VARCHAR(50) NULL COMMENT '庫存/上架狀態',
  PRIMARY KEY (format_id),
  KEY idx_edition (edition_id),
  CONSTRAINT fk_fp_edition FOREIGN KEY (edition_id) REFERENCES editions (edition_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 連結(購書/介紹/影片;可掛作品層或版本層) ─────────────
CREATE TABLE IF NOT EXISTS links (
  link_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id    INT UNSIGNED NULL,
  edition_id INT UNSIGNED NULL,
  link_type VARCHAR(20) NOT NULL COMMENT 'buy/official/ebook/audio/intro/video/social',
  platform  VARCHAR(50) NULL COMMENT '校園書房/基道/博客來/YouTube',
  url       VARCHAR(700) NOT NULL,
  note      VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (link_id),
  KEY idx_book (book_id),
  KEY idx_edition (edition_id),
  CONSTRAINT fk_lk_book    FOREIGN KEY (book_id)    REFERENCES books (book_id)     ON DELETE CASCADE,
  CONSTRAINT fk_lk_edition FOREIGN KEY (edition_id) REFERENCES editions (edition_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 媒體(封面/內頁;封面通常綁版本) ─────────────────────
CREATE TABLE IF NOT EXISTS media (
  media_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id    INT UNSIGNED NULL,
  edition_id INT UNSIGNED NULL,
  media_type VARCHAR(20) NOT NULL COMMENT 'cover/inside/promo',
  url_or_path VARCHAR(700) NOT NULL COMMENT 'R2 公開網址或來源 URL',
  caption   VARCHAR(255) NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  source_url VARCHAR(700) NULL COMMENT '原始來源(標注出處)',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (media_id),
  KEY idx_book (book_id),
  KEY idx_edition (edition_id),
  CONSTRAINT fk_md_book    FOREIGN KEY (book_id)    REFERENCES books (book_id)     ON DELETE CASCADE,
  CONSTRAINT fk_md_edition FOREIGN KEY (edition_id) REFERENCES editions (edition_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 書評/推薦 ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reviews (
  review_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id   INT UNSIGNED NOT NULL,
  source    VARCHAR(100) NULL COMMENT '媒體/平台/個人',
  rating    DECIMAL(3,1) NULL,
  quote     VARCHAR(500) NULL,
  content   TEXT NULL,
  url       VARCHAR(700) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (review_id),
  KEY idx_book (book_id),
  CONSTRAINT fk_rv_book FOREIGN KEY (book_id) REFERENCES books (book_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 檢視表:API 讀取用(關聯優先,無關聯資料時退回 books 平面欄位) ──
CREATE OR REPLACE VIEW v_book_list AS
SELECT
  b.book_id,
  b.title,
  b.subtitle,
  b.original_title,
  COALESCE(
    (SELECT GROUP_CONCAT(p.name ORDER BY bp.role_order SEPARATOR '、')
     FROM book_persons bp JOIN persons p ON p.person_id = bp.person_id
     WHERE bp.book_id = b.book_id AND bp.role = 'author'),
    b.author) AS author,
  COALESCE(
    (SELECT GROUP_CONCAT(p.name ORDER BY bp.role_order SEPARATOR '、')
     FROM book_persons bp JOIN persons p ON p.person_id = bp.person_id
     WHERE bp.book_id = b.book_id AND bp.role = 'translator'),
    b.translator) AS translator,
  COALESCE(
    (SELECT pub.name_zh
     FROM editions e JOIN publishers pub ON pub.publisher_id = e.publisher_id
     WHERE e.book_id = b.book_id ORDER BY e.edition_id ASC LIMIT 1),
    b.publisher) AS publisher,
  COALESCE(
    (SELECT e.publish_date FROM editions e
     WHERE e.book_id = b.book_id AND e.publish_date IS NOT NULL
     ORDER BY e.publish_date DESC LIMIT 1),
    b.publish_date) AS publish_date,
  COALESCE(
    (SELECT i.id_value
     FROM identifiers i JOIN editions e ON e.edition_id = i.edition_id
     WHERE e.book_id = b.book_id AND i.id_type = 'ISBN13'
     ORDER BY i.identifier_id ASC LIMIT 1),
    b.isbn13) AS isbn13,
  COALESCE(
    (SELECT m.url_or_path FROM media m
     WHERE (m.book_id = b.book_id
            OR m.edition_id IN (SELECT e2.edition_id FROM editions e2 WHERE e2.book_id = b.book_id))
       AND m.media_type = 'cover'
     ORDER BY m.is_primary DESC, m.media_id ASC LIMIT 1),
    b.cover_url) AS cover_url,
  b.summary_short,
  b.summary,
  b.category_id,
  b.source,
  b.is_published,
  b.created_at
FROM books b;
