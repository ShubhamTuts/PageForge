<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * PageForge MCP Lite tool registry (Free).
 *
 * "Ask AI about your PageForge site." Every tool here is read, analyze,
 * validate, or preview. None inserts, updates, or deletes anything. This is
 * enforced structurally, not just by convention: every entry declares a
 * non-write scope (Mcp_Scopes::READ or Mcp_Scopes::PREVIEW), and the transport
 * (Mcp_Server) drops any tool whose scope this site cannot grant at all — so
 * on Free a write tool cannot be introduced even through the filter that Pro
 * uses to add its own (see Mcp_Registry_Pro).
 *
 * Read and preview are separate scopes because a preview can invoke an AI
 * provider and therefore costs money and time, while a status read does not.
 *
 * Each tool's handler reuses existing PageForge business logic
 * (Generator::preview_one, Generator::validate_rows, Compatibility, Frontend
 * helpers) rather than re-implementing it, so MCP, the admin UI, and AJAX
 * can never disagree about what a template produces.
 */
class Mcp_Registry {
    /**
     * @return array<string, array{
     *   title:string, description:string, scope:string, readOnly:bool,
     *   inputSchema:array, handler:callable
     * }>
     */
    public static function tools(): array {
        return [
            'pageforge.site_status' => [
                'title' => 'PageForge site status',
                'description' => 'Plugin edition, version, active AI provider (without exposing keys), and overall readiness.',
                'scope' => Mcp_Scopes::READ,
                'readOnly' => true,
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'handler' => [self::class, 'tool_site_status'],
            ],
            'pageforge.templates_list' => [
                'title' => 'List PageForge templates',
                'description' => 'Templates available for generation, with their title/slug patterns and referenced variables.',
                'scope' => Mcp_Scopes::READ,
                'readOnly' => true,
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'handler' => [self::class, 'tool_templates_list'],
            ],
            'pageforge.pages_list' => [
                'title' => 'List generated PageForge pages',
                'description' => 'Pages PageForge has generated, across both the current and legacy content namespaces.',
                'scope' => Mcp_Scopes::READ,
                'readOnly' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]],
                    'additionalProperties' => false,
                ],
                'handler' => [self::class, 'tool_pages_list'],
            ],
            'pageforge.variables_preview' => [
                'title' => 'Preview PageForge variable resolution',
                'description' => 'Shows how {Column} placeholders and [pageforge var]/[pf var] shortcodes resolve for one generated page.',
                'scope' => Mcp_Scopes::READ,
                'readOnly' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['post_id' => ['type' => 'integer', 'minimum' => 1]],
                    'required' => ['post_id'],
                    'additionalProperties' => false,
                ],
                'handler' => [self::class, 'tool_variables_preview'],
            ],
            'pageforge.generation_preview' => [
                'title' => 'Preview a generated page',
                'description' => 'Computes the title, slug, content, and SEO metadata one data row would produce from one template, without creating anything.',
                'scope' => Mcp_Scopes::PREVIEW,
                'readOnly' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'template_id' => ['type' => 'integer', 'minimum' => 1],
                        'row' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                    ],
                    'required' => ['template_id', 'row'],
                    'additionalProperties' => false,
                ],
                'handler' => [self::class, 'tool_generation_preview'],
            ],
            'pageforge.campaign_validate' => [
                'title' => 'Validate rows before generation',
                'description' => 'Checks a set of data rows against one template for unresolved variables, duplicate slugs, and missing data before any generation runs.',
                'scope' => Mcp_Scopes::PREVIEW,
                'readOnly' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'template_id' => ['type' => 'integer', 'minimum' => 1],
                        'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']], 'maxItems' => 200],
                    ],
                    'required' => ['template_id', 'rows'],
                    'additionalProperties' => false,
                ],
                'handler' => [self::class, 'tool_campaign_validate'],
            ],
            'pageforge.seo_read' => [
                'title' => 'Read SEO metadata for one page',
                'description' => 'SEO title, meta description, focus keyword, canonical and social metadata for one post, read through whichever SEO plugin is active (Yoast, Rank Math, SEOPress, or PageForge itself), plus a length and completeness assessment.',
                'scope' => Mcp_Scopes::READ,
                'readOnly' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['post_id' => ['type' => 'integer', 'minimum' => 1]],
                    'required' => ['post_id'],
                    'additionalProperties' => false,
                ],
                'handler' => [self::class, 'tool_seo_read'],
            ],
            'pageforge.seo_audit' => [
                'title' => 'Audit SEO across content',
                'description' => 'Finds pages with no SEO title, weak or missing meta descriptions, duplicate titles, and unresolved PageForge variables left in metadata. Filter to one issue with the "issue" argument.',
                'scope' => Mcp_Scopes::READ,
                'readOnly' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'post_types' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100],
                        'issue' => [
                            'type' => 'string',
                            'enum' => [
                                'missing_seo_title', 'seo_title_too_long', 'seo_title_too_short',
                                'missing_meta_description', 'meta_description_too_long',
                                'meta_description_too_short', 'unresolved_variables_in_metadata',
                            ],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'handler' => [self::class, 'tool_seo_audit'],
            ],
            'pageforge.seo_status' => [
                'title' => 'SEO integration status',
                'description' => 'Which SEO plugin is active, whether PageForge can read and write its metadata, and whether PageForge is rendering its own SEO tags or deferring to that plugin.',
                'scope' => Mcp_Scopes::READ,
                'readOnly' => true,
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'handler' => [self::class, 'tool_seo_status'],
            ],
            'pageforge.image_alt_audit' => [
                'title' => 'Find images missing ALT text',
                'description' => 'Lists images in one post that have no alt text, including the featured image.',
                'scope' => Mcp_Scopes::READ,
                'readOnly' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['post_id' => ['type' => 'integer', 'minimum' => 1]],
                    'required' => ['post_id'],
                    'additionalProperties' => false,
                ],
                'handler' => [self::class, 'tool_image_alt_audit'],
            ],
            'pageforge.content_search' => [
                'title' => 'Search site content',
                'description' => 'Finds pages and posts by keyword, post type and status. Returns only content the authorizing WordPress user may read.',
                'scope' => Mcp_Scopes::READ,
                'readOnly' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'maxLength' => 200],
                        'post_types' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20],
                        'status' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 6],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                    ],
                    'additionalProperties' => false,
                ],
                'handler' => [self::class, 'tool_content_search'],
            ],
            'pageforge.post_read' => [
                'title' => 'Read one page or post',
                'description' => 'Title, slug, status, URL, excerpt, content and PageForge variables for one post the authorizing user may read.',
                'scope' => Mcp_Scopes::READ,
                'readOnly' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'post_id' => ['type' => 'integer', 'minimum' => 1],
                        'include_content' => ['type' => 'boolean', 'default' => false],
                    ],
                    'required' => ['post_id'],
                    'additionalProperties' => false,
                ],
                'handler' => [self::class, 'tool_post_read'],
            ],
            'pageforge.taxonomies_list' => [
                'title' => 'List taxonomies and terms',
                'description' => 'Public taxonomies and their terms, for understanding how the site is categorised.',
                'scope' => Mcp_Scopes::READ,
                'readOnly' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'taxonomy' => ['type' => 'string', 'maxLength' => 60],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                    ],
                    'additionalProperties' => false,
                ],
                'handler' => [self::class, 'tool_taxonomies_list'],
            ],
            'pageforge.post_types_list' => [
                'title' => 'List available post types',
                'description' => 'Public post types PageForge can target, and which ones PageForge itself uses for templates and generated pages.',
                'scope' => Mcp_Scopes::READ,
                'readOnly' => true,
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'handler' => [self::class, 'tool_post_types_list'],
            ],
            'pageforge.diagnostics' => [
                'title' => 'PageForge diagnostics',
                'description' => 'Plugin readiness: MCP state, HTTPS, SEO integration, AI provider configuration (never keys), builder detections, and content counts.',
                'scope' => Mcp_Scopes::READ,
                'readOnly' => true,
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'handler' => [self::class, 'tool_diagnostics'],
            ],
            'pageforge.seo_preview' => [
                'title' => 'Preview improved SEO metadata',
                'description' => 'Proposes an improved SEO title and meta description for one post and returns them for review. PageForge Free never applies them; applying requires PageForge Pro.',
                'scope' => Mcp_Scopes::PREVIEW,
                'readOnly' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'post_id' => ['type' => 'integer', 'minimum' => 1],
                        'focus_keyword' => ['type' => 'string', 'maxLength' => 120],
                    ],
                    'required' => ['post_id'],
                    'additionalProperties' => false,
                ],
                'handler' => [self::class, 'tool_seo_preview'],
            ],
            'pageforge.builder_compatibility' => [
                'title' => 'Check builder compatibility',
                'description' => 'Detects whether a page was built with Elementor, Divi, Gutenberg, or the classic editor, and whether PageForge can safely operate on it.',
                'scope' => Mcp_Scopes::READ,
                'readOnly' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['post_id' => ['type' => 'integer', 'minimum' => 1]],
                    'required' => ['post_id'],
                    'additionalProperties' => false,
                ],
                'handler' => [self::class, 'tool_builder_compatibility'],
            ],
        ];
    }

    // ------------------------------------------------------------ handlers --

    public static function tool_site_status(array $args): array {
        return [
            'edition' => Edition::current(),
            'plugin_version' => defined('PAGEFORGE_VERSION') ? PAGEFORGE_VERSION : '',
            'namespace' => Compatibility::namespace(),
            'ai_provider' => self::ai_provider_status(),
            'generated_post_types' => Compatibility::generated_post_types(),
            'template_post_types' => Compatibility::template_post_types(),
        ];
    }

    /**
     * Provider name and whether a key is present — never the key itself.
     *
     * The two editions expose different provider helpers (Free: ai_*,
     * Pro: pf_ai_*), so this resolves whichever is actually available
     * rather than assuming one edition's function names.
     */
    private static function ai_provider_status(): array {
        $settings = settings_get();

        $active = '';
        if (function_exists(__NAMESPACE__ . '\\ai_get_provider')) {
            $active = (string) ai_get_provider($settings);
        } elseif (function_exists(__NAMESPACE__ . '\\pf_ai_normalize_provider')) {
            $active = (string) pf_ai_normalize_provider((string) array_get($settings, 'ai_provider', 'openai'));
        }

        $configured = false;
        if (function_exists(__NAMESPACE__ . '\\ai_has_provider_key')) {
            $configured = (bool) ai_has_provider_key($settings);
        } elseif (function_exists(__NAMESPACE__ . '\\pf_ai_has_api_key')) {
            $configured = (bool) pf_ai_has_api_key($settings);
        }

        return ['active' => $active, 'configured' => $configured];
    }

    public static function tool_templates_list(array $args): array {
        $templates = \get_posts([
            'post_type' => Compatibility::template_post_types(),
            'post_status' => 'any',
            'numberposts' => 50,
        ]);
        $out = [];
        foreach ($templates as $template) {
            // Templates are queried with post_status "any", so unpublished
            // templates are filtered per object rather than by the query.
            if (!self::can_read_post((int) $template->ID)) {
                continue;
            }
            $title_pattern = (string) Compatibility::read_meta((int) $template->ID, 'title_pattern', '');
            $meta_description_pattern = (string) Compatibility::read_meta((int) $template->ID, 'meta_description_pattern', '');
            $slug_pattern = (string) Compatibility::read_meta((int) $template->ID, 'slug_pattern', '');
            preg_match_all('/\{([A-Za-z0-9_]+)\}/', $title_pattern . ' ' . $meta_description_pattern . ' ' . $slug_pattern . ' ' . (string) $template->post_content, $matches);
            $destination = Generator::destination((int) $template->ID);
            $out[] = [
                'id' => (int) $template->ID,
                'title' => (string) $template->post_title,
                'title_pattern' => $title_pattern,
                'meta_description_pattern' => $meta_description_pattern,
                'slug_pattern' => $slug_pattern,
                'target_post_type' => \is_wp_error($destination) ? '' : $destination['post_type'],
                'post_parent' => \is_wp_error($destination) ? 0 : $destination['post_parent'],
                'destination_error' => \is_wp_error($destination) ? $destination->get_error_message() : '',
                'variables' => array_values(array_unique($matches[1] ?? [])),
            ];
        }
        return ['templates' => $out, 'count' => count($out)];
    }

    public static function tool_pages_list(array $args): array {
        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));
        $pages = \get_posts([
            'post_type' => Compatibility::generated_post_types(),
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'numberposts' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $out = [];
        $withheld = 0;
        foreach ($pages as $page) {
            // The query intentionally includes draft/pending/private, so each
            // row is authorized individually rather than trusting the query.
            if (!self::can_read_post((int) $page->ID)) {
                $withheld++;
                continue;
            }
            $out[] = [
                'id' => (int) $page->ID,
                'title' => (string) $page->post_title,
                'slug' => (string) $page->post_name,
                'status' => (string) $page->post_status,
                'post_type' => (string) $page->post_type,
            ];
        }
        // Reported as a count so the caller knows the list is partial, without
        // learning anything about what was withheld.
        return ['pages' => $out, 'count' => count($out), 'withheld_by_permission' => $withheld];
    }

    /**
     * Object-level read authorization for a specific post.
     *
     * A scope says "this grant may read PageForge data"; it does not say which
     * posts. Without this check a grant could read an unpublished or private
     * post that the acting WordPress user has no right to see, simply by
     * naming its ID. `read_post` is the correct primitive because it accounts
     * for post status, post type and ownership.
     */
    private static function can_read_post(int $post_id): bool {
        return \current_user_can('read_post', $post_id);
    }

    public static function tool_variables_preview(array $args): array {
        $post_id = (int) ($args['post_id'] ?? 0);
        $post = \get_post($post_id);
        if (!$post) {
            return ['error' => 'post_not_found'];
        }
        if (!self::can_read_post($post_id)) {
            // Deliberately the same shape as "not found": distinguishing them
            // would let a caller enumerate which private posts exist.
            return ['error' => 'post_not_found'];
        }
        $vars = Compatibility::read_meta($post_id, 'vars', []);
        $vars = is_array($vars) ? $vars : [];
        foreach (['city', 'state', 'service'] as $logical) {
            $value = (string) Compatibility::read_meta($post_id, $logical, '');
            $label = ucfirst($logical);
            if ('' !== $value && !isset($vars[$label])) {
                $vars[$label] = $value;
            }
        }
        $sample = [];
        foreach ($vars as $key => $value) {
            $sample['{' . $key . '}'] = (string) $value;
            $sample['[pageforge var="' . $key . '"]'] = (string) $value;
            $sample['[pf var="' . $key . '"]'] = (string) $value;
        }
        return ['post_id' => $post_id, 'variables' => $vars, 'resolved_examples' => $sample];
    }

    public static function tool_generation_preview(array $args): array {
        $template_id = (int) ($args['template_id'] ?? 0);
        if (!self::can_read_post($template_id)) {
            return ['error' => 'template_not_found'];
        }
        $row = is_array($args['row'] ?? null) ? $args['row'] : [];
        $result = (new Generator())->preview_one($template_id, $row);
        if (\is_wp_error($result)) {
            return ['error' => $result->get_error_code(), 'message' => $result->get_error_message()];
        }
        return $result;
    }

    public static function tool_campaign_validate(array $args): array {
        $template_id = (int) ($args['template_id'] ?? 0);
        if (!self::can_read_post($template_id)) {
            return ['error' => 'template_not_found'];
        }
        $rows = is_array($args['rows'] ?? null) ? $args['rows'] : [];
        return (new Generator())->validate_rows($template_id, $rows);
    }

    // ------------------------------------------------------------- SEO ----

    public static function tool_seo_read(array $args): array {
        $post_id = (int) ($args['post_id'] ?? 0);
        // Seo::read performs its own object authorization, so a post the acting
        // user cannot see reports as not found rather than leaking its metadata.
        if (!self::can_read_post($post_id)) {
            return ['error' => 'post_not_found'];
        }
        return Seo::read($post_id);
    }

    public static function tool_seo_audit(array $args): array {
        $post_types = is_array($args['post_types'] ?? null) ? $args['post_types'] : [];
        $limit = (int) ($args['limit'] ?? 100);
        $issue = \sanitize_key((string) ($args['issue'] ?? ''));
        return Seo::audit($post_types, $limit, $issue);
    }

    public static function tool_seo_status(array $args): array {
        $detected = Seo::detected();
        return [
            'active_integration' => Seo::active(),
            'active_integration_label' => Seo::label(Seo::active()),
            'readable_and_writable' => Seo::is_writable(Seo::active()),
            'pageforge_renders_seo_tags' => Seo::pageforge_owns_output(),
            'detected_integrations' => array_map([Seo::class, 'label'], $detected),
            // Two active SEO plugins is a misconfiguration that produces
            // duplicate output regardless of what PageForge does.
            'multiple_seo_plugins_active' => count($detected) > 1,
            'notes' => Seo::is_writable(Seo::active())
                ? []
                : [__('PageForge can detect this SEO plugin but cannot read or write its metadata, because it does not store that data in post meta.', 'pageforge')],
        ];
    }

    public static function tool_image_alt_audit(array $args): array {
        return Seo::image_alt_audit((int) ($args['post_id'] ?? 0));
    }

    /**
     * Proposes better metadata without writing it.
     *
     * Free is analyse-and-preview only, so this returns a suggestion and states
     * plainly that applying requires Pro. The suggestion is derived from the
     * post's own content rather than an AI call, so it costs nothing and works
     * without a provider key configured.
     */
    public static function tool_seo_preview(array $args): array {
        $post_id = (int) ($args['post_id'] ?? 0);
        if (!self::can_read_post($post_id)) {
            return ['error' => 'post_not_found'];
        }
        $post = \get_post($post_id);
        $current = Seo::read($post_id);
        $focus = \sanitize_text_field((string) ($args['focus_keyword'] ?? ''));

        $site = (string) \get_bloginfo('name');
        $base_title = (string) $post->post_title;
        $proposed_title = '' !== $focus && false === stripos($base_title, $focus)
            ? $focus . ' — ' . $base_title
            : $base_title;
        if ('' !== $site && Seo::text_length($proposed_title . ' | ' . $site) <= 60) {
            $proposed_title .= ' | ' . $site;
        }

        $source = (string) $post->post_excerpt;
        if ('' === trim($source)) {
            $source = (string) \wp_strip_all_tags((string) $post->post_content);
        }
        $source = trim((string) preg_replace('/\s+/', ' ', $source));
        $proposed_description = $source;
        if (Seo::text_length($proposed_description) > 155) {
            $proposed_description = rtrim(pf_utf8_substr($proposed_description, 0, 152)) . '...';
        }

        return [
            'post_id' => $post_id,
            'current' => $current['analysis'] ?? [],
            'proposed' => [
                'seo_title' => $proposed_title,
                'seo_title_length' => Seo::text_length($proposed_title),
                'meta_description' => $proposed_description,
                'meta_description_length' => Seo::text_length($proposed_description),
                'focus_keyword' => $focus,
            ],
            'applied' => false,
            'apply_requires' => 'pro',
            'message' => __('PageForge Free previews metadata only. Applying SEO changes through MCP requires PageForge Pro.', 'pageforge'),
        ];
    }

    // -------------------------------------------------- content and site ----

    public static function tool_content_search(array $args): array {
        $search = \sanitize_text_field((string) ($args['search'] ?? ''));
        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));

        $post_types = array_values(array_filter(
            array_map('sanitize_key', (array) ($args['post_types'] ?? [])),
            'post_type_exists'
        ));
        if (empty($post_types)) {
            $post_types = array_values(array_filter(
                array_merge(['page', 'post'], Compatibility::generated_post_types()),
                'post_type_exists'
            ));
        }

        $statuses = array_values(array_filter(
            array_map('sanitize_key', (array) ($args['status'] ?? [])),
            static function (string $status): bool {
                return in_array($status, ['publish', 'draft', 'pending', 'private', 'future'], true);
            }
        ));
        if (empty($statuses)) {
            $statuses = ['publish', 'draft', 'pending', 'private'];
        }

        $query = [
            'post_type' => $post_types,
            'post_status' => $statuses,
            'numberposts' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        if ('' !== $search) {
            $query['s'] = $search;
        }

        $out = [];
        $withheld = 0;
        foreach (\get_posts($query) as $post) {
            // The query includes non-public statuses, so each row is authorized
            // individually rather than trusting the query.
            if (!self::can_read_post((int) $post->ID)) {
                $withheld++;
                continue;
            }
            $out[] = [
                'post_id' => (int) $post->ID,
                'title' => (string) $post->post_title,
                'slug' => (string) $post->post_name,
                'post_type' => (string) $post->post_type,
                'status' => (string) $post->post_status,
                'url' => (string) \get_permalink((int) $post->ID),
            ];
        }

        return ['results' => $out, 'count' => count($out), 'withheld_by_permission' => $withheld];
    }

    public static function tool_post_read(array $args): array {
        $post_id = (int) ($args['post_id'] ?? 0);
        if (!self::can_read_post($post_id)) {
            return ['error' => 'post_not_found'];
        }
        $post = \get_post($post_id);
        $include_content = !empty($args['include_content']);

        $vars = Compatibility::read_meta($post_id, 'vars', []);
        $out = [
            'post_id' => $post_id,
            'title' => (string) $post->post_title,
            'slug' => (string) $post->post_name,
            'post_type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'url' => (string) \get_permalink($post_id),
            'excerpt' => (string) $post->post_excerpt,
            'pageforge_variables' => is_array($vars) ? $vars : [],
            'is_pageforge_generated' => in_array((string) $post->post_type, Compatibility::generated_post_types(), true)
                || (is_array($vars) && !empty($vars)),
        ];

        if ($include_content) {
            // Bounded: an agent needs enough to reason about the page, not a
            // full dump of every post it touches.
            $content = (string) $post->post_content;
            $out['content'] = pf_utf8_substr($content, 0, 20000);
            $out['content_truncated'] = pf_utf8_strlen($content) > 20000;
        }

        return $out;
    }

    public static function tool_taxonomies_list(array $args): array {
        $requested = \sanitize_key((string) ($args['taxonomy'] ?? ''));
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));

        $taxonomies = \get_taxonomies(['public' => true], 'objects');
        $out = [];
        foreach ($taxonomies as $taxonomy) {
            $name = (string) $taxonomy->name;
            if ('' !== $requested && $name !== $requested) {
                continue;
            }
            $terms = \get_terms(['taxonomy' => $name, 'hide_empty' => false, 'number' => $limit]);
            $term_list = [];
            if (is_array($terms)) {
                foreach ($terms as $term) {
                    if (!is_object($term)) {
                        continue;
                    }
                    $term_list[] = [
                        'term_id' => (int) ($term->term_id ?? 0),
                        'name' => (string) ($term->name ?? ''),
                        'slug' => (string) ($term->slug ?? ''),
                        'count' => (int) ($term->count ?? 0),
                    ];
                }
            }
            $out[] = [
                'taxonomy' => $name,
                'label' => (string) ($taxonomy->label ?? $name),
                'hierarchical' => (bool) ($taxonomy->hierarchical ?? false),
                'terms' => $term_list,
            ];
        }

        return ['taxonomies' => $out, 'count' => count($out)];
    }

    public static function tool_post_types_list(array $args): array {
        $types = \get_post_types(['public' => true], 'objects');
        $out = [];
        foreach ($types as $type) {
            $name = (string) $type->name;
            $out[] = [
                'name' => $name,
                'label' => (string) ($type->label ?? $name),
                'hierarchical' => (bool) ($type->hierarchical ?? false),
                'used_by_pageforge' => in_array($name, Compatibility::generated_post_types(), true),
            ];
        }
        return [
            'post_types' => $out,
            'pageforge_generated_post_types' => Compatibility::generated_post_types(),
            'pageforge_template_post_types' => Compatibility::template_post_types(),
        ];
    }

    /**
     * Plugin readiness. Deliberately reports whether credentials are configured,
     * never any part of a credential.
     */
    public static function tool_diagnostics(array $args): array {
        $settings = settings_get();

        $counts = [];
        foreach (Compatibility::generated_post_types() as $type) {
            if (!\post_type_exists($type)) {
                continue;
            }
            $tally = \wp_count_posts($type);
            $counts[$type] = [
                'publish' => (int) ($tally->publish ?? 0),
                'draft' => (int) ($tally->draft ?? 0),
                'pending' => (int) ($tally->pending ?? 0),
                'private' => (int) ($tally->private ?? 0),
            ];
        }

        return [
            'edition' => Edition::current(),
            'plugin_version' => defined('PAGEFORGE_VERSION') ? PAGEFORGE_VERSION : '',
            'mcp_enabled' => !empty($settings['mcp_enabled']),
            'https' => Request::site_is_https(),
            'namespace' => Compatibility::namespace(),
            'ai_provider' => self::ai_provider_status(),
            'seo' => [
                'active_integration' => Seo::active(),
                'pageforge_renders_seo_tags' => Seo::pageforge_owns_output(),
            ],
            'generated_counts' => $counts,
            'granted_scopes_available' => Mcp_Scopes::grantable(),
        ];
    }

    public static function tool_builder_compatibility(array $args): array {
        $post_id = (int) ($args['post_id'] ?? 0);
        $post = \get_post($post_id);
        if (!$post) {
            return ['error' => 'post_not_found'];
        }
        if (!self::can_read_post($post_id)) {
            return ['error' => 'post_not_found'];
        }

        $builder = 'classic';
        $compatible = true;
        $notes = [];

        if (\metadata_exists('post', $post_id, '_elementor_data')) {
            $builder = 'elementor';
            $raw = \get_post_meta($post_id, '_elementor_data', true);
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            $compatible = is_array($decoded);
            if (!$compatible) {
                $notes[] = 'Elementor data could not be parsed as JSON.';
            }
        } elseif (\metadata_exists('post', $post_id, '_et_pb_use_builder') && '1' === (string) \get_post_meta($post_id, '_et_pb_use_builder', true)) {
            $builder = 'divi';
        } elseif (false !== strpos((string) $post->post_content, '<!-- wp:')) {
            $builder = 'gutenberg';
        }

        return [
            'post_id' => $post_id,
            'builder' => $builder,
            'compatible' => $compatible,
            'notes' => $notes,
        ];
    }
}
