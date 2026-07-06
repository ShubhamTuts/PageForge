<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

class Generator {
    public function bulk_generate(int $template_id, array $rows, string $status = 'draft'): array {
        $processed = 0; $errors = [];
        foreach ($rows as $row) {
            $res = $this->generate_one($template_id, $row, $status);
            if (is_wp_error($res)) {
                $errors[] = $res->get_error_message();
            } else {
                $processed++;
            }
        }
        return ['processed' => $processed, 'errors' => $errors];
    }

    public function generate_one(int $template_id, array $row, string $status = 'draft') {
        $tpl = \get_post($template_id);
        if (!$tpl || $tpl->post_type !== 'pageforge_template') return new \WP_Error('invalid_template', __('Invalid template', 'pageforge'));

        $s = settings_get();
        $post_type   = \get_post_meta($template_id, '_pageforge_post_type', true) ?: array_get($s,'post_type','pageforge_page');
        $title_pat   = \get_post_meta($template_id, '_pageforge_title_pattern', true) ?: '{Service} in {City}, {State}';
        $slug_pat    = \get_post_meta($template_id, '_pageforge_slug_pattern', true)  ?: '{service}-in-{city}-{state}';
        $base_post_id= (int) \get_post_meta($template_id, '_pageforge_base_post_id', true);
        $template_status = \sanitize_key((string) \get_post_meta($template_id, '_pageforge_post_status', true));
        if (in_array($template_status, ['publish', 'private', 'draft', 'pending'], true)) {
            $status = $template_status;
        } elseif (!in_array($status, ['publish', 'private', 'draft', 'pending'], true)) {
            $status = 'draft';
        }

        // Build title, slug, and content from template
        $title   = replace_placeholders($title_pat, $row);
        $slug    = build_slug($slug_pat, $row);
        $content = replace_placeholders($tpl->post_content, $row);
        $excerpt = replace_placeholders((string) $tpl->post_excerpt, $row);

        // If a base layout page is set, clone its content (builder-aware by replacement)
        $base = null;
        if ($base_post_id) {
            $base = \get_post($base_post_id);
            if ($base) {
                $content = replace_placeholders($base->post_content, $row);
                $excerpt = replace_placeholders((string) $base->post_excerpt, $row);
            }
        }

        // Duplicate prevention by slug
        $exists = \get_page_by_path($slug, OBJECT, $post_type);
        if ($exists) {
            return new \WP_Error(
                'duplicate',
                sprintf(
                    /* translators: %d: existing post ID */
                    __('Exists (ID: %d)', 'pageforge'),
                    (int) $exists->ID
                )
            );
        }

        // The free WordPress.org build does not inject AI articles into
        // generated SEO pages. Strip legacy markers if a template still uses them.
        if (strpos($content, '{AI:Article}') !== false) {
            $content = str_replace('{AI:Article}', '', $content);
        }

        // Optional Pexels image if placeholder present
        $thumbnail_id = 0;
        if (strpos($content, '{AI:Image}') !== false) {
            $pexels_key = array_get($s,'pexels_key');
            $keyword = trim(($row['Service'] ?? '') . ' ' . ($row['City'] ?? ''));
            $thumbnail_id = pexels_sideload($keyword, $pexels_key);
            $content = str_replace('{AI:Image}', '', $content);
        }

        // Insert post
        $postarr = [
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => $status,
            'post_type'    => $post_type,
            'comment_status' => 'closed',
        ];
        $post_id = \wp_insert_post($postarr, true);
        if (is_wp_error($post_id)) return $post_id;

        if ($thumbnail_id) \set_post_thumbnail($post_id, $thumbnail_id);
        if ($base_post_id && $base) {
            $this->clone_base_layout_meta($base_post_id, (int) $post_id, $row, (string) $post_type);
            if (!$thumbnail_id) {
                $base_thumbnail_id = (int) \get_post_thumbnail_id($base_post_id);
                if ($base_thumbnail_id) {
                    \set_post_thumbnail($post_id, $base_thumbnail_id);
                }
            }
            $this->refresh_builder_assets((int) $post_id);
        }

        // Basic SEO meta
        $service = $row['Service'] ?? $row['service'] ?? '';
        $city = $row['City'] ?? $row['city'] ?? '';
        $state = $row['State'] ?? $row['state'] ?? '';
        \update_post_meta($post_id, '_pageforge_city', $city);
        \update_post_meta($post_id, '_pageforge_state', $state);
        \update_post_meta($post_id, '_pageforge_service', $service);
        \update_post_meta($post_id, '_pageforge_template_id', $template_id);
        \update_post_meta($post_id, '_pageforge_generated_status', $status);
        if ($base_post_id && $base) {
            \update_post_meta($post_id, '_pageforge_base_post_id', $base_post_id);
        }
        // Store full variable map for robust runtime placeholder replacement
        $vars = [];
        foreach ($row as $k => $v) { $vars[$k] = \sanitize_text_field((string)$v); }
        \update_post_meta($post_id, '_pageforge_vars', $vars);
        $location = trim(implode(', ', array_filter([$city, $state])));
        $meta_title = ($service !== '' && $location !== '') ? trim($service . ' in ' . $location) : $title;
        $meta_desc = ($service !== '' && $city !== '')
            ? trim('Looking for ' . $service . ' in ' . $city . '? Get expert help' . ($state !== '' ? ' across ' . $state : '') . '.')
            : trim(wp_strip_all_tags($excerpt !== '' ? $excerpt : wp_trim_words(wp_strip_all_tags($content), 24, '')));
        if ($meta_desc === '') {
            $meta_desc = $meta_title;
        }
        \update_post_meta($post_id, '_pageforge_meta_title', $meta_title);
        \update_post_meta($post_id, '_pageforge_meta_description', $meta_desc);
        \update_post_meta($post_id, '_yoast_wpseo_title', $meta_title);
        \update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
        \update_post_meta($post_id, 'rank_math_title', $meta_title);
        \update_post_meta($post_id, 'rank_math_description', $meta_desc);

        // Track activity
        $activity = (array) \get_option('pageforge_activity', []);
        $activity[] = [ 'time' => time(), 'post_id' => $post_id, 'title' => get_the_title($post_id), 'status' => $status ];
        \update_option('pageforge_activity', array_slice($activity, -200));

        return $post_id;
    }

    private function clone_base_layout_meta(int $base_post_id, int $post_id, array $row, string $post_type): void {
        $all_meta = \get_post_meta($base_post_id);
        if (empty($all_meta) || !is_array($all_meta)) {
            return;
        }

        foreach ($all_meta as $key => $values) {
            $key = (string) $key;
            if (!$this->should_clone_base_meta($key)) {
                continue;
            }

            \delete_post_meta($post_id, $key);
            foreach ((array) $values as $value) {
                $value = \maybe_unserialize($value);
                $value = replace_placeholders_deep($value, $row);
                $value = $this->normalize_cloned_builder_meta($key, $value, $post_type);
                if ($value === null) {
                    continue;
                }
                \add_post_meta($post_id, $key, $this->prepare_meta_value_for_storage($key, $value));
            }
        }
    }

    private function should_clone_base_meta(string $key): bool {
        $skip = [
            '_elementor_css',
            '_elementor_inline_svg',
            '_edit_lock',
            '_edit_last',
        ];
        if (in_array($key, $skip, true)) {
            return false;
        }

        $exact = [
            '_wp_page_template',
            '_wpb_shortcodes_custom_css',
        ];
        if (in_array($key, $exact, true)) {
            return true;
        }

        $prefixes = [
            '_elementor_',
            '_et_',
        ];

        foreach ($prefixes as $prefix) {
            if (strpos($key, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    private function normalize_cloned_builder_meta(string $key, $value, string $post_type) {
        if ($key === '_elementor_template_type' && is_string($value) && in_array($value, ['wp-page', 'wp-post', 'page', 'section', 'container'], true)) {
            return in_array($post_type, ['page', 'pageforge_page'], true) ? 'wp-page' : 'wp-post';
        }

        if ($key === '_elementor_edit_mode' && empty($value)) {
            return 'builder';
        }

        return $value;
    }

    private function prepare_meta_value_for_storage(string $key, $value) {
        if ($key === '_elementor_data' && is_string($value)) {
            return \wp_slash($value);
        }

        return $value;
    }

    private function refresh_builder_assets(int $post_id): void {
        \delete_post_meta($post_id, '_elementor_css');

        if (class_exists('\\Elementor\\Core\\Files\\CSS\\Post')) {
            try {
                $css = new \Elementor\Core\Files\CSS\Post($post_id);
                if (method_exists($css, 'delete')) {
                    $css->delete();
                }
            } catch (\Throwable $e) {
                // Elementor will regenerate CSS on the next render/editor load.
            }
        }

        if (function_exists('et_core_page_resource_force_regenerate')) {
            try {
                $fn = new \ReflectionFunction('et_core_page_resource_force_regenerate');
                if ($fn->getNumberOfParameters() > 0) {
                    et_core_page_resource_force_regenerate($post_id);
                } else {
                    et_core_page_resource_force_regenerate();
                }
            } catch (\Throwable $e) {
                // Divi will rebuild static resources when it needs them.
            }
        }
    }
}
