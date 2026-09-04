<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Plan_Updater
{
    public const REPOSITORY = 'chmajster/WordPress-PluginChecker';
    public const REPOSITORY_URL = 'https://github.com/chmajster/WordPress-PluginChecker';
    public const API_URL = 'https://api.github.com/repos/chmajster/WordPress-PluginChecker/releases?per_page=20';
    public const ASSET_NAME = 'plan-integration.zip';
    public const CHECKSUM_ASSET_NAME = 'plan-integration.zip.sha256';
    public const BRANCH = 'main';
    public const CACHE_KEY = 'plan_integration_github_release_v2';
    public const LAST_CHECK_OPTION = 'plan_integration_last_update_check';
    public const CHANNEL_OPTION = 'plan_integration_update_channel';
    public const PINNED_VERSION_OPTION = 'plan_integration_pinned_version';
    public const UPDATES_ENABLED_OPTION = 'plan_integration_updates_enabled';

    private string $plugin_file;
    private string $plugin_basename;
    private string $version;

    public function __construct(string $plugin_file, string $version)
    {
        $this->plugin_file = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->version = $version;
    }

    public function register_hooks(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_information'], 20, 3);
        add_filter('upgrader_pre_download', [$this, 'download_and_verify_asset'], 10, 4);
    }

    public function inject_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $release = $this->get_release($this->is_force_check_request());
        $item = (object)[
            'id' => self::REPOSITORY_URL,
            'slug' => 'plan-integration',
            'plugin' => $this->plugin_basename,
            'new_version' => $release['version'] ?? $this->version,
            'url' => $release['release_url'] ?? self::REPOSITORY_URL,
            'package' => $release['package_url'] ?? '',
            'icons' => [],
            'banners' => [],
            'banners_rtl' => [],
            'tested' => '7.1',
            'requires_php' => '8.2',
            'compatibility' => new stdClass(),
        ];

        $enabled = (bool)get_site_option(self::UPDATES_ENABLED_OPTION, true);
        $pinned = trim((string)get_site_option(self::PINNED_VERSION_OPTION, ''));
        $allowed_by_pin = $pinned === '' || version_compare((string)($release['version'] ?? '0.0.0'), $pinned, '<=');

        if (
            $enabled
            && $allowed_by_pin
            && ($release['status'] ?? '') === 'ok'
            && !empty($release['package_url'])
            && !empty($release['checksum_url'])
            && Plan_Version::is_update_available($this->version, (string)$release['version'])
        ) {
            $transient->response[$this->plugin_basename] = $item;
            unset($transient->no_update[$this->plugin_basename]);
        } else {
            $transient->no_update[$this->plugin_basename] = $item;
            unset($transient->response[$this->plugin_basename]);
        }

        return $transient;
    }

    public function plugin_information($result, string $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'plan-integration') {
            return $result;
        }

        $release = $this->get_release(false);
        if (($release['status'] ?? '') !== 'ok') {
            return $result;
        }

        return (object)[
            'name' => 'Plan Integration',
            'slug' => 'plan-integration',
            'version' => (string)$release['version'],
            'author' => '<a href="https://github.com/chmajster">chmajster</a>',
            'homepage' => self::REPOSITORY_URL,
            'requires' => '6.0',
            'tested' => '7.1',
            'requires_php' => '8.2',
            'download_link' => (string)$release['package_url'],
            'last_updated' => (string)($release['published_at'] ?? ''),
            'sections' => [
                'description' => esc_html__('Integracja WordPress z aplikacją Plan.', 'plan-integration'),
                'changelog' => '<pre style="white-space:pre-wrap">' . esc_html((string)($release['body'] ?? '')) . '</pre>',
            ],
            'external' => true,
        ];
    }

    public function download_and_verify_asset($reply, string $package, $upgrader, array $hook_extra)
    {
        if (($hook_extra['type'] ?? '') !== 'plugin' || !$this->is_our_package($package)) {
            return $reply;
        }

        $release = $this->get_release(false);
        if (($release['status'] ?? '') !== 'ok' || empty($release['checksum_url'])) {
            return new WP_Error('plan_integration_checksum_missing', __('Brak sumy SHA-256 dla aktualizacji.', 'plan-integration'));
        }

        $tmp = wp_tempnam(self::ASSET_NAME);
        if (!$tmp) {
            return new WP_Error('plan_integration_temp_file', __('Nie można utworzyć pliku tymczasowego aktualizacji.', 'plan-integration'));
        }

        $response = wp_remote_get($package, [
            'timeout' => 60,
            'redirection' => 5,
            'stream' => true,
            'filename' => $tmp,
            'headers' => $this->github_headers(true),
        ]);
        if (is_wp_error($response)) {
            @unlink($tmp);
            Plan_Update_Log::add('error', 'Pobieranie aktualizacji nie powiodło się.', ['reason' => $response->get_error_message()]);
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            @unlink($tmp);
            Plan_Update_Log::add('error', 'GitHub odrzucił pobieranie aktualizacji.', ['http_code' => $code]);
            return new WP_Error('plan_integration_download_failed', sprintf(__('GitHub zwrócił kod HTTP %d podczas pobierania aktualizacji.', 'plan-integration'), $code));
        }

        $expected = $this->fetch_checksum((string)$release['checksum_url']);
        if (is_wp_error($expected)) {
            @unlink($tmp);
            return $expected;
        }

        $actual = hash_file('sha256', $tmp);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            @unlink($tmp);
            Plan_Update_Log::add('error', 'Suma SHA-256 aktualizacji jest nieprawidłowa.');
            return new WP_Error('plan_integration_checksum_mismatch', __('Suma SHA-256 aktualizacji jest nieprawidłowa. Instalacja została zablokowana.', 'plan-integration'));
        }

        Plan_Update_Log::add('info', 'Zweryfikowano SHA-256 paczki aktualizacji.', ['version' => (string)($release['version'] ?? '')]);
        return $tmp;
    }

    public function get_release(bool $force = false): array
    {
        if (!$force) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $checked_at = time();
        update_site_option(self::LAST_CHECK_OPTION, $checked_at);
        $response = wp_remote_get(self::API_URL, [
            'timeout' => 10,
            'redirection' => 3,
            'headers' => $this->github_headers(false),
        ]);

        if (is_wp_error($response)) {
            return $this->cache_error('api_error', $checked_at, $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return $this->cache_error('http_error', $checked_at, 'HTTP ' . $code);
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            return $this->cache_error('invalid_response', $checked_at, 'Nieprawidłowa odpowiedź JSON.');
        }

        $channel = $this->get_channel();
        $release = Plan_Version::latest_release($decoded, self::ASSET_NAME, $channel);
        if ($release === null) {
            return $this->cache_error('no_release', $checked_at, 'Nie znaleziono Release dla wybranego kanału.');
        }

        $asset = $release['_asset'] ?? null;
        if (!is_array($asset)) {
            return $this->cache_error('missing_asset', $checked_at, 'Release znaleziony, ale brak ' . self::ASSET_NAME . '.');
        }

        $checksum_asset = null;
        foreach ((array)($release['assets'] ?? []) as $candidate) {
            if ((string)($candidate['name'] ?? '') === self::CHECKSUM_ASSET_NAME) {
                $checksum_asset = $candidate;
                break;
            }
        }
        if (!is_array($checksum_asset)) {
            return $this->cache_error('missing_checksum', $checked_at, 'Release znaleziony, ale brak ' . self::CHECKSUM_ASSET_NAME . '.');
        }

        $token = $this->github_token();
        $package_url = $token !== '' ? (string)($asset['url'] ?? '') : (string)($asset['browser_download_url'] ?? '');
        $checksum_url = $token !== '' ? (string)($checksum_asset['url'] ?? '') : (string)($checksum_asset['browser_download_url'] ?? '');
        if ($package_url === '' || $checksum_url === '') {
            return $this->cache_error('missing_asset_url', $checked_at, 'Asset nie ma prawidłowego URL.');
        }

        $data = [
            'status' => 'ok',
            'version' => (string)$release['_normalized_version'],
            'release_url' => (string)($release['html_url'] ?? self::REPOSITORY_URL),
            'package_url' => $package_url,
            'checksum_url' => $checksum_url,
            'published_at' => (string)($release['published_at'] ?? ''),
            'body' => (string)($release['body'] ?? ''),
            'checked_at' => $checked_at,
            'channel' => $channel,
            'message' => 'OK',
        ];

        set_site_transient(self::CACHE_KEY, $data, 12 * HOUR_IN_SECONDS);
        Plan_Update_Log::add('info', 'Sprawdzono GitHub Releases.', ['version' => $data['version'], 'channel' => $channel]);
        return $data;
    }

    public function clear_cache(): void
    {
        delete_site_transient(self::CACHE_KEY);
    }

    public function get_installed_version(): string
    {
        return $this->version;
    }

    public function get_plugin_basename(): string
    {
        return $this->plugin_basename;
    }

    public function get_channel(): string
    {
        $channel = (string)get_site_option(self::CHANNEL_OPTION, 'stable');
        return in_array($channel, ['stable', 'beta'], true) ? $channel : 'stable';
    }

    private function fetch_checksum(string $url)
    {
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'redirection' => 5,
            'headers' => $this->github_headers(true),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        if (wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300) {
            return new WP_Error('plan_integration_checksum_download_failed', __('Nie udało się pobrać sumy SHA-256.', 'plan-integration'));
        }
        $body = trim(wp_remote_retrieve_body($response));
        if (preg_match('/\b([a-fA-F0-9]{64})\b/', $body, $matches) !== 1) {
            return new WP_Error('plan_integration_checksum_invalid', __('Plik sumy SHA-256 ma nieprawidłowy format.', 'plan-integration'));
        }
        return strtolower($matches[1]);
    }

    private function is_our_package(string $package): bool
    {
        return str_contains($package, 'github.com/' . self::REPOSITORY)
            || str_contains($package, 'api.github.com/repos/' . self::REPOSITORY . '/releases/assets/');
    }

    private function is_force_check_request(): bool
    {
        return is_admin() && current_user_can('update_plugins') && isset($_GET['force-check']) && sanitize_text_field(wp_unslash($_GET['force-check'])) === '1';
    }

    private function github_headers(bool $download): array
    {
        $headers = [
            'Accept' => $download ? 'application/octet-stream' : 'application/vnd.github+json',
            'User-Agent' => 'Plan-Integration/' . $this->version,
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        $token = $this->github_token();
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $headers;
    }

    private function github_token(): string
    {
        if (defined('PLAN_GITHUB_TOKEN') && is_string(PLAN_GITHUB_TOKEN) && PLAN_GITHUB_TOKEN !== '') {
            return PLAN_GITHUB_TOKEN;
        }
        $env = getenv('PLAN_GITHUB_TOKEN');
        return is_string($env) ? trim($env) : '';
    }

    private function cache_error(string $status, int $checked_at, string $message): array
    {
        $data = [
            'status' => $status,
            'version' => null,
            'release_url' => self::REPOSITORY_URL . '/releases',
            'checked_at' => $checked_at,
            'channel' => $this->get_channel(),
            'message' => sanitize_text_field($message),
        ];
        set_site_transient(self::CACHE_KEY, $data, 15 * MINUTE_IN_SECONDS);
        Plan_Update_Log::add('warning', 'Sprawdzenie GitHub Releases nie powiodło się.', ['status' => $status, 'message' => $message]);
        return $data;
    }
}
