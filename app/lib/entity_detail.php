<?php
declare(strict_types=1);

/**
 * 作者頁／出版社頁的資料（API GET /api/persons/{id}、/api/publishers/{id} 與 person.php、publisher.php 共用）。
 * 2026-11-27 小芯（Asana 1220588345978224；熊叔叔 11/24 TG「甲 先做頁面不放簡介」）。
 * 跟 book_detail.php 同一個做法：API 與伺服器輸出用同一個函式，內容不會分岔。
 * 只算上架書（is_published=1）。找不到回 null。
 */

require_once __DIR__ . '/db.php';

const ENTITY_PAGE_BOOKS = 24;   // 頁面上直接列出的書數；看全部走首頁篩選 /?person=／/?publisher=
// 書單新到舊；打錯的未來年份（超過明年）當作沒有日期排最後（小布 11/27 審查一）
const ENTITY_SORT = "(b.publish_date IS NULL OR b.publish_date NOT REGEXP '^[12][0-9]{3}' OR LEFT(b.publish_date, 4) + 0 > YEAR(CURDATE()) + 1), b.publish_date DESC";

function person_detail(int $id): ?array
{
    $st = db()->prepare('SELECT person_id, name, name_en FROM persons WHERE person_id = :id');
    $st->execute([':id' => $id]);
    $p = $st->fetch();
    if (!$p) {
        return null;
    }
    $st = db()->prepare(
        'SELECT bp.role, COUNT(DISTINCT bp.book_id) AS book_count
           FROM book_persons bp
           JOIN books b ON b.book_id = bp.book_id AND b.is_published = 1
          WHERE bp.person_id = :id
          GROUP BY bp.role
          ORDER BY book_count DESC, bp.role');
    $st->execute([':id' => $id]);
    $roles = array_map(static fn(array $r): array =>
        ['role' => (string) $r['role'], 'book_count' => (int) $r['book_count']], $st->fetchAll());

    $st = db()->prepare(
        'SELECT COUNT(DISTINCT bp.book_id)
           FROM book_persons bp
           JOIN books b ON b.book_id = bp.book_id AND b.is_published = 1
          WHERE bp.person_id = :id');
    $st->execute([':id' => $id]);
    $total = (int) $st->fetchColumn();

    // 合作過的人：同一本（上架）書上出現的其他人，依共同書數排序
    $st = db()->prepare(
        'SELECT p2.person_id, p2.name, p2.name_en, COUNT(DISTINCT me.book_id) AS shared
           FROM book_persons me
           JOIN books b ON b.book_id = me.book_id AND b.is_published = 1
           JOIN book_persons other ON other.book_id = me.book_id AND other.person_id <> me.person_id
           JOIN persons p2 ON p2.person_id = other.person_id
          WHERE me.person_id = :id
          GROUP BY p2.person_id, p2.name, p2.name_en
          ORDER BY shared DESC, p2.name
          LIMIT 10');
    $st->execute([':id' => $id]);
    $co = array_map(static fn(array $r): array => [
        'person_id' => (int) $r['person_id'], 'name' => $r['name'], 'name_en' => $r['name_en'],
        'shared_books' => (int) $r['shared']], $st->fetchAll());

    return [
        'person_id'     => (int) $p['person_id'],
        'name'          => $p['name'],
        'name_en'       => $p['name_en'],
        'book_count'    => $total,
        'roles'         => $roles,
        'collaborators' => $co,
    ];
}

/** 正規社 id 群組裡的所有 publisher_id（變體也算同一家）。 */
function publisher_group_ids(int $canonicalId): array
{
    $st = db()->prepare('SELECT publisher_id FROM publishers WHERE COALESCE(canonical_id, publisher_id) = :cid');
    $st->execute([':cid' => $canonicalId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) ?: [$canonicalId];
}

/**
 * 任一變體 id 都回正規社（COALESCE(canonical_id, publisher_id)），書數合併整社所有變體，
 * 跟 /api/books?publisher={id} 的展開方式一致。
 * publish_date 是 VARCHAR（YYYY／YYYY-MM／YYYY-MM-DD），年份取前 4 碼；
 * 資料裡有打錯的未來年份（開發庫見過 2108、2110），超過明年的不算。
 * ★ 年份範圍算的是「本社各版本」的出版年；首頁年份篩選用的是每本書的出版年，兩者定義不同，數字可能對不上（小布 11/27 小意見 2）。
 */
function publisher_detail(int $id): ?array
{
    $st = db()->prepare(
        'SELECT c.publisher_id, c.name_zh
           FROM publishers p
           JOIN publishers c ON c.publisher_id = COALESCE(p.canonical_id, p.publisher_id)
          WHERE p.publisher_id = :id');
    $st->execute([':id' => $id]);
    $pub = $st->fetch();
    if (!$pub) {
        return null;
    }
    $cid = (int) $pub['publisher_id'];
    $in  = implode(',', publisher_group_ids($cid));   // 皆為整數，無注入風險

    $row = db()->query(
        "SELECT COUNT(DISTINCT e.book_id) AS n,
                MIN(CASE WHEN e.publish_date REGEXP '^[12][0-9]{3}' AND LEFT(e.publish_date, 4) + 0 <= YEAR(CURDATE()) + 1 THEN LEFT(e.publish_date, 4) END) AS y0,
                MAX(CASE WHEN e.publish_date REGEXP '^[12][0-9]{3}' AND LEFT(e.publish_date, 4) + 0 <= YEAR(CURDATE()) + 1 THEN LEFT(e.publish_date, 4) END) AS y1
           FROM editions e
           JOIN books b ON b.book_id = e.book_id AND b.is_published = 1
          WHERE e.publisher_id IN ($in)")->fetch();

    $cats = db()->query(
        "SELECT c.category_id, c.name, COUNT(DISTINCT b.book_id) AS book_count
           FROM editions e
           JOIN books b ON b.book_id = e.book_id AND b.is_published = 1
           JOIN categories c ON c.category_id = b.category_id
          WHERE e.publisher_id IN ($in)
          GROUP BY c.category_id, c.name
          ORDER BY book_count DESC, c.name
          LIMIT 5")->fetchAll();

    return [
        'publisher_id'   => $cid,
        'name'           => $pub['name_zh'],
        'book_count'     => (int) $row['n'],
        'year_from'      => $row['y0'] !== null ? (int) $row['y0'] : null,
        'year_to'        => $row['y1'] !== null ? (int) $row['y1'] : null,
        'top_categories' => array_map(static fn(array $r): array => [
            'category_id' => (int) $r['category_id'], 'name' => $r['name'],
            'book_count' => (int) $r['book_count']], $cats),
    ];
}

/**
 * 頁面上直接列出的書（新到舊，最多 ENTITY_PAGE_BOOKS 本）。$type = 'person' | 'publisher'。
 * 只給頁面用；API 的書單照舊走 /api/books?person=／publisher=（有分頁、年份、書房篩選）。
 */
function entity_books(string $type, int $id): array
{
    if ($type === 'person') {
        $st = db()->prepare(
            'SELECT b.book_id, b.title, b.author, b.publish_date, b.cover_url
               FROM books b
              WHERE b.is_published = 1
                AND EXISTS (SELECT 1 FROM book_persons bp WHERE bp.book_id = b.book_id AND bp.person_id = :id)
              ORDER BY ' . ENTITY_SORT . ', b.book_id DESC
              LIMIT ' . ENTITY_PAGE_BOOKS);
        $st->execute([':id' => $id]);
        return $st->fetchAll();
    }
    // 傳進來的若是變體 id，先換成正規社（小布 11/27 小意見 1）
    $st = db()->prepare('SELECT COALESCE(canonical_id, publisher_id) FROM publishers WHERE publisher_id = :id');
    $st->execute([':id' => $id]);
    $in = implode(',', publisher_group_ids((int) ($st->fetchColumn() ?: $id)));
    return db()->query(
        "SELECT b.book_id, b.title, b.author, b.publish_date, b.cover_url
           FROM books b
          WHERE b.is_published = 1
            AND EXISTS (SELECT 1 FROM editions e WHERE e.book_id = b.book_id AND e.publisher_id IN ($in))
          ORDER BY " . ENTITY_SORT . ", b.book_id DESC
          LIMIT " . ENTITY_PAGE_BOOKS)->fetchAll();
}
