<?php
declare(strict_types=1);

namespace App\Services;

final class Config
{
    /** @var array<string, string> */
    private static array $env = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^([^#=\s]+)\s*=(.*)$/', $line, $m)) {
                continue;
            }
            $key = $m[1];
            $val = EnvFile::parseValue($m[2]);
            self::$env[$key] = $val;
            $_ENV[$key] = $val;
            if (!str_contains($val, "\0")) {
                @putenv($key . '=' . $val);
            }
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::$env[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }

    public static function set(string $key, string $value): void
    {
        self::$env[$key] = $value;
        $_ENV[$key] = $value;
        if (!str_contains($value, "\0")) {
            @putenv($key . '=' . $value);
        }
    }

    /** @internal Test helper */
    public static function resetForTests(): void
    {
        self::$env = [];
    }
}
