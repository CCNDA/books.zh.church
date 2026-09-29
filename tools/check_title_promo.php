<?php
declare(strict_types=1);
/**
 * 書名促銷詞盤點 + 剝除 dry-run 對照表(唯讀,不寫任何資料)。
 *
 * ════════ 為什麼需要這支 ════════
 * 2026-09-29 熊哥指出書名不該有「（新書79折）」(book 105504)。
 * 全庫實查:118 本書名混入促銷/狀態詞,散在 14 個來源(elim 54、mezu 20、logos 12…),
 * 且已實測造成重複書 —— 書名是 import.php `fuzzy_key()` 的輸入,
 * 促銷尾註讓跨站比對比不中,於是站上長出第二本:
 *   87839《跟耶穌學安靜…(可另選合購優惠…)》↔ 57881
 *   88945《100個至愛聖經故事【瑕疵商品特價】》↔ 62842
 *   79674《禱告探訪手冊(特價)》↔ 83271、45452
 *
 * ════════ ★★ 為什麼「限量」不在白名單裡(最重要的一條) ════════
 * 「限量」是命中最多的詞(63/118),但它**同時是三種不同的東西**:
 *   (a) 庫存狀態 —— elim 式「(限量)」前綴 32 本,意思是「快賣完了」,
 *       語意等同絕版/缺書。熊哥 2026-09-29 裁示:庫存狀態本輪不動。
 *   (b) **版本標示,是書名真的有的字** ——《一九八四（限量精裝版）》
 *       《賈伯斯傳（限量硬殼精裝）》《生命中不能承受之輕（出版30週年限量紀念）》。
 *       ★ 剝掉會讓「限量精裝版」與「平裝本」變成同名,**反而製造錯誤合併**。
 *   (c) 非書商品的禮盒/組合包。
 * 另有《無可限量：神手中的教會》這種「限量」本來就在書名裡的。
 * ⇒ 本工具**一律不剝含「限量」的片段**;含限量的片段一律降 HOLD 由人判。
 *
 * ★ 方法教訓(寫在這裡免得日後有人放寬):
 *   最早是用線上關鍵字抽樣做分類的,抽到「限量」只有 1 本,於是把它歸進促銷詞。
 *   全庫實查是 63 本,而且三類混在一起。
 *   **拿抽樣去「分類」比拿抽樣去「計數」危險** —— 數字錯了看得出來,
 *   分類錯了會直接寫進剝除規則,然後靜默改壞幾十本書名。
 *
 * ════════ 這支刻意不做什麼 ════════
 * - **不改資料。** 只產對照表,由人逐列勾過,才另外寫 migration。
 * - **不改 import.php。** 那是共用檔,一動影響全站三萬多次跨站合併。
 * - **不自動合併重複書。** 偵測到同名只是候選;合併一律走
 *   find_*_duplicates + merge_duplicate_books.php --pairs 的人工複核
 *   (akow 那次 100 列裡有 5 列是不該併的)。
 * - **不碰「絕版/缺書/售完」(庫存狀態 279 本)與「套書/合售」(245 本)。**
 *
 * ════════ 全形標點怎麼寫(陷阱 11) ════════
 * 括號一律用 \x{FF08} 這種 unicode escape,**不寫字面全形字元** ——
 * 字面全形括號在生成/複製/存檔過程中會退化成半形重複(「[（(]」變成「[((]」),
 * 退化後 regex 照樣編譯得過、只是靜默少比對一半,看不出來。
 * 用 escape 就沒有這個問題,也不必事後用 hex 驗位元組。
 *
 * 用法:
 *   php tools/check_title_promo.php                       # 摘要 + 對照表
 *   php tools/check_title_promo.php --out=/tmp/promo.tsv  # 另存 TSV 給熊哥逐列勾
 *   php tools/check_title_promo.php --show=hold           # 只看要人工判的
 *
 * 結束碼一律 0 —— 這支是盤點工具,不是守門員。
 * 守門(新書名命中就在每日排程叫出來)是另一次改動,要動 crawler/daily_new.sh。
 *
 * 相關:Asana 1218961224853655、收錄判準彙整 1218562704413649
 */

require __DIR__ . '/../api/lib/db.php';

$opt   = getopt('', ['out::', 'show::', 'limit::']);
$out   = $opt['out']  ?? null;
$show  = strtoupper((string) ($opt['show'] ?? 'all'));
$limit = (int) ($opt['limit'] ?? 0);

// ★ 剝除規則**不在這支裡**,在 tools/lib_title.php —— import.php 用的是同一份。
//   兩邊各寫一份的話,「驗過的規則」與「實際跑的規則」會悄悄分家。
require_once __DIR__ . '/lib_title.php';

/** 疑似非書商品 —— 只標記,不處理。屬「收錄判準」1218562704413649。 */
const NONBOOK = '(?:禮盒|明信片|木筷|木雕|香水|杯墊|書卡|小卡|透卡|骨盤枕|序號卡|點播機|聖經機|桌遊|牛奶棒|掛件|打氣筒|音樂專輯|SAMPLERS|USB|DVD|CD)';

/** 書名正規化(與 check_new_books.php 同一套白名單作法):只留中日韓與英數,轉小寫。 */
function norm_title(?string $s): string
{
    if ($s === null || $s === '') {
        return '';
    }
    preg_match_all('/[0-9A-Za-z\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}\x{f900}-\x{faff}]+/u', $s, $m);
    return mb_strtolower(implode('', $m[0]), 'UTF-8');
}

$pdo = db();

// ── 1. 全站書名索引(含下架:「有沒有收過」與「有沒有上架」是兩件事) ──────────
echo "載入站上書目…\n";
$index  = [];   // 正規化書名 → [book_id, …]
$titles = [];   // book_id → 原書名
foreach ($pdo->query("SELECT book_id, title FROM books")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $id           = (int) $r['book_id'];
    $titles[$id]  = (string) $r['title'];
    $n            = norm_title($r['title']);
    if ($n !== '') {
        $index[$n][] = $id;
    }
}
echo '  共 ' . count($titles) . " 本\n\n";

// ── 2. 取出命中的書 ─────────────────────────────────────────────────────
//    ★ SQL 這層故意寬(含「限量」「預購」一起撈):寧可多撈讓 PHP 判掉,不要在 SQL 就漏。
//    ★★ 這串字必須與稽核 SQL database/migrations/2026-09-29_title_promo_audit.sql
//        第 3、4 部分**完全相同**,否則兩邊的「命中幾本」會各說各話。
//        2026-09-29 第一版少了「預購|促銷|特惠|下殺|破盤」,主機跑出 109 本、
//        Navicat 稽核跑出 118 本。逐本比對過,差的 9 本全是「只含預購」的
//        (74713、74720、74721、74723、94851、98651、105475–105477,elim/cosmiccare 的 2027 年曆與預購書),
//        它們一律落在 EXCLUDED,所以 AUTO/HOLD 兩邊完全相同 —— 但**數字兜不攏本身就要修**,
//        而且熊哥若日後把「預購」納入白名單,撈取層不必再改一次。
$sql = "SELECT b.book_id, b.title, b.is_published,
               GROUP_CONCAT(DISTINCT e.source ORDER BY e.source SEPARATOR ',') AS srcs
          FROM books b
          LEFT JOIN editions e ON e.book_id = b.book_id
         WHERE b.title REGEXP '[0-9]+[[:space:]]*折|特價|優惠|預購|免運|限時|限量|瑕疵|促銷|特惠|下殺|破盤'
         GROUP BY b.book_id, b.title, b.is_published
         ORDER BY srcs, b.book_id";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// ── 3. 逐本判定 ─────────────────────────────────────────────────────────
$results = [];
$tally   = ['AUTO' => 0, 'HOLD' => 0, 'EXCLUDED' => 0];

foreach ($rows as $r) {
    $id    = (int) $r['book_id'];
    $title = (string) $r['title'];
    $srcs  = (string) ($r['srcs'] ?? '');
    $pub   = (int) $r['is_published'];

    $res      = strip_title_promo($title);
    $clean    = $res['clean'];
    $stripped = $res['stripped'];
    $hold     = $res['hold'];
    $hasPromo = (bool) preg_match('/' . TP_PROMO . '/u', $title);

    // 3c. 分類
    if (!$hasPromo) {
        $verdict = 'EXCLUDED';                       // 只命中「限量」等不在白名單的詞
    } elseif ($hold || $clean === '' || mb_strlen($clean, 'UTF-8') < 2) {
        $verdict = 'HOLD';
    } elseif ($stripped) {
        $verdict = 'AUTO';
    } else {
        // ★ 命中白名單詞、但兩條規則都沒吃到(詞在書名中間、沒有括號)。
        //   這種**不可以當成沒事** —— 規則沒涵蓋到,正是要人看的那一類。
        $verdict = 'HOLD';
        $hold[]  = '命中促銷詞但規則沒吃到(詞在句中且無括號),要人工看';
    }
    $tally[$verdict]++;

    // 3d. 剝完會不會撞到站上另一本?★ 只報候選,絕不自動合併。
    $dups = [];
    if ($verdict === 'AUTO' && $clean !== $title) {
        foreach ($index[norm_title($clean)] ?? [] as $other) {
            if ($other !== $id) {
                $dups[] = $other . '《' . $titles[$other] . '》';
            }
        }
    }

    $results[] = [
        'book_id'  => $id,
        'verdict'  => $verdict,
        'srcs'     => $srcs,
        'pub'      => $pub,
        'title'    => $title,
        'clean'    => $verdict === 'AUTO' ? $clean : '',
        'stripped' => implode(' + ', $stripped),
        'hold'     => implode(' | ', $hold),
        'dups'     => implode(' ; ', $dups),
        'nonbook'  => preg_match('/' . NONBOOK . '/ui', $title) ? 'Y' : '',
    ];
}

// ── 3.5 ★★ 同一批剝完之後,彼此會不會撞成同名? ─────────────────────────
// 2026-09-29 上線後才發現的漏洞:原本的撞名偵測只比「剝後書名 vs 站上既有書名」,
// **沒有比「這批 AUTO 彼此之間」**。結果:
//   96943「Good TV DVD 特價199元」與 96945「Good TV DVD 特價99元」
//   剝完雙雙變成「Good TV DVD」—— 價格是這兩筆唯一的區別,剝掉就分不出來了。
// ★ 這正是「破壞性清洗」要防的事,而 dry-run 沒攔下來,因為它問錯了問題。
// → 同批互撞一律降 HOLD:規則不該在「剝掉之後兩本一模一樣」時還說可以剝。
$byClean = [];
foreach ($results as $i => $x) {
    if ($x['verdict'] === 'AUTO') {
        $byClean[norm_title($x['clean'])][] = $i;
    }
}
foreach ($byClean as $idxs) {
    if (count($idxs) < 2) {
        continue;
    }
    $ids = implode('、', array_map(static fn($i) => $results[$i]['book_id'], $idxs));
    foreach ($idxs as $i) {
        $results[$i]['verdict'] = 'HOLD';
        $results[$i]['hold']    = '剝完會與同批的 ' . $ids . ' 同名(價格/數量可能是唯一區別),要人工看';
        $results[$i]['clean']   = '';
        $results[$i]['dups']    = '';
        $tally['AUTO']--;
        $tally['HOLD']++;
    }
}

// ── 4. 摘要 ─────────────────────────────────────────────────────────────
echo "════ 摘要 ════\n";
printf("  SQL 撈到            %d 本(含「限量」一起撈)\n", count($rows));
printf("  AUTO(規則可直接剝)  %d 本 ← 括號段落裡只有促銷詞,或字尾單一促銷詞\n", $tally['AUTO']);
printf("  HOLD(要人工判)      %d 本 ← 含「限量」/段落還有其他內容/規則沒吃到\n", $tally['HOLD']);
printf("  EXCLUDED(本輪不動)  %d 本 ← 只命中「限量」等不在白名單的詞\n", $tally['EXCLUDED']);

$dupN  = count(array_filter($results, static fn($x) => $x['dups'] !== ''));
$nbN   = count(array_filter($results, static fn($x) => $x['nonbook'] === 'Y'));
$nbPub = count(array_filter($results, static fn($x) => $x['nonbook'] === 'Y' && $x['pub'] === 1));
printf("\n  剝完撞到站上同名書  %d 本 ★ 這是候選,不是合併清單\n", $dupN);
printf("  疑似非書商品        %d 本(其中上架中 %d 本)★ 屬收錄判準 1218562704413649\n", $nbN, $nbPub);

echo "\n  來源分布(AUTO + HOLD):\n";
$bySrc = [];
foreach ($results as $x) {
    if ($x['verdict'] === 'EXCLUDED') {
        continue;
    }
    foreach (explode(',', $x['srcs']) as $s) {
        if ($s !== '') {
            $bySrc[$s] = ($bySrc[$s] ?? 0) + 1;
        }
    }
}
arsort($bySrc);
foreach ($bySrc as $s => $n) {
    printf("    %-12s %d\n", $s, $n);
}

// ── 5. 對照表 ───────────────────────────────────────────────────────────
$pick = array_values(array_filter(
    $results,
    static fn($x) => $show === 'ALL' ? true : ($x['verdict'] === $show)
));
if ($limit > 0) {
    $pick = array_slice($pick, 0, $limit);
}

$lines = [implode("\t", [
    'book_id', '判定', '來源', '上架', '原書名', '剝後書名', '剝掉的段落', '人工判的原因', '撞到的同名書', '疑似非書',
])];
foreach ($pick as $x) {
    $lines[] = implode("\t", [
        $x['book_id'], $x['verdict'], $x['srcs'], $x['pub'],
        $x['title'], $x['clean'], $x['stripped'], $x['hold'], $x['dups'], $x['nonbook'],
    ]);
}

if ($out) {
    file_put_contents($out, implode("\n", $lines) . "\n");
    echo "\n對照表已寫到 $out(" . count($pick) . " 列)\n";
} else {
    echo "\n════ 對照表(show=" . strtolower($show) . ',共 ' . count($pick) . " 列)════\n";
    echo implode("\n", $lines) . "\n";
}

echo "\n★ 下一步:熊哥逐列勾過 AUTO 與 HOLD,確認後才寫 migration 改書名。\n";
echo "★ 「撞到的同名書」那一欄是候選,合併另走人工複核,不可依這份直接併。\n";
exit(0);
