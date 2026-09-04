<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('plan_integration_app_url');
delete_site_option('plan_integration_last_update_check');
delete_site_transient('plan_integration_github_release_v1');
