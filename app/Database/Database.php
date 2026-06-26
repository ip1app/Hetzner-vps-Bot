<?php
declare(strict_types=1);

namespace App\Database;

use App\Services\Config;

final class Database
{
    private static function driver(): string
    {
        return 'mysql';
    }

    /** @return class-string */
    private static function impl(): string
    {
        return MysqlDatabase::class;
    }

    public static function init(): void
    {
        if (!\App\Services\Installed::isInstalled() || !MysqlDatabase::isConfigured()) {
            return;
        }
        $impl = self::impl();
        $impl::init();
        if (\App\Services\Installed::isInstalled()) {
            try {
                self::saveSettings(self::getSettings());
            } catch (\Throwable) {
            }
        }
    }

    /** @param array<string, string> $cfg */
    public static function testMysqlConnection(array $cfg): array
    {
        return MysqlDatabase::testConnection([
            'DB_HOST' => $cfg['host'] ?? 'localhost',
            'DB_PORT' => $cfg['port'] ?? '3306',
            'DB_NAME' => $cfg['database'] ?? '',
            'DB_USER' => $cfg['user'] ?? '',
            'DB_PASS' => $cfg['password'] ?? '',
        ]);
    }

    public static function mysqlAvailable(): bool
    {
        return in_array('mysql', \PDO::getAvailableDrivers(), true);
    }

    /** @param array<string, string> $env */
    public static function setupFreshMysql(array $env): void
    {
        $test = MysqlDatabase::testConnection($env);
        if (!$test['ok']) {
            throw new \RuntimeException($test['error'] ?? 'MySQL connection failed');
        }
        Config::set('DB_HOST', $env['DB_HOST'] ?? Config::get('DB_HOST'));
        Config::set('DB_PORT', $env['DB_PORT'] ?? Config::get('DB_PORT'));
        Config::set('DB_NAME', $env['DB_NAME'] ?? Config::get('DB_NAME'));
        Config::set('DB_USER', $env['DB_USER'] ?? Config::get('DB_USER'));
        Config::set('DB_PASS', $env['DB_PASS'] ?? Config::get('DB_PASS'));
        MysqlDatabase::init();
        MysqlDatabase::setupFreshMysql();
    }

    /** @return array{driver: string, label: string, schema: int, stats: array<string, int>} */
    public static function getDbInfo(): array
    {
        $schema = 3;
        $stats = [];
        if (MysqlDatabase::isConfigured()) {
            try {
                $settings = self::getSettings();
                $schema = (int) ($settings['db_schema_version'] ?? 3);
                $stats = MysqlDatabase::backupStats();
            } catch (\Throwable) {
            }
        }
        return [
            'driver' => 'mysql',
            'label' => 'MySQL v' . $schema,
            'schema' => $schema,
            'stats' => $stats,
        ];
    }

    public static function normPhone(string $p): string
    {
        return Shared::normPhone($p);
    }

    public static function corePhone(string $p): string
    {
        return Shared::corePhone($p);
    }

    /** @param array<string, mixed>|null $client */
    public static function isExpired(?array $client): bool
    {
        return Shared::isExpired($client);
    }

    /** @param array<string, mixed>|null $client */
    public static function daysLeft(?array $client): ?int
    {
        return Shared::daysLeft($client);
    }

    public static function __callStatic(string $name, array $args): mixed
    {
        return (self::impl())::$name(...$args);
    }
}
