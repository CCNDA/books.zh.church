-- 作者欄書目尾巴：v1.19.4 規則不敢動、留給人工的那幾筆（Asana 1219676923286530 第 1 項）
-- 裁示：熊哥 2026-10-21 TG——pc-merge-A（訊息 1065）、tp-merge-A（1070）；其餘四本照 10/21 票上建議（重複髒名字直接刪）
-- 這幾本書都已經有乾淨的同一個人，髒名字是重複多出來的 → 刪髒的即可；
-- 只有 57500 沒有乾淨的「朱怡康」，先把那一列改掛到既有的 33927 再刪。
--   43525 觀想耶穌        42169「張令憙 發行商：… ISBN：…」       已有 34224 張令憙（translator）
--   57500 上主，人生為何要受苦？ 42113「朱怡康 發行商：… 書號：…」 改掛 33927 朱怡康（translator）
--   45119 猶太戰爭史      41730「夫拉維．約瑟夫 (…) 出版：…」     已有 20645 夫拉維．約瑟夫（author）
--   86026 聖經透析        41916～41919 四個殘段                    已有 29515／31971／49890／29517 四位作者
--   46464 故事聖經        41582「邦妮…繪者：派屈克…出版：…」      已有 21391 邦妮．芮克．詹森（author）
--         繪者 39550「派屈克柯里根」原本誤掛成 author → 改名「派屈克．柯里根（Patrick Corrigan）」、角色改 illustrator（來源寫「繪者：」）
--   90117 從上頭得力的祕訣 45137「美國見證出版社編輯部 (…)ISBN：…」  改掛既有 44683「美國見證出版社 編輯委員會 (Testimony Publications)」（translator）
-- ★ book_persons 對 persons 有 ON DELETE CASCADE。

START TRANSACTION;

-- A0. 名字核對（應回 10）：任何一個 id 對不上名字就停下來 ROLLBACK
SELECT COUNT(*) AS 名字對得上_應為10 FROM persons WHERE
     (person_id = 42169 AND name LIKE '張令憙 發行商%9786267272077')
  OR (person_id = 42113 AND name LIKE '朱怡康 發行商%9786267272152')
  OR (person_id = 41730 AND name LIKE '夫拉維．約瑟夫 (Flavius Josephus) 出版%')
  OR (person_id = 41916 AND name = '(舊約：) 比爾．阿諾德')
  OR (person_id = 41917 AND name = '布賴恩．拜爾 (新約：) 沃爾特．埃爾韋爾')
  OR (person_id = 41918 AND name = '羅伯特．亞伯勒 (Bill Arnold, Bryan Beyer')
  OR (person_id = 41919 AND name LIKE 'Walter Elwell, Robert Ya)%')
  OR (person_id = 41582 AND name LIKE '邦妮．芮克．詹森（Bonnie Rickner Jensen） 繪者%')
  OR (person_id = 45137 AND name LIKE '美國見證出版社編輯部 (Testimony Publications)ISBN%')
  OR (person_id = 39550 AND name = '派屈克柯里根');
-- A1. 這 7 個髒名字只掛在預期的書上（應回 0 列）
SELECT person_id, book_id FROM book_persons
 WHERE person_id IN (42169,42113,41730,41916,41917,41918,41919,41582,45137)
   AND NOT ((person_id = 42169 AND book_id = 43525) OR (person_id = 42113 AND book_id = 57500)
         OR (person_id = 41730 AND book_id = 45119) OR (person_id IN (41916,41917,41918,41919) AND book_id = 86026)
         OR (person_id = 41582 AND book_id = 46464) OR (person_id = 45137 AND book_id = 90117));

-- B. 57500 的譯者改掛既有的朱怡康
UPDATE book_persons SET person_id = 33927, credit_text = '朱怡康'
 WHERE book_id = 57500 AND person_id = 42113 AND role = 'translator';

-- B2. 90117 的譯者改掛既有的「美國見證出版社 編輯委員會」
UPDATE book_persons SET person_id = 44683, credit_text = '美國見證出版社編輯部 (Testimony Publications)'
 WHERE book_id = 90117 AND person_id = 45137 AND role = 'translator';

-- B3. 46464 的繪者：改名、角色由 author 改 illustrator
UPDATE persons SET name = '派屈克．柯里根（Patrick Corrigan）' WHERE person_id = 39550 AND name = '派屈克柯里根';
UPDATE book_persons SET role = 'illustrator', role_order = 0
 WHERE book_id = 46464 AND person_id = 39550 AND role = 'author';

-- C. 刪髒名字
DELETE FROM persons WHERE person_id IN (42169,42113,41730,41916,41917,41918,41919,41582,45137);

-- E1. 應回 0 列
SELECT person_id FROM persons WHERE person_id IN (42169,42113,41730,41916,41917,41918,41919,41582,45137);
-- E2. 六本書的作者／譯者／繪者還在（應回 6 列；46464 繪者 1、90117 譯者 1、其餘同上）
SELECT book_id,
       SUM(role = 'author') AS 作者, SUM(role = 'translator') AS 譯者, SUM(role = 'illustrator') AS 繪者
  FROM book_persons WHERE book_id IN (43525,45119,57500,86026,46464,90117) GROUP BY book_id;
-- E3. 改掛／改角色都到位（應回 3 列）
SELECT book_person_id, book_id, person_id, role FROM book_persons
 WHERE (book_id = 57500 AND person_id = 33927 AND role = 'translator')
    OR (book_id = 90117 AND person_id = 44683 AND role = 'translator')
    OR (book_id = 46464 AND person_id = 39550 AND role = 'illustrator');

INSERT INTO schema_migrations (version, applied_at, note) VALUES
  ('2026-10-21_person_manual_tail.sql', NOW(), '人工列：刪 9 個髒名字（6 本），57500／90117 譯者改掛、46464 繪者改名改角色')
ON DUPLICATE KEY UPDATE note = VALUES(note);

-- A0 回 10、A1／E1 0 列、E2 6 列、E3 3 列：COMMIT;   有任何一句不對：ROLLBACK;
