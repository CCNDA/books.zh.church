<?php
declare(strict_types=1);

/**
 * 單本書目的完整資料（API GET /api/books/{id} 與書目頁 book.php 共用）。
 * 2026-10-11 由 public/api/index.php 的 get_book() 抽出（Asana 1219388521666079：書目頁伺服器端輸出書目內容）：
 *   原本書目頁只在 <head> 有 meta，內文靠 JavaScript 呼叫 API 才畫出來 → 不執行 JS 的 AI 爬蟲看到空頁。
 *   兩邊用同一個函式，伺服器輸出與 API 回傳的內容才不會分岔。
 * 找不到（或未上架）回 null。呼叫端要先 require app/lib/db.php。
 */

require_once __DIR__ . '/db.php';

const BUY_PLATFORM_LABELS = ['campus' => '校園書房', 'logos' => '基道 BookFinder',
                             'elim' => '以琳書房', 'grace' => '天恩出版社',
                             'wdbook' => '微讀書城', 'methodist' => '衛理書房',
                             'osb' => '格子外面', 'taosheng' => '道聲',
                             'cclm' => '橄欖華宣',
                             'cosmiccare' => '宇宙光',
                             'mezu' => '真哪噠',
                             'twgbr' => '福音書房',
                             'pctpress' => '教會公報社',
                             // 海外(2026-09 起,一站一版 v1.11.0～v1.16.0)
                             'tiendao' => '天道書樓',      // 香港,v1.11.0
                             'akow' => '麥種傳道會',        // 美國,v1.13.0
                             'bappress' => '浸信會出版社',  // 香港,v1.15.0
                             'rockhouse' => '海天書樓'];    // 香港,v1.16.0(先登錄備用)

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
                             'tiendao' => 15, '天道書樓' => 15,
                             // ★ 2026-09-22 補登。麥種(v1.13.0)自上線起就沒登錄過,
                             //   而未登錄者的預設值是 9 → 麥種的購書連結一直被排在
                             //   「道聲」那一格,混在台灣書房中間,與上面那條註解的意圖相反。
                             //   ★ 變體標註的字串要各自登錄:匯入器寫進 links.platform 的是
                             //     「麥種傳道會(正體)」這種**完整字串**,不是代碼;
                             //     少登一種就會落回預設值(天恩(電子書)、微讀書城(簡體)
                             //     早就是這樣處理的)。
                             'akow' => 16, '麥種傳道會' => 16,
                             '麥種傳道會(正體)' => 16, '麥種傳道會(簡體)' => 16,
                             'bappress' => 17, '浸信會出版社' => 17,
                             'rockhouse' => 18, '海天書樓' => 18];

function book_detail(int $id): ?array
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
        return null;
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
    // ★ 2026-09-22:預設值由 9 改為 99。
    //   9 是「道聲」的排序值,等於**沒登錄的平台會被靜默插進台灣書房中間**
    //   (麥種自 v1.13.0 上線起就是這樣)。沒登錄代表我們不知道它該排哪,
    //   正確行為是排到最後、讓它顯眼,而不是躲在中間看不出來。
    usort($buy, static fn (array $a, array $b): int =>
        [BUY_PLATFORM_ORDER[$a['platform']] ?? 99, $a['label']]
        <=> [BUY_PLATFORM_ORDER[$b['platform']] ?? 99, $b['label']]);
    $book['buy_links'] = $buy;    // 所有購書來源(校園/基道/未來新增)
    $book['links']     = $others; // 延伸連結(已排除購書,避免與按鈕重複)

    return $book;
}
