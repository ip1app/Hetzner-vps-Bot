<?php
declare(strict_types=1);

namespace App\Services;

/** Detect and remove install artifacts (setup.php, install/, wizard draft). */
final class InstallCleanup
{
    public const DRAFT_FILE = DATA_DIR . '/install_wizard.json';

    public static function setupPath(): string
    {
        return ROOT . '/public/setup.php';
    }

    public static function installDir(): string
    {
        return ROOT . '/install';
    }

    /** @return list<string> Human-readable paths still on disk */
    public static function remnants(): array
    {
        $items = [];
        if (is_file(self::setupPath())) {
            $items[] = 'public/setup.php';
        }
        if (is_dir(self::installDir())) {
            $items[] = 'install/';
        }
        if (is_file(self::DRAFT_FILE)) {
            $items[] = 'data/install_wizard.json';
        }
        return $items;
    }

    public static function hasRemnants(): bool
    {
        return self::remnants() !== [];
    }

    /**
     * @return array{deleted: list<string>, failed: list<string>}
     */
    public static function cleanup(bool $scheduleSetupSelfDelete = false): array
    {
        $deleted = [];
        $failed = [];

        if (is_file(self::DRAFT_FILE)) {
            if (@unlink(self::DRAFT_FILE)) {
                $deleted[] = 'data/install_wizard.json';
            } else {
                $failed[] = 'data/install_wizard.json';
            }
        }

        if (is_dir(self::installDir())) {
            if (self::removeDir(self::installDir())) {
                $deleted[] = 'install/';
            } else {
                $failed[] = 'install/';
            }
        }

        $setup = self::setupPath();
        if (is_file($setup)) {
            $runningSetup = realpath($setup) === realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
            if ($scheduleSetupSelfDelete && $runningSetup) {
                register_shutdown_function(static function () use ($setup): void {
                    @unlink($setup);
                });
                $deleted[] = 'public/setup.php';
            } elseif (@unlink($setup)) {
                $deleted[] = 'public/setup.php';
            } else {
                $failed[] = 'public/setup.php';
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    private static function removeDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                if (!self::removeDir($path)) {
                    return false;
                }
            } elseif (!@unlink($path)) {
                return false;
            }
        }
        return @rmdir($dir);
    }
}
