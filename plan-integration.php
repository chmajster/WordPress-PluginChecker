<?php
/**
 * Plugin Name: Plan Integration
 * Description: Integracja WordPress z aplikacją Plan z aktualizacjami dostarczanymi przez GitHub Releases.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author: chmajster
 * Text Domain: plan-integration
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PLAN_INTEGRATION_VERSION', '1.1.0');
define('PLAN_INTEGRATION_FILE', __FILE__);
define('PLAN_INTEGRATION_DIR', plugin_dir_path(__FILE__));
define('PLAN_INTEGRATION_URL', plugin_dir_url(__FILE__));

require_once PLAN_INTEGRATION_DIR . 'includes/class-plan-version.php';
require_once PLAN_INTEGRATION_DIR . 'includes/class-plan-update-log.php';
require_once PLAN_INTEGRATION_DIR . 'includes/class-plan-updater.php';
require_once PLAN_INTEGRATION_DIR . 'includes/class-plan-rollback.php';
require_once PLAN_INTEGRATION_DIR . 'includes/class-plan-settings.php';
require_once PLAN_INTEGRATION_DIR . 'includes/class-plan-integration.php';

function plan_integration_bootstrap(): void
{
    $updater = new Plan_Updater(PLAN_INTEGRATION_FILE, PLAN_INTEGRATION_VERSION);
    $rollback = new Plan_Rollback(PLAN_INTEGRATION_FILE, PLAN_INTEGRATION_VERSION);
    $settings = new Plan_Settings($updater, $rollback);
    $integration = new Plan_Integration();

    $updater->register_hooks();
    $rollback->register_hooks();
    $settings->register_hooks();
    $integration->register_hooks();
}
add_action('plugins_loaded', 'plan_integration_bootstrap');
