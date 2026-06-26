<?php
declare(strict_types=1);

namespace App\Api;

use App\Response;
use App\Services\SecurityHeaders;

final class ApiResponse
{
    public static function preflight(): never
    {
        self::cors();
        SecurityHeaders::send();
        http_response_code(204);
        exit;
    }

    /** @param array<string, mixed> $data */
    public static function ok(mixed $data, int $code = 200, array $meta = []): never
    {
        self::send(['ok' => true, 'data' => $data, 'meta' => self::meta($meta)], $code);
    }

    public static function err(string $code, string $message, int $http = 400, array $meta = []): never
    {
        self::send([
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message],
            'meta' => self::meta($meta),
        ], $http);
    }

    /** @param array<string, mixed> $payload */
    private static function send(array $payload, int $code): never
    {
        self::cors();
        Response::json($payload, $code);
    }

    private static function cors(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept-Language');
    }

    /** @param array<string, mixed> $extra */
    private static function meta(array $extra = []): array
    {
        return array_merge(['api_version' => 'v1'], $extra);
    }
}
