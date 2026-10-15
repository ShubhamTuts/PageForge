<?php
/**
 * Plugin Name: PageForge – AI Programmatic SEO, Bulk Page Generator, SEO MCP & Local SEO
 * Plugin URI: https://pageforge.pro
 * Description: AI Programmatic SEO WordPress Plugin — Generate bulk pages from CSV with AI, schema, internal links, and Yoast or Rank Math sync.
 * Version: 1.2.6
 * Author: Codefreex
 * Author URI: https://codefreex.com
 * Text Domain: pageforge
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

// ------------------------------------------------------------
// PHP requirement guard
// ------------------------------------------------------------
// The header's "Requires PHP" is advisory: WordPress honours it when installing
// or updating through the dashboard, but a site already running this plugin on an
// older PHP, or one that copied the files in manually, would otherwise hit a
// fatal error on a typed signature. Bail with an actionable notice instead, so an
// unsupported host degrades to "PageForge is inactive" rather than a white screen.
if (version_compare(PHP_VERSION, '8.0', '<')) {
    add_action('admin_notices', function () {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(
                sprintf(
                    /* translators: 1: required PHP version, 2: PHP version running on the server. */
                    __('PageForge requires PHP %1$s or newer. This site is running PHP %2$s, so PageForge has not started. Ask your host to update PHP.', 'pageforge'),
                    '8.0',
                    PHP_VERSION
                )
            )
        );
    });
    return;
}

// ------------------------------------------------------------
// Single runtime guard
// ------------------------------------------------------------
// Both editions declare the same constants, classes, helper functions, post
// types, AJAX actions and shortcodes. If another edition already booted in this
// request, stay completely inert and tell the administrator, rather than
// fataling on a redeclaration.
if (defined('PAGEFORGE_EDITION')) {
    add_action('admin_notices', function () {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Two PageForge editions are active at the same time. Only one is running. Deactivate the edition you do not want to use.', 'pageforge')
        );
    });
    return;
}

define('PAGEFORGE_EDITION', 'free');
define('PAGEFORGE_FILE', __FILE__);

// ------------------------------------------------------------
// Defines
// ------------------------------------------------------------
define('PAGEFORGE_VERSION', '1.2.6');
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
 require_once __DIR__ . '/includes/class-pageforge-secrets.php';
 require_once __DIR__ . '/includes/class-pageforge-edition.php';
 require_once __DIR__ . '/includes/class-pageforge-compatibility.php';
 require_once __DIR__ . '/includes/class-pageforge-upgrade.php';
 require_once __DIR__ . '/includes/class-pageforge-plugin.php';
 require_once __DIR__ . '/includes/class-pageforge-admin.php';
 require_once __DIR__ . '/includes/class-pageforge-ai.php';
 require_once __DIR__ . '/includes/class-pageforge-datasource.php';
 require_once __DIR__ . '/includes/class-pageforge-template.php';
 require_once __DIR__ . '/includes/class-pageforge-generator.php';
 require_once __DIR__ . '/includes/class-pageforge-frontend.php';
 require_once __DIR__ . '/includes/class-pageforge-webhook.php';
 require_once __DIR__ . '/includes/class-pageforge-access.php';
 require_once __DIR__ . '/includes/class-pageforge-seo.php';
 require_once __DIR__ . '/includes/class-pageforge-url-guard.php';
 require_once __DIR__ . '/includes/class-pageforge-request.php';
 require_once __DIR__ . '/includes/class-pageforge-mcp-scopes.php';
 require_once __DIR__ . '/includes/class-pageforge-mcp-ratelimit.php';
 require_once __DIR__ . '/includes/class-pageforge-oauth-tokens.php';
 require_once __DIR__ . '/includes/class-pageforge-oauth-clients.php';
 require_once __DIR__ . '/includes/class-pageforge-oauth.php';
 require_once __DIR__ . '/includes/class-pageforge-mcp-credentials.php';
 require_once __DIR__ . '/includes/class-pageforge-mcp-registry.php';
 require_once __DIR__ . '/includes/class-pageforge-mcp-server.php';
 require_once __DIR__ . '/includes/class-pageforge-mcp-diagnostics.php';

// ------------------------------------------------------------
// Bootstrap
// ------------------------------------------------------------
add_action('plugins_loaded', function() {
    \PageForge\Plugin::instance();
});

// The MCP REST route is always registered; the handler itself refuses every
// request unless the admin has explicitly enabled MCP in Settings.
\PageForge\Mcp_Server::init();
// Discovery documents, the consent screen and the token/revocation endpoints.
// These must exist even while MCP is disabled so the admin diagnostics can
// report on them; each handler independently refuses work when MCP is off.
\PageForge\Oauth::init();

add_action('init', function() {
    \PageForge\Upgrade::run();
}, 11);

// Self-heal if a conflicting edition appears after activation, for example
// through a restored backup or a manual upload.
add_action('admin_init', ['PageForge\\Edition', 'enforce_single_runtime'], 1);
add_action('admin_notices', ['PageForge\\Edition', 'render_notice']);

register_activation_hook(__FILE__, function(){
    \PageForge\Edition::guard_activation();
    \PageForge\Plugin::instance()->activate();
});

register_deactivation_hook(__FILE__, function(){
    \PageForge\Plugin::instance()->deactivate();
});
