<?php
declare(strict_types=1);

namespace App;

use App\Helpers\Url;
use App\Services\SecurityHeaders;

final class Response
{
    public static function html(string $body, int $code = 200): never
    {
        SecurityHeaders::send();
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        echo $body;
        exit;
    }

    public static function text(string $body, int $code = 200): never
    {
        SecurityHeaders::send();
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
        exit;
    }

    public static function json(mixed $data, int $code = 200): never
    {
        SecurityHeaders::send();
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function redirect(string $url, int $code = 302): never
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $url = Url::to($url);
        }
        SecurityHeaders::send();
        http_response_code($code);
        header('Location: ' . $url);
        exit;
    }

    public static function cookie(string $name, string $value, ?string $path = null, int $maxAge = 86400, bool $httpOnly = true): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie($name, $value, [
            'expires' => time() + $maxAge,
            'path' => $path ?? (Url::base() ?: '/'),
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]);
    }
}
