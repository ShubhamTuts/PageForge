<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * AI helper utilities + admin AJAX handlers support.
 *
 * NOTE:
 * - Uses provider-aware chat completion helpers from helpers.php.
 * - Keeps prompts strict JSON so admin UI can parse reliably.
 */
final class AI {
	private const OPT_LAST_SEO_REPORT = 'pageforge_last_seo_report';
    private const BLOG_LENGTH_PRESETS = [
        'short'  => 400,
        'medium' => 700,
        'long'   => 1000,
    ];
	/**
	 * Stores per-site AI usage quota for the free plan.
	 * Shape: ['month' => 'YYYY-MM', 'count' => int]
	 */
	private const OPT_AI_QUOTA      = 'pageforge_ai_quota';

	public static function feature_enabled(string $feature): bool {
		// WordPress.org build: no plan-gated features.
		return true;
	}

	public static function freemius_plan_id(): int {
		// WordPress.org build: no licensing SDK.
		return 0;
	}

	/**
	 * Return the current AI quota state for this site.
	 *
	 * @return array{month:string,count:int,limit:int,remaining:int}
	 */
	public static function ai_quota_state(): array {
		// WordPress.org build: we expose a quota shape for UI compatibility,
		// but do not enforce plugin-level quotas.
		$limit = 0;
		$now   = gmdate('Y-m');
		$state = \get_option(self::OPT_AI_QUOTA, []);
		if (!is_array($state)) {
			$state = [];
		}
		$month = isset($state['month']) ? (string) $state['month'] : $now;
		$count = isset($state['count']) ? (int) $state['count'] : 0;
		if ($month !== $now) {
			$month = $now;
			$count = 0;
		}
		$remaining = 0;

		return [
			'month'     => $month,
			'count'     => $count,
			'limit'     => $limit,
			'remaining' => $remaining,
		];
	}

	/**
	 * Enforce free-plan AI quota.
	 *
	 * WordPress.org build: no artificial plugin-level AI quota enforcement.
	 *
	 * @param string $feature Arbitrary feature key (ai_meta, ai_blog, etc.).
	 * @param bool   $consume Whether to increment usage on success.
	 * @return true|\WP_Error
	 */
	public static function check_ai_quota(string $feature, bool $consume = true) {
		// WordPress.org build: no artificial AI quota.
		return true;
	}

    /**
     * Best-effort JSON decoder for model output.
     * Accepts either raw JSON or JSON embedded in extra text.
     */
    public static function decode_json(string $text): array {
        $raw = trim($text);
        $raw = trim(str_replace(["```json", "```"], '', $raw));
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;

        // Try to extract first JSON object.
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $slice = substr($raw, $start, $end - $start + 1);
            $decoded = json_decode($slice, true);
            if (is_array($decoded)) return $decoded;
        }
        return [];
    }

    private static function brand_context_suffix(): string {
        $s = settings_get();
        $brand = trim((string) array_get($s, 'brand_context', ''));
        return $brand !== '' ? "\n\nBrand voice/context:\n" . $brand : '';
    }

    public static function blog_length_presets(): array {
        return self::BLOG_LENGTH_PRESETS;
    }

    public static function normalize_blog_length_preset(string $preset): string {
        $preset = sanitize_key($preset);
        return isset(self::BLOG_LENGTH_PRESETS[$preset]) ? $preset : 'medium';
    }

	    /**
	     * Lightweight helper for chat-based site planning.
	     * Returns: ['reply_markdown' => string]
	     */
	    public static function site_planner_chat(array $args): array {
	        $s = settings_get();
        $ai = ai_provider_credentials($s);

	        $messages = $args['messages'] ?? [];
	        if (!is_array($messages)) {
	            $messages = [];
	        }

	        // Normalize incoming messages (defensive against malformed payloads).
	        $normalized = [];
	        foreach ($messages as $m) {
	            if (!is_array($m)) {
	                continue;
	            }
	            $role    = (string) ($m['role'] ?? '');
	            $content = trim((string) ($m['content'] ?? ''));
	            if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
	                continue;
	            }
	            $normalized[] = [
	                'role'    => $role,
	                'content' => mb_substr($content, 0, 8000),
	            ];
	        }

	        // If nothing survived normalization (e.g. empty or invalid JSON),
	        // fall back to a generic starter prompt instead of hard-failing.
	        if (empty($normalized)) {
	            $normalized[] = [
	                'role'    => 'user',
	                'content' => __(
	                    'Create a complete website plan for my business. Ask me for my business name, location, services, and goals, then propose sitemap, page sections, SEO keywords, and a launch checklist.',
	                    'pageforge'
	                ),
	            ];
	        }

        // Optional context
        $include_urls = !empty($args['include_urls']);
        $post_types = $args['post_types'] ?? ['page', 'post', 'pageforge_page'];
        if (!is_array($post_types) || empty($post_types)) {
            $post_types = ['page', 'post', 'pageforge_page'];
        }
        $post_types = array_values(array_filter(array_map('sanitize_text_field', $post_types)));

        $site = [
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => site_url(),
            'wp_version' => get_bloginfo('version'),
            'language' => get_locale(),
        ];

        $samples = [];
        if ($include_urls) {
            $q = new \WP_Query([
                'post_type' => $post_types,
                'post_status' => 'publish',
                'posts_per_page' => 12,
                'orderby' => 'date',
                'order' => 'DESC',
                'fields' => 'ids',
            ]);
            foreach ($q->posts as $pid) {
                $samples[] = [
                    'id' => (int) $pid,
                    'title' => (string) get_the_title($pid),
                    'url' => (string) get_permalink($pid),
                ];
            }
        }

        $system = "You are PageForge Site Planner.\n" .
            "Output MUST be concise, structured Markdown (no code fences).\n" .
            "When asked for a plan, include: Sitemap, Page-by-page sections, SEO keywords, Internal linking, Schema, Tracking, Launch checklist.\n" .
            "Prefer bullet lists and short headings. Avoid fluff.";

        $context = "Site context JSON:\n" . wp_json_encode([
            'site' => $site,
            'sample_urls' => $samples,
        ]) . self::brand_context_suffix();

	        // Build chat messages: system + context + conversation
	        $chat = [
	            ['role' => 'system', 'content' => $system],
	            ['role' => 'system', 'content' => $context],
	        ];

	        foreach ($normalized as $m) {
	            $chat[] = [
	                'role'    => $m['role'],
	                'content' => $m['content'],
	            ];
	            if (count($chat) >= 18) {
	                break;
	            }
	        }

        $out = openai_chat($chat, (string) $ai['model'], (string) $ai['key'], 2000, (string) $ai['provider']);
        if (is_wp_error($out)) {
            return ['_error' => $out->get_error_message()];
        }
        $reply = trim((string) $out);
        if ($reply === '') {
            return ['_error' => __('Empty AI response', 'pageforge')];
        }

        return ['reply_markdown' => $reply];
    }

    private static function ai_json(string $prompt, int $max_tokens = 900): array {
        $s = settings_get();
        $ai = ai_provider_credentials($s);
        $out = openai_generate(
            $prompt . self::brand_context_suffix(),
            (string) $ai['model'],
            (string) $ai['key'],
            $max_tokens,
            (string) $ai['provider']
        );
        if (is_wp_error($out)) {
            return ['_error' => $out->get_error_message()];
        }
        if (!is_string($out) || trim($out) === '') {
            return ['_error' => __('Empty AI response', 'pageforge')];
        }
        $json = self::decode_json($out);
        if (!$json) {
            return ['_error' => __('AI returned invalid JSON', 'pageforge')];
        }
        return $json;
    }

    public static function generate_meta_for_post(int $post_id): array {
        if ($post_id <= 0) {
            return ['_error' => __('Invalid post', 'pageforge')];
        }
        $post = get_post($post_id);
        if (!$post) {
            return ['_error' => __('Post not found', 'pageforge')];
        }

        $title = (string) $post->post_title;
        $content_plain = wp_strip_all_tags((string) $post->post_content);
        $content_plain = preg_replace('/\s+/', ' ', $content_plain);
        $content_plain = trim(substr($content_plain, 0, 2500));
        $existing_desc = (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true);

        $prompt = "Generate SEO meta for a WordPress page/post.\n" .
            "Return ONLY a JSON object with keys: meta_title, meta_description, focus_keyword.\n" .
            "Rules:\n" .
            "- meta_title <= 60 characters\n" .
            "- meta_description <= 155 characters\n" .
            "- No emojis, no quotes around the whole title/description\n\n" .
            "Post title: {$title}\n" .
            "Existing meta description (may be empty): {$existing_desc}\n" .
            "Content excerpt: {$content_plain}";

        $json = self::ai_json($prompt, 500);
        if (isset($json['_error'])) return $json;

        $meta_title = sanitize_text_field((string) ($json['meta_title'] ?? ''));
        $meta_desc  = sanitize_text_field((string) ($json['meta_description'] ?? ''));
        $focus_kw   = sanitize_text_field((string) ($json['focus_keyword'] ?? ''));

        if ($meta_title === '' || $meta_desc === '') {
            return ['_error' => __('AI did not return meta_title/meta_description', 'pageforge')];
        }

        // Store in PageForge keys
        update_post_meta($post_id, '_pageforge_meta_title', $meta_title);
        update_post_meta($post_id, '_pageforge_meta_description', $meta_desc);
        update_post_meta($post_id, '_pageforge_focus_keyword', $focus_kw);

        // Yoast SEO compatibility
        update_post_meta($post_id, '_yoast_wpseo_title', $meta_title);
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
        if ($focus_kw !== '') {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_kw);
        }

        // Rank Math compatibility
        update_post_meta($post_id, 'rank_math_title', $meta_title);
        update_post_meta($post_id, 'rank_math_description', $meta_desc);
        if ($focus_kw !== '') {
            update_post_meta($post_id, 'rank_math_focus_keyword', $focus_kw);
        }

        return [
            'post_id' => $post_id,
            'meta_title' => $meta_title,
            'meta_description' => $meta_desc,
            'focus_keyword' => $focus_kw,
        ];
    }

	    public static function generate_blog_draft(array $args): array {
	        $topic = sanitize_text_field((string) ($args['topic'] ?? ''));
	        $audience = sanitize_text_field((string) ($args['audience'] ?? ''));
	        $post_type = sanitize_text_field((string) ($args['post_type'] ?? 'post'));
	        $status = sanitize_text_field((string) ($args['status'] ?? 'draft'));
	        $include_image = !empty($args['include_image']);
	        $keywords_raw = (string) ($args['keywords_raw'] ?? '');
            $length_preset = self::normalize_blog_length_preset((string) ($args['content_length'] ?? 'medium'));
            $target_words = (int) (self::BLOG_LENGTH_PRESETS[$length_preset] ?? self::BLOG_LENGTH_PRESETS['medium']);

	        // Parse bulk keywords (comma or newline separated).
	        $keywords = [];
	        if ($keywords_raw !== '') {
	            $pieces = preg_split('/[\r\n,]+/', $keywords_raw);
	            if (is_array($pieces)) {
	                foreach ($pieces as $kw) {
	                    $kw = trim((string) $kw);
	                    if ($kw !== '') {
	                        $keywords[] = $kw;
	                    }
	                }
	            }
	        }
	        if (!empty($keywords)) {
	            $keywords = array_values(array_unique($keywords));
	        }

        if ($topic === '') {
            return ['_error' => __('Topic is required', 'pageforge')];
        }

	        $prompt = "Write a high-quality SEO blog post for WordPress.\n" .
	            "Return ONLY a JSON object with keys: title, slug, excerpt, content_html, tags, image_keyword.\n" .
	            "Rules:\n" .
	            "- content_html must be valid HTML using h2/h3/p/ul/ol only (no markdown)\n" .
	            "- excerpt <= 160 characters\n" .
	            "- tags is an array of 3-8 short strings\n" .
                "- target approximately {$target_words} words and do not exceed {$target_words} words by more than 10 percent\n\n" .
	            "Topic: {$topic}\n" .
	            ($audience !== '' ? "Audience: {$audience}\n" : '') .
	            (!empty($keywords) ? "Target SEO keywords (use naturally throughout the article): " . implode(', ', $keywords) . "\n" : '') .
	            "Tone: helpful, direct, non-salesy. Include actionable steps.";

        $json = self::ai_json($prompt, 2200);
        if (isset($json['_error'])) return $json;

        $title = sanitize_text_field((string) ($json['title'] ?? ''));
        $slug = sanitize_title((string) ($json['slug'] ?? ''));
        $excerpt = sanitize_text_field((string) ($json['excerpt'] ?? ''));
        $content = (string) ($json['content_html'] ?? '');
	        $tags = $json['tags'] ?? [];
	        $image_keyword = sanitize_text_field((string) ($json['image_keyword'] ?? $topic));

        if ($title === '' || $content === '') {
            return ['_error' => __('AI did not return title/content_html', 'pageforge')];
        }

        $post_id = wp_insert_post([
            'post_title' => $title,
            'post_name' => $slug !== '' ? $slug : sanitize_title($title),
            'post_excerpt' => $excerpt,
            'post_content' => $content,
            'post_type' => $post_type,
            'post_status' => $status,
            'comment_status' => 'closed',
        ], true);

        if (is_wp_error($post_id)) {
            return ['_error' => $post_id->get_error_message()];
        }

	        // Merge AI-suggested tags with any explicit target keywords.
	        $final_tags = [];
	        if (is_array($tags)) {
	            foreach ($tags as $t) {
	                $t = sanitize_text_field((string) $t);
	                if ($t !== '') {
	                    $final_tags[] = $t;
	                }
	            }
	        }
	        if (!empty($keywords)) {
	            foreach ($keywords as $kw) {
	                $kw = sanitize_text_field((string) $kw);
	                if ($kw !== '') {
	                    $final_tags[] = $kw;
	                }
	            }
	        }
	        if (!empty($final_tags)) {
	            $final_tags = array_values(array_unique($final_tags));
	            wp_set_post_tags((int) $post_id, $final_tags, false);
	        }

        $thumb_id = 0;
        if ($include_image && self::feature_enabled('ai_images')) {
            $s = settings_get();
            $pexels_key = (string) array_get($s, 'pexels_key', '');
            if ($pexels_key !== '') {
                $thumb_id = pexels_sideload($image_keyword, $pexels_key);
                if ($thumb_id) {
                    set_post_thumbnail((int) $post_id, (int) $thumb_id);
                }
            }
        }

        return [
            'post_id' => (int) $post_id,
            'edit_url' => (string) get_edit_post_link((int) $post_id, 'raw'),
            'title' => $title,
            'thumbnail_id' => (int) $thumb_id,
            'content_length' => $length_preset,
        ];
    }

    public static function generate_seo_report(array $args): array {
        $include_urls = !empty($args['include_urls']);
        $post_types = $args['post_types'] ?? ['page', 'post'];
        if (!is_array($post_types) || empty($post_types)) {
            $post_types = ['page', 'post'];
        }
        $post_types = array_values(array_filter(array_map('sanitize_text_field', $post_types)));

        $site = [
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => site_url(),
            'wp_version' => get_bloginfo('version'),
            'language' => get_locale(),
        ];

        $samples = [];
        if ($include_urls) {
            $q = new \WP_Query([
                'post_type' => $post_types,
                'post_status' => 'publish',
                'posts_per_page' => 20,
                'orderby' => 'date',
                'order' => 'DESC',
                'fields' => 'ids',
            ]);
            foreach ($q->posts as $pid) {
                $samples[] = [
                    'id' => (int) $pid,
                    'title' => (string) get_the_title($pid),
                    'url' => (string) get_permalink($pid),
                ];
            }
        }

        $prompt = "Create a practical SEO optimization plan/report for this WordPress site.\n" .
            "Return ONLY a JSON object with keys: summary, quick_wins, technical_seo, content_strategy, schema, tracking, priorities, markdown.\n" .
            "Rules:\n" .
            "- quick_wins, technical_seo, content_strategy, schema, tracking are arrays of bullet strings\n" .
            "- priorities is an array of objects with keys: item, impact (High/Med/Low), effort (High/Med/Low), steps (array)\n" .
            "- markdown is a complete markdown report (no code fences)\n\n" .
            "Site JSON:\n" . wp_json_encode(['site' => $site, 'sample_urls' => $samples]);

        $json = self::ai_json($prompt, 2000);
        if (isset($json['_error'])) return $json;

        $report = [
            'generated_at' => time(),
            'site' => $site,
            'data' => $json,
        ];

        update_option(self::OPT_LAST_SEO_REPORT, $report, false);

        return $report;
    }

    public static function last_seo_report(): array {
        $v = get_option(self::OPT_LAST_SEO_REPORT, []);
        return is_array($v) ? $v : [];
    }
}

