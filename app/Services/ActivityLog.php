<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Helpers\I18n;

final class ActivityLog
{
    public static function log(string $type, string $key, array $vars = []): void
    {
        Database::logActivity($type, null, ['key' => $key, 'vars' => $vars]);
    }

    public static function typeLabel(string $type, string $locale): string
    {
        $id = strtolower($type);
        return I18n::t('admin.act.type.' . $id, $locale) ?: $type;
    }

    /** @param array<string, mixed> $entry */
    public static function formatText(array $entry, string $locale): string
    {
        if (!empty($entry['key'])) {
            return I18n::t((string) $entry['key'], $locale, $entry['vars'] ?? []);
        }
        return (string) ($entry['text'] ?? '');
    }
}
