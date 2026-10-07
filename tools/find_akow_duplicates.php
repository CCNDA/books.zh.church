<?php
declare(strict_types=1);

/**
 * 找出麥種(akow)與站上既有書的重複 —— 唯讀,只產 review 清單(2026-09-14)
 *
 * ═══ 為什麼需要這支工具 ═══
 * v1.13.0 匯入 akow 144 個版本後線上抽查發現:**新建的 100 本裡約九成是站上已有的書**
 * (《我不以為恥》《宣教士保羅》《聖經教牧學》《靈魂的衝突》書名一模一樣都沒併)。
 * 根因在 import.php 的 `fuzzy_key()` 第一行:
 *     if (!$title || !$firstAuthor) return null;   // 沒有作者就完全不比對
 * akow 只有 46% 的書有作者欄,而站上既有的麥種書有不少也沒作者 ——
 * 只要任一邊缺作者,模糊比對就不會啟動,必然長出新書。
 * 再加上 akow 書名帶「(正體)」「(繁)」「(正)」尾註、且常用短書名
 * (「大衛寶庫」vs 站上「大衛寶庫（1）：詩篇一至二十六篇」),就算有作者也比不中。
 *
 * ═══ ★ 一個設計上的陷阱(務必理解再改本檔)═══
 * import.php 的 `same_work()`(ISBN 防線用)**不能直接拿來找重複**。
 * 它當「否決條件」安全:ISBN 已經指出候選,它只負責攔下明顯不對的。
 * 當「搜尋條件」很危險,因為**冊次剛好落在最長共同子字串忽略的字元上**:
 *     「每日效法基督1(正)」vs「每日效法基督：耶穌生平靈修365（3）」
 *      共同子字串「每日效法基督」6/7 = 86% → 會判成同一本,實際是第 1 冊對上第 3 冊。
 * 所以本工具在相似度之外**另外比對冊次記號**(數字、上/中/下、卷/冊),
 * 對不上就降級成「待判」,絕不列入高信心。
 *
 * ═══ 輸出 ═══
 * crawler/data/akow_dup_review.tsv(UTF-8 with BOM,Excel 可直開)
 *   判定 / 信心 / 理由 / akow_id / akow書名 / 既有id / 既有書名 / 既有出版社 / 既有ISBN / 冊次
 * 「判定」欄留白給人填:併入 / 不併 / 其他;填完交
 *   php tools/merge_duplicate_books.php --pairs=crawler/data/akow_dup_review.tsv --dry-run
 *
 * 另外印一段 **B 段全站測量**:若把「無作者時以 書名+出版社 比對」納入合併鍵,
 * 全站會多出多少組候選 —— 這是 fuzzy_key 根因修補前必須先量的數字
 * (「聖經」這類無作者書一旦誤併就是災難,所以先量再改)。
 *
 * 用法(主機 CLI,唯讀,不寫資料庫):
 *   php tools/find_akow_duplicates.php
 *   php tools/find_akow_duplicates.php --source=akow   # 日後其他來源可沿用
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/lib/db.php';

$opt    = getopt('', ['source::', 'out::']);
$source = (string) ($opt['source'] ?? 'akow');
$out    = (string) ($opt['out'] ?? dirname(__DIR__) . '/crawler/data/' . $source . '_dup_review.tsv');

/** 去標點空白轉小寫(與 merge_duplicate_books.php 的 norm() 同步) */
function nm(?string $s): string
{
    return mb_strtolower((string) preg_replace('/[\s\p{P}\p{S}]+/u', '', (string) $s), 'UTF-8');
}

/** 去掉書名尾端的字體/版本標記:(正體)(正)(繁)(簡體)(簡)(繁體)(簡體字)／简 … */
function strip_variant(?string $t): string
{
    $t = (string) $t;
    $pat = '/[（(\/／\-－]\s*(簡體字|繁體字|正體字|簡體|繁體|正體|简体|繁体|正体|簡|繁|正|简)\s*[)）]?\s*$/u';
    while (preg_match($pat, $t)) {
        $t = (string) preg_replace($pat, '', $t);
    }
    return trim($t);
}

/** 冊次記號:阿拉伯數字、上/中/下、卷N、第N冊。回傳排序後的字串集合。
 *  ★ 這是本工具與 same_work() 最關鍵的差別 —— 冊次不一致一律不自動併。 */
function vol_tokens(string $t): array
{
    $v = [];
    // 全形數字轉半形後抓所有數字
    $h = strtr($t, ['０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4',
                    '５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9']);
    if (preg_match_all('/\d+/u', $h, $m)) {
        foreach ($m[0] as $n) {
            // 四位數多半是年份或 ISBN 片段,不是冊次
            if (mb_strlen($n) <= 2) $v[] = ltrim($n, '0') ?: '0';
        }
    }
    foreach (['上', '中', '下'] as $c) {
        if (mb_strpos($t, $c) !== false) $v[] = $c;
    }
    $v = array_values(array_unique($v));
    sort($v);
    return $v;
}

/** 最長共同子字串長度 */
function lcs_len(string $a, string $b): int
{
    $n = mb_strlen($a, 'UTF-8');
    $m = mb_strlen($b, 'UTF-8');
    if ($n === 0 || $m === 0) return 0;
    $prev = array_fill(0, $m + 1, 0);
    $best = 0;
    $bc = [];
    for ($j = 1; $j <= $m; $j++) $bc[$j] = mb_substr($b, $j - 1, 1, 'UTF-8');
    for ($i = 1; $i <= $n; $i++) {
        $cur = array_fill(0, $m + 1, 0);
        $ca = mb_substr($a, $i - 1, 1, 'UTF-8');
        for ($j = 1; $j <= $m; $j++) {
            if ($ca === $bc[$j]) {
                $cur[$j] = $prev[$j - 1] + 1;
                if ($cur[$j] > $best) $best = $cur[$j];
            }
        }
        $prev = $cur;
    }
    return $best;
}

$pdo = db();
$pdo->exec('SET NAMES utf8mb4');

// ── 載入全站已上架書(一次)──────────────────────────────────
$all = [];
$sql = "SELECT b.book_id, b.title, b.author, b.publisher, b.isbn13, b.source
          FROM books b WHERE b.is_published = 1";
foreach ($pdo->query($sql) as $r) {
    $r['book_id'] = (int) $r['book_id'];
    $r['nt'] = nm(strip_variant($r['title']));
    if ($r['nt'] === '') continue;
    $all[$r['book_id']] = $r;
}
echo '載入已上架書 ' . count($all) . " 筆\n";

// 前三字索引:避免 N×M 全比(十萬筆書 × 上百本來源書)
$prefix = [];
foreach ($all as $id => $r) {
    $p = mb_substr($r['nt'], 0, 3, 'UTF-8');
    $prefix[$p][] = $id;
}

// ── 本次來源新建的書 ────────────────────────────────────────
$mine = [];
$st = $pdo->prepare("SELECT DISTINCT b.book_id FROM books b
                       JOIN editions e ON e.book_id = b.book_id AND e.source = :s
                      WHERE b.source = :s2");
$st->execute([':s' => $source, ':s2' => $source]);
foreach ($st->fetchAll() as $r) {
    $id = (int) $r['book_id'];
    if (isset($all[$id])) $mine[$id] = $all[$id];
}
echo "本次由 {$source} 新建的書 " . count($mine) . " 筆\n";
if (!$mine) exit("沒有 source='{$source}' 的書,無事可做\n");

// ── 逐本找候選 ──────────────────────────────────────────────
$rows = [];
$stat = ['高' => 0, '中' => 0, '待判' => 0, '無對應' => 0];
foreach ($mine as $id => $a) {
    $cands = [];
    // 候選池:前三字相同者(取前 1~3 字各試一次,涵蓋短書名)
    for ($k = 3; $k >= 1; $k--) {
        $p = mb_substr($a['nt'], 0, $k, 'UTF-8');
        foreach ($prefix[$p] ?? [] as $oid) {
            if ($oid === $id || isset($cands[$oid])) continue;
            $cands[$oid] = $all[$oid];
        }
        if ($cands) break;
    }
    // ★ 每本書只輸出「最佳候選」一列 —— 第一版把所有候選都列出來,100 本書產出 457 列,
    //   人根本看不完(前三字相同就入選,雜訊很多)。這裡先全部評分,最後只留最好的一個,
    //   其餘只記筆數與 id,需要時再回查。
    $best = null;      // [排序分, 列資料]
    $others = [];
    $va = vol_tokens($a['title']);
    foreach ($cands as $oid => $o) {
        if ($o['source'] === $source && isset($mine[$oid])) continue;   // 同批自己人不比
        $s1 = $a['nt'];
        $s2 = $o['nt'];
        $min = min(mb_strlen($s1, 'UTF-8'), mb_strlen($s2, 'UTF-8'));
        if ($min < 2) continue;

        $reason = '';
        $conf   = '';
        $ratio  = 0.0;
        if ($s1 === $s2) {
            $conf = '高'; $reason = '書名正規化後完全相同'; $ratio = 1.0;
        } elseif (mb_strpos($s1, $s2, 0, 'UTF-8') !== false || mb_strpos($s2, $s1, 0, 'UTF-8') !== false) {
            $conf = '高'; $reason = '一方書名包含另一方(副標/書系前綴)';
            $ratio = $min / max(mb_strlen($s1, 'UTF-8'), mb_strlen($s2, 'UTF-8'));
        } else {
            $l = lcs_len($s1, $s2);
            if ($l >= 3 && $l / $min >= 0.6) {
                $conf = '中'; $reason = "共同子字串 {$l} 字,占短書名 " . round($l / $min * 100) . '%';
                $ratio = $l / $min;
            } else {
                continue;   // 不夠像,不列
            }
        }

        // ★ 冊次比對:對不上就降級待判(每日效法基督 1 vs 3 的教訓)
        $vo = vol_tokens($o['title']);
        if ($va !== $vo) {
            $conf = '待判';
            $reason .= ';但冊次記號不同(' . (implode(',', $va) ?: '無') . ' vs '
                     . (implode(',', $vo) ?: '無') . ')';
        }
        // 出版社佐證:同為麥種系列出版社的,信心較高;完全不同社的降為待判
        $pubA = nm($a['publisher']);
        $pubO = nm($o['publisher']);
        $sameFamily = $pubA !== '' && $pubO !== ''
                   && (mb_strpos($pubO, '麥種') !== false || mb_strpos($pubA, $pubO) !== false
                       || mb_strpos($pubO, $pubA) !== false);
        if (!$sameFamily && $conf !== '待判') {
            $conf = '待判';
            $reason .= ';出版社不同(' . ($o['publisher'] ?: '空') . ')';
        }

        $rank  = ['高' => 3, '中' => 2, '待判' => 1][$conf];
        $score = $rank * 100 + $ratio * 10 + ($o['isbn13'] ? 1 : 0);   // 有 ISBN 的既有書優先當保留對象
        $row   = ['', $conf, $reason, $id, $a['title'], $oid, $o['title'],
                  $o['publisher'] ?? '', $o['isbn13'] ?? '',
                  implode(',', $va) . ' / ' . implode(',', $vo), ''];
        if ($best === null || $score > $best[0]) {
            if ($best !== null) $others[] = $best[1][5];
            $best = [$score, $row];
        } else {
            $others[] = $oid;
        }
    }
    if ($best === null) {
        $stat['無對應']++;
        $rows[] = ['', '無對應', '找不到相似的既有書(可能真的是新書)', $id, $a['title'], '', '', '', '', '', ''];
        continue;
    }
    $row = $best[1];
    $row[10] = $others ? ('另有 ' . count($others) . ' 個候選:'
                          . implode(',', array_slice($others, 0, 5))) : '';
    $rows[] = $row;
    $stat[$row[1]]++;
}

// ── 輸出 TSV ───────────────────────────────────────────────
$fh = fopen($out, 'w');
fwrite($fh, "\xEF\xBB\xBF");   // BOM,Excel 直開不亂碼
fwrite($fh, implode("\t", ['判定(填:併入/不併/其他)', '信心', '理由', 'akow_id', 'akow書名',
                           '既有id', '既有書名', '既有出版社', '既有ISBN', '冊次(新/舊)',
                           '其他候選']) . "\n");
foreach ($rows as $r) {
    fwrite($fh, implode("\t", array_map(fn($x) => str_replace(["\t", "\n"], ' ', (string) $x), $r)) . "\n");
}
fclose($fh);

echo "\n== A 段:{$source} 重複比對 ==\n";
foreach ($stat as $k => $v) echo sprintf("  %-6s %5d 組\n", $k, $v);
echo '清單已寫入:' . $out . '(共 ' . count($rows) . " 列,每本書一列)\n";
echo "  高 = 書名相同或一方包含另一方,且冊次與出版社都對得上 → 多半可直接併\n";
echo "  中 = 共同子字串過半 → 要看一眼\n";
echo "  待判 = 冊次或出版社對不上 → **一定要人看**,自動併會出事\n";

// ── B 段:全站測量(唯讀,只算數字)─────────────────────────
// fuzzy_key 根因修補前必須先量:若把「無作者時以 書名+出版社 當合併鍵」納入,
// 全站會多出多少組候選?「聖經」這類無作者書一旦誤併就是災難,所以先量再改。
$noAuthorKey = [];
$withAuthor  = 0;
foreach ($all as $r) {
    if (trim((string) $r['author']) !== '') { $withAuthor++; continue; }
    $p = nm($r['publisher']);
    if ($p === '') continue;
    $noAuthorKey[$r['nt'] . '|' . $p][] = $r;
}
$groups = array_filter($noAuthorKey, fn($g) => count($g) > 1);
$affected = 0;
foreach ($groups as $g) $affected += count($g) - 1;
echo "\n== B 段:全站測量(僅統計,未變更任何邏輯)==\n";
echo '已上架書 ' . count($all) . " 筆,其中有作者 {$withAuthor} 筆、無作者 " . (count($all) - $withAuthor) . " 筆\n";
echo '若「無作者時以 書名+出版社」當合併鍵:會形成 ' . count($groups) . ' 組、'
   . "牽動 {$affected} 筆書\n";
echo "  ★ 這個數字若很大,代表不能無條件開啟 —— 同名不同版的聖經、詩本、教材\n";
echo "     會被併成一本。先看下面前 20 組長什麼樣再決定。\n";
$i = 0;
foreach ($groups as $k => $g) {
    echo sprintf("  %-40s %d 筆:%s\n", mb_substr($g[0]['title'], 0, 20, 'UTF-8'),
                 count($g), implode(',', array_column($g, 'book_id')));
    if (++$i >= 20) { echo '  …(其餘 ' . (count($groups) - 20) . " 組略)\n"; break; }
}
