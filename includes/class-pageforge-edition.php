<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * Edition arbitration.
 *
 * Both PageForge editions ship the same constants, classes, helper functions,
 * post types, AJAX actions and shortcodes, so only one may run. This class
 * decides which one, without ever deleting content or settings.
 *
 * The decision helpers are pure so they can be exercised without WordPress.
 */
final class Edition {
    public const FREE = 'free';
    public const PRO = 'pro';

    /** Transient/option key used to surface a single actionable notice. */
    public const NOTICE_OPTION = 'pageforge_edition_notice';

    /**
     * Identify active plugins that are another PageForge edition.
     *
     * @param string[] $active_plugins Plugin files relative to the plugins dir.
     * @param string   $self           This plugin's file, same format.
     * @param callable $describe       fn(string $plugin): array{name:string,edition:string}|null
     * @return array<string, string> Plugin file => edition.
     */
    public static function conflicts(array $active_plugins, string $self, callable $describe): array {
        $conflicts = [];
        foreach (array_unique(array_filter(array_map('strval', $active_plugins))) as $plugin) {
            if ($plugin === $self) {
                continue;
            }
            if (!self::looks_like_pageforge($plugin)) {
                continue;
            }
            $described = $describe($plugin);
            if (!is_array($described) || empty($described['edition'])) {
                continue;
            }
            $conflicts[$plugin] = self::PRO === $described['edition'] ? self::PRO : self::FREE;
        }
        return $conflicts;
    }

    /**
     * Cheap path test before touching the filesystem.
     */
    public static function looks_like_pageforge(string $plugin): bool {
        $plugin = str_replace('\\', '/', $plugin);
        if ('' === $plugin) {
            return false;
        }
        if ('pageforge.php' === basename($plugin)) {
            return true;
        }
        $folder = strtolower((string) strstr($plugin . '/', '/', true));
        return 0 === strpos($folder, 'pageforge');
    }

    /**
     * What activation should do when other editions are already running.
     *
     * Pro outranks Free: activating Pro retires Free, and activating Free while
     * Pro runs is blocked rather than allowed to duplicate the runtime.
     *
     * @param array<string, string> $conflicts Plugin file => edition.
     * @return array{action:string, plugins:string[]}
     */
    public static function activation_decision(string $edition, array $conflicts): array {
        if (empty($conflicts)) {
            return ['action' => 'proceed', 'plugins' => []];
        }
        if (self::PRO === $edition) {
            return ['action' => 'deactivate_others', 'plugins' => array_keys($conflicts)];
        }
        if (in_array(self::PRO, $conflicts, true)) {
            return ['action' => 'block', 'plugins' => array_keys($conflicts, self::PRO, true)];
        }
        // Two Free copies in different folders: retire the other one.
        return ['action' => 'deactivate_others', 'plugins' => array_keys($conflicts)];
    }

    /**
     * What a normal admin request should do when a conflict slipped past
     * activation, for example after a manual file copy or a restored backup.
     *
     * @param array<string, string> $conflicts Plugin file => edition.
     * @return array{action:string, plugins:string[]}
     */
    public static function runtime_decision(string $edition, array $conflicts): array {
        if (empty($conflicts)) {
            return ['action' => 'proceed', 'plugins' => []];
        }
        if (self::PRO === $edition) {
            return ['action' => 'deactivate_others', 'plugins' => array_keys($conflicts)];
        }
        if (in_array(self::PRO, $conflicts, true)) {
            // Pro owns the runtime; step aside without touching any content.
            return ['action' => 'deactivate_self', 'plugins' => array_keys($conflicts, self::PRO, true)];
        }
        return ['action' => 'deactivate_others', 'plugins' => array_keys($conflicts)];
    }

    // ---------------------------------------------------------------- runtime --

    public static function current(): string {
        return defined('PAGEFORGE_EDITION') ? (string) PAGEFORGE_EDITION : self::FREE;
    }

    public static function active_plugins(): array {
        $active = (array) \get_option('active_plugins', []);
        if (function_exists('is_multisite') && \is_multisite()) {
            $active = array_merge($active, array_keys((array) \get_site_option('active_sitewide_plugins', [])));
        }
        return $active;
    }

    /**
     * Read a candidate plugin header and classify its edition.
     *
     * @return array{name:string, edition:string}|null
     */
    public static function describe_plugin(string $plugin): ?array {
        if (!defined('WP_PLUGIN_DIR')) {
            return null;
        }
        $path = \WP_PLUGIN_DIR . '/' . $plugin;
        if (!is_readable($path)) {
            return null;
        }
        $data = \get_file_data($path, [
            'Name' => 'Plugin Name',
            'TextDomain' => 'Text Domain',
            'UpdateURI' => 'Update URI',
        ]);
        $name = (string) ($data['Name'] ?? '');
        $domain = (string) ($data['TextDomain'] ?? '');
        if (false === stripos($name, 'pageforge') && 'pageforge' !== $domain) {
            return null;
        }
        $is_pro = false !== stripos($name, 'premium')
            || false !== stripos($name, 'pro)')
            || false !== stripos((string) ($data['UpdateURI'] ?? ''), 'freemius');
        return ['name' => $name, 'edition' => $is_pro ? self::PRO : self::FREE];
    }

    /**
     * @return array<string, string>
     */
    public static function detect_conflicts(): array {
        return self::conflicts(
            self::active_plugins(),
            \plugin_basename(PAGEFORGE_FILE),
            [self::class, 'describe_plugin']
        );
    }

    public static function remember_notice(string $message): void {
        if ('' === $message) {
            return;
        }
        \update_option(self::NOTICE_OPTION, $message, false);
    }

    public static function render_notice(): void {
        $message = (string) \get_option(self::NOTICE_OPTION, '');
        if ('' === $message) {
            return;
        }
        \delete_option(self::NOTICE_OPTION);
        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            \esc_html($message)
        );
    }

    /**
     * Resolve a conflict discovered during a normal admin request.
     */
    public static function enforce_single_runtime(): void {
        if (!\is_admin() || !\current_user_can('activate_plugins')) {
            return;
        }
        $conflicts = self::detect_conflicts();
        if (empty($conflicts)) {
            return;
        }
        $decision = self::runtime_decision(self::current(), $conflicts);
        if ('proceed' === $decision['action']) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if ('deactivate_self' === $decision['action']) {
            self::remember_notice(__('PageForge Premium is active, so the free PageForge plugin was deactivated. Premium includes the complete PageForge feature set and all of your templates, pages and settings are unchanged.', 'pageforge'));
            \deactivate_plugins(\plugin_basename(PAGEFORGE_FILE));
            return;
        }

        \deactivate_plugins($decision['plugins']);
        self::remember_notice(__('Another PageForge edition was deactivated so only one PageForge runtime is active. No templates, generated pages or settings were changed.', 'pageforge'));
    }

    /**
     * Resolve a conflict at activation time.
     *
     * Blocking uses wp_die so WordPress aborts activation without touching the
     * edition that is already running.
     */
    public static function guard_activation(): void {
        $conflicts = self::detect_conflicts();
        $decision = self::activation_decision(self::current(), $conflicts);
        if ('proceed' === $decision['action']) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if ('block' === $decision['action']) {
            \wp_die(
                \esc_html__('PageForge Premium is already active on this site and already includes everything in the free plugin. Deactivate PageForge Premium first if you want to run the free edition instead.', 'pageforge'),
                \esc_html__('PageForge is already active', 'pageforge'),
                ['back_link' => true]
            );
        }

        \deactivate_plugins($decision['plugins']);
        self::remember_notice(__('The other PageForge edition was deactivated so only one PageForge runtime is active. Your templates, generated pages and settings were preserved.', 'pageforge'));
    }
}
