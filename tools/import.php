<?php
declare(strict_types=1);

/**
 * 爬蟲 JSONL → 正規化關聯表匯入器(D5)
 *
 * 依 docs/data-mapping-import.md:
 * - 直接寫關聯表:persons/book_persons、publishers、editions、identifiers、
 *   subjects/book_subjects、formats_prices、links、media;books 平面欄位為過渡後備
 * - 跨站合併:ISBN13 同 → 同一 book(Work),各站各建一個 edition(價格各記幣別:campus=TWD、logos=HKD)
 *   無 ISBN → 「正規化書名+第一作者」完全相同才合併;存疑不合併(寧可重複待人工)
 * - 不丟資料:原始紀錄整包存 books.extra JSON(依來源分鍵)
 * - 可重跑:已匯入的版本(source+source_url)自動跳過;封面先記 media,R2 轉存另跑
 *
 * 用法(主機 SSH;JSONL 先 FTP 上傳):
 *   php tools/import.php --file=crawler/data/logos_books.jsonl  --source=logos  --dry-run
 *   php tools/import.php --file=crawler/data/logos_books.jsonl  --source=logos
 *   php tools/import.php --file=crawler/data/campus_books.jsonl --source=campus
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/api/lib/db.php';

$opt    = getopt('', ['file:', 'source:', 'limit::', 'dry-run']);
$file   = $opt['file'] ?? null;
$source = $opt['source'] ?? null;
$limit  = (int) ($opt['limit'] ?? 0);
$dry    = array_key_exists('dry-run', $opt);
if (!$file || !in_array($source, ['campus', 'logos'], true)) {
    exit("用法:php tools/import.php --file=xxx.jsonl --source=campus|logos [--limit=N] [--dry-run]\n");
}
if (!is_file($file)) {
    exit("找不到檔案:$file\n");
}

// ── 工具函式 ─────────────────────────────────────────────

function norm_isbn(?string $s): ?string
{
    if (!$s) return null;
    $s = strtoupper(preg_replace('/[^0-9Xx]/', '', $s));
    if (preg_match('/^(97[89]\d{10})$/', $s)) return $s;                // ISBN13
    if (preg_match('/^\d{9}[\dX]$/', $s)) return $s;                    // ISBN10
    return null;
}

function isbn10_to_13(string $isbn10): string
{
    $core = '978' . substr($isbn10, 0, 9);
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int) $core[$i] * ($i % 2 ? 3 : 1);
    }
    return $core . ((10 - $sum % 10) % 10);
}

/** 任意 ISBN → [isbn13, isbn10](缺者為 null) */
function isbn_pair(?string $raw): array
{
    $n = norm_isbn($raw);
    if (!$n) return [null, null];
    return strlen($n) === 13 ? [$n, null] : [isbn10_to_13($n), $n];
}

/** YYYYMMDD/YYYY-MM-DD/YYYY → [書用 YYYY-MM 或 YYYY, 版本用完整] */
function parse_date(?string $s): array
{
    if (!$s) return [null, null];
    $d = preg_replace('/[^0-9]/', '', $s);
    if (strlen($d) >= 8) return [substr($d, 0, 4) . '-' . substr($d, 4, 2),
                                 substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2)];
    if (strlen($d) >= 6) return [substr($d, 0, 4) . '-' . substr($d, 4, 2), substr($d, 0, 4) . '-' . substr($d, 4, 2)];
    if (strlen($d) >= 4) return [substr($d, 0, 4), substr($d, 0, 4)];
    return [null, null];
}

/** 舊 bug 殘值(「出版社：」等純標籤)視為空 */
function tidy(?string $s): ?string
{
    $s = trim((string) $s);
    if ($s === '' || preg_match('/^[^:：]{0,8}[:：]$/u', $s)) return null;
    return $s;
}

/**
 * 截斷至欄位長度(以字元計,保留完整多位元組)。
 * MySQL VARCHAR(n) 對 utf8mb4 以「字元」計長,故 mb_substr 至 n 字元即安全。
 * 完整原文另整包存於 books.extra,截斷僅影響平面後備欄的顯示,不損資料。
 */
function cap(?string $s, int $n): ?string
{
    if ($s === null) return null;
    return mb_strlen($s, 'UTF-8') > $n ? mb_substr($s, 0, $n, 'UTF-8') : $s;
}

/** 多人名拆分(;、頓號);保留「等」尾註於 credit_text */
function split_names(?string $raw): array
{
    $raw = tidy($raw);
    if (!$raw) return [];
    $parts = preg_split('/[;;、]/u', $raw);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '' || $p === '等') continue;
        $name = preg_replace('/\s*等$/u', '', $p);
        $out[] = ['name' => cap($name, 150), 'credit' => cap($p, 255)]; // persons.name(150)/credit_text(255)
    }
    return $out;
}

/** 模糊合併鍵:書名+第一作者(去空白、轉小寫) */
function fuzzy_key(?string $title, ?string $firstAuthor): ?string
{
    if (!$title || !$firstAuthor) return null;
    $n = fn($s) => mb_strtolower(preg_replace('/\s+/u', '', $s), 'UTF-8');
    return $n($title) . '|' . $n($firstAuthor);
}

/** 重量「850克」→ 850 */
function weight_g(?string $s): ?int
{
    return $s && preg_match('/(\d+)/', $s, $m) ? (int) $m[1] : null;
}

// ── 來源映射(→ 統一中介格式) ───────────────────────────

function map_record(string $source, array $r): array
{
    $isJunkTitle = !tidy($r['title'] ?? null);
    [$isbn13, $isbn10] = isbn_pair($r['isbn'] ?? ($r['isbn_meta'] ?? null));
    [$bDate, $eDate]   = parse_date($r['publish_date'] ?? null);
    $m = [
        'title'          => cap(tidy($r['title'] ?? null), 255),
        'original_title' => cap(tidy($r['title_en'] ?? null), 255),
        'authors'        => split_names($r['authors_raw'] ?? null),
        'translators'    => split_names($r['translators_raw'] ?? null),
        'illustrators'   => split_names($r['illustrators_raw'] ?? null),
        'editors'        => split_names($r['editors_raw'] ?? null),
        'authors_raw'    => cap(tidy($r['authors_raw'] ?? null), 255),
        'publisher'      => cap(tidy($r['publisher'] ?? null), 100), // books.publisher(100)、publishers.name_zh(150) 取小者
        'book_date'      => $bDate,
        'edition_date'   => $eDate,
        'isbn13'         => $isbn13,
        'isbn10'         => $isbn10,
        'page_count'     => isset($r['page_count']) ? (int) $r['page_count'] : null,
        'binding'        => cap(tidy($r['binding'] ?? null), 50),
        'language'       => cap(tidy($r['language'] ?? null), 50),
        'series'         => cap(tidy($r['series_text'] ?? ($r['series'] ?? null)), 255),
        'summary'        => str_replace("\t", "\n", trim((string) ($r['summary'] ?? ''))) ?: null,
        'keywords'       => cap(tidy($r['keywords'] ?? null), 500),
        'dimensions'     => cap(tidy($r['dimensions'] ?? null), 50),
        'weight_g'       => weight_g($r['weight'] ?? null),
        'store_code'     => cap(tidy($r['item_no'] ?? ($r['code'] ?? null)), 30),
        'price'          => isset($r['price_list']) && $r['price_list'] !== '' ? $r['price_list']
                            : ($r['price_sale'] ?? null),
        'currency'       => $source === 'campus' ? 'TWD' : (tidy($r['currency'] ?? null) ?: 'HKD'),
        'cover_url'      => tidy($r['cover_url'] ?? null),
        'source_url'     => $r['source_url'] ?? null,
        'subject_code'   => cap(tidy($r['category_source'] ?? null), 20),
        'subject_label'  => cap(tidy($r['category_text'] ?? null), 150),
        'skip'           => $isJunkTitle,
    ];
    return $m;
}

// ── 預載(可重跑 + 跨站合併的比對基礎) ───────────────────

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

$doneUrls = [];
foreach ($pdo->query("SELECT source_url FROM editions WHERE source_url IS NOT NULL") as $r) {
    $doneUrls[$r['source_url']] = true;
}
$isbnMap = [];
foreach ($pdo->query("SELECT book_id, isbn13 FROM books WHERE isbn13 IS NOT NULL") as $r) {
    $isbnMap[$r['isbn13']] = (int) $r['book_id'];
}
foreach ($pdo->query(
    "SELECT e.book_id, i.id_value FROM identifiers i JOIN editions e ON e.edition_id = i.edition_id
     WHERE i.id_type = 'ISBN13'") as $r) {
    $isbnMap[$r['id_value']] = (int) $r['book_id'];
}
$fuzzyMap = [];
foreach ($pdo->query("SELECT book_id, title, author FROM books") as $r) {
    $first = split_names($r['author'])[0]['name'] ?? null;
    $k = fuzzy_key($r['title'], $first);
    if ($k) $fuzzyMap[$k] = (int) $r['book_id'];
}
$personMap = [];
foreach ($pdo->query("SELECT person_id, name FROM persons") as $r) {
    $personMap[$r['name']] = (int) $r['person_id'];
}
$pubMap = [];
foreach ($pdo->query("SELECT publisher_id, name_zh FROM publishers") as $r) {
    $pubMap[$r['name_zh']] = (int) $r['publisher_id'];
}
$subjMap = [];
foreach ($pdo->query("SELECT subject_id, scheme, code, label FROM subjects") as $r) {
    $subjMap[$r['scheme'] . '|' . $r['code'] . '|' . $r['label']] = (int) $r['subject_id'];
}
echo "預載:editions " . count($doneUrls) . "、isbn " . count($isbnMap)
   . "、fuzzy " . count($fuzzyMap) . "、persons " . count($personMap) . "\n";

// ── 匯入主迴圈 ───────────────────────────────────────────

$stats = ['read' => 0, 'skip_done' => 0, 'skip_bad' => 0, 'new_book' => 0, 'merged' => 0, 'edition' => 0];
$fh = fopen($file, 'r');
$batch = 0;
if (!$dry) $pdo->beginTransaction();

while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $raw = json_decode($line, true);
    if (!is_array($raw)) { $stats['skip_bad']++; continue; }
    $stats['read']++;
    if ($limit && $stats['read'] > $limit) break;

    $m = map_record($source, $raw);
    if ($m['skip'] || !$m['source_url']) { $stats['skip_bad']++; continue; }
    if (isset($doneUrls[$m['source_url']])) { $stats['skip_done']++; continue; }

    // 1. 找/建 book(Work)
    $bookId = null;
    $isMerge = false;
    if ($m['isbn13'] && isset($isbnMap[$m['isbn13']])) {
        $bookId = $isbnMap[$m['isbn13']];
        $isMerge = true;
    } elseif (!$m['isbn13']) {
        $fk = fuzzy_key($m['title'], $m['authors'][0]['name'] ?? null);
        if ($fk && isset($fuzzyMap[$fk])) {
            $bookId = $fuzzyMap[$fk];
            $isMerge = true;
        }
    }

    $extraRec = $raw;
    if ($dry) {
        if (!$bookId) $stats['new_book']++; else $stats['merged']++;
        $stats['edition']++;
        $doneUrls[$m['source_url']] = true;
        if ($m['isbn13'] && !$bookId) $isbnMap[$m['isbn13']] = -1;
        continue;
    }

    if ($bookId === null) {
        $stmt = $pdo->prepare(
            "INSERT INTO books (title, original_title, author, publisher, publish_date,
                                isbn13, isbn10, page_count, binding, language, series,
                                summary, keywords, buy_links, extra, source, is_published)
             VALUES (:t, :ot, :au, :pub, :pd, :i13, :i10, :pc, :bd, :lg, :se, :su, :kw, :bl, :ex, :src, 1)"
        );
        unset($extraRec['summary']); // 已入 books.summary
        $stmt->execute([
            ':t' => $m['title'], ':ot' => $m['original_title'], ':au' => $m['authors_raw'],
            ':pub' => $m['publisher'], ':pd' => $m['book_date'],
            ':i13' => $m['isbn13'], ':i10' => $m['isbn10'], ':pc' => $m['page_count'],
            ':bd' => $m['binding'], ':lg' => $m['language'], ':se' => $m['series'],
            ':su' => $m['summary'], ':kw' => $m['keywords'],
            ':bl' => json_encode([['platform' => $source, 'url' => $m['source_url']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ex' => json_encode([$source => $extraRec], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':src' => $source,
        ]);
        $bookId = (int) $pdo->lastInsertId();
        $stats['new_book']++;
        if ($m['isbn13']) $isbnMap[$m['isbn13']] = $bookId;
        $fk = fuzzy_key($m['title'], $m['authors'][0]['name'] ?? null);
        if ($fk) $fuzzyMap[$fk] = $bookId;
    } else {
        // 合併:只補空欄,不覆蓋;extra 增鍵;buy_links 追加
        $cur = $pdo->prepare("SELECT summary, extra, buy_links FROM books WHERE book_id = :id");
        $cur->execute([':id' => $bookId]);
        $curRow = $cur->fetch();
        if ($curRow['summary'] === null && $m['summary'] !== null) {
            unset($extraRec['summary']);
        }
        $extra = $curRow['extra'] ? (json_decode($curRow['extra'], true) ?: []) : [];
        $extra[$source] = $extraRec;
        $bl = $curRow['buy_links'] ? (json_decode($curRow['buy_links'], true) ?: []) : [];
        $bl[] = ['platform' => $source, 'url' => $m['source_url']];
        $stmt = $pdo->prepare(
            "UPDATE books SET
               original_title = COALESCE(original_title, :ot), author = COALESCE(author, :au),
               publisher = COALESCE(publisher, :pub), publish_date = COALESCE(publish_date, :pd),
               isbn13 = COALESCE(isbn13, :i13), isbn10 = COALESCE(isbn10, :i10),
               page_count = COALESCE(page_count, :pc), binding = COALESCE(binding, :bd),
               language = COALESCE(language, :lg), series = COALESCE(series, :se),
               summary = COALESCE(summary, :su), keywords = COALESCE(keywords, :kw),
               buy_links = :bl, extra = :ex
             WHERE book_id = :id"
        );
        $stmt->execute([
            ':ot' => $m['original_title'], ':au' => $m['authors_raw'], ':pub' => $m['publisher'],
            ':pd' => $m['book_date'], ':i13' => $m['isbn13'], ':i10' => $m['isbn10'],
            ':pc' => $m['page_count'], ':bd' => $m['binding'], ':lg' => $m['language'],
            ':se' => $m['series'], ':su' => $m['summary'], ':kw' => $m['keywords'],
            ':bl' => json_encode($bl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ex' => json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => $bookId,
        ]);
        $stats['merged']++;
    }

    // 2. persons + book_persons(多角色:作者/譯者/繪者/編者;credit_text 保留原樣)
    foreach ([
        'author'      => $m['authors'],
        'translator'  => $m['translators'],
        'illustrator' => $m['illustrators'],
        'editor'      => $m['editors'],
    ] as $role => $people) {
        $order = 0;
        foreach ($people as $a) {
            if (!isset($personMap[$a['name']])) {
                $st = $pdo->prepare("INSERT INTO persons (name) VALUES (:n)");
                $st->execute([':n' => $a['name']]);
                $personMap[$a['name']] = (int) $pdo->lastInsertId();
            }
            $st = $pdo->prepare(
                "INSERT IGNORE INTO book_persons (book_id, person_id, role, role_order, credit_text)
                 VALUES (:b, :p, :role, :o, :c)"
            );
            $st->execute([':b' => $bookId, ':p' => $personMap[$a['name']], ':role' => $role,
                          ':o' => $order++, ':c' => $a['credit']]);
        }
    }

    // 3. publisher
    $pubId = null;
    if ($m['publisher']) {
        if (!isset($pubMap[$m['publisher']])) {
            // uq_name_zh 為 utf8mb4_unicode_ci(不分大小寫/全半形),PHP 陣列鍵卻區分大小寫;
            // 以 upsert 取回既有 id,避免大小寫/全半形變體撞唯一鍵而 1062。
            $st = $pdo->prepare(
                "INSERT INTO publishers (name_zh) VALUES (:n)
                 ON DUPLICATE KEY UPDATE publisher_id = LAST_INSERT_ID(publisher_id)");
            $st->execute([':n' => $m['publisher']]);
            $pubMap[$m['publisher']] = (int) $pdo->lastInsertId();
        }
        $pubId = $pubMap[$m['publisher']];
    }

    // 4. edition(每站一版)
    $st = $pdo->prepare(
        "INSERT INTO editions (book_id, publisher_id, publish_date, page_count, binding,
                               dimensions, weight_g, source, source_url)
         VALUES (:b, :p, :d, :pc, :bd, :dim, :w, :src, :url)"
    );
    $st->execute([
        ':b' => $bookId, ':p' => $pubId, ':d' => $m['edition_date'], ':pc' => $m['page_count'],
        ':bd' => $m['binding'], ':dim' => $m['dimensions'], ':w' => $m['weight_g'],
        ':src' => $source, ':url' => $m['source_url'],
    ]);
    $editionId = (int) $pdo->lastInsertId();
    $doneUrls[$m['source_url']] = true;
    $stats['edition']++;

    // 5. identifiers
    $idRows = [];
    if ($m['isbn13']) $idRows[] = ['ISBN13', $m['isbn13']];
    if ($m['isbn10']) $idRows[] = ['ISBN10', $m['isbn10']];
    if ($m['store_code']) $idRows[] = ['STORE', $m['store_code']];
    foreach ($idRows as [$t, $v]) {
        $st = $pdo->prepare(
            "INSERT IGNORE INTO identifiers (edition_id, id_type, id_value) VALUES (:e, :t, :v)");
        $st->execute([':e' => $editionId, ':t' => $t, ':v' => $v]);
    }

    // 6. 價格(各站幣別)
    if ($m['price'] !== null && is_numeric($m['price'])) {
        $st = $pdo->prepare(
            "INSERT INTO formats_prices (edition_id, media_type, price, currency)
             VALUES (:e, 'print', :p, :c)");
        $st->execute([':e' => $editionId, ':p' => $m['price'], ':c' => $m['currency']]);
    }

    // 7. 購書連結(版本層)
    $st = $pdo->prepare(
        "INSERT INTO links (edition_id, link_type, platform, url)
         VALUES (:e, 'buy', :pf, :u)");
    $st->execute([':e' => $editionId, ':pf' => $source === 'campus' ? '校園書房' : '基道 BookFinder',
                  ':u' => $m['source_url']]);

    // 8. 封面(先記來源網址;R2 轉存腳本後續更新 url_or_path 與 books.cover_url)
    if ($m['cover_url']) {
        $st = $pdo->prepare(
            "INSERT INTO media (edition_id, media_type, url_or_path, is_primary, source_url)
             VALUES (:e, 'cover', :u, 1, :s)");
        $st->execute([':e' => $editionId, ':u' => $m['cover_url'], ':s' => $m['cover_url']]);
    }

    // 9. 來源分類(subjects scheme=campus/logos)
    if ($m['subject_code'] || $m['subject_label']) {
        $label = $m['subject_label'] ?: $m['subject_code'];
        $key = "$source|{$m['subject_code']}|$label";
        if (!isset($subjMap[$key])) {
            // uq_scheme_code_label 同為 unicode_ci;upsert 取回既有 id 防變體撞鍵 1062。
            $st = $pdo->prepare(
                "INSERT INTO subjects (scheme, code, label) VALUES (:s, :c, :l)
                 ON DUPLICATE KEY UPDATE subject_id = LAST_INSERT_ID(subject_id)");
            $st->execute([':s' => $source, ':c' => $m['subject_code'], ':l' => $label]);
            $subjMap[$key] = (int) $pdo->lastInsertId();
        }
        $st = $pdo->prepare("INSERT IGNORE INTO book_subjects (book_id, subject_id) VALUES (:b, :s)");
        $st->execute([':b' => $bookId, ':s'