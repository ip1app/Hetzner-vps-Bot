<?php
declare(strict_types=1);

namespace App\Services;

final class HttpClient
{
    /** @param array<string, string> $headers */
    public static function request(string $method, string $url, ?string $body = null, array $headers = [], ?string $user = null, ?string $pass = null): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        $ca = CaBundle::path();
        if ($ca !== null) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($user !== null && $pass !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            $hint = $ca === null
                ? ' (missing CA bundle — place cacert.pem in data/ or set curl.cainfo in php.ini)'
                : '';
            throw new \RuntimeException(($err !== '' ? $err : 'HTTP request failed') . $hint);
        }
        $json = json_decode($response, true);
        return ['code' => $code, 'body' => $json ?? $response, 'raw' => $response];
    }
}
