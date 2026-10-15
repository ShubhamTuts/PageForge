<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

require_once __DIR__ . '/TestCase.php';

class PageForge_Test_WPDB {
    public $posts = 'posts';

    public function prepare($query, ...$args) {
        return serialize($args);
    }

    public function get_var($query) {
        $args = unserialize($query);
        $counts = $GLOBALS['pageforge_test_post_counts'][$args[0]] ?? 0;
        if (isset($args[1]) && is_array($counts)) {
            return (int) ($counts[$args[1]] ?? 0);
        }
        return is_array($counts) ? array_sum(array_map('intval', $counts)) : (int) $counts;
    }
}

function pageforge_test_reset_state(): void {
    // Cleared so fixtures cannot leak between tests: a featured image or
    // attachment left behind by one test silently changes another's counts.
    $GLOBALS['pageforge_test_thumbnails'] = [];
    $GLOBALS['pageforge_test_attached_files'] = [];
    $GLOBALS['pageforge_test_taxonomies'] = [];
    $GLOBALS['pageforge_test_terms'] = [];
    $GLOBALS['pageforge_test_options'] = [];
    $GLOBALS['pageforge_test_settings'] = [];
    $GLOBALS['pageforge_test_meta'] = [];
    $GLOBALS['pageforge_test_post_types'] = ['page' => true, 'post' => true];
    $GLOBALS['pageforge_test_post_type_args'] = [];
    $GLOBALS['pageforge_test_post_counts'] = [];
    $GLOBALS['pageforge_test_posts'] = [];
    $GLOBALS['pageforge_test_inserted_posts'] = [];
    $GLOBALS['pageforge_test_permalinks'] = [];
    $GLOBALS['pageforge_test_events'] = [];
    $GLOBALS['pageforge_test_query_args'] = [];
    $GLOBALS['pageforge_test_get_posts_args'] = [];
    $GLOBALS['pageforge_test_rewrite_rules'] = [];
    $GLOBALS['pageforge_test_filters'] = [];
    $GLOBALS['pageforge_test_transients'] = [];
    $GLOBALS['pageforge_test_redirects'] = [];
    $GLOBALS['pageforge_test_rest_routes'] = [];
    $GLOBALS['pageforge_test_abilities'] = [];
    $GLOBALS['pageforge_test_active_plugins'] = [];
    $GLOBALS['pageforge_test_http_handler'] = null;
    $GLOBALS['pageforge_test_ai_chat_response'] = null;
    $GLOBALS['pageforge_test_current_user'] = 0;
    // Absent = allow-all, which keeps the pre-existing suite unchanged.
    unset($GLOBALS['pageforge_test_caps'], $GLOBALS['pageforge_test_nonce_valid']);
    $GLOBALS['wpdb'] = new PageForge_Test_WPDB();
}

class WP_Post {
    public $ID = 0;
    public $post_type = 'post';
    public $post_title = '';
    public $post_name = '';
    public $post_content = '';
    public $post_excerpt = '';
    public $post_status = 'publish';
    public $post_parent = 0;
    public $comment_status = 'closed';

    public function __construct(array $data = []) {
        foreach ($data as $key => $value) {
            $this->{$key} = $value;
        }
    }
}

function pageforge_test_add_post(int $id, string $post_type, string $title, string $slug = '', string $status = 'publish'): WP_Post {
    $post = new WP_Post([
        'ID' => $id,
        'post_type' => $post_type,
        'post_title' => $title,
        'post_name' => $slug,
        'post_status' => $status,
    ]);
    $GLOBALS['pageforge_test_posts'][$id] = $post;
    $counts = $GLOBALS['pageforge_test_post_counts'][$post_type] ?? [];
    $counts[$status] = (int) ($counts[$status] ?? 0) + 1;
    $GLOBALS['pageforge_test_post_counts'][$post_type] = $counts;
    return $post;
}

function get_option($option, $default = false) {
    return $GLOBALS['pageforge_test_options'][$option] ?? $default;
}

function update_option($option, $value, $autoload = null): bool {
    $GLOBALS['pageforge_test_options'][$option] = $value;
    if ($option === 'pageforge_content_namespace') {
        $GLOBALS['pageforge_test_events'][] = 'namespace';
    }
    return true;
}

function get_post_meta($post_id, $key = '', $single = false) {
    $meta = $GLOBALS['pageforge_test_meta'][$post_id] ?? [];
    if ($key === '') {
        return $meta;
    }

    $value = $meta[$key] ?? '';
    return $single ? $value : [$value];
}

function metadata_exists($meta_type, $object_id, $meta_key): bool {
    return isset($GLOBALS['pageforge_test_meta'][$object_id])
        && array_key_exists($meta_key, $GLOBALS['pageforge_test_meta'][$object_id]);
}

function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '') {
    $existing = $GLOBALS['pageforge_test_meta'][$post_id][$meta_key] ?? null;
    if (metadata_exists('post', $post_id, $meta_key) && $existing === $meta_value) {
        return false;
    }

    $GLOBALS['pageforge_test_meta'][$post_id][$meta_key] = $meta_value;
    return 1;
}

class PageForge_Test_Ability {
    private $callback;

    public function __construct(callable $callback) {
        $this->callback = $callback;
    }

    public function execute($input = null) {
        return call_user_func($this->callback, $input);
    }
}

function wp_get_ability($name) {
    return $GLOBALS['pageforge_test_abilities'][(string) $name] ?? null;
}

function delete_post_meta($post_id, $meta_key, $meta_value = ''): bool {
    unset($GLOBALS['pageforge_test_meta'][$post_id][$meta_key]);
    return true;
}

function post_type_exists($post_type): bool {
    return !empty($GLOBALS['pageforge_test_post_types'][$post_type]);
}

function wp_count_posts($post_type = 'post', $perm = '') {
    if (!post_type_exists($post_type)) {
        return (object) [];
    }
    return (object) ($GLOBALS['pageforge_test_post_counts'][$post_type] ?? ['publish' => 0]);
}

function get_post($post_id) {
    return $GLOBALS['pageforge_test_posts'][$post_id] ?? null;
}

function settings_get() {
    return $GLOBALS['pageforge_test_settings'];
}

function array_get($array, $key, $default = null) {
    return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;
}

function sanitize_key($value) {
    return strtolower((string) $value);
}

function sanitize_text_field($value) {
    return trim((string) $value);
}

function esc_url_raw($url) {
    $url = trim((string) $url);
    return preg_match('#^https?://#i', $url) ? $url : '';
}

function wp_kses_post($value) {
    return (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $value);
}

function replace_placeholders($text, array $values) {
    foreach ($values as $key => $value) {
        $text = str_replace('{' . $key . '}', (string) $value, (string) $text);
    }
    return $text;
}

function build_slug($pattern, array $values) {
    $slug = strtolower(replace_placeholders((string) $pattern, array_change_key_case($values, CASE_LOWER)));
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
}

function get_page_by_path($slug, $output = null, $post_type = 'post') {
    foreach ($GLOBALS['pageforge_test_posts'] as $post) {
        $name = (string) ($post->post_name ?? '');
        $type = (string) ($post->post_type ?? '');
        $path = $type === 'page' ? get_page_uri($post->ID) : $name;
        if ('' !== $name && $path === $slug && in_array($type, (array) $post_type, true)) {
            return $post;
        }
    }
    return null;
}

function get_page_uri($post_id) {
    $parts = [];
    $seen = [];
    while ($post_id && !isset($seen[$post_id])) {
        $post = get_post($post_id);
        if (!$post) break;
        $seen[$post_id] = true;
        array_unshift($parts, $post->post_name);
        $post_id = (int) ($post->post_parent ?? 0);
    }
    return implode('/', $parts);
}

function wp_insert_post($postarr, $wp_error = false) {
    if (!post_type_exists((string) ($postarr['post_type'] ?? 'post'))) {
        return $wp_error ? new WP_Error('invalid_post_type', 'Invalid post type.') : 0;
    }
    $GLOBALS['pageforge_test_inserted_posts'][] = $postarr;
    $id = 100 + count($GLOBALS['pageforge_test_inserted_posts']) - 1;
    $GLOBALS['pageforge_test_posts'][$id] = new WP_Post(array_merge($postarr, ['ID' => $id]));
    return $id;
}

function is_wp_error($value) {
    return $value instanceof WP_Error;
}

/**
 * Mirrors WordPress: script and style *contents* are removed before tags are
 * stripped. A plain strip_tags() would leave "alert(1)" behind as text, so a
 * stub that only calls strip_tags() would let a sanitisation test pass or fail
 * for the wrong reason.
 */
function wp_strip_all_tags($text, $remove_breaks = false) {
    $text = (string) $text;
    $text = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text);
    $text = strip_tags($text);
    if ($remove_breaks) {
        $text = (string) preg_replace('/[\r\n\t ]+/', ' ', $text);
    }
    return trim($text);
}

function wp_trim_words($text, $num_words = 55, $more = null) {
    return (string) $text;
}

function get_the_title($post_id = 0) {
    return '';
}

function __($text, $domain = null) {
    return $text;
}

function add_action() {
}

function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['pageforge_test_filters'][$tag][] = $callback;
}

function add_shortcode() {
}

function load_plugin_textdomain() {
}

function sanitize_title($value) {
    return strtolower((string) $value);
}

function register_post_type($post_type, $args = []) {
    $GLOBALS['pageforge_test_post_types'][$post_type] = true;
    $GLOBALS['pageforge_test_post_type_args'][$post_type] = $args;
    $GLOBALS['pageforge_test_events'][] = 'register_' . $post_type;
}

function get_post_types($args = [], $output = 'names') {
    return array_keys($GLOBALS['pageforge_test_post_types']);
}

function add_post_type_support() {
}

function did_action() {
    return 0;
}

function flush_rewrite_rules() {
    $GLOBALS['pageforge_test_events'][] = 'flush';
}

function add_rewrite_rule($regex, $query, $after = 'bottom') {
    $GLOBALS['pageforge_test_rewrite_rules'][] = compact('regex', 'query', 'after');
}

function home_url($path = '') {
    return 'https://example.test/' . ltrim((string) $path, '/');
}

function user_trailingslashit($value) {
    return rtrim((string) $value, '/') . '/';
}

function get_posts($args = []) {
    $GLOBALS['pageforge_test_get_posts_args'][] = $args;
    $post_types = (array) ($args['post_type'] ?? 'post');
    return array_values(array_filter($GLOBALS['pageforge_test_posts'], static function ($post) use ($post_types) {
        return in_array($post->post_type, $post_types, true);
    }));
}

function wp_unslash($value) { return $value; }
function absint($value): int { return abs((int) $value); }
function wp_verify_nonce($nonce = '', $action = -1) {
    if (array_key_exists('pageforge_test_nonce_valid', $GLOBALS)) {
        return (bool) $GLOBALS['pageforge_test_nonce_valid'];
    }
    return true;
}

/**
 * Capability stub.
 *
 * Defaults to allow-all so the pre-existing suite is unaffected, but tests can
 * populate $pageforge_test_caps to make a capability check actually deny. That
 * is what lets the authorization tests be meaningful rather than tautological.
 * Shape: ['<user_id>' => ['manage_options' => true, 'read_post' => [12 => false]]]
 */
function pageforge_test_user_can($user_id, $capability, ...$args) {
    if (!isset($GLOBALS['pageforge_test_caps'])) {
        return true;
    }
    $caps = $GLOBALS['pageforge_test_caps'][(int) $user_id] ?? null;
    if (null === $caps) {
        return false;
    }
    if (!array_key_exists($capability, $caps)) {
        return false;
    }
    $value = $caps[$capability];
    if (is_array($value)) {
        $object_id = (int) ($args[0] ?? 0);
        return (bool) ($value[$object_id] ?? false);
    }
    return (bool) $value;
}

function current_user_can($capability = '', ...$args) {
    return pageforge_test_user_can(get_current_user_id(), $capability, ...$args);
}

function user_can($user, $capability, ...$args) {
    $user_id = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;
    return pageforge_test_user_can($user_id, $capability, ...$args);
}

function get_current_user_id() { return (int) ($GLOBALS['pageforge_test_current_user'] ?? 0); }
function wp_set_current_user($user_id) { $GLOBALS['pageforge_test_current_user'] = (int) $user_id; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function wp_get_current_user() {
    $id = get_current_user_id();
    return (object) ['ID' => $id, 'display_name' => 'User ' . $id];
}

// ---- transients: the harness mirrors real expiry semantics so TTL-dependent
// ---- token behaviour is actually exercised rather than assumed.
function set_transient($key, $value, $ttl = 0) {
    $GLOBALS['pageforge_test_transients'][$key] = [
        'value' => $value,
        'expires' => $ttl > 0 ? time() + (int) $ttl : 0,
    ];
    return true;
}
function get_transient($key) {
    $record = $GLOBALS['pageforge_test_transients'][$key] ?? null;
    if (!is_array($record)) {
        return false;
    }
    if ((int) $record['expires'] > 0 && (int) $record['expires'] < time()) {
        unset($GLOBALS['pageforge_test_transients'][$key]);
        return false;
    }
    return $record['value'];
}
function delete_transient($key) {
    unset($GLOBALS['pageforge_test_transients'][$key]);
    return true;
}

function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
function untrailingslashit($value) { return rtrim((string) $value, '/\\'); }
function rest_url($path = '') { return 'https://example.test/wp-json/' . ltrim((string) $path, '/'); }
function get_permalink_structure() { return '/%postname%/'; }
function human_time_diff($from, $to = 0) { return (string) max(0, (int) $to - (int) $from) . ' seconds'; }
function nocache_headers() {}
function status_header($code) { $GLOBALS['pageforge_test_status_header'] = (int) $code; }
function add_query_arg($args = [], $url = '') {
    if (!is_array($args)) {
        return (string) $url;
    }
    $separator = false === strpos((string) $url, '?') ? '?' : '&';
    $pairs = [];
    foreach ($args as $key => $value) {
        $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
    }
    return empty($pairs) ? (string) $url : $url . $separator . implode('&', $pairs);
}
function wp_redirect($url, $status = 302) {
    $GLOBALS['pageforge_test_redirects'][] = ['url' => (string) $url, 'status' => (int) $status];
    return true;
}
function wp_safe_redirect($url, $status = 302) { return wp_redirect($url, $status); }
function wp_login_url($redirect = '') { return 'https://example.test/wp-login.php?redirect_to=' . rawurlencode((string) $redirect); }
function is_plugin_active($plugin) { return !empty($GLOBALS['pageforge_test_active_plugins'][$plugin]); }

// Outbound HTTP is injectable so CIMD and diagnostics can be tested without
// touching the network.
function wp_remote_get($url, $args = []) {
    $handler = $GLOBALS['pageforge_test_http_handler'] ?? null;
    if (is_callable($handler)) {
        return $handler((string) $url, $args);
    }
    return new WP_Error('http_request_failed', 'No HTTP handler configured in tests.');
}
function wp_remote_retrieve_response_code($response) { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }
function wp_remote_retrieve_header($response, $header) {
    $headers = array_change_key_case((array) ($response['headers'] ?? []), CASE_LOWER);
    return (string) ($headers[strtolower((string) $header)] ?? '');
}
function sanitize_textarea_field($value) { return trim((string) $value); }
function pageforge_seo_plugins_active(): bool { return false; }
function shortcode_atts($pairs, $atts, $shortcode = '') { return array_merge($pairs, (array) $atts); }
function esc_html__($text, $domain = null) { return $text; }
function sanitize_hex_color($value) { return (string) $value; }
function get_permalink($post_id = 0) { return $GLOBALS['pageforge_test_permalinks'][(int) $post_id] ?? 'https://example.test/?p=' . (int) $post_id; }
function get_the_excerpt($post_id = 0) { $post = get_post($post_id); return $post ? $post->post_excerpt : ''; }
function get_the_post_thumbnail_url() { return false; }
function get_site_icon_url() { return false; }
function get_bloginfo() { return 'PageForge Test'; }
function is_singular() { return true; }
function get_locale() { return 'en_AU'; }
function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); }
function schema_normalize_business_type($value): string { return (string) $value; }
function pf_ai_has_api_key(): bool { return false; }
function ai_has_provider_key($settings = []): bool { return false; }
function ai_provider_credentials($settings = []) { return ['model' => 'test-model', 'key' => 'test-key', 'provider' => 'openai']; }
function openai_generate($prompt, $model, $api_key, $max_tokens = 3000, $provider = null) {
    return json_encode([
        'title' => 'Generated Blog',
        'slug' => 'generated-blog',
        'excerpt' => 'Generated excerpt',
        'content_html' => '<h2>Generated</h2><p>Body.</p>',
        'tags' => ['SEO', 'PageForge'],
        'image_keyword' => 'SEO',
    ]);
}
function openai_chat($messages, $model = '', $api_key = '', $max_tokens = 0, $provider = '') {
    $configured = $GLOBALS['pageforge_test_ai_chat_response'] ?? null;
    if ($configured instanceof WP_Error || is_string($configured)) {
        return $configured;
    }
    return "# Executive Summary\nPrioritize technical health and high-intent pages.\n\n## Quick Wins\n- Improve titles and descriptions.";
}
function wp_set_post_tags($post_id, $tags = [], $append = false) { $GLOBALS['pageforge_test_terms'][$post_id] = ['tags' => (array) $tags, 'append' => (bool) $append]; return true; }
function get_edit_post_link($post_id = 0, $context = 'display') { return 'https://example.test/wp-admin/post.php?post=' . (int) $post_id; }
function ai_get_provider($settings = []): string { return 'openai'; }
function esc_attr($value) { return (string) $value; }
function esc_url($value) { return (string) $value; }
// Mirrors WordPress: htmlspecialchars with ENT_QUOTES on UTF-8.
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function get_the_ID() { return (int) ($GLOBALS['pageforge_test_current_post_id'] ?? 0); }
function apply_filters($tag, $value, ...$args) {
    foreach ($GLOBALS['pageforge_test_filters'][$tag] ?? [] as $callback) {
        $value = call_user_func($callback, $value, ...$args);
    }
    return $value;
}
function do_action(...$args) {}
function register_rest_route(...$args) { $GLOBALS['pageforge_test_rest_routes'][] = $args; }
function wp_generate_password($length = 12, $special = true, $extra = false) {
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

class WP_REST_Request {
    private $headers = [];
    private $body = '';

    public function __construct(array $headers = [], string $body = '') {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->body = $body;
    }

    public function get_header($name) {
        return $this->headers[strtolower((string) $name)] ?? '';
    }

    public function get_body() {
        return $this->body;
    }

    public function get_body_params() {
        return [];
    }
}

class WP_REST_Response {
    public $data;
    public $status;
    private $headers = [];

    public function __construct($data = null, int $status = 200) {
        $this->data = $data;
        $this->status = $status;
    }

    public function header($key, $value) {
        $this->headers[$key] = $value;
    }

    public function get_headers() {
        return $this->headers;
    }

    public function get_status() {
        return $this->status;
    }
}

class WP_Query {
    public $posts = [];
    public $found_posts = 0;

    public function __construct(array $args = []) {
        $GLOBALS['pageforge_test_query_args'][] = $args;
        $post_types = (array) ($args['post_type'] ?? ['post']);
        $statuses = (array) ($args['post_status'] ?? ['publish']);
        foreach ($GLOBALS['pageforge_test_posts'] as $post) {
            if (!in_array($post->post_type, $post_types, true) || !in_array($post->post_status, $statuses, true)) {
                continue;
            }
            if (!$this->matches_meta_query($post->ID, $args['meta_query'] ?? [])) {
                continue;
            }
            $this->posts[] = ($args['fields'] ?? '') === 'ids' ? $post->ID : $post;
        }
        $this->found_posts = count($this->posts);
    }

    public function have_posts(): bool {
        return !empty($this->posts);
    }

    private function matches_meta_query(int $post_id, array $query): bool {
        if (empty($query)) {
            return true;
        }
        $relation = strtoupper((string) ($query['relation'] ?? 'AND'));
        $matches = [];
        foreach ($query as $key => $condition) {
            if ('relation' === $key || !is_array($condition)) {
                continue;
            }
            $meta_key = (string) ($condition['key'] ?? '');
            $exists = metadata_exists('post', $post_id, $meta_key);
            if (($condition['compare'] ?? '') === 'IN') {
                $matches[] = $exists && in_array((string) get_post_meta($post_id, $meta_key, true), array_map('strval', (array) ($condition['value'] ?? [])), true);
            } else {
                $matches[] = $exists && (string) get_post_meta($post_id, $meta_key, true) === (string) ($condition['value'] ?? '');
            }
        }
        return 'OR' === $relation ? in_array(true, $matches, true) : !in_array(false, $matches, true);
    }
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (!defined('PAGEFORGE_BASENAME')) {
    define('PAGEFORGE_BASENAME', 'pageforge/pageforge.php');
}

class WP_Error {
    private $code;
    private $message;

    public function __construct($code = '', $message = '') {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }
}

pageforge_test_reset_state();

require_once __DIR__ . '/../includes/class-pageforge-secrets.php';
require_once __DIR__ . '/../includes/class-pageforge-edition.php';
require_once __DIR__ . '/../includes/class-pageforge-compatibility.php';
require_once __DIR__ . '/../includes/class-pageforge-upgrade.php';
require_once __DIR__ . '/../includes/class-pageforge-template.php';
require_once __DIR__ . '/../includes/class-pageforge-generator.php';
require_once __DIR__ . '/../includes/class-pageforge-admin.php';
require_once __DIR__ . '/../includes/class-pageforge-plugin.php';
require_once __DIR__ . '/../includes/class-pageforge-frontend.php';
require_once __DIR__ . '/../includes/class-pageforge-access.php';
require_once __DIR__ . '/../includes/class-pageforge-ai.php';
require_once __DIR__ . '/../includes/class-pageforge-seo.php';
require_once __DIR__ . '/../includes/class-pageforge-url-guard.php';
require_once __DIR__ . '/../includes/class-pageforge-request.php';
require_once __DIR__ . '/../includes/class-pageforge-mcp-scopes.php';
require_once __DIR__ . '/../includes/class-pageforge-mcp-ratelimit.php';
require_once __DIR__ . '/../includes/class-pageforge-oauth-tokens.php';
require_once __DIR__ . '/../includes/class-pageforge-oauth-clients.php';
require_once __DIR__ . '/../includes/class-pageforge-oauth.php';
require_once __DIR__ . '/../includes/class-pageforge-mcp-credentials.php';
require_once __DIR__ . '/../includes/class-pageforge-mcp-registry.php';
require_once __DIR__ . '/../includes/class-pageforge-mcp-server.php';
require_once __DIR__ . '/../includes/class-pageforge-mcp-diagnostics.php';

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

function site_url($path = '') { return 'https://example.test/' . ltrim((string) $path, '/'); }

function remove_filter($tag, $callback, $priority = 10) {
    if (!isset($GLOBALS['pageforge_test_filters'][$tag])) {
        return false;
    }
    $GLOBALS['pageforge_test_filters'][$tag] = array_values(array_filter(
        $GLOBALS['pageforge_test_filters'][$tag],
        static function ($registered) use ($callback) {
            return $registered !== $callback;
        }
    ));
    return true;
}

/**
 * Minimal post type object exposing the capability map, which is what the
 * status/creation authorization consults.
 */
function get_post_type_object($post_type) {
    return (object) [
        'name' => (string) $post_type,
        'cap' => (object) [
            'create_posts' => 'edit_posts',
            'edit_posts' => 'edit_posts',
            'publish_posts' => 'publish_posts',
            'edit_post' => 'edit_post',
            'read_post' => 'read_post',
        ],
    ];
}

function wp_get_upload_dir() {
    return ['basedir' => sys_get_temp_dir(), 'baseurl' => 'https://example.test/uploads'];
}

function get_attached_file($attachment_id, $unfiltered = false) {
    return $GLOBALS['pageforge_test_attached_files'][(int) $attachment_id] ?? false;
}

function get_taxonomies($args = [], $output = 'names') {
    $taxonomies = $GLOBALS['pageforge_test_taxonomies'] ?? [];
    if ('objects' !== $output) {
        return array_keys($taxonomies);
    }
    $out = [];
    foreach ($taxonomies as $name => $meta) {
        $out[$name] = (object) array_merge(['name' => $name, 'label' => $name, 'hierarchical' => false], (array) $meta);
    }
    return $out;
}

function get_terms($args = []) {
    $taxonomy = is_array($args) ? (string) ($args['taxonomy'] ?? '') : (string) $args;
    return $GLOBALS['pageforge_test_terms'][$taxonomy] ?? [];
}

function get_post_thumbnail_id($post_id = 0) {
    return (int) ($GLOBALS['pageforge_test_thumbnails'][(int) $post_id] ?? 0);
}
