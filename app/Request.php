<?php
declare(strict_types=1);

namespace App;

use App\Helpers\Url;
use App\Services\Config;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        /** @var array<string, string> */
        public readonly array $query,
        /** @var array<string, mixed> */
        public readonly array $body,
        /** @var array<string, string> */
        public readonly array $headers,
        public readonly string $ip,
        public readonly bool $isHttps,
    ) {}

    public static function capture(): self
    {
        $uri = Url::strip(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $headers[$name] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $uri,
            array_map('strval', $_GET),
            $_POST,
            $headers,
            $ip,
            $isHttps,
        );
    }

    public function cookie(string $name): string
    {
        return isset($_COOKIE[$name]) ? (string) $_COOKIE[$name] : '';
    }

    /** @return array<string, mixed> */
    public function jsonBody(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $ct = strtolower($this->header('Content-Type'));
        if (!str_contains($ct, 'application/json')) {
            $cache = $this->body;
            return $cache;
        }
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            $cache = [];
            return $cache;
        }
        $data = json_decode($raw, true);
        $cache = is_array($data) ? $data : [];
        return $cache;
    }

    public function header(string $name): string
    {
        // Case-insensitive lookup (HTTP headers are case-insensitive; helps with proxies, ngrok, old PHP setups)
        $nameLower = strtolower($name);
        foreach ($this->headers as $k => $v) {
            if (strtolower($k) === $nameLower) {
                return (string) $v;
            }
        }
        return '';
    }

    public function host(): string
    {
        return $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    public function scheme(): string
    {
        return $this->isHttps ? 'https' : 'http';
    }

    public function baseUrl(): string
    {
        $domain = Config::get('WEBHOOK_DOMAIN');
        if ($domain !== '') {
            $base = rtrim($domain, '/');
            $path = Url::base();
            if ($path !== '' && !str_ends_with($base, $path)) {
                $base .= $path;
            }
            return $base;
        }
        return $this->scheme() . '://' . $this->host() . Url::base();
    }
}
