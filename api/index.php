<?php
declare(strict_types=1);

/**
 * REST API 前端控制器
 * GET /api/categories        分類清單(含各類書數)
 * GET /api/books             書目清單:q(關鍵字)、category(分類id)、page、per_page
 *                            讀 v_book_list 檢視表(關聯優先、平面後備)
 * GET /api/books/{id}        單書完整資訊(含 contributors/editions/series/subjects/links)
 * GET /api/books/latest      新進書(limit,預設 6)
 * GET /api/books/popular     本月熱門(當月點擊數排序;limit,預設 6)
 * POST /api/books/{id}/click 點擊回報(公開遙測:僅記 book_id/來源/關鍵字,無個資,
 *                            故為「寫入需 X-Api-Key」規範之例外)
 */

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/response.php';

/**
 * 購書平台顯示名與排序(新增來源時在此擴充即可;platform 代碼由匯入器寫入
 * links.platform / books.buy_links[].platform,未列入者以 platform 原值當顯示名)
 */
const BUY_PLATFORM_LABELS = ['campus' => '校園書房', 'logos' => '基道 BookFinder',
                             'elim' => '以琳書房', 'grace' => '天恩出版社',
                             'wdbook' => '微讀書城', 'methodist' => '衛理書房',
                             'osb' => '格子外面', 'taosheng' => '道聲',
                             'cclm' => '橄欖華宣',
                             'cosmiccare' => '宇宙光',
                             'mezu' => '真哪噠',
                             'twgbr' => '福音書房',
                             'pctpress' => '教會公報社',
                             'tiendao' => '天道書樓'];  // 海外第 1 站(香港,v1.11.0)
// 排序鍵同時涵蓋代碼與中文名(歷史資料 links.platform/buy_links 存的是中文名)
const BUY_PLATFORM_ORDER  = ['campus' => 1, '校園書房' => 1, 'logos' => 2, '基道 BookFinder' => 2, '基道' => 2,
                             'elim' => 3, '以琳書房' => 3, 'grace' => 4, '天恩出版社' => 4, '天恩出版社(電子書)' => 5,
                             'wdbook' => 6, '微讀書城' => 6, '微讀書城(簡體)' => 6,
                             'methodist' => 7, '衛理書房' => 7, '衛理書房(簡體)' => 7,
                             'osb' => 8, '格子外面' => 8,
                             'taosheng' => 9, '道聲' => 9,
                             'cclm' => 10, '橄欖華宣' => 10,
                             'cosmiccare' => 11, '宇宙光' => 11,
                             'mezu' => 12, '真哪噠' => 12,
                             'twgbr' => 13, '福音書房' => 13,
                             'pctpress' => 14, '教會公報社' => 14,
                             // 海外站排在台灣站之後(讀者多數在台灣,台灣購書管道優先顯示)
                             'tiendao' => 15, '天道書樓' => 15];

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 解析路徑:去掉前綴 /api
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = preg_replace('#^.*?/api#', '', $uri) ?: '/';
$path = rtrim($path, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET' && $path === '/categories') {
        get_categories();
    }
    if ($method === 'GET' && $path === '/books') {
        get_books();
    }
    if ($method === 'GET' && $path === '/books/latest') {
        get_books_latest();
    }
    if ($method === 'GET' && $path === '/books/popular') {
        get_books_popular();
    }
    if ($method === 'GET' && $path === '/persons') {
        get_persons();
    }
    if ($method === 'GET' && $path === '/publishers') {
        get_publishers();
    }
    if ($method === 'POST' && preg_match('#^/books/(\d+)/click$#', $path, $m)) {
        post_book_click((int) $m[1]);
    }
    if ($method === 'GET' && preg_match('#^/books/(\d+)$#', $path, $m)) {
        get_book((int) $m[1]);
    }
    json_error('找不到端點', 404);
} catch (PDOException $e) {
    error_log('[api] DB error: ' . $e->getMessage());
    json_error('資料庫錯誤', 500);
}

function get_categories(): never
{
    $sql = 'SELECT c.category_id, c.code, c.name, c.sort_order,
                   COUNT(b.book_id) AS book_count
            FROM categories c
            LEFT JOIN books b ON b.category_id = c.category_id AND b.is_published = 1
            GROUP BY c.category_id, c.code, c.name, c.sort_order
            ORDER BY c.sort_order, c.code';
    $rows = db()->query($sql)->fetchAll();
    foreach ($rows as &$r) {
        $r['category_id'] = (int) $r['category_id'];
        $r['sort_order']  = (int) $r['sort_order'];
        $r['book_count']  = (int) $r['book_count'];
    }
    json_data($rows);
}

/** 作者/貢獻者:關鍵字搜尋(q)或熱門(依著作數);供探索面板自動完成 */
function get_persons(): never
{
    $q     = trim((string) ($_GET['q'] ?? ''));
    $limit = min(50, max(1, (int) ($_GET['limit'] ?? 15)));
    $where  = '';
    $params = [];
    if ($q !== '') {
        $where = 'WHERE p.name LIKE :q1 OR p.name_en LIKE :q2';
        $params[':q1'] = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
    }
    $sql = "SELECT p.person_id, p.name, p.name_en, COUNT(DISTINCT bp.book_id) AS book_count
            FROM persons p
            JOIN book_persons bp ON bp.person_id = p.person_id
            JOIN books b ON b.book_id = bp.book_id AND b.is_published = 1
            $where
            GROUP BY p.person_id, p.name, p.name_en
            ORDER BY book_count DESC, p.name
            LIMIT :limit";
    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['person_id']  = (int) $r['person_id'];
        $r['book_count'] = (int) $r['book_count'];
    }
    json_data($rows);
}

/** 出版社:關鍵字搜尋(q)或熱門(依出版書數) */
function get_publishers(): never
{
    $q     = trim((string) ($_GET['q'] ?? ''));
    $limit = min(50, max(1, (int) ($_GET['limit'] ?? 15)));
    $where  = '';
    $params = [];
    if ($q !== '') {
        $where = 'WHERE cano.name_zh LIKE :q1';
        $params[':q1'] = '%' . $q . '%';
    }
    // 依 canonical 合併同社變體:群組鍵 = COALESCE(canonical_id, publisher_id),顯示正規列名稱
    $sql = "SELECT COALESCE(pub.canonical_id, pub.publisher_id) AS publisher_id,
                   cano.name_zh AS name_zh,
                   COUNT(DISTINCT e.book_id) AS book_count
            FROM publishers pub
            JOIN publishers cano ON cano.publisher_id = COALESCE(pub.canonical_id, pub.publisher_id)
            JOIN editions e ON e.publisher_id = pub.publisher_id
            JOIN books b ON b.book_id = e.book_id AND b.is_published = 1
            $where
            GROUP BY publisher_id, name_zh
            ORDER BY book_count DESC, name_zh
            LIMIT :limit";
    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['publisher_id'] = (int) $r['publisher_id'];
        $r['book_count']   = (int) $r['book_count'];
    }
    json_data($rows);
}

function get_books(): never
{
    $q         = trim((string) ($_GET['q'] ?? ''));
    $category  = (int) ($_GET['category'] ?? 0);
    $person    = (int) ($_GET['person'] ?? 0);     // 作者/貢獻者 person_id 篩選
    $publisher = (int) ($_GET['publisher'] ?? 0);  // 出版社 publisher_id 篩選
    $page      = max(1, (int) ($_GET['page'] ?? 1));
    $perPage   = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));

    $where  = ['v.is_published = 1'];
    $params = [];

    if ($q !== '') {
        // 注意:原生預備語句不可重複使用同名參數,故逐一編號
        $like = '%' . $q . '%';
        $isbn = str_replace('-', '', $q);
        $where[] = '(v.title LIKE :q1 OR v.subtitle LIKE :q2 OR v.author LIKE :q3
                     OR v.translator LIKE :q4 OR v.publisher LIKE :q5
                     OR v.summary LIKE :q6 OR v.isbn13 = :isbn1 OR b.isbn10 = :isbn2
                     OR EXISTS (SELECT 1 FROM identifiers i
                                JOIN editions e ON e.edition_id = i.edition_id
                                WHERE e.book_id = v.book_id AND i.id_value = :isbn3))';
        for ($i = 1; $i <= 6; $i++) {
            $params[":q$i"] = $like;
        }
        $params[':isbn1'] = $isbn;
        $params[':isbn2'] = $isbn;
        $params[':isbn3'] = $isbn;
    }
    if ($category > 0) {
        $where[] = 'v.category_id = :cat';
        $params[':cat'] = $category;
    }
    if ($person > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM book_persons bp
                            WHERE bp.book_id = v.book_id AND bp.person_id = :person)';
        $params[':person'] = $person;
    }
    if ($publisher > 0) {
        // 展開同一正規群組的所有 publisher_id(含變體),以便點任一名稱都涵蓋整社
        $pst = db()->prepare(
            "SELECT publisher_id FROM publishers
             WHERE COALESCE(canonical_id, publisher_id) =
                   (SELECT COALESCE(canonical_id, publisher_id) FROM publishers WHERE publisher_id = :pid)");
        $pst->execute([':pid' => $publisher]);
        $pubIds = array_map('intval', $pst->fetchAll(PDO::FETCH_COLUMN));
        $inList = $pubIds ? implode(',', $pubIds) : (string) (int) $publisher;  // 皆為整數,無注入風險
        $where[] = "EXISTS (SELECT 1 FROM editions e2
                            WHERE e2.book_id = v.book_id AND e2.publisher_id IN ($inList))";
    }
    $whereSql = implode(' AND ', $where);

    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM v_book_list v
         JOIN books b ON b.book_id = v.book_id
         WHERE $whereSql"
    );
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $sql = "SELECT v.book_id, v.title, v.subtitle, v.original_title, v.author, v.translator,
                   v.publisher, v.publish_date, v.isbn13, v.cover_url,
                   v.summary_short, v.summary, v.category_id, c.name AS category_name
            FROM v_book_list v
            JOIN books b ON b.book_id = v.book_id
            LEFT JOIN categories c ON c.category_id = v.category_id
            WHERE $whereSql
            ORDER BY v.created_at DESC, v.book_id DESC
            LIMIT :limit OFFSET :offset";
    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = finish_cards($stmt->fetchAll());

    // 篩選標籤(供前端顯示「作者/出版社:XXX 的書」標題)
    $filter = null;
    if ($person > 0) {
        $st = db()->prepare('SELECT name, name_en FROM persons WHERE person_id = :id');
        $st->execute([':id' => $person]);
        if ($row = $st->fetch()) {
            $filter = ['type' => 'person', 'id' => $person,
                       'name' => $row['name'], 'name_en' => $row['name_en']];
        }
    } elseif ($publisher > 0) {
        $st = db()->prepare(
            'SELECT c.name_zh FROM publishers p
             JOIN publishers c ON c.publisher_id = COALESCE(p.canonical_id, p.publisher_id)
             WHERE p.publisher_id = :id');
        $st->execute([':id' => $publisher]);
        if (($nm = $st->fetchColumn()) !== false) {
            $filter = ['type' => 'publisher', 'id' => $publisher, 'name' => $nm];
        }
    }

    json_data([
        'items'    => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int) ceil($total / $perPage),
        'filter'   => $filter,
    ]);
}

/** 卡片欄位共用處理(型別轉換+短摘要後備) */
function finish_cards(array $rows): array
{
    foreach ($rows as &$r) {
        $r['book_id']     = (int) $r['book_id'];
        $r['category_id'] = $r['category_id'] !== null ? (int) $r['category_id'] : null;
        if (isset($r['click_count'])) {
            $r['click_count'] = (int) $r['click_count'];
        }
        if (array_key_exists('summary', $r)) {
            if ($r['summary_short'] === null && $r['summary'] !== null) {
                $r['summary_short'] = mb_substr($r['summary'], 0, 150, 'UTF-8');
            }
            unset($r['summary']);
        }
    }
    return $rows;
}

/** 新進書:依建檔時間新到舊 */
function get_books_latest(): never
{
    $limit = min(20, max(1, (int) ($_GET['limit'] ?? 6)));
    $stmt = db()->prepare(
        "SELECT v.book_id, v.title, v.subtitle, v.author, v.translator, v.publisher,
                v.publish_date, v.cover_url, v.summary_short, v.summary, v.category_id,
                c.name AS category_name
         FROM v_book_list v
         LEFT JOIN categories c ON c.category_id = v.category_id
         WHERE v.is_published = 1
         ORDER BY v.created_at DESC, v.book_id DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    json_data(finish_cards($stmt->fetchAll()));
}

/** 本月熱門:當月(本月 1 日起)點擊數排序;無資料回空陣列,前端自行隱藏區塊 */
function get_books_popular(): never
{
    $limit = min(20, max(1, (int) ($_GET['limit'] ?? 6)));
    $stmt = db()->prepare(
        "SELECT v.book_id, v.title, v.subtitle, v.author, v.translator, v.publisher,
                v.publish_date, v.cover_url, v.summary_short, v.summary, v.category_id,
                c.name AS category_name, COUNT(k.click_id) AS click_count
         FROM book_clicks k
         JOIN v_book_list v ON v.book_id = k.book_id AND v.is_published = 1
         LEFT JOIN categories c ON c.category_id = v.category_id
         WHERE k.clicked_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
         GROUP BY v.book_id, v.title, v.subtitle, v.author, v.translator, v.publisher,
                  v.publish_date, v.cover_url, v.summary_short, v.summary, v.category_id, c.name
         ORDER BY click_count DESC, v.book_id DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    json_data(finish_cards($stmt->fetchAll()));
}

/** 點擊回報(sendBeacon 友善:body 為 JSON 字串;失敗一律靜默) */
function post_book_click(int $id): never
{
    $body   = json_decode(file_get_contents('php://input') ?: '', true);
    $body   = is_array($body) ? $body : [];
    $source = preg_replace('/[^a-z_]/', '', (string) ($body['source'] ?? 'list'));
    $source = $source !== '' ? substr($source, 0, 20) : 'list';
    $q      = trim((string) ($body['q'] ?? ''));
    $q      = $q === '' ? null : mb_substr($q, 0, 200, 'UTF-8');
    try {
        $stmt = db()->prepare('INSERT INTO book_clicks (book_id, source, q) VALUES (:id, :src, :q)');
        $stmt->execute([':id' => $id, ':src' => $source, ':q' => $q]);
    } catch (PDOException $e) {
        // 不存在的 book_id(FK 擋下)等情況靜默;遙測失敗不影響前端
    }
    json_data(['ok' => true]);
}

function get_book(int $id): never
{
    // 基底:books 平面欄位 + v_book_list 的關聯優先欄位覆蓋
    $stmt = db()->prepare(
        'SELECT b.*,
                v.author   AS v_author,   v.translator   AS v_translator,
                v.publisher AS v_publisher, v.publish_date AS v_publish_date,
                v.isbn13   AS v_isbn13,   v.cover_url    AS v_cover_url,
                c.code AS category_code, c.name AS category_name
         FROM books b
         JOIN v_book_list v ON v.book_id = b.book_id
         LEFT JOIN categories c ON c.category_id = b.category_id
         WHERE b.book_id = :id AND b.is_published = 1'
    );
    $stmt->execute([':id' => $id]);
    $book = $stmt->fetch();
    if (!$book) {
        json_error('找不到此書', 404);
    }

    // 關聯優先欄位覆蓋平面欄位
    foreach (['author', 'translator', 'publisher', 'publish_date', 'isbn13', 'cover_url'] as $f) {
        if ($book['v_' . $f] !== null) {
            $book[$f] = $book['v_' . $f];
        }
        unset($book['v_' . $f]);
    }

    $book['book_id']      = (int) $book['book_id'];
    $book['category_id']  = $book['category_id'] !== null ? (int) $book['category_id'] : null;
    $book['page_count']   = $book['page_count'] !== null ? (int) $book['page_count'] : null;
    $book['is_published'] = (int) $book['is_published'];
    $book['buy_links']    = $book['buy_links'] ? json_decode($book['buy_links'], true) : [];
    $book['extra']        = $book['extra'] ? json_decode($book['extra'], true) : null;

    // 貢獻者(多人多角色,含署名原文)
    $stmt = db()->prepare(
        'SELECT bp.role, bp.role_order, bp.credit_text, p.person_id, p.name, p.name_en
         FROM book_persons bp JOIN persons p ON p.person_id = bp.person_id
         WHERE bp.book_id = :id
         ORDER BY FIELD(bp.role, \'author\',\'editor\',\'translator\',\'illustrator\',
                        \'foreword\',\'advisor\',\'proofreader\',\'contributor\'), bp.role_order'
    );
    $stmt->execute([':id' => $id]);
    $contributors = [];
    foreach ($stmt->fetchAll() as $r) {
        $contributors[$r['role']][] = [
            'person_id'   => (int) $r['person_id'],
            'name'        => $r['name'],
            'name_en'     => $r['name_en'],
            'credit_text' => $r['credit_text'],
        ];
    }
    $book['contributors'] = $contributors ?: null;

    // 版本(含出版者、識別碼、格式/價格);出版者顯示正規社名(canonical),id 也回正規列
    $stmt = db()->prepare(
        'SELECT e.edition_id, e.edition_statement, e.publish_date, e.place_of_publication,
                e.page_count, e.binding, e.dimensions, e.source, e.source_url,
                COALESCE(praw.canonical_id, praw.publisher_id) AS publisher_id,
                pub.name_zh AS publisher_name
         FROM editions e
         LEFT JOIN publishers praw ON praw.publisher_id = e.publisher_id
         LEFT JOIN publishers pub  ON pub.publisher_id  = COALESCE(praw.canonical_id, praw.publisher_id)
         WHERE e.book_id = :id ORDER BY e.publish_date DESC, e.edition_id ASC'
    );
    $stmt->execute([':id' => $id]);
    $editions = $stmt->fetchAll();
    if ($editions) {
        $eids = array_column($editions, 'edition_id');
        $ph = implode(',', array_fill(0, count($eids), '?'));

        $st = db()->prepare("SELECT edition_id, id_type, id_value, note FROM identifiers WHERE edition_id IN ($ph)");
        $st->execute($eids);
        $idsByEd = [];
        foreach ($st->fetchAll() as $r) {
            $idsByEd[$r['edition_id']][] = ['type' => $r['id_type'], 'value' => $r['id_value'], 'note' => $r['note']];
        }

        $st = db()->prepare("SELECT edition_id, media_type, file_format, price, currency, availability FROM formats_prices WHERE edition_id IN ($ph)");
        $st->execute($eids);
        $fpByEd = [];
        foreach ($st->fetchAll() as $r) {
            $fpByEd[$r['edition_id']][] = [
                'media_type' => $r['media_type'], 'file_format' => $r['file_format'],
                'price' => $r['price'] !== null ? (float) $r['price'] : null,
                'currency' => $r['currency'], 'availability' => $r['availability'],
            ];
        }

        foreach ($editions as &$e) {
            $eid = $e['edition_id'];
            $e['edition_id']    = (int) $eid;
            $e['publisher_id']  = $e['publisher_id'] !== null ? (int) $e['publisher_id'] : null;
            $e['page_count']    = $e['page_count'] !== null ? (int) $e['page_count'] : null;
            $e['identifiers']   = $idsByEd[$eid] ?? [];
            $e['formats']     = $fpByEd[$eid] ?? [];
        }
        unset($e);
    }
    $book['editions'] = $editions;

    // 系列
    $stmt = db()->prepare(
        'SELECT s.series_name, bs.series_number
         FROM book_series bs JOIN series s ON s.series_id = bs.series_id
         WHERE bs.book_id = :id'
    );
    $stmt->execute([':id' => $id]);
    $book['series_list'] = $stmt->fetchAll();

    // 主題分類(多套系統)
    $stmt = db()->prepare(
        'SELECT s.scheme, s.code, s.label
         FROM book_subjects bsub JOIN subjects s ON s.subject_id = bsub.subject_id
         WHERE bsub.book_id = :id ORDER BY bsub.weight DESC'
    );
    $stmt->execute([':id' => $id]);
    $book['subjects'] = $stmt->fetchAll();

    // 連結(作品層 + 版本層)
    $stmt = db()->prepare(
        'SELECT l.link_type, l.platform, l.url, l.note
         FROM links l
         WHERE l.book_id = :id1
            OR l.edition_id IN (SELECT edition_id FROM editions WHERE book_id = :id2)
         ORDER BY l.link_type, l.link_id'
    );
    $stmt->execute([':id1' => $id, ':id2' => $id]);
    $linkRows = $stmt->fetchAll();

    // 購書連結彙整:links(link_type='buy',正規層)+ books.buy_links(平面後備)
    // URL 去重、平台固定排序;統一格式 {platform, label, url, note},來源增加時只需擴充上方常數
    $buy = [];
    $addBuy = static function (?string $platform, string $url, ?string $note = null, ?string $label = null) use (&$buy): void {
        $url = trim($url);
        if ($url === '' || isset($buy[$url])) {
            return;
        }
        $buy[$url] = [
            'platform' => $platform,
            'label'    => ($label !== null && $label !== '')
                          ? $label
                          : (BUY_PLATFORM_LABELS[$platform] ?? ($platform !== null && $platform !== '' ? $platform : '購書連結')),
            'url'      => $url,
            'note'     => ($note !== null && $note !== '') ? $note : null,
        ];
    };
    $others = [];
    foreach ($linkRows as $r) {
        if ($r['link_type'] === 'buy') {
            $addBuy($r['platform'], (string) $r['url'], $r['note']);
        } else {
            $others[] = $r;
        }
    }
    foreach ((array) $book['buy_links'] as $l) {
        if (is_string($l)) {
            $addBuy(null, $l);
        } elseif (is_array($l)) {
            $addBuy(isset($l['platform']) ? (string) $l['platform'] : null,
                    (string) ($l['url'] ?? ''),
                    isset($l['note']) ? (string) $l['note'] : null,
                    isset($l['label']) ? (string) $l['label'] : null);
        }
    }
    $buy = array_values($buy);
    usort($buy, static fn (array $a, array $b): int =>
        [BUY_PLATFORM_ORDER[$a['platform']] ?? 9, $a['label']]
        <=> [BUY_PLATFORM_ORDER[$b['platform']] ?? 9, $b['label']]);
    $book['buy_links'] = $buy;    // 所有購書來源(校園/基道/未來新增)
    $book['links']     = $others; // 延伸連結(已排除購書,避免與按鈕重複)

    json_data($book);
}
