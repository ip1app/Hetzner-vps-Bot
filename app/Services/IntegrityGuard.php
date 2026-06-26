<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Html;
use App\Helpers\I18n;

/** Verifies protected credit files — warns, blocks updates, and restricts features when tampered. */
final class IntegrityGuard
{
    private const MANIFEST_FILE = ROOT . '/app/integrity.manifest.json';
    private const UPDATES_BLOCKED = '__integrity__';

    /** @var array{ok: bool, files: list<string>}|null */
    private static ?array $state = null;

    /** @return array{ok: bool, files: list<string>} */
    public static function verify(): array
    {
        if (self::$state !== null) {
            return self::$state;
        }
        $tampered = [];
        $manifest = self::loadManifest();
        if ($manifest === null) {
            self::$state = ['ok' => false, 'files' => ['app/integrity.manifest.json']];
            return self::$state;
        }
        foreach ($manifest as $rel => $expected) {
            if (!is_string($rel) || !is_string($expected) || $expected === '') {
                continue;
            }
            $path = ROOT . '/' . ltrim(str_replace('\\', '/', $rel), '/');
            if (!is_file($path)) {
                $tampered[] = $rel;
                continue;
            }
            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || !hash_equals($expected, $hash)) {
                $tampered[] = $rel;
            }
        }
        self::$state = ['ok' => $tampered === [], 'files' => $tampered];
        return self::$state;
    }

    /** @return array<string, string>|null */
    private static function loadManifest(): ?array
    {
        if (!is_file(self::MANIFEST_FILE)) {
            return null;
        }
        $raw = file_get_contents(self::MANIFEST_FILE);
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public static function isTampered(): bool
    {
        return !self::verify()['ok'];
    }

    public static function featuresEnabled(): bool
    {
        return !self::isTampered();
    }

    public static function updatesAllowed(): bool
    {
        return self::featuresEnabled();
    }

    public static function updatesBlockedFlag(): string
    {
        return self::UPDATES_BLOCKED;
    }

    public static function adminBannerHtml(string $loc): string
    {
        if (!self::isTampered()) {
            return '';
        }
        return '<div class="warn integrity-warn">' . I18n::t('integrity.admin_banner', $loc) . '</div>';
    }

    public static function storeWarningHtml(string $loc): string
    {
        if (!self::isTampered()) {
            return '';
        }
        $e = Html::esc(...);
        return '<div class="foot-integrity-warn">' . $e(I18n::t('integrity.store_warning', $loc)) . '</div>';
    }

    public static function featuresDisabledMessage(string $loc): string
    {
        return I18n::t('integrity.features_disabled', $loc);
    }

    public static function assertFeaturesEnabled(string $locale = 'en'): void
    {
        if (self::featuresEnabled()) {
            return;
        }
        throw new \RuntimeException(self::featuresDisabledMessage($locale));
    }
}
