<?php
declare(strict_types=1);
/**
 * 量「人名規則改動對跨站模糊比對的影響」(唯讀,**不寫任何資料**)。
 *
 * ════════ 為什麼需要這支 ════════
 * `split_names()` 不只用在寫入,也用來建 `import.php` 的 `$fuzzyMap`
 * (`fuzzy_key(書名, 第一作者)` → book_id)。把「梁家麟著」收斂成「梁家麟」之後,
 * 原本比不中的兩本書會開始互相命中 —— 這正是要的效果,但**幅度必須先量**。
 *
 * ★★ 2026-10-03 的教訓:原本打算用
 *     `php tools/import.php --file=…logos… --source=logos --dry-run`
 *   來看這件事,實跑結果是:
 *     讀 30795、新書 0、合併 0、已存在跳過 30795
 *     合併明細:ISBN 命中 0、模糊比對 0
 *   —— 每一筆都因為 `source_url` 已存在而**在比對之前就 continue 了**。
 *   那三個 0 不是「沒有影響」,是「這個方法量不到影響」。
 *   **一個應該有值卻是 0 的統計,就是要當場追**(陷阱 27),這支就是追出來的結果。
 *
 * ════════ 它怎麼量 ════════
 * 不靠匯入流程,直接對**站上既有的書**算兩次 `fuzzy_key`:
 *   舊 = 改動前的切法(括號感知切割 + 只剝「等」)
 *   新 = `pn_split_names()`
 * 然後看「舊 key 不同、新 key 相同」的書有幾組 —— 那就是規則改動**新增的合併機會**。
 *
 * ★ 這些書**不會因為這支而被合併**:既有書的合併要另外跑
 *   `tools/merge_duplicate_books.php`;`import.php` 只影響之後新抓進來的書。
 *   這支的用途是「上線前知道量級」,不是修資料。
 *
 * 用法:
 *   php tools/check_fuzzy_impact.php             # 摘要 + 前 30 組
 *   php tools/check_fuzzy_impact.php --limit=100
 *   php tools/check_fuzzy_impact.php --tsv=data/fuzzy_impact.tsv
 *
 * 相關:Asana 1218277971470042、tools/lib_person.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require __DIR__ . '/../app/lib/db.php';
require_once __DIR__ . '/lib_person.php';

const CFI_REV = '2026-10-03.1';

$opt   = getopt('', ['limit::', 'tsv::']);
$limit = max(1, (int) ($opt['limit'] ?? 30));

/**
 * 改動**之前**的第一作者切法,原樣重現(不是重寫,是抄過來當對照組)。
 * 括號感知切割 + 只剝字尾「等」,沒有 decode、沒有角色詞、沒有字尾註記。
 */
function legacy_first_author(?string $raw): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') return null;
    foreach (pn_split_delims($raw) as $p) {
        $p = trim($p);
        if ($p === '' || $p === '等') continue;
        return (string) preg_replace('/\s*等$/u', '', $p);
    }
    return null;
}

/** 與 import.php 的 fuzzy_key() 同一條(去空白與標點、轉小寫) */
function cfi_key(?string $title, ?string $author): ?string
{
    if (!$title || !$author) return null;
    $n = fn($s) => mb_strtolower((string) preg_replace('/[\s\p{P}\p{S}]+/u', '', $s), 'UTF-8');
    return $n($title) . '|' . $n($author);
}

echo "check_fuzzy_impact rev " . CFI_REV . "(唯讀)\n\n";

$pdo = db();
$pdo->exec('SET NAMES utf8mb4');

$oldMap = [];   // 舊 key → [book_id…]
$newMap = [];   // 新 key → [book_id…]
$rows   = [];
$nNoAuthorOld = 0; $nNoAuthorNew = 0; $n = 0;

foreach ($pdo->query("SELECT book_id, title, author, source FROM books") as $r) {
    $n++;
    $bid = (int) $r['book_id'];
    $old = legacy_first_author($r['author']);
    $new = pn_split_names($r['author'])[0]['name'] ?? null;
    if ($old === null) $nNoAuthorOld++;
    if ($new === null) $nNoAuthorNew++;
    $ko = cfi_key($r['title'], $old);
    $kn = cfi_key($r['title'], $new);
    if ($ko !== null) $oldMap[$ko][] = $bid;
    if ($kn !== null) $newMap[$kn][] = $bid;
    $rows[$bid] = ['t' => $r['title'], 's' => $r['source'], 'o' => $old, 'n' => $new, 'ko' => $ko, 'kn' => $kn];
}

printf("掃描 %d 本;第一作者解不出來的:舊 %d 本、新 %d 本\n", $n, $nNoAuthorOld, $nNoAuthorNew);
printf("第一作者字串有變的:%d 本\n\n",
    count(array_filter($rows, fn($x) => $x['o'] !== $x['n'])));

// ★ 「第一作者有變的本數」若是 0,不要當成沒事 —— 幾乎一定是這支沒讀到 books.author
//   或規則根本沒載進來。
if (!array_filter($rows, fn($x) => $x['o'] !== $x['n'])) {
    echo "★★ 第一作者一本都沒變。這不是「規則沒影響」,而是這支或規則沒生效,當場追。\n";
    exit(1);
}

// 新增的合併機會 = 新 key 底下有多本書,而它們的舊 key 不全相同
$newly = [];
foreach ($newMap as $k => $ids) {
    if (count($ids) < 2) continue;
    $oldKeys = array_unique(array_map(fn($b) => (string) $rows[$b]['ko'], $ids));
    if (count($oldKeys) < 2) continue;         // 本來就同 key → 不是這次改動造成的
    $newly[$k] = $ids;
}

// 反向:本來同 key、現在拆開(規則讓比對變嚴,理論上不該發生,發生就要追)
$lost = [];
foreach ($oldMap as $k => $ids) {
    if (count($ids) < 2) continue;
    $newKeys = array_unique(array_map(fn($b) => (string) $rows[$b]['kn'], $ids));
    if (count($newKeys) < 2) continue;
    $lost[$k] = $ids;
}

printf("═══ 影響 ═══\n");
printf("  新增的合併機會   %5d 組(舊 key 不同、新 key 相同)\n", count($newly));
printf("  被拆開的既有配對 %5d 組(舊 key 相同、新 key 不同)← ★ 這個應該是 0\n\n", count($lost));

if ($lost) {
    echo "★★ 有配對被拆開了。人名收斂只會讓 key 變少、不該變多 ——\n"
       . "   出現這個數字表示規則把某些名字**改得更不一致**了,上線前必須查清楚。\n\n";
    $i = 0;
    foreach ($lost as $k => $ids) {
        if ($i++ >= 10) break;
        foreach ($ids as $b) {
            printf("   #%-7d [%-10s] %-34s 舊「%s」→ 新「%s」\n", $b, (string) $rows[$b]['s'],
                mb_strimwidth((string) $rows[$b]['t'], 0, 34, '…', 'UTF-8'),
                (string) $rows[$b]['o'], (string) $rows[$b]['n']);
        }
        echo "   ──\n";
    }
}

echo "── 新增的合併機會(前 {$limit} 組)──\n";
$i = 0;
foreach ($newly as $k => $ids) {
    if ($i++ >= $limit) { echo "   …(共 " . count($newly) . " 組)\n"; break; }
    foreach ($ids as $b) {
        printf("   #%-7d [%-10s] %-34s 作者「%s」→「%s」\n", $b, (string) $rows[$b]['s'],
            mb_strimwidth((string) $rows[$b]['t'], 0, 34, '…', 'UTF-8'),
            (string) $rows[$b]['o'], (string) $rows[$b]['n']);
    }
    echo "   ──\n";
}

if (!empty($opt['tsv'])) {
    $fh = fopen((string) $opt['tsv'], 'w');
    fwrite($fh, "kind\tfuzzy_key\tbook_id\tsource\ttitle\told_first_author\tnew_first_author\n");
    foreach (['newly_mergeable' => $newly, 'newly_split' => $lost] as $kind => $set) {
        foreach ($set as $k => $ids) {
            foreach ($ids as $b) {
                fwrite($fh, implode("\t", [$kind, $k, $b, (string) $rows[$b]['s'],
                    str_replace(["\t", "\n"], ' ', (string) $rows[$b]['t']),
                    (string) $rows[$b]['o'], (string) $rows[$b]['n']]) . "\n");
            }
        }
    }
    fclose($fh);
    echo "\n已寫出 TSV:{$opt['tsv']}\n";
}

echo "\n★ 這支不合併任何東西。既有書的合併要另外跑 merge_duplicate_books.php,\n"
   . "  import.php 的新規則只影響之後新抓進來的書。\n";
exit($lost ? 1 : 0);
