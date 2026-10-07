<?php
declare(strict_types=1);
/**
 * 匯入前守門:把「爬蟲檔裡會被判成新書的那些」拿去和站上既有書目做**寬鬆**比對,
 * 產出候選對照表供人工複核。唯讀,不寫任何資料。
 *
 * ════════ 為什麼需要這支 ════════
 * import.php 的「新書 N」只代表**沒比對到**,不代表站上沒有。
 *   2026-09-15 麥種:報「新書 100」,實際 95 本是站上已有的書。
 *   2026-09-22 浸信會:報「新書 316」,抽 30 本用書名去站上搜,**27 本搜得到(90%)**。
 * 根因不是某一支程式壞掉,是 import 的合併鍵**當搜尋條件時太嚴**:
 *   ISBN 命中是硬條件;比不到就退到 fuzzy_key = 正規化書名 + 第一作者,而
 *     - 來源書名常缺副標(站上有):「追求屬靈的得與失」vs「追求屬靈的得與失:評基督宗教…」
 *     - 來源作者常帶英文原名:「萊特 (Christopher J. H. Wright)」vs 站上「萊特」
 *   兩個鍵都對不上 → 報成新書。
 * ★ 陷阱 28 的另一面:same_work() 那組判準**當否決條件安全、當搜尋條件危險**;
 *   這裡反過來——搜尋條件太嚴,漏掉的重複沒有任何徵兆,只會在站上長出兩本一樣的書。
 *
 * ════════ 這支刻意不做什麼 ════════
 * - **不改 import.php 的合併邏輯**。那是共用檔,一動就影響全站既有三萬多次跨站合併。
 * - **不自動合併**。輸出是候選清單,由人判斷;寧可多列幾筆讓人刪,不要漏列。
 * - 比對只用書名(正規化後雙向前綴/包含),**不看作者** —— 作者寫法不一致正是漏比的原因,
 *   拿它當條件會重蹈覆轍。作者只印出來給人眼核對。
 *
 * 用法:
 *   php tools/check_new_books.php --file=crawler/data/bappress_books.jsonl --source=bappress
 *   php tools/check_new_books.php --file=... --source=... --out=/tmp/bappress_dup.tsv
 *   php tools/check_new_books.php --file=... --source=... --min=6   # 前綴比對最短字數
 */

require __DIR__ . '/../app/lib/db.php';

$opt    = getopt('', ['file:', 'source:', 'out::', 'min::', 'limit::', 'split::']);
$file   = $opt['file'] ?? null;
$source = $opt['source'] ?? null;
$out    = $opt['out'] ?? null;
$split  = $opt['split'] ?? null;     // 前綴:會產生 {前綴}_ok.jsonl 與 {前綴}_hold.jsonl
$minLen = max(4, (int) ($opt['min'] ?? 6));
$limit  = (int) ($opt['limit'] ?? 0);
if (!$file || !$source) {
    exit("用法:php tools/check_new_books.php --file=xxx.jsonl --source=代碼 "
       . "[--out=x.tsv] [--split=/tmp/前綴] [--min=6]\n");
}
if (!is_file($file)) {
    exit("找不到檔案:$file\n");
}

/** 書名正規化:只保留中日韓與英數,其餘(標點空白,全形半形都算)一律丟。
 * ★ 刻意用**白名單**而不是列舉標點的黑名單:全形逗號/冒號/括號寫進原始碼時
 *   會退化成半形重複(陷阱 11),黑名單會靜默失效而且看不出來。 */
function norm_title(?string $s): string
{
    if ($s === null || $s === '') {
        return '';
    }
    preg_match_all('/[0-9A-Za-z\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}\x{f900}-\x{faff}]+/u', $s, $m);
    // ★ 一律轉小寫:第一版沒轉,於是
    //   「氧OXYGEN-靈修系列12(修訂版)」與站上「《氧Oxygen》靈修系列修訂版12」
    //   因為大小寫不同而比不到 —— 這兩本明明是同一本。
    //   英文書名在這批資料裡很多(NIV、ACCS、MTS…),不轉小寫會系統性漏比。
    return mb_strtolower(implode('', $m[0]), 'UTF-8');
}

$pdo = db();

// ── 1. 載入站上既有書目(含下架:「有沒有收過」與「有沒有上架」是兩件事)──
echo "載入站上書目…\n";
$rows = $pdo->query(
    "SELECT book_id, title, author, isbn13, is_published FROM books"
)->fetchAll(PDO::FETCH_ASSOC);

$byIsbn = [];      // isbn13 → book_id
$index  = [];      // 正規化書名 → [book_id, …]
$books  = [];      // book_id → 列
foreach ($rows as $r) {
    $id = (int) $r['book_id'];
    $books[$id] = $r;
    if (!empty($r['isbn13'])) {
        $byIsbn[$r['isbn13']] = $id;
    }
    // ※ identifiers 那一段在迴圈外補,見下
    $n = norm_title($r['title']);
    if ($n !== '') {
        $index[$n][] = $id;
    }
}
/* ★★ ISBN 索引必須和 import.php 用**完全相同的定義**,否則兩邊數字會各說各話。
 * import.php(第 429–437 行)的 isbnMap 是**兩個來源的聯集**:
 *     books.isbn13   +   identifiers(id_type='ISBN13')JOIN editions
 * 第一版這支只讀了 books.isbn13,結果:
 *     全檔比對時   工具 3,488 vs import 3,491(差 3)
 *     切檔後       工具 3,488 vs import 3,489(差 1)
 * 方向是**我低估了 ISBN 命中** —— 更要緊的是反過來:被擋進 hold 的那些,
 * 可能其實 import 會用 identifiers 裡的 ISBN 正確合併,那就白擋了。
 * ★ 對帳守門員那張票(1218194268650259)第 1 條講的就是這件事:
 *   兩支工具對同一件事用不同定義,數字必然對不上,而且看起來像資料有問題。 */
$n0 = count($byIsbn);
foreach ($pdo->query(
    "SELECT e.book_id, i.id_value FROM identifiers i
       JOIN editions e ON e.edition_id = i.edition_id
      WHERE i.id_type = 'ISBN13'") as $r) {
    $byIsbn[$r['id_value']] = (int) $r['book_id'];
}
printf("ISBN 索引:books.isbn13 %d 筆 + identifiers 補 %d 筆 = %d(與 import.php 同定義)\n",
    $n0, count($byIsbn) - $n0, count($byIsbn));

// 前綴比對用:把正規化書名依「前 N 字」分桶,避免 65,000 × 316 的全比對
// ★ PHP 陷阱:**純數字的字串當陣列鍵會被自動轉成 int**(「365」→ 365),
//   於是 foreach 取出來的 $n 是 int,mb_strlen(int) 在 PHP 8 直接 TypeError。
//   站上真的有書名正規化後全是數字(例:「365」),所以這不是理論問題。
//   一律轉回字串再用;存進桶裡的值也要是字串,否則 str_starts_with() 一樣會炸。
$bucket = [];
foreach ($index as $n => $ids) {
    $n = (string) $n;
    if (mb_strlen($n, 'UTF-8') >= $minLen) {
        $bucket[mb_substr($n, 0, $minLen, 'UTF-8')][] = $n;
    }
}
printf("站上 %d 本(其中有 ISBN %d 本);前綴桶 %d 個\n", count($books), count($byIsbn), count($bucket));

// ── 2. 逐筆檢查來源檔 ──
$fh = fopen($file, 'r');
/* --split:把來源檔切成兩份(熊哥 2026-09-22 裁示「先匯 214 本」)
 *   {前綴}_ok.jsonl   = ISBN 已在站上的 + 書名也找不到的 → 這份拿去 import
 *   {前綴}_hold.jsonl = 書名在站上找得到的(幾乎確定 + 疑似)→ 擱置待人工複核
 * ★ ISBN 已命中的那批**要放進 ok**:它們會併進既有書、補上浸信會的購書連結,
 *   那正是收這個來源最主要的價值,不是「新書數」。
 * ★ 兩份筆數相加必須等於原檔,程式最後會自己驗;對不上就是切壞了。 */
$okFh = $holdFh = null;
if ($split) {
    $okFh   = fopen($split . '_ok.jsonl', 'w');
    $holdFh = fopen($split . '_hold.jsonl', 'w');
}
$stat = ['read' => 0, 'isbn_hit' => 0, 'exact' => 0, 'prefix' => 0, 'common' => 0, 'none' => 0,
         'w_ok' => 0, 'w_hold' => 0];

/** 兩個正規化書名的共同前綴字數。 */
function common_prefix_len(string $a, string $b): int
{
    $la = mb_strlen($a, 'UTF-8');
    $lb = mb_strlen($b, 'UTF-8');
    $n  = min($la, $lb);
    for ($i = 0; $i < $n; $i++) {
        if (mb_substr($a, $i, 1, 'UTF-8') !== mb_substr($b, $i, 1, 'UTF-8')) {
            return $i;
        }
    }
    return $n;
}
$hits = [];
while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $raw = json_decode($line, true);
    if (!is_array($raw)) {
        continue;
    }
    $stat['read']++;
    if ($limit && $stat['read'] > $limit) {
        break;
    }

    $isbn = (string) ($raw['isbn'] ?? '');
    if ($isbn !== '' && isset($byIsbn[$isbn])) {
        $stat['isbn_hit']++;     // ISBN 命中 → import 本來就會合併,不是新書
        if ($okFh) { fwrite($okFh, $line . "\n"); $stat['w_ok']++; }
        continue;
    }

    $title = (string) ($raw['title'] ?? '');
    $n = norm_title($title);
    if ($n === '') {
        // 沒有書名可比 → 不做判斷,照樣放進 ok 讓 import 決定(不靜默丟資料)
        if ($okFh) { fwrite($okFh, $line . "\n"); $stat['w_ok']++; }
        continue;
    }

    $cand = [];
    $how  = '';
    if (isset($index[$n])) {                       // 正規化後完全相同
        $cand = $index[$n];
        $how  = '書名相同';
        $stat['exact']++;
    } else {
        // 雙向前綴:來源書名少副標、或站上少副標,兩種都要抓
        $key  = mb_substr($n, 0, $minLen, 'UTF-8');
        $seen = [];
        foreach ($bucket[$key] ?? [] as $other) {
            $other = (string) $other;          // 同上:數字鍵會變 int
            if (str_starts_with($other, $n) || str_starts_with($n, $other)) {
                foreach ($index[$other] as $id) {
                    $seen[$id] = true;
                }
            }
        }
        if ($seen) {
            $cand = array_keys($seen);
            $how  = '書名互為前綴';
            $stat['prefix']++;
        } else {
            /* ★ 第三種:共同前綴夠長。
             * 前兩種(完全相同、互為前綴)都要求「其中一個是另一個的開頭」,
             * 抓不到**中間或尾端**有差異的同一本書。實例:
             *   「氧OXYGEN-靈修系列12(修訂版)」 vs 站上「《氧Oxygen》靈修系列修訂版12」
             * 兩者共同前綴 11 字,但誰也不是誰的前綴 —— 那 11 本《氧》一本都沒被抓到,
             * 而它們確實是同一批書。
             * ★ 這一類**可信度較低**(系列書天生共用長前綴),所以獨立計數、
             *   報表分開列,讓人知道該用不同的眼光看。門檻取 8 字:
             *   「中文聖經註釋馬可福音」vs「中文聖經註釋路加福音」共同前綴只有 6 字,
             *   設 8 就不會把整套註釋書互相配對。 */
            $best = 0;
            foreach ($bucket[$key] ?? [] as $other) {
                $other = (string) $other;
                $c = common_prefix_len($n, $other);
                if ($c >= 8 && $c > $best) {
                    $best = $c;
                    $seen = [];
                    foreach ($index[$other] as $id) {
                        $seen[$id] = true;
                    }
                }
            }
            if ($seen) {
                $cand = array_keys($seen);
                $how  = "共同前綴{$best}字(可信度較低)";
                $stat['common']++;
            } else {
                $stat['none']++;
                if ($okFh) { fwrite($okFh, $line . "\n"); $stat['w_ok']++; }
                continue;
            }
        }
    }
    // 走到這裡表示書名在站上找得到(幾乎確定 或 疑似)→ 擱置
    if ($holdFh) { fwrite($holdFh, $line . "\n"); $stat['w_hold']++; }

    foreach (array_slice($cand, 0, 3) as $id) {
        $hits[] = [
            $raw['pid'] ?? '', $title, (string) ($raw['authors_raw'] ?? ''), $isbn,
            $id, $books[$id]['title'], (string) $books[$id]['author'],
            (string) $books[$id]['isbn13'],
            ((int) $books[$id]['is_published'] === 1 ? '上架' : '已下架'), $how,
        ];
    }
}
fclose($fh);
if ($okFh) {
    fclose($okFh);
    fclose($holdFh);
}

// ── 3. 報表 ──
$strong  = $stat['exact'] + $stat['prefix'];      // 可信度高
$weak    = $stat['common'];                       // 可信度較低,要人看
$suspect = $strong + $weak;
$newish  = $suspect + $stat['none'];
echo "\n════ 結果 ════\n";
printf("讀 %d 筆\n", $stat['read']);
printf("  ISBN 已在站上(import 會合併,不算新書):%d\n", $stat['isbn_hit']);
printf("  ★ 幾乎確定是重複(書名相同 %d + 互為前綴 %d):%d\n",
    $stat['exact'], $stat['prefix'], $strong);
printf("  ★ 疑似重複(共同前綴 ≥8 字,系列書會誤中,**要人看**):%d\n", $weak);
printf("  書名也找不到(較可能是真新書):%d\n", $stat['none']);
if ($newish > 0) {
    printf("\n★ import 會報「新書 %d」左右,而其中 %d 筆(%d%%)書名在站上找得到。\n",
        $newish, $suspect, (int) round($suspect * 100 / $newish));
}
echo "★ 這張表是**候選**不是判決:書名相同不必然是同一本(不同版本、同名異書都可能)。\n";
echo "  請逐筆看過再決定怎麼處理,不要自動合併。\n";

if ($split) {
    printf("\n切檔:%s_ok.jsonl %d 筆 / %s_hold.jsonl %d 筆\n",
        $split, $stat['w_ok'], $split, $stat['w_hold']);
    $sum = $stat['w_ok'] + $stat['w_hold'];
    if ($sum !== $stat['read']) {
        // ★ 對不上就是切壞了,寧可當場中止也不要拿一份少了東西的檔去匯入
        printf("★★ 兩份相加 %d ≠ 讀入 %d,**差 %d 筆** —— 切檔有問題,不要用這兩個檔匯入!\n",
            $sum, $stat['read'], $stat['read'] - $sum);
        exit(1);
    }
    printf("✓ 兩份相加 %d = 讀入 %d,沒有掉資料\n", $sum, $stat['read']);
    printf("  下一步:php tools/import.php --file=%s_ok.jsonl --source=%s --dry-run\n",
        $split, $source);
    printf("  預期會報「新書 %d、合併 %d」左右。\n", $stat['none'], $stat['isbn_hit']);
}

if ($out) {
    $f = fopen($out, 'w');
    fwrite($f, "\xEF\xBB\xBF");   // BOM:Excel 直接開得了
    fputcsv($f, ['來源貨號', '來源書名', '來源作者', '來源ISBN',
                 '站上book_id', '站上書名', '站上作者', '站上ISBN', '站上狀態', '比對方式'], "\t");
    foreach ($hits as $row) {
        fputcsv($f, $row, "\t");
    }
    fclose($f);
    printf("\n對照表已寫出:%s(%d 列)\n", $out, count($hits));
} else {
    echo "\n(加 --out=/tmp/xxx.tsv 可輸出完整對照表供人工複核)\n";
    foreach (array_slice($hits, 0, 40) as $r) {
        echo "  「{$r[1]}」/{$r[2]}\n      ↔ #{$r[4]}「{$r[5]}」/{$r[6]} {$r[7]} [{$r[8]}] ({$r[9]})\n";
    }
}
