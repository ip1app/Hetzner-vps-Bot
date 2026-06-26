<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Str;

final class Telegram
{
    private static function token(): string
    {
        return trim(Config::get('TELEGRAM_BOT_TOKEN'));
    }

    /** @return array{code: int, body: mixed, raw: string} */
    private static function rawRequest(string $token, string $method, array $params = [], string $httpMethod = 'POST'): array
    {
        if ($token === '') {
            throw new \RuntimeException('Bot token not configured');
        }
        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
        $body = $httpMethod === 'GET' ? null : json_encode($params);
        $headers = $httpMethod === 'GET' ? [] : ['Content-Type: application/json'];
        if ($httpMethod === 'GET' && $params !== []) {
            $url .= '?' . http_build_query($params);
        }
        $res = HttpClient::request($httpMethod, $url, $body, $headers);
        if (!is_array($res['body']) || empty($res['body']['ok'])) {
            $msg = is_array($res['body']) ? ($res['body']['description'] ?? 'Telegram API error') : 'Telegram API error';
            throw new \RuntimeException($msg);
        }
        return $res;
    }

    private static function api(string $method, array $params = []): array
    {
        $res = self::rawRequest(self::token(), $method, $params);
        return $res['body']['result'] ?? [];
    }

    public static function sendMessage(string $chatId, string $text, ?array $replyMarkup = null, string $parseMode = 'HTML'): void
    {
        $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => $parseMode];
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        self::api('sendMessage', $params);
    }

    public static function answerCallbackQuery(string $id, string $text = ''): void
    {
        self::api('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $text]);
    }

    public static function editMessageText(string $chatId, int $messageId, string $text, ?array $replyMarkup = null): void
    {
        $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        self::api('editMessageText', $params);
    }

    /** @return array<string, mixed> */
    public static function getMe(): array
    {
        return self::api('getMe');
    }

    /** @return array<string, mixed> */
    public static function getMeWithToken(string $token): array
    {
        $res = self::rawRequest($token, 'getMe', [], 'GET');
        return $res['body']['result'] ?? [];
    }

    public static function setWebhook(string $url, string $secretToken = ''): void
    {
        self::setWebhookWithToken(self::token(), $url, $secretToken);
    }

    public static function setWebhookWithToken(string $token, string $url, string $secretToken = ''): void
    {
        $params = [
            'url' => $url,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => false,
        ];
        if ($secretToken !== '') {
            $params['secret_token'] = $secretToken;
        }
        self::rawRequest($token, 'setWebhook', $params);
    }

    public static function deleteWebhook(): void
    {
        self::api('deleteWebhook', ['drop_pending_updates' => true]);
    }

    /** @return array<string, mixed> */
    public static function getWebhookInfo(): array
    {
        return self::api('getWebhookInfo');
    }

    /** @return array<string, mixed> */
    public static function getWebhookInfoWithToken(string $token): array
    {
        $res = self::rawRequest($token, 'getWebhookInfo', [], 'GET');
        return $res['body']['result'] ?? [];
    }

    /** @return array<string, mixed> */
    public static function verifyToken(string $token): array
    {
        return self::getMeWithToken($token);
    }

    /** @param list<array{command: string, description: string}> $commands */
    public static function setMyCommands(string $token, array $commands, string $languageCode = '', ?array $scope = null): void
    {
        $params = ['commands' => $commands];
        if ($languageCode !== '') {
            $params['language_code'] = $languageCode;
        }
        if ($scope !== null) {
            $params['scope'] = $scope;
        }
        self::rawRequest($token, 'setMyCommands', $params);
    }

    public static function setChatMenuButtonCommands(string $token): void
    {
        self::rawRequest($token, 'setChatMenuButton', [
            'menu_button' => ['type' => 'commands'],
        ]);
    }

    public static function setMyDescription(string $token, string $text, string $languageCode = ''): void
    {
        $params = ['description' => Str::limit($text, 512)];
        if ($languageCode !== '') {
            $params['language_code'] = $languageCode;
        }
        self::rawRequest($token, 'setMyDescription', $params);
    }

    public static function setMyShortDescription(string $token, string $text, string $languageCode = ''): void
    {
        $params = ['short_description' => Str::limit($text, 120)];
        if ($languageCode !== '') {
            $params['language_code'] = $languageCode;
        }
        self::rawRequest($token, 'setMyShortDescription', $params);
    }

    public static function removeReplyKeyboard(string $chatId): void
    {
        self::api('sendMessage', [
            'chat_id' => $chatId,
            'text' => '.',
            'disable_notification' => true,
            'reply_markup' => json_encode(['remove_keyboard' => true]),
        ]);
    }

    public static function isPublicWebhookUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = strtolower($p['host'] ?? '');
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.local')) {
            return false;
        }
        return true;
    }

    public static function buildWebhookUrl(string $publicBase, string $secret): string
    {
        return rtrim($publicBase, '/') . '/tg/' . rawurlencode($secret);
    }
}
