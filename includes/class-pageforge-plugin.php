<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

final class Plugin {
    private static $instance = null;

    /**
     * Shared rewrite base used when both content namespaces publish generated
     * pages under one configured base. Empty when each namespace owns its own
     * base and WordPress can resolve the request without help.
     */
    private $generated_rewrite_base = '';

    /**
     * Namespaces served by the shared rewrite base, in resolution order.
     */
    private $generated_rewrite_namespaces = [];

    public static function instance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        \add_action('init', [$this, 'load_textdomain'], 0);
        \add_action('init', [$this, 'register_cpts']);
        \add_action('init', [$this, 'maybe_enable_elementor_post_types'], 20);
        \add_filter('query_vars', [$this, 'register_generated_rewrite_query_var']);
        \add_filter('request', [$this, 'resolve_generated_rewrite_request']);
        \add_filter('post_type_link', [$this, 'filter_generated_post_link'], 10, 4);
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

        // MCP / AI Clients
        \add_action('wp_ajax_pageforge_mcp_toggle', [$this, 'ajax_mcp_toggle']);
        \add_action('wp_ajax_pageforge_mcp_list_credentials', [$this, 'ajax_mcp_list_credentials']);
        \add_action('wp_ajax_pageforge_mcp_create_credential', [$this, 'ajax_mcp_create_credential']);
        \add_action('wp_ajax_pageforge_mcp_rotate_credential', [$this, 'ajax_mcp_rotate_credential']);
        \add_action('wp_ajax_pageforge_mcp_revoke_credential', [$this, 'ajax_mcp_revoke_credential']);
        \add_action('wp_ajax_pageforge_mcp_oauth_status', [$this, 'ajax_mcp_oauth_status']);
        \add_action('wp_ajax_pageforge_mcp_revoke_grant', [$this, 'ajax_mcp_revoke_grant']);
        \add_action('wp_ajax_pageforge_mcp_create_oauth_client', [$this, 'ajax_mcp_create_oauth_client']);
        \add_action('wp_ajax_pageforge_mcp_revoke_oauth_client', [$this, 'ajax_mcp_revoke_oauth_client']);

        // Cron
    }

    // ----------------- MCP AJAX -----------------

    public function ajax_mcp_toggle() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('manage_options')) \wp_send_json_error(__('Unauthorized', 'pageforge'));
        $enabled = !empty($_POST['enabled']);
        $settings = settings_get();
        $settings['mcp_enabled'] = $enabled;
        settings_update($settings);
        \wp_send_json_success(['enabled' => $enabled]);
    }

    public function ajax_mcp_list_credentials() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('manage_options')) \wp_send_json_error(__('Unauthorized', 'pageforge'));

        $clients = Oauth_Clients::all_redacted();
        $grants = [];
        foreach (Oauth_Tokens::grants_redacted() as $grant) {
            $client = $clients[$grant['client_id']] ?? null;
            $grant['client_name'] = is_array($client) ? (string) ($client['client_name'] ?? '') : (string) $grant['client_id'];
            $grants[] = $grant;
        }

        \wp_send_json_success([
            'credentials' => array_values(Mcp_Credentials::all_redacted()),
            'grants' => $grants,
            'oauth_clients' => array_values($clients),
            'client_states' => $this->mcp_connector_states($grants),
        ]);
    }

    /**
     * Creates OAuth client credentials for a connector that asks for a Client ID
     * and Secret. Deliberately separate from AI provider API keys: those are
     * different credentials for different systems, and reusing one for the other
     * is a support problem waiting to happen.
     */
    public function ajax_mcp_create_oauth_client() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('manage_options')) \wp_send_json_error(__('Unauthorized', 'pageforge'));

        $name = \sanitize_text_field((string) (isset($_POST['name']) ? wp_unslash($_POST['name']) : ''));
        $raw_uris = isset($_POST['redirect_uris']) ? wp_unslash($_POST['redirect_uris']) : [];
        $raw_uris = is_array($raw_uris) ? $raw_uris : [$raw_uris];

        // Rejects anything that is not https (or a loopback http address) and
        // anything carrying a fragment, so a redirect URI cannot leak an
        // authorization code in cleartext.
        $redirect_uris = Oauth_Clients::sanitize_redirect_uris(array_map('strval', $raw_uris));
        if (empty($redirect_uris)) {
            \wp_send_json_error(__('Enter an HTTPS redirect URI, or an http://127.0.0.1 address for a local client.', 'pageforge'));
        }

        $created = Oauth_Clients::create_preregistered($name, $redirect_uris, true);
        \wp_send_json_success([
            'client_id' => $created['client_id'],
            'client_secret' => $created['client_secret'],
            'redirect_uris' => $redirect_uris,
        ]);
    }

    public function ajax_mcp_revoke_oauth_client() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('manage_options')) \wp_send_json_error(__('Unauthorized', 'pageforge'));
        $client_id = \sanitize_text_field((string) (isset($_POST['client_id']) ? wp_unslash($_POST['client_id']) : ''));
        if (!Oauth_Clients::revoke($client_id)) \wp_send_json_error(__('OAuth client not found', 'pageforge'));
        \wp_send_json_success(['client_id' => $client_id]);
    }

    /**
     * Evidence-based connector state.
     *
     * "Connected" is only ever reported when PageForge has actually observed an
     * OAuth grant for that connector. Before that it says what is true:
     * PageForge is ready and waiting, or MCP is off, or HTTPS is missing. The
     * previous UI reported success as soon as a credential existed, which told
     * the operator nothing about whether the client could reach the site.
     *
     * @param array $grants Already-redacted OAuth grants.
     * @return array<string, array{label:string, badge:string}>
     */
    private function mcp_connector_states(array $grants): array {
        $enabled = !empty(settings_get()['mcp_enabled']);
        $https = Request::site_is_https();

        $states = [];
        foreach (['chatgpt' => 'ChatGPT', 'claude' => 'Claude'] as $key => $label) {
            if (!$enabled) {
                $states[$key] = ['label' => __('MCP disabled', 'pageforge'), 'badge' => 'warn'];
                continue;
            }
            if (!$https) {
                $states[$key] = ['label' => __('HTTPS required', 'pageforge'), 'badge' => 'bad'];
                continue;
            }

            $matched = null;
            foreach ($grants as $grant) {
                $haystack = strtolower((string) ($grant['client_name'] ?? '') . ' ' . (string) ($grant['client_id'] ?? ''));
                if (false !== strpos($haystack, $key)) {
                    $matched = $grant;
                    break;
                }
            }

            if (null === $matched) {
                $states[$key] = [
                    'label' => sprintf(
                        /* translators: %s: connector name, e.g. ChatGPT. */
                        __('Ready for %s · awaiting connection', 'pageforge'),
                        $label
                    ),
                    'badge' => 'warn',
                ];
                continue;
            }

            $states[$key] = [
                'label' => sprintf(
                    /* translators: %s: human-readable time difference. */
                    __('Connected · approved %s ago', 'pageforge'),
                    (string) \human_time_diff((int) $matched['issued'], time())
                ),
                'badge' => 'ok',
            ];
        }

        return $states;
    }

    /**
     * Reports whether PageForge's own OAuth discovery chain is serveable.
     *
     * Deliberately scoped to what this site can verify about itself. It never
     * claims a client is connected, because that can only be established by
     * observing an authenticated request.
     */
    public function ajax_mcp_oauth_status() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('manage_options')) \wp_send_json_error(__('Unauthorized', 'pageforge'));

        $checks = Mcp_Diagnostics::run();
        $overall = Mcp_Diagnostics::overall($checks);

        $labels = [
            Mcp_Diagnostics::OK => [__('Ready · OAuth discovery is being served correctly', 'pageforge'), 'ok'],
            Mcp_Diagnostics::WARN => [__('Ready with warnings · see Connection diagnostics below', 'pageforge'), 'warn'],
            Mcp_Diagnostics::UNKNOWN => [__('Partly unverifiable · see Connection diagnostics below', 'pageforge'), 'warn'],
            Mcp_Diagnostics::FAIL => [__('Not ready · see Connection diagnostics below', 'pageforge'), 'bad'],
        ];
        [$label, $badge] = $labels[$overall] ?? $labels[Mcp_Diagnostics::UNKNOWN];

        \wp_send_json_success([
            'state' => $overall,
            'label' => $label,
            'badge' => $badge,
            'checks' => $checks,
        ]);
    }

    public function ajax_mcp_revoke_grant() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('manage_options')) \wp_send_json_error(__('Unauthorized', 'pageforge'));
        $family = \sanitize_text_field((string) (isset($_POST['family']) ? wp_unslash($_POST['family']) : ''));
        if ('' === $family) \wp_send_json_error(__('Missing grant reference', 'pageforge'));
        // Revoking one member revokes the whole grant family, which is what
        // "disconnect this client" means to the operator.
        Oauth_Tokens::revoke_family($family);
        \wp_send_json_success(['family' => $family]);
    }

    public function ajax_mcp_create_credential() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('manage_options')) \wp_send_json_error(__('Unauthorized', 'pageforge'));
        $label = \sanitize_text_field((string) (isset($_POST['label']) ? wp_unslash($_POST['label']) : ''));
        $client = \sanitize_key((string) (isset($_POST['client']) ? wp_unslash($_POST['client']) : 'generic'));

        // Scopes come from the shared catalog, reduced to what this edition can
        // actually grant, so a PAT never promises a capability the site lacks.
        // Free resolves this to read + preview; the transport enforces the same
        // limit independently.
        $scopes = Mcp_Scopes::advertised();

        $created = Mcp_Credentials::create($label, $client, $scopes);
        if (\is_wp_error($created)) {
            \wp_send_json_error($created->get_error_message());
        }

        \wp_send_json_success([
            'id' => $created['id'],
            'token' => $created['token'],
            'label' => $label,
            'scopes' => $scopes,
        ]);
    }

    public function ajax_mcp_rotate_credential() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('manage_options')) \wp_send_json_error(__('Unauthorized', 'pageforge'));
        $id = \sanitize_key((string) (isset($_POST['id']) ? wp_unslash($_POST['id']) : ''));
        $token = Mcp_Credentials::rotate($id);
        if (null === $token) \wp_send_json_error(__('Credential not found', 'pageforge'));
        \wp_send_json_success(['id' => $id, 'token' => $token]);
    }

    public function ajax_mcp_revoke_credential() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('manage_options')) \wp_send_json_error(__('Unauthorized', 'pageforge'));
        $id = \sanitize_key((string) (isset($_POST['id']) ? wp_unslash($_POST['id']) : ''));
        if (!Mcp_Credentials::revoke($id)) \wp_send_json_error(__('Credential not found', 'pageforge'));
        \wp_send_json_success(['id' => $id]);
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
        Upgrade::run();
        $this->register_cpts();
        $this->maybe_enable_elementor_post_types();
        Upgrade::maybe_upgrade_rewrite_rules(true);
    }

    public function deactivate() {
        \flush_rewrite_rules();
    }

    public function register_cpts() {
        // The namespace must be resolved before any post type is registered so
        // generation, admin lists and rewrites all agree for the whole request.
        Upgrade::run();

        $settings = settings_get();
        $configured_base = isset($settings['cpt_base']) && $settings['cpt_base'] !== ''
            ? sanitize_title($settings['cpt_base'])
            : '';
        $selected = Compatibility::namespace();
        $alternate = 'pageforge' === $selected ? 'pf' : 'pageforge';
        $counts = Upgrade::content_counts();

        // Templates: the selected namespace owns the UI. The alternate namespace
        // is only registered when it actually holds content, so mixed sites stay
        // readable without showing a duplicate admin menu.
        $template_namespaces = [$selected];
        if ((int) ($counts[$alternate . '_templates'] ?? 0) > 0) {
            $template_namespaces[] = $alternate;
        }

        foreach ($template_namespaces as $namespace) {
            $post_type = Compatibility::post_type('template', $namespace);
            \register_post_type($post_type, [
                'labels' => [
                    'name' => __('PageForge Templates', 'pageforge'),
                    'singular_name' => __('PageForge Template', 'pageforge'),
                    'add_new_item' => __('Add New Template', 'pageforge'),
                    'edit_item' => __('Edit Template', 'pageforge'),
                ],
                'public' => false,
                'show_ui' => $namespace === $selected,
                'show_in_menu' => false,
                'supports' => ['title', 'editor', 'revisions'],
            ]);
            \add_post_type_support($post_type, ['custom-fields']);
        }

        $plan = $this->generated_rewrite_plan($counts, $selected, $configured_base);
        $this->generated_rewrite_base = $plan['shared_base'];
        $this->generated_rewrite_namespaces = '' !== $plan['shared_base'] ? $plan['namespaces'] : [];

        foreach ($plan['namespaces'] as $namespace) {
            $post_type = Compatibility::post_type('generated', $namespace);
            $base = $plan['bases'][$namespace];
            \register_post_type($post_type, [
                'labels' => [
                    'name' => __('PageForge Pages', 'pageforge'),
                    'singular_name' => __('PageForge Page', 'pageforge'),
                ],
                'public' => true,
                'has_archive' => true,
                'show_in_rest' => true,
                'show_ui' => $namespace === $selected,
                'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions'],
                'rewrite' => false === $base ? false : ['slug' => $base, 'with_front' => false],
            ]);
            \add_post_type_support($post_type, ['author']);
        }

        if ('' !== $this->generated_rewrite_base) {
            \add_rewrite_rule(
                '^' . preg_quote($this->generated_rewrite_base, '#') . '/([^/]+)/?$',
                'index.php?pageforge_generated_slug=$matches[1]',
                'top'
            );
        }
    }

    /**
     * Decide how generated post types share (or split) the rewrite base.
     *
     * Published URLs are never rewritten for cosmetic consistency: the legacy
     * pf_page namespace keeps ownership of a configured base when it holds the
     * existing pages. When both namespaces hold pages under one configured base
     * WordPress cannot disambiguate two post types on the same permalink, so a
     * single collision-safe resolver takes over instead of silently changing
     * one namespace's URLs.
     */
    private function generated_rewrite_plan(array $counts, string $selected, string $configured_base): array {
        $alternate = 'pageforge' === $selected ? 'pf' : 'pageforge';
        $namespaces = [$selected];
        if ((int) ($counts[$alternate . '_pages'] ?? 0) > 0) {
            $namespaces[] = $alternate;
        }

        $selected_pages = (int) ($counts[$selected . '_pages'] ?? 0);
        $alternate_pages = (int) ($counts[$alternate . '_pages'] ?? 0);
        if ('' !== $configured_base && $selected_pages > 0 && $alternate_pages > 0) {
            return [
                'namespaces' => $namespaces,
                'bases' => array_fill_keys($namespaces, false),
                'shared_base' => $configured_base,
            ];
        }

        $base_owner = (int) ($counts['pf_pages'] ?? 0) > 0 ? 'pf' : $selected;
        $owned_base = '' !== $configured_base ? $configured_base : $base_owner;
        $bases = [];
        foreach ($namespaces as $namespace) {
            $base = $namespace === $base_owner ? $owned_base : $namespace;
            if ($namespace !== $base_owner && $base === $owned_base) {
                $base .= '-page';
            }
            $bases[$namespace] = $base;
        }

        return ['namespaces' => $namespaces, 'bases' => $bases, 'shared_base' => ''];
    }

    public function register_generated_rewrite_query_var($query_vars) {
        if (!is_array($query_vars)) {
            return $query_vars;
        }
        if (!in_array('pageforge_generated_slug', $query_vars, true)) {
            $query_vars[] = 'pageforge_generated_slug';
        }
        return $query_vars;
    }

    /**
     * Map a shared-base request onto the post type that actually owns the slug.
     * The selected namespace wins when both namespaces use the same slug so the
     * current edition stays authoritative for new content.
     */
    public function resolve_generated_rewrite_request($query_vars) {
        if (!is_array($query_vars) || !isset($query_vars['pageforge_generated_slug'])) {
            return $query_vars;
        }

        $slug = sanitize_title((string) $query_vars['pageforge_generated_slug']);
        unset($query_vars['pageforge_generated_slug']);
        if ('' === $slug || empty($this->generated_rewrite_namespaces)) {
            return $query_vars;
        }

        foreach ($this->generated_rewrite_namespaces as $namespace) {
            $post_type = Compatibility::post_type('generated', $namespace);
            if ('' === $post_type) {
                continue;
            }
            if (\get_page_by_path($slug, OBJECT, $post_type)) {
                $query_vars['post_type'] = $post_type;
                $query_vars['name'] = $slug;
                return $query_vars;
            }
        }

        return $query_vars;
    }

    public function filter_generated_post_link($link, $post = null, $leavename = false, $sample = false) {
        if ('' === $this->generated_rewrite_base || $leavename || $sample) {
            return $link;
        }

        $post = $post instanceof \WP_Post ? $post : \get_post($post);
        if (!$post) {
            return $link;
        }

        $owned = [];
        foreach ($this->generated_rewrite_namespaces as $namespace) {
            $owned[] = Compatibility::post_type('generated', $namespace);
        }
        if (!in_array((string) $post->post_type, $owned, true)) {
            return $link;
        }

        $slug = (string) ($post->post_name ?? '');
        if ('' === $slug) {
            return $link;
        }

        return \user_trailingslashit(\home_url('/' . $this->generated_rewrite_base . '/' . $slug));
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
		\add_submenu_page('pageforge', __('Connections', 'pageforge'), __('Connections', 'pageforge'), 'manage_options', 'pageforge-connections', [Admin::class, 'render_connections']);
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
            'mcp' => $this->mcp_script_data(),
            'i18n' => $this->admin_script_i18n(),
		]);
	}

    /**
     * Data the Connections screen needs for the remote-connector setup dialogs.
     *
     * Setup instructions live here rather than in JavaScript so they are
     * translatable, and they describe what the operator does in the client
     * rather than promising an outcome PageForge cannot observe.
     */
    private function mcp_script_data(): array {
        return [
            'endpoint' => Oauth::resource_identifier(),
            'metadata' => Oauth::protected_resource_metadata_url(),
            'connectors' => [
                'chatgpt' => [
                    'icon' => PAGEFORGE_URL . 'assets/mcp/icon-chatgpt.png',
                    'intro' => __('ChatGPT connects to PageForge as a custom MCP connector. It discovers OAuth from the server URL below, then asks you to sign in to WordPress and approve the connection.', 'pageforge'),
                    'steps' => [
                        __('Copy the MCP server URL below.', 'pageforge'),
                        __('In ChatGPT, open Settings and add a new connector, choosing the MCP / custom connector option.', 'pageforge'),
                        __('Paste the server URL and choose OAuth as the authentication method.', 'pageforge'),
                        __('ChatGPT will send you to this site to sign in and approve the requested access.', 'pageforge'),
                        __('Return here and use Test configuration to confirm PageForge saw the connection.', 'pageforge'),
                    ],
                    // Stated plainly because PageForge Pro capability and what a
                    // given ChatGPT plan/surface permits are different things,
                    // and promising write access we cannot deliver would be a
                    // support problem, not a feature.
                    'caveat' => __('Which connectors you can add, and whether they may perform write actions, is controlled by your ChatGPT plan and OpenAI’s current connector policy. PageForge cannot grant capabilities your ChatGPT account does not allow.', 'pageforge'),
                    'docs' => 'https://pageforge.pro/docs/mcp/chatgpt',
                ],
                'claude' => [
                    'icon' => PAGEFORGE_URL . 'assets/mcp/icon-claude.png',
                    'intro' => __('Claude connects to PageForge as a remote custom connector over OAuth. If Claude asks for a Client ID and Client Secret, create an OAuth client below rather than using an API key or your PageForge licence key.', 'pageforge'),
                    'steps' => [
                        __('Copy the MCP server URL below.', 'pageforge'),
                        __('In Claude, add a custom connector and paste the server URL.', 'pageforge'),
                        __('Leave authentication set to OAuth so Claude discovers it automatically.', 'pageforge'),
                        __('Approve the connection when Claude sends you to this site.', 'pageforge'),
                        __('Return here and use Test configuration to confirm PageForge saw the connection.', 'pageforge'),
                    ],
                    'caveat' => __('The Claude connector credentials are separate from your Claude API key in Settings and from your PageForge licence. Do not reuse one for the other.', 'pageforge'),
                    'docs' => 'https://pageforge.pro/docs/mcp/claude',
                ],
                // Not an OAuth connector. These clients let the operator supply
                // the Authorization header themselves, so the mechanism is a
                // personal access token and the steps say so explicitly.
                'other' => [
                    'icon' => PAGEFORGE_URL . 'assets/mcp/mcp.svg',
                    'intro' => __('VS Code, Codex, GitHub Copilot, Roo Code, Cursor, Claude Code and generic MCP clients read their configuration from a file, so they use a personal access token rather than OAuth.', 'pageforge'),
                    'steps' => [
                        __('Create a personal access token and copy it. It is shown only once.', 'pageforge'),
                        __('Pick your client on the configuration tab in the dialog.', 'pageforge'),
                        __('Paste the configuration into that client’s MCP config file.', 'pageforge'),
                        __('Restart the client so it reloads its configuration.', 'pageforge'),
                        __('Ask it: “Show my PageForge site status.”', 'pageforge'),
                    ],
                    'caveat' => __('A personal access token is not an OAuth client secret and not an AI provider API key. Revoke it here if a machine holding it is lost.', 'pageforge'),
                    'docs' => 'https://pageforge.pro/docs/mcp/clients',
                ],
            ],
        ];
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
            'openGraphTitle' => __('Open Graph title', 'pageforge'),
            'integration' => __('SEO integration', 'pageforge'),
            'status' => __('Status', 'pageforge'),
            'metadataWarning' => __('Metadata warning:', 'pageforge'),
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
            'generatingDraft' => __('Generating post...', 'pageforge'),
            'draftCreated' => __('Post created', 'pageforge'),
            'editPost' => __('Edit Post', 'pageforge'),
            'featuredImageAttached' => __('Featured image attached (attachment #%s)', 'pageforge'),
            'generatingReport' => __('Generating report...', 'pageforge'),
            'generatedAt' => __('Generated at:', 'pageforge'),
            'invalidSeoReportResponse' => __('The AI provider returned an invalid SEO report.', 'pageforge'),
            'seoReportRequestFailed' => __('The SEO report request failed.', 'pageforge'),
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
        // Secret fields never take the raw submitted value at face value: a
        // blank/sentinel submission means "keep what's already stored", so a
        // page reload can never wipe a working credential.
        $secret = static function (string $key) use ($data, $existing): string {
            return Secrets::resolve_incoming($data, $key, (string) array_get($existing, $key, ''), array_key_exists($key, $data));
        };
		$clean = [
            'ai_provider'  => $ai_provider,
			'openai_key'   => sanitize_text_field($secret('openai_key')),
			'openai_model' => $openai_model,
            'anthropic_key' => sanitize_text_field($secret('anthropic_key')),
            'anthropic_model' => $anthropic_model,
            'gemini_key' => sanitize_text_field($secret('gemini_key')),
            'gemini_model' => $gemini_model,
            'deepseek_key' => sanitize_text_field($secret('deepseek_key')),
            'deepseek_model' => $deepseek_model,
			'pexels_key'   => sanitize_text_field($secret('pexels_key')),
			'brand_context'=> sanitize_textarea_field($data['brand_context'] ?? ''),
			'business_type'=> schema_normalize_business_type(sanitize_text_field($data['business_type'] ?? 'LocalBusiness')),
			'target_country'=> strtoupper(sanitize_text_field($data['target_country'] ?? '')),
			'post_type'    => Generator::resolve_generated_post_type((string) ($data['post_type'] ?? Compatibility::post_type('generated'))),
			'cpt_base'     => sanitize_title($data['cpt_base'] ?? 'pageforge'),
			'daily_limit'  => max(1, (int) ($data['daily_limit'] ?? 10)),
			'ga4_measurement_id' => sanitize_text_field($data['ga4_measurement_id'] ?? ''),
			'ga4_api_secret'     => sanitize_text_field($secret('ga4_api_secret')),
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
        if (array_key_exists('cpt_base', $data) && $clean['cpt_base'] === '') {
            \wp_send_json_error(__('Enter a nonempty Generated CPT Slug Base, such as pageforge. For root URLs choose native Page output.', 'pageforge'));
        }
        if (array_key_exists('post_type', $data) && $clean['post_type'] === '') {
            \wp_send_json_error(__('Choose a supported generation post type.', 'pageforge'));
        }
        settings_update($merged);
        if ((string) ($existing['cpt_base'] ?? '') !== (string) ($merged['cpt_base'] ?? '')) {
            $this->register_cpts();
            \flush_rewrite_rules();
        }
        // The AJAX response echoes settings back into the UI to confirm the
        // save. Secrets never travel in that response, redacted or not raw.
        \wp_send_json_success(Secrets::redact_settings($merged));
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

    private function generation_destination_label(int $template_id, array $destination): string {
        $type = $destination['post_type'];
        if ($type === 'page') {
            $path = trim((string) $destination['parent_path'], '/');
            $label = __('Native Page → Pages > All Pages', 'pageforge') . ' → /' . ($path !== '' ? $path . '/' : '') . 'child/';
        } elseif ($type === 'post') {
            $label = __('Native Post → Posts > All Posts → WordPress post permalinks', 'pageforge');
        } else {
            $label = __('PageForge custom content → PageForge list', 'pageforge') . ' → ' . rtrim($destination['expected_path'], '/') . '/child/';
        }
        return $label . ' — ' . (Compatibility::read_meta($template_id, 'post_type', '') !== ''
            ? __('Controlled by template Target Post Type.', 'pageforge')
            : __('Controlled by Settings > Default Post Type.', 'pageforge'));
    }

	public function ajax_template_meta() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('edit_posts')) \wp_send_json_error(__('Unauthorized', 'pageforge'));
		$id = isset($_POST['template_id']) ? absint( wp_unslash( $_POST['template_id'] ) ) : 0;
        if (!$id) \wp_send_json_error(__('Missing', 'pageforge'));
        // The id arrives from the request, so it is an arbitrary object
        // reference. Without an object-level check any user who can edit
        // something could read the configuration of a template they have no
        // rights to, including an unpublished one.
        if (!Access::can_read_post($id)) {
            \wp_send_json_error(__('That template does not exist.', 'pageforge'));
        }
        $destination = Generator::destination($id);
        if (\is_wp_error($destination)) { \wp_send_json_error($destination->get_error_message()); }
        $meta = [
            'title_pattern' => Compatibility::read_meta($id, 'title_pattern', ''),
            'meta_description_pattern' => Compatibility::read_meta($id, 'meta_description_pattern', ''),
            'slug_pattern'  => Compatibility::read_meta($id, 'slug_pattern', ''),
            'post_type' => $destination['post_type'],
            'post_type_override' => (string) Compatibility::read_meta($id, 'post_type', ''),
            'parent_source' => (string) Compatibility::read_meta($id, 'parent_source', 'static'),
            'parent_page_id' => $destination['post_parent'],
            'parent_path' => $destination['parent_path'],
            'destination' => $this->generation_destination_label($id, $destination),
            'post_status'   => Compatibility::read_meta($id, 'default_status', ''),
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

        // The template id is an arbitrary object reference from the request.
        if (!Access::can_read_post($template_id)) {
            \wp_send_json_error(__('That template does not exist.', 'pageforge'));
        }

        $destination = Generator::destination($template_id);
        if (\is_wp_error($destination)) { \wp_send_json_error($destination->get_error_message()); }
        $target_post_type = $destination['post_type'];
        if (!Access::can_create($target_post_type)) {
            \wp_send_json_error(__('Your account cannot create content of that type.', 'pageforge'));
        }

        // Editing is not publishing. Without this, any account able to edit
        // posts could generate live pages, which is a publishing right it does
        // not otherwise have. The status is reduced rather than refused so the
        // run still completes as drafts.
        $requested_status = $status;
        $status = Access::clamp_status($target_post_type, $status);
        $status_notice = '';
        if ($status !== $requested_status) {
            $status_notice = sprintf(
                /* translators: 1: requested post status, 2: status actually used. */
                __('Your account cannot publish, so pages were created as %2$s instead of %1$s.', 'pageforge'),
                $requested_status,
                $status
            );
        }

        // Resolves and authorizes the attachment, confines it to the uploads
        // directory, and rejects anything that is not a CSV. Passing a raw
        // attachment id to get_attached_file() let any caller have PageForge
        // read and return the contents of an arbitrary media item.
        $file = Access::resolve_data_source($csv_id);
        if (\is_wp_error($file)) {
            \wp_send_json_error($file->get_error_message());
        }

        [$headers, $rows] = parse_csv($file);
        if (empty($rows)) \wp_send_json_error(__('CSV has no rows', 'pageforge'));

        $messages = [];
        if ('' !== $status_notice) {
            $messages[] = $status_notice;
        }
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
            $pid = (int) $pid;
            if (!Access::can_read_post($pid)) {
                continue;
            }
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
        // This writes SEO metadata onto the named post. `edit_posts` alone only
        // establishes that the user may edit *something*, so without an
        // object-level check any contributor could rewrite metadata on any post
        // on the site, including another author's private drafts.
        if (!Access::can_edit_post($post_id)) {
            \wp_send_json_error(__('You cannot edit that content.', 'pageforge'));
        }
  $out = AI::generate_meta_for_post($post_id);
        if (isset($out['_error'])) \wp_send_json_error($out['_error']);
		\wp_send_json_success($out);
	}


	/**
	 * Bounded batch metadata generation.
	 *
	 * The Meta screen drives this repeatedly with an offset, so each request stays
	 * small and survives a closed browser or a timeout without losing prior work.
	 * It reuses the single-post path, which keeps quota accounting and provider
	 * behaviour identical to the one-off control.
	 */
	public function ajax_ai_generate_meta_bulk() {
		\check_ajax_referer('pageforge_admin', 'nonce');
		if (!\current_user_can('edit_posts')) \wp_send_json_error(__('Unauthorized', 'pageforge'));

		$s = settings_get();
		if (!ai_has_provider_key($s)) {
			\wp_send_json_error(__('Add an AI provider API key in PageForge settings first.', 'pageforge'));
		}

		$post_types_input = [];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if (isset($_POST['post_types'])) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$post_types_input = wp_unslash($_POST['post_types']);
		}
		$post_types = array_values(array_filter(array_map('sanitize_key', (array) $post_types_input), 'post_type_exists'));
		if (empty($post_types)) {
			$post_types = array_values(array_filter(
				array_merge(['page', 'post'], Compatibility::generated_post_types()),
				'post_type_exists'
			));
		}
		// A request for generated content covers both namespaces on a mixed site.
		if (array_intersect($post_types, Compatibility::generated_post_types())) {
			$post_types = array_values(array_unique(array_filter(
				array_merge($post_types, Compatibility::generated_post_types()),
				'post_type_exists'
			)));
		}
		if (empty($post_types)) {
			\wp_send_json_error(__('No matching post types are available.', 'pageforge'));
		}

		$status = sanitize_key((string) (isset($_POST['status']) ? wp_unslash($_POST['status']) : 'publish'));
		if (!in_array($status, ['publish', 'draft', 'pending', 'private'], true)) {
			$status = 'publish';
		}

		$offset = isset($_POST['offset']) ? absint(wp_unslash($_POST['offset'])) : 0;
		$limit  = isset($_POST['limit']) ? absint(wp_unslash($_POST['limit'])) : 5;
		$limit  = max(1, min(10, $limit));

		$q = new \WP_Query([
			'post_type'      => $post_types,
			'post_status'    => $status,
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]);

		$ids = is_array($q->posts) ? $q->posts : [];
		$total = (int) ($q->found_posts ?? 0);
		$results = ['success' => [], 'errors' => [], 'skipped_no_permission' => 0];

		// A bulk operation must authorize every target, not sample one. The query
		// above selects by post type and status, so without this an account with
		// `edit_posts` could rewrite metadata across the entire site including
		// content it has no rights to. Denied rows are counted and reported so
		// the run is honest about having done less than was asked.
		// Pagination advances by what the query returned, not by what survived
		// authorization, so a batch of rows the user cannot edit moves the cursor
		// forward instead of stalling the run on the same offset forever.
		$batch_size = count($ids);
		$partition = Access::partition_editable($ids);
		$results['skipped_no_permission'] = count($partition['denied']);
		$ids = $partition['allowed'];

		foreach ($ids as $pid) {
			$pid = (int) $pid;
			if ($pid <= 0) continue;

			$quota = AI::check_ai_quota('ai_meta', false);
			if (\is_wp_error($quota)) {
				// Report the limit and stop the run instead of silently skipping rows.
				$results['errors'][] = ['post_id' => $pid, 'error' => $quota->get_error_message()];
				\wp_send_json_success([
					'total' => $total,
					'next_offset' => $offset + count($results['success']) + count($results['errors']),
					'done' => true,
					'results' => $results,
				]);
			}

			$out = AI::generate_meta_for_post($pid);
			if (isset($out['_error'])) {
				$results['errors'][] = ['post_id' => $pid, 'error' => (string) $out['_error']];
				continue;
			}
			AI::check_ai_quota('ai_meta', true);
			$results['success'][] = [
				'post_id' => (int) ($out['post_id'] ?? $pid),
				'meta_title' => (string) ($out['meta_title'] ?? ''),
			];
		}

		$processed = count($results['success']) + count($results['errors']);
		$next_offset = $offset + $batch_size;

		\wp_send_json_success([
			'total' => $total,
			'next_offset' => $next_offset,
			'done' => $batch_size < $limit || $next_offset >= $total,
			'results' => $results,
			'processed' => $processed,
		]);
	}

	public function ajax_ai_generate_blog() {
        \check_ajax_referer('pageforge_admin', 'nonce');
        if (!\current_user_can('edit_posts')) \wp_send_json_error(__('Unauthorized', 'pageforge'));

		// WordPress.org build: feature always enabled.

        $args = [
            'topic'        => sanitize_text_field( (string) ( isset( $_POST['topic'] ) ? wp_unslash( $_POST['topic'] ) : '' ) ),
            'audience'     => sanitize_text_field( (string) ( isset( $_POST['audience'] ) ? wp_unslash( $_POST['audience'] ) : '' ) ),
            'post_type'    => 'post',
            'status'       => sanitize_key( (string) ( isset( $_POST['status'] ) ? wp_unslash( $_POST['status'] ) : 'draft' ) ),
            'include_image'=> !empty( $_POST['include_image'] ),
            'keywords_raw' => sanitize_textarea_field( (string) ( isset( $_POST['keywords_raw'] ) ? wp_unslash( $_POST['keywords_raw'] ) : '' ) ),
            'content_length' => sanitize_key((string) (isset($_POST['content_length']) ? wp_unslash($_POST['content_length']) : 'medium')),
        ];
        if (!Access::can_create('post')) {
            \wp_send_json_error(__('Your account cannot create blog posts.', 'pageforge'));
        }
        if (!in_array($args['status'], ['publish', 'private', 'draft', 'pending'], true)) {
            $args['status'] = 'draft';
        }
        $requested_status = $args['status'];
        $args['status'] = Access::clamp_status('post', $args['status']);
        $status_notice = '';
        if ($args['status'] !== $requested_status) {
            $status_notice = sprintf(
                /* translators: 1: requested post status, 2: status actually used. */
                __('Your account cannot publish, so the blog post was created as %2$s instead of %1$s.', 'pageforge'),
                $requested_status,
                $args['status']
            );
        }
        $out = AI::generate_blog_draft($args);
        if (isset($out['_error'])) \wp_send_json_error($out['_error']);
        if ('' !== $status_notice) {
            $out['notice'] = $status_notice;
        }
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

