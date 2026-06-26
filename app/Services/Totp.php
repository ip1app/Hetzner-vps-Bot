<?php
declare(strict_types=1);

namespace App\Services;

/** RFC 6238 TOTP (Google Authenticator compatible). */
final class Totp
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;

    public static function randomSecret(int $length = 16): string
    {
        $out = '';
        $bytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $out .= self::BASE32[ord($bytes[$i]) & 31];
        }
        return $out;
    }

    public static function provisioningUri(string $secret, string $label, string $issuer): string
    {
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => self::PERIOD,
        ]);
        return 'otpauth://totp/' . rawurlencode($label) . '?' . $params;
    }

    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            $counter = intdiv($now, self::PERIOD) + $i;
            if (hash_equals(self::codeAt($secret, $counter), $code)) {
                return true;
            }
        }
        return false;
    }

    private static function codeAt(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }
        $bin = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $trunc = (
            ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff)
        );
        return str_pad((string) ($trunc % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $input): string
    {
        $input = strtoupper(preg_replace('/[^A-Z2-7]/', '', $input) ?? '');
        if ($input === '') {
            return '';
        }
        $buffer = 0;
        $bits = 0;
        $out = '';
        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos(self::BASE32, $input[$i]);
            if ($pos === false) {
                return '';
            }
            $buffer = ($buffer << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xff);
            }
        }
        return $out;
    }
}
