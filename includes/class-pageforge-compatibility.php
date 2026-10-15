<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

final class Compatibility {
    private const POST_TYPES = [
        'template' => ['pageforge' => 'pageforge_template', 'pf' => 'pf_template'],
        'generated' => ['pageforge' => 'pageforge_page', 'pf' => 'pf_page'],
    ];

    private const META = [
        'generated' => ['pageforge' => ['_pageforge_generated', '_pageforge_generated_status'], 'pf' => ['_pf_generated']],
        'template_id' => ['pageforge' => ['_pageforge_template_id', '_pageforge_template'], 'pf' => ['_pf_template_id', '_pf_template']],
        'vars' => ['pageforge' => ['_pageforge_vars'], 'pf' => ['_pf_vars']],
        'city' => ['pageforge' => ['_pageforge_city'], 'pf' => ['_pf_city']],
        'state' => ['pageforge' => ['_pageforge_state'], 'pf' => ['_pf_state']],
        'service' => ['pageforge' => ['_pageforge_service'], 'pf' => ['_pf_service']],
        'title_pattern' => ['pageforge' => ['_pageforge_title_pattern'], 'pf' => ['_pf_title_pattern']],
        'meta_description_pattern' => ['pageforge' => ['_pageforge_meta_description_pattern'], 'pf' => ['_pf_meta_description_pattern']],
        'slug_pattern' => ['pageforge' => ['_pageforge_slug_pattern'], 'pf' => ['_pf_slug_pattern']],
        'post_type' => ['pageforge' => ['_pageforge_post_type'], 'pf' => ['_pf_post_type']],
        'base_post_id' => ['pageforge' => ['_pageforge_base_post_id'], 'pf' => ['_pf_base_post_id']],
        'parent_page_id' => ['pageforge' => ['_pageforge_parent_page_id'], 'pf' => ['_pf_parent_page_id']],
        'parent_source' => ['pageforge' => ['_pageforge_parent_source'], 'pf' => ['_pf_parent_source']],
        'parent_column' => ['pageforge' => ['_pageforge_parent_column'], 'pf' => ['_pf_parent_column']],
        'meta_title' => ['pageforge' => ['_pageforge_meta_title'], 'pf' => ['_pf_meta_title']],
        'meta_description' => ['pageforge' => ['_pageforge_meta_description'], 'pf' => ['_pf_meta_description']],
        'focus_keyword' => ['pageforge' => ['_pageforge_focus_keyword'], 'pf' => ['_pf_focus_keyword']],
        'canonical' => ['pageforge' => ['_pageforge_canonical'], 'pf' => ['_pf_canonical']],
        'generated_canonical_url' => ['pageforge' => ['_pageforge_generated_canonical_url'], 'pf' => ['_pf_generated_canonical_url']],
        'og_title' => ['pageforge' => ['_pageforge_og_title'], 'pf' => ['_pf_og_title']],
        'og_description' => ['pageforge' => ['_pageforge_og_description'], 'pf' => ['_pf_og_description']],
        'twitter_title' => ['pageforge' => ['_pageforge_twitter_title'], 'pf' => ['_pf_twitter_title']],
        'twitter_description' => ['pageforge' => ['_pageforge_twitter_description'], 'pf' => ['_pf_twitter_description']],
        'noindex' => ['pageforge' => ['_pageforge_noindex'], 'pf' => ['_pf_noindex']],
        'nofollow' => ['pageforge' => ['_pageforge_nofollow'], 'pf' => ['_pf_nofollow']],
        'row_fingerprint' => ['pageforge' => ['_pageforge_row_fingerprint'], 'pf' => ['_pf_row_fingerprint']],
        'default_status' => ['pageforge' => ['_pageforge_default_status', '_pageforge_post_status'], 'pf' => ['_pf_default_status']],
    ];

    public static function template_post_types(): array {
        return array_values(self::POST_TYPES['template']);
    }

    public static function generated_post_types(): array {
        return array_values(self::POST_TYPES['generated']);
    }

    public static function generated_marker_conditions(?string $namespace = null): array {
        $conditions = [
            'pageforge' => [
                ['key' => '_pageforge_generated', 'value' => '1'],
                [
                    'key' => '_pageforge_generated_status',
                    'value' => ['publish', 'draft', 'pending', 'private'],
                    'compare' => 'IN',
                ],
            ],
            'pf' => [
                ['key' => '_pf_generated', 'value' => '1'],
            ],
        ];
        return isset($conditions[$namespace])
            ? $conditions[$namespace]
            : array_merge($conditions['pageforge'], $conditions['pf']);
    }

    public static function has_generated_marker(int $post_id, ?string $namespace = null): bool {
        foreach (self::generated_marker_conditions($namespace) as $condition) {
            $key = (string) $condition['key'];
            if (!\metadata_exists('post', $post_id, $key)) continue;
            $value = \get_post_meta($post_id, $key, true);
            if (($condition['compare'] ?? '') === 'IN') {
                if (in_array((string) $value, array_map('strval', (array) $condition['value']), true)) return true;
            } elseif ((string) $value === (string) $condition['value']) {
                return true;
            }
        }
        return false;
    }

    public static function meta_keys(string $logical): array {
        if (!isset(self::META[$logical])) return [];
        $selected = self::namespace();
        $alternate = $selected === 'pageforge' ? 'pf' : 'pageforge';
        return array_merge(self::META[$logical][$selected], self::META[$logical][$alternate]);
    }

    public static function select_namespace(array $counts): string {
        if ((int) ($counts['pageforge_templates'] ?? 0) > 0 || (int) ($counts['pageforge_pages'] ?? 0) > 0
            || (int) ($counts['pageforge_native_pages'] ?? 0) > 0) {
            return 'pageforge';
        }
        if ((int) ($counts['pf_templates'] ?? 0) > 0 || (int) ($counts['pf_pages'] ?? 0) > 0
            || (int) ($counts['pf_native_pages'] ?? 0) > 0) {
            return 'pf';
        }
        return 'pageforge';
    }

    public static function namespace(): string {
        $stored = (string) \get_option('pageforge_content_namespace', '');
        return in_array($stored, ['pageforge', 'pf'], true) ? $stored : 'pageforge';
    }

    public static function post_type(string $kind, ?string $namespace = null): string {
        $namespace = $namespace ?: self::namespace();
        return self::POST_TYPES[$kind][$namespace] ?? '';
    }

    public static function read_meta(int $post_id, string $logical, $default = '') {
        foreach (self::meta_keys($logical) as $key) {
            if (\metadata_exists('post', $post_id, $key)) {
                return \get_post_meta($post_id, $key, true);
            }
        }
        return $default;
    }

    public static function write_meta(int $post_id, string $logical, $value): bool {
        $keys = self::META[$logical][self::namespace()] ?? [];
        $key = $keys[0] ?? '';
        if ($key === '') return false;
        if (\metadata_exists('post', $post_id, $key) && \get_post_meta($post_id, $key, true) === $value) return true;
        return false !== \update_post_meta($post_id, $key, $value);
    }
}
