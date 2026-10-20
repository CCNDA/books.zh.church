"""從審核清單＋check_person_names 產的全庫 SQL，挑出指定 person 的清理 SQL（2026-10-20 作者欄書目尾巴，Asana 1219613623198535）。
用法：python3 gen_pn_sql.py <審核 TSV> <全庫 SQL> <輸出 SQL>
規則（熊哥 2026-10-20 TG 裁示 944～947）：
  刪（不是人）→ A 段原句；併到既有同名列 → B 段原句（41456 哥本哈根出版社例外，改成刪）；改名 → C 段原句；
  重切／人工 → 不產 SQL（重切另跑 fix_person_names --book）。
每一列都要在全庫 SQL 找到「一模一樣的目標」，對不上就停，不猜。"""
import csv, re, sys, collections

tsv, allsql, out = sys.argv[1:4]
rows = list(csv.DictReader(open(tsv, encoding="utf-8-sig"), delimiter="\t"))
sql = open(allsql, encoding="utf-8").read()

# 全庫 SQL 的 A／B／C 句子，用來源 person_id 當鍵
A = {int(m.group(1)): m.group(0) for m in re.finditer(r"^DELETE FROM persons WHERE person_id = (\d+);.*$", sql[:sql.index("═══ B.")], re.M)}
B = {}
for m in re.finditer(r"^(UPDATE IGNORE book_persons SET person_id = (\d+) WHERE person_id = (\d+);)\n(DELETE FROM persons WHERE person_id = \3;.*)$", sql, re.M):
    B[int(m.group(3))] = (int(m.group(2)), m.group(1) + "\n" + m.group(4))
C = {}
for m in re.finditer(r"^UPDATE persons SET name = '((?:[^']|'')*)' WHERE person_id = (\d+);.*$", sql, re.M):
    C[int(m.group(2))] = (m.group(1).replace("''", "'"), m.group(0))

COPENHAGEN = 41456
a, b, c, resplit, manual, problems = [], [], [], [], [], []
for r in rows:
    pid = int(r["person_id"]); act = r["處理"]
    if act == "刪（不是人）":
        (a.append(A[pid]) if pid in A else problems.append(f"{pid} 刪：全庫 SQL 的 A 段沒有"))
    elif act == "併到既有同名列":
        if pid == COPENHAGEN:
            continue
        want = int(r["併入 person_id"])
        if pid not in B: problems.append(f"{pid} 併：全庫 SQL 的 B 段沒有")
        elif B[pid][0] != want: problems.append(f"{pid} 併：清單說併到 {want}，工具說 {B[pid][0]}")
        else: b.append(B[pid][1])
    elif act == "改名":
        want = r["切完"]
        if pid not in C: problems.append(f"{pid} 改名：全庫 SQL 的 C 段沒有")
        elif C[pid][0] != want: problems.append(f"{pid} 改名：清單是「{want}」，工具是「{C[pid][0]}」")
        else: c.append(C[pid][1])
    elif act.startswith("重切"):
        resplit.append(pid)
    else:
        manual.append(pid)

if problems:
    print("對不上，沒有產出：", *problems, sep="\n  ")
    sys.exit(1)

a_ids = [int(re.search(r"person_id = (\d+)", x).group(1)) for x in a]
b_ids = [int(re.search(r"WHERE person_id = (\d+);\nDELETE", x).group(1)) for x in b]
gone = a_ids + b_ids + [COPENHAGEN]
del_ids = ",".join(map(str, a_ids + [COPENHAGEN]))
c_ids = ",".join(re.search(r"WHERE person_id = (\d+);", x).group(1) for x in c)

L = []
L.append(f"""-- 作者欄書目尾巴清理（只動 107 個 person／114 本書）—— 由 gen_pn_sql.py 從
--   docs/review/2026-10-19_person_biblio_tail.tsv ＋ check_person_names.php --emit-sql 產生
-- Asana 1219613623198535；裁示：熊哥 2026-10-20 TG（訊息 944～947）
-- 刪 {len(a)} 列只有 ISBN 的、併 {len(b)} 列、改名 {len(c)} 列、「哥本哈根出版社」(41456) 從作者刪；
-- 重切 {len(resplit)} 列另跑 fix_person_names.php --book；人工 {len(manual)} 列不動。
-- ★ 跑法：Navicat 一段一段跑；A0 先跑、應回 0 列；最後 E 段每句應回 0 列再 COMMIT。
-- ★ book_persons 對 persons 有 ON DELETE CASCADE。

START TRANSACTION;

-- ═══ A0. 前置檢查（應回 0 列）：刪掉這些之後會變成沒有作者的書 ═══
SELECT bp.book_id, b.title, COUNT(*) AS 只剩這些關聯
  FROM book_persons bp JOIN books b ON b.book_id = bp.book_id
 WHERE bp.book_id IN (SELECT book_id FROM book_persons WHERE person_id IN ({del_ids}))
 GROUP BY bp.book_id, b.title
HAVING SUM(bp.person_id NOT IN ({del_ids})) = 0;

-- ═══ A. 整列不是人（只有 ISBN）：{len(a)} 列 ═══""")
L += a
L.append(f"\n-- ═══ A2. 「哥本哈根出版社 出版：漢語聖經協會」是出版社不是人：從作者刪（裁示 3） ═══")
L.append(f"DELETE FROM persons WHERE person_id = {COPENHAGEN};  -- 哥本哈根出版社 出版：漢語聖經協會（2 本）")
L.append(f"\n-- ═══ B. 併到既有同名列：{len(b)} 列 ═══")
L += b
L.append(f"\n-- ═══ C. 改名：{len(c)} 列 ═══")
L += c
L.append(f"""
-- ═══ E. 回查（COMMIT 之前，每一句應回 0 列） ═══
-- E1. 要刪／要併的 person_id 都不在了
SELECT person_id, name FROM persons WHERE person_id IN ({",".join(map(str, gone))});
-- E2. 沒有孤兒關聯
SELECT bp.person_id, COUNT(*) FROM book_persons bp LEFT JOIN persons p ON p.person_id = bp.person_id
 WHERE p.person_id IS NULL GROUP BY bp.person_id;
-- E3. 這批 person 的名字不該再有 ISBN／書號／出版：
SELECT person_id, name FROM persons WHERE person_id IN ({c_ids})
   AND (name REGEXP '97[89][0-9]{{10}}' OR name LIKE '%ISBN%' OR name LIKE '%書號%' OR name LIKE '%出版：%');
-- E4. 總量（記下來寫進 Asana）
SELECT (SELECT COUNT(*) FROM persons) AS persons_total, (SELECT COUNT(*) FROM book_persons) AS book_persons_total;

-- 以上全部 0 列：COMMIT;   有任何一句回了列：ROLLBACK;
""")
open(out, "w", encoding="utf-8").write("\n".join(L))
print(f"ok：刪 {len(a)}＋哥本哈根 1、併 {len(b)}、改名 {len(c)}、重切 {len(resplit)}（{resplit}）、人工 {len(manual)}")
