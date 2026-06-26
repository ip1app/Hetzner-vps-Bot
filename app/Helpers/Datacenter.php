<?php
declare(strict_types=1);

namespace App\Helpers;

final class Datacenter
{
    /** @param array<string, mixed> $server Hetzner server payload */
    public static function countryLabel(array $server, string $loc): string
    {
        $code = strtoupper(trim((string) ($server['datacenter']['location']['country'] ?? '')));
        if ($code === '') {
            return '—';
        }
        $key = 'country.' . $code;
        $label = I18n::t($key, $loc);
        return str_starts_with($label, 'country.') ? $code : $label;
    }
}
