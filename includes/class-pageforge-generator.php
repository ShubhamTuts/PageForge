<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

class Generator {
    /** Resolve the output once for admin, preflight and insertion. No writes. */
    public static function destination(int $template_id, array $row = []) {
        if (!Template::get($template_id)) {
            return new \WP_Error('invalid_template', __('Invalid template', 'pageforge'));
        }
        if (!Access::can_read_post($template_id)) {
            return new \WP_Error('forbidden_template', __('You cannot read this template.', 'pageforge'));
        }
        $saved = (string) Compatibility::read_meta($template_id, 'post_type', '');
        $requested = $saved !== '' ? $saved : (string) array_get(settings_get(), 'post_type', Compatibility::post_type('generated'));
        $type = $requested === '' ? '' : self::resolve_generated_post_type($requested);
        if ($type === '' || !\post_type_exists($type)) {
            return new \WP_Error('invalid_post_type', __('Select a valid generation target in the template or global settings.', 'pageforge'));
        }
        $parent_id = 0;
        $parent_path = '';
        if ($type === 'page') {
            $source = (string) Compatibility::read_meta($template_id, 'parent_source', 'static');
            if (!in_array($source, ['', 'none', 'static'], true)) {
                return new \WP_Error('premium_parent_mapping', __('Dynamic parent mapping requires licensed PageForge Premium. Select a static parent Page instead.', 'pageforge'));
            }
            $raw = $source === 'none' ? '0' : trim((string) Compatibility::read_meta($template_id, 'parent_page_id', '0'));
            if ($raw !== '' && !ctype_digit($raw)) {
                return new \WP_Error('invalid_parent', __('The configured parent must be an existing native Page you can edit.', 'pageforge'));
            }
            $parent_id = (int) $raw;
            $cursor = $parent_id;
            $seen = [];
            $parts = [];
            while ($cursor > 0) {
                $parent = \get_post($cursor);
                if (isset($seen[$cursor]) || count($seen) >= 100 || !$parent || $parent->post_type !== 'page'
                    || !in_array($parent->post_status, ['publish', 'private', 'draft', 'pending', 'future'], true)
                    || !\current_user_can('read_post', $cursor) || !\current_user_can('edit_post', $cursor)
                    || '' === (string) $parent->post_name) {
                    return new \WP_Error('invalid_parent', __('The configured parent must be an existing native Page you can edit, with a valid hierarchy and slug.', 'pageforge'));
                }
                $seen[$cursor] = true;
                array_unshift($parts, $parent->post_name);
                $cursor = (int) ($parent->post_parent ?? 0);
            }
            $parent_path = implode('/', $parts);
        }
        $slug = empty($row) ? '' : build_slug(Template::slug_pattern($template_id), $row);
        $path = trim($parent_path . '/' . $slug, '/');
        if (in_array($type, Compatibility::generated_post_types(), true)) {
            $object = \get_post_type_object($type);
            $rewrite = $object->rewrite ?? false;
            // A mixed-namespace site uses a shared resolver instead of per-CPT rewrites.
            $base = is_array($rewrite) ? (string) ($rewrite['slug'] ?? '') : '';
            if ('' === $base) $base = (string) array_get(settings_get(), 'cpt_base', Compatibility::namespace());
            $path = trim($base . '/' . $slug, '/');
        }
        return [
            'post_type' => $type, 'post_parent' => $parent_id, 'parent_path' => $parent_path,
            'expected_path' => '/' . $path . '/',
            'destination' => $type === 'page' ? __('Pages > All Pages', 'pageforge') : ($type === 'post' ? __('Posts > All Posts', 'pageforge') : __('PageForge content list', 'pageforge')),
            'type_source' => $saved !== '' ? 'template' : 'global',
        ];
    }

    public function bulk_generate(int $template_id, array $rows, string $status = 'draft'): array {
        $processed = 0; $errors = [];
        $limit = defined('PAGEFORGE_FREE_ROW_LIMIT') ? PAGEFORGE_FREE_ROW_LIMIT : 100;
        foreach (array_slice($rows, 0, $limit) as $index => $row) {
            $res = $this->generate_one($template_id, $row, $status);
            if (is_wp_error($res)) {
                /* translators: 1: CSV data row number, 2: error message. */
                $errors[] = sprintf(__('Row %1$d: %2$s', 'pageforge'), $index + 1, $res->get_error_message());
            } else {
                $processed++;
            }
        }
        if (count($rows) > $limit) {
            /* translators: %d: maximum rows per run in Free. */
            $errors[] = sprintf(__('Free processes at most %d rows per run. Split the remaining rows into another run.', 'pageforge'), $limit);
        }
        return ['processed' => $processed, 'errors' => $errors];
    }

    public function generate_one(int $template_id, array $row, string $status = 'draft') {
        $tpl = \get_post($template_id);
        if (!$tpl || !in_array($tpl->post_type, Compatibility::template_post_types(), true)) return new \WP_Error('invalid_template', __('Invalid template', 'pageforge'));

        $s = settings_get();
        $destination = self::destination($template_id, $row);
        if (\is_wp_error($destination)) return $destination;
        $post_type = $destination['post_type'];
        if (!Access::can_edit_post($template_id) || !Access::can_create($post_type)) {
            return new \WP_Error('forbidden_generation', __('You cannot generate content with this template and target type.', 'pageforge'));
        }
        $title_pat   = Compatibility::read_meta($template_id, 'title_pattern', '') ?: '{Service} in {City}, {State}';
        $meta_desc_pat = (string) Compatibility::read_meta($template_id, 'meta_description_pattern', '');
        $slug_pat    = Compatibility::read_meta($template_id, 'slug_pattern', '') ?: '{service}-in-{city}-{state}';
        $base_post_id= (int) Compatibility::read_meta($template_id, 'base_post_id', 0);
        $template_status = \sanitize_key((string) Compatibility::read_meta($template_id, 'default_status', ''));
        if (in_array($template_status, ['publish', 'private', 'draft', 'pending'], true)) {
            $status = $template_status;
        } elseif (!in_array($status, ['publish', 'private', 'draft', 'pending'], true)) {
            $status = 'draft';
        }
        $status = Access::clamp_status($post_type, $status);

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

        // Duplicate prevention by slug across every location generated pages can live in.
        if ('' === $slug) return new \WP_Error('empty_slug', __('The generated slug is empty. Check the slug pattern and row data.', 'pageforge'));
        $exists = $this->find_existing_generated_post($slug, (string) $post_type, $destination['post_parent'], $destination['parent_path']);
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
            'post_parent'  => $destination['post_parent'],
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
        Compatibility::write_meta($post_id, 'city', $city);
        Compatibility::write_meta($post_id, 'state', $state);
        Compatibility::write_meta($post_id, 'service', $service);
        Compatibility::write_meta($post_id, 'generated', 1);
        Compatibility::write_meta($post_id, 'template_id', $template_id);
        if ($base_post_id && $base) {
            Compatibility::write_meta($post_id, 'base_post_id', $base_post_id);
        }
        // Store full variable map for robust runtime placeholder replacement
        $vars = [];
        foreach ($row as $k => $v) { $vars[$k] = \sanitize_text_field((string)$v); }
        Compatibility::write_meta($post_id, 'vars', $vars);
        $location = trim(implode(', ', array_filter([$city, $state])));
        $meta_title = ($service !== '' && $location !== '') ? trim($service . ' in ' . $location) : $title;
        $meta_desc = trim(wp_strip_all_tags(replace_placeholders($meta_desc_pat, $row)));
        if ($meta_desc === '') {
            $meta_desc = ($service !== '' && $city !== '')
                ? trim('Looking for ' . $service . ' in ' . $city . '? Get expert help' . ($state !== '' ? ' across ' . $state : '') . '.')
                : trim(wp_strip_all_tags($excerpt !== '' ? $excerpt : wp_trim_words(wp_strip_all_tags($content), 24, '')));
        }
        if ($meta_desc === '') {
            $meta_desc = $meta_title;
        }
        Seo::write_generated((int) $post_id, [
            'title' => $meta_title,
            'description' => $meta_desc,
            'focus_keyword' => $service !== '' ? $service : $meta_title,
            'canonical' => (string) \get_permalink((int) $post_id),
            'og_title' => $meta_title,
            'og_description' => $meta_desc,
            'twitter_title' => $meta_title,
            'twitter_description' => $meta_desc,
        ]);

        // Track activity
        $activity = (array) \get_option('pageforge_activity', []);
        $activity[] = [ 'time' => time(), 'post_id' => $post_id, 'title' => get_the_title($post_id), 'status' => $status ];
        \update_option('pageforge_activity', array_slice($activity, -200));

        return $post_id;
    }

    /**
     * Compute exactly what generate_one() would produce, without inserting
     * anything. Shared by the admin preview UI and the MCP preview/validate/
     * dry-run tools so there is exactly one place that decides what a row
     * becomes.
     *
     * @return array{
     *   title:string, slug:string, content:string, excerpt:string,
     *   post_type:string, status:string, meta_title:string,
     *   meta_description:string, duplicate_of:int, vars:array<string,string>
     * }|\WP_Error
     */
    public function preview_one(int $template_id, array $row, string $status = 'draft') {
        $tpl = \get_post($template_id);
        if (!$tpl || !in_array($tpl->post_type, Compatibility::template_post_types(), true)) {
            return new \WP_Error('invalid_template', __('Invalid template', 'pageforge'));
        }

        $s = settings_get();
        $destination = self::destination($template_id, $row);
        if (\is_wp_error($destination)) return $destination;
        $post_type = $destination['post_type'];
        if (!Access::can_edit_post($template_id) || !Access::can_create($post_type)) {
            return new \WP_Error('forbidden_generation', __('You cannot generate content with this template and target type.', 'pageforge'));
        }
        $title_pat = Compatibility::read_meta($template_id, 'title_pattern', '') ?: '{Service} in {City}, {State}';
        $meta_desc_pat = (string) Compatibility::read_meta($template_id, 'meta_description_pattern', '');
        $slug_pat = Compatibility::read_meta($template_id, 'slug_pattern', '') ?: '{service}-in-{city}-{state}';
        $base_post_id = (int) Compatibility::read_meta($template_id, 'base_post_id', 0);
        $template_status = \sanitize_key((string) Compatibility::read_meta($template_id, 'default_status', ''));
        if (in_array($template_status, ['publish', 'private', 'draft', 'pending'], true)) {
            $status = $template_status;
        } elseif (!in_array($status, ['publish', 'private', 'draft', 'pending'], true)) {
            $status = 'draft';
        }

        $title = replace_placeholders($title_pat, $row);
        $slug = build_slug($slug_pat, $row);
        $content = replace_placeholders($tpl->post_content, $row);
        $excerpt = replace_placeholders((string) $tpl->post_excerpt, $row);

        if ($base_post_id) {
            $base = \get_post($base_post_id);
            if ($base) {
                $content = replace_placeholders($base->post_content, $row);
                $excerpt = replace_placeholders((string) $base->post_excerpt, $row);
            }
        }

        $status = Access::clamp_status($post_type, $status);
        if ('' === $slug) return new \WP_Error('empty_slug', __('The generated slug is empty. Check the slug pattern and row data.', 'pageforge'));
        $existing = $this->find_existing_generated_post($slug, (string) $post_type, $destination['post_parent'], $destination['parent_path']);

        $service = $row['Service'] ?? $row['service'] ?? '';
        $city = $row['City'] ?? $row['city'] ?? '';
        $state = $row['State'] ?? $row['state'] ?? '';
        $location = trim(implode(', ', array_filter([$city, $state])));
        $meta_title = ($service !== '' && $location !== '') ? trim($service . ' in ' . $location) : $title;
        $meta_desc = trim(wp_strip_all_tags(replace_placeholders($meta_desc_pat, $row)));
        if ($meta_desc === '') {
            $meta_desc = ($service !== '' && $city !== '')
                ? trim('Looking for ' . $service . ' in ' . $city . '? Get expert help' . ($state !== '' ? ' across ' . $state : '') . '.')
                : trim(wp_strip_all_tags($excerpt !== '' ? $excerpt : wp_trim_words(wp_strip_all_tags($content), 24, '')));
        }
        if ($meta_desc === '') {
            $meta_desc = $meta_title;
        }

        $vars = [];
        foreach ($row as $k => $v) {
            $vars[(string) $k] = \sanitize_text_field((string) $v);
        }

        return [
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'excerpt' => $excerpt,
            'post_type' => (string) $post_type,
            'post_parent' => $destination['post_parent'],
            'parent_path' => $destination['parent_path'],
            'expected_path' => $destination['expected_path'],
            'destination' => $destination['destination'],
            'type_source' => $destination['type_source'],
            'status' => $status,
            'meta_title' => $meta_title,
            'meta_description' => $meta_desc,
            'duplicate_of' => $existing ? (int) $existing->ID : 0,
            'vars' => $vars,
        ];
    }

    /**
     * Preflight a set of rows against one template: which variables are
     * referenced but missing per row, which rows collide on slug with each
     * other or with existing content, and which rows have no usable data at
     * all. Used by both the admin campaign screen and the MCP validate tool.
     *
     * @return array{
     *   template_id:int, row_count:int,
     *   unresolved_variables: array<int,array{row:int,variables:string[]}>,
     *   duplicate_slugs: array<int,array{row:int,slug:string,conflicts_with:int|string}>,
     *   missing_data: array<int,int>,
     *   safe_to_generate: bool
     * }|\WP_Error
     */
    public function validate_rows(int $template_id, array $rows): array {
        $tpl = \get_post($template_id);
        if (!$tpl || !in_array($tpl->post_type, Compatibility::template_post_types(), true)) {
            return [
                'template_id' => $template_id,
                'row_count' => 0,
                'unresolved_variables' => [],
                'duplicate_slugs' => [],
                'missing_data' => [],
                'safe_to_generate' => false,
                'error' => __('Invalid template', 'pageforge'),
            ];
        }

        $title_pat = (string) (Compatibility::read_meta($template_id, 'title_pattern', '') ?: '{Service} in {City}, {State}');
        $meta_desc_pat = (string) Compatibility::read_meta($template_id, 'meta_description_pattern', '');
        $slug_pat = (string) (Compatibility::read_meta($template_id, 'slug_pattern', '') ?: '{service}-in-{city}-{state}');
        preg_match_all('/\{([A-Za-z0-9_]+)\}/', $title_pat . ' ' . $meta_desc_pat . ' ' . $slug_pat . ' ' . (string) $tpl->post_content, $matches);
        $referenced = array_values(array_unique($matches[1] ?? []));

        $unresolved_variables = [];
        $duplicate_slugs = [];
        $missing_data = [];
        $seen_slugs = [];
        $destination_errors = [];
        $destinations = [];

        foreach ($rows as $index => $row) {
            $row = is_array($row) ? $row : [];
            if (empty(array_filter($row, static function ($v) { return trim((string) $v) !== ''; }))) {
                $missing_data[] = $index;
            }

            $lower_row = array_change_key_case($row, CASE_LOWER);
            $missing = [];
            foreach ($referenced as $variable) {
                if (!array_key_exists($variable, $row) && !array_key_exists(strtolower($variable), $lower_row)) {
                    $missing[] = $variable;
                }
            }
            if (!empty($missing)) {
                $unresolved_variables[] = ['row' => $index, 'variables' => $missing];
            }

            $destination = self::destination($template_id, $row);
            if (\is_wp_error($destination)) {
                $destination_errors[] = ['row' => $index, 'message' => $destination->get_error_message()];
                continue;
            }
            if (!Access::can_edit_post($template_id) || !Access::can_create($destination['post_type'])) {
                $destination_errors[] = ['row' => $index, 'message' => __('You cannot generate content with this template and target type.', 'pageforge')];
                continue;
            }
            $destinations[] = array_merge(['row' => $index], $destination);
            $slug = build_slug($slug_pat, $row);
            if ('' === $slug) {
                $destination_errors[] = ['row' => $index, 'message' => __('The generated slug is empty. Check the slug pattern and row data.', 'pageforge')];
                continue;
            }
            $key = $destination['post_type'] . ':' . $destination['post_parent'] . ':' . $slug;
            if (isset($seen_slugs[$key])) {
                $duplicate_slugs[] = ['row' => $index, 'slug' => $slug, 'conflicts_with' => $seen_slugs[$key]];
                continue;
            }
            $seen_slugs[$key] = $index;

            $existing = $this->find_existing_generated_post($slug, $destination['post_type'], $destination['post_parent'], $destination['parent_path']);
            if ($existing) {
                $duplicate_slugs[] = ['row' => $index, 'slug' => $slug, 'conflicts_with' => (int) $existing->ID];
            }
        }

        return [
            'template_id' => $template_id,
            'row_count' => count($rows),
            'unresolved_variables' => $unresolved_variables,
            'duplicate_slugs' => $duplicate_slugs,
            'missing_data' => $missing_data,
            'destination_errors' => $destination_errors,
            'destinations' => $destinations,
            'safe_to_generate' => empty($duplicate_slugs) && empty($missing_data) && empty($destination_errors),
        ];
    }

    /**
     * Templates authored under either namespace may name the other namespace's
     * generated CPT. Only that CPT is translated, so a template targeting
     * "page" or "post" keeps writing to native content.
     */
    public static function resolve_generated_post_type(string $post_type): string {
        if ('' === $post_type) {
            return Compatibility::post_type('generated');
        }
        if (in_array($post_type, Compatibility::generated_post_types(), true)) {
            return Compatibility::post_type('generated');
        }
        return in_array($post_type, ['page', 'post'], true) ? $post_type : '';
    }

    /**
     * Generated pages can live in the selected CPT, the legacy CPT, or a native
     * post type. A slug match in any of those is a duplicate, so retries and
     * re-imports never create a second copy.
     */
    private function find_existing_generated_post(string $slug, string $post_type, int $parent_id = 0, string $parent_path = '') {
        if ('' === $slug) {
            return null;
        }
        if ($post_type === 'page') {
            // Native Pages collide by complete hierarchy, never by leaf alone.
            $path = trim($parent_path . '/' . $slug, '/');
            $existing = \get_page_by_path($path, OBJECT, 'page');
            if ($existing) return $existing;
            if ((string) \get_option('permalink_structure', '') !== '') {
                $post = \get_page_by_path($slug, OBJECT, 'post');
                if ($post && rtrim((string) \get_permalink($post->ID), '/') === rtrim(\home_url('/' . $path . '/'), '/')) return $post;
            }
            return null;
        }
        if ($post_type === 'post') {
            $structure = (string) \get_option('permalink_structure', '');
            $path = str_replace('%postname%', $slug, $structure);
            if ($path !== '' && strpos($path, '%') === false) {
                $page = \get_page_by_path(trim($path, '/'), OBJECT, 'page');
                if ($page && rtrim((string) \get_permalink($page->ID), '/') === rtrim(\home_url($path), '/')) return $page;
            }
        }

        $types = array_values(array_unique(array_merge(
            [$post_type],
            Compatibility::generated_post_types()
        )));
        foreach ($types as $type) {
            $existing = \get_page_by_path($slug, OBJECT, $type);
            if ($existing) {
                return $existing;
            }
        }

        foreach (['page', 'post'] as $native) {
            if (in_array($native, $types, true)) {
                continue;
            }
            $existing = \get_page_by_path($slug, OBJECT, $native);
            if ($existing && Compatibility::has_generated_marker((int) $existing->ID)) {
                return $existing;
            }
        }

        return null;
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
