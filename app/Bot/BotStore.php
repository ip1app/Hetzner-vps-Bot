<?php
declare(strict_types=1);

namespace App\Bot;

use App\Database\Database;
use App\Database\Shared;
use App\Helpers\I18n;

/**
 * In-bot purchase helpers (Option A): list purchasable plans and remember which
 * Telegram user started a checkout so the new client can be auto-linked once the
 * payment is captured (StoreController::activateInvoice).
 */
final class BotStore
{
    /** Pending links older than this are pruned (payment links rarely live this long). */
    private const PENDING_TTL = 604800; // 7 days

    private static function pendingFile(): string
    {
        return DATA_DIR . '/bot_pending_links.json';
    }

    /** Active plans that currently have free stock to assign to a new buyer. */
    /** @return list<array<string, mixed>> */
    public static function purchasableProducts(): array
    {
        $out = [];
        foreach (Database::listProducts() as $p) {
            if (($p['active'] ?? true) === false) {
                continue;
            }
            if (count(Database::availableStockForProduct((string) ($p['id'] ?? ''))) <= 0) {
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }

    /** @param array<string, mixed> $product */
    public static function productButtonLabel(array $product, string $currency, string $loc): string
    {
        $name = trim((string) ($product['name'] ?? '')) ?: I18n::t('account.products.unnamed', $loc);
        $price = self::formatPrice((float) ($product['price'] ?? 0), $currency);
        return $name . ' — ' . $price;
    }

    public static function formatPrice(float $amount, string $currency): string
    {
        $value = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
        return ($value === '' ? '0' : $value) . ' ' . $currency;
    }

    /**
     * Remember that $tgId started checkout for $phone, so activation can link the
     * resulting client back to this Telegram account.
     */
    public static function rememberTelegramForPhone(string $phone, string $tgId): void
    {
        $key = Shared::corePhone($phone);
        $tgId = trim($tgId);
        if ($key === '' || $tgId === '') {
            return;
        }
        $all = self::loadPending();
        $all[$key] = ['tg' => $tgId, 't' => time()];
        self::savePending($all);
    }

    /** Consume (read + remove) the pending Telegram id for $phone. Returns '' if none. */
    public static function takeTelegramForPhone(string $phone): string
    {
        $key = Shared::corePhone($phone);
        if ($key === '') {
            return '';
        }
        $all = self::loadPending();
        $entry = $all[$key] ?? null;
        if (!is_array($entry)) {
            return '';
        }
        unset($all[$key]);
        self::savePending($all);
        return (string) ($entry['tg'] ?? '');
    }

    /** @return array<string, array<string, mixed>> */
    private static function loadPending(): array
    {
        $path = self::pendingFile();
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }
        $now = time();
        foreach ($data as $key => $entry) {
            if (!is_array($entry) || ($now - (int) ($entry['t'] ?? 0)) > self::PENDING_TTL) {
                unset($data[$key]);
            }
        }
        return $data;
    }

    /** @param array<string, array<string, mixed>> $data */
    private static function savePending(array $data): void
    {
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0755, true);
        }
        file_put_contents(self::pendingFile(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
