<?php

declare(strict_types=1);

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        http_response_code(500);
        $json = '{"success":false,"message":"JSON 编码失败","data":{}}';
    }

    echo $json;
    exit;
}

function successResponse(array $data = []): void
{
    jsonResponse([
        'success' => true,
        'data' => $data,
    ]);
}

function errorResponse(string $message, int $status = 400, array $extra = []): void
{
    jsonResponse([
        'success' => false,
        'message' => $message,
        'data' => $extra,
    ], $status);
}
