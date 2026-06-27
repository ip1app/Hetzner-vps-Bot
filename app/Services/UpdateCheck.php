<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Html;
use App\Helpers\I18n;

/** GitHub release check — cached 24h, manual refresh from admin settings. */
final class UpdateCheck
{
    public const REPO = 'ip1app/Hetzner-vps-Bot';
    public const CACHE_FILE = DATA_DIR . '/update_check.json';
    public const CACHE_TTL = 86400;

    /** @return array{current: string, latest: string, update_available: bool, release_url: string, checked_at: string, error: string, release_name: string, release_body: string, published_at: string} */
    public static function check(bool $force = false): array
    {
        $current = Installed::VERSION;
        if (!IntegrityGuard::updatesAllowed()) {
            return self::emptyState($current, IntegrityGuard::updatesBlockedFlag());
        }
        $cached = self::loadCache();
        if (!$force && $cached !== null && !self::isStale($cached)) {
            return $cached;
        }

        $result = self::emptyState($current, '');
        $result['latest'] = $cached['latest'] ?? $current;
        $result['release_url'] = $cached['release_url'] ?? self::repoReleasesUrl();
        $result['release_name'] = $cached['release_name'] ?? '';
        $result['release_body'] = $cached['release_body'] ?? '';
        $result['published_at'] = $cached['published_at'] ?? '';

        try {
            $remote = self::fetchLatestFromGitHub();
            $result['latest'] = $remote['version'];
            $result['release_url'] = $remote['url'];
            $result['release_name'] = $remote['name'];
            $result['release_body'] = $remote['body'];
            $result['published_at'] = $remote['published_at'];
            $result['update_available'] = self::isNewer($remote['version'], $current);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            if ($cached !== null) {
                $result['latest'] = $cached['latest'];
                $result['release_url'] = $cached['release_url'];
                $result['release_name'] = $cached['release_name'] ?? '';
                $result['release_body'] = $cached['release_body'] ?? '';
                $result['published_at'] = $cached['published_at'] ?? '';
                $result['update_available'] = ($cached['update_available'] ?? false) && self::isNewer($cached['latest'], $current);
            }
        }

        self::saveCache($result);
        return $result;
    }

    /** @return array{current: string, latest: string, update_available: bool, release_url: string, checked_at: string, error: string, release_name: string, release_body: string, published_at: string} */
    private static function emptyState(string $current, string $error): array
    {
        return [
            'current' => $current,
            'latest' => $current,
            'update_available' => false,
            'release_url' => self::repoReleasesUrl(),
            'checked_at' => date('c'),
            'error' => $error,
            'release_name' => '',
            'release_body' => '',
            'published_at' => '',
        ];
    }

    /** @param array<string, mixed> $cached */
    public static function isStale(array $cached): bool
    {
        $at = (string) ($cached['checked_at'] ?? '');
        if ($at === '') {
            return true;
        }
        $ts = strtotime($at);
        return $ts === false || (time() - $ts) >= self::CACHE_TTL;
    }

    /** @return array{current: string, latest: string, update_available: bool, release_url: string, checked_at: string, error: string, release_name: string, release_body: string, published_at: string}|null */
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
        return [
            'current' => (string) ($data['current'] ?? Installed::VERSION),
            'latest' => (string) ($data['latest'] ?? Installed::VERSION),
            'update_available' => !empty($data['update_available']),
            'release_url' => (string) ($data['release_url'] ?? self::repoReleasesUrl()),
            'checked_at' => (string) ($data['checked_at'] ?? ''),
            'error' => (string) ($data['error'] ?? ''),
            'release_name' => (string) ($data['release_name'] ?? ''),
            'release_body' => (string) ($data['release_body'] ?? ''),
            'published_at' => (string) ($data['published_at'] ?? ''),
        ];
    }

    /** @param array{current: string, latest: string, update_available: bool, release_url: string, checked_at: string, error: string, release_name: string, release_body: string, published_at: string} $data */
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

    public static function repoReleasesUrl(): string
    {
        return 'https://github.com/' . self::REPO . '/releases/latest';
    }

    /** @return array{version: string, url: string, name: string, body: string, published_at: string} */
    private static function fetchLatestFromGitHub(): array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: Hetzner-vps-Bot/' . Installed::VERSION,
        ];
        $base = 'https://api.github.com/repos/' . self::REPO;
        $res = HttpClient::request('GET', $base . '/releases/latest', null, $headers);
        if ($res['code'] === 404) {
            return self::fetchLatestFromGitHubTags($base, $headers);
        }
        if ($res['code'] !== 200 || !is_array($res['body'])) {
            throw new \RuntimeException('GitHub API HTTP ' . $res['code']);
        }
        $tag = trim((string) ($res['body']['tag_name'] ?? ''));
        if ($tag === '') {
            throw new \RuntimeException('Invalid release data from GitHub.');
        }
        $releaseUrl = trim((string) ($res['body']['html_url'] ?? ''));
        if ($releaseUrl === '') {
            $releaseUrl = self::repoReleasesUrl();
        }
        return [
            'version' => self::parseVersion($tag),
            'url' => $releaseUrl,
            'name' => trim((string) ($res['body']['name'] ?? '')),
            'body' => self::plainReleaseBody(trim((string) ($res['body']['body'] ?? ''))),
            'published_at' => trim((string) ($res['body']['published_at'] ?? '')),
        ];
    }

    /** @param list<string> $headers @return array{version: string, url: string, name: string, body: string, published_at: string} */
    private static function fetchLatestFromGitHubTags(string $base, array $headers): array
    {
        $res = HttpClient::request('GET', $base . '/tags?per_page=100', null, $headers);
        if ($res['code'] !== 200 || !is_array($res['body']) || $res['body'] === []) {
            throw new \RuntimeException('No GitHub releases published yet.');
        }
        $bestVersion = '0.0.0';
        $bestTag = '';
        foreach ($res['body'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tag = trim((string) ($row['name'] ?? ''));
            if ($tag === '') {
                continue;
            }
            $version = self::parseVersion($tag);
            if (version_compare($version, $bestVersion, '>')) {
                $bestVersion = $version;
                $bestTag = $tag;
            }
        }
        if ($bestTag === '') {
            throw new \RuntimeException('No GitHub releases published yet.');
        }
        return [
            'version' => $bestVersion,
            'url' => 'https://github.com/' . self::REPO . '/releases/tag/' . rawurlencode($bestTag),
            'name' => $bestTag,
            'body' => '',
            'published_at' => '',
        ];
    }

    private static function plainReleaseBody(string $markdown): string
    {
        if ($markdown === '') {
            return '';
        }
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $markdown) ?? $markdown;
        $text = preg_replace('/^#{1,6}\s+/m', '', $text) ?? $text;
        $text = preg_replace('/[*_`~]/', '', $text) ?? $text;
        $text = trim($text);
        if (strlen($text) > 3000) {
            $text = substr($text, 0, 3000) . '…';
        }
        return $text;
    }

    private static function parseVersion(string $tag): string
    {
        $v = ltrim(trim($tag), "vV \t");
        return $v !== '' ? $v : '0.0.0';
    }

    private static function isNewer(string $latest, string $current): bool
    {
        return version_compare(self::parseVersion($latest), self::parseVersion($current), '>');
    }

    /** @param array{current: string, latest: string, update_available: bool, release_url: string, checked_at: string, error: string, release_name: string, release_body: string, published_at: string} $state */
    public static function bannerHtml(string $loc, array $state): string
    {
        if (empty($state['update_available'])) {
            return '';
        }
        $url = Html::esc($state['release_url']);
        $updatesUrl = Html::esc(AdminPath::url('/updates'));
        $latest = Html::esc($state['latest']);
        $current = Html::esc($state['current']);
        $msg = I18n::t('admin.home.update_banner', $loc, [
            'url' => $url,
            'latest' => $latest,
            'current' => $current,
            'updates_url' => $updatesUrl,
        ]);
        return '<div class="warn">' . $msg . '</div>';
    }

    /** @param array{current: string, latest: string, update_available: bool, release_url: string, checked_at: string, error: string, release_name: string, release_body: string, published_at: string} $state */
    public static function settingsBlockHtml(string $loc, array $state, string $csrf): string
    {
        $e = Html::esc(...);
        $checked = $state['checked_at'] !== '' ? $e(self::formatCheckedAt($state['checked_at'], $loc)) : '—';
        $status = '';
        if ($state['error'] === IntegrityGuard::updatesBlockedFlag()) {
            $status = '<div class="warn">' . I18n::t('integrity.updates_blocked', $loc) . '</div>';
        } elseif ($state['error'] !== '') {
            $status = '<div class="warn">' . $e(I18n::t('settings.updates_check_failed', $loc, ['msg' => $state['error']])) . '</div>';
        } elseif ($state['update_available']) {
            $status = '<div class="warn"><p style="margin:0 0 8px">'
                . $e(I18n::t('settings.updates_available', $loc, [
                    'latest' => $state['latest'],
                    'current' => $state['current'],
                ]))
                . '</p><a class="btn" href="' . $e($state['release_url']) . '" target="_blank" rel="noopener">'
                . $e(I18n::t('settings.updates_download', $loc)) . '</a></div>';
            $status .= self::releaseNotesHtml($state, $loc);
        } else {
            $status = '<div class="ok">' . $e(I18n::t('settings.updates_up_to_date', $loc)) . '</div>';
        }
        $html = '<div class="card"><h2>' . $e(I18n::t('settings.updates_head', $loc)) . '</h2><p class="sub">'
            . $e(I18n::t('settings.updates_sub', $loc)) . '</p>'
            . '<div class="kv"><b>' . $e(I18n::t('settings.updates_current', $loc)) . '</b><span>v' . $e($state['current']) . '</span></div>'
            . '<div class="kv"><b>' . $e(I18n::t('settings.updates_latest', $loc)) . '</b><span>v' . $e($state['latest']) . '</span></div>'
            . '<div class="kv"><b>' . $e(I18n::t('settings.updates_last_check', $loc)) . '</b><span>' . $checked . '</span></div>'
            . $status
            . '<p class="sub">' . $e(I18n::t('settings.updates_manual_hint', $loc)) . '</p>';
        if ($state['error'] !== IntegrityGuard::updatesBlockedFlag()) {
            $html .= '<form method="post" action="' . $e(AdminPath::url('/updates/check-update')) . '" style="margin-top:12px">'
                . Csrf::field($csrf)
                . '<button type="submit" class="btn">' . $e(I18n::t('settings.updates_check_btn', $loc)) . '</button>'
                . '</form>';
        }
        return $html . '</div>';
    }

    /** @param array{release_name: string, release_body: string, published_at: string} $state */
    private static function releaseNotesHtml(array $state, string $loc): string
    {
        $body = trim((string) ($state['release_body'] ?? ''));
        if ($body === '') {
            return '';
        }
        $e = Html::esc(...);
        $name = trim((string) ($state['release_name'] ?? ''));
        $head = $name !== '' ? $name : I18n::t('settings.updates_release_notes', $loc);
        $published = '';
        $at = trim((string) ($state['published_at'] ?? ''));
        if ($at !== '') {
            $ts = strtotime($at);
            if ($ts !== false) {
                $published = date($loc === 'ar' ? 'Y-m-d' : 'M j, Y', $ts);
            }
        }
        $meta = $published !== '' ? '<p class="sub" style="margin:0 0 8px">' . $e($published) . '</p>' : '';
        return '<details class="release-notes" style="margin-top:12px"><summary style="cursor:pointer;font-weight:600">'
            . $e($head) . '</summary>' . $meta
            . '<pre class="release-notes-body">' . $e($body) . '</pre></details>';
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
