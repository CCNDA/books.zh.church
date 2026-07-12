<?php
declare(strict_types=1);

/**
 * REST API 前端控制器
 * GET /api/categories        分類清單(含各類書數)
 * GET /api/books             書目清單:q(關鍵字)、category(分類id)、page、per_page
 *                            讀 v_book_list 檢視表(關聯優先、平面後備)
 * GET /api/books/{id}        單書完整資訊(含 contributors/editions/series/subjects/links)
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
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['book_id'] = (int) $r['book_id'];
        $r['category_id'] = $r['category_id'] !== null ? (int) $r['category_id'] : null;
        // 清單頁摘要:優先短書介,退回 summary 截 150 字
        if ($r['summary_short'] === null && $r['summary'] !== null) {
            $r['summary_short'] = mb_substr($r['summary'], 0, 150, 'UTF-8');
        }
        unset($r['summary']);
    }

    json_data([
        'items'    => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_p