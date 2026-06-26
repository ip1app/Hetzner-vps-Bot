<?php
declare(strict_types=1);

namespace App\Services;

final class EnvFile
{
    public static function path(): string
    {
        return ROOT . '/.env';
    }

    public static function parseValue(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (strlen($raw) >= 2 && $raw[0] === '"' && str_ends_with($raw, '"')) {
            return stripcslashes(substr($raw, 1, -1));
        }
        if (strlen($raw) >= 2 && $raw[0] === "'" && str_ends_with($raw, "'")) {
            return substr($raw, 1, -1);
        }
        return $raw;
    }

    public static function formatValue(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.,:@\\/+-]+$/', $value)) {
            return $value;
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    public static function formatLine(string $key, string $value): string
    {
        return $key . '=' . self::formatValue($value);
    }

    /** @return array<string, string> */
    public static function read(): array
    {
        $env = [];
        if (!is_file(self::path())) {
            return $env;
        }
        foreach (file(self::path(), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^([^#=\s]+)\s*=(.*)$/', $line, $m)) {
                $env[$m[1]] = self::parseValue($m[2]);
            }
        }
        return $env;
    }

    /** @param array<string, string> $vars */
    public static function write(array $vars): void
    {
        $lines = [];
        foreach ($vars as $k => $v) {
            $lines[] = self::formatLine($k, $v);
        }
        if (file_put_contents(self::path(), implode("\n", $lines) . "\n") === false) {
            throw new \RuntimeException('Cannot write .env — check folder permissions (chmod 755)');
        }
        foreach ($vars as $k => $v) {
            Config::set($k, $v);
        }
    }

    /** @param array<string, string> $patch */
    public static function update(array $patch): void
    {
        $lines = is_file(self::path()) ? file(self::path(), FILE_IGNORE_NEW_LINES) : [];
        $pending = $patch;
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^([^#=\s]+)\s*=/', $line, $m) && array_key_exists($m[1], $pending)) {
                $out[] = self::formatLine($m[1], $pending[$m[1]]);
                unset($pending[$m[1]]);
            } else {
                $out[] = $line;
            }
        }
        foreach ($pending as $k => $v) {
            $out[] = self::formatLine($k, $v);
        }
        if (file_put_contents(self::path(), implode("\n", $out) . "\n") === false) {
            throw new \RuntimeException('Cannot update .env — check folder permissions');
        }
        foreach ($patch as $k => $v) {
            Config::set($k, $v);
        }
    }
}
