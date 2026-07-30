-- 加速 /api/books?publisher=<id> 出版社篩選,修正大出版社查詢 504 Timeout。
-- 症狀:天恩出版社(408 本)等大出版社點進去卡「載入中」→ 504。
-- 根因:editions.publisher_id 無索引,EXISTS 半連結無法由 publisher_id 驅動,
--       只能全表掃描/物化 v_book_list;作者篩選(book_persons.person_id 有索引)則快。
-- 修法:補索引,讓出版社篩選比照作者篩選由索引驅動。
CREATE INDEX IF NOT EXISTS idx_editions_publisher ON editions (publisher_id);
