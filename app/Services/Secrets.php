<?php
declare(strict_types=1);

namespace App\Services;

final class Secrets
{
    private const PREFIX = 'enc:v1:';

    private static function key(): string
    {
        $base = Config::get('SECRET_KEY');
        if ($base === '') {
            $base = Config::get('TELEGRAM_BOT_TOKEN');
        }
        return hash('sha256', 'q8-secrets|' . $base, true);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '' || str_starts_with($plain, self::PREFIX)) {
            return $plain;
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = \openssl_encrypt($plain, 'aes-256-gcm', self::key(), \OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return $plain;
        }
        return self::PREFIX . bin2hex($iv) . ':' . bin2hex($tag) . ':' . bin2hex($cipher);
    }

    public static function decrypt(string $value): string
    {
        if (!str_starts_with($value, self::PREFIX)) {
            return $value;
        }
        $rest = substr($value, strlen(self::PREFIX));
        $parts = explode(':', $rest, 3);
        if (count($parts) !== 3) {
            return $value;
        }
        [$ivHex, $tagHex, $dataHex] = $parts;
        $plain = \openssl_decrypt(
            hex2bin($dataHex) ?: '',
            'aes-256-gcm',
            self::key(),
            \OPENSSL_RAW_DATA,
            hex2bin($ivHex) ?: '',
            hex2bin($tagHex) ?: ''
        );
        return $plain === false ? $value : $plain;
    }
}
