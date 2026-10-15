<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

final class Upgrade {
    private const REWRITE_OPTION = 'pageforge_rewrite_rules_version';
    private const REWRITE_VERSION = '1';
    private const MAINTENANCE_OPTION = 'pageforge_maintenance_version';
    private const MAINTENANCE_VERSION = '1.2.4';

    public static function run(): void {
        self::run_maintenance();
        $stored = (string) \get_option('pageforge_content_namespace', '');
        if (in_array($stored, ['pageforge', 'pf'], true)) return;
        \update_option('pageforge_content_namespace', Compatibility::select_namespace(self::content_counts()), false);
    }

    private static function run_maintenance(): void {
        $installed = (string) \get_option(self::MAINTENANCE_OPTION, '0');
        if (version_compare($installed, self::MAINTENANCE_VERSION, '>=')) {
            return;
        }
        if (class_exists(Mcp_Server::class)) {
            Mcp_Server::clear_failures();
        }
        \update_option(self::MAINTENANCE_OPTION, self::MAINTENANCE_VERSION, false);
    }

    public static function content_counts(): array {
        return [
            'pageforge_templates' => self::count('pageforge_template'),
            'pageforge_pages' => self::count('pageforge_page'),
            'pf_templates' => self::count('pf_template'),
            'pf_pages' => self::count('pf_page'),
            'pageforge_native_pages' => self::count_native_generated('pageforge'),
            'pf_native_pages' => self::count_native_generated('pf'),
        ];
    }

    public static function maybe_upgrade_rewrite_rules(bool $force = false): void {
        if (!$force && self::REWRITE_VERSION === (string) \get_option(self::REWRITE_OPTION, '')) {
            return;
        }
        \flush_rewrite_rules();
        \update_option(self::REWRITE_OPTION, self::REWRITE_VERSION, false);
    }

    private static function count_native_generated(string $namespace): int {
        $query = new \WP_Query([
            'post_type' => ['page', 'post'],
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query' => array_merge(['relation' => 'OR'], Compatibility::generated_marker_conditions($namespace)),
        ]);
        return (int) $query->found_posts;
    }

    private static function count(string $post_type): int {
        $counts = \wp_count_posts($post_type);
        $values = $counts ? get_object_vars($counts) : [];
        if (!empty($values)) {
            return array_sum(array_map('intval', $values));
        }

        global $wpdb;
        if (!isset($wpdb->posts) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            return 0;
        }

        $query = $wpdb->prepare(
            "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type = %s",
            $post_type
        );
        return (int) $wpdb->get_var($query);
    }
}
