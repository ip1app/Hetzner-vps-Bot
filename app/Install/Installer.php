<?php
declare(strict_types=1);

namespace App\Install;

use App\Database\Database;
use App\Helpers\Html;
use App\Helpers\Url;
use App\Response;
use App\Services\AdminAuth;
use App\Services\AdminPath;
use App\Services\Config;
use App\Services\EnvFile;
use App\Services\Installed;

/** Standalone installer — file-based wizard state, posts to SCRIPT_NAME (no router). */
final class Installer
{
    private const DRAFT_FILE = DATA_DIR . '/install_wizard.json';

    public static function run(): void
    {
        Installed::ensureLegacyLock();

        if (Installed::isInstalled()) {
            self::html(InstallerView::locked(self::locale()));
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            self::handlePost();
            return;
        }

        self::handleGet();
    }

    private static function scriptUrl(): string
    {
        return $_SERVER['SCRIPT_NAME'] ?? '/setup.php';
    }

    private static function go(string $query = ''): never
    {
        $target = self::scriptUrl();
        if ($query !== '') {
            $target .= '?' . $query;
        }
        // SCRIPT_NAME is the correct full path — bypass Url::to() to avoid subdirectory bugs.
        \App\Services\SecurityHeaders::send();
        http_response_code(302);
        header('Location: ' . $target);
        exit;
    }

    /** @return array<string, string> */
    private static function draft(): array
    {
        if (!is_file(self::DRAFT_FILE)) {
            return [];
        }
        $raw = file_get_contents(self::DRAFT_FILE);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return [];
        }
        return array_map(static fn($v) => is_string($v) ? $v : (string) $v, $data);
    }

    /** @param array<string, string> $data */
    private static function saveDraft(array $data): void
    {
        if (!is_dir(DATA_DIR)) {
            if (!@mkdir(DATA_DIR, 0755, true) && !is_dir(DATA_DIR)) {
                throw new \RuntimeException('Cannot create data/ directory');
            }
        }
        if (file_put_contents(self::DRAFT_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new \RuntimeException('Cannot write install wizard data — check data/ permissions');
        }
    }

    private static function clearDraft(): void
    {
        if (is_file(self::DRAFT_FILE)) {
            @unlink(self::DRAFT_FILE);
        }
    }

    private static function locale(): string
    {
        $d = self::draft();
        $q = strtolower(trim((string) ($d['locale'] ?? $_GET['lang'] ?? $_POST['locale'] ?? '')));
        return $q === 'ar' ? 'ar' : 'en';
    }

    private static function hasLocale(): bool
    {
        $d = self::draft();
        $loc = strtolower(trim((string) ($d['locale'] ?? '')));
        return $loc === 'ar' || $loc === 'en';
    }

    private static function handleGet(): void
    {
        $pick = strtolower(trim((string) ($_GET['lang'] ?? '')));
        if ($pick === 'ar' || $pick === 'en') {
            $d = self::draft();
            $d['locale'] = $pick;
            self::saveDraft($d);
            self::go('step=1');
        }

        if (!self::hasLocale()) {
            self::html(InstallerView::language());
            return;
        }

        $loc = self::locale();
        $step = max(1, min(4, (int) ($_GET['step'] ?? 1)));
        $draft = self::draft();

        if ($step >= 2 && ($draft['store_name'] ?? '') === '') {
            self::go('step=1');
        }
        if ($step >= 3 && (($draft['db_name'] ?? '') === '' || ($draft['db_user'] ?? '') === '')) {
            self::go('step=2');
        }
        if ($step === 4) {
            self::go('step=3');
        }

        self::html(match ($step) {
            1 => InstallerView::store($loc, $draft, self::scriptUrl()),
            2 => InstallerView::database($loc, $draft, self::scriptUrl(), '', '', Database::mysqlAvailable()),
            3 => InstallerView::admin($loc, $draft, self::scriptUrl()),
            default => InstallerView::store($loc, $draft, self::scriptUrl()),
        });
    }

    private static function handlePost(): void
    {
        if (!self::hasLocale()) {
            self::html(InstallerView::language());
            return;
        }

        $loc = self::locale();
        $action = trim((string) ($_POST['action'] ?? ''));
        $draft = self::draft();
        $draft['locale'] = $loc;

        if ($action === 'store') {
            $draft['store_name'] = trim((string) ($_POST['store_name'] ?? ''));
            $draft['currency'] = trim((string) ($_POST['currency'] ?? 'USD')) ?: 'USD';
            if ($draft['store_name'] === '') {
                self::html(InstallerView::store($loc, $draft, self::scriptUrl(), InstallerView::t('err_store', $loc)));
                return;
            }
            self::saveDraft($draft);
            self::go('step=2');
        }

        if ($action === 'test_db') {
            $draft = self::mergeDbPost($draft);
            self::saveDraft($draft);
            $err = self::validateMysql($loc, $draft);
            $ok = $err === null ? InstallerView::t('test_ok', $loc) : '';
            self::html(InstallerView::database($loc, $draft, self::scriptUrl(), $err ?? '', $ok, Database::mysqlAvailable()));
            return;
        }

        if ($action === 'db') {
            $draft = self::mergeDbPost($draft);
            self::saveDraft($draft);
            $err = self::validateMysql($loc, $draft);
            if ($err !== null) {
                self::html(InstallerView::database($loc, $draft, self::scriptUrl(), $err, '', Database::mysqlAvailable()));
                return;
            }
            self::go('step=3');
        }

        if ($action === 'finish') {
            $draft['admin_user'] = trim((string) ($_POST['admin_user'] ?? 'admin')) ?: 'admin';
            $pass = (string) ($_POST['admin_pass'] ?? '');
            $pass2 = (string) ($_POST['admin_pass2'] ?? '');
            if (strlen($pass) < 6) {
                self::html(InstallerView::admin($loc, $draft, self::scriptUrl(), InstallerView::t('err_pass_short', $loc)));
                return;
            }
            if ($pass !== $pass2) {
                self::html(InstallerView::admin($loc, $draft, self::scriptUrl(), InstallerView::t('err_pass_match', $loc)));
                return;
            }
            $draft['admin_pass'] = $pass;
            try {
                self::completeInstall($draft, $loc);
                self::clearDraft();
                \App\Services\InstallCleanup::cleanup(scheduleSetupSelfDelete: true);
                self::html(InstallerView::done($loc, \App\Services\InstallCleanup::remnants()));
            } catch (\Throwable $e) {
                error_log('Install failed: ' . $e->getMessage());
                $hint = InstallerView::t('err_finish', $loc);
                if (preg_match('/write|writable|permission|chmod/i', $e->getMessage())) {
                    $hint = InstallerView::t('err_writable', $loc);
                }
                self::html(InstallerView::admin($loc, $draft, self::scriptUrl(), $hint . "\n" . $e->getMessage()));
            }
            return;
        }

        self::html(InstallerView::store($loc, $draft, self::scriptUrl(), InstallerView::t('err_post', $loc)));
    }

    /** @param array<string, string> $draft */
    private static function mergeDbPost(array $draft): array
    {
        $draft['db_host'] = trim((string) ($_POST['db_host'] ?? $draft['db_host'] ?? 'localhost')) ?: 'localhost';
        $draft['db_port'] = trim((string) ($_POST['db_port'] ?? $draft['db_port'] ?? '3306')) ?: '3306';
        $draft['db_name'] = trim((string) ($_POST['db_name'] ?? $draft['db_name'] ?? ''));
        $draft['db_user'] = trim((string) ($_POST['db_user'] ?? $draft['db_user'] ?? ''));
        $draft['db_pass'] = (string) ($_POST['db_pass'] ?? $draft['db_pass'] ?? '');
        return $draft;
    }

    /** @param array<string, string> $vals */
    private static function validateMysql(string $loc, array $vals): ?string
    {
        if (!Database::mysqlAvailable()) {
            return InstallerView::t('err_pdo_mysql', $loc);
        }
        if ($vals['db_name'] === '' || $vals['db_user'] === '') {
            return InstallerView::t('err_mysql', $loc);
        }
        $r = Database::testMysqlConnection([
            'host' => $vals['db_host'],
            'port' => $vals['db_port'],
            'database' => $vals['db_name'],
            'user' => $vals['db_user'],
            'password' => $vals['db_pass'],
        ]);
        if ($r['ok']) {
            return null;
        }
        if (($r['code'] ?? '') === 'no_pdo_mysql') {
            return InstallerView::t('err_pdo_mysql', $loc);
        }
        return InstallerView::t('test_fail', $loc, ['msg' => $r['error'] ?? '']);
    }

    /** @param array<string, string> $vals */
    private static function completeInstall(array $vals, string $loc): void
    {
        if (!Database::mysqlAvailable()) {
            throw new \RuntimeException('pdo_mysql extension is not enabled on this server');
        }
        if ($vals['db_name'] === '' || $vals['db_user'] === '') {
            throw new \RuntimeException('MySQL database name and username are required');
        }
        if (!is_writable(DATA_DIR)) {
            throw new \RuntimeException('data/ is not writable — set permissions to 755 or 775');
        }
        $envDir = dirname(EnvFile::path());
        if (!is_writable($envDir)) {
            throw new \RuntimeException('Project folder is not writable — cannot save .env');
        }

        $secret = Config::get('SECRET_KEY') ?: bin2hex(random_bytes(32));
        $cronKey = Config::get('CRON_KEY') ?: bin2hex(random_bytes(16));
        $jwtSecret = Config::get('JWT_SECRET') ?: bin2hex(random_bytes(32));
        $webhookSecret = Config::get('WEBHOOK_SECRET') ?: bin2hex(random_bytes(16));
        $env = [
            'SECRET_KEY' => $secret,
            'CRON_KEY' => $cronKey,
            'JWT_SECRET' => $jwtSecret,
            'WEBHOOK_SECRET' => $webhookSecret,
            'ADMIN_USER' => $vals['admin_user'],
            'ADMIN_PASS' => AdminAuth::hashPassword($vals['admin_pass']),
            'ADMIN_PATH' => 'admin',
            'DB_HOST' => $vals['db_host'],
            'DB_PORT' => $vals['db_port'],
            'DB_NAME' => $vals['db_name'],
            'DB_USER' => $vals['db_user'],
            'DB_PASS' => $vals['db_pass'],
        ];
        if (is_file(EnvFile::path())) {
            EnvFile::update($env);
        } else {
            EnvFile::write($env);
        }
        Database::setupFreshMysql($env);
        Database::saveSettings([
            'store_name' => $vals['store_name'],
            'currency' => $vals['currency'],
            'store_locale' => $loc,
            'admin_locale' => $loc,
        ]);
        Installed::markInstalled();
    }

    private static function html(string $body): never
    {
        Response::html($body);
    }
}
