<?php
declare(strict_types=1);

namespace App\Bot;

use App\Services\Telegram;

/** Full Telegram bot registration — commands menu, descriptions, chat menu button. */
final class BotSetup
{
    /** @return string Non-empty warning when admin command scope could not be set */
    public static function apply(string $token, string $adminChatId = ''): string
    {
        BotCommands::register($token);
        $adminWarn = self::tryRegisterAdminScope($token, $adminChatId);
        Telegram::setChatMenuButtonCommands($token);
        self::syncProfile($token);
        return $adminWarn;
    }

    public static function syncProfile(string $token): void
    {
        foreach (['en', 'ar'] as $lang) {
            Telegram::setMyDescription($token, BotTexts::text('bot_desc', $lang), $lang);
            Telegram::setMyShortDescription($token, BotTexts::text('bot_short_desc', $lang), $lang);
        }
    }

    public static function tryRegisterAdminScope(string $token, string $adminChatId): string
    {
        if ($adminChatId === '' || !ctype_digit($adminChatId)) {
            return '';
        }
        try {
            BotCommands::registerAdminScope($token, $adminChatId);
            return '';
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains(strtolower($msg), 'chat not found')) {
                return 'admin_start_required';
            }
            return $msg;
        }
    }
}
