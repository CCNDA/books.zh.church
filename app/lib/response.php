<?php
declare(strict_types=1);

/** 統一 JSON 回應:{"data": ...} */
function json_data(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 統一 JSON 錯誤:{"error": "..."} */
function json_error(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 寫入類端點驗證 X-Api-Key */
function require_api_key(): void
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $expected = app_config()['api']['write_key'] ?? '';
    if ($expected === '' || !hash_equals($expected, $key)) {
        json_error('未授權', 401);
    }
}
