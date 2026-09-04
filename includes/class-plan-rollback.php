<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Plan_Rollback
{
    public const BACKUP_OPTION = 'plan_integration_update_backups';

    private string $plugin_file;
    private string $version;

    public function __construct(string $plugin_file, string $version)
    {
        $this->plugin_file = $plugin_file;
        $this->version = $version;
    }

    public function register_hooks(): void
    {
        add_filter('upgrader_pre_install', [$this, 'backup_before_update'], 10, 2);
        add_action('upgrader_process_complete', [$this, 'log_completed_update'], 10, 2);
        add_action('admin_post_plan_integration_rollback', [$this, 'handle_rollback']);
    }

    public function backup_before_update($response, array $hook_extra)
    {
        if (($hook_extra['action'] ?? '') !== 'update' || ($hook_extra['type'] ?? '') !== 'plugin') {
            return $response;
        }

        $plugins = (array)($hook_extra['plugins'] ?? []);
        if (!in_array(plugin_basename($this->plugin_file), $plugins, true)) {
            return $response;
        }

        $source = plugin_dir_path($this->plugin_file);
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            Plan_Update_Log::add('warning', 'Nie udało się utworzyć backupu przed aktualizacją.', ['reason' => (string)$uploads['error']]);
            return $response;
        }

        $root = trailingslashit($uploads['basedir']) . 'plan-integration-backups';
        $target = trailingslashit($root) . $this->version . '-' . gmdate('Ymd-His');
        wp_mkdir_p($target);

        if (!$this->copy_directory($source, $target)) {
            Plan_Update_Log::add('warning', 'Backup przed aktualizacją nie został wykonany w całości.');
            return $response;
        }

        $backups = get_site_option(self::BACKUP_OPTION, []);
        if (!is_array($backups)) {
            $backups = [];
        }
        array_unshift($backups, [
            'version' => $this->version,
            'path' => $target,
            'created_at' => time(),
        ]);
        update_site_option(self::BACKUP_OPTION, array_slice($backups, 0, 5));
        Plan_Update_Log::add('info', 'Utworzono backup przed aktualizacją.', ['version' => $this->version]);

        return $response;
    }

    public function log_completed_update($upgrader, array $hook_extra): void
    {
        if (($hook_extra['action'] ?? '') !== 'update' || ($hook_extra['type'] ?? '') !== 'plugin') {
            return;
        }
        $plugins = (array)($hook_extra['plugins'] ?? []);
        if (in_array(plugin_basename($this->plugin_file), $plugins, true)) {
            Plan_Update_Log::add('info', 'Aktualizacja wtyczki została zakończona.', ['from_version' => $this->version]);
        }
    }

    public function get_backups(): array
    {
        $backups = get_site_option(self::BACKUP_OPTION, []);
        return is_array($backups) ? $backups : [];
    }

    public function handle_rollback(): void
    {
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('Brak uprawnień do rollbacku.', 'plan-integration'));
        }
        check_admin_referer('plan_integration_rollback');

        $index = isset($_POST['backup_index']) ? absint($_POST['backup_index']) : -1;
        $backups = $this->get_backups();
        if (!isset($backups[$index]) || empty($backups[$index]['path'])) {
            wp_die(esc_html__('Nieprawidłowy backup.', 'plan-integration'));
        }

        $source = (string)$backups[$index]['path'];
        $target = plugin_dir_path($this->plugin_file);
        if (!is_dir($source) || !$this->replace_directory($source, $target)) {
            Plan_Update_Log::add('error', 'Rollback nie powiódł się.', ['version' => (string)($backups[$index]['version'] ?? '')]);
            wp_die(esc_html__('Rollback nie powiódł się.', 'plan-integration'));
        }

        Plan_Update_Log::add('info', 'Przywrócono poprzednią wersję wtyczki.', ['version' => (string)($backups[$index]['version'] ?? '')]);
        delete_site_transient('update_plugins');
        wp_safe_redirect(admin_url('options-general.php?page=plan-integration&plan-rollback=1'));
        exit;
    }

    private function replace_directory(string $source, string $target): bool
    {
        $this->remove_directory($target);
        wp_mkdir_p($target);
        return $this->copy_directory($source, $target);
    }

    private function copy_directory(string $source, string $target): bool
    {
        if (!is_dir($source)) {
            return false;
        }
        wp_mkdir_p($target);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $destination = $target . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                wp_mkdir_p($destination);
            } elseif (!copy($item->getPathname(), $destination)) {
                return false;
            }
        }
        return true;
    }

    private function remove_directory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
