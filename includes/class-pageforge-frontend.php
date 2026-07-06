<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

class Frontend {
    public static function init() {
        \add_shortcode('pageforge_sitemap', [__CLASS__, 'sitemap']);
        \add_action('init', [__CLASS__, 'register_blocks']);
        \add_filter('block_categories_all', [__CLASS__, 'register_block_category'], 10, 2);
        \add_action('wp_head', [__CLASS__, 'inject_basic_seo_meta'], 7);
        \add_action('wp_head', [__CLASS__, 'inject_schema']);
        // Runtime token replacement for builder/theme outputs
        \add_filter('the_content', [__CLASS__, 'runtime_replace_content'], 9);
        \add_filter('the_title', [__CLASS__, 'runtime_replace_title'], 9, 2);
        \add_filter('the_excerpt', [__CLASS__, 'runtime_replace_content'], 9);
        \add_filter('get_the_excerpt', [__CLASS__, 'runtime_replace_content'], 9);
        \add_filter('render_block', [__CLASS__, 'runtime_replace_rendered_block'], 9, 2);
        \add_filter('widget_title', [__CLASS__, 'runtime_replace_content'], 9);
        \add_filter('widget_text', [__CLASS__, 'runtime_replace_content'], 9);
        \add_filter('widget_text_content', [__CLASS__, 'runtime_replace_content'], 9);
        \add_filter('widget_block_content', [__CLASS__, 'runtime_replace_content'], 9);
        \add_filter('elementor/widget/render_content', [__CLASS__, 'runtime_replace_builder_content'], 9, 2);
        \add_filter('wpseo_title', [__CLASS__, 'runtime_replace_content'], 9);
        \add_filter('wpseo_metadesc', [__CLASS__, 'runtime_replace_content'], 9);
        \add_filter('rank_math/frontend/title', [__CLASS__, 'runtime_replace_content'], 9);
        \add_filter('rank_math/frontend/description', [__CLASS__, 'runtime_replace_content'], 9);
        \add_filter('wp_title', [__CLASS__, 'runtime_replace_content'], 9);
        \add_filter('document_title_parts', [__CLASS__, 'runtime_replace_title_parts'], 9);
        // Variable shortcode for explicit placements in widgets/builders
        \add_shortcode('pageforge', [__CLASS__, 'shortcode_var']);
    }

    public static function sitemap($atts = []) {
        $atts = shortcode_atts([
            'post_type'        => 'pageforge_page,page,post',
            'columns'          => 3,
            'limit'            => 1000,
            'group_by'         => 'city',
            'show_counts'      => true,
            'heading_level'    => 3,
            'style'            => 'cards',
            'accent_color'     => '#1f4b59',
            'background_color' => '#ffffff',
            'link_color'       => '#0f766e',
        ], $atts, 'pageforge_sitemap');

        $post_types = self::parse_sitemap_post_types((string) $atts['post_type']);
        $limit = max(1, min(5000, (int) $atts['limit']));

        $q = new \WP_Query([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => '_pageforge_vars',
                    'compare' => 'EXISTS',
                ],
                [
                    'key'     => '_pageforge_city',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);
        if (!$q->have_posts()) return '<div class="pageforge-sitemap pageforge-sitemap--empty">' . esc_html__('No PageForge pages found.', 'pageforge') . '</div>';

        $cols = max(1, min(6, intval($atts['columns'])));
        $group_by = sanitize_key((string) $atts['group_by']);
        if (!in_array($group_by, ['city', 'state', 'service', 'none'], true)) {
            $group_by = 'city';
        }
        $show_counts = filter_var($atts['show_counts'], FILTER_VALIDATE_BOOLEAN);
        $heading_level = max(2, min(4, (int) $atts['heading_level']));
        $heading_tag = 'h' . $heading_level;
        $style = sanitize_key((string) $atts['style']);
        if (!in_array($style, ['cards', 'compact', 'minimal'], true)) {
            $style = 'cards';
        }
        $accent = sanitize_hex_color((string) $atts['accent_color']);
        $background = sanitize_hex_color((string) $atts['background_color']);
        $link = sanitize_hex_color((string) $atts['link_color']);
        $accent = $accent ?: '#1f4b59';
        $background = $background ?: '#ffffff';
        $link = $link ?: '#0f766e';

        $groups = [];
        foreach ($q->posts as $pid) {
            $label = self::sitemap_group_label((int) $pid, $group_by);
            if (!isset($groups[$label])) {
                $groups[$label] = [];
            }
            $groups[$label][] = (int) $pid;
        }
        uksort($groups, 'strnatcasecmp');

        $style_attr = sprintf(
            '--pf-sitemap-columns:%d;--pf-sitemap-accent:%s;--pf-sitemap-bg:%s;--pf-sitemap-link:%s;',
            $cols,
            esc_attr($accent),
            esc_attr($background),
            esc_attr($link)
        );
        $html = self::sitemap_inline_css();
        $html .= '<div class="pageforge-sitemap pageforge-sitemap--' . esc_attr($style) . '" style="' . esc_attr($style_attr) . '">';
        $html .= '<div class="pageforge-sitemap__groups">';
        foreach ($groups as $label => $ids) {
            $html .= '<section class="pageforge-sitemap__group">';
            if ($group_by !== 'none') {
                $html .= '<' . $heading_tag . ' class="pageforge-sitemap__heading">' . esc_html($label);
                if ($show_counts) {
                    $html .= ' <span>' . esc_html(sprintf(_n('%d page', '%d pages', count($ids), 'pageforge'), count($ids))) . '</span>';
                }
                $html .= '</' . $heading_tag . '>';
            }
            $html .= '<ul class="pageforge-sitemap__list">';
            foreach ($ids as $pid) {
                $html .= '<li><a href="' . esc_url(get_permalink($pid)) . '">' . esc_html(get_the_title($pid)) . '</a></li>';
            }
            $html .= '</ul></section>';
        }
        $html .= '</div></div>';
        return $html;
    }

    public static function register_block_category(array $categories, $context = null): array {
        foreach ($categories as $category) {
            if (isset($category['slug']) && $category['slug'] === 'pageforge') {
                return $categories;
            }
        }

        array_unshift($categories, [
            'slug'  => 'pageforge',
            'title' => __('PageForge Blocks', 'pageforge'),
            'icon'  => null,
        ]);
        return $categories;
    }

    public static function register_blocks(): void {
        if (!function_exists('register_block_type')) {
            return;
        }

        \wp_register_script(
            'pageforge-blocks',
            false,
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n'],
            PAGEFORGE_VERSION,
            true
        );

        \wp_add_inline_script(
            'pageforge-blocks',
            'window.PAGEFORGE_BLOCKS_I18N = ' . wp_json_encode(self::block_editor_translations()) . ";\n" . self::block_editor_script()
        );

        \register_block_type('pageforge/sitemap', [
            'api_version'   => 2,
            'editor_script' => 'pageforge-blocks',
            'render_callback' => [__CLASS__, 'render_sitemap_block'],
            'attributes'    => [
                'postType'        => ['type' => 'string', 'default' => 'pageforge_page,page,post'],
                'columns'         => ['type' => 'number', 'default' => 3],
                'limit'           => ['type' => 'number', 'default' => 1000],
                'groupBy'         => ['type' => 'string', 'default' => 'city'],
                'showCounts'      => ['type' => 'boolean', 'default' => true],
                'headingLevel'    => ['type' => 'number', 'default' => 3],
                'style'           => ['type' => 'string', 'default' => 'cards'],
                'accentColor'     => ['type' => 'string', 'default' => '#1f4b59'],
                'backgroundColor' => ['type' => 'string', 'default' => '#ffffff'],
                'linkColor'       => ['type' => 'string', 'default' => '#0f766e'],
            ],
        ]);
    }

    public static function render_sitemap_block(array $attributes): string {
        return self::sitemap([
            'post_type'        => $attributes['postType'] ?? 'pageforge_page,page,post',
            'columns'          => $attributes['columns'] ?? 3,
            'limit'            => $attributes['limit'] ?? 1000,
            'group_by'         => $attributes['groupBy'] ?? 'city',
            'show_counts'      => !empty($attributes['showCounts']),
            'heading_level'    => $attributes['headingLevel'] ?? 3,
            'style'            => $attributes['style'] ?? 'cards',
            'accent_color'     => $attributes['accentColor'] ?? '#1f4b59',
            'background_color' => $attributes['backgroundColor'] ?? '#ffffff',
            'link_color'       => $attributes['linkColor'] ?? '#0f766e',
        ]);
    }

    private static function block_editor_script(): string {
        return <<<'JS'
(function(blocks, element, components, blockEditor, serverSideRender, i18n){
    if (!blocks || !element || !components || !blockEditor || !serverSideRender) {
        return;
    }
    var el = element.createElement;
    var __ = i18n.__;
    var strings = window.PAGEFORGE_BLOCKS_I18N || {};
    function t(text) {
        return strings[text] || __(text, 'pageforge');
    }
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var SelectControl = components.SelectControl;
    var RangeControl = components.RangeControl;
    var TextControl = components.TextControl;
    var ToggleControl = components.ToggleControl;
    var ServerSideRender = serverSideRender.default || serverSideRender;

    blocks.registerBlockType('pageforge/sitemap', {
        apiVersion: 2,
        title: t('PageForge Sitemap'),
        description: t('Displays generated PageForge pages grouped by city, state, service, or as one list.'),
        icon: 'networking',
        category: 'pageforge',
        supports: {
            html: false
        },
        attributes: {
            postType: { type: 'string', default: 'pageforge_page,page,post' },
            columns: { type: 'number', default: 3 },
            limit: { type: 'number', default: 1000 },
            groupBy: { type: 'string', default: 'city' },
            showCounts: { type: 'boolean', default: true },
            headingLevel: { type: 'number', default: 3 },
            style: { type: 'string', default: 'cards' },
            accentColor: { type: 'string', default: '#1f4b59' },
            backgroundColor: { type: 'string', default: '#ffffff' },
            linkColor: { type: 'string', default: '#0f766e' }
        },
        edit: function(props) {
            var a = props.attributes;
            function set(key, value) {
                var next = {};
                next[key] = value;
                props.setAttributes(next);
            }
            return el('div', {},
                el(InspectorControls, {},
                    el(PanelBody, { title: t('Sitemap Settings'), initialOpen: true },
                        el(TextControl, {
                            label: t('Post types'),
                            help: t('Comma-separated. Example: pageforge_page,page,post'),
                            value: a.postType,
                            onChange: function(value){ set('postType', value); }
                        }),
                        el(SelectControl, {
                            label: t('Group by'),
                            value: a.groupBy,
                            options: [
                                { label: t('City'), value: 'city' },
                                { label: t('State'), value: 'state' },
                                { label: t('Service'), value: 'service' },
                                { label: t('No grouping'), value: 'none' }
                            ],
                            onChange: function(value){ set('groupBy', value); }
                        }),
                        el(RangeControl, {
                            label: t('Columns'),
                            value: a.columns,
                            min: 1,
                            max: 6,
                            onChange: function(value){ set('columns', value || 1); }
                        }),
                        el(RangeControl, {
                            label: t('Page limit'),
                            value: a.limit,
                            min: 10,
                            max: 5000,
                            step: 10,
                            onChange: function(value){ set('limit', value || 1000); }
                        }),
                        el(ToggleControl, {
                            label: t('Show group counts'),
                            checked: !!a.showCounts,
                            onChange: function(value){ set('showCounts', !!value); }
                        }),
                        el(RangeControl, {
                            label: t('Heading level'),
                            value: a.headingLevel,
                            min: 2,
                            max: 4,
                            onChange: function(value){ set('headingLevel', value || 3); }
                        })
                    ),
                    el(PanelBody, { title: t('Appearance'), initialOpen: false },
                        el(SelectControl, {
                            label: t('Style'),
                            value: a.style,
                            options: [
                                { label: t('Cards'), value: 'cards' },
                                { label: t('Compact'), value: 'compact' },
                                { label: t('Minimal'), value: 'minimal' }
                            ],
                            onChange: function(value){ set('style', value); }
                        }),
                        el(TextControl, {
                            label: t('Accent color'),
                            value: a.accentColor,
                            onChange: function(value){ set('accentColor', value); }
                        }),
                        el(TextControl, {
                            label: t('Background color'),
                            value: a.backgroundColor,
                            onChange: function(value){ set('backgroundColor', value); }
                        }),
                        el(TextControl, {
                            label: t('Link color'),
                            value: a.linkColor,
                            onChange: function(value){ set('linkColor', value); }
                        })
                    )
                ),
                el(ServerSideRender, {
                    block: 'pageforge/sitemap',
                    attributes: a
                })
            );
        },
        save: function() {
            return null;
        }
    });
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.serverSideRender, window.wp.i18n);
JS;
    }

    private static function block_editor_translations(): array {
        $strings = [
            'PageForge Sitemap',
            'Displays generated PageForge pages grouped by city, state, service, or as one list.',
            'Sitemap Settings',
            'Post types',
            'Comma-separated. Example: pageforge_page,page,post',
            'Group by',
            'City',
            'State',
            'Service',
            'No grouping',
            'Columns',
            'Page limit',
            'Show group counts',
            'Heading level',
            'Appearance',
            'Style',
            'Cards',
            'Compact',
            'Minimal',
            'Accent color',
            'Background color',
            'Link color',
        ];

        $translations = [];
        foreach ($strings as $string) {
            $translations[$string] = __($string, 'pageforge');
        }

        return $translations;
    }

    private static function parse_sitemap_post_types(string $raw): array {
        $parts = preg_split('/[\s,]+/', $raw);
        if (!is_array($parts)) {
            $parts = [];
        }

        $post_types = [];
        foreach ($parts as $part) {
            $post_type = sanitize_key($part);
            if ($post_type !== '' && \post_type_exists($post_type)) {
                $post_types[] = $post_type;
            }
        }

        $post_types = array_values(array_unique($post_types));
        return !empty($post_types) ? $post_types : ['pageforge_page'];
    }

    private static function sitemap_group_label(int $post_id, string $group_by): string {
        if ($group_by === 'none') {
            return '';
        }

        $meta_key = '_pageforge_' . $group_by;
        $label = trim((string) \get_post_meta($post_id, $meta_key, true));
        if ($label !== '') {
            return $label;
        }

        $fallbacks = [
            'city'    => __('Other Cities', 'pageforge'),
            'state'   => __('Other States', 'pageforge'),
            'service' => __('Other Services', 'pageforge'),
        ];
        return $fallbacks[$group_by] ?? __('Other Pages', 'pageforge');
    }

    private static function sitemap_inline_css(): string {
        static $printed = false;
        if ($printed) {
            return '';
        }
        $printed = true;
        return '<style id="pageforge-sitemap-css">.pageforge-sitemap{--pf-sitemap-columns:3;--pf-sitemap-accent:#1f4b59;--pf-sitemap-bg:#fff;--pf-sitemap-link:#0f766e;margin:1.5rem 0}.pageforge-sitemap__groups{display:grid;grid-template-columns:repeat(var(--pf-sitemap-columns),minmax(0,1fr));gap:1rem}.pageforge-sitemap__group{background:var(--pf-sitemap-bg);border:1px solid #dbe4ea;border-radius:8px;padding:1rem}.pageforge-sitemap__heading{margin:0 0 .65rem;color:var(--pf-sitemap-accent);font-size:1rem;line-height:1.25}.pageforge-sitemap__heading span{display:inline-block;margin-left:.4rem;color:#64748b;font-size:.78em;font-weight:500}.pageforge-sitemap__list{list-style:none;margin:0;padding:0}.pageforge-sitemap__list li{margin:.4rem 0}.pageforge-sitemap__list a{color:var(--pf-sitemap-link);text-decoration:none}.pageforge-sitemap__list a:hover{text-decoration:underline}.pageforge-sitemap--compact .pageforge-sitemap__group{padding:.75rem}.pageforge-sitemap--minimal .pageforge-sitemap__group{border:0;background:transparent;padding:0}.pageforge-sitemap--minimal .pageforge-sitemap__heading{border-bottom:1px solid #dbe4ea;padding-bottom:.35rem}.pageforge-sitemap--empty{padding:1rem;border:1px solid #dbe4ea;border-radius:8px;background:#fff}@media(max-width:780px){.pageforge-sitemap__groups{grid-template-columns:1fr}}</style>';
    }

	public static function inject_schema() {
		if (!\is_singular()) return;
		global $post;
		if (!$post) return;

		$s = settings_get();
		$business_type = schema_normalize_business_type((string) array_get($s, 'business_type', 'LocalBusiness'));
		$target_country = strtoupper((string) array_get($s, 'target_country', ''));
		$has_ai = ai_has_provider_key($s);

		// Determine country:
		// - If a target country is set, use it.
		// - Else if AI is connected, auto-detect from site locale (global prevalence).
		// - Else default to AU (back-compat with previous behavior).
		$country = $target_country;
		if ($country === '') {
			if ($has_ai) {
				$loc = (string) get_locale();
				// locale like en_US or en_AU
				if (preg_match('/_([A-Z]{2})$/', $loc, $m)) {
					$country = $m[1];
				}
			}
		}
		if ($country === '') {
			$country = 'AU';
		}

		$post_type = (string) ($post->post_type ?? '');
		$thumb = \get_the_post_thumbnail_url($post->ID) ?: \get_site_icon_url();
		$provider_name = \get_bloginfo('name');
		$provider_id = \home_url('/#pageforge-provider');
		$provider = [
			'@id' => $provider_id,
			'@type' => $business_type,
			'name' => $provider_name,
			'url' => \home_url('/'),
		];
		if ($thumb) {
			$provider['image'] = $thumb;
		}

		// Common service/location meta used by generated pages.
		$service = (string) \get_post_meta($post->ID, '_pageforge_service', true);
		$city    = (string) \get_post_meta($post->ID, '_pageforge_city', true);
		$state   = (string) \get_post_meta($post->ID, '_pageforge_state', true);

		$schemas = [];
		$schemas[] = $provider;
		$webpage = [
			'@context' => 'https://schema.org',
			'@type' => 'WebPage',
			'name' => (string) \get_the_title($post->ID),
			'url' => (string) \get_permalink($post->ID),
			'publisher' => ['@id' => $provider_id],
		];
		$excerpt = trim((string) \get_the_excerpt($post->ID));
		if ($excerpt !== '') {
			$webpage['description'] = \wp_strip_all_tags($excerpt);
		}
		if ($thumb) {
			$webpage['image'] = $thumb;
		}
		$schemas[] = $webpage;

		if ($service !== '' && $city !== '') {
			// Service schema for location/service pages.
			$schemas[] = [
				'@context' => 'https://schema.org',
				'@type' => 'Service',
				'name' => $service . ' in ' . $city,
				'serviceType' => $service,
				'areaServed' => [
					'@type' => 'City',
					'name' => $city,
					'address' => [
						'@type' => 'PostalAddress',
						'addressRegion' => $state,
						'addressCountry' => $country,
					]
				],
				'provider' => ['@id' => $provider_id],
			];
		}

		// Product schema for product-like post types (including custom product CPTs).
		$is_product = ($post_type === 'product' || stripos($post_type, 'product') !== false);
		if ($is_product) {
			$schemas[] = [
				'@context' => 'https://schema.org',
				'@type' => 'Product',
				'name' => (string) get_the_title($post->ID),
				'url'  => (string) get_permalink($post->ID),
				'brand' => [
					'@id' => $provider_id,
				],
				'image' => $thumb ? [$thumb] : [],
			];
		}

		// Prefer a single graph so multiple types can coexist.
		$out = [
			'@context' => 'https://schema.org',
			'@graph' => $schemas,
		];
		echo '<script type="application/ld+json">' . \wp_json_encode($out) . '</script>' . "\n";
	}


    // ---------- Runtime Placeholder Replacement ----------
    private static function get_vars_for_post(int $post_id): array {
        $raw_vars = \get_post_meta($post_id, '_pageforge_vars', true);
        $vars = is_array($raw_vars) ? $raw_vars : [];
        // Back-compat: also expose _pageforge_city/_pageforge_state/_pageforge_service
        $city = (string) \get_post_meta($post_id, '_pageforge_city', true);
        $state = (string) \get_post_meta($post_id, '_pageforge_state', true);
        $service = (string) \get_post_meta($post_id, '_pageforge_service', true);
        if ($city !== '' && !isset($vars['City'])) {
            $vars['City'] = $city;
        }
        if ($state !== '' && !isset($vars['State'])) {
            $vars['State'] = $state;
        }
        if ($service !== '' && !isset($vars['Service'])) {
            $vars['Service'] = $service;
        }

        $vars = array_filter($vars, static function($value) {
            return is_scalar($value) && (string) $value !== '';
        });
        return $vars;
    }

    public static function runtime_replace_content($text) {
        if (!is_string($text) && !is_numeric($text)) return $text;
        if (!\is_singular()) return $text;
        global $post; if (!$post) return $text;
        $vars = self::get_vars_for_post($post->ID);
        if (empty($vars)) return $text;

        // Preserve existing escaping behavior of themes/builders by only
        // injecting plain text replacements.
        foreach ($vars as $k => $v) {
            $vars[$k] = wp_kses_post($v);
        }
        return replace_placeholders((string)$text, $vars);
    }

    public static function runtime_replace_rendered_block($content, $block = null) {
        return self::runtime_replace_content($content);
    }

    public static function runtime_replace_builder_content($content, $widget = null) {
        return self::runtime_replace_content($content);
    }

    public static function runtime_replace_title($title, $post_id = 0) {
        if (!is_string($title) && !is_numeric($title)) return $title;
        $pid = $post_id ?: (get_the_ID() ?: 0);
        if (!$pid) return $title;
        $vars = self::get_vars_for_post($pid);
        if (empty($vars)) return $title;

        // Titles must always be plain text.
        $vars = array_map( 'esc_html', $vars );
        return replace_placeholders((string)$title, $vars);
    }

    public static function runtime_replace_title_parts(array $parts): array {
        if (!\is_singular()) return $parts;
        $pid = get_the_ID(); if (!$pid) return $parts;

        // When no dedicated SEO plugin is active, allow PageForge meta title
        // to override the document title, while still supporting placeholders.
        if (!pageforge_seo_plugins_active()) {
            $meta_title = (string) \get_post_meta($pid, '_pageforge_meta_title', true);
            if ($meta_title !== '') {
                $parts['title'] = $meta_title;
            }
        }

        $vars = self::get_vars_for_post($pid);
        if (empty($vars)) return $parts;
        // Document title parts are plain text.
        $vars = array_map( 'esc_html', $vars );
        foreach ($parts as $k => $v) {
            if (is_string($v)) $parts[$k] = replace_placeholders($v, $vars);
        }
        return $parts;
    }

    // [pf var="City" fallback=""]
    public static function shortcode_var($atts = []) {
        $a = shortcode_atts([
            'var' => '',
            'fallback' => '',
        ], $atts, 'pageforge');
        $pid = get_the_ID(); if (!$pid) return (string)$a['fallback'];
        $vars = self::get_vars_for_post($pid);
        if (empty($a['var'])) return (string)$a['fallback'];
        // Support case-insensitive keys
        $key = (string)$a['var'];
        $hay = array_change_key_case($vars, CASE_LOWER);
        $val = $hay[strtolower($key)] ?? '';
        $out = $val !== '' ? $val : (string)$a['fallback'];

        // Shortcodes are inserted into post content; return escaped text.
        return esc_html( (string) $out );
    }

    // Output basic SEO meta tags when no SEO plugin is active.
    public static function inject_basic_seo_meta() {
        if (pageforge_seo_plugins_active()) return;
        if (!\is_singular()) return;
        $post_id = get_the_ID();
        if (!$post_id) return;

        $meta_title = (string) \get_post_meta($post_id, '_pageforge_meta_title', true);
        $meta_desc  = (string) \get_post_meta($post_id, '_pageforge_meta_description', true);
        $focus_kw   = (string) \get_post_meta($post_id, '_pageforge_focus_keyword', true);
        $meta_desc = (string) self::runtime_replace_content($meta_desc);
        $focus_kw = (string) self::runtime_replace_content($focus_kw);

        if ($meta_desc !== '') {
            echo '<meta name="description" content="' . esc_attr($meta_desc) . '" />' . "\n";
        }
        if ($focus_kw !== '') {
            echo '<meta name="keywords" content="' . esc_attr($focus_kw) . '" />' . "\n";
        }
    }
}

Frontend::init();

