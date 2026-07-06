<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

final class Plugin {
    private static $instance = null;

    public static function instance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        \add_action('init', [$this, 'load_textdomain'], 0);
        \add_action('init', [$this, 'register_cpts']);
        \add_action('init', [$this, 'maybe_enable_elementor_post_types'], 20);
        \add_action('admin_menu', [$this, 'register_admin_menu']);
        \add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // AJAX
        \add_action('wp_ajax_pageforge_save_settings', [$this, 'ajax_save_settings']);
        \add_action('wp_ajax_pageforge_upload_csv', [$this, 'ajax_upload_csv']);
        \add_action('wp_ajax_pageforge_list_csv', [$this, 'ajax_list_csv']);
        \add_action('wp_ajax_pageforge_sample_csv', [$this, 'ajax_sample_csv']);
        \add_action('wp_ajax_pageforge_start_generation', [$this, 'ajax_start_generation']);
        \add_action('wp_ajax_pageforge_template_meta', [$this, 'ajax_template_meta']);

		// AI Tools
		\add_action('wp_ajax_pageforge_ai_generate_meta', [$this, 'ajax_ai_generate_meta']);
		\add_action('wp_ajax_pageforge_ai_generate_blog', [$this, 'ajax_ai_generate_blog']);
		\add_action('wp_ajax_pageforge_ai_seo_report', [$this, 'ajax_ai_seo_report']);
		\add_action('wp_ajax_pageforge_ai_search_posts', [$this, 'ajax_ai_search_posts']);
		\add_action('wp_ajax_pageforge_ai_site_planner', [$this, 'ajax_ai_site_planner']);

        // Cron
    }

    public function load_textdomain(): void {
        \load_plugin_textdomain(
            'pageforge',
            false,
            dirname(PAGEFORGE_BASENAME) . '/languages'
        );
    }

    public function activate() {
        $this->load_textdomain();
        $this->register_cpts();
        $this->maybe_enable_elementor_post_types();
        \flush_rewrite_rules();
    }

    public function deactivate() {
        \flush_rewrite_rules();
    }

    public function register_cpts() {
        $settings = settings_get();
        $cpt_base = isset($settings['cpt_base']) && $settings['cpt_base'] !== '' ? sanitize_title($settings['cpt_base']) : 'pageforge';

        // Template CPT
        \register_post_type('pageforge_template', [
            'labels' => [
                'name' => __('PageForge Templates', 'pageforge'),
                'singular_name' => __('PageForge Template', 'pageforge'),
                'add_new_item' => __('Add New Template', 'pageforge'),
                'edit_item' => __('Edit Template', 'pageforge'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => ['title', 'editor', 'revisions'],
        ]);

        // Optional generated CPT (user can switch to "page")
        \register_post_type('pageforge_page', [
            'labels' => [
                'name' => __('PageForge Pages', 'pageforge'),
                'singular_name' => __('PageForge Page', 'pageforge'),
            ],
            'public' => true,
            'has_archive' => true,
            'show_in_rest' => true,
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions'],
            'rewrite' => ['slug' => $cpt_base, 'with_front' => false],
        ]);
        // Ensure supports arrays are filtered
        \add_post_type_support('pageforge_template', ['custom-fields']);
        \add_post_type_support('pageforge_page', ['author']);
    }

    public function maybe_enable_elementor_post_types(): void {
        if (!defined('ELEMENTOR_VERSION') && !did_action('elementor/loaded') && !class_exists('\\Elementor\\Plugin')) {
            return;
        }

        $supported = \get_option('elementor_cpt_support', null);
        if (!is_array($supported)) {
            $supported = ['page', 'post'];
        }

        if (!in_array('pageforge_page', $supported, true)) {
            $supported[] = 'pageforge_page';
            \update_option('elementor_cpt_support', array_values(array_unique($supported)));
        }
    }

	public function register_admin_menu() {
		\add_menu_page(
			__('PageForge', 'pageforge'),
			__('PageForge', 'pageforge'),
			'manage_options',
			'pageforge',
			[Admin::class, 'render_dashboard'],
			'dashicons-hammer',
			60
		);
		\add_submenu_page('pageforge', __('Pages', 'pageforge'), __('Pages', 'pageforge'), 'edit_posts', 'pageforge-pages', [Admin::class, 'render_pages']);
		\add_submenu_page('pageforge', __('Data Sources', 'pageforge'), __('Data Sources', 'pageforge'), 'upload_files', 'pageforge-datasources', [Admin::class, 'render_datasources']);
		\add_submenu_page('pageforge', __('Templates', 'pageforge'), __('Templates', 'pageforge'), 'edit_posts', 'pageforge-templates', [Admin::class, 'render_templates']);
		\add_submenu_page('pageforge', __('Blocks & Shortcodes', 'pageforge'), __('Blocks', 'pageforge'), 'edit_posts', 'pageforge-shortcodes', [Admin::class, 'render_shortcodes']);
		\add_submenu_page('pageforge', __('AI Site Planner', 'pageforge'), __('AI Site Planner', 'pageforge'), 'edit_posts', 'pageforge-ai', [Admin::class, 'render_ai_tools']);
		\add_submenu_page('pageforge', __('Meta Title & Description', 'pageforge'), __('Meta Title & Description', 'pageforge'), 'edit_posts', 'pageforge-meta', [Admin::class, 'render_ai_meta']);
		\add_submenu_page('pageforge', __('Blog Generator', 'pageforge'), __('Blog Generator', 'pageforge'), 'edit_posts', 'pageforge-blog', [Admin::class, 'render_ai_blog']);
		// WordPress.org build: no licensing/upgrade screens.
		\add_submenu_page('pageforge', __('Settings', 'pageforge'), __('Settings', 'pageforge'), 'manage_options', 'pageforge-settings', [Admin::class, 'render_settings']);
	}

	public function enqueue_admin_assets($hook) {
        // Hooks look like:
        // - toplevel_page_pageforge
        // - pageforge_page_pageforge-pages
        // - pageforge_page_pageforge-ai
        if (strpos((string)$hook, 'pageforge') === false) return;
        \wp_enqueue_style('pageforge-admin', PAGEFORGE_URL . 'assets/admin.css', [], PAGEFORGE_VERSION);
        \wp_enqueue_script('jquery');

        // Local markdown renderer (Plugin Repo: external scripts disallowed).
        \wp_enqueue_script(
            'pageforge-marked',
            PAGEFORGE_URL . 'assets/marked.min.js',
            [],
            PAGEFORGE_VERSION,
            true
        );

		\wp_enqueue_script('pageforge-admin', PAGEFORGE_URL . 'assets/admin.js', ['jquery', 'pageforge-marked'], PAGEFORGE_VERSION, true);
		\wp_localize_script('pageforge-admin', 'PAGEFORGE', [
			'ajax'    => \admin_url('admin-ajax.php'),
			'nonce'   => \wp_create_nonce('pageforge_admin'),
			'limits'  => [
				'free_rows' => PAGEFORGE_FREE_ROW_LIMIT,
				'ai_quota'  => AI::ai_quota_state(),
			],
			'plan_id' => AI::freemius_plan_id(),
            'active_provider' => ai_get_provider(),
            'i18n' => $this->admin_script_i18n(),
		]);
	}

    private function admin_script_i18n(): array {
        return [
            'settingsSaved' => __('Settings saved', 'pageforge'),
            'errorPrefix' => __('Error:', 'pageforge'),
            'uploadedPrefix' => __('Uploaded:', 'pageforge'),
            'uploadFailedPrefix' => __('Upload failed:', 'pageforge'),
            'loading' => __('Loading...', 'pageforge'),
            'unableToLoadCsvs' => __('Unable to load CSVs', 'pageforge'),
            'noCsvsYetTable' => __('No CSVs yet.', 'pageforge'),
            'noCsvsYet' => __('No CSVs yet', 'pageforge'),
            'selectCsv' => __('Select CSV', 'pageforge'),
            'untitled' => __('Untitled', 'pageforge'),
            'selectTemplateAndCsv' => __('Select template and CSV', 'pageforge'),
            'startingGeneration' => __('Starting generation...', 'pageforge'),
            'processedPrefix' => __('Processed:', 'pageforge'),
            'queuedRowsPrefix' => __('Queued rows:', 'pageforge'),
            'errorsPrefix' => __('Errors:', 'pageforge'),
            'selectPostFirst' => __('Select a post first', 'pageforge'),
            'generating' => __('Generating...', 'pageforge'),
            'saved' => __('Saved', 'pageforge'),
            'forPost' => __('for Post', 'pageforge'),
            'post' => __('Post', 'pageforge'),
            'title' => __('Title', 'pageforge'),
            'description' => __('Description', 'pageforge'),
            'keyword' => __('Keyword', 'pageforge'),
            'dash' => __('-', 'pageforge'),
            'workingOffset' => __('Working... (offset %s)', 'pageforge'),
            'bulkStartFailed' => __('Bulk request failed to start (JS error).', 'pageforge'),
            'stoppedJsError' => __('Stopped (JS error).', 'pageforge'),
            'ok' => __('OK', 'pageforge'),
            'savedLower' => __('saved', 'pageforge'),
            'processedProgress' => __('Processed %1$s / %2$s...', 'pageforge'),
            'done' => __('Done.', 'pageforge'),
            'stoppedError' => __('Stopped (error).', 'pageforge'),
            'requestFailed' => __('Request failed (nonce / permissions / network).', 'pageforge'),
            'stoppedRequestFailed' => __('Stopped (request failed).', 'pageforge'),
            'starting' => __('Starting...', 'pageforge'),
            'stopped' => __('Stopped.', 'pageforge'),
            'enterTopic' => __('Enter a topic', 'pageforge'),
            'generatingDraft' => __('Generating draft...', 'pageforge'),
            'draftCreated' => __('Draft created', 'pageforge'),
            'editPost' => __('Edit Post', 'pageforge'),
            'featuredImageAttached' => __('Featured image attached (attachment #%s)', 'pageforge'),
            'generatingReport' => __('Generating report...', 'pageforge'),
            'generatedAt' => __('Generated at:', 'pageforge'),
            'newSitePlan' => __('New site plan', 'pageforge'),
            'justNow' => __('just now', 'pageforge'),
            'minAgo' => __('%s min ago', 'pageforge'),
            'hrAgo' => __('%s hr ago', 'pageforge'),
            'dayAgo' => __('%s day ago', 'pageforge'),
            'daysAgo' => __('%s days ago', 'pageforge'),
            'updated' => __('Updated %s', 'pageforge'),
            'conversation' => __('Conversation', 'pageforge'),
            'assistant' => __('Assistant', 'pageforge'),
            'you' => __('You', 'pageforge'),
            'plannerRequestFailed' => __('Error: Request failed. Check network / API key.', 'pageforge'),
        ];
    }

    // ----------------- AJAX -----------------
	public function ajax_save_settings() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('manage_options')) \wp_send_json_error(__('Unauthorized', 'pageforge'));
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $data = isset($_POST['data']) ? wp_unslash($_POST['data']) : [];
        if (is_string($data)) {
            \parse_str($data, $data);
        }
		$existing = settings_get();
        $ai_provider = ai_normalize_provider((string) ($data['ai_provider'] ?? 'openai'));
        $openai_model = sanitize_text_field((string) ($data['openai_model'] ?? ai_provider_default_model('openai')));
        if (!isset(ai_provider_model_options('openai')[$openai_model])) {
            $openai_model = ai_provider_default_model('openai');
        }
        $deepseek_model = sanitize_text_field((string) ($data['deepseek_model'] ?? ai_provider_default_model('deepseek')));
        if (!isset(ai_provider_model_options('deepseek')[$deepseek_model])) {
            $deepseek_model = ai_provider_default_model('deepseek');
        }
        $anthropic_model = sanitize_text_field((string) ($data['anthropic_model'] ?? ai_provider_default_model('anthropic')));
        if (!isset(ai_provider_model_options('anthropic')[$anthropic_model])) {
            $anthropic_model = ai_provider_default_model('anthropic');
        }
        $gemini_model = sanitize_text_field((string) ($data['gemini_model'] ?? ai_provider_default_model('gemini')));
        if (!isset(ai_provider_model_options('gemini')[$gemini_model])) {
            $gemini_model = ai_provider_default_model('gemini');
        }
		$clean = [
            'ai_provider'  => $ai_provider,
			'openai_key'   => sanitize_text_field( isset($data['openai_key']) ? (string) wp_unslash($data['openai_key']) : '' ),
			'openai_model' => $openai_model,
            'anthropic_key' => sanitize_text_field(isset($data['anthropic_key']) ? (string) wp_unslash($data['anthropic_key']) : ''),
            'anthropic_model' => $anthropic_model,
            'gemini_key' => sanitize_text_field(isset($data['gemini_key']) ? (string) wp_unslash($data['gemini_key']) : ''),
            'gemini_model' => $gemini_model,
            'deepseek_key' => sanitize_text_field(isset($data['deepseek_key']) ? (string) wp_unslash($data['deepseek_key']) : ''),
            'deepseek_model' => $deepseek_model,
			'pexels_key'   => sanitize_text_field($data['pexels_key'] ?? ''),
			'brand_context'=> sanitize_textarea_field($data['brand_context'] ?? ''),
			'business_type'=> schema_normalize_business_type(sanitize_text_field($data['business_type'] ?? 'LocalBusiness')),
			'target_country'=> strtoupper(sanitize_text_field($data['target_country'] ?? '')),
			'post_type'    => sanitize_text_field($data['post_type'] ?? 'pageforge_page'),
			'cpt_base'     => sanitize_title($data['cpt_base'] ?? 'pageforge'),
			'daily_limit'  => max(1, (int) ($data['daily_limit'] ?? 10)),
			'ga4_measurement_id' => sanitize_text_field($data['ga4_measurement_id'] ?? ''),
			'ga4_api_secret'     => sanitize_text_field($data['ga4_api_secret'] ?? ''),
		];
		// Merge: any key not present in this request should retain its existing value.
		// But note: empty strings *are* meaningful, so we only preserve when the
		// key is absent from $data.
		$merged = is_array($existing) ? $existing : [];
		foreach ($clean as $k => $v) {
			if (array_key_exists($k, $data) || in_array($k, ['daily_limit'], true)) {
				$merged[$k] = $v;
			}
		}
		settings_update($merged);
				    // Re-register CPTs with new base and flush rewrites
				    $this->register_cpts();
				    \flush_rewrite_rules();
		\wp_send_json_success($merged);
	}

    public function ajax_upload_csv() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('upload_files')) \wp_send_json_error(__('Unauthorized', 'pageforge'));
        if (empty($_FILES['file'])) \wp_send_json_error(__('No file', 'pageforge'));
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $id = \media_handle_upload('file', 0);
        if (\is_wp_error($id)) \wp_send_json_error($id->get_error_message());
        \update_post_meta($id, '_pageforge_csv', 1);
        // Analytics event
        $s = settings_get();
        ga4_send_event('csv_upload', ['attachment_id' => $id], (string)($s['ga4_measurement_id'] ?? ''), (string)($s['ga4_api_secret'] ?? ''));
        \wp_send_json_success(['id' => $id, 'url' => \wp_get_attachment_url($id), 'title' => \get_the_title($id)]);
    }

    public function ajax_list_csv() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        // Allow editors (edit_posts) to list CSVs even if they cannot upload files
        if (!\current_user_can('edit_posts') && !\current_user_can('upload_files')) \wp_send_json_error(__('Unauthorized', 'pageforge'));
        $q = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 50,
            // This meta_query is bounded (50 attachments) and uses a dedicated meta flag.
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            'meta_query' => [
                [ 'key' => '_pageforge_csv', 'value' => 1 ]
            ],
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        $items = [];
        foreach ($q->posts as $p) {
            $items[] = [ 'id' => $p->ID, 'title' => $p->post_title, 'url' => \wp_get_attachment_url($p->ID) ];
        }
        \wp_send_json_success($items);
    }

    public function ajax_sample_csv() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('manage_options')) \wp_send_json_error(__('Unauthorized', 'pageforge'));
        $csv = "City,State,Service\nSydney,NSW,Web Development\nMelbourne,VIC,SEO\n";
        \wp_send_json_success(['content' => $csv, 'filename' => 'pageforge-sample.csv']);
    }

	public function ajax_template_meta() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('edit_posts')) \wp_send_json_error(__('Unauthorized', 'pageforge'));
		$id = isset($_POST['template_id']) ? absint( wp_unslash( $_POST['template_id'] ) ) : 0;
        if (!$id) \wp_send_json_error(__('Missing', 'pageforge'));
        $meta = [
            'title_pattern' => \get_post_meta($id, '_pageforge_title_pattern', true),
            'slug_pattern'  => \get_post_meta($id, '_pageforge_slug_pattern', true),
            'post_type'     => \get_post_meta($id, '_pageforge_post_type', true) ?: 'pageforge_page',
            'post_status'   => \get_post_meta($id, '_pageforge_post_status', true),
        ];
        \wp_send_json_success($meta);
    }

	public function ajax_start_generation() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('edit_posts')) \wp_send_json_error(__('Unauthorized', 'pageforge'));
		$template_id = isset($_POST['template_id']) ? absint( wp_unslash( $_POST['template_id'] ) ) : 0;
		$csv_id      = isset($_POST['csv_id']) ? absint( wp_unslash( $_POST['csv_id'] ) ) : 0;
        $status      = sanitize_text_field( isset( $_POST['status'] ) ? wp_unslash( $_POST['status'] ) : 'draft' );
        $mode        = sanitize_text_field( isset( $_POST['mode'] ) ? wp_unslash( $_POST['mode'] ) : 'instant' );
        if (!in_array($status, ['publish', 'private', 'draft', 'pending'], true)) {
            $status = 'draft';
        }

        if (!$template_id || !$csv_id) \wp_send_json_error(__('Missing template or data source', 'pageforge'));

        $file = \get_attached_file($csv_id);
        [$headers, $rows] = parse_csv($file);
        if (empty($rows)) \wp_send_json_error(__('CSV has no rows', 'pageforge'));

        $messages = [];
        if ($mode !== 'instant') {
            $messages[] = sprintf(
                /* translators: %s: selected generation mode */
                __('%s mode is not available in this free build. Running instantly instead.', 'pageforge'),
                ucfirst($mode)
            );
        }

        $total_rows = count($rows);
        $row_limit = (int) PAGEFORGE_FREE_ROW_LIMIT;
        if ($row_limit > 0 && $total_rows > $row_limit) {
            $rows = array_slice($rows, 0, $row_limit);
            $messages[] = sprintf(
                /* translators: 1: row limit, 2: skipped rows */
                __('Free build processes up to %1$d pages per run. %2$d rows were skipped.', 'pageforge'),
                $row_limit,
                $total_rows - $row_limit
            );
        }

        $gen = new Generator();
        $result = $gen->bulk_generate($template_id, $rows, $status);

        // Analytics event
        $s = settings_get();
        ga4_send_event('generation_instant', ['rows' => (int) $result['processed']], (string)($s['ga4_measurement_id'] ?? ''), (string)($s['ga4_api_secret'] ?? ''));

        $response = [
            'processed' => $result['processed'],
            'errors' => $result['errors'],
            'total_rows' => $total_rows,
        ];
        if (!empty($messages)) {
            $response['notice'] = implode(' ', $messages);
        }
        \wp_send_json_success($response);
    }

    // ----------------- AI Tools AJAX -----------------
	public function ajax_ai_search_posts() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('edit_posts')) \wp_send_json_error(__('Unauthorized', 'pageforge'));
        $q = sanitize_text_field( (string) ( isset( $_POST['q'] ) ? wp_unslash( $_POST['q'] ) : '' ) );
        if ($q === '') \wp_send_json_success([]);
        $query = new \WP_Query([
            's' => $q,
            'post_type' => ['post', 'page', 'pageforge_page'],
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 10,
            'orderby' => 'relevance',
            'fields' => 'ids',
        ]);
        $items = [];
        foreach ($query->posts as $pid) {
            $p = \get_post($pid);
            if (!$p) continue;
            $items[] = [
                'id' => (int)$pid,
                'title' => (string) get_the_title($pid),
                'type' => (string) $p->post_type,
                'status' => (string) $p->post_status,
            ];
        }
        \wp_send_json_success($items);
    }

	public function ajax_ai_generate_meta() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('edit_posts')) \wp_send_json_error(__('Unauthorized', 'pageforge'));

		// WordPress.org build: feature always enabled.

  $post_id = isset($_POST['post_id']) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
  $out = AI::generate_meta_for_post($post_id);
        if (isset($out['_error'])) \wp_send_json_error($out['_error']);
		\wp_send_json_success($out);
	}


	public function ajax_ai_generate_blog() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('edit_posts')) \wp_send_json_error(__('Unauthorized', 'pageforge'));

		// WordPress.org build: feature always enabled.

        $args = [
            'topic'        => sanitize_text_field( (string) ( isset( $_POST['topic'] ) ? wp_unslash( $_POST['topic'] ) : '' ) ),
            'audience'     => sanitize_text_field( (string) ( isset( $_POST['audience'] ) ? wp_unslash( $_POST['audience'] ) : '' ) ),
            'post_type'    => sanitize_text_field( (string) ( isset( $_POST['post_type'] ) ? wp_unslash( $_POST['post_type'] ) : 'post' ) ),
            'status'       => sanitize_text_field( (string) ( isset( $_POST['status'] ) ? wp_unslash( $_POST['status'] ) : 'draft' ) ),
            'include_image'=> !empty( $_POST['include_image'] ),
            'keywords_raw' => sanitize_textarea_field( (string) ( isset( $_POST['keywords_raw'] ) ? wp_unslash( $_POST['keywords_raw'] ) : '' ) ),
            'content_length' => sanitize_key((string) (isset($_POST['content_length']) ? wp_unslash($_POST['content_length']) : 'medium')),
        ];
        $out = AI::generate_blog_draft($args);
        if (isset($out['_error'])) \wp_send_json_error($out['_error']);
        \wp_send_json_success($out);
    }

	public function ajax_ai_seo_report() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('manage_options')) \wp_send_json_error(__('Unauthorized', 'pageforge'));

		// WordPress.org build: feature always enabled.

  // Read + unslash + sanitize (PHPCS may not be able to infer sanitization across array_map).
  $post_types_input = [];
  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
  if ( isset( $_POST['post_types'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
   // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
   $post_types_input = wp_unslash( $_POST['post_types'] );
  }
  $post_types = array_values(array_filter(array_map('sanitize_key', (array) $post_types_input)));
  if (empty($post_types)) {
   $post_types = ['page', 'post'];
  }
  $args = [
   'include_urls' => !empty( $_POST['include_urls'] ),
   'post_types' => $post_types,
  ];
        $out = AI::generate_seo_report($args);
        if (isset($out['_error'])) \wp_send_json_error($out['_error']);
        \wp_send_json_success($out);
    }

	public function ajax_ai_site_planner() {
		\check_ajax_referer('pageforge_admin', 'nonce');
		if (!\current_user_can('edit_posts')) \wp_send_json_error(__('Unauthorized', 'pageforge'));

		// Gate using Site Planner entitlement (Starter+).
		// WordPress.org build: feature always enabled.

  // Messages come as JSON string; keep raw content for AI but unslash.
  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
  $raw = isset($_POST['messages']) ? wp_unslash($_POST['messages']) : [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $raw = $decoded;
            } else {
                $raw = [];
            }
        }
        if (!is_array($raw)) {
            $raw = [];
        }

  $include_urls = !empty($_POST['include_urls']);
  // Read + unslash + sanitize (PHPCS may not be able to infer sanitization across array_map).
  $post_types_input = [];
  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
  if ( isset( $_POST['post_types'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
   // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
   $post_types_input = wp_unslash( $_POST['post_types'] );
  }
  $post_types = array_values(array_filter(array_map('sanitize_key', (array) $post_types_input)));
  if (empty($post_types)) {
   $post_types = ['page', 'post', 'pageforge_page'];
  }

        $out = AI::site_planner_chat([
            'messages' => $raw,
            'include_urls' => $include_urls,
            'post_types' => $post_types,
        ]);

        if (isset($out['_error'])) \wp_send_json_error($out['_error']);
        \wp_send_json_success($out);
    }
}

