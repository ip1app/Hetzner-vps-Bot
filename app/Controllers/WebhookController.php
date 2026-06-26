<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Bot\BotHandler;
use App\Request;
use App\Response;
use App\Services\Config;
use App\Services\GatewayManager;

final class WebhookController
{
    public function telegram(Request $req, array $params): void
    {
        $secret = Config::get('WEBHOOK_SECRET');
        if ($secret === '') {
            error_log('Telegram webhook: WEBHOOK_SECRET is not configured');
            Response::text('forbidden', 403);
        }
        $incomingSecret = (string) ($params['secret'] ?? '');
        if (!hash_equals($secret, $incomingSecret)) {
            error_log('Telegram webhook: secret mismatch (path). incoming_len=' . strlen($incomingSecret) . ' configured_len=' . strlen($secret));
            Response::text('forbidden', 403);
        }
        $headerSecret = $req->header('X-Telegram-Bot-Api-Secret-Token');
        if ($secret !== '' && $headerSecret !== '' && !hash_equals($secret, $headerSecret)) {
            error_log('Telegram webhook: secret_token header mismatch');
            Response::text('forbidden', 403);
        }

        $raw = file_get_contents('php://input') ?: '{}';
        $update = json_decode($raw, true);
        if (!is_array($update)) {
            Response::text('bad request', 400);
        }

        // Helpful log for debugging "bot not responding"
        $type = isset($update['message']) ? 'message' : (isset($update['callback_query']) ? 'callback' : (isset($update['my_chat_member']) ? 'member' : 'other'));
        error_log('Telegram webhook: received update type=' . $type . ' chat_id=' . ($update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? 'n/a'));

        try {
            (new BotHandler())->handle($update);
        } catch (\Throwable $e) {
            error_log('Bot webhook error: ' . $e->getMessage());
        }
        Response::text('ok');
    }

    public function paypal(Request $req, array $params = []): void
    {
        $gw = GatewayManager::get('paypal');
        if ($gw === null) {
            Response::text('gateway missing', 500);
        }
        $result = $gw->handleWebhook($req);
        Response::text($result['body'], $result['status']);
    }
}
