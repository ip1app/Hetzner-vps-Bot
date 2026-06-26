<?php
declare(strict_types=1);

namespace App\Bot;

use App\Bot\BotSetup;
use App\Bot\BotStore;
use App\Database\Database;
use App\Gateways\CheckoutRequest;
use App\Helpers\Datacenter;
use App\Helpers\I18n;
use App\Helpers\Locale;
use App\Helpers\Str;
use App\Request;
use App\Services\ActivityLog;
use App\Services\ClientAccounts;
use App\Services\Config;
use App\Services\Finance;
use App\Services\GatewayManager;
use App\Services\IntegrityGuard;
use App\Services\ProviderManager;
use App\Services\Renewal;
use App\Services\StockAlert;
use App\Services\Telegram;

final class BotHandler
{
    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        if (!IntegrityGuard::featuresEnabled()) {
            $this->replyIntegrityBlocked($update);
            return;
        }
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }
        if (isset($update['message']['contact'])) {
            $this->handleContact($update['message']);
            return;
        }
        if (isset($update['message']['text'])) {
            $this->handleText($update['message']);
        }
    }

    private function adminId(): string
    {
        return trim(explode(',', Config::get('ADMIN_IDS'))[0] ?? '');
    }

    /** @param array<string, mixed> $update */
    private function replyIntegrityBlocked(array $update): void
    {
        $chatId = '';
        if (isset($update['message']['chat']['id'])) {
            $chatId = (string) $update['message']['chat']['id'];
        } elseif (isset($update['callback_query']['message']['chat']['id'])) {
            $chatId = (string) $update['callback_query']['message']['chat']['id'];
        }
        if ($chatId === '') {
            return;
        }
        $langCode = (string) ($update['message']['from']['language_code']
            ?? $update['callback_query']['from']['language_code']
            ?? 'en');
        $loc = str_starts_with($langCode, 'ar') ? 'ar' : 'en';
        Telegram::sendMessage($chatId, '⚠️ ' . IntegrityGuard::featuresDisabledMessage($loc));
    }

    /** @param array<string, mixed> $msg */
    private function handleContact(array $msg): void
    {
        $tgId = (string) ($msg['from']['id'] ?? '');
        $phone = (string) ($msg['contact']['phone_number'] ?? '');
        $contactUserId = (string) ($msg['contact']['user_id'] ?? '');
        $loc = Locale::resolveBotLocale($tgId, Database::getClientByTelegramId($tgId));

        if ($contactUserId !== '' && $contactUserId !== $tgId) {
            Telegram::sendMessage($tgId, BotTexts::text('phone_not_own', $loc));
            return;
        }

        $purchase = BotState::getPurchase($tgId);
        if ($purchase !== null && trim((string) ($purchase['product_id'] ?? '')) !== '') {
            $this->createBotOrder($tgId, $msg, $loc);
            return;
        }

        $clients = ClientAccounts::byPhone($phone);
        if ($clients === []) {
            Telegram::sendMessage($tgId, BotTexts::text('phone_not_found', $loc, ['phone' => $phone]), $this->contactKeyboard($loc));
            return;
        }
        $blocked = true;
        foreach ($clients as $c) {
            if (($c['active'] ?? true) !== false) {
                $blocked = false;
                break;
            }
        }
        if ($blocked) {
            Telegram::sendMessage($tgId, BotTexts::text('suspended', $loc));
            return;
        }

        $this->linkTelegramToClient($tgId, $clients[0], $loc);
    }

    /** @param array<string, mixed> $client */
    private function linkTelegramToClient(string $tgId, array $client, string $loc): void
    {
        ClientAccounts::linkTelegramToPhone($tgId, (string) ($client['phone'] ?? ''));
        $this->safeActivityLog('bot', 'act.bot_link', ['name' => $client['name']]);
        $clients = ClientAccounts::byTelegramId($tgId);
        $active = $this->resolveActiveClient($tgId, $clients);
        $prefix = BotTexts::text('linked_ok', $loc, ['name' => $client['name'] ?? '']) . "\n\n";
        if (count($clients) > 1) {
            $this->serverPicker($tgId, $clients, $loc, $prefix);
            return;
        }
        $this->clientMenu($tgId, $active, $loc, false, $prefix);
    }

    /** @param list<array<string, mixed>> $clients */
    private function resolveActiveClient(string $tgId, array $clients): ?array
    {
        if ($clients === []) {
            return null;
        }
        if (count($clients) === 1) {
            return $clients[0];
        }
        $sel = BotState::getSelectedClient($tgId);
        if ($sel !== null) {
            foreach ($clients as $c) {
                if (($c['id'] ?? '') === $sel) {
                    return $c;
                }
            }
        }
        return $clients[0];
    }

    /** @param list<array<string, mixed>> $clients */
    private function serverPicker(string $tgId, array $clients, string $loc, string $prefix = ''): void
    {
        $rows = [];
        foreach ($clients as $c) {
            $cid = (string) ($c['id'] ?? '');
            $label = ClientAccounts::labelFor($c, $loc);
            $sid = (int) ($c['server_id'] ?? 0);
            $exp = (string) ($c['expires'] ?? '');
            $rows[] = [[
                'text' => $label . ($sid > 0 ? ' #' . $sid : '') . ' · ' . $exp,
                'callback_data' => 'c:pick:' . $cid,
            ]];
        }
        Telegram::sendMessage(
            $tgId,
            $prefix . BotTexts::text('servers_pick', $loc, ['n' => (string) count($clients)]),
            ['inline_keyboard' => $rows]
        );
    }

    /** @param array<string, mixed> $msg */
    private function handleText(array $msg): void
    {
        $tgId = (string) ($msg['from']['id'] ?? '');
        $text = trim((string) ($msg['text'] ?? ''));
        $isAdmin = $tgId !== '' && $tgId === $this->adminId();

        if ($isAdmin) {
            $adminLoc = Locale::resolveBotLocale($tgId, null);
            if (str_starts_with($text, '/stats')) {
                $this->adminStats($tgId, $adminLoc);
                return;
            }
            if (str_starts_with($text, '/linked')) {
                $this->adminLinked($tgId, $adminLoc);
                return;
            }
            if (str_starts_with($text, '/servers')) {
                $this->adminServers($tgId, $adminLoc);
                return;
            }
            if (str_starts_with($text, '/whoami')) {
                $this->adminWhoami($tgId, $adminLoc);
                return;
            }
            if (str_starts_with($text, '/start') || str_starts_with($text, '/help')) {
                BotSetup::tryRegisterAdminScope(Config::get('TELEGRAM_BOT_TOKEN'), $tgId);
                Telegram::sendMessage($tgId, $this->adminHelpText($adminLoc), $this->adminReplyKeyboard($adminLoc));
                return;
            }
            $adminBtn = BotTexts::adminActionFromLabel($text);
            if ($adminBtn !== null) {
                match ($adminBtn) {
                    'stats' => $this->adminStats($tgId, $adminLoc),
                    'linked' => $this->adminLinked($tgId, $adminLoc),
                    'servers' => $this->adminServers($tgId, $adminLoc),
                    'whoami' => $this->adminWhoami($tgId, $adminLoc),
                    'help' => Telegram::sendMessage($tgId, $this->adminHelpText($adminLoc), $this->adminReplyKeyboard($adminLoc)),
                    default => null,
                };
                return;
            }
        }

        $clients = Database::listClientsByTelegramId($tgId);
        $client = $this->resolveActiveClient($tgId, $clients);
        $loc = Locale::resolveBotLocale($tgId, $client);

        if (preg_match('#^/buy#', $text)) {
            $this->startPurchase($tgId, $loc);
            return;
        }

        if (!$client) {
            if (preg_match('#^/(start|help|language|server)#', $text)) {
                $this->promptContact($tgId, $loc);
                return;
            }
            Telegram::sendMessage($tgId, BotTexts::text('unregistered', $loc, ['id' => $tgId]), $this->contactKeyboard($loc));
            $this->offerPurchaseButton($tgId, $loc);
            return;
        }

        if (($client['active'] ?? true) === false) {
            Telegram::sendMessage($tgId, BotTexts::text('suspended', $loc));
            return;
        }

        $expired = Database::isExpired($client);
        if ($expired && !preg_match('#^/start#', $text)) {
            $action = BotTexts::actionFromLabel($text);
            $allowPicker = count($clients) > 1 && (
                $action === 'info' || $action === 'serverinfo' || preg_match('#^/server#', $text)
            );
            if ($action !== 'renew' && $action !== 'sub' && !preg_match('#^/help#', $text) && !$allowPicker) {
                Telegram::sendMessage($tgId, BotTexts::text('expired', $loc, ['expires' => $client['expires'] ?? '']), $this->safeClientKeyboard($loc));
                return;
            }
        }

        if (preg_match('#^/language#', $text) || BotTexts::actionFromLabel($text) === 'language') {
            if (!BotTexts::langAllowed()) {
                Telegram::sendMessage($tgId, BotTexts::text('lang_disabled', $loc), $this->safeClientKeyboard($loc));
                return;
            }
            try {
                $this->langPicker($tgId, $loc);
            } catch (\Throwable $e) {
                error_log('langPicker: ' . $e->getMessage());
                Telegram::sendMessage($tgId, '✖ ' . $e->getMessage(), $this->safeClientKeyboard($loc));
            }
            return;
        }

        if (preg_match('#^/help#', $text)) {
            Telegram::sendMessage($tgId, $this->clientHelpText($loc), $this->safeClientKeyboard($loc));
            return;
        }

        if (preg_match('#^/(start|server)#', $text)) {
            if (count($clients) > 1) {
                $this->serverPicker($tgId, $clients, $loc);
                return;
            }
            $this->clientMenu($tgId, $client, $loc);
            return;
        }

        $btnAction = BotTexts::actionFromLabel($text);
        if ($btnAction !== null) {
            $this->runClientAction($tgId, 0, '', $client, $loc, 'c:' . $btnAction, $clients);
            return;
        }

        $this->clientMenu($tgId, $client, $loc);
    }

    private function clientHelpText(string $loc): string
    {
        $lines = ['<b>' . BotTexts::text('help_title', $loc) . '</b>', ''];
        foreach (BotCommands::clientCommands() as $c) {
            $lines[] = '<code>/' . $c['command'] . '</code> — ' . BotCommands::description($c['command'], $loc);
        }
        $lines[] = '';
        $lines[] = BotTexts::text('help_inline', $loc);
        return implode("\n", $lines);
    }

    private function adminHelpText(string $loc): string
    {
        $lines = ['<b>👋 Admin</b>', ''];
        foreach (array_merge(BotCommands::clientCommands(), BotCommands::adminCommands()) as $c) {
            $lines[] = '<code>/' . $c['command'] . '</code> — ' . BotCommands::description($c['command'], $loc);
        }
        $lines[] = '';
        $lines[] = BotTexts::text('help_inline', $loc);
        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $cb */
    private function handleCallback(array $cb): void
    {
        $data = (string) ($cb['data'] ?? '');
        $tgId = (string) ($cb['from']['id'] ?? '');
        $msgId = (int) ($cb['message']['message_id'] ?? 0);
        $cbId = (string) ($cb['id'] ?? '');
        $isAdmin = $tgId === $this->adminId();

        if (preg_match('#^lang:set:(en|ar)$#', $data, $m)) {
            $newLoc = $m[1];
            if (!BotTexts::langAllowed()) {
                $this->ackCallback($cbId, BotTexts::text('lang_disabled', $newLoc));
                return;
            }
            $this->ackCallback($cbId);
            $langLabel = $newLoc === 'ar' ? 'العربية' : 'English';
            $confirm = BotTexts::text('lang_set', $newLoc, ['lang' => $langLabel]);
            try {
                Locale::saveBotUserLocale($tgId, $newLoc);
            } catch (\Throwable $e) {
                error_log('saveBotUserLocale: ' . $e->getMessage());
            }
            $clients = Database::listClientsByTelegramId($tgId);
            $client = $this->resolveActiveClient($tgId, $clients);
            if ($client) {
                $this->clientMenu($tgId, $client, $newLoc, false, $confirm . "\n\n");
            } elseif ($isAdmin) {
                Telegram::sendMessage($tgId, $confirm, $this->adminReplyKeyboard($newLoc));
            } else {
                Telegram::sendMessage($tgId, $confirm, $this->safeClientKeyboard($newLoc));
            }
            return;
        }

        if ($data === 'lang:pick') {
            $loc = Locale::resolveBotLocale($tgId, Database::getClientByTelegramId($tgId));
            if (!BotTexts::langAllowed()) {
                $this->ackCallback($cbId, BotTexts::text('lang_disabled', $loc));
                return;
            }
            $this->ackCallback($cbId);
            try {
                $this->langPicker($tgId, $loc);
            } catch (\Throwable $e) {
                error_log('langPicker: ' . $e->getMessage());
                Telegram::sendMessage($tgId, '✖ ' . $e->getMessage(), $this->safeClientKeyboard($loc));
            }
            return;
        }

        if ($data === 'buy:list') {
            $loc = Locale::resolveBotLocale($tgId, Database::getClientByTelegramId($tgId));
            $this->ackCallback($cbId);
            $this->startPurchase($tgId, $loc);
            return;
        }

        if (preg_match('#^buy:p:([a-zA-Z0-9]+)$#', $data, $m)) {
            $loc = Locale::resolveBotLocale($tgId, Database::getClientByTelegramId($tgId));
            $this->ackCallback($cbId);
            $this->beginPurchase($tgId, $m[1], $loc);
            return;
        }

        if ($isAdmin && preg_match('#^act:(on|off|reboot|pass):(\d+)$#', $data, $m)) {
            $this->adminAction($tgId, $cbId, $m[1], (int) $m[2]);
            return;
        }

        if ($isAdmin && preg_match('#^act:panel:(\d+)$#', $data, $m)) {
            $this->adminServerPanel($tgId, $cbId, (int) $m[1]);
            return;
        }

        if (preg_match('#^c:pick:([a-z0-9]+)$#', $data, $m)) {
            $clients = Database::listClientsByTelegramId($tgId);
            $loc = Locale::resolveBotLocale($tgId, $clients[0] ?? null);
            foreach ($clients as $c) {
                if (($c['id'] ?? '') === $m[1]) {
                    BotState::setSelectedClient($tgId, $m[1]);
                    $this->ackCallback($cbId);
                    $this->clientMenu($tgId, $c, $loc);
                    return;
                }
            }
            $this->ackCallback($cbId, '—');
            return;
        }

        $clients = Database::listClientsByTelegramId($tgId);
        $client = $this->resolveActiveClient($tgId, $clients);
        if (!$client) {
            Telegram::answerCallbackQuery($cbId, '—');
            return;
        }
        $loc = Locale::resolveBotLocale($tgId, $client);

        if (($client['active'] ?? true) === false) {
            $this->ackCallback($cbId, BotTexts::text('suspended', $loc));
            return;
        }
        if (Database::isExpired($client) && !preg_match('#^c:(renew|sub|pick|list)#', $data)) {
            $this->ackCallback($cbId, BotTexts::text('expired', $loc, ['expires' => $client['expires'] ?? '']));
            return;
        }

        if ($data === 'c:list') {
            $this->ackCallback($cbId);
            $this->serverPicker($tgId, $clients, $loc);
            return;
        }

        if (str_starts_with($data, 'c:')) {
            $this->runClientAction($tgId, $msgId, $cbId, $client, $loc, $data, $clients);
            return;
        }
        $this->ackCallback($cbId, '—');
    }

    /**
     * @param array<string, mixed> $client
     * @param list<array<string, mixed>> $allClients
     */
    private function runClientAction(string $tgId, int $msgId, string $cbId, array $client, string $loc, string $data, array $allClients = []): void
    {
        if ($allClients === []) {
            $allClients = Database::listClientsByTelegramId($tgId);
        }
        $sid = (int) ($client['server_id'] ?? 0);
        $acc = ProviderManager::accountIdForClient($client);
        $sp = ProviderManager::forClient($client);

        if ($data === 'c:sub') {
            $this->ackCallback($cbId);
            if (count($allClients) > 1) {
                $this->sendAllSubscriptions($tgId, $allClients, $loc);
            } else {
                $this->sendSubscription($tgId, $client, $loc);
            }
            return;
        }
        if ($data === 'c:renew') {
            $this->ackCallback($cbId);
            $this->sendRenew($tgId, $client, $loc);
            return;
        }
        if ($data === 'c:info' || $data === 'c:serverinfo') {
            $this->ackCallback($cbId);
            if (count($allClients) > 1 && $data === 'c:info') {
                $this->serverPicker($tgId, $allClients, $loc);
                return;
            }
            $this->clientMenu($tgId, $client, $loc, $data === 'c:serverinfo');
            return;
        }
        if ($data === 'c:rebuild') {
            $this->startRebuild($tgId, $cbId, $client, $loc, $acc);
            return;
        }
        if (preg_match('#^c:rbimg:(\d+)$#', $data, $m)) {
            BotState::setRebuild($tgId, [
                'client_id' => $client['id'],
                'image' => (int) $m[1],
                'sid' => $sid,
                'acc' => $acc,
            ]);
            if ($msgId > 0) {
                Telegram::editMessageText($tgId, $msgId, BotTexts::text('rebuild_warning', $loc), [
                    'inline_keyboard' => [[
                        ['text' => '✅ ' . I18n::t('admin.clients.rebuild_btn', $loc), 'callback_data' => 'c:rbok'],
                        ['text' => '❌', 'callback_data' => 'c:info'],
                    ]],
                ]);
            } else {
                Telegram::sendMessage($tgId, BotTexts::text('rebuild_warning', $loc), [
                    'inline_keyboard' => [[
                        ['text' => '✅ ' . I18n::t('admin.clients.rebuild_btn', $loc), 'callback_data' => 'c:rbok'],
                        ['text' => '❌', 'callback_data' => 'c:info'],
                    ]],
                ]);
            }
            if ($cbId !== '') {
                Telegram::answerCallbackQuery($cbId);
            }
            return;
        }
        if ($data === 'c:rbok') {
            $this->confirmRebuild($tgId, $cbId, $client, $loc);
            return;
        }

        if ($sid <= 0) {
            if ($cbId !== '') {
                $this->ackCallback($cbId, BotTexts::text('no_server', $loc));
            } else {
                Telegram::sendMessage($tgId, BotTexts::text('no_server', $loc), $this->safeClientKeyboard($loc));
            }
            return;
        }

        if ($data === 'c:pass') {
            $this->ackCallback($cbId);
            try {
                $pw = $sp->resetPassword($sid, $acc);
                Telegram::sendMessage(
                    $tgId,
                    '🔑 <code>' . htmlspecialchars($pw, ENT_QUOTES, 'UTF-8') . '</code>',
                    $this->safeClientKeyboard($loc)
                );
                \App\Services\AdminNotify::passwordRequest($client, 'bot');
                $this->safeActivityLog('bot', 'act.bot_password', ['name' => $client['name']]);
            } catch (\Throwable $e) {
                error_log('Bot pass action: ' . $e->getMessage());
                Telegram::sendMessage($tgId, '✖ ' . $sp->explain($e), $this->safeClientKeyboard($loc));
            }
            return;
        }

        if (in_array($data, ['c:on', 'c:off', 'c:reboot'], true)) {
            $this->ackCallback($cbId);
            try {
                match ($data) {
                    'c:on' => $sp->powerOn($sid, $acc),
                    'c:off' => $sp->powerOff($sid, $acc),
                    'c:reboot' => $sp->reboot($sid, $acc),
                    default => null,
                };
                $this->safeActivityLog('bot', 'act.bot_power_' . substr($data, 2), [
                    'name' => $client['name'],
                    'server' => (string) $sid,
                ]);
                sleep(1);
                $this->clientMenu($tgId, Database::getClient($client['id']) ?? $client, $loc);
            } catch (\Throwable $e) {
                error_log('Bot power action: ' . $e->getMessage());
                Telegram::sendMessage($tgId, '✖ ' . $sp->explain($e), $this->safeClientKeyboard($loc));
            }
            return;
        }

        $this->ackCallback($cbId, BotTexts::text('action_unknown', $loc));
    }

    /** @param array<string, mixed> $client */
    private function startRebuild(string $tgId, string $cbId, array $client, string $loc, string $acc): void
    {
        $sid = (int) ($client['server_id'] ?? 0);
        if ($sid <= 0) {
            $this->ackCallback($cbId, BotTexts::text('no_server', $loc));
            return;
        }
        $this->ackCallback($cbId);
        $sp = ProviderManager::forClient($client);
        try {
            $images = $sp->listImages($acc);
            $allowed = BotTexts::allowedOsImages();
            $rows = [];
            $row = [];
            foreach ($images as $img) {
                $iid = (string) ($img['id'] ?? '');
                $name = (string) ($img['name'] ?? '');
                if ($allowed !== [] && !in_array($name, $allowed, true) && !in_array($iid, $allowed, true)) {
                    continue;
                }
                $row[] = ['text' => $name, 'callback_data' => 'c:rbimg:' . $iid];
                if (count($row) === 2) {
                    $rows[] = $row;
                    $row = [];
                }
            }
            if ($row !== []) {
                $rows[] = $row;
            }
            if ($rows === []) {
                $hint = BotTexts::text('no_os_images', $loc);
                Telegram::sendMessage($tgId, '⚠️ ' . $hint, $this->safeClientKeyboard($loc));
                return;
            }
            Telegram::sendMessage($tgId, BotTexts::text('rebuild_warning', $loc), ['inline_keyboard' => $rows]);
        } catch (\Throwable $e) {
            error_log('Bot rebuild: ' . $e->getMessage());
            Telegram::sendMessage($tgId, '✖ ' . ProviderManager::forClient($client)->explain($e), $this->safeClientKeyboard($loc));
        }
    }

    /** @param array<string, mixed> $client */
    private function confirmRebuild(string $tgId, string $cbId, array $client, string $loc): void
    {
        $state = BotState::getRebuild($tgId);
        if (!$state || ($state['client_id'] ?? '') !== ($client['id'] ?? '')) {
            $this->ackCallback($cbId, '—');
            return;
        }
        $this->ackCallback($cbId);
        $sp = ProviderManager::forClient($client);
        try {
            $pw = $sp->rebuild((int) $state['sid'], (int) $state['image'], (string) ($state['acc'] ?? ''));
            BotState::clearRebuild($tgId);
            Telegram::sendMessage(
                $tgId,
                I18n::t('admin.clients.rebuild_ok_sub', $loc, ['name' => $client['name']])
                    . "\n\n🔑 <code>" . htmlspecialchars($pw, ENT_QUOTES, 'UTF-8') . '</code>',
                $this->safeClientKeyboard($loc)
            );
            \App\Services\AdminNotify::rebuildRequest($client, 'bot', (string) ($state['image_name'] ?? ''));
            $this->safeActivityLog('bot', 'act.bot_rebuild', ['name' => $client['name']]);
        } catch (\Throwable $e) {
            error_log('Bot rebuild confirm: ' . $e->getMessage());
            Telegram::sendMessage($tgId, '✖ ' . ProviderManager::forClient($client)->explain($e), $this->safeClientKeyboard($loc));
        }
    }

    /** @param list<array<string, mixed>> $clients */
    private function sendAllSubscriptions(string $tgId, array $clients, string $loc): void
    {
        $lines = ['<b>' . BotTexts::text('subscriptions_all', $loc) . '</b>', ''];
        foreach ($clients as $c) {
            $label = ClientAccounts::labelFor($c, $loc);
            $lines[] = '• <b>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</b>';
            $lines[] = $this->subLine($c, $loc);
            $lines[] = '';
        }
        Telegram::sendMessage($tgId, trim(implode("\n", $lines)), $this->safeClientKeyboard($loc));
    }

    /** @param array<string, mixed> $client */
    private function sendSubscription(string $tgId, array $client, string $loc): void
    {
        $line = $this->subLine($client, $loc);
        Telegram::sendMessage($tgId, BotTexts::text('sub_view', $loc, [
            'name' => $client['name'] ?? '',
            'phone' => $client['phone'] ?? '',
            'sub_line' => $line,
        ]), $this->safeClientKeyboard($loc));
    }

    /** @param array<string, mixed> $client */
    private function sendRenew(string $tgId, array $client, string $loc): void
    {
        $url = Renewal::absoluteCheckoutUrl($client);
        if ($url === null) {
            Telegram::sendMessage($tgId, BotTexts::text('store_url_missing', $loc), $this->safeClientKeyboard($loc));
            return;
        }
        Telegram::sendMessage(
            $tgId,
            BotTexts::text('renew_store', $loc, ['phone' => $client['phone'] ?? '']) . "\n" . $url,
            $this->safeClientKeyboard($loc)
        );
    }

    /** @param array<string, mixed> $client */
    private function subLine(array $client, string $loc): string
    {
        if (empty($client['expires'])) {
            return BotTexts::text('sub_line_none', $loc);
        }
        if (Database::isExpired($client)) {
            return BotTexts::text('sub_line_expired', $loc, ['expires' => $client['expires']]);
        }
        $days = Database::daysLeft($client);
        if ($days === 0) {
            return BotTexts::text('sub_line_today', $loc, ['expires' => $client['expires']]);
        }
        return BotTexts::text('sub_line_active', $loc, ['expires' => $client['expires'], 'days' => (string) ($days ?? '?')]);
    }

    /** @param array<string, mixed> $client */
    private function clientMenu(string $tgId, array $client, string $loc, bool $detailed = false, string $prefix = ''): void
    {
        $sid = (int) ($client['server_id'] ?? 0);
        if ($sid <= 0) {
            Telegram::sendMessage($tgId, $prefix . BotTexts::text('no_server', $loc) . "\n\n" . $this->subLine($client, $loc), $this->safeClientKeyboard($loc));
            return;
        }
        try {
            $sp = ProviderManager::forClient($client);
            $s = $sp->getServer($sid, ProviderManager::accountIdForClient($client));
            $status = BotTexts::status((string) ($s['status'] ?? ''), $loc);
            $ip = $s['public_net']['ipv4']['ip'] ?? '—';
            $caption = $prefix . "🖥️ <b>" . ($s['name'] ?? '') . "</b>\nIPv4: <code>$ip</code>\n$status\n\n" . $this->subLine($client, $loc);
            $clients = Database::listClientsByTelegramId($tgId);
            if ($detailed) {
                $type = $s['server_type']['name'] ?? '—';
                $dc = Datacenter::countryLabel($s, $loc);
                $caption .= "\n\n📦 " . BotTexts::text('server_type', $loc) . ": <code>$type</code>"
                    . "\n🌍 " . BotTexts::text('server_country', $loc) . ": <code>$dc</code>"
                    . "\n📱 " . BotTexts::text('server_phone', $loc) . ": <code>" . ($client['phone'] ?? '') . '</code>';
            }
            if (count($clients) > 1) {
                Telegram::sendMessage($tgId, $caption, $this->safeClientKeyboard($loc));
                $switch = '↪️ ' . BotTexts::text('servers_switch', $loc);
                Telegram::sendMessage($tgId, $switch, [
                    'inline_keyboard' => [[
                        ['text' => $switch, 'callback_data' => 'c:list'],
                    ]],
                ]);
            } else {
                Telegram::sendMessage($tgId, $caption, $this->safeClientKeyboard($loc));
            }
        } catch (\Throwable $e) {
            Telegram::sendMessage($tgId, '✖ ' . ProviderManager::forClient($client)->explain($e), $this->safeClientKeyboard($loc));
        }
    }

    private function ackCallback(string $cbId, string $text = ''): void
    {
        if ($cbId === '') {
            return;
        }
        try {
            Telegram::answerCallbackQuery($cbId, Str::limit($text, 180));
        } catch (\Throwable $e) {
            error_log('answerCallbackQuery: ' . $e->getMessage());
        }
    }

    /** @param array<string, string> $vars */
    private function safeActivityLog(string $type, string $key, array $vars = []): void
    {
        try {
            ActivityLog::log($type, $key, $vars);
        } catch (\Throwable $e) {
            error_log('ActivityLog: ' . $e->getMessage());
        }
    }

    private function adminAction(string $tgId, string $cbId, string $act, int $sid): void
    {
        $sp = ProviderManager::active();
        try {
            match ($act) {
                'on' => $sp->powerOn($sid),
                'off' => $sp->powerOff($sid),
                'reboot' => $sp->reboot($sid),
                'pass' => Telegram::sendMessage($tgId, '🔑 <code>' . $sp->resetPassword($sid) . '</code>'),
                default => null,
            };
            Telegram::answerCallbackQuery($cbId, '✓');
        } catch (\Throwable $e) {
            Telegram::answerCallbackQuery($cbId, $sp->explain($e));
        }
    }

    private function adminServerPanel(string $tgId, string $cbId, int $sid): void
    {
        $sp = ProviderManager::active();
        try {
            $s = $sp->getServer($sid);
            $ip = $s['public_net']['ipv4']['ip'] ?? '—';
            $text = '<b>' . ($s['name'] ?? '') . "</b>\n#$sid · <code>$ip</code>\n" . ($s['status'] ?? '');
            Telegram::sendMessage($tgId, $text, [
                'inline_keyboard' => [[
                    ['text' => '▶️', 'callback_data' => 'act:on:' . $sid],
                    ['text' => '⏹️', 'callback_data' => 'act:off:' . $sid],
                    ['text' => '🔄', 'callback_data' => 'act:reboot:' . $sid],
                    ['text' => '🔑', 'callback_data' => 'act:pass:' . $sid],
                ]],
            ]);
            Telegram::answerCallbackQuery($cbId);
        } catch (\Throwable $e) {
            Telegram::answerCallbackQuery($cbId, $sp->explain($e));
        }
    }

    private function adminStats(string $tgId, string $loc): void
    {
        $st = Database::getSettings();
        $cur = (string) ($st['currency'] ?? 'KWD');
        $clients = Database::listClients();
        $total = count($clients);
        $linked = 0;
        $withServer = 0;
        $active = 0;
        $expired = 0;
        $expiring = 0;
        foreach ($clients as $c) {
            if (!empty($c['telegram_id'])) {
                $linked++;
            }
            if ((int) ($c['server_id'] ?? 0) > 0) {
                $withServer++;
            }
            if (($c['active'] ?? true) === false) {
                continue;
            }
            if (Database::isExpired($c)) {
                $expired++;
            } else {
                $active++;
                $days = Database::daysLeft($c);
                if ($days !== null && $days >= 0 && $days <= 7) {
                    $expiring++;
                }
            }
        }
        $unpaid = 0;
        foreach (Database::listInvoices() as $inv) {
            if (empty($inv['paid'])) {
                $unpaid++;
            }
        }
        $lowStock = count(StockAlert::lowStockPlans());
        $report = Finance::buildReport();
        $monthRev = number_format((float) $report['thisMonth'], 2);
        $text = '<b>' . BotTexts::text('admin_stats_title', $loc) . "</b>\n\n"
            . '👥 ' . BotTexts::text('admin_stats_total', $loc, ['n' => $total]) . "\n"
            . '✅ ' . BotTexts::text('admin_stats_active', $loc, ['n' => $active]) . "\n"
            . '⛔ ' . BotTexts::text('admin_stats_expired', $loc, ['n' => $expired]) . "\n"
            . '⏰ ' . BotTexts::text('admin_stats_expiring', $loc, ['n' => $expiring]) . "\n"
            . '🤖 ' . BotTexts::text('admin_stats_linked', $loc, ['n' => $linked]) . "\n"
            . '🖥️ ' . BotTexts::text('admin_stats_servers', $loc, ['n' => $withServer]) . "\n"
            . '🧾 ' . BotTexts::text('admin_stats_unpaid', $loc, ['n' => $unpaid]) . "\n"
            . '📦 ' . BotTexts::text('admin_stats_low_stock', $loc, ['n' => $lowStock]) . "\n"
            . '💰 ' . BotTexts::text('admin_stats_month', $loc, ['amount' => $monthRev, 'currency' => $cur]);
        Telegram::sendMessage($tgId, $text, $this->adminReplyKeyboard($loc));
    }

    private function adminLinked(string $tgId, string $loc): void
    {
        $clients = Database::listClients();
        $total = count($clients);
        $linked = 0;
        foreach ($clients as $c) {
            if (!empty($c['telegram_id'])) {
                $linked++;
            }
        }
        $unlinked = $total - $linked;
        $pct = $total > 0 ? (int) round(($linked / $total) * 100) : 0;
        $text = '<b>' . BotTexts::text('admin_linked_title', $loc) . "</b>\n\n"
            . '✅ ' . BotTexts::text('admin_linked_yes', $loc, ['n' => $linked]) . "\n"
            . '❌ ' . BotTexts::text('admin_linked_no', $loc, ['n' => $unlinked]) . "\n"
            . '📊 ' . BotTexts::text('admin_linked_total', $loc, ['n' => $total, 'pct' => $pct]);
        Telegram::sendMessage($tgId, $text, $this->adminReplyKeyboard($loc));
    }

    private function adminWhoami(string $tgId, string $loc): void
    {
        Telegram::sendMessage($tgId, 'ID: <code>' . $tgId . '</code>', $this->adminReplyKeyboard($loc));
    }

    private function adminServers(string $tgId, string $loc): void
    {
        $sp = ProviderManager::active();
        try {
            $servers = $sp->listServers();
            $rows = [];
            foreach ($servers as $s) {
                $sid = (int) ($s['id'] ?? 0);
                $rows[] = [[
                    'text' => ($s['name'] ?? '') . ' [' . ($s['status'] ?? '') . ']',
                    'callback_data' => 'act:panel:' . $sid,
                ]];
            }
            Telegram::sendMessage($tgId, '📋 ' . BotTexts::text('admin_servers_list', $loc) . ' (' . count($servers) . ')', ['inline_keyboard' => $rows]);
        } catch (\Throwable $e) {
            Telegram::sendMessage($tgId, '✖ ' . $sp->explain($e), $this->adminReplyKeyboard($loc));
        }
    }

    /** @return array<string, mixed>|null */
    private function safeClientKeyboard(string $loc): ?array
    {
        return $this->clientReplyKeyboard($loc);
    }

    /** @return array<string, mixed>|null */
    private function clientReplyKeyboard(string $loc): ?array
    {
        $pending = [];
        foreach (BotTexts::enabledButtonKeys() as $key) {
            $lbl = BotTexts::btn($key, $loc);
            if ($lbl === '') {
                continue;
            }
            $pending[] = ['text' => $lbl];
        }
        $rows = [];
        for ($i = 0, $n = count($pending); $i < $n; $i += 2) {
            $rows[] = array_slice($pending, $i, 2);
        }
        if (BotTexts::langAllowed()) {
            $lang = ['text' => BotTexts::btn('language', $loc)];
            if ($rows !== [] && count($rows[count($rows) - 1]) === 1) {
                $rows[count($rows) - 1][] = $lang;
            } else {
                $rows[] = [$lang];
            }
        }
        if ($rows === []) {
            return null;
        }
        return [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function adminReplyKeyboard(string $loc): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => BotTexts::adminBtn('stats', $loc)],
                    ['text' => BotTexts::adminBtn('linked', $loc)],
                ],
                [
                    ['text' => BotTexts::adminBtn('servers', $loc)],
                    ['text' => BotTexts::adminBtn('whoami', $loc)],
                ],
                [
                    ['text' => BotTexts::adminBtn('help', $loc)],
                ],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function contactKeyboard(string $loc): array
    {
        return [
            'keyboard' => [[['text' => BotTexts::text('share_contact', $loc), 'request_contact' => true]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    private function promptContact(string $tgId, string $loc): void
    {
        Telegram::sendMessage($tgId, BotTexts::text('unregistered', $loc, ['id' => $tgId]), $this->contactKeyboard($loc));
        $this->offerPurchaseButton($tgId, $loc);
    }

    /** Inline button that opens the in-bot store (only when something is purchasable). */
    private function offerPurchaseButton(string $tgId, string $loc): void
    {
        if (BotStore::purchasableProducts() === []) {
            return;
        }
        Telegram::sendMessage($tgId, BotTexts::text('buy_offer', $loc), [
            'inline_keyboard' => [[['text' => BotTexts::text('buy_btn', $loc), 'callback_data' => 'buy:list']]],
        ]);
    }

    /** Step 1: list plans available for purchase as inline buttons. */
    private function startPurchase(string $tgId, string $loc): void
    {
        $products = BotStore::purchasableProducts();
        if ($products === []) {
            Telegram::sendMessage($tgId, BotTexts::text('buy_none', $loc));
            return;
        }
        $cur = (string) (Database::getSettings()['currency'] ?? 'USD');
        $rows = [];
        foreach ($products as $p) {
            $rows[] = [[
                'text' => BotStore::productButtonLabel($p, $cur, $loc),
                'callback_data' => 'buy:p:' . $p['id'],
            ]];
        }
        Telegram::sendMessage($tgId, BotTexts::text('buy_intro', $loc), ['inline_keyboard' => $rows]);
    }

    /** Step 2: a plan was chosen — validate and ask for the buyer's phone number. */
    private function beginPurchase(string $tgId, string $productId, string $loc): void
    {
        $p = Database::getProduct($productId);
        if (!$p || ($p['active'] ?? true) === false) {
            Telegram::sendMessage($tgId, BotTexts::text('buy_product_gone', $loc));
            $this->startPurchase($tgId, $loc);
            return;
        }
        if (!GatewayManager::isActiveConfigured()) {
            Telegram::sendMessage($tgId, BotTexts::text('buy_gateway_off', $loc));
            return;
        }
        if (count(Database::availableStockForProduct((string) $p['id'])) === 0) {
            Telegram::sendMessage($tgId, BotTexts::text('buy_stock_empty', $loc));
            $this->startPurchase($tgId, $loc);
            return;
        }
        BotState::setPurchase($tgId, ['product_id' => (string) $p['id']]);
        Telegram::sendMessage(
            $tgId,
            BotTexts::text('buy_share_phone', $loc, ['plan' => $p['name']]),
            $this->purchaseContactKeyboard($loc)
        );
    }

    /** Step 3: phone shared — create the order + invoice and send a payment button. */
    private function createBotOrder(string $tgId, array $msg, string $loc): void
    {
        $purchase = BotState::getPurchase($tgId) ?? [];
        $productId = trim((string) ($purchase['product_id'] ?? ''));
        $phone = (string) ($msg['contact']['phone_number'] ?? '');
        $name = trim(($msg['from']['first_name'] ?? '') . ' ' . ($msg['from']['last_name'] ?? ''));
        if ($name === '') {
            $name = 'Telegram buyer';
        }

        $p = $productId !== '' ? Database::getProduct($productId) : null;
        if (!$p || ($p['active'] ?? true) === false) {
            BotState::clearPurchase($tgId);
            Telegram::sendMessage($tgId, BotTexts::text('buy_product_gone', $loc), $this->safeClientKeyboard($loc));
            return;
        }
        if (strlen(Database::normPhone($phone)) < 8) {
            Telegram::sendMessage($tgId, BotTexts::text('buy_phone_invalid', $loc), $this->purchaseContactKeyboard($loc));
            return;
        }
        if (!GatewayManager::isActiveConfigured()) {
            BotState::clearPurchase($tgId);
            Telegram::sendMessage($tgId, BotTexts::text('buy_gateway_off', $loc), $this->safeClientKeyboard($loc));
            return;
        }
        if (count(Database::availableStockForProduct((string) $p['id'])) === 0) {
            BotState::clearPurchase($tgId);
            Telegram::sendMessage($tgId, BotTexts::text('buy_stock_empty', $loc), $this->safeClientKeyboard($loc));
            return;
        }
        $gw = GatewayManager::active();
        try {
            $result = $gw->createCheckout(new CheckoutRequest(
                Request::capture(),
                (float) $p['price'],
                $p['name'] . ' — ' . $phone,
                '/success',
                '/checkout/' . $p['id'] . '?cancel=1',
            ));
            Database::addInvoice([
                'client_id' => '',
                'desc' => $p['name'],
                'amount' => $p['price'],
                'months' => $p['months'],
                'source' => 'bot',
                'buyer_name' => $name,
                'phone' => $phone,
                'product_id' => $p['id'],
                'paypal_order_id' => $result->gatewayRef,
            ]);
            BotStore::rememberTelegramForPhone($phone, $tgId);
            $this->safeActivityLog('store', 'act.store_order_new', [
                'name' => $name,
                'phone' => $phone,
                'plan' => $p['name'],
            ]);
            BotState::clearPurchase($tgId);
            $cur = (string) (Database::getSettings()['currency'] ?? 'USD');
            $price = BotStore::formatPrice((float) $p['price'], $cur);
            Telegram::sendMessage(
                $tgId,
                BotTexts::text('buy_pay', $loc, ['plan' => $p['name'], 'price' => $price]),
                ['inline_keyboard' => [[['text' => BotTexts::text('buy_pay_btn', $loc), 'url' => $result->paymentUrl]]]]
            );
        } catch (\Throwable $e) {
            error_log('Bot order: ' . $e->getMessage());
            Telegram::sendMessage($tgId, '✖ ' . $gw->name() . ': ' . $gw->explain($e), $this->safeClientKeyboard($loc));
        }
    }

    /** @return array<string, mixed> */
    private function purchaseContactKeyboard(string $loc): array
    {
        return [
            'keyboard' => [[['text' => BotTexts::text('buy_share_phone_btn', $loc), 'request_contact' => true]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    private function langPicker(string $tgId, string $loc): void
    {
        $current = $loc === 'ar' ? 'العربية' : 'English';
        Telegram::sendMessage(
            $tgId,
            BotTexts::text('lang_title', $loc) . "\n" . BotTexts::text('lang_current', $loc, ['lang' => $current]),
            [
                'inline_keyboard' => [[
                    ['text' => '🇬🇧 English', 'callback_data' => 'lang:set:en'],
                    ['text' => '🇸🇦 العربية', 'callback_data' => 'lang:set:ar'],
                ]],
            ]
        );
    }
}
