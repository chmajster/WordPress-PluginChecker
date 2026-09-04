<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('plan_integration_app_url');
delete_option('plan_integration_role_map');
delete_option('plan_integration_last_connection_test');
delete_site_option('plan_integration_last_update_check');
delete_site_option('plan_integration_update_channel');
delete_site_option('plan_integration_pinned_version');
delete_site_option('plan_integration_updates_enabled');
delete_site_option('plan_integration_update_log');
delete_site_option('plan_integration_update_backups');
delete_site_transient('plan_integration_github_release_v1');
delete_site_transient('plan_integration_github_release_v2');
