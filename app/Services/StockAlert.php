<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Helpers\Html;
use App\Helpers\I18n;
use App\Helpers\Url;

/** Low-stock detection, admin banners, and throttled Telegram alerts. */
final class StockAlert
{
    private const STATE_FILE = DATA_DIR . '/stock-alerts.json';

    public static function threshold(): int
    {
        $st = Database::getSettings();
        $t = (int) ($st['low_stock_threshold'] ?? 1);
        return max(0, min(50, $t));
    }

    /** @return list<array{product: array<string, mixed>, available: int}> */
    public static function lowStockPlans(): array
    {
        $threshold = self::threshold();
        $out = [];
        foreach (Database::listProducts() as $p) {
            if (($p['active'] ?? true) === false) {
                continue;
            }
            $n = count(Database::availableStockForProduct((string) $p['id']));
            if ($n <= $threshold) {
                $out[] = ['product' => $p, 'available' => $n];
            }
        }
        usort($out, fn($a, $b) => ($a['available'] <=> $b['available']) ?: strcmp((string) $a['product']['name'], (string) $b['product']['name']));
        return $out;
    }

    public static function bannerHtml(string $loc): string
    {
        $low = self::lowStockPlans();
        if ($low === []) {
            return '';
        }
        $items = '';
        foreach ($low as $row) {
            $name = (string) ($row['product']['name'] ?? '');
            $n = (int) $row['available'];
            $tone = $n === 0 ? 'b-exp' : 'b-warn';
            $items .= '<li><strong>' . Html::esc($name) . '</strong> — '
                . Html::esc(I18n::t('admin.stock_alert.item', $loc, ['n' => $n])) . ' '
                . '<span class="badge ' . $tone . '">'
                . Html::esc(I18n::t($n === 0 ? 'admin.stock_alert.empty' : 'admin.stock_alert.low', $loc))
                . '</span></li>';
        }
        return '<div class="warn stock-alert-banner" role="status"><strong>'
            . Html::esc(I18n::t('admin.stock_alert.banner_title', $loc)) . '</strong>'
            . '<ul class="stock-alert-list">' . $items . '</ul>'
            . '<p class="sub" style="margin:10px 0 0"><a class="card-link" href="'
            . Html::esc(\App\Services\AdminPath::url('/products')) . '">'
            . Html::esc(I18n::t('admin.stock_alert.manage', $loc)) . ' →</a></p></div>';
    }

    public static function checkAndNotify(): void
    {
        foreach (self::lowStockPlans() as $row) {
            $pid = (string) ($row['product']['id'] ?? '');
            $n = (int) $row['available'];
            if ($pid === '' || self::wasNotifiedToday($pid, $n)) {
                continue;
            }
            AdminNotify::lowStock($row['product'], $n, self::threshold());
            self::markNotified($pid, $n);
        }
    }

    private static function wasNotifiedToday(string $productId, int $available): bool
    {
        $state = self::readState();
        $key = $productId . ':' . $available;
        $today = gmdate('Y-m-d');
        return ($state[$key] ?? '') === $today;
    }

    private static function markNotified(string $productId, int $available): void
    {
        $state = self::readState();
        $key = $productId . ':' . $available;
        $state[$key] = gmdate('Y-m-d');
        $cutoff = gmdate('Y-m-d', strtotime('-14 days'));
        foreach ($state as $k => $day) {
            if ($day < $cutoff) {
                unset($state[$k]);
            }
        }
        self::writeState($state);
    }

    /** @return array<string, string> */
    private static function readState(): array
    {
        if (!is_file(self::STATE_FILE)) {
            return [];
        }
        $raw = file_get_contents(self::STATE_FILE);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string, string> $state */
    private static function writeState(array $state): void
    {
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0755, true);
        }
        file_put_contents(self::STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE));
    }
}
