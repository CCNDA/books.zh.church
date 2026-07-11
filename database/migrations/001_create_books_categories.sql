-- 屬靈共同書目 MVP schema(簡化版:books + categories)
-- 在主機 phpMyAdmin / cPanel MySQL 執行;資料庫需為 utf8mb4
-- 之後階段三才遷移至 Work/Edition 正規化架構,本檔不可破壞既有資料

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
  category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(10)  NOT NULL COMMENT 'CategoryV11 編號,如 A0000',
  name        VARCHAR(100) NOT NULL COMMENT '類別名稱',
  sort_order  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (category_id),
  UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS books (
  book_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title        VARCHAR(255) NOT NULL COMMENT '書名',
  subtitle     VARCHAR(255) NULL,
  author       VARCHAR(255) NULL COMMENT '作者(多人以、分隔)',
  translator   VARCHAR(255) NULL COMMENT '譯者',
  publisher    VARCHAR(100) NULL COMMENT '出版社',
  publish_date VARCHAR(10)  NULL COMMENT 'YYYY-MM 或 YYYY',
  isbn13       VARCHAR(13)  NULL,
  isbn10       VARCHAR(10)  NULL,
  category_id  INT UNSIGNED NULL,
  summary      TEXT         NULL COMMENT '簡介(80-150 字)',
  cover_url    VARCHAR(500) NULL COMMENT '封面圖網址或站內路徑',
  buy_links    TEXT         NULL COMMENT 'JSON:[{"platform":"校園書房","url":"..."}]',
  source       VARCHAR(50)  NULL COMMENT '資料來源(seed/campus/logos/manual)',
  is_published TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (book_id),
  KEY idx_title (title),
  KEY idx_author (author),
  KEY idx_publisher (publisher),
  KEY idx_isbn13 (isbn13),
  KEY idx_category (category_id),
  CONSTRAINT fk_books_category FOREIGN KEY (category_id)
    REFERENCES categories (category_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
