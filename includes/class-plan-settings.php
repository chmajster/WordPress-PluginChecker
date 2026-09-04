<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Plan_Settings
{
    public const OPTION_APP_URL = 'plan_integration_app_url';
    public const OPTION_ROLE_MAP = 'plan_integration_role_map';
    public const OPTION_LAST_CONNECTION = 'plan_integration_last_connection_test';

    private Plan_Updater $updater;
    private Plan_Rollback $rollback;

    public function __construct(Plan_Updater $updater, Plan_Rollback $rollback)
    {
        $this->updater = $updater;
        $this->rollback = $rollback;
    }

    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_plan_integration_force_update_check', [$this, 'handle_force_check']);
        add_action('admin_post_plan_integration_test_connection', [$this, 'handle_test_connection']);
        add_action('admin_post_plan_integration_clear_log', [$this, 'handle_clear_log']);
        add_filter('plugin_action_links_' . $this->updater->get_plugin_basename(), [$this, 'add_settings_link']);
    }

    public function add_settings_page(): void
    {
        add_options_page(__('Plan Integration', 'plan-integration'), __('Plan Integration', 'plan-integration'), 'manage_options', 'plan-integration', [$this, 'render_page']);
    }

    public function register_settings(): void
    {
        register_setting('plan_integration', self::OPTION_APP_URL, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ]);
        register_setting('plan_integration', Plan_Updater::CHANNEL_OPTION, [
            'type' => 'string',
            'sanitize_callback' => static fn($value): string => in_array($value, ['stable', 'beta'], true) ? $value : 'stable',
            'default' => 'stable',
        ]);
        register_setting('plan_integration', Plan_Updater::PINNED_VERSION_OPTION, [
            'type' => 'string',
            'sanitize_callback' => static function ($value): string {
                $value = trim(sanitize_text_field((string)$value));
                return $value === '' || preg_match('/^\d+\.\d+\.\d+(?:-(?:alpha|beta|rc)\.?\d*)?$/i', $value) === 1 ? $value : '';
            },
            'default' => '',
        ]);
        register_setting('plan_integration', Plan_Updater::UPDATES_ENABLED_OPTION, [
            'type' => 'boolean',
            'sanitize_callback' => static fn($value): bool => (bool)$value,
            'default' => true,
        ]);
        register_setting('plan_integration', self::OPTION_ROLE_MAP, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_role_map'],
            'default' => [],
        ]);
    }

    public function sanitize_role_map($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $allowed = ['admin', 'leader', 'deputy', 'volunteer', 'none'];
        $safe = [];
        foreach ($value as $wp_role => $plan_role) {
            $wp_role = sanitize_key((string)$wp_role);
            $plan_role = sanitize_key((string)$plan_role);
            if ($wp_role !== '' && in_array($plan_role, $allowed, true)) {
                $safe[$wp_role] = $plan_role;
            }
        }
        return $safe;
    }

    public function add_settings_link(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=plan-integration')) . '">' . esc_html__('Ustawienia', 'plan-integration') . '</a>');
        return $links;
    }

    public function handle_force_check(): void
    {
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('Brak uprawnień do sprawdzania aktualizacji.', 'plan-integration'));
        }
        check_admin_referer('plan_integration_force_update_check');
        $this->updater->clear_cache();
        $this->updater->get_release(true);
        delete_site_transient('update_plugins');
        wp_update_plugins();
        wp_safe_redirect(admin_url('options-general.php?page=plan-integration&plan-update-checked=1'));
        exit;
    }

    public function handle_test_connection(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Brak uprawnień.', 'plan-integration'));
        }
        check_admin_referer('plan_integration_test_connection');
        $url = esc_url_raw((string)get_option(self::OPTION_APP_URL, ''));
        $result = ['time' => time(), 'ok' => false, 'code' => 0, 'message' => 'URL aplikacji Plan nie został ustawiony.'];
        if ($url !== '') {
            $response = wp_remote_get($url, ['timeout' => 10, 'redirection' => 3]);
            if (is_wp_error($response)) {
                $result['message'] = sanitize_text_field($response->get_error_message());
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $result['code'] = $code;
                $result['ok'] = $code >= 200 && $code < 400;
                $result['message'] = $result['ok'] ? 'Połączenie poprawne.' : 'Serwer odpowiedział kodem HTTP ' . $code . '.';
            }
        }
        update_option(self::OPTION_LAST_CONNECTION, $result, false);
        Plan_Update_Log::add($result['ok'] ? 'info' : 'warning', 'Test połączenia z aplikacją Plan.', ['http_code' => $result['code'], 'result' => $result['message']]);
        wp_safe_redirect(admin_url('options-general.php?page=plan-integration&plan-connection-tested=1'));
        exit;
    }

    public function handle_clear_log(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Brak uprawnień.', 'plan-integration'));
        }
        check_admin_referer('plan_integration_clear_log');
        Plan_Update_Log::clear();
        wp_safe_redirect(admin_url('options-general.php?page=plan-integration'));
        exit;
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $release = $this->updater->get_release(false);
        $last_check = (int)get_site_option(Plan_Updater::LAST_CHECK_OPTION, 0);
        $status = $this->status_label((string)($release['status'] ?? 'unknown'));
        $connection = get_option(self::OPTION_LAST_CONNECTION, []);
        $role_map = get_option(self::OPTION_ROLE_MAP, []);
        $role_map = is_array($role_map) ? $role_map : [];
        $wp_roles = wp_roles()->roles;
        $backups = $this->rollback->get_backups();
        $log = Plan_Update_Log::all();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Plan Integration', 'plan-integration'); ?></h1>

            <?php if (isset($_GET['plan-update-checked'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Sprawdzono dostępność aktualizacji.', 'plan-integration'); ?></p></div><?php endif; ?>
            <?php if (isset($_GET['plan-rollback'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Przywrócono poprzednią wersję wtyczki.', 'plan-integration'); ?></p></div><?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('plan_integration'); ?>
                <h2><?php echo esc_html__('Integracja', 'plan-integration'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr><th><label for="plan_integration_app_url"><?php echo esc_html__('URL aplikacji Plan', 'plan-integration'); ?></label></th><td><input class="regular-text" type="url" id="plan_integration_app_url" name="<?php echo esc_attr(self::OPTION_APP_URL); ?>" value="<?php echo esc_attr((string)get_option(self::OPTION_APP_URL, '')); ?>" placeholder="https://plan.example.org"></td></tr>
                    <tr><th><?php echo esc_html__('Kanał aktualizacji', 'plan-integration'); ?></th><td><select name="<?php echo esc_attr(Plan_Updater::CHANNEL_OPTION); ?>"><option value="stable" <?php selected($this->updater->get_channel(), 'stable'); ?>>Stable</option><option value="beta" <?php selected($this->updater->get_channel(), 'beta'); ?>>Beta / RC</option></select></td></tr>
                    <tr><th><?php echo esc_html__('Aktualizacje GitHub', 'plan-integration'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(Plan_Updater::UPDATES_ENABLED_OPTION); ?>" value="1" <?php checked((bool)get_site_option(Plan_Updater::UPDATES_ENABLED_OPTION, true)); ?>> <?php echo esc_html__('Włączone', 'plan-integration'); ?></label></td></tr>
                    <tr><th><label for="plan_integration_pinned_version"><?php echo esc_html__('Maksymalna wersja', 'plan-integration'); ?></label></th><td><input type="text" id="plan_integration_pinned_version" name="<?php echo esc_attr(Plan_Updater::PINNED_VERSION_OPTION); ?>" value="<?php echo esc_attr((string)get_site_option(Plan_Updater::PINNED_VERSION_OPTION, '')); ?>" placeholder="np. 1.5.0"><p class="description"><?php echo esc_html__('Puste pole oznacza brak blokady wersji.', 'plan-integration'); ?></p></td></tr>
                </table>

                <h2><?php echo esc_html__('Mapowanie ról WordPress → Plan', 'plan-integration'); ?></h2>
                <table class="widefat striped" style="max-width:900px"><thead><tr><th>WordPress</th><th>Plan</th></tr></thead><tbody>
                <?php foreach ($wp_roles as $slug => $data) : ?><tr><td><?php echo esc_html($data['name']); ?></td><td><select name="<?php echo esc_attr(self::OPTION_ROLE_MAP . '[' . $slug . ']'); ?>"><?php foreach (['none' => 'Brak', 'admin' => 'Admin', 'leader' => 'Leader', 'deputy' => 'Deputy', 'volunteer' => 'Volunteer'] as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($role_map[$slug] ?? 'none', $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
                </tbody></table>
                <?php submit_button(); ?>
            </form>

            <h2><?php echo esc_html__('Połączenie z aplikacją Plan', 'plan-integration'); ?></h2>
            <p><?php echo esc_html((string)($connection['message'] ?? 'Nie testowano.')); ?><?php if (!empty($connection['time'])) : ?> — <?php echo esc_html(wp_date('d.m.Y H:i', (int)$connection['time'])); ?><?php endif; ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="plan_integration_test_connection"><?php wp_nonce_field('plan_integration_test_connection'); ?><?php submit_button(__('Testuj połączenie', 'plan-integration'), 'secondary', 'submit', false); ?></form>

            <h2><?php echo esc_html__('Aktualizacje i diagnostyka', 'plan-integration'); ?></h2>
            <table class="widefat striped" style="max-width:900px"><tbody>
                <tr><th><?php echo esc_html__('Repozytorium', 'plan-integration'); ?></th><td><?php echo esc_html(Plan_Updater::REPOSITORY); ?></td></tr>
                <tr><th><?php echo esc_html__('Aktualna wersja', 'plan-integration'); ?></th><td><?php echo esc_html($this->updater->get_installed_version()); ?></td></tr>
                <tr><th><?php echo esc_html__('Wykryta wersja', 'plan-integration'); ?></th><td><?php echo esc_html((string)($release['version'] ?? '—')); ?></td></tr>
                <tr><th><?php echo esc_html__('Kanał', 'plan-integration'); ?></th><td><?php echo esc_html($this->updater->get_channel()); ?></td></tr>
                <tr><th><?php echo esc_html__('PHP', 'plan-integration'); ?></th><td><?php echo esc_html(PHP_VERSION); ?></td></tr>
                <tr><th><?php echo esc_html__('WordPress', 'plan-integration'); ?></th><td><?php echo esc_html(get_bloginfo('version')); ?></td></tr>
                <tr><th><?php echo esc_html__('HTTPS', 'plan-integration'); ?></th><td><?php echo is_ssl() ? 'OK' : esc_html__('Nie', 'plan-integration'); ?></td></tr>
                <tr><th><?php echo esc_html__('ZIP extension', 'plan-integration'); ?></th><td><?php echo extension_loaded('zip') ? 'OK' : esc_html__('Brak', 'plan-integration'); ?></td></tr>
                <tr><th><?php echo esc_html__('Ostatnie sprawdzenie', 'plan-integration'); ?></th><td><?php echo $last_check > 0 ? esc_html(wp_date('d.m.Y H:i', $last_check)) : '—'; ?></td></tr>
                <tr><th><?php echo esc_html__('GitHub API', 'plan-integration'); ?></th><td><?php echo esc_html($status); ?></td></tr>
                <tr><th><?php echo esc_html__('URL Release', 'plan-integration'); ?></th><td><a href="<?php echo esc_url((string)($release['release_url'] ?? Plan_Updater::REPOSITORY_URL . '/releases')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)($release['release_url'] ?? Plan_Updater::REPOSITORY_URL . '/releases')); ?></a></td></tr>
            </tbody></table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px"><input type="hidden" name="action" value="plan_integration_force_update_check"><?php wp_nonce_field('plan_integration_force_update_check'); ?><?php submit_button(__('Sprawdź aktualizacje teraz', 'plan-integration'), 'secondary', 'submit', false); ?></form>

            <h2><?php echo esc_html__('Rollback', 'plan-integration'); ?></h2>
            <?php if ($backups === []) : ?><p><?php echo esc_html__('Brak backupów aktualizacji.', 'plan-integration'); ?></p><?php else : ?><table class="widefat striped" style="max-width:900px"><thead><tr><th>Wersja</th><th>Data</th><th>Akcja</th></tr></thead><tbody><?php foreach ($backups as $index => $backup) : ?><tr><td><?php echo esc_html((string)($backup['version'] ?? '')); ?></td><td><?php echo esc_html(wp_date('d.m.Y H:i', (int)($backup['created_at'] ?? 0))); ?></td><td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="plan_integration_rollback"><input type="hidden" name="backup_index" value="<?php echo esc_attr((string)$index); ?>"><?php wp_nonce_field('plan_integration_rollback'); ?><?php submit_button(__('Przywróć', 'plan-integration'), 'secondary', 'submit', false); ?></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>

            <h2><?php echo esc_html__('Historia aktualizacji i diagnostyki', 'plan-integration'); ?></h2>
            <?php if ($log === []) : ?><p><?php echo esc_html__('Brak wpisów.', 'plan-integration'); ?></p><?php else : ?><table class="widefat striped" style="max-width:1100px"><thead><tr><th>Data</th><th>Poziom</th><th>Zdarzenie</th><th>Kontekst</th></tr></thead><tbody><?php foreach ($log as $entry) : ?><tr><td><?php echo esc_html(wp_date('d.m.Y H:i:s', (int)($entry['time'] ?? 0))); ?></td><td><?php echo esc_html(strtoupper((string)($entry['level'] ?? 'info'))); ?></td><td><?php echo esc_html((string)($entry['event'] ?? '')); ?></td><td><code><?php echo esc_html(wp_json_encode($entry['context'] ?? [], JSON_UNESCAPED_UNICODE)); ?></code></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px"><input type="hidden" name="action" value="plan_integration_clear_log"><?php wp_nonce_field('plan_integration_clear_log'); ?><?php submit_button(__('Wyczyść historię', 'plan-integration'), 'secondary', 'submit', false); ?></form>
        </div>
        <?php
    }

    private function status_label(string $status): string
    {
        return match ($status) {
            'ok' => __('Połączenie poprawne.', 'plan-integration'),
            'missing_asset' => __('Release znaleziony, ale brak plan-integration.zip.', 'plan-integration'),
            'missing_checksum' => __('Release znaleziony, ale brak sumy SHA-256.', 'plan-integration'),
            'no_release' => __('Brak Release dla wybranego kanału.', 'plan-integration'),
            default => __('Nie udało się sprawdzić dostępności aktualizacji.', 'plan-integration'),
        };
    }
}
