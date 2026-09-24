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
                             // 海外(2026-09 起,一站一版 v1.11.0～v1.16.0)
                             'tiendao' => '天道書樓',      // 香港,v1.11.0
                             'akow' => '麥種傳道會',        // 美國,v1.13.0
                             'bappress' => '浸信會出版社',  // 香港,v1.15.0
                             'rockhouse' => '海天書樓'];    // 香港,v1.16.0(先登錄備用)
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
    $yearFrom  = trim((string) ($_GET['year_from'] ?? ''));  // 出版年下限(YYYY)
    $yearTo    = trim((string) ($_GET['year_to'] ?? ''));    // 出版年上限(YYYY)
    $source    = trim((string) ($_GET['source'] ?? ''));     // 來源書房代碼(logos/campus/…)
    $deep      = ($_GET['deep'] ?? '') === '1';              // 1=關鍵字也搜摘要內文(慢)
    $page      = max(1, (int) ($_GET['page'] ?? 1));
    $perPage   = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));

    // ★★ 2026-09-19:篩選與計數一律以 books b 為基準,**完全不碰 v_book_list**。
    //   理由見下方 COUNT 前的註解。v_book_list 只在「拿到本頁 20 個 book_id 之後」
    //   用來取顯示欄位(eq_ref 20 列),不再參與過濾。
    $where  = ['b.is_published = 1'];
    $params = [];
    $joins  = '';   // 額外 JOIN;關鍵字搜尋會接上 book_search 瘦表(見下方說明)

    if ($q !== '') {
        // 2026-09-17(M1-B):原本對 v.title/subtitle/author/translator/publisher/summary
        // 六欄各做一次 LIKE。而 author/translator/publisher 在 v_book_list 裡是
        // 「相關子查詢 + GROUP_CONCAT」→ 每一列都要執行子查詢,65,290 列 × 6 個,
        // 這就是搜尋慢的真因(2026-08-17 判定,2026-09-16 由 view DDL 證實)。
        // 改查 books.search_text 單欄(由 tools/build_search_text.php 維護),
        // 該欄已涵蓋 書名/副標/原文名/作者/譯者/出版社/系列/關鍵字/摘要/ISBN/商品代碼,
        // 因此原本的 isbn13 精確比對與 identifiers EXISTS 都已被涵蓋,一併移除。
        // ★ 不用 FULLTEXT:MariaDB 無 ngram 分詞,對中文無效。
        // ★ 為什麼是兩個 LIKE 而不是一個:搜尋欄裡的 ISBN 沒有連字號,
        //   而使用者可能輸入「978-986-6674-49-5」,故另備一個去連字號的樣式。
        //   注意:原生預備語句不可重複使用同名參數,故逐一編號。
        //
        // ★★ 2026-09-17 改為預設查 search_key(不含摘要)。實測原因:
        //   `search_text LIKE '%…%'` 是 type=ALL 全表掃描(LIKE 中間比對用不到索引),
        //   而 search_text 平均 463 字 × 65,290 列 ≈ 90MB 文字 → 實測 4,946 ms。
        //   瓶頸不在 v_book_list(COUNT 走 view 5,644ms vs 走 books 4,946ms,只差 12%)。
        //   search_key 不含摘要、平均僅 59 字(比值 7.85)→ 預期約 630 ms。
        //   需要搜摘要內文時帶 deep=1,代價是回到數秒級。
        //
        // ★★★ 2026-09-17 第三輪(前兩輪的假設都被實測推翻,記在這裡避免重蹈):
        //   ✗ 假設 v_book_list 被具體化 → EXPLAIN 證明是 MERGE,子查詢都走索引 rows=1
        //   ✗ 假設 search_text 太長 → 拆出 91 字的 search_key 後線上仍 12 秒
        //   ✓ 真因:books 表 DATA_LENGTH **501 MB**(每列 7.7KB,大頭是 extra/summary/
        //     buy_links)。InnoDB 全表掃描以 page 為單位**讀整列**,成本由整列大小決定,
        //     與搜尋欄長度幾乎無關(掃 search_key 4,738ms vs search_text 5,316ms,只差 12%)。
        //   ⇒ 解法是把搜尋欄搬到只有兩欄的 book_search 瘦表(14 MB):
        //     同一個查詢 4,543ms → **389ms**(11.7 倍)。
        //
        // ★ 必須寫成 JOIN 而不是 EXISTS:JOIN 讓最佳化器可以拿 14MB 的瘦表當驅動表,
        //   掃出少數 book_id 後用 PRIMARY 回 books 取那幾列;
        //   寫成 EXISTS 會變成對 books 每一列執行子查詢 = 又掃 501 MB。
        //   ★ 2026-09-19:但瘦表當驅動表的前提是查詢裡沒有 v_book_list(見 COUNT 註解)。
        if ($deep) {
            $where[] = '(b.search_text LIKE :q1 OR b.search_text LIKE :q2)';
        } else {
            $joins   = ' JOIN book_search bs ON bs.book_id = b.book_id';
            $where[] = '(bs.search_key LIKE :q1 OR bs.search_key LIKE :q2)';
        }
        $params[':q1'] = '%' . $q . '%';
        $params[':q2'] = '%' . str_replace('-', '', $q) . '%';
    }
    if ($category > 0) {
        // ★ v_book_list 的 category_id 就是 b.category_id 原樣輸出(2026-07-16 view DDL 第 36 行),
        //   改用 b.category_id **語意完全相同**,不是近似。
        $where[] = 'b.category_id = :cat';
        $params[':cat'] = $category;
    }
    if ($person > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM book_persons bp
                            WHERE bp.book_id = b.book_id AND bp.person_id = :person)';
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
                            WHERE e2.book_id = b.book_id AND e2.publisher_id IN ($inList))";
    }
    // ── 2026-09-17(M1-B)出版年範圍篩選 ───────────────────────
    // 年份必須與列表顯示的年份**同源**,否則會出現「篩 2020–2026 卻顯示 2015」。
    // 顯示值來自 v_book_list,其定義(2026-07-16 view DDL 第 25-27 行)是:
    //   coalesce((select e.publish_date from editions e
    //             where e.book_id = b.book_id and e.publish_date is not null
    //             order by e.publish_date desc limit 1), b.publish_date)
    //
    // ★★ 2026-09-19:原本直接寫 v.publish_date,代價是只要帶 year_from/year_to,
    //   COUNT 就被迫回去碰 v_book_list,438ms 的好處全部消失(見 COUNT 前註解)。
    //   這裡改成在 books 上**原地重建同一個運算式**:
    //     MAX(publish_date) ≡ 「order by publish_date desc limit 1」
    //       —— 欄位是 varchar(10),兩者同為字典序;MAX 本身忽略 NULL,
    //          因此 view 裡的 `is not null` 條件是多餘的,拿掉不改變結果。
    //     外層 COALESCE 保留 books 平面欄 fallback —— ★ 這一層不能省:
    //       只寫 EXISTS(editions …) 會漏掉「只有 books.publish_date 有值」的書,
    //       是會靜默少算筆數的改法。
    //   走 idx_ed_book_pubdate (book_id, publish_date),相關子查詢只對候選列執行。
    //
    // 格式混用 YYYY / YYYY-MM / YYYY-MM-DD,故用字串比對(前綴語意):
    //   '2020' >= '2020' ✓、'2026-12' <= '2026-12-31' ✓
    //   ★ 絕不可用 YEAR() 等函式包欄位,索引會失效。
    $pubDateExpr = 'COALESCE((SELECT MAX(ey.publish_date) FROM editions ey
                              WHERE ey.book_id = b.book_id), b.publish_date)';
    if ($yearFrom !== '' && preg_match('/^\d{4}$/', $yearFrom)) {
        $where[] = "$pubDateExpr >= :yf";
        $params[':yf'] = $yearFrom;
    }
    if ($yearTo !== '' && preg_match('/^\d{4}$/', $yearTo)) {
        $where[] = "$pubDateExpr <= :yt";
        $params[':yt'] = $yearTo . '-12-31';
    }
    // ── 2026-09-17(M1-B)來源書房篩選 ───────────────────────
    // ★ 不可用 v.source:那是 books.source 平面欄(單值),
    //   一本書被多家書房收錄時只會有一個值 → 會漏掉跨站書
    //   (全站 65,290 本裡有 16,164 本掛 2 個以上來源)。
    //   必須查 editions,走 idx_ed_book_source (book_id, source)。
    if ($source !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM editions es
                            WHERE es.book_id = b.book_id AND es.source = :src)';
        $params[':src'] = $source;
    }
    $whereSql = implode(' AND ', $where);

    // ══ ★★ 2026-09-19:查詢改成兩段,關鍵是「過濾階段不碰 v_book_list」 ══
    //
    // 【真因】原本寫 `FROM v_book_list v JOIN books b ON b.book_id = v.book_id`。
    //   v_book_list 是 MERGE 演算法,展開後自己就是 `FROM books`,外面又 JOIN 一次
    //   → **books 被 JOIN 了兩次**。EXPLAIN:
    //       id=1 PRIMARY b  type=ALL   rows=53251   ← 全表掃描(501 MB)
    //       id=1 PRIMARY b  eq_ref PRIMARY 1        ← 同一張 books 又出現一次
    //       id=1 PRIMARY bs eq_ref PRIMARY 1
    //   那個 `JOIN books b` 是歷史遺留(原本 q 要用 b.isbn10),改掉 q 之後只剩負擔。
    //
    // 【實測 2026-09-17,同樣命中 3 筆】
    //   ① 原寫法(JOIN view + JOIN 瘦表)          5,239 ms
    //   ② 改 IN 子查詢,但仍 JOIN view            5,285 ms
    //   ③ 純 books + 瘦表,完全不碰 view            438 ms   ← 本次採用
    //   ⇒ 關鍵不是 JOIN vs IN,是**碰不碰 v_book_list**。
    //
    // 【為什麼可以不碰】過濾用到的欄位在 view 裡全是 books 原樣輸出:
    //   is_published / category_id / created_at / book_id ——
    //   唯一的例外是 publish_date(coalesce 最新版日期),已在上面原地重建同一運算式。
    //
    // 【顯示欄位仍然走 view】第二段用本頁的 ≤50 個 book_id 去 view 取
    //   author/translator/publisher/isbn13/cover_url(那些才是 view 存在的理由),
    //   走 PRIMARY eq_ref,子查詢只執行 20 次,不是 65,290 次。
    // COUNT 沒有 ORDER BY,最佳化器本來就會拿瘦表當驅動表 —— 實測 0.288s,維持原形不動。
    $stmt = db()->prepare("SELECT COUNT(*) FROM books b$joins WHERE $whereSql");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    // ══ ★★★ 2026-09-19 第五輪:ORDER BY + LIMIT 會讓最佳化器翻轉執行計畫 ══
    //
    // 同樣的 WHERE、同樣命中 3 筆,只差一個
    // `ORDER BY b.created_at DESC, b.book_id DESC LIMIT 20`:
    //     COUNT(無 ORDER BY)                0.288 s
    //     取 book_id(有 ORDER BY)          4.776 s   ← 差 16 倍
    //     同一句把 ORDER BY 拿掉             0.153 s   ← 證明問題出在 ORDER BY
    //
    // 排序 3 筆不可能花 4.5 秒。EXPLAIN 顯示最佳化器把驅動表從 13.5 MB 的 book_search
    // 換成了 511 MB 的 books(type=ALL rows=50323, Using filesort)——
    // 它以為「照 created_at 掃 books 可以早點湊滿 20 筆」,而符合的只有 3 筆 → 一路掃到底。
    //
    // 【三個候選解的實測(命中 3 筆)】
    //   ① STRAIGHT_JOIN 強制瘦表當驅動表     0.167 s   ← 採用
    //   ② IN 子查詢                         4.793 s
    //   ③ 衍生表先過濾                       5.208 s
    //   ★ ②③ 都沒用,因為最佳化器一樣會翻轉;只有 STRAIGHT_JOIN 是命令而非建議。
    //   ★ 另以命中 447 筆的關鍵字複測 ①:0.388 s(只有 3 筆時「早停」佔不到便宜,
    //     所以一定要用命中多的詞再測一次,否則會高估)。
    //
    // ★ STRAIGHT_JOIN 依 FROM 的書寫順序決定 join 順序 → book_search 必須寫在前面。
    //   只在「有關鍵字且非 deep」時套用;沒有瘦表可 JOIN 時用 hint 沒有意義。
    //   deep=1 那條路仍需 books(search_text 在那),維持原樣、接受慢。
    if ($joins !== '') {
        $fromRows = 'book_search bs JOIN books b ON b.book_id = bs.book_id';
        $hint     = 'STRAIGHT_JOIN ';
    } else {
        // 無關鍵字的瀏覽/篩選:單表,靠 idx_pub_created (is_published, created_at, book_id)
        //   實測 建索引前 5.004 s → 建索引後 0.0004 s(Using index,filesort 消失)。
        $fromRows = 'books b';
        $hint     = '';
    }

    $offset = ($page - 1) * $perPage;
    // 第一段:只取本頁的 book_id(不碰 view)
    //   ORDER BY b.created_at ≡ 原本的 v.created_at(view 第 39 行為 b.created_at 原樣輸出)
    $stmt = db()->prepare(
        "SELECT {$hint}b.book_id FROM $fromRows
         WHERE $whereSql
         ORDER BY b.created_at DESC, b.book_id DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    // 第二段:用這幾個 id 去 view 取顯示欄位
    $rows = [];
    if ($ids) {
        $ph = [];
        foreach ($ids as $i => $_) {
            $ph[] = ':b' . $i;   // ★ EMULATE_PREPARES=false,參數不可同名,逐一編號
        }
        $stmt = db()->prepare(
            "SELECT v.book_id, v.title, v.subtitle, v.original_title, v.author, v.translator,
                    v.publisher, v.publish_date, v.isbn13, v.cover_url,
                    v.summary_short, v.summary, v.category_id, c.name AS category_name
               FROM v_book_list v
               LEFT JOIN categories c ON c.category_id = v.category_id
              WHERE v.book_id IN (" . implode(',', $ph) . ")"
        );
        foreach ($ids as $i => $id) {
            $stmt->bindValue(':b' . $i, $id, PDO::PARAM_INT);
        }
        $stmt->execute();
        // IN 不保證回傳順序 → 依第一段的 id 順序重排,排序語意才不會被悄悄改掉
        $byId = [];
        foreach ($stmt->fetchAll() as $r) {
            $byId[(int) $r['book_id']] = $r;
        }
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $rows[] = $byId[$id];
            }
        }
        $rows = finish_cards($rows);
    }

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
    // ★ 2026-09-22:預設值由 9 改為 99。
    //   9 是「道聲」的排序值,等於**沒登錄的平台會被靜默插進台灣書房中間**
    //   (麥種自 v1.13.0 上線起就是這樣)。沒登錄代表我們不知道它該排哪,
    //   正確行為是排到最後、讓它顯眼,而不是躲在中間看不出來。
    usort($buy, static fn (array $a, array $b): int =>
        [BUY_PLATFORM_ORDER[$a['platform']] ?? 99, $a['label']]
        <=> [BUY_PLATFORM_ORDER[$b['platform']] ?? 99, $b['label']]);
    $book['buy_links'] = $buy;    // 所有購書來源(校園/基道/未來新增)
    $book['links']     = $others; // 延伸連結(已排除購書,避免與按鈕重複)

    json_data($book);
}
