<?php
declare(strict_types=1);

/**
 * PDO 連線(單例)。設定讀取順序:config/app.local.php → 環境變數。
 * 注意:檔案需為 UTF-8 無 BOM,否則 strict_types 會報錯。
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    $db = $config['db'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'],
        (int) $db['port'],
        $db['name'],
        $db['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $local = dirname(__DIR__, 2) . '/config/app.local.php';
    if (is_file($local)) {
        $config = require $local;
        return $config;
    }

    // 後備:環境變數(開發環境)
    $config = [
        'app' => [
            'url' => getenv('APP_URL') ?: 'http://localhost:8080',
        ],
        'db' => [
            'host'    => getenv('DB_HOST') ?: '127.0.0.1',
            'port'    => (int) (getenv('DB_PORT') ?: 3306),
            'name'    => getenv('DB_NAME') ?: 'books',
            'user'    => getenv('DB_USER') ?: 'root',
            'pass'    => getenv('DB_PASS') ?: '',
            'charset' => 'utf8mb4',
        ],
        'api' => [
            'write_key' => getenv('API_WRITE_KEY') ?: '',
        ],
    ];
    return $config;
}
