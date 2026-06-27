<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Helpers\Html;
use App\Helpers\I18n;

/** Developer announcements from GitHub announcements.json — cached, dismissible. */
final class DevAnnouncements
{
    public const REPO = UpdateCheck::REPO;
    public const CACHE_FILE = DATA_DIR . '/dev_announcements.json';
    public const CACHE_TTL = 43200;

    /** @return array{fetched_at: string, error: string, items: list<array<string, mixed>>} */
    public static function fetch(bool $force = false): array
    {
        $cached = self::loadCache();
        if (!$force && $cached !== null && !self::isStale($cached)) {
            return $cached;
        }

        $result = [
            'fetched_at' => date('c'),
            'error' => '',
            'items' => $cached['items'] ?? [],
        ];

        try {
            $url = 'https://raw.githubusercontent.com/' . self::REPO . '/main/announcements.json';
            $headers = [
                'Accept: application/json',
                'User-Agent: Hetzner-vps-Bot/' . Installed::VERSION,
            ];
            $res = HttpClient::request('GET', $url, null, $headers);
            if ($res['code'] !== 200 || !is_array($res['body'])) {
                throw new \RuntimeException('HTTP ' . $res['code']);
            }
            $items = $res['body']['items'] ?? [];
            if (!is_array($items)) {
                throw new \RuntimeException('Invalid announcements format.');
            }
            $result['items'] = self::normalizeItems($items);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        self::saveCache($result);
        return $result;
    }

    /** @param list<array<string, mixed>> $items */
    private static function normalizeItems(array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'severity' => self::normalizeSeverity((string) ($row['severity'] ?? 'info')),
                'placement' => trim((string) ($row['placement'] ?? 'admin_global')),
                'expires' => trim((string) ($row['expires'] ?? '')),
                'min_version' => trim((string) ($row['min_version'] ?? '')),
                'max_version' => trim((string) ($row['max_version'] ?? '')),
                'dismissible' => ($row['dismissible'] ?? true) !== false,
                'title' => is_array($row['title'] ?? null) ? $row['title'] : [],
                'body' => is_array($row['body'] ?? null) ? $row['body'] : [],
                'link' => trim((string) ($row['link'] ?? '')),
            ];
        }
        return $out;
    }

    private static function normalizeSeverity(string $severity): string
    {
        return match (strtolower($severity)) {
            'critical', 'danger' => 'critical',
            'warning', 'warn' => 'warning',
            default => 'info',
        };
    }

    /** @return list<array<string, mixed>> */
    public static function active(string $placement): array
    {
        $state = self::fetch(false);
        $current = Installed::VERSION;
        $out = [];
        foreach ($state['items'] as $item) {
            if (!self::matchesPlacement($item, $placement)) {
                continue;
            }
            if (!self::isActive($item, $current)) {
                continue;
            }
            if (self::isDismissed((string) $item['id'])) {
                continue;
            }
            $out[] = $item;
        }
        usort($out, static function (array $a, array $b): int {
            return self::severityRank((string) $b['severity']) <=> self::severityRank((string) $a['severity']);
        });
        return $out;
    }

    /** @param array<string, mixed> $item */
    private static function matchesPlacement(array $item, string $placement): bool
    {
        $p = (string) ($item['placement'] ?? 'admin_global');
        return $p === $placement || ($placement === 'admin_global' && $p === '');
    }

    /** @param array<string, mixed> $item */
    private static function isActive(array $item, string $current): bool
    {
        $expires = (string) ($item['expires'] ?? '');
        if ($expires !== '') {
            $ts = strtotime($expires . ' 23:59:59');
            if ($ts !== false && time() > $ts) {
                return false;
            }
        }
        $min = (string) ($item['min_version'] ?? '');
        if ($min !== '' && version_compare($current, $min, '<')) {
            return false;
        }
        $max = (string) ($item['max_version'] ?? '');
        if ($max !== '' && version_compare($current, $max, '>')) {
            return false;
        }
        return true;
    }

    private static function severityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 3,
            'warning' => 2,
            default => 1,
        };
    }

    public static function isDismissed(string $id): bool
    {
        $id = trim($id);
        if ($id === '') {
            return false;
        }
        $st = Database::getSettings();
        $list = $st['dismissed_announcements'] ?? [];
        if (!is_array($list)) {
            return false;
        }
        return in_array($id, $list, true);
    }

    public static function dismiss(string $id): void
    {
        $id = trim($id);
        if ($id === '') {
            return;
        }
        $st = Database::getSettings();
        $list = $st['dismissed_announcements'] ?? [];
        if (!is_array($list)) {
            $list = [];
        }
        if (!in_array($id, $list, true)) {
            $list[] = $id;
            Database::saveSettings(['dismissed_announcements' => $list]);
        }
    }

    public static function bannersHtml(string $loc, string $placement, string $csrf): string
    {
        if (!IntegrityGuard::featuresEnabled()) {
            return '';
        }
        $html = '';
        foreach (self::active($placement) as $item) {
            $html .= self::itemBannerHtml($item, $loc, $csrf);
        }
        return $html;
    }

    /** @param array<string, mixed> $item */
    private static function itemBannerHtml(array $item, string $loc, string $csrf): string
    {
        $e = Html::esc(...);
        $severity = (string) ($item['severity'] ?? 'info');
        $class = match ($severity) {
            'critical' => 'danger dev-announce dev-announce-critical',
            'warning' => 'warn dev-announce dev-announce-warning',
            default => 'ok dev-announce dev-announce-info',
        };
        $title = self::text($item['title'] ?? [], $loc);
        $body = self::text($item['body'] ?? [], $loc);
        $link = trim((string) ($item['link'] ?? ''));
        $id = (string) ($item['id'] ?? '');
        $dismissible = ($item['dismissible'] ?? true) !== false;

        $inner = '<strong>' . $e($title) . '</strong>';
        if ($body !== '') {
            $inner .= '<p style="margin:6px 0 0">' . $e($body) . '</p>';
        }
        if ($link !== '') {
            $inner .= '<p style="margin:8px 0 0"><a href="' . $e($link) . '" target="_blank" rel="noopener" style="color:inherit;font-weight:600">'
                . $e(I18n::t('dev_announce.read_more', $loc)) . ' →</a></p>';
        }

        $actions = '';
        if ($dismissible && $id !== '' && $csrf !== '') {
            $actions = '<form method="post" action="' . $e(AdminPath::url('/updates/dismiss-announcement')) . '" class="dev-announce-dismiss">'
                . Csrf::field($csrf)
                . '<input type="hidden" name="id" value="' . $e($id) . '">'
                . '<button type="submit" class="dev-announce-dismiss-btn" title="' . $e(I18n::t('dev_announce.dismiss', $loc)) . '" aria-label="'
                . $e(I18n::t('dev_announce.dismiss', $loc)) . '">×</button></form>';
        }

        return '<div class="' . $e($class) . ' dev-announce-wrap"><div class="dev-announce-body">' . $inner . '</div>' . $actions . '</div>';
    }

    /** @param array<string, mixed> $map */
    private static function text(array $map, string $loc): string
    {
        $key = $loc === 'ar' ? 'ar' : 'en';
        $alt = $key === 'ar' ? 'en' : 'ar';
        $val = trim((string) ($map[$key] ?? ''));
        if ($val === '') {
            $val = trim((string) ($map[$alt] ?? ''));
        }
        return $val;
    }

    /** @param array{fetched_at: string, error: string, items: list<array<string, mixed>>} $state */
    public static function settingsBlockHtml(string $loc, array $state, string $csrf): string
    {
        $e = Html::esc(...);
        $checked = $state['fetched_at'] !== '' ? $e(self::formatCheckedAt($state['fetched_at'], $loc)) : '—';
        $status = '';
        if ($state['error'] !== '') {
            $status = '<div class="warn">' . $e(I18n::t('dev_announce.fetch_failed', $loc, ['msg' => $state['error']])) . '</div>';
        }

        $activeGlobal = self::active('admin_global');
        $activeDash = self::active('admin_dashboard');
        $shown = [];
        foreach (array_merge($activeGlobal, $activeDash) as $item) {
            $shown[(string) $item['id']] = $item;
        }

        $list = '';
        if ($shown === []) {
            $list = '<p class="sub">' . $e(I18n::t('dev_announce.none_active', $loc)) . '</p>';
        } else {
            foreach ($shown as $item) {
                $list .= self::itemBannerHtml($item, $loc, $csrf);
            }
        }

        return '<div class="card"><h2>' . $e(I18n::t('dev_announce.head', $loc)) . '</h2><p class="sub">'
            . $e(I18n::t('dev_announce.sub', $loc)) . '</p>'
            . '<div class="kv"><b>' . $e(I18n::t('dev_announce.last_fetch', $loc)) . '</b><span>' . $checked . '</span></div>'
            . $status
            . $list
            . '<form method="post" action="' . $e(AdminPath::url('/updates/check-announcements')) . '" style="margin-top:12px">'
            . Csrf::field($csrf)
            . '<button type="submit" class="btn gray">' . $e(I18n::t('dev_announce.refresh_btn', $loc)) . '</button>'
            . '</form></div>';
    }

    /** @param array{fetched_at: string, error: string, items: list<array<string, mixed>>}|null $cached */
    public static function isStale(?array $cached): bool
    {
        if ($cached === null) {
            return true;
        }
        $at = (string) ($cached['fetched_at'] ?? '');
        if ($at === '') {
            return true;
        }
        $ts = strtotime($at);
        return $ts === false || (time() - $ts) >= self::CACHE_TTL;
    }

    /** @return array{fetched_at: string, error: string, items: list<array<string, mixed>>}|null */
    public static function loadCache(): ?array
    {
        if (!is_file(self::CACHE_FILE)) {
            return null;
        }
        $raw = file_get_contents(self::CACHE_FILE);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return null;
        }
        $items = $data['items'] ?? [];
        return [
            'fetched_at' => (string) ($data['fetched_at'] ?? ''),
            'error' => (string) ($data['error'] ?? ''),
            'items' => is_array($items) ? self::normalizeItems($items) : [],
        ];
    }

    /** @param array{fetched_at: string, error: string, items: list<array<string, mixed>>} $data */
    public static function saveCache(array $data): void
    {
        if (!is_dir(DATA_DIR)) {
            @mkdir(DATA_DIR, 0755, true);
        }
        @file_put_contents(
            self::CACHE_FILE,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private static function formatCheckedAt(string $iso, string $loc): string
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            return $iso;
        }
        return date($loc === 'ar' ? 'Y-m-d H:i' : 'M j, Y H:i', $ts);
    }
}
