<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Plan_Settings
{
    public const OPTION_APP_URL = 'plan_integration_app_url';

    private Plan_Updater $updater;

    public function __construct(Plan_Updater $updater)
    {
        $this->updater = $updater;
    }

    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_plan_integration_force_update_check', [$this, 'handle_force_check']);
        add_filter('plugin_action_links_' . $this->updater->get_plugin_basename(), [$this, 'add_settings_link']);
    }

    public function add_settings_page(): void
    {
        add_options_page(
            __('Plan Integration', 'plan-integration'),
            __('Plan Integration', 'plan-integration'),
            'manage_options',
            'plan-integration',
            [$this, 'render_page']
        );
    }

    public function register_settings(): void
    {
        register_setting('plan_integration', self::OPTION_APP_URL, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ]);
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

        wp_safe_redirect(add_query_arg([
            'page' => 'plan-integration',
            'plan-update-checked' => '1',
        ], admin_url('options-general.php')));
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
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Plan Integration', 'plan-integration'); ?></h1>

            <?php if (isset($_GET['plan-update-checked']) && sanitize_text_field(wp_unslash($_GET['plan-update-checked'])) === '1') : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Sprawdzono dostępność aktualizacji.', 'plan-integration'); ?></p></div>
            <?php endif; ?>

            <h2><?php echo esc_html__('Integracja', 'plan-integration'); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields('plan_integration'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="plan_integration_app_url"><?php echo esc_html__('URL aplikacji Plan', 'plan-integration'); ?></label></th>
                        <td><input class="regular-text" type="url" id="plan_integration_app_url" name="<?php echo esc_attr(self::OPTION_APP_URL); ?>" value="<?php echo esc_attr((string)get_option(self::OPTION_APP_URL, '')); ?>" placeholder="https://plan.example.org"></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2><?php echo esc_html__('Aktualizacje', 'plan-integration'); ?></h2>
            <table class="widefat striped" style="max-width:900px">
                <tbody>
                    <tr><th><?php echo esc_html__('Repozytorium', 'plan-integration'); ?></th><td><?php echo esc_html(Plan_Updater::REPOSITORY); ?></td></tr>
                    <tr><th><?php echo esc_html__('Zainstalowana wersja', 'plan-integration'); ?></th><td><?php echo esc_html($this->updater->get_installed_version()); ?></td></tr>
                    <tr><th><?php echo esc_html__('Najnowsza wykryta wersja', 'plan-integration'); ?></th><td><?php echo esc_html((string)($release['version'] ?? '—')); ?></td></tr>
                    <tr><th><?php echo esc_html__('Ostatnie sprawdzenie', 'plan-integration'); ?></th><td><?php echo $last_check > 0 ? esc_html(wp_date('d.m.Y H:i', $last_check)) : '—'; ?></td></tr>
                    <tr><th><?php echo esc_html__('Status', 'plan-integration'); ?></th><td><?php echo esc_html($status); ?></td></tr>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px">
                <input type="hidden" name="action" value="plan_integration_force_update_check">
                <?php wp_nonce_field('plan_integration_force_update_check'); ?>
                <?php submit_button(__('Sprawdź aktualizacje teraz', 'plan-integration'), 'secondary', 'submit', false); ?>
            </form>

            <h2><?php echo esc_html__('Diagnostyka aktualizacji', 'plan-integration'); ?></h2>
            <table class="widefat striped" style="max-width:900px">
                <tbody>
                    <tr><th><?php echo esc_html__('Aktualna wersja', 'plan-integration'); ?></th><td><?php echo esc_html($this->updater->get_installed_version()); ?></td></tr>
                    <tr><th><?php echo esc_html__('Wykryta wersja', 'plan-integration'); ?></th><td><?php echo esc_html((string)($release['version'] ?? '—')); ?></td></tr>
                    <tr><th><?php echo esc_html__('Repozytorium', 'plan-integration'); ?></th><td><?php echo esc_html(Plan_Updater::REPOSITORY); ?></td></tr>
                    <tr><th><?php echo esc_html__('Branch', 'plan-integration'); ?></th><td><?php echo esc_html(Plan_Updater::BRANCH); ?></td></tr>
                    <tr><th><?php echo esc_html__('Kanał', 'plan-integration'); ?></th><td><?php echo esc_html(Plan_Updater::CHANNEL); ?></td></tr>
                    <tr><th><?php echo esc_html__('URL Release', 'plan-integration'); ?></th><td><a href="<?php echo esc_url((string)($release['release_url'] ?? Plan_Updater::REPOSITORY_URL . '/releases')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)($release['release_url'] ?? Plan_Updater::REPOSITORY_URL . '/releases')); ?></a></td></tr>
                    <tr><th><?php echo esc_html__('Komunikacja z GitHub API', 'plan-integration'); ?></th><td><?php echo esc_html($status); ?></td></tr>
                    <tr><th><?php echo esc_html__('Komunikat', 'plan-integration'); ?></th><td><?php echo esc_html((string)($release['message'] ?? '')); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function status_label(string $status): string
    {
        return match ($status) {
            'ok' => __('Połączenie poprawne.', 'plan-integration'),
            'missing_asset' => __('Release znaleziony, ale brak plan-integration.zip.', 'plan-integration'),
            'no_stable_release' => __('Brak stabilnego Release.', 'plan-integration'),
            default => __('Nie udało się sprawdzić dostępności aktualizacji.', 'plan-integration'),
        };
    }
}
