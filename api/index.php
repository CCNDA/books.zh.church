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

function get_books(): never
{
    $q        = trim((string) ($_GET['q'] ?? ''));
    $category = (int) ($_GET['category'] ?? 0);
    $page     = max(1, (int) ($_GET['page'] ?? 1));
    $perPage  = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));

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

    json_data([
        'items'    => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int) ceil($total / $perPage),
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

    // 版本(含出版者、識別碼、格式/價格)
    $stmt = db()->prepare(
        'SELECT e.edition_id, e.edition_statement, e.publish_date, e.place_of_publication,
                e.page_count, e.binding, e.dimensions, e.source, e.source_url,
                pub.name_zh AS publisher_name
         FROM editions e LEFT JOIN publishers pub ON pub.publisher_id = e.publisher_id
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
            $e['edition_id']  = (int) $eid;
            $e['page_count']  = $e['page_count'] !== null ? (int) $e['page_count'] : null;
            $e['identifiers'] = $idsByEd[$eid] ?? [];
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
    $book['links'] = $stmt->fetchAll();

    json_data($book);
}
