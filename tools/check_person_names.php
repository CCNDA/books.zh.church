<?php
declare(strict_types=1);
/**
 * persons 表盤點(唯讀,**不寫任何資料**)。
 *
 * ════════ 這支在規則鏈裡的位置 ════════
 *   tools/lib_person.php          規則本體(唯一一份)
 *   ├─ tools/import.php           新書套用
 *   ├─ tools/fix_person_names.php 既有書重切
 *   └─ **本檔**                   規則的驗證者:產對照表給人勾、產清理 SQL 給人跑
 *
 * 規則若只有匯入器在用,沒有人會發現它剝錯;這支的本分就是**把規則的判斷攤開來給人看**。
 *
 * ════════ 六類髒資料(Asana 1218277971470042) ════════
 *   fullwidth_semi  全形分號沒被拆,多個人擠在同一列       → fix_person_names.php 重切
 *   entity          HTML entity 殘骸(「Anselm Gr&uuml」)  → fix_person_names.php 重切
 *   role_word       整列就是角色詞(「文」「圖」「譯」)     → 刪關聯 + 刪列
 *   filler          整列是填充詞(「等」)                   → 同上
 *   tail_note       尾註沒清(「梁家麟著」vs「梁家麟」)     → 併到正規列
 *   role_prefix     角色前綴(「圖:李小華」)                → 併到正規列
 *   long_text       整段文字誤入人名                        → **人工**,不產 SQL
 *   變體            異體字同一人(托馬斯/託馬斯)            → 需逐對過目才產 SQL
 *
 * ★★ **long_text 與「未知差異」的變體候選,本檔一律只列清單、不產 SQL。**
 *    這兩類沒有安全的自動解,硬產 SQL 只會讓人以為它驗過了。
 *
 * ════════ 用法 ════════
 *   php tools/check_person_names.php                      # 盤點摘要(先跑這個)
 *   php tools/check_person_names.php --limit=80           # 每類多列幾筆
 *   php tools/check_person_names.php --tsv=data/person_audit.tsv
 *   php tools/check_person_names.php --variants           # 只看異體字候選
 *   php tools/check_person_names.php --emit-sql=database/migrations/2026-10-03_person_cleanup.sql
 *   php tools/check_person_names.php --guard              # 當守門員用(有可自動處理的髒列就 exit 1)
 *
 * ★ `--guard` 要等清理完成之後才併進 daily_new.sh —— 清理前它一定是紅的,
 *   天天紅的守門員等於沒有守門員。
 *
 * ★ 本檔印出來的數字只是「規則怎麼判」,**不是對帳結果**。
 *   真的動完資料之後,一律回資料庫實查(產出的 SQL 末尾附了回查查詢,應回 0 列)。
 *
 * 相關:Asana 1218277971470042、tools/lib_person.php、tools/fix_person_names.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/lib_person.php';

const CPN_REV = '2026-10-03.2';

/** 以**顯示寬度**補空白(printf 的 %-Ns 算的是位元組,中文會歪掉) */
function cpn_pad(string $s, int $w): string
{
    $s = mb_strimwidth($s, 0, $w, '…', 'UTF-8');
    return $s . str_repeat(' ', max(0, $w - mb_strwidth($s, 'UTF-8')));
}          // ★ 版本戳記:FTP 沒蓋到時唯一能當場抓出來的辦法

$opt      = getopt('', ['limit::', 'tsv::', 'emit-sql::', 'variants', 'guard', 'min-books::']);
$limit    = max(1, (int) ($opt['limit'] ?? 30));
$minBooks = max(0, (int) ($opt['min-books'] ?? 1));   // 變體候選:兩邊都要至少幾本書
$onlyVar  = array_key_exists('variants', $opt);
$guard    = array_key_exists('guard', $opt);

echo "check_person_names rev " . CPN_REV . "\n";

$pdo = db();
$pdo->exec('SET NAMES utf8mb4');

// ── 1. 載入 persons + 每人掛幾本書 ───────────────────────────────────────
$rows = $pdo->query(
    "SELECT p.person_id, p.name, p.aka,
            COUNT(DISTINCT bp.book_id) AS n_books,
            COUNT(bp.book_person_id)   AS n_links
       FROM persons p
       LEFT JOIN book_persons bp ON bp.person_id = p.person_id
      GROUP BY p.person_id, p.name, p.aka"
)->fetchAll(PDO::FETCH_ASSOC);

echo "persons 共 " . count($rows) . " 列\n\n";

// name → 該名字底下的所有列(persons.name **沒有 UNIQUE**,同名多列是可能的)
$byName = [];
foreach ($rows as $r) $byName[$r['name']][] = $r;

// ── 2. 逐列套規則 ────────────────────────────────────────────────────────
$bucket = ['fullwidth_semi'=>[], 'entity'=>[], 'role_word'=>[], 'filler'=>[],
           'tail_note'=>[], 'role_prefix'=>[], 'long_text'=>[], 'clean'=>[]];
foreach ($rows as $r) {
    $n = normalize_person_name($r['name']);
    $r['_kind']  = $n['kind'];
    $r['_clean'] = $n['clean'];
    $r['_role']  = $n['role'];
    $r['_hold']  = implode(' / ', $n['hold']);
    // 剝完等於原值 → 規則其實沒動它,歸 clean(避免把「沒事」算進髒資料)
    if (in_array($n['kind'], ['tail_note', 'role_prefix'], true)
        && ($n['hold'] || $n['clean'] === $r['name'])) {
        $r['_kind'] = $n['hold'] ? 'long_text' : 'clean';   // hold 的要人看
    }
    $bucket[$r['_kind']][] = $r;
}

// ── 3. 併入目標(tail_note / role_prefix 才有) ──────────────────────────
//    目標 = 名字等於剝乾淨結果、且掛書最多的那一列。
//    ★ 找不到既有列時**不新建**,改用 UPDATE 改名 —— 少一次 INSERT 就少一個
//      「改完才發現撞到同名列」的機會。
$plan = [];                 // [ dirty_id => ['row'=>…, 'target'=>?row, 'clean'=>…] ]
foreach (['tail_note', 'role_prefix'] as $k) {
    foreach ($bucket[$k] as $r) {
        $cands = $byName[$r['_clean']] ?? [];
        usort($cands, fn($a, $b) => (int) $b['n_books'] <=> (int) $a['n_books']);
        $target = null;
        foreach ($cands as $c) { if ((int) $c['person_id'] !== (int) $r['person_id']) { $target = $c; break; } }
        $plan[(int) $r['person_id']] = ['row' => $r, 'target' => $target, 'clean' => $r['_clean']];
    }
}

// ── 4. 異體字候選(同長度、只差一個字;用通配鍵分群,不做 O(n^2) 比對) ──
//    ★ 候選**不是**從 PN_VARIANTS 產生的 —— 那張表只用來標「這一對差的是不是
//      已知異體字」。表裡沒收到的寫法照樣找得到,只是標成「未知差異」排在後面。
$variants = [];
$wild = [];
foreach ($rows as $r) {
    if ((int) $r['n_books'] < $minBooks) continue;
    $chars = preg_split('//u', $r['name'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($chars) < 3) continue;                 // 太短的「只差一字」全是雜訊
    foreach ($chars as $i => $_) {
        $k = $chars; $k[$i] = "\x00";
        $wild[implode('', $k)][] = [$r, $i];
    }
}
foreach ($wild as $group) {
    if (count($group) < 2) continue;
    for ($i = 0; $i < count($group); $i++) {
        for ($j = $i + 1; $j < count($group); $j++) {
            [$ra, $pa] = $group[$i];
            [$rb, $pb] = $group[$j];
            if ($ra['person_id'] === $rb['person_id']) continue;
            $ca = mb_substr($ra['name'], $pa, 1, 'UTF-8');
            $cb = mb_substr($rb['name'], $pb, 1, 'UTF-8');
            if ($ca === $cb) continue;
            $known = pn_same_variant($ca, $cb);
            $key = min((int) $ra['person_id'], (int) $rb['person_id']) . '-'
                 . max((int) $ra['person_id'], (int) $rb['person_id']);
            $variants[$key] = [
                'a' => $ra, 'b' => $rb, 'ca' => $ca, 'cb' => $cb, 'known' => $known,
                'n' => (int) $ra['n_books'] + (int) $rb['n_books'],
            ];
        }
    }
}
uasort($variants, fn($x, $y) => [$y['known'], $y['n']] <=> [$x['known'], $x['n']]);

// ── 5. 輸出 ──────────────────────────────────────────────────────────────
$label = [
    'fullwidth_semi' => '全形分號沒被拆(多人擠同一列)→ fix_person_names.php',
    'entity'         => 'HTML entity 殘骸 → fix_person_names.php',
    'role_word'      => '整列就是角色詞,不是人 → 刪',
    'filler'         => '整列是填充詞(等/其他)→ 刪',
    'tail_note'      => '尾註沒清 → 併到正規列',
    'role_prefix'    => '角色前綴 → 併到正規列',
    'long_text'      => '★ 疑似整段文字或規則不敢動 → **人工**',
];

if (!$onlyVar) {
    echo "═══ 分類盤點 ═══\n";
    $sum = 0;
    foreach ($label as $k => $desc) {
        $n = count($bucket[$k]);
        $sum += $n;
        printf("  %-15s %6d 列  %s\n", $k, $n, $desc);
    }
    printf("  %-15s %6d 列\n", 'clean', count($bucket['clean']));
    echo "  ──────────────────────────\n";
    printf("  髒列合計        %6d 列\n\n", $sum);

    // ★ 任何一類「應該有值卻是 0」都要當場追(陷阱 27)。票上 9/8 實測
    //   fullwidth_semi 56、role_word 6、entity 11 都是非 0 —— 這裡變成 0
    //   不代表修好了,更可能是這支工具讀錯表或規則退化。
    foreach (['fullwidth_semi' => 56, 'entity' => 11, 'role_word' => 6] as $k => $base) {
        if (count($bucket[$k]) === 0) {
            echo "  ★★ 警告:{$k} 盤出 0 列,但 2026-09-08 實測有 {$base} 列。\n"
               . "     清理尚未執行就回 0,幾乎一定是這支工具或規則出了問題,**當場追**。\n\n";
        }
    }

    foreach ($label as $k => $desc) {
        if (!$bucket[$k]) continue;
        echo "── {$k}:{$desc} ──\n";
        $shown = 0;
        foreach ($bucket[$k] as $r) {
            if ($shown++ >= $limit) { echo "   …(共 " . count($bucket[$k]) . " 列,--limit=N 看更多)\n"; break; }
            $tgt = $plan[(int) $r['person_id']]['target'] ?? null;
            printf("   %-7d %4d 本  %s %s\n",
                (int) $r['person_id'], (int) $r['n_books'], cpn_pad($r['name'], 42),
                $r['_hold'] !== '' ? '← ' . $r['_hold']
                    : ($r['_clean'] !== '' ? '→ ' . $r['_clean'] . ($tgt ? " (併入 {$tgt['person_id']},{$tgt['n_books']} 本)" : ' (改名,無既有列)') : '→ 刪'));
        }
        echo "\n";
    }
}

echo "═══ 異體字候選(同長度、只差一個字) ═══\n";
$knownN = count(array_filter($variants, fn($v) => $v['known']));
printf("  已知異體字 %d 對、未知差異 %d 對(兩邊各至少 %d 本)\n", $knownN, count($variants) - $knownN, $minBooks);
echo "  ★ 未知差異那批可能根本是兩個人(陳志明/陳志民),**一律人工過目**。\n\n";
$shown = 0;
foreach ($variants as $v) {
    if ($shown++ >= $limit) { echo "   …(共 " . count($variants) . " 對)\n"; break; }
    printf("   %-7d %4d 本 %s %s  %-7d %4d 本 %s\n",
        (int) $v['a']['person_id'], (int) $v['a']['n_books'], cpn_pad($v['a']['name'], 30),
        cpn_pad($v['known'] ? "[{$v['ca']}/{$v['cb']} 異體]" : "[{$v['ca']}/{$v['cb']} 未知]", 12),
        (int) $v['b']['person_id'], (int) $v['b']['n_books'], cpn_pad($v['b']['name'], 30));
}
echo "\n";

// ── 6. TSV ───────────────────────────────────────────────────────────────
if (!empty($opt['tsv'])) {
    $fh = fopen((string) $opt['tsv'], 'w');
    fwrite($fh, "kind\tperson_id\tname\tn_books\tn_links\tclean\trole\ttarget_person_id\ttarget_n_books\thold\n");
    foreach ($label as $k => $_) {
        foreach ($bucket[$k] as $r) {
            $t = $plan[(int) $r['person_id']]['target'] ?? null;
            fwrite($fh, implode("\t", [
                $k, $r['person_id'], str_replace(["\t", "\n"], ' ', $r['name']),
                $r['n_books'], $r['n_links'], $r['_clean'], (string) $r['_role'],
                $t['person_id'] ?? '', $t['n_books'] ?? '', $r['_hold'],
            ]) . "\n");
        }
    }
    foreach ($variants as $v) {
        fwrite($fh, implode("\t", [
            $v['known'] ? 'variant_known' : 'variant_unknown',
            $v['a']['person_id'], $v['a']['name'], $v['a']['n_books'], '', $v['b']['name'],
            '', $v['b']['person_id'], $v['b']['n_books'], "{$v['ca']}/{$v['cb']}",
        ]) . "\n");
    }
    fclose($fh);
    echo "已寫出 TSV:{$opt['tsv']}\n";
}

// ── 7. 清理 SQL ──────────────────────────────────────────────────────────
if (!empty($opt['emit-sql'])) {
    $f = fopen((string) $opt['emit-sql'], 'w');
    $q = fn(string $s): string => "'" . str_replace("'", "''", $s) . "'";

    fwrite($f, "-- persons 表清理 —— 由 tools/check_person_names.php rev " . CPN_REV . " 產生\n");
    fwrite($f, "-- 產生時間:" . date('Y-m-d H:i:s') . "    Asana 1218277971470042\n");
    fwrite($f, "--\n");
    fwrite($f, "-- ★ 跑法:Navicat 對遠端 DB,**一段一段跑**,每段跑完看回查數字再跑下一段。\n");
    fwrite($f, "-- ★ book_persons 對 persons 有 ON DELETE CASCADE,所以 DELETE FROM persons\n");
    fwrite($f, "--   會順手清掉 UPDATE IGNORE 撞 uq_book_person_role 而留下的那幾列。這是刻意的。\n");
    fwrite($f, "-- ★ 本檔**不含** long_text 與「未知差異」的變體 —— 那兩類沒有安全的自動解,人工處理。\n");
    fwrite($f, "-- ★ 本檔**不改 book_persons.role**:「黃伯和編」併進「黃伯和」之後,那 3 筆關聯\n");
    fwrite($f, "--   仍然記成 author(它本來就是從 authors_raw 進來的)。角色正確化只對\n");
    fwrite($f, "--   **之後新匯入的書**生效(import.php 改用署名裡的角色)。既有關聯的角色\n");
    fwrite($f, "--   要不要回頭修,是另一件事,本票不處理。\n\n");
    fwrite($f, "START TRANSACTION;\n\n");

    // (A) 刪:角色詞 / 填充詞
    $del    = array_merge($bucket['role_word'], $bucket['filler']);
    $delIds = array_map(fn($r) => (int) $r['person_id'], $del);

    // ★★ A0 前置檢查,不可跳過。
    //    DELETE FROM persons 會 CASCADE 掉 book_persons —— 「文」那一列掛著 34 本書的關聯。
    //    這些書**理當**還有另一位真正的作者(「文、圖:王小明」切開後「王小明」自成一列),
    //    但那是推論,不是事實。本案已經為「兩個數字兜不攏推出來的解釋」付過代價:
    //    推論聽起來很合理,逐本查證後完全是錯的。所以先查,查完是 0 才往下跑。
    fwrite($f, "-- ═══ A0. 前置檢查(★ 跑 A 之前必跑,**應回 0 列**) ═══\n");
    fwrite($f, "-- 刪掉角色詞那幾列會 CASCADE 掉它們的 book_persons。\n");
    fwrite($f, "-- 這句找出「刪完之後就一個人都不剩」的書 —— 有列回來就表示那本書的\n");
    fwrite($f, "-- 真正作者從來沒被建進去,直接刪會讓那本書變成無作者。先處理那幾本再回來。\n");
    if ($delIds) {
        fwrite($f, "SELECT bp.book_id, b.title, COUNT(*) AS 只剩這些關聯\n"
                 . "  FROM book_persons bp JOIN books b ON b.book_id = bp.book_id\n"
                 . " WHERE bp.book_id IN (SELECT book_id FROM book_persons WHERE person_id IN ("
                 . implode(',', $delIds) . "))\n"
                 . " GROUP BY bp.book_id, b.title\n"
                 . "HAVING SUM(bp.person_id NOT IN (" . implode(',', $delIds) . ")) = 0;\n\n");
    } else {
        fwrite($f, "-- (本次沒有角色詞列)\n\n");
    }

    fwrite($f, "-- ═══ A. 整列不是人(角色詞 / 填充詞):" . count($del) . " 列 ═══\n");
    foreach ($del as $r) {
        fwrite($f, sprintf("DELETE FROM persons WHERE person_id = %d;  -- %s(%d 本,CASCADE 連 book_persons 一起清)\n",
            (int) $r['person_id'], str_replace("\n", ' ', $r['name']), (int) $r['n_books']));
    }

    // (B) 併:尾註 / 角色前綴
    $merge  = array_filter($plan, fn($p) => $p['target'] !== null);
    $rename = array_filter($plan, fn($p) => $p['target'] === null);
    // ★ 兩列剝乾淨後同名、而資料庫裡又沒有那個正規列(「邁爾著」「邁爾編」都 → 「邁爾」):
    //   兩列都 UPDATE name 就會憑空多出一組同名重複列 —— persons.name **沒有 UNIQUE**,
    //   資料庫不會擋,只會靜默多一列。所以同名的只留書最多的那列改名,其餘併過去。
    $groups = [];
    foreach ($rename as $p) $groups[$p['clean']][] = $p;
    foreach ($groups as $g) {
        if (count($g) < 2) continue;
        usort($g, fn($x, $y) => (int) $y['row']['n_books'] <=> (int) $x['row']['n_books']);
        $keep = array_shift($g);
        foreach ($g as $p) {
            $id = (int) $p['row']['person_id'];
            unset($rename[$id]);
            $p['target'] = $keep['row'];          // 併進「待改名」那一列(改名後名字就是 clean)
            $merge[$id]  = $p;
        }
    }
    fwrite($f, "\n-- ═══ B. 併入既有正規列:" . count($merge) . " 列 ═══\n");
    foreach ($merge as $p) {
        $a = (int) $p['row']['person_id'];
        $b = (int) $p['target']['person_id'];
        fwrite($f, sprintf("UPDATE IGNORE book_persons SET person_id = %d WHERE person_id = %d;\n", $b, $a));
        fwrite($f, sprintf("DELETE FROM persons WHERE person_id = %d;  -- %s → %s (%d)\n",
            $a, str_replace("\n", ' ', $p['row']['name']), $p['clean'], $b));
    }
    fwrite($f, "\n-- ═══ C. 剝乾淨但沒有既有正規列,直接改名:" . count($rename) . " 列 ═══\n");
    foreach ($rename as $p) {
        fwrite($f, sprintf("UPDATE persons SET name = %s WHERE person_id = %d;  -- 原「%s」\n",
            $q($p['clean']), (int) $p['row']['person_id'], str_replace("\n", ' ', $p['row']['name'])));
    }

    // (D) 異體字:只產已知異體字那批,且標明要逐對過目
    $known = array_filter($variants, fn($v) => $v['known']);
    fwrite($f, "\n-- ═══ D. 異體字合併:" . count($known) . " 對 ═══\n");
    fwrite($f, "-- ★★ 這一段**預設是註解掉的**。候選是機器找的,「只差一個已知異體字」\n");
    fwrite($f, "--    仍可能是兩個不同的人。逐對看過、確認是同一人,才把該行的 -- 拿掉。\n");
    fwrite($f, "-- ★  併的方向:書多的留下,書少的併過去,舊寫法寫進保留列的 aka。\n");
    foreach ($known as $v) {
        $keep = (int) $v['a']['n_books'] >= (int) $v['b']['n_books'] ? $v['a'] : $v['b'];
        $drop = $keep === $v['a'] ? $v['b'] : $v['a'];
        fwrite($f, sprintf("--   %s(%d 本,留)  ←  %s(%d 本,併)\n",
            $keep['name'], (int) $keep['n_books'], $drop['name'], (int) $drop['n_books']));
        fwrite($f, sprintf("-- UPDATE persons SET aka = CONCAT_WS(';', NULLIF(aka, ''), %s) WHERE person_id = %d;\n",
            $q($drop['name']), (int) $keep['person_id']));
        fwrite($f, sprintf("-- UPDATE IGNORE book_persons SET person_id = %d WHERE person_id = %d;\n",
            (int) $keep['person_id'], (int) $drop['person_id']));
        fwrite($f, sprintf("-- DELETE FROM persons WHERE person_id = %d;\n", (int) $drop['person_id']));
    }

    // (E) 回查驗證 —— 不跑這段不算做完
    fwrite($f, "\n-- ═══ E. 回查驗證(COMMIT 之前先跑,**每一句都應回 0 列**) ═══\n");
    fwrite($f, "-- ★ 工具自印的數字不算證據,以下才算。\n\n");
    fwrite($f, "-- E1. 本次要刪/要併的 person_id 應該都不存在了\n");
    $gone = array_merge(
        array_map(fn($r) => (int) $r['person_id'], $del),
        array_map(fn($p) => (int) $p['row']['person_id'], $merge)
    );
    fwrite($f, $gone
        ? "SELECT person_id, name FROM persons WHERE person_id IN (" . implode(',', $gone) . ");\n"
        : "-- (本次沒有要刪的列)\n");
    fwrite($f, "\n-- E2. 沒有孤兒關聯(FK 有 CASCADE,這句是確認 CASCADE 真的生效)\n");
    fwrite($f, "SELECT bp.person_id, COUNT(*) FROM book_persons bp\n"
             . "  LEFT JOIN persons p ON p.person_id = bp.person_id\n"
             . " WHERE p.person_id IS NULL GROUP BY bp.person_id;\n");
    fwrite($f, "\n-- E3. 角色詞不該再有獨立人名列\n");
    fwrite($f, "SELECT person_id, name FROM persons\n WHERE name IN ("
             . implode(', ', array_map($q, array_keys(PN_ROLE_WORDS))) . ");\n");
    fwrite($f, "\n-- E4. persons.name 不該再有全形分號(U+FF1B);這一類要先跑 fix_person_names.php\n");
    // ★ utf8mb4_unicode_ci 會把全形分號與半形分號視為相等 → 不加 COLLATE 會把
    //   所有含半形分號的名字一起撈進來,看起來「清不乾淨」。票上的查詢陷阱備忘就是這條。
    fwrite($f, "SELECT person_id, name FROM persons\n"
             . " WHERE name COLLATE utf8mb4_bin LIKE CONCAT('%', CHAR(0xEFBC9B USING utf8mb4), '%');\n");
    fwrite($f, "\n-- E5. persons.name 不該再有 HTML entity 殘骸\n");
    fwrite($f, "SELECT person_id, name FROM persons WHERE name REGEXP '&(#[0-9]+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]+);?';\n");
    fwrite($f, "\n-- E6. 清理前後的總量(記下來寫進 Asana)\n");
    fwrite($f, "SELECT (SELECT COUNT(*) FROM persons) AS persons_total,\n"
             . "       (SELECT COUNT(*) FROM book_persons) AS book_persons_total;\n");
    fwrite($f, "\n-- 以上全部確認後:\n-- COMMIT;\n-- 有任何一句回了列:ROLLBACK;\n");
    fclose($f);

    echo "已寫出清理 SQL:{$opt['emit-sql']}\n";
    echo "  A 刪 " . count($del) . " 列、B 併 " . count($merge) . " 列、C 改名 " . count($rename)
       . " 列、D 異體字 " . count($known) . " 對(預設註解掉)\n";
    echo "  ★ 本檔要另存一份到本機 database/migrations/ —— 主機上產的 SQL,Navicat 開不到。\n";
}

if ($guard) {
    $dirty = count($bucket['role_word']) + count($bucket['filler'])
           + count($bucket['tail_note']) + count($bucket['role_prefix'])
           + count($bucket['fullwidth_semi']) + count($bucket['entity']);
    if ($dirty > 0) {
        echo "\n★ 守門員:可自動處理的髒列 {$dirty} 列 → exit 1\n";
        exit(1);
    }
    echo "\n守門員:0 列 ✓\n";
}
exit(0);
