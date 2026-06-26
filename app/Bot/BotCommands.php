<?php
declare(strict_types=1);

namespace App\Bot;

use App\Database\Database;
use App\Helpers\I18n;
use App\Helpers\Str;
use App\Services\Config;
use App\Services\Telegram;

final class BotCommands
{
    /** Client commands shown in Telegram menu (/) */
    /** @return list<array{command: string, desc_key: string}> */
    public static function clientCommands(): array
    {
        return [
            ['command' => 'start', 'desc_key' => 'bot.cmd.start'],
            ['command' => 'server', 'desc_key' => 'bot.cmd.server'],
            ['command' => 'help', 'desc_key' => 'bot.cmd.help'],
            ['command' => 'language', 'desc_key' => 'bot.cmd.language'],
        ];
    }

    /** Admin-only commands (documented in panel; admin chat scope) */
    /** @return list<array{command: string, desc_key: string}> */
    public static function adminCommands(): array
    {
        return [
            ['command' => 'stats', 'desc_key' => 'bot.cmd.stats'],
            ['command' => 'linked', 'desc_key' => 'bot.cmd.linked'],
            ['command' => 'servers', 'desc_key' => 'bot.cmd.servers'],
            ['command' => 'whoami', 'desc_key' => 'bot.cmd.whoami'],
        ];
    }

    /** @return list<array{command: string, desc_key: string}> */
    public static function allCommands(): array
    {
        return array_merge(self::clientCommands(), self::adminCommands());
    }

    public static function isAdminOnlyCommand(string $command): bool
    {
        return in_array($command, ['stats', 'linked', 'servers', 'whoami'], true);
    }

    public static function defaultDescription(string $command, string $loc): string
    {
        $key = 'bot.cmd.' . $command;
        $text = I18n::t($key, $loc);
        if ($text === $key) {
            $text = I18n::t($key, 'en');
        }
        return $text;
    }

    public static function description(string $command, string $loc): string
    {
        $st = Database::getSettings();
        $custom = trim((string) ($st['bot_commands'][$command][$loc] ?? $st['bot_commands'][$command]['en'] ?? ''));
        if ($custom !== '') {
            return Str::limit($custom, 256);
        }
        return Str::limit(self::defaultDescription($command, $loc), 256);
    }

    /** @return list<array{command: string, description: string}> */
    public static function forLocale(string $locale): array
    {
        $out = [];
        foreach (self::clientCommands() as $c) {
            $out[] = [
                'command' => $c['command'],
                'description' => self::description($c['command'], $locale),
            ];
        }
        return $out;
    }

    public static function register(string $token): void
    {
        foreach (['en', 'ar'] as $lang) {
            Telegram::setMyCommands($token, self::forLocale($lang), $lang);
        }
    }

    public static function registerAdminScope(string $token, string $adminChatId): void
    {
        $scope = ['type' => 'chat', 'chat_id' => (int) $adminChatId];
        foreach (['en', 'ar'] as $lang) {
            $list = [];
            foreach (self::allCommands() as $c) {
                $list[] = [
                    'command' => $c['command'],
                    'description' => self::description($c['command'], $lang),
                ];
            }
            Telegram::setMyCommands($token, $list, $lang, $scope);
        }
    }

    /** Push current command descriptions to Telegram (public + admin scope). */
    public static function sync(string $token): void
    {
        self::register($token);
        $adminId = trim(explode(',', Config::get('ADMIN_IDS'))[0] ?? '');
        if ($adminId !== '' && ctype_digit($adminId)) {
            try {
                self::registerAdminScope($token, $adminId);
            } catch (\Throwable) {
            }
        }
    }

    /** @param array<string, array<string, string>> $botCommands */
    public static function commandsFormHtml(string $loc, array $botCommands): string
    {
        $e = \App\Helpers\Html::esc(...);
        $form = '<form method="post" action="' . \App\Services\AdminPath::url('/telegram/commands') . '">'
            . '<table><tr><th>' . $e(I18n::t('admin.telegram.cmd_col', $loc)) . '</th><th>'
            . $e(I18n::t('admin.telegram.cmd_scope_col', $loc)) . '</th><th>EN</th><th>AR</th></tr>';
        foreach (self::allCommands() as $c) {
            $cmd = $c['command'];
            $scope = self::isAdminOnlyCommand($cmd)
                ? I18n::t('admin.telegram.cmd_scope_admin', $loc)
                : I18n::t('admin.telegram.cmd_scope_client', $loc);
            $defEn = self::defaultDescription($cmd, 'en');
            $defAr = self::defaultDescription($cmd, 'ar');
            $valEn = trim((string) ($botCommands[$cmd]['en'] ?? ''));
            $valAr = trim((string) ($botCommands[$cmd]['ar'] ?? ''));
            $form .= '<tr><td class="mono">/' . $e($cmd) . '</td><td>' . $e($scope) . '</td>'
                . '<td><input name="cmd_' . $e($cmd) . '_en" value="' . $e($valEn !== '' ? $valEn : $defEn) . '" maxlength="256"></td>'
                . '<td><input name="cmd_' . $e($cmd) . '_ar" value="' . $e($valAr !== '' ? $valAr : $defAr) . '" dir="rtl" maxlength="256"></td></tr>';
        }
        $form .= '</table><div class="row" style="margin-top:14px"><button class="btn">'
            . $e(I18n::t('admin.telegram.commands_save', $loc)) . '</button></div></form>'
            . '<form method="post" action="' . \App\Services\AdminPath::url('/telegram/commands-reset') . '" style="margin-top:10px" onsubmit="return confirm('
            . "'" . $e(I18n::t('admin.telegram.commands_reset_confirm', $loc)) . "'" . ')"><button class="btn gray">'
            . $e(I18n::t('admin.telegram.commands_reset', $loc)) . '</button></form>';
        return $form;
    }

    /** Inline keyboard buttons reference (read-only) */
    public static function adminInlineButtonsHtml(string $loc): string
    {
        $e = \App\Helpers\Html::esc(...);
        $keys = BotTexts::CLIENT_BTN_KEYS;
        $rows = '';
        foreach ($keys as $key) {
            if ($key === 'renew') {
                continue;
            }
            $lblKey = $key === 'language' ? 'bot.btn.language' : 'bot.btn.lbl.' . $key;
            $rows .= '<tr><td class="mono">' . $e($key) . '</td><td>'
                . $e(I18n::t($lblKey, $loc)) . '</td><td>'
                . $e(I18n::t('admin.telegram.cmd_scope_client', $loc)) . '</td></tr>';
        }
        return '<h3 style="font-size:15px;margin:18px 0 8px">' . $e(I18n::t('admin.telegram.inline_buttons_head', $loc)) . '</h3>'
            . '<table><tr><th>' . $e(I18n::t('admin.telegram.buttons_key', $loc)) . '</th><th>'
            . $e(I18n::t('admin.telegram.inline_buttons_label', $loc)) . '</th><th>'
            . $e(I18n::t('admin.telegram.cmd_scope_col', $loc)) . '</th></tr>' . $rows . '</table>';
    }
}
