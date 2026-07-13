-- 005: 書籍點擊追蹤(首頁「本月熱門」統計)
-- 決議 2026-07-13:首頁增「新進書」「本月熱門」;熱門=當月點擊次數排序
-- 隱私:只記 book_id/時間/來源/搜尋詞,不記 IP、不記使用者

CREATE TABLE IF NOT EXISTS book_clicks (
  click_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id    INT UNSIGNED    NOT NULL,
  clicked_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  source     VARCHAR(20)     NOT NULL DEFAULT 'list',  -- list/search/home/detail
  q          VARCHAR(200)    NULL,                     -- 點擊當下的搜尋關鍵字
  PRIMARY KEY (click_id),
  KEY idx_month_book (clicked_at, book_id),
  KEY idx_book (book_id),
  CONSTRAINT fk_clicks_book FOREIGN KEY (book_id)
    REFERENCES books (book_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
