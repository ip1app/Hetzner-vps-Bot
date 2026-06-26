<?php
declare(strict_types=1);

namespace App\Services;

final class CaBundle
{
    /** Resolve a CA bundle path for curl/OpenSSL on Windows and shared hosting. */
    public static function path(): ?string
    {
        $candidates = [
            ROOT . '/resources/cacert.pem',
            DATA_DIR . '/cacert.pem',
            ROOT . '/data/cacert.pem',
        ];
        $ini = trim((string) ini_get('curl.cainfo'));
        if ($ini !== '' && is_file($ini)) {
            $candidates[] = $ini;
        }
        $ini = trim((string) ini_get('openssl.cafile'));
        if ($ini !== '' && is_file($ini)) {
            $candidates[] = $ini;
        }
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }
}
