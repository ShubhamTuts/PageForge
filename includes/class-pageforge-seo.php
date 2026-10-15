<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * SEO integration adapter.
 *
 * WordPress has no canonical place for an SEO title. Yoast, Rank Math, SEOPress
 * and AIOSEO each keep their own post meta, and whichever is active owns what the
 * front end renders. PageForge therefore needs one place that knows:
 *
 *   - which integration is active (`active()`)
 *   - how to read a normalised view of a post's SEO data (`read()`)
 *   - how to write through the *active* integration's keys (`write()`)
 *
 * Writing to the wrong keys is the failure this prevents. If Rank Math owns
 * rendering and PageForge only writes Yoast meta, the operator sees "saved" and
 * nothing changes on the page. Conversely, PageForge must not render its own
 * title/description tags when another plugin already does, or every page ships
 * two of each.
 *
 * Reads are deliberately shallow: enough to answer "is this page's SEO set up
 * properly", not a dump of a third-party plugin's private state.
 */
final class Seo {
    public const YOAST = 'yoast';
    public const RANK_MATH = 'rank_math';
    public const SEOPRESS = 'seopress';
    public const AIOSEO = 'aioseo';
    public const NATIVE = 'pageforge';

    /**
     * Meta keys per integration, keyed by the normalised field name.
     *
     * Only post-meta-backed fields are listed here. AIOSEO keeps its data in a
     * custom table and is handled through its registered WordPress abilities,
     * with its post SEO service as a compatibility fallback on older Core.
     *
     * @return array<string, array<string, string>>
     */
    public static function key_map(): array {
        return [
            self::YOAST => [
                'title' => '_yoast_wpseo_title',
                'description' => '_yoast_wpseo_metadesc',
                'focus_keyword' => '_yoast_wpseo_focuskw',
                'canonical' => '_yoast_wpseo_canonical',
                'og_title' => '_yoast_wpseo_opengraph-title',
                'og_description' => '_yoast_wpseo_opengraph-description',
                'twitter_title' => '_yoast_wpseo_twitter-title',
                'twitter_description' => '_yoast_wpseo_twitter-description',
                'noindex' => '_yoast_wpseo_meta-robots-noindex',
                'nofollow' => '_yoast_wpseo_meta-robots-nofollow',
            ],
            self::RANK_MATH => [
                'title' => 'rank_math_title',
                'description' => 'rank_math_description',
                'focus_keyword' => 'rank_math_focus_keyword',
                'canonical' => 'rank_math_canonical_url',
                'og_title' => 'rank_math_facebook_title',
                'og_description' => 'rank_math_facebook_description',
                'twitter_title' => 'rank_math_twitter_title',
                'twitter_description' => 'rank_math_twitter_description',
                'robots' => 'rank_math_robots',
            ],
            self::SEOPRESS => [
                'title' => '_seopress_titles_title',
                'description' => '_seopress_titles_desc',
                'focus_keyword' => '_seopress_analysis_target_kw',
                'canonical' => '_seopress_robots_canonical',
                'og_title' => '_seopress_social_fb_title',
                'og_description' => '_seopress_social_fb_desc',
                'twitter_title' => '_seopress_social_twitter_title',
                'twitter_description' => '_seopress_social_twitter_desc',
                'noindex' => '_seopress_robots_index',
                'nofollow' => '_seopress_robots_follow',
            ],
            self::AIOSEO => [
                // Custom-table integration; see read_aioseo() and write_aioseo().
            ],
            self::NATIVE => [
                'title' => '_pageforge_meta_title',
                'description' => '_pageforge_meta_description',
                'focus_keyword' => '_pageforge_focus_keyword',
                'canonical' => '_pageforge_canonical',
                'og_title' => '_pageforge_og_title',
                'og_description' => '_pageforge_og_description',
                'twitter_title' => '_pageforge_twitter_title',
                'twitter_description' => '_pageforge_twitter_description',
                'noindex' => '_pageforge_noindex',
                'nofollow' => '_pageforge_nofollow',
            ],
        ];
    }

    /**
     * Which integration currently owns front-end SEO rendering.
     *
     * Detection order matters only when two are installed at once, which is a
     * misconfiguration; the first match wins and the rest are reported by
     * `detected()` so the UI can warn.
     */
    public static function active(): string {
        foreach (self::detected() as $integration) {
            return $integration;
        }
        return self::NATIVE;
    }

    /**
     * Every SEO plugin detected as active, in precedence order.
     *
     * @return string[]
     */
    public static function detected(): array {
        $found = [];
        if (defined('WPSEO_VERSION')) {
            $found[] = self::YOAST;
        }
        if (defined('RANK_MATH_VERSION') || class_exists('\\RankMath')) {
            $found[] = self::RANK_MATH;
        }
        if (defined('SEOPRESS_VERSION') || function_exists('seopress_get_service')) {
            $found[] = self::SEOPRESS;
        }
        if (defined('AIOSEO_VERSION') || class_exists('\\AIOSEO\\Plugin')) {
            $found[] = self::AIOSEO;
        }
        $found = (array) \apply_filters('pageforge_seo_detected_integrations', $found);
        return array_values(array_intersect(
            [self::YOAST, self::RANK_MATH, self::SEOPRESS, self::AIOSEO],
            array_unique(array_map('strval', $found))
        ));
    }

    public static function label(string $integration): string {
        $labels = [
            self::YOAST => 'Yoast SEO',
            self::RANK_MATH => 'Rank Math',
            self::SEOPRESS => 'SEOPress',
            self::AIOSEO => 'All in One SEO',
            self::NATIVE => 'PageForge (built-in)',
        ];
        return $labels[$integration] ?? $integration;
    }

    /**
     * Whether PageForge may render its own title/description tags.
     *
     * False whenever another plugin owns rendering, which is what stops a page
     * shipping two titles and two descriptions.
     */
    public static function pageforge_owns_output(): bool {
        return empty(self::detected());
    }

    /**
     * Whether PageForge has a supported read/write path for this integration.
     */
    public static function is_writable(string $integration): bool {
        if (self::AIOSEO === $integration) {
            if (self::aioseo_ability('seo-data-get') && self::aioseo_ability('seo-data-update')) {
                return true;
            }
            $class = self::aioseo_service_class();
            return class_exists($class)
                && method_exists($class, 'getSeoData')
                && method_exists($class, 'updateSeoData');
        }
        return !empty(self::key_map()[$integration] ?? []);
    }

    /**
     * Read a normalised SEO view of one post.
     *
     * Always includes PageForge's own values alongside the active integration's,
     * because a site can hold both and an operator debugging a discrepancy needs
     * to see the difference rather than a merged guess.
     *
     * @return array
     */
    public static function read(int $post_id): array {
        $post = \get_post($post_id);
        if (!$post) {
            return ['error' => 'post_not_found'];
        }

        $active = self::active();
        $fields = self::read_fields($post_id, $active);
        $native = self::NATIVE === $active ? $fields : self::read_fields($post_id, self::NATIVE);

        $title = (string) ($fields['title'] ?? '');
        $description = (string) ($fields['description'] ?? '');

        return [
            'post_id' => $post_id,
            'post_type' => (string) $post->post_type,
            'post_status' => (string) $post->post_status,
            'permalink' => (string) \get_permalink($post_id),
            'integration' => $active,
            'integration_label' => self::label($active),
            'integration_writable' => self::is_writable($active),
            'pageforge_renders_tags' => self::pageforge_owns_output(),
            'fields' => $fields,
            'pageforge_fields' => $native,
            // Computed so a client does not have to re-derive the same rules.
            'analysis' => self::analyse_fields($post, $title, $description),
        ];
    }

    /**
     * @return array<string, string|null> null means "this integration does not
     *                                    expose that field through post meta".
     */
    public static function read_fields(int $post_id, string $integration): array {
        if (self::AIOSEO === $integration) {
            return self::read_aioseo($post_id);
        }
        $map = self::key_map()[$integration] ?? [];
        if (empty($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $field => $key) {
            $value = \get_post_meta($post_id, $key, true);
            if (self::RANK_MATH === $integration && 'robots' === $field) {
                $robots = is_array($value) ? array_map('strval', $value) : [];
                $out['noindex'] = in_array('noindex', $robots, true);
                $out['nofollow'] = in_array('nofollow', $robots, true);
                continue;
            }
            if (in_array($field, ['noindex', 'nofollow'], true)) {
                $out[$field] = self::normalise_boolean($value);
                continue;
            }
            $out[$field] = is_scalar($value) ? (string) $value : '';
        }
        return $out;
    }

    /**
     * Field-level assessment. Thresholds follow common search-result truncation
     * points rather than any single plugin's opinion, and are reported as facts
     * (lengths) plus flags so a client can explain itself to a user.
     */
    private static function analyse_fields(\WP_Post $post, string $title, string $description): array {
        $effective_title = '' !== $title ? $title : (string) $post->post_title;
        $title_length = self::text_length($effective_title);
        $description_length = self::text_length($description);

        $issues = [];
        if ('' === $title) {
            $issues[] = 'missing_seo_title';
        } elseif ($title_length > 60) {
            $issues[] = 'seo_title_too_long';
        } elseif ($title_length < 20) {
            $issues[] = 'seo_title_too_short';
        }

        if ('' === $description) {
            $issues[] = 'missing_meta_description';
        } elseif ($description_length > 160) {
            $issues[] = 'meta_description_too_long';
        } elseif ($description_length < 70) {
            $issues[] = 'meta_description_too_short';
        }

        // Unresolved PageForge variables in metadata are a generation fault, not
        // a copywriting one, so they are called out separately.
        if (self::has_unresolved_variables($title) || self::has_unresolved_variables($description)) {
            $issues[] = 'unresolved_variables_in_metadata';
        }

        return [
            'seo_title' => $effective_title,
            'seo_title_length' => $title_length,
            'meta_description_length' => $description_length,
            'has_seo_title' => '' !== $title,
            'has_meta_description' => '' !== $description,
            'issues' => $issues,
        ];
    }

    /**
     * An unresolved `{Column}` token, or a PageForge shortcode that never ran,
     * left in rendered metadata.
     */
    public static function has_unresolved_variables(string $text): bool {
        if ('' === $text) {
            return false;
        }
        if (1 === preg_match('/\{[A-Za-z0-9_ -]{1,40}\}/', $text)) {
            return true;
        }
        return 1 === preg_match('/\[(?:pageforge|pf)\s+var=/i', $text);
    }

    /** Multibyte-safe so a non-Latin title is not reported as over-length. */
    public static function text_length(string $text): int {
        $text = trim(\wp_strip_all_tags($text));
        if (function_exists(__NAMESPACE__ . '\\pf_utf8_strlen')) {
            return pf_utf8_strlen($text);
        }
        if (function_exists('mb_strlen')) {
            return (int) \mb_strlen($text, 'UTF-8');
        }
        if (false !== preg_match_all('/./us', $text, $matches)) {
            return count($matches[0]);
        }
        return strlen($text);
    }

    /**
     * Write SEO fields through the active integration.
     *
     * Two rules are enforced here rather than at every call site:
     *
     *  1. Object authorization. A write reaches post meta, so the acting user
     *     must be allowed to edit that specific post.
     *  2. The active integration's keys are always written. PageForge's own keys
     *     are written too, so switching SEO plugin later does not lose the data,
     *     but the active plugin is what determines what the page renders.
     *
     * @param array<string, mixed> $fields Normalised field names.
     * @return array|\WP_Error Applied values per integration.
     */
    public static function write_generated(int $post_id, array $fields) {
        // A generated self-canonical is automatic, not a permanent override.
        // SEO plugins use their current permalink when the override is blank.
        $fields['canonical'] = '';
        $result = self::write($post_id, $fields);
        if (\is_wp_error($result)) return $result;
        $canonical = (string) \get_permalink($post_id);
        Compatibility::write_meta($post_id, 'canonical', $canonical);
        Compatibility::write_meta($post_id, 'generated_canonical_url', $canonical);
        return $result;
    }

    public static function write(int $post_id, array $fields) {
        if (!Access::can_edit_post($post_id)) {
            return new \WP_Error('cannot_edit', __('You cannot edit that content.', 'pageforge'));
        }

        $text_fields = [
            'title',
            'description',
            'focus_keyword',
            'canonical',
            'og_title',
            'og_description',
            'twitter_title',
            'twitter_description',
        ];
        $boolean_fields = ['noindex', 'nofollow'];
        $clean = [];
        foreach ($text_fields as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $value = (string) $fields[$field];
            // Metadata is plain text. Stripping tags here means a prompt
            // injection that returns markup cannot become stored HTML.
            $value = \wp_strip_all_tags($value);
            if ('canonical' === $field) {
                $clean[$field] = \esc_url_raw($value);
                continue;
            }
            $clean[$field] = in_array($field, ['description', 'og_description', 'twitter_description'], true)
                ? \sanitize_textarea_field($value)
                : \sanitize_text_field($value);
        }
        foreach ($boolean_fields as $field) {
            if (array_key_exists($field, $fields)) {
                $clean[$field] = self::normalise_boolean($fields[$field]);
            }
        }

        if (empty($clean)) {
            return new \WP_Error('nothing_to_write', __('No supported SEO fields were supplied.', 'pageforge'));
        }

        $applied = [];
        // The active integration first: it is what the front end reads.
        foreach (array_unique(array_merge(self::detected(), [self::NATIVE])) as $integration) {
            if (self::AIOSEO === $integration) {
                $applied[$integration] = self::write_aioseo($post_id, $clean);
                continue;
            }
            $map = self::key_map()[$integration] ?? [];
            if (empty($map)) {
                $applied[$integration] = ['written' => false, 'reason' => 'unsupported_integration'];
                continue;
            }
            $written = [];
            foreach ($clean as $field => $value) {
                if (self::RANK_MATH === $integration && in_array($field, $boolean_fields, true)) {
                    continue;
                }
                if (!isset($map[$field])) {
                    continue;
                }
                if (in_array($field, $boolean_fields, true)) {
                    if ($value) {
                        $stored = self::SEOPRESS === $integration ? 'yes' : '1';
                        \update_post_meta($post_id, $map[$field], $stored);
                    } else {
                        \delete_post_meta($post_id, $map[$field]);
                    }
                } else {
                    \update_post_meta($post_id, $map[$field], $value);
                }
                $written[$field] = $value;
            }
            if (self::RANK_MATH === $integration && isset($map['robots'])) {
                $robots = \get_post_meta($post_id, $map['robots'], true);
                $robots = is_array($robots) ? array_values(array_unique(array_map('strval', $robots))) : [];
                foreach ($boolean_fields as $field) {
                    if (!array_key_exists($field, $clean)) {
                        continue;
                    }
                    $robots = array_values(array_filter($robots, static function ($directive) use ($field) {
                        return $directive !== $field;
                    }));
                    if ($clean[$field]) {
                        $robots[] = $field;
                    }
                    $written[$field] = $clean[$field];
                }
                $robots = array_values(array_unique($robots));
                if (empty($robots)) {
                    \delete_post_meta($post_id, $map['robots']);
                } else {
                    \update_post_meta($post_id, $map['robots'], $robots);
                }
            }
            $applied[$integration] = ['written' => true, 'fields' => $written];
        }

        // PageForge's namespaced copy, so a legacy or mixed site stays coherent.
        if (isset($clean['title'])) {
            Compatibility::write_meta($post_id, 'meta_title', $clean['title']);
        }
        if (isset($clean['description'])) {
            Compatibility::write_meta($post_id, 'meta_description', $clean['description']);
        }
        if (isset($clean['focus_keyword'])) {
            Compatibility::write_meta($post_id, 'focus_keyword', $clean['focus_keyword']);
        }
        foreach (['canonical', 'og_title', 'og_description', 'twitter_title', 'twitter_description', 'noindex', 'nofollow'] as $field) {
            if (array_key_exists($field, $clean)) {
                Compatibility::write_meta($post_id, $field, $clean[$field]);
            }
        }

        $active = self::active();
        $write_integration = !empty($applied[$active]['written']) ? $active : self::NATIVE;
        $warning = '';
        if ($active !== $write_integration) {
            $warning = __('The active SEO plugin does not expose a supported direct write path, so PageForge saved its built-in fallback metadata. Review the post in your SEO plugin before publishing.', 'pageforge');
        }

        return [
            'post_id' => $post_id,
            'active_integration' => $active,
            'write_integration' => $write_integration,
            'write_integration_label' => self::label($write_integration),
            'warning' => $warning,
            'applied' => $applied,
        ];
    }

    /** AIOSEO 4.9.8+ WordPress ability, when registered on current Core. */
    private static function aioseo_ability(string $operation) {
        if (!function_exists('wp_get_ability')) {
            return null;
        }
        $ability = \wp_get_ability('aioseo-posts/' . $operation);
        return is_object($ability) && method_exists($ability, 'execute') ? $ability : null;
    }

    /** AIOSEO post SEO service fallback for WordPress without Abilities. */
    private static function aioseo_service_class(): string {
        return (string) \apply_filters(
            'pageforge_aioseo_service_class',
            '\\AIOSEO\\Plugin\\Common\\Services\\PostSeoService'
        );
    }

    /** @return array<string, mixed> */
    private static function read_aioseo(int $post_id): array {
        if (!self::is_writable(self::AIOSEO)) {
            return [];
        }

        try {
            $ability = self::aioseo_ability('seo-data-get');
            if ($ability) {
                $result = $ability->execute(['postId' => $post_id]);
            } else {
                $class = self::aioseo_service_class();
                $result = (new $class())->getSeoData($post_id);
            }
        } catch (\Throwable $exception) {
            return [];
        }
        if (\is_wp_error($result) || !is_array($result)) {
            return [];
        }

        $robots = isset($result['robots']) && is_array($result['robots']) ? $result['robots'] : [];
        $social = isset($result['social']) && is_array($result['social']) ? $result['social'] : [];
        return [
            'title' => (string) ($result['title'] ?? ''),
            'description' => (string) ($result['description'] ?? ''),
            'focus_keyword' => (string) ($result['focus_keyphrase'] ?? ''),
            'canonical' => (string) ($result['canonical_url'] ?? ''),
            'og_title' => (string) ($social['og_title'] ?? ''),
            'og_description' => (string) ($social['og_description'] ?? ''),
            'twitter_title' => (string) ($social['twitter_title'] ?? ''),
            'twitter_description' => (string) ($social['twitter_description'] ?? ''),
            'noindex' => !empty($robots['noindex']),
            'nofollow' => !empty($robots['nofollow']),
        ];
    }

    /** @param array<string, mixed> $clean */
    private static function write_aioseo(int $post_id, array $clean): array {
        if (!self::is_writable(self::AIOSEO)) {
            return ['written' => false, 'reason' => 'aioseo_service_unavailable'];
        }

        $fields = [];
        foreach (['title', 'description'] as $field) {
            if (array_key_exists($field, $clean)) {
                $fields[$field] = $clean[$field];
            }
        }
        if (array_key_exists('focus_keyword', $clean)) {
            $fields['focus_keyphrase'] = $clean['focus_keyword'];
        }
        if (array_key_exists('canonical', $clean)) {
            $fields['canonical_url'] = $clean['canonical'];
        }

        $social = [];
        foreach (['og_title', 'og_description', 'twitter_title', 'twitter_description'] as $field) {
            if (array_key_exists($field, $clean)) {
                $social[$field] = $clean[$field];
            }
        }
        if (!empty($social)) {
            $fields['social'] = $social;
        }

        $robots = [];
        foreach (['noindex', 'nofollow'] as $field) {
            if (array_key_exists($field, $clean)) {
                $robots[$field] = (bool) $clean[$field];
            }
        }
        if (!empty($robots)) {
            $fields['robots'] = array_merge(['use_default' => false], $robots);
        }

        try {
            $ability = self::aioseo_ability('seo-data-update');
            if ($ability) {
                $result = $ability->execute(array_merge(['postId' => $post_id], $fields));
            } else {
                $class = self::aioseo_service_class();
                $result = (new $class())->updateSeoData($post_id, $fields);
            }
        } catch (\Throwable $exception) {
            return ['written' => false, 'reason' => 'aioseo_write_failed'];
        }
        if (\is_wp_error($result)) {
            return ['written' => false, 'reason' => (string) $result->get_error_code()];
        }
        if (!is_array($result) || empty($result['updated'])) {
            return ['written' => false, 'reason' => 'aioseo_write_failed'];
        }

        return ['written' => true, 'fields' => $clean];
    }

    /** @param mixed $value */
    private static function normalise_boolean($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on', 'noindex', 'nofollow'], true);
    }

    /**
     * Site-wide SEO audit over content the acting user may read.
     *
     * Every row is authorized individually, so the report is scoped to what the
     * caller is entitled to see rather than to what exists.
     *
     * @param string[] $post_types
     * @return array
     */
    public static function audit(array $post_types, int $limit = 100, string $issue = ''): array {
        $post_types = array_values(array_filter(array_map('sanitize_key', $post_types), 'post_type_exists'));
        if (empty($post_types)) {
            $post_types = array_values(array_filter(['page', 'post'], 'post_type_exists'));
        }
        $limit = max(1, min(200, $limit));

        $posts = \get_posts([
            'post_type' => $post_types,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'numberposts' => $limit,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $rows = [];
        $counts = [];
        $withheld = 0;
        $titles = [];
        foreach ($posts as $post) {
            $post_id = (int) $post->ID;
            if (!Access::can_read_post($post_id)) {
                $withheld++;
                continue;
            }
            $view = self::read($post_id);
            if (isset($view['error'])) {
                continue;
            }
            $analysis = (array) $view['analysis'];
            $issues = (array) $analysis['issues'];

            $effective = (string) $analysis['seo_title'];
            $normalized = strtolower(trim($effective));
            if ('' !== $normalized) {
                $titles[$normalized] = array_merge($titles[$normalized] ?? [], [$post_id]);
            }

            foreach ($issues as $code) {
                $counts[$code] = (int) ($counts[$code] ?? 0) + 1;
            }

            if ('' !== $issue && !in_array($issue, $issues, true)) {
                continue;
            }

            $rows[] = [
                'post_id' => $post_id,
                'title' => (string) $post->post_title,
                'post_type' => (string) $post->post_type,
                'status' => (string) $post->post_status,
                'seo_title' => $effective,
                'seo_title_length' => (int) $analysis['seo_title_length'],
                'meta_description_length' => (int) $analysis['meta_description_length'],
                'issues' => $issues,
            ];
        }

        // Duplicate titles are a set property, so they are computed after the
        // pass rather than per row.
        $duplicates = [];
        foreach ($titles as $normalized => $ids) {
            if (count($ids) > 1) {
                $duplicates[] = ['seo_title' => $normalized, 'post_ids' => array_values($ids)];
            }
        }
        if (!empty($duplicates)) {
            $counts['duplicate_seo_title'] = count($duplicates);
        }

        return [
            'integration' => self::active(),
            'integration_label' => self::label(self::active()),
            'scanned' => count($rows),
            'withheld_by_permission' => $withheld,
            'issue_counts' => $counts,
            'duplicate_titles' => $duplicates,
            'rows' => $rows,
        ];
    }

    /**
     * Images in a post's content that have no alt text.
     *
     * Reported per post rather than site-wide because the fix is per image, and
     * the attachment's own alt text is consulted so an image that inherits valid
     * alt is not falsely flagged.
     */
    public static function image_alt_audit(int $post_id): array {
        if (!Access::can_read_post($post_id)) {
            return ['error' => 'post_not_found'];
        }
        $post = \get_post($post_id);
        $content = $post ? (string) $post->post_content : '';

        $missing = [];
        $total = 0;
        if (preg_match_all('/<img\b[^>]*>/i', $content, $matches)) {
            foreach ($matches[0] as $tag) {
                $total++;
                $has_alt = 1 === preg_match('/\balt\s*=\s*("[^"]*"|\'[^\']*\')/i', $tag, $alt_match)
                    && '' !== trim((string) trim((string) ($alt_match[1] ?? ''), '"\''));
                if ($has_alt) {
                    continue;
                }
                $src = '';
                if (preg_match('/\bsrc\s*=\s*("[^"]*"|\'[^\']*\')/i', $tag, $src_match)) {
                    $src = trim((string) $src_match[1], '"\'');
                }
                $missing[] = ['src' => $src];
            }
        }

        // Featured image counts too: it renders on the page but is not in content.
        $thumbnail_id = (int) \get_post_thumbnail_id($post_id);
        $featured_missing = false;
        if ($thumbnail_id > 0) {
            $total++;
            $alt = (string) \get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true);
            $featured_missing = '' === trim($alt);
        }

        return [
            'post_id' => $post_id,
            'images_total' => $total,
            'images_missing_alt' => count($missing) + ($featured_missing ? 1 : 0),
            'missing' => $missing,
            'featured_image_missing_alt' => $featured_missing,
        ];
    }
}
