<?php
declare(strict_types=1);

/**
 * REST API 前端控制器
 * GET /api/categories        分類清單(含各類書數)
 * GET /api/books             書目清單:q(關鍵字)、category(分類id)、page、per_page
 * GET /api/books/{id}        單書完整資訊
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

    $where  = ['b.is_published = 1'];
    $params = [];

    if ($q !== '') {
        $where[] = '(b.title LIKE :q OR b.subtitle LIKE :q OR b.author LIKE :q
                     OR b.translator LIKE :q OR b.publisher LIKE :q
                     OR b.summary LIKE :q OR b.isbn13 = :isbn OR b.isbn10 = :isbn)';
        $params[':q']    = '%' . $q . '%';
        $params[':isbn'] = str_replace('-', '', $q);
    }
    if ($category > 0) {
        $where[] = 'b.category_id = :cat';
        $params[':cat'] = $category;
    }
    $whereSql = implode(' AND ', $where);

    $stmt = db()->prepare("SELECT COUNT(*) AS total FROM books b WHERE $whereSql");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $sql = "SELECT b.book_id, b.title, b.subtitle, b.author, b.translator,
                   b.publisher, b.publish_date, b.isbn13, b.cover_url,
                   b.summary, c.category_id, c.name AS category_name
            FROM books b
            LEFT JOIN categories c ON c.category_id = b.category_id
            WHERE $whereSql
            ORDER BY b.created_at DESC, b.book_id DESC
            LIMIT :limit OFFSET :offset";
    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['book_id'] = (int) $r['book_id'];
        $r['category_id'] = $r['category_id'] !== null ? (int) $r['category_id'] : null;
    }

    json_data([
        'items'    => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int) ceil($total / $perPage),
    ]);
}

function get_book(int $id): never
{
    $stmt = db()->prepare(
        'SELECT b.*, c.code AS category_code, c.name AS category_name
         FROM books b
         LEFT JOIN categories c ON c.category_id = b.category_id
         WHERE b.book_id = :id AND b.is_published = 1'
    );
    $stmt->execute([':id' => $id]);
    $book = $stmt->fetch();
    if (!$book) {
        json_error('找不到此書', 404);
    }
    $book['book_id'] = (int) $book['book_id'];
    $book['category_id'] = $book['category_id'] !== null ? (int) $book['category_id'] : null;
    $book['is_published'] = (int) $book['is_published'];
    $book['buy_links'] = $book['buy_links'] ? json_decode($book['buy_links'], true) : [];
    json_data($book);
}
