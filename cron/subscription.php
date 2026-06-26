<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database\Database;
use App\Services\Config;
use App\Services\ProviderManager;
use App\Services\Telegram;

Database::init();

$key = Config::get('CRON_KEY');
if ($key === '') {
    http_response_code(403);
    exit('forbidden: CRON_KEY not configured');
}
if (PHP_SAPI !== 'cli' && !hash_equals($key, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}

$st = Database::getSettings();
$autoStop = ($st['auto_stop_expired'] ?? true) !== false;
$adminId = trim(explode(',', Config::get('ADMIN_IDS'))[0] ?? '');

foreach (Database::listClients() as $c) {
    if (($c['active'] ?? true) === false || empty($c['telegram_id'])) {
        continue;
    }
    $left = Database::daysLeft($c);
    if ($left === null) {
        continue;
    }
    $notices = $c['notices'] ?? [];
    $expires = $c['expires'] ?? '';

    if ($left <= 3 && $left >= 0) {
        $kind = $left <= 1 ? '1d' : '3d';
        $nk = "$expires:$kind";
        if (empty($notices[$nk])) {
            $msg = $left <= 1 ? "⚠️ Subscription ends soon ($expires)" : "⏰ $left days left ($expires)";
            try {
                Telegram::sendMessage((string) $c['telegram_id'], $msg);
                $notices[$nk] = date('c');
                Database::updateClient($c['id'], ['notices' => $notices]);
            } catch (\Throwable) {
            }
        }
    }

    if ($left < 0) {
        $nk = "$expires:expired";
        if (empty($notices[$nk])) {
            try {
                Telegram::sendMessage((string) $c['telegram_id'], "⛔ Subscription expired ($expires)");
                if ($adminId) {
                    Telegram::sendMessage($adminId, "📋 Expired: {$c['name']} ({$c['phone']})");
                }
                $notices[$nk] = date('c');
                Database::updateClient($c['id'], ['notices' => $notices]);
            } catch (\Throwable) {
            }
        }
        if ($autoStop && !empty($c['server_id']) && empty($notices["$expires:poweroff"])) {
            try {
                $sp = ProviderManager::forClient($c);
                $sp->powerOffForce((int) $c['server_id'], ProviderManager::accountIdForClient($c));
                $notices["$expires:poweroff"] = date('c');
                Database::updateClient($c['id'], ['notices' => $notices]);
            } catch (\Throwable) {
            }
        }
    }
}

echo "ok " . date('c');
