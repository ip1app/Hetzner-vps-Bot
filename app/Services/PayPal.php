<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Request;

final class PayPal
{
    /** @var list<string> */
    private const SUPPORTED_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'JPY', 'CHF', 'HKD', 'SGD', 'SEK', 'DKK',
        'PLN', 'NOK', 'HUF', 'CZK', 'ILS', 'MXN', 'BRL', 'TWD', 'THB', 'PHP', 'NZD',
    ];

    /** @return array{clientId: string, secret: string, mode: string, currency: string} */
    public static function conf(): array
    {
        $s = Database::getSettings();
        return [
            'clientId' => trim((string) ($s['paypal_client_id'] ?? '')),
            'secret' => trim((string) ($s['paypal_secret'] ?? '')),
            'mode' => ($s['paypal_mode'] ?? '') === 'live' ? 'live' : 'sandbox',
            'currency' => strtoupper((string) ($s['paypal_currency'] ?? 'USD')),
        ];
    }

    public static function configured(): bool
    {
        $c = self::conf();
        return $c['clientId'] !== '' && $c['secret'] !== '' && !self::looksEncrypted($c['secret']);
    }

    /** @param array<string, string> $overrides */
    public static function overridesFromRequest(Request $req): array
    {
        $st = Database::getSettings();
        $overrides = [];

        $clientId = trim((string) ($req->body['paypal_client_id'] ?? ''));
        if ($clientId !== '') {
            $overrides['clientId'] = $clientId;
        }

        $secret = trim((string) ($req->body['paypal_secret'] ?? ''));
        if ($secret !== '') {
            $overrides['secret'] = $secret;
        } elseif (!empty($st['paypal_secret'])) {
            $overrides['secret'] = trim((string) $st['paypal_secret']);
        }

        $modeRaw = (string) ($req->body['paypal_mode'] ?? '');
        if ($modeRaw === 'live' || $modeRaw === 'sandbox') {
            $overrides['mode'] = $modeRaw;
        }

        return $overrides;
    }

    public static function publicUrl(Request $req, string $path): string
    {
        $path = $path === '' || $path === '/' ? '/' : (str_starts_with($path, '/') ? $path : '/' . $path);
        $base = rtrim($req->baseUrl(), '/');
        if ($path === '/') {
            return $base ?: '/';
        }
        return $base . $path;
    }

    public static function webhookUrl(Request $req): string
    {
        $url = self::publicUrl($req, '/paypal/webhook');
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException('Webhook requires a public https URL — set WEBHOOK_DOMAIN in .env (include subfolder if any, e.g. https://yourdomain.com/app)');
        }
        return $url;
    }

    private static function apiHost(?string $mode = null): string
    {
        $m = $mode ?? self::conf()['mode'];
        return $m === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private static function looksEncrypted(string $value): bool
    {
        return str_starts_with($value, 'enc:v1:');
    }

    /** @param array{clientId: string, secret: string, mode: string} $cfg */
    private static function assertCredentials(array $cfg): void
    {
        if ($cfg['clientId'] === '') {
            throw new \RuntimeException('PayPal Client ID is missing — paste it from PayPal Developer → Apps');
        }
        if ($cfg['secret'] === '') {
            throw new \RuntimeException('PayPal Secret is missing — save credentials first or paste Secret before testing');
        }
        if (self::looksEncrypted($cfg['secret'])) {
            throw new \RuntimeException('PayPal Secret could not be decrypted — check SECRET_KEY in .env matches the key used when saving');
        }
    }

    /** @param array{clientId: string, secret: string, mode: string} $cfg */
    private static function mergeCredentials(array $cfg, array $overrides): array
    {
        return [
            'clientId' => trim($overrides['clientId'] ?? $cfg['clientId']),
            'secret' => trim($overrides['secret'] ?? $cfg['secret']),
            'mode' => ($overrides['mode'] ?? $cfg['mode']) === 'live' ? 'live' : 'sandbox',
        ];
    }

    /** @param array<string, mixed> $body */
    private static function apiErrorMessage(int $code, mixed $body): string
    {
        if (is_array($body)) {
            if (!empty($body['error_description'])) {
                return (string) $body['error_description'];
            }
            if (!empty($body['message'])) {
                $msg = (string) $body['message'];
                $detail = $body['details'][0]['description'] ?? $body['details'][0]['issue'] ?? '';
                return $detail !== '' ? $msg . ': ' . $detail : $msg;
            }
            if (!empty($body['error'])) {
                return (string) $body['error'];
            }
        }
        if (is_string($body) && $body !== '') {
            return substr($body, 0, 200);
        }
        return 'PayPal API error (HTTP ' . $code . ')';
    }

    /**
     * @param array<string, string> $headers
     * @return array{code: int, body: mixed, raw: string}
     */
    private static function api(string $method, string $url, ?string $body, array $headers): array
    {
        $res = HttpClient::request($method, $url, $body, $headers);
        if ($res['code'] >= 400) {
            throw new \RuntimeException(self::apiErrorMessage($res['code'], $res['body']));
        }
        return $res;
    }

    /** @param array<string, string> $overrides */
    public static function fetchAccessToken(array $overrides = []): string
    {
        $cfg = self::mergeCredentials(self::conf(), $overrides);
        self::assertCredentials($cfg);

        $auth = base64_encode($cfg['clientId'] . ':' . $cfg['secret']);
        $res = self::api(
            'POST',
            self::apiHost($cfg['mode']) . '/v1/oauth2/token',
            'grant_type=client_credentials',
            [
                'Authorization: Basic ' . $auth,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ]
        );
        if (!is_array($res['body']) || empty($res['body']['access_token'])) {
            throw new \RuntimeException('PayPal auth failed — no access token in response');
        }
        return (string) $res['body']['access_token'];
    }

    public static function isCurrencySupported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::SUPPORTED_CURRENCIES, true);
    }

    /** @return array{id: string, approveUrl: string} */
    public static function createOrder(float $amount, string $desc, string $returnUrl, string $cancelUrl): array
    {
        $cfg = self::conf();
        if (!self::isCurrencySupported($cfg['currency'])) {
            throw new \RuntimeException(
                'Currency ' . $cfg['currency'] . ' is not supported by PayPal — set paypal_currency to USD (or EUR) in admin payments'
            );
        }
        if ($amount <= 0) {
            throw new \RuntimeException('Order amount must be greater than zero');
        }

        $token = self::fetchAccessToken();
        $payload = json_encode([
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => $cfg['currency'],
                    'value' => number_format($amount, 2, '.', ''),
                ],
                'description' => substr($desc, 0, 120),
            ]],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'brand_name' => substr(Config::get('STORE_NAME', 'VPS Store'), 0, 127),
            ],
        ], JSON_THROW_ON_ERROR);

        $res = self::api('POST', self::apiHost() . '/v2/checkout/orders', $payload, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
            'Prefer: return=representation',
        ]);

        $body = is_array($res['body']) ? $res['body'] : [];
        $orderId = (string) ($body['id'] ?? '');
        $approve = '';
        foreach (($body['links'] ?? []) as $link) {
            if (($link['rel'] ?? '') === 'approve' && !empty($link['href'])) {
                $approve = (string) $link['href'];
                break;
            }
        }
        if ($orderId === '' || $approve === '') {
            throw new \RuntimeException('PayPal did not return an approval URL — check currency and API credentials');
        }
        return ['id' => $orderId, 'approveUrl' => $approve];
    }

    /** @return array{completed: bool, raw: mixed} */
    public static function captureOrder(string $orderId): array
    {
        $token = self::fetchAccessToken();
        $res = self::api('POST', self::apiHost() . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', '{}', [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
            'Prefer: return=representation',
        ]);
        $body = $res['body'];
        $status = is_array($body) ? (string) ($body['status'] ?? '') : '';
        return ['completed' => $status === 'COMPLETED', 'raw' => $body];
    }

    public static function explain(\Throwable $e): string
    {
        $msg = $e->getMessage();
        $lower = strtolower($msg);

        if (str_contains($lower, 'invalid_client') || str_contains($lower, 'client authentication failed')) {
            return 'Client ID أو Secret غير صحيح — انسخهما من نفس التطبيق في PayPal Developer وبنفس الوضع (Sandbox/Live)';
        }
        if (str_contains($msg, 'enc:v1:') || str_contains($lower, 'could not be decrypted')) {
            return 'لم يُفك تشفير Secret — تأكد أن SECRET_KEY في .env لم يتغيّر بعد الحفظ';
        }
        if (str_contains($lower, 'secret is missing')) {
            return 'Secret غير محفوظ — الصق Secret ثم احفظ أو اضغط فحص الاتصال من نفس النموذج';
        }
        if (str_contains($lower, 'not supported by paypal') || str_contains($lower, 'currency')) {
            return $msg;
        }
        if (str_contains($lower, 'curl') || str_contains($lower, 'ssl') || str_contains($lower, 'ca bundle')) {
            return $msg . ' — تحقق من cURL وشهادة SSL على الاستضافة';
        }

        return $msg;
    }

    /** @param array<string, string> $overrides */
    public static function testConnection(array $overrides = []): array
    {
        self::fetchAccessToken($overrides);
        $cfg = self::mergeCredentials(self::conf(), $overrides);
        $currency = strtoupper((string) (Database::getSettings()['paypal_currency'] ?? 'USD'));
        return [
            'mode' => $cfg['mode'],
            'modeLabel' => $cfg['mode'] === 'live' ? 'Live' : 'Sandbox',
            'currency' => $currency,
            'currencyOk' => self::isCurrencySupported($currency),
        ];
    }

    public static function ensureWebhook(Request $req): string
    {
        $url = self::webhookUrl($req);
        $token = self::fetchAccessToken();
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $res = self::api('GET', self::apiHost() . '/v1/notifications/webhooks', null, $headers);
        $hook = null;
        foreach ((is_array($res['body']) ? ($res['body']['webhooks'] ?? []) : []) as $w) {
            if (is_array($w) && ($w['url'] ?? '') === $url) {
                $hook = $w;
                break;
            }
        }

        if (!$hook) {
            $create = self::api('POST', self::apiHost() . '/v1/notifications/webhooks', json_encode([
                'url' => $url,
                'event_types' => [
                    ['name' => 'PAYMENT.CAPTURE.COMPLETED'],
                    ['name' => 'CHECKOUT.ORDER.APPROVED'],
                ],
            ], JSON_THROW_ON_ERROR), $headers);
            $hook = is_array($create['body']) ? $create['body'] : [];
        }

        $hookId = (string) ($hook['id'] ?? '');
        if ($hookId === '') {
            throw new \RuntimeException('PayPal did not return a webhook ID');
        }

        Database::saveSettings(['paypal_webhook_id' => $hookId, 'paypal_webhook_url' => $url]);
        return $hookId;
    }

    /** @param array<string, mixed> $event */
    public static function verifyWebhookSignature(array $headers, array $event): bool
    {
        $webhookId = Database::getSettings()['paypal_webhook_id'] ?? '';
        if ($webhookId === '') {
            return false;
        }

        $h = static function (string $name) use ($headers): string {
            $lower = strtolower($name);
            foreach ($headers as $k => $v) {
                if (strtolower((string) $k) === $lower) {
                    return (string) $v;
                }
            }
            return '';
        };

        $token = self::fetchAccessToken();
        $payload = json_encode([
            'auth_algo' => $h('Paypal-Auth-Algo'),
            'cert_url' => $h('Paypal-Cert-Url'),
            'transmission_id' => $h('Paypal-Transmission-Id'),
            'transmission_sig' => $h('Paypal-Transmission-Sig'),
            'transmission_time' => $h('Paypal-Transmission-Time'),
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ], JSON_THROW_ON_ERROR);

        $res = self::api('POST', self::apiHost() . '/v1/notifications/verify-webhook-signature', $payload, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        return is_array($res['body']) && ($res['body']['verification_status'] ?? '') === 'SUCCESS';
    }
}
