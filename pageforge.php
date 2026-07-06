<?php
/**
 * Plugin Name: PageForge – AI Programmatic SEO, Bulk Page Generator & Local SEO 
 * Plugin URI: https://pageforge.pro
 * Description: AI Programmatic SEO WordPress Plugin — Generate bulk pages from CSV with AI, schema, internal links, and Yoast or Rank Math sync.
 * Version: 1.1.8
 * Author: Codefreex
 * Author URI: https://codefreex.com
 * Text Domain: pageforge
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

// ------------------------------------------------------------
// Defines
// ------------------------------------------------------------
define('PAGEFORGE_VERSION', '1.1.8');
define('PAGEFORGE_PATH', plugin_dir_path(__FILE__));
define('PAGEFORGE_URL', plugin_dir_url(__FILE__));
define('PAGEFORGE_BASENAME', plugin_basename(__FILE__));
define('PAGEFORGE_OPTION', 'pageforge_settings');
// WordPress.org build is fully free with a generous per-run CSV limit.
define('PAGEFORGE_FREE_ROW_LIMIT', 100);
define('PAGEFORGE_AI_FREE_LIMIT', 0);

// ------------------------------------------------------------
// Autoload lightweight
// ------------------------------------------------------------
 require_once __DIR__ . '/includes/helpers.php';
 require_once __DIR__ . '/includes/class-pageforge-plugin.php';
 require_once __DIR__ . '/includes/class-pageforge-admin.php';
 require_once __DIR__ . '/includes/class-pageforge-ai.php';
 require_once __DIR__ . '/includes/class-pageforge-datasource.php';
 require_once __DIR__ . '/includes/class-pageforge-template.php';
 require_once __DIR__ . '/includes/class-pageforge-generator.php';
 require_once __DIR__ . '/includes/class-pageforge-frontend.php';
 require_once __DIR__ . '/includes/class-pageforge-webhook.php';

// ------------------------------------------------------------
// Bootstrap
// ------------------------------------------------------------
add_action('plugins_loaded', function() {
    \PageForge\Plugin::instance();
});

register_activation_hook(__FILE__, function(){
    \PageForge\Plugin::instance()->activate();
});

register_deactivation_hook(__FILE__, function(){
    \PageForge\Plugin::instance()->deactivate();
});
