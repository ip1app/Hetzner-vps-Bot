<?php
declare(strict_types=1);

namespace App\Database;

use App\Services\Config;
use PDO;

final class MysqlDatabase
{
    private static ?PDO $pdo = null;

    private const SCHEMA_VERSION = 3;

    /** @var list<string> */
    private const DATA_TABLES = ['q8_clients', 'q8_products', 'q8_inventory', 'q8_invoices', 'q8_activity', 'q8_settings'];

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Config::get('DB_HOST', 'localhost'),
                Config::get('DB_PORT', '3306'),
                Config::get('DB_NAME')
            );
            self::$pdo = new PDO($dsn, Config::get('DB_USER'), Config::get('DB_PASS'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return self::$pdo;
    }

    /** @param array<string, string> $cfg */
    public static function testConnection(array $cfg): array
    {
        if (!in_array('mysql', \PDO::getAvailableDrivers(), true)) {
            return ['ok' => false, 'error' => 'pdo_mysql_missing', 'code' => 'no_pdo_mysql'];
        }
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $cfg['DB_HOST'] ?? 'localhost',
                $cfg['DB_PORT'] ?? '3306',
                $cfg['DB_NAME'] ?? ''
            );
            $pdo = new PDO($dsn, $cfg['DB_USER'] ?? '', $cfg['DB_PASS'] ?? '');
            $pdo->query('SELECT 1');
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function isConfigured(): bool
    {
        return Config::get('DB_NAME') !== '' && Config::get('DB_USER') !== '';
    }

    public static function init(): void
    {
        if (!self::isConfigured()) {
            return;
        }
        $pdo = self::pdo();
        if (self::isLegacySchema($pdo)) {
            $snapshot = self::exportLegacy($pdo);
            self::dropDataTables($pdo);
            self::createSchema($pdo);
            self::importAll($snapshot);
            self::setSettingRaw($pdo, 'db_schema_version', (string) self::SCHEMA_VERSION);
            return;
        }
        self::createSchema($pdo);
        self::ensureClientColumns($pdo);
        self::ensureRefreshTokensTable($pdo);
    }

    private static function ensureRefreshTokensTable(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS q8_refresh_tokens (
    jti_hash CHAR(64) NOT NULL PRIMARY KEY,
    client_id VARCHAR(32) NOT NULL,
    expires_at INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_refresh_client (client_id),
    INDEX idx_refresh_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    private static function ensureClientColumns(PDO $pdo): void
    {
        foreach ([
            'otp_code VARCHAR(128) NOT NULL DEFAULT ""',
            'otp_exp INT UNSIGNED NOT NULL DEFAULT 0',
        ] as $col) {
            try {
                $pdo->exec('ALTER TABLE q8_clients ADD COLUMN ' . $col);
            } catch (\Throwable) {
            }
        }
        try {
            $pdo->exec('ALTER TABLE q8_clients MODIFY COLUMN otp_code VARCHAR(128) NOT NULL DEFAULT ""');
        } catch (\Throwable) {
        }
    }

    public static function setupFreshMysql(): void
    {
        $pdo = self::pdo();
        self::dropDataTables($pdo);
        self::createSchema($pdo);
        self::setSettingRaw($pdo, 'db_schema_version', (string) self::SCHEMA_VERSION);
    }

    private static function createSchema(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS q8_clients (
    id VARCHAR(32) NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL DEFAULT '',
    phone VARCHAR(64) NOT NULL DEFAULT '',
    telegram_id VARCHAR(64) NOT NULL DEFAULT '',
    server_id INT UNSIGNED NOT NULL DEFAULT 0,
    hetzner_account_id VARCHAR(64) NOT NULL DEFAULT '',
    expires DATE NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    otp_code VARCHAR(128) NOT NULL DEFAULT '',
    otp_exp INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_clients_phone (phone),
    INDEX idx_clients_telegram (telegram_id),
    INDEX idx_clients_server (server_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS q8_products (
    id VARCHAR(32) NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL DEFAULT '',
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    months SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    description TEXT NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS q8_inventory (
    id VARCHAR(32) NOT NULL PRIMARY KEY,
    product_id VARCHAR(32) NOT NULL DEFAULT '',
    server_id INT UNSIGNED NOT NULL DEFAULT 0,
    hetzner_account_id VARCHAR(64) NOT NULL DEFAULT '',
    note VARCHAR(512) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    INDEX idx_inventory_product (product_id),
    INDEX idx_inventory_server (server_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS q8_invoices (
    id VARCHAR(32) NOT NULL PRIMARY KEY,
    number VARCHAR(32) NOT NULL DEFAULT '',
    client_id VARCHAR(32) NOT NULL DEFAULT '',
    description TEXT NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    months SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    paid TINYINT(1) NOT NULL DEFAULT 0,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'admin',
    buyer_name VARCHAR(255) NOT NULL DEFAULT '',
    phone VARCHAR(64) NOT NULL DEFAULT '',
    product_id VARCHAR(32) NOT NULL DEFAULT '',
    paypal_order_id VARCHAR(128) NOT NULL DEFAULT '',
    INDEX idx_invoices_client (client_id),
    INDEX idx_invoices_paypal (paypal_order_id),
    INDEX idx_invoices_number (number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS q8_activity (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    t DATETIME(3) NOT NULL,
    type VARCHAR(32) NOT NULL DEFAULT '',
    act_key VARCHAR(128) NOT NULL DEFAULT '',
    vars JSON NULL,
    text TEXT NOT NULL,
    INDEX idx_activity_t (t DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS q8_settings (
    k VARCHAR(128) NOT NULL PRIMARY KEY,
    v MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    private static function dropDataTables(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::DATA_TABLES as $table) {
            $pdo->exec("DROP TABLE IF EXISTS $table");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private static function isLegacySchema(PDO $pdo): bool
    {
        try {
            $row = $pdo->query("SHOW COLUMNS FROM q8_clients LIKE 'data'")->fetch();
            return is_array($row);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private static function exportLegacy(PDO $pdo): array
    {
        $db = json_decode(json_encode(Shared::DEFAULTS), true);
        foreach (['q8_clients', 'q8_products', 'q8_inventory', 'q8_invoices'] as $table) {
            try {
                $stmt = $pdo->query("SELECT data FROM $table");
                $key = match ($table) {
                    'q8_clients' => 'clients',
                    'q8_products' => 'products',
                    'q8_inventory' => 'inventory',
                    'q8_invoices' => 'invoices',
                };
                $db[$key] = array_map(
                    static fn(array $r): array => json_decode((string) $r['data'], true) ?: [],
                    $stmt->fetchAll()
                );
            } catch (\Throwable) {
            }
        }
        try {
            $stmt = $pdo->query('SELECT data FROM q8_activity ORDER BY t DESC LIMIT 500');
            $db['activity'] = array_map(
                static fn(array $r): array => json_decode((string) $r['data'], true) ?: [],
                $stmt->fetchAll()
            );
        } catch (\Throwable) {
        }
        $db['settings'] = self::loadSettingsRaw($pdo);
        return $db;
    }

    /** @return array<string, mixed> */
    private static function loadSettingsRaw(PDO $pdo): array
    {
        $settings = [];
        try {
            $stmt = $pdo->query('SELECT k, v FROM q8_settings');
            foreach ($stmt->fetchAll() as $row) {
                $decoded = json_decode((string) $row['v'], true);
                $settings[$row['k']] = $decoded ?? $row['v'];
            }
        } catch (\Throwable) {
        }
        return $settings;
    }

    private static function setSettingRaw(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare('INSERT INTO q8_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
        $stmt->execute([$key, $value]);
    }

    private static function encodeSettingValue(mixed $value): string
    {
        return is_string($value) ? $value : (json_encode($value, JSON_UNESCAPED_UNICODE) ?: 'null');
    }

    private static function formatIso(?string $value): string
    {
        if ($value === null || $value === '') {
            return gmdate('c');
        }
        try {
            return (new \DateTimeImmutable($value))->format('c');
        } catch (\Throwable) {
            $ts = strtotime($value);
            return $ts !== false ? gmdate('c', $ts) : gmdate('c');
        }
    }

    private static function formatIsoOrNull(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return self::formatIso($value);
    }

    private static function toMysqlDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            $ts = strtotime($value);
            return $ts !== false ? gmdate('Y-m-d', $ts) : null;
        }
    }

    /** @param array<string, mixed> $row */
    private static function rowToClient(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'telegram_id' => (string) ($row['telegram_id'] ?? ''),
            'server_id' => (int) ($row['server_id'] ?? 0),
            'hetzner_account_id' => (string) ($row['hetzner_account_id'] ?? ''),
            'expires' => $row['expires'] !== null ? (string) $row['expires'] : '',
            'active' => ((int) ($row['active'] ?? 1)) === 1,
            'otp_code' => (string) ($row['otp_code'] ?? ''),
            'otp_exp' => (string) ($row['otp_exp'] ?? '0'),
            'created_at' => self::formatIso(isset($row['created_at']) ? (string) $row['created_at'] : null),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function rowToProduct(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'price' => (float) ($row['price'] ?? 0),
            'months' => (int) ($row['months'] ?? 1),
            'desc' => (string) ($row['description'] ?? ''),
            'active' => ((int) ($row['active'] ?? 1)) === 1,
            'created_at' => self::formatIso(isset($row['created_at']) ? (string) $row['created_at'] : null),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function rowToInventory(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'product_id' => (string) ($row['product_id'] ?? ''),
            'server_id' => (int) ($row['server_id'] ?? 0),
            'hetzner_account_id' => (string) ($row['hetzner_account_id'] ?? ''),
            'note' => (string) ($row['note'] ?? ''),
            'created_at' => self::formatIso(isset($row['created_at']) ? (string) $row['created_at'] : null),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function rowToInvoice(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'number' => (string) ($row['number'] ?? ''),
            'client_id' => (string) ($row['client_id'] ?? ''),
            'desc' => (string) ($row['description'] ?? ''),
            'amount' => (float) ($row['amount'] ?? 0),
            'months' => (int) ($row['months'] ?? 1),
            'paid' => ((int) ($row['paid'] ?? 0)) === 1,
            'paid_at' => self::formatIsoOrNull(isset($row['paid_at']) ? (string) $row['paid_at'] : null),
            'created_at' => self::formatIso(isset($row['created_at']) ? (string) $row['created_at'] : null),
            'source' => (string) ($row['source'] ?? 'admin'),
            'buyer_name' => (string) ($row['buyer_name'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'product_id' => (string) ($row['product_id'] ?? ''),
            'paypal_order_id' => (string) ($row['paypal_order_id'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function rowToActivity(array $row): array
    {
        $vars = $row['vars'] ?? null;
        if (is_string($vars)) {
            $vars = json_decode($vars, true);
        }
        $entry = [
            't' => self::formatIso(isset($row['t']) ? (string) $row['t'] : null),
            'type' => (string) ($row['type'] ?? ''),
        ];
        $key = (string) ($row['act_key'] ?? '');
        if ($key !== '') {
            $entry['key'] = $key;
            $entry['vars'] = is_array($vars) ? $vars : [];
            $entry['text'] = '';
        } else {
            $entry['text'] = (string) ($row['text'] ?? '');
        }
        return $entry;
    }

    public static function listClients(): array
    {
        $stmt = self::pdo()->query('SELECT * FROM q8_clients ORDER BY created_at DESC');
        return array_map(self::rowToClient(...), $stmt->fetchAll());
    }

    public static function getClient(string $id): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM q8_clients WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? self::rowToClient($row) : null;
    }

    public static function getClientByTelegramId(string $tgId): ?array
    {
        $list = self::listClientsByTelegramId($tgId);
        return $list[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public static function listClientsByTelegramId(string $tgId): array
    {
        if ($tgId === '') {
            return [];
        }
        $stmt = self::pdo()->prepare('SELECT * FROM q8_clients WHERE telegram_id = ? ORDER BY created_at DESC');
        $stmt->execute([$tgId]);
        return array_map(self::rowToClient(...), $stmt->fetchAll());
    }

    private static function phoneMatches(string $phone, string $candidate): bool
    {
        $n = Shared::corePhone($phone);
        $cn = Shared::corePhone($candidate);
        if (strlen($n) < 7 || strlen($cn) < 7) {
            return false;
        }
        return $cn === $n || str_ends_with($cn, $n) || str_ends_with($n, $cn);
    }

    /** @return list<array<string, mixed>> */
    public static function listClientsByPhone(string $phone): array
    {
        $out = [];
        foreach (self::listClients() as $c) {
            if (self::phoneMatches($phone, (string) ($c['phone'] ?? ''))) {
                $out[] = $c;
            }
        }
        return $out;
    }

    public static function getClientByPhone(string $phone): ?array
    {
        $list = self::listClientsByPhone($phone);
        if ($list === []) {
            return null;
        }
        foreach ($list as $c) {
            if (!empty($c['telegram_id'])) {
                return $c;
            }
        }
        return $list[0];
    }

    /** @param array<string, mixed> $client */
    public static function productIdForClient(array $client): ?string
    {
        $sid = (int) ($client['server_id'] ?? 0);
        if ($sid > 0) {
            foreach (self::listInventory() as $item) {
                if ((int) ($item['server_id'] ?? 0) === $sid) {
                    $pid = (string) ($item['product_id'] ?? '');
                    if ($pid !== '' && self::getProduct($pid)) {
                        return $pid;
                    }
                }
            }
        }
        $clientId = (string) ($client['id'] ?? '');
        if ($clientId === '') {
            return null;
        }
        $invoices = self::listInvoices();
        usort($invoices, fn($a, $b) => strcmp((string) ($b['paid_at'] ?? $b['created_at'] ?? ''), (string) ($a['paid_at'] ?? $a['created_at'] ?? '')));
        foreach ($invoices as $inv) {
            if ((string) ($inv['client_id'] ?? '') !== $clientId || empty($inv['paid'])) {
                continue;
            }
            $pid = (string) ($inv['product_id'] ?? '');
            if ($pid !== '' && self::getProduct($pid)) {
                return $pid;
            }
        }
        return null;
    }

    public static function findClientByPhoneAndProduct(string $phone, string $productId): ?array
    {
        if ($productId === '') {
            return null;
        }
        foreach (self::listClientsByPhone($phone) as $c) {
            if (self::productIdForClient($c) === $productId) {
                return $c;
            }
        }
        return null;
    }

    public static function addClient(array $data): array
    {
        $client = [
            'id' => Shared::genId(),
            'name' => trim((string) ($data['name'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'telegram_id' => trim((string) ($data['telegram_id'] ?? '')),
            'server_id' => (int) ($data['server_id'] ?? 0),
            'hetzner_account_id' => trim((string) ($data['hetzner_account_id'] ?? '')),
            'expires' => trim((string) ($data['expires'] ?? '')),
            'active' => true,
            'created_at' => date('c'),
        ];
        $stmt = self::pdo()->prepare(
            'INSERT INTO q8_clients (id, name, phone, telegram_id, server_id, hetzner_account_id, expires, active, otp_code, otp_exp, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $client['id'],
            $client['name'],
            $client['phone'],
            $client['telegram_id'],
            $client['server_id'],
            $client['hetzner_account_id'],
            self::toMysqlDate($client['expires']),
            1,
            '',
            0,
            Shared::toMysqlDatetime($client['created_at']),
        ]);
        return $client;
    }

    public static function updateClient(string $id, array $patch): ?array
    {
        $allowed = [
            'name' => 'name',
            'phone' => 'phone',
            'telegram_id' => 'telegram_id',
            'server_id' => 'server_id',
            'hetzner_account_id' => 'hetzner_account_id',
            'expires' => 'expires',
            'active' => 'active',
            'otp_code' => 'otp_code',
            'otp_exp' => 'otp_exp',
        ];
        $sets = [];
        $params = [];
        foreach ($patch as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            $sets[] = $allowed[$key] . ' = ?';
            if ($key === 'expires') {
                $params[] = self::toMysqlDate((string) $value);
            } elseif ($key === 'active') {
                $params[] = $value ? 1 : 0;
            } elseif ($key === 'server_id' || $key === 'otp_exp') {
                $params[] = (int) $value;
            } else {
                $params[] = $value;
            }
        }
        if ($sets === []) {
            return self::getClient($id);
        }
        $params[] = $id;
        $stmt = self::pdo()->prepare('UPDATE q8_clients SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
        return self::getClient($id);
    }

    public static function removeClient(string $id): bool
    {
        $stmt = self::pdo()->prepare('DELETE FROM q8_clients WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public static function extendClient(string $id, int $months): ?array
    {
        $c = self::getClient($id);
        if (!$c) {
            return null;
        }
        $base = (!empty($c['expires']) && !Shared::isExpired($c))
            ? new \DateTime($c['expires'] . ' 12:00:00')
            : new \DateTime();
        $base->modify('+' . $months . ' months');
        return self::updateClient($id, ['expires' => $base->format('Y-m-d')]);
    }

    public static function listProducts(): array
    {
        $stmt = self::pdo()->query('SELECT * FROM q8_products ORDER BY created_at DESC');
        return array_map(self::rowToProduct(...), $stmt->fetchAll());
    }

    public static function getProduct(string $id): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM q8_products WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? self::rowToProduct($row) : null;
    }

    public static function addProduct(array $data): array
    {
        $product = [
            'id' => Shared::genId(),
            'name' => trim((string) ($data['name'] ?? '')),
            'price' => (float) ($data['price'] ?? 0),
            'months' => (int) ($data['months'] ?? 1),
            'desc' => trim((string) ($data['desc'] ?? '')),
            'active' => true,
            'created_at' => date('c'),
        ];
        $stmt = self::pdo()->prepare(
            'INSERT INTO q8_products (id, name, price, months, description, active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $product['id'],
            $product['name'],
            $product['price'],
            $product['months'],
            $product['desc'],
            1,
            Shared::toMysqlDatetime($product['created_at']),
        ]);
        return $product;
    }

    public static function updateProduct(string $id, array $patch): ?array
    {
        $map = ['name' => 'name', 'price' => 'price', 'months' => 'months', 'desc' => 'description', 'active' => 'active'];
        $sets = [];
        $params = [];
        foreach ($patch as $key => $value) {
            if (!isset($map[$key])) {
                continue;
            }
            $sets[] = $map[$key] . ' = ?';
            if ($key === 'active') {
                $params[] = $value ? 1 : 0;
            } elseif ($key === 'price') {
                $params[] = (float) $value;
            } elseif ($key === 'months') {
                $params[] = (int) $value;
            } else {
                $params[] = $value;
            }
        }
        if ($sets === []) {
            return self::getProduct($id);
        }
        $params[] = $id;
        $stmt = self::pdo()->prepare('UPDATE q8_products SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
        return self::getProduct($id);
    }

    public static function removeProduct(string $id): void
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM q8_inventory WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM q8_products WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function listInventory(): array
    {
        $stmt = self::pdo()->query('SELECT * FROM q8_inventory ORDER BY created_at DESC');
        return array_map(self::rowToInventory(...), $stmt->fetchAll());
    }

    public static function availableStockForProduct(string $productId): array
    {
        $stmt = self::pdo()->prepare(
            'SELECT i.* FROM q8_inventory i
             LEFT JOIN q8_clients c ON c.server_id = i.server_id AND c.server_id > 0
             WHERE i.product_id = ? AND c.id IS NULL
             ORDER BY i.created_at DESC'
        );
        $stmt->execute([$productId]);
        return array_map(self::rowToInventory(...), $stmt->fetchAll());
    }

    /**
     * Atomically assign stock, create client, and mark invoice paid (new store purchase).
     *
     * @param array{name: string, phone: string, telegram_id: string, expires: string} $clientData
     * @return array{inv: array<string, mixed>, client: array<string, mixed>, isNew: bool, already: bool}
     */
    public static function fulfillNewStoreInvoice(string $invId, array $clientData, string $productId): array
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $inv = self::lockInvoiceForUpdate($pdo, $invId);
            if ($inv === null) {
                $pdo->rollBack();
                throw new \InvalidArgumentException('Invoice not found');
            }
            if (!empty($inv['paid'])) {
                $pdo->commit();
                return [
                    'inv' => $inv,
                    'client' => self::getClient((string) ($inv['client_id'] ?? '')) ?? [],
                    'isNew' => false,
                    'already' => true,
                ];
            }
            $stock = self::claimAvailableStock($pdo, $productId);
            $client = [
                'id' => Shared::genId(),
                'name' => trim((string) ($clientData['name'] ?? '')),
                'phone' => trim((string) ($clientData['phone'] ?? '')),
                'telegram_id' => trim((string) ($clientData['telegram_id'] ?? '')),
                'server_id' => (int) ($stock['server_id'] ?? 0),
                'hetzner_account_id' => trim((string) ($stock['hetzner_account_id'] ?? '')),
                'expires' => trim((string) ($clientData['expires'] ?? '')),
                'active' => true,
                'created_at' => date('c'),
            ];
            $stmt = $pdo->prepare(
                'INSERT INTO q8_clients (id, name, phone, telegram_id, server_id, hetzner_account_id, expires, active, otp_code, otp_exp, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $client['id'],
                $client['name'],
                $client['phone'],
                $client['telegram_id'],
                $client['server_id'],
                $client['hetzner_account_id'],
                self::toMysqlDate($client['expires']),
                1,
                '',
                0,
                Shared::toMysqlDatetime($client['created_at']),
            ]);
            self::markInvoicePaidInTransaction($pdo, $invId, $client['id']);
            $pdo->commit();
            return [
                'inv' => self::getInvoice($invId) ?? $inv,
                'client' => $client,
                'isNew' => true,
                'already' => false,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Atomically extend subscription and mark renewal invoice paid.
     *
     * @return array{inv: array<string, mixed>, client: array<string, mixed>, isNew: bool, already: bool}
     */
    public static function fulfillRenewalInvoice(string $invId, string $clientId, int $months): array
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $inv = self::lockInvoiceForUpdate($pdo, $invId);
            if ($inv === null) {
                $pdo->rollBack();
                throw new \InvalidArgumentException('Invoice not found');
            }
            if (!empty($inv['paid'])) {
                $pdo->commit();
                return [
                    'inv' => $inv,
                    'client' => self::getClient((string) ($inv['client_id'] ?? '')) ?? [],
                    'isNew' => false,
                    'already' => true,
                ];
            }
            $stmt = $pdo->prepare('SELECT * FROM q8_clients WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$clientId]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                $pdo->rollBack();
                throw new \InvalidArgumentException('Client not found');
            }
            $c = self::rowToClient($row);
            $base = (!empty($c['expires']) && !Shared::isExpired($c))
                ? new \DateTime($c['expires'] . ' 12:00:00')
                : new \DateTime();
            $base->modify('+' . $months . ' months');
            $expires = $base->format('Y-m-d');
            $upd = $pdo->prepare('UPDATE q8_clients SET expires = ? WHERE id = ?');
            $upd->execute([self::toMysqlDate($expires), $clientId]);
            $c['expires'] = $expires;
            self::markInvoicePaidInTransaction($pdo, $invId, $clientId);
            $pdo->commit();
            return [
                'inv' => self::getInvoice($invId) ?? $inv,
                'client' => $c,
                'isNew' => false,
                'already' => false,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private static function lockInvoiceForUpdate(PDO $pdo, string $invId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM q8_invoices WHERE id = ? FOR UPDATE');
        $stmt->execute([$invId]);
        $row = $stmt->fetch();
        return is_array($row) ? self::rowToInvoice($row) : null;
    }

    /** @return array<string, mixed>|null */
    private static function claimAvailableStock(PDO $pdo, string $productId): ?array
    {
        if ($productId === '') {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT i.* FROM q8_inventory i
             WHERE i.product_id = ?
             ORDER BY i.created_at DESC
             FOR UPDATE'
        );
        $stmt->execute([$productId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $sid = (int) ($row['server_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $chk = $pdo->prepare('SELECT id FROM q8_clients WHERE server_id = ? LIMIT 1 FOR UPDATE');
            $chk->execute([$sid]);
            if ($chk->fetch()) {
                continue;
            }
            return self::rowToInventory($row);
        }
        return null;
    }

    private static function markInvoicePaidInTransaction(PDO $pdo, string $invId, string $clientId): void
    {
        $stmt = $pdo->prepare(
            'UPDATE q8_invoices SET paid = 1, paid_at = ?, client_id = ? WHERE id = ? AND paid = 0'
        );
        $stmt->execute([Shared::toMysqlDatetime(date('c')), $clientId, $invId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Invoice already paid');
        }
    }

    public static function addInventory(array $data): array
    {
        $item = [
            'id' => Shared::genId(),
            'product_id' => trim((string) ($data['product_id'] ?? '')),
            'server_id' => (int) ($data['server_id'] ?? 0),
            'hetzner_account_id' => trim((string) ($data['hetzner_account_id'] ?? '')),
            'note' => trim((string) ($data['note'] ?? '')),
            'created_at' => date('c'),
        ];
        $stmt = self::pdo()->prepare(
            'INSERT INTO q8_inventory (id, product_id, server_id, hetzner_account_id, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $item['id'],
            $item['product_id'],
            $item['server_id'],
            $item['hetzner_account_id'],
            $item['note'],
            Shared::toMysqlDatetime($item['created_at']),
        ]);
        return $item;
    }

    public static function updateInventory(string $id, array $patch): ?array
    {
        $map = [
            'product_id' => 'product_id',
            'server_id' => 'server_id',
            'hetzner_account_id' => 'hetzner_account_id',
            'note' => 'note',
        ];
        $sets = [];
        $params = [];
        foreach ($patch as $key => $value) {
            if (!isset($map[$key])) {
                continue;
            }
            $sets[] = $map[$key] . ' = ?';
            $params[] = $key === 'server_id' ? (int) $value : $value;
        }
        if ($sets === []) {
            return self::getInventory($id);
        }
        $params[] = $id;
        $stmt = self::pdo()->prepare('UPDATE q8_inventory SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
        return self::getInventory($id);
    }

    public static function getInventory(string $id): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM q8_inventory WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? self::rowToInventory($row) : null;
    }

    public static function removeInventory(string $id): void
    {
        self::pdo()->prepare('DELETE FROM q8_inventory WHERE id = ?')->execute([$id]);
    }

    public static function findAccountForServer(int $serverId): string
    {
        if ($serverId <= 0) {
            return '';
        }
        $stmt = self::pdo()->prepare(
            'SELECT hetzner_account_id FROM q8_clients WHERE server_id = ? AND hetzner_account_id <> "" LIMIT 1'
        );
        $stmt->execute([$serverId]);
        $row = $stmt->fetch();
        if (is_array($row) && ($row['hetzner_account_id'] ?? '') !== '') {
            return (string) $row['hetzner_account_id'];
        }
        $stmt = self::pdo()->prepare(
            'SELECT hetzner_account_id FROM q8_inventory WHERE server_id = ? AND hetzner_account_id <> "" LIMIT 1'
        );
        $stmt->execute([$serverId]);
        $row = $stmt->fetch();
        return is_array($row) ? (string) ($row['hetzner_account_id'] ?? '') : '';
    }

    public static function listInvoices(): array
    {
        $stmt = self::pdo()->query('SELECT * FROM q8_invoices ORDER BY created_at DESC');
        return array_map(self::rowToInvoice(...), $stmt->fetchAll());
    }

    /**
     * @param array{q?: string, filter?: string, page?: int, per_page?: int} $opts
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public static function listInvoicesPage(array $opts = []): array
    {
        $perPage = max(1, min(100, (int) ($opts['per_page'] ?? 25)));
        $page = max(1, (int) ($opts['page'] ?? 1));
        $q = trim((string) ($opts['q'] ?? ''));
        $filter = (string) ($opts['filter'] ?? 'all');

        $where = [];
        $params = [];
        if ($filter === 'paid') {
            $where[] = 'paid = 1';
        } elseif ($filter === 'unpaid') {
            $where[] = 'paid = 0';
        }
        if ($q !== '') {
            $where[] = '(number LIKE ? OR description LIKE ? OR buyer_name LIKE ? OR phone LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = self::pdo()->prepare('SELECT COUNT(*) FROM q8_invoices ' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT * FROM q8_invoices ' . $whereSql . ' ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $items = array_map(self::rowToInvoice(...), $stmt->fetchAll());

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public static function countInvoices(): int
    {
        return (int) self::pdo()->query('SELECT COUNT(*) FROM q8_invoices')->fetchColumn();
    }

    public static function getInvoice(string $id): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM q8_invoices WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? self::rowToInvoice($row) : null;
    }

    public static function addInvoice(array $data): array
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $current = self::loadSettingsRaw($pdo);
            $seq = ((int) (Shared::decryptSettings($current)['invoice_seq'] ?? 0)) + 1;
            $merged = Shared::encryptSettings(array_merge($current, ['invoice_seq' => $seq]));
            self::setSettingRaw($pdo, 'invoice_seq', self::encodeSettingValue($merged['invoice_seq'] ?? $seq));

            $inv = [
                'id' => Shared::genId(),
                'number' => 'INV-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                'client_id' => (string) ($data['client_id'] ?? ''),
                'desc' => trim((string) ($data['desc'] ?? '')),
                'amount' => (float) ($data['amount'] ?? 0),
                'months' => (int) ($data['months'] ?? 1),
                'paid' => false,
                'paid_at' => null,
                'created_at' => date('c'),
                'source' => (string) ($data['source'] ?? 'admin'),
                'buyer_name' => trim((string) ($data['buyer_name'] ?? '')),
                'phone' => trim((string) ($data['phone'] ?? '')),
                'product_id' => (string) ($data['product_id'] ?? ''),
                'paypal_order_id' => (string) ($data['paypal_order_id'] ?? ''),
            ];
            $stmt = $pdo->prepare(
                'INSERT INTO q8_invoices
                 (id, number, client_id, description, amount, months, paid, paid_at, created_at, source, buyer_name, phone, product_id, paypal_order_id)
                 VALUES (?, ?, ?, ?, ?, ?, 0, NULL, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $inv['id'],
                $inv['number'],
                $inv['client_id'],
                $inv['desc'],
                $inv['amount'],
                $inv['months'],
                Shared::toMysqlDatetime($inv['created_at']),
                $inv['source'],
                $inv['buyer_name'],
                $inv['phone'],
                $inv['product_id'],
                $inv['paypal_order_id'],
            ]);
            $pdo->commit();
            return $inv;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function updateInvoice(string $id, array $patch): ?array
    {
        $map = [
            'number' => 'number',
            'client_id' => 'client_id',
            'desc' => 'description',
            'amount' => 'amount',
            'months' => 'months',
            'paid' => 'paid',
            'paid_at' => 'paid_at',
            'source' => 'source',
            'buyer_name' => 'buyer_name',
            'phone' => 'phone',
            'product_id' => 'product_id',
            'paypal_order_id' => 'paypal_order_id',
        ];
        $sets = [];
        $params = [];
        foreach ($patch as $key => $value) {
            if (!isset($map[$key])) {
                continue;
            }
            $sets[] = $map[$key] . ' = ?';
            if ($key === 'paid') {
                $params[] = $value ? 1 : 0;
            } elseif ($key === 'paid_at') {
                $params[] = $value ? Shared::toMysqlDatetime((string) $value) : null;
            } elseif ($key === 'amount') {
                $params[] = (float) $value;
            } elseif ($key === 'months') {
                $params[] = (int) $value;
            } else {
                $params[] = $value;
            }
        }
        if ($sets === []) {
            return self::getInvoice($id);
        }
        $params[] = $id;
        $stmt = self::pdo()->prepare('UPDATE q8_invoices SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
        return self::getInvoice($id);
    }

    public static function getInvoiceByPayPalOrder(string $orderId): ?array
    {
        if ($orderId === '') {
            return null;
        }
        $stmt = self::pdo()->prepare('SELECT * FROM q8_invoices WHERE paypal_order_id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        return is_array($row) ? self::rowToInvoice($row) : null;
    }

    public static function listInvoicesByClient(string $clientId): array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM q8_invoices WHERE client_id = ? ORDER BY created_at DESC');
        $stmt->execute([$clientId]);
        return array_map(self::rowToInvoice(...), $stmt->fetchAll());
    }

    public static function markInvoicePaid(string $id): ?array
    {
        $inv = self::getInvoice($id);
        if (!$inv || !empty($inv['paid'])) {
            return null;
        }
        return self::updateInvoice($id, ['paid' => true, 'paid_at' => date('c')]);
    }

    public static function removeInvoice(string $id): void
    {
        self::pdo()->prepare('DELETE FROM q8_invoices WHERE id = ?')->execute([$id]);
    }

    public static function logActivity(string $type, ?string $text, ?array $meta = null): void
    {
        $pdo = self::pdo();
        $key = '';
        $vars = null;
        $body = $text ?? '';
        if ($meta && !empty($meta['key'])) {
            $key = (string) $meta['key'];
            $vars = json_encode($meta['vars'] ?? [], JSON_UNESCAPED_UNICODE);
            $body = '';
        }
        $stmt = $pdo->prepare(
            'INSERT INTO q8_activity (t, type, act_key, vars, text) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            Shared::toMysqlDatetime(date('c')),
            $type,
            $key,
            $vars,
            $body,
        ]);
        self::trimActivity($pdo);
    }

    private static function trimActivity(PDO $pdo): void
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM q8_activity')->fetchColumn();
        $extra = $count - 500;
        if ($extra <= 0) {
            return;
        }
        $stmt = $pdo->prepare('DELETE FROM q8_activity ORDER BY t ASC LIMIT ?');
        $stmt->bindValue(1, $extra, PDO::PARAM_INT);
        $stmt->execute();
    }

    public static function listActivity(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = self::pdo()->prepare('SELECT * FROM q8_activity ORDER BY t DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(self::rowToActivity(...), $stmt->fetchAll());
    }

    public static function getSettings(): array
    {
        return Shared::decryptSettings(self::loadSettingsRaw(self::pdo()));
    }

    public static function saveSettings(array $patch): array
    {
        $pdo = self::pdo();
        $current = self::loadSettingsRaw($pdo);
        $merged = Shared::encryptSettings(array_merge($current, $patch));
        $stmt = $pdo->prepare('INSERT INTO q8_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
        foreach ($patch as $k => $_) {
            if (!array_key_exists($k, $merged)) {
                continue;
            }
            $stmt->execute([$k, self::encodeSettingValue($merged[$k])]);
        }
        return Shared::decryptSettings($merged);
    }

    /** @return array<string, int> */
    public static function backupStats(): array
    {
        $pdo = self::pdo();
        $stats = [];
        foreach (self::DATA_TABLES as $table) {
            $stats[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        }
        return $stats;
    }

    /** @return array<string, mixed> */
    public static function exportAll(): array
    {
        $pdo = self::pdo();
        $activityStmt = $pdo->query('SELECT * FROM q8_activity ORDER BY t DESC');
        $activity = array_map(self::rowToActivity(...), $activityStmt->fetchAll());

        return [
            '_meta' => [
                'format' => 'q8-backup',
                'version' => 2,
                'driver' => 'mysql',
                'schema' => self::SCHEMA_VERSION,
                'exported_at' => gmdate('c'),
                'app_version' => \App\Services\Installed::version(),
            ],
            'clients' => self::listClients(),
            'products' => self::listProducts(),
            'inventory' => self::listInventory(),
            'invoices' => self::listInvoices(),
            'activity' => $activity,
            'settings' => self::getSettings(),
        ];
    }

    public static function exportSql(): string
    {
        $pdo = self::pdo();
        $lines = [
            '-- Q8 VPS Platform MySQL Backup',
            '-- Schema: v' . self::SCHEMA_VERSION,
            '-- Exported: ' . gmdate('c'),
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS=0;',
        ];

        foreach (self::DATA_TABLES as $table) {
            $lines[] = "TRUNCATE TABLE `$table`;";
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll();
            if ($rows === []) {
                continue;
            }
            $columns = array_keys($rows[0]);
            $colList = '`' . implode('`,`', $columns) . '`';
            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $col) {
                    $val = $row[$col] ?? null;
                    if ($val === null) {
                        $values[] = 'NULL';
                    } elseif (is_int($val) || is_float($val)) {
                        $values[] = (string) $val;
                    } else {
                        $values[] = $pdo->quote((string) $val);
                    }
                }
                $lines[] = "INSERT INTO `$table` ($colList) VALUES (" . implode(',', $values) . ');';
            }
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
        return implode("\n", $lines) . "\n";
    }

    public static function importSql(string $sql): void
    {
        if (!str_contains($sql, 'Q8 VPS Platform MySQL Backup') && !preg_match('/\bq8_(clients|products|inventory|invoices|activity|settings)\b/i', $sql)) {
            throw new \InvalidArgumentException('Invalid SQL backup — use a file exported from this panel');
        }

        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            foreach (preg_split('/\r\n|\r|\n/', $sql) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }
                if (!self::isAllowedSqlStatement($line)) {
                    throw new \InvalidArgumentException('Unsupported SQL statement in backup file');
                }
                $pdo->exec($line);
            }
            self::setSettingRaw($pdo, 'db_schema_version', (string) self::SCHEMA_VERSION);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function isAllowedSqlStatement(string $line): bool
    {
        $upper = strtoupper(ltrim($line));
        if (str_starts_with($upper, 'SET ')) {
            return true;
        }
        foreach (self::DATA_TABLES as $table) {
            $t = strtoupper($table);
            if (str_starts_with($upper, "TRUNCATE TABLE `$t`") || str_starts_with($upper, "TRUNCATE TABLE $t")) {
                return true;
            }
            if (str_starts_with($upper, "INSERT INTO `$t`") || str_starts_with($upper, "INSERT INTO $t")) {
                return true;
            }
        }
        return false;
    }

    public static function importAll(array $obj): void
    {
        if (isset($obj['_meta']) && is_array($obj['_meta'])) {
            $driver = (string) ($obj['_meta']['driver'] ?? '');
            if ($driver !== '' && $driver !== 'mysql') {
                throw new \InvalidArgumentException('Backup driver mismatch — expected MySQL export');
            }
        }
        if (!isset($obj['clients']) || !is_array($obj['clients'])) {
            throw new \InvalidArgumentException('Invalid backup');
        }
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM q8_clients');
            $pdo->exec('DELETE FROM q8_products');
            $pdo->exec('DELETE FROM q8_inventory');
            $pdo->exec('DELETE FROM q8_invoices');
            $pdo->exec('DELETE FROM q8_activity');

            $clientStmt = $pdo->prepare(
                'INSERT INTO q8_clients (id, name, phone, telegram_id, server_id, hetzner_account_id, expires, active, otp_code, otp_exp, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($obj['clients'] as $c) {
                if (!is_array($c) || empty($c['id'])) {
                    continue;
                }
                $clientStmt->execute([
                    $c['id'],
                    trim((string) ($c['name'] ?? '')),
                    trim((string) ($c['phone'] ?? '')),
                    trim((string) ($c['telegram_id'] ?? '')),
                    (int) ($c['server_id'] ?? 0),
                    trim((string) ($c['hetzner_account_id'] ?? '')),
                    self::toMysqlDate(trim((string) ($c['expires'] ?? ''))),
                    (($c['active'] ?? true) !== false) ? 1 : 0,
                    trim((string) ($c['otp_code'] ?? '')),
                    (int) ($c['otp_exp'] ?? 0),
                    Shared::toMysqlDatetime((string) ($c['created_at'] ?? date('c'))),
                ]);
            }

            $productStmt = $pdo->prepare(
                'INSERT INTO q8_products (id, name, price, months, description, active, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($obj['products'] ?? [] as $p) {
                if (!is_array($p) || empty($p['id'])) {
                    continue;
                }
                $productStmt->execute([
                    $p['id'],
                    trim((string) ($p['name'] ?? '')),
                    (float) ($p['price'] ?? 0),
                    (int) ($p['months'] ?? 1),
                    trim((string) ($p['desc'] ?? '')),
                    (($p['active'] ?? true) !== false) ? 1 : 0,
                    Shared::toMysqlDatetime((string) ($p['created_at'] ?? date('c'))),
                ]);
            }

            $invStmt = $pdo->prepare(
                'INSERT INTO q8_inventory (id, product_id, server_id, hetzner_account_id, note, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($obj['inventory'] ?? [] as $item) {
                if (!is_array($item) || empty($item['id'])) {
                    continue;
                }
                $invStmt->execute([
                    $item['id'],
                    trim((string) ($item['product_id'] ?? '')),
                    (int) ($item['server_id'] ?? 0),
                    trim((string) ($item['hetzner_account_id'] ?? '')),
                    trim((string) ($item['note'] ?? '')),
                    Shared::toMysqlDatetime((string) ($item['created_at'] ?? date('c'))),
                ]);
            }

            $invoiceStmt = $pdo->prepare(
                'INSERT INTO q8_invoices
                 (id, number, client_id, description, amount, months, paid, paid_at, created_at, source, buyer_name, phone, product_id, paypal_order_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($obj['invoices'] ?? [] as $inv) {
                if (!is_array($inv) || empty($inv['id'])) {
                    continue;
                }
                $invoiceStmt->execute([
                    $inv['id'],
                    (string) ($inv['number'] ?? ''),
                    (string) ($inv['client_id'] ?? ''),
                    trim((string) ($inv['desc'] ?? '')),
                    (float) ($inv['amount'] ?? 0),
                    (int) ($inv['months'] ?? 1),
                    !empty($inv['paid']) ? 1 : 0,
                    !empty($inv['paid_at']) ? Shared::toMysqlDatetime((string) $inv['paid_at']) : null,
                    Shared::toMysqlDatetime((string) ($inv['created_at'] ?? date('c'))),
                    (string) ($inv['source'] ?? 'admin'),
                    trim((string) ($inv['buyer_name'] ?? '')),
                    trim((string) ($inv['phone'] ?? '')),
                    (string) ($inv['product_id'] ?? ''),
                    (string) ($inv['paypal_order_id'] ?? ''),
                ]);
            }

            $pdo->exec('DELETE FROM q8_settings');
            $settings = Shared::encryptSettings(is_array($obj['settings'] ?? null) ? $obj['settings'] : []);
            $settingsStmt = $pdo->prepare('INSERT INTO q8_settings (k, v) VALUES (?, ?)');
            foreach ($settings as $k => $v) {
                $settingsStmt->execute([$k, self::encodeSettingValue($v)]);
            }

            $actStmt = $pdo->prepare('INSERT INTO q8_activity (t, type, act_key, vars, text) VALUES (?, ?, ?, ?, ?)');
            foreach ($obj['activity'] ?? [] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $key = (string) ($entry['key'] ?? '');
                $vars = isset($entry['vars']) ? json_encode($entry['vars'], JSON_UNESCAPED_UNICODE) : null;
                $actStmt->execute([
                    Shared::toMysqlDatetime((string) ($entry['t'] ?? date('c'))),
                    (string) ($entry['type'] ?? ''),
                    $key,
                    $vars,
                    $key !== '' ? '' : (string) ($entry['text'] ?? ''),
                ]);
            }

            self::setSettingRaw($pdo, 'db_schema_version', (string) self::SCHEMA_VERSION);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function storeRefreshToken(string $clientId, string $jti, int $expiresAt): void
    {
        $pdo = self::pdo();
        self::ensureRefreshTokensTable($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO q8_refresh_tokens (jti_hash, client_id, expires_at, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([hash('sha256', $jti), $clientId, $expiresAt, date('Y-m-d H:i:s')]);
    }

    public static function consumeRefreshToken(string $clientId, string $jti): bool
    {
        $pdo = self::pdo();
        self::ensureRefreshTokensTable($pdo);
        $stmt = $pdo->prepare(
            'DELETE FROM q8_refresh_tokens WHERE jti_hash = ? AND client_id = ? AND expires_at > ?'
        );
        $stmt->execute([hash('sha256', $jti), $clientId, time()]);
        return $stmt->rowCount() > 0;
    }

    public static function revokeClientRefreshTokens(string $clientId): void
    {
        $pdo = self::pdo();
        self::ensureRefreshTokensTable($pdo);
        $stmt = $pdo->prepare('DELETE FROM q8_refresh_tokens WHERE client_id = ?');
        $stmt->execute([$clientId]);
    }
}
