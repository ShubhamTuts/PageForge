<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * PageForge MCP OAuth 2.1 authorization server + resource metadata.
 *
 * PageForge is both the protected resource (the MCP endpoint) and its own
 * authorization server. That is the only arrangement that works for a
 * self-hosted WordPress plugin: there is no external IdP to delegate to, and
 * the identity that matters is a WordPress user account.
 *
 * Implements the MCP 2026-07-28 authorization model:
 *   - RFC 9728 Protected Resource Metadata (MUST for MCP servers)
 *   - RFC 8414 Authorization Server Metadata
 *   - OAuth 2.1 authorization code flow, PKCE S256 mandatory
 *   - RFC 8707 resource indicators, with the audience bound into the token
 *   - RFC 9207 `iss` in authorization responses
 *   - CIMD client identification plus bounded DCR compatibility
 *   - refresh tokens with rotation and replay detection
 *   - RFC 7009 token revocation
 *
 * Endpoint map (all relative to the site):
 *   GET  /wp-json/pageforge/v1/oauth-protected-resource   PRM (canonical)
 *   GET  /.well-known/oauth-protected-resource            PRM (root installs)
 *   GET  /.well-known/oauth-authorization-server          AS metadata
 *   GET  /pageforge-oauth/authorize                       consent (browser)
 *   POST /pageforge-oauth/authorize                       consent decision
 *   POST /wp-json/pageforge/v1/oauth/token                token + refresh
 *   POST /wp-json/pageforge/v1/oauth/revoke               RFC 7009
 *   POST /wp-json/pageforge/v1/oauth/register             bounded public DCR
 */
final class Oauth {
    public const ROUTE_NAMESPACE = 'pageforge/v1';
    /** Front-end path for the browser-facing consent endpoint. */
    public const AUTHORIZE_PATH = 'pageforge-oauth/authorize';

    public static function init(): void {
        \add_action('rest_api_init', [self::class, 'register_routes']);
        // Serves the root-level well-known documents and the consent endpoint.
        // `parse_request` is used rather than rewrite rules so these paths work
        // under plain permalinks and need no rewrite flush to start working.
        \add_action('parse_request', [self::class, 'maybe_handle_raw_request'], 0);
    }

    // --------------------------------------------------------------- URLs --

    /**
     * The canonical resource identifier for this MCP server, per RFC 8707 s2.
     * Tokens are bound to exactly this string, and a token presented with a
     * different audience is rejected. No trailing slash, no fragment, no
     * query, lowercase scheme/host, per the spec's canonical-URI guidance.
     */
    public static function resource_identifier(): string {
        $url = \untrailingslashit((string) \home_url('/wp-json/' . self::ROUTE_NAMESPACE . '/mcp'));
        $parts = \wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . $host . $port . (string) ($parts['path'] ?? '');
    }

    /**
     * The issuer identifier. RFC 8414 requires the metadata document's
     * `issuer` to match the identifier used to build its well-known URL, so
     * this must be exactly what we advertise, including any subdirectory.
     */
    public static function issuer(): string {
        return \untrailingslashit((string) \home_url());
    }

    /** Always-reachable PRM URL. Used in the WWW-Authenticate challenge. */
    public static function protected_resource_metadata_url(): string {
        return (string) \rest_url(self::ROUTE_NAMESPACE . '/oauth-protected-resource');
    }

    /**
     * Root-level PRM URL. Only reachable when WordPress serves the domain
     * root; see `is_root_install()`.
     */
    public static function wellknown_prm_url(): string {
        return self::issuer() . '/.well-known/oauth-protected-resource';
    }

    public static function wellknown_as_url(): string {
        return self::issuer() . '/.well-known/oauth-authorization-server';
    }

    public static function authorize_url(): string {
        return (string) \home_url('/' . self::AUTHORIZE_PATH);
    }

    public static function token_url(): string {
        return (string) \rest_url(self::ROUTE_NAMESPACE . '/oauth/token');
    }

    public static function revoke_url(): string {
        return (string) \rest_url(self::ROUTE_NAMESPACE . '/oauth/revoke');
    }

    public static function register_url(): string {
        return (string) \rest_url(self::ROUTE_NAMESPACE . '/oauth/register');
    }

    /**
     * Whether WordPress is installed at the domain root. When it is not, the
     * root `/.well-known/...` paths never reach PHP, so header-based discovery
     * (which MCP clients MUST support) is the only working mechanism. This is
     * surfaced in diagnostics rather than failing silently.
     */
    public static function is_root_install(): bool {
        $path = (string) \wp_parse_url(self::issuer(), PHP_URL_PATH);
        return '' === trim($path, '/');
    }

    // ------------------------------------------------------------- routes --

    public static function register_routes(): void {
        \register_rest_route(self::ROUTE_NAMESPACE, '/oauth-protected-resource', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_protected_resource_metadata'],
            'permission_callback' => '__return_true', // Discovery is public by design.
        ]);

        \register_rest_route(self::ROUTE_NAMESPACE, '/oauth-authorization-server', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_authorization_server_metadata'],
            'permission_callback' => '__return_true',
        ]);

        \register_rest_route(self::ROUTE_NAMESPACE, '/oauth/token', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_token'],
            'permission_callback' => '__return_true', // Client auth happens inside.
        ]);

        \register_rest_route(self::ROUTE_NAMESPACE, '/oauth/revoke', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_revoke'],
            'permission_callback' => '__return_true',
        ]);

        \register_rest_route(self::ROUTE_NAMESPACE, '/oauth/register', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_register'],
            'permission_callback' => '__return_true',
        ]);
    }

    // ---------------------------------------------------------- metadata --

    /** RFC 9728 Protected Resource Metadata. */
    public static function protected_resource_metadata(): array {
        return [
            'resource' => self::resource_identifier(),
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => Mcp_Scopes::advertised(),
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'PageForge MCP',
            'resource_documentation' => 'https://pageforge.pro/docs/mcp',
            'tls_client_certificate_bound_access_tokens' => false,
            // Signals that this resource expects audience-restricted tokens.
            'authorization_details_types_supported' => [],
        ];
    }

    /**
     * RFC 8414 Authorization Server Metadata.
     *
     * `registration_endpoint` is advertised while DCR compatibility is
     * enabled. CIMD remains supported for clients that implement it.
     */
    public static function authorization_server_metadata(): array {
        $metadata = [
            'issuer' => self::issuer(),
            'authorization_endpoint' => self::authorize_url(),
            'token_endpoint' => self::token_url(),
            'revocation_endpoint' => self::revoke_url(),
            'scopes_supported' => array_values(array_unique(array_merge(
                Mcp_Scopes::grantable(),
                [Mcp_Scopes::OFFLINE_ACCESS]
            ))),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            // PKCE is mandatory, so `none` (public client) is safe and is what
            // CIMD clients use. `client_secret_basic`/`_post` serve
            // pre-registered confidential clients such as Claude's connector.
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
            // RFC 9207: we emit `iss`, so we must advertise that we do.
            'authorization_response_iss_parameter_supported' => true,
            // CIMD is preferred; guarded DCR remains advertised for clients
            // that have not implemented CIMD yet.
            'client_id_metadata_document_supported' => true,
            'service_documentation' => 'https://pageforge.pro/docs/mcp',
            'ui_locales_supported' => [str_replace('_', '-', (string) \get_locale())],
        ];

        if (Oauth_Clients::dcr_enabled()) {
            $metadata['registration_endpoint'] = self::register_url();
            $metadata['registration_endpoint_auth_methods_supported'] = ['none'];
        }

        return $metadata;
    }

    public static function rest_protected_resource_metadata(): \WP_REST_Response {
        return self::json_metadata(self::protected_resource_metadata());
    }

    public static function rest_authorization_server_metadata(): \WP_REST_Response {
        return self::json_metadata(self::authorization_server_metadata());
    }

    private static function json_metadata(array $data): \WP_REST_Response {
        $response = new \WP_REST_Response($data, 200);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        // Discovery documents are public and stable; let clients cache them.
        $response->header('Cache-Control', 'public, max-age=3600');
        return $response;
    }

    // ------------------------------------------- raw (non-REST) endpoints --

    /**
     * Handles the paths that cannot be REST routes: the root-level well-known
     * discovery documents (whose location RFC 9728/8414 fix) and the browser
     * consent endpoint.
     */
    public static function maybe_handle_raw_request(): void {
        $path = self::current_relative_path();
        if ('' === $path) {
            return;
        }

        // RFC 9728 allows both the root form and a path-inserted form that
        // encodes the resource's own path; accept either.
        if ('.well-known/oauth-protected-resource' === $path
            || 0 === strpos($path, '.well-known/oauth-protected-resource/')) {
            self::emit_json(self::protected_resource_metadata());
        }

        if ('.well-known/oauth-authorization-server' === $path
            || 0 === strpos($path, '.well-known/oauth-authorization-server/')
            || '.well-known/openid-configuration' === $path) {
            self::emit_json(self::authorization_server_metadata());
        }

        if (self::AUTHORIZE_PATH === $path) {
            self::handle_authorize();
        }
    }

    /**
     * The request path relative to the WordPress home path, so a subdirectory
     * install still matches. Query strings and the home prefix are stripped.
     */
    private static function current_relative_path(): string {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ('' === $uri) {
            return '';
        }
        $path = (string) \wp_parse_url($uri, PHP_URL_PATH);
        $path = '/' . ltrim(rawurldecode($path), '/');

        $home = (string) \wp_parse_url(self::issuer(), PHP_URL_PATH);
        $home = '/' . trim((string) $home, '/');
        if ('/' !== $home && 0 === strpos($path, $home)) {
            $path = substr($path, strlen($home));
        }
        return trim((string) $path, '/');
    }

    private static function emit_json(array $data, int $status = 200): void {
        \status_header($status);
        \nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        if (200 === $status) {
            header('Cache-Control: public, max-age=3600');
        }
        echo (string) \wp_json_encode($data);
        exit;
    }

    // ------------------------------------------------ authorization endpoint --

    /**
     * The browser-facing authorization endpoint.
     *
     * Order matters here, and follows OAuth 2.1 s4.1.2.1: parameters that
     * determine *where* a response may be sent (client_id, redirect_uri) are
     * validated first and their failures are rendered on-site, never
     * redirected. Only once the redirect target is proven legitimate may any
     * other error be returned to it. Doing this the other way round is the
     * classic open-redirector.
     */
    private static function handle_authorize(): void {
        if (empty(settings_get()['mcp_enabled'])) {
            self::render_error(__('MCP is disabled', 'pageforge'), __('PageForge MCP is turned off on this site, so it cannot authorize AI clients. An administrator can enable it under PageForge → Connections.', 'pageforge'));
        }

        $ip = Request::client_ip();
        if (!Mcp_Rate_Limit::allow('oauth_authorize', $ip)) {
            self::render_error(__('Too many attempts', 'pageforge'), __('Too many authorization attempts from your network. Please wait a few minutes and try again.', 'pageforge'), 429);
        }

        // --- Stage 1: validate the redirect target before trusting it.
        $client_id = isset($_GET['client_id']) ? trim((string) \wp_unslash($_GET['client_id'])) : '';
        $redirect_uri = isset($_GET['redirect_uri']) ? trim((string) \wp_unslash($_GET['redirect_uri'])) : '';

        if ('' === $client_id) {
            self::render_error(__('Missing client', 'pageforge'), __('The request did not identify which AI client is asking for access.', 'pageforge'));
        }

        $client = Oauth_Clients::resolve($client_id);
        if (null === $client) {
            self::render_error(
                __('Unknown client', 'pageforge'),
                __('PageForge could not verify the AI client making this request. If the client uses a Client ID Metadata Document, the document must be reachable over HTTPS and its client_id must exactly match its own URL.', 'pageforge')
            );
        }

        if ('' === $redirect_uri) {
            // Only safe to default when the client registered exactly one.
            $registered = (array) $client['redirect_uris'];
            if (1 !== count($registered)) {
                self::render_error(__('Missing redirect URI', 'pageforge'), __('The request did not say where to send you afterwards, and this client has more than one registered address.', 'pageforge'));
            }
            $redirect_uri = (string) $registered[0];
        }

        if (!Oauth_Clients::redirect_uri_allowed($client, $redirect_uri)) {
            self::render_error(
                __('Redirect address not allowed', 'pageforge'),
                __('The address this client asked PageForge to return to is not one it has registered. PageForge will not redirect you there.', 'pageforge')
            );
        }

        // --- Stage 2: redirect target is trusted; errors may now go to it.
        $state = isset($_GET['state']) ? (string) \wp_unslash($_GET['state']) : '';
        $response_type = isset($_GET['response_type']) ? (string) \wp_unslash($_GET['response_type']) : '';
        $code_challenge = isset($_GET['code_challenge']) ? (string) \wp_unslash($_GET['code_challenge']) : '';
        $code_challenge_method = isset($_GET['code_challenge_method']) ? (string) \wp_unslash($_GET['code_challenge_method']) : '';
        $resource = isset($_GET['resource']) ? (string) \wp_unslash($_GET['resource']) : '';
        $requested_scope = isset($_GET['scope']) ? (string) \wp_unslash($_GET['scope']) : '';

        if ('code' !== $response_type) {
            self::redirect_error($redirect_uri, 'unsupported_response_type', 'Only the authorization code flow is supported.', $state);
        }

        // PKCE is mandatory in OAuth 2.1, and S256 only: `plain` offers no
        // protection against a code intercepted in the redirect.
        if ('' === $code_challenge) {
            self::redirect_error($redirect_uri, 'invalid_request', 'PKCE code_challenge is required.', $state);
        }
        if ('S256' !== $code_challenge_method) {
            self::redirect_error($redirect_uri, 'invalid_request', 'code_challenge_method must be S256.', $state);
        }
        if (!self::valid_code_challenge($code_challenge)) {
            self::redirect_error($redirect_uri, 'invalid_request', 'code_challenge is not a valid base64url S256 value.', $state);
        }

        // RFC 8707: bind the token to the resource the client names. An
        // unrecognised audience is refused rather than silently rewritten,
        // otherwise this server would happily mint tokens for someone else.
        $resource = '' === $resource ? self::resource_identifier() : $resource;
        if (!self::resource_matches($resource)) {
            self::redirect_error($redirect_uri, 'invalid_target', 'The requested resource does not match this MCP server.', $state);
        }

        $requested = Mcp_Scopes::parse($requested_scope);
        $offline = in_array(Mcp_Scopes::OFFLINE_ACCESS, $requested, true);
        // Default to the advertised minimum when the client names no scope.
        $scopes = empty($requested) ? Mcp_Scopes::advertised() : Mcp_Scopes::filter_grantable($requested);
        if (empty($scopes)) {
            self::redirect_error($redirect_uri, 'invalid_scope', 'None of the requested scopes can be granted on this site.', $state);
        }

        // --- Stage 3: authenticate the WordPress user.
        if (!\is_user_logged_in()) {
            // Returns here after login with every parameter intact.
            \wp_safe_redirect(\wp_login_url(self::current_url()));
            exit;
        }

        $user = \wp_get_current_user();
        $user_id = (int) $user->ID;

        // The grant is bound to this user. It does not confer administrator
        // power just because an administrator approved it: every later tool
        // call re-checks this user's own WordPress capabilities.
        if (!self::user_may_authorize($user_id)) {
            self::render_error(
                __('Account cannot authorize AI clients', 'pageforge'),
                __('Your WordPress account does not have permission to connect an AI client to this site. Ask an administrator to perform the connection.', 'pageforge'),
                403
            );
        }

        // --- Stage 4: consent.
        $decision = isset($_POST['pageforge_oauth_decision']) ? (string) \wp_unslash($_POST['pageforge_oauth_decision']) : '';
        if ('' !== $decision) {
            $nonce = isset($_POST['pageforge_oauth_nonce']) ? (string) \wp_unslash($_POST['pageforge_oauth_nonce']) : '';
            if (!\wp_verify_nonce($nonce, 'pageforge_oauth_consent')) {
                self::render_error(__('Request expired', 'pageforge'), __('This authorization request expired. Start the connection again from your AI client.', 'pageforge'), 400);
            }
            if ('approve' !== $decision) {
                self::redirect_error($redirect_uri, 'access_denied', 'The user declined the request.', $state);
            }
            self::issue_code($client, $user_id, $redirect_uri, $scopes, $resource, $code_challenge, $code_challenge_method, $state, $offline);
        }

        // Previously approved for at least these scopes: no need to re-ask.
        if (Oauth_Tokens::has_consent($user_id, (string) $client['client_id'], $scopes)) {
            self::issue_code($client, $user_id, $redirect_uri, $scopes, $resource, $code_challenge, $code_challenge_method, $state, $offline);
        }

        self::render_consent($client, $user, $scopes, $resource);
    }

    /**
     * Mint an authorization code and hand control back to the client.
     * `iss` is included per RFC 9207 so the client can detect a mix-up attack.
     */
    private static function issue_code(array $client, int $user_id, string $redirect_uri, array $scopes, string $resource, string $challenge, string $challenge_method, string $state, bool $offline): void {
        Oauth_Tokens::remember_consent($user_id, (string) $client['client_id'], $scopes);

        $code = 'pfac_' . \wp_generate_password(48, false, false);
        Oauth_Tokens::store_code($code, [
            'client_id' => (string) $client['client_id'],
            'user_id' => $user_id,
            'redirect_uri' => $redirect_uri,
            'scopes' => $scopes,
            'resource' => $resource,
            'code_challenge' => $challenge,
            'code_challenge_method' => $challenge_method,
            'offline_access' => $offline,
        ]);

        $args = ['code' => $code, 'iss' => self::issuer()];
        if ('' !== $state) {
            $args['state'] = $state;
        }
        self::redirect_to_client($redirect_uri, $args);
    }

    /** Errors returned to a *validated* redirect URI, per OAuth 2.1 s4.1.2.1. */
    private static function redirect_error(string $redirect_uri, string $error, string $description, string $state): void {
        $args = [
            'error' => $error,
            'error_description' => $description,
            // RFC 9207 applies to error responses too.
            'iss' => self::issuer(),
        ];
        if ('' !== $state) {
            $args['state'] = $state;
        }
        self::redirect_to_client($redirect_uri, $args);
    }

    /**
     * Redirects to the client. `wp_safe_redirect` is deliberately NOT used:
     * it restricts to the site's own host, and this redirect must reach the
     * client's registered address. Safety comes from the exact-match
     * redirect-URI check that already ran, not from host allow-listing.
     */
    private static function redirect_to_client(string $redirect_uri, array $args): void {
        $url = self::authorization_response_url($redirect_uri, $args);
        \wp_redirect($url, 302);
        exit;
    }


    /**
     * Build an authorization response URL with each value encoded once.
     * WordPress add_query_arg() performs query encoding itself; pre-encoding
     * values here double-encoded iss, causing strict MCP clients to reject
     * an otherwise valid authorization response.
     *
     * @internal Public so the exact callback representation is testable.
     */
    public static function authorization_response_url(string $redirect_uri, array $args): string {
        return (string) \add_query_arg($args, $redirect_uri);
    }

    /**
     * Who may connect an AI client. `manage_options` by default because an MCP
     * grant can read across the site. Sites that delegate content operations
     * can widen this deliberately via the filter.
     */
    public static function user_may_authorize(int $user_id): bool {
        $allowed = \user_can($user_id, 'manage_options');
        return (bool) \apply_filters('pageforge_oauth_user_may_authorize', $allowed, $user_id);
    }

    /**
     * Audience check. Accepts the canonical identifier, and tolerates a
     * trailing slash and uppercase scheme/host, which the spec asks servers to
     * accept for interoperability. Anything else is a different resource.
     */
    public static function resource_matches(string $resource): bool {
        $canonical = self::resource_identifier();
        $candidate = \untrailingslashit(trim($resource));
        if (\hash_equals($canonical, $candidate)) {
            return true;
        }
        $parts = \wp_parse_url($candidate);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }
        $normalized = strtolower((string) ($parts['scheme'] ?? '')) . '://'
            . strtolower((string) $parts['host'])
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
            . (string) ($parts['path'] ?? '');
        if (\hash_equals($canonical, \untrailingslashit($normalized))) {
            return true;
        }
        // The origin alone is also a valid identifier for this server.
        $origin = strtolower((string) ($parts['scheme'] ?? '')) . '://' . strtolower((string) $parts['host'])
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
        return \hash_equals(\untrailingslashit(self::issuer()), \untrailingslashit($origin))
            && in_array(trim((string) ($parts['path'] ?? ''), '/'), ['', 'wp-json'], true);
    }

    private static function valid_code_challenge(string $challenge): bool {
        // base64url of a SHA-256 digest: 43 chars, no padding.
        return 1 === preg_match('/^[A-Za-z0-9\-_]{43}$/', $challenge);
    }

    /**
     * The current request URL, rebuilt from the site's own scheme/host plus the
     * raw request path.
     *
     * Deliberately not `home_url(add_query_arg([]))`: WordPress's
     * `add_query_arg()` with a single array argument returns a URL derived from
     * REQUEST_URI, which already contains any subdirectory path, so prefixing
     * it with home_url() duplicates that path on a subdirectory install and the
     * login round-trip lands on a 404.
     *
     * Only the scheme and host come from site configuration; the path and query
     * are taken from the request but never used as a redirect target, only as
     * the form action and the post-login return URL, which WordPress validates
     * against the site host itself.
     */
    private static function current_url(): string {
        $base = (string) \home_url();
        $parts = \wp_parse_url($base);
        $origin = strtolower((string) ($parts['scheme'] ?? 'https')) . '://' . (string) ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ('' === $uri) {
            return self::authorize_url();
        }
        return $origin . '/' . ltrim($uri, '/');
    }

    // ------------------------------------------------------ token endpoint --

    public static function rest_token(\WP_REST_Request $request) {
        if (empty(settings_get()['mcp_enabled'])) {
            return self::token_error('invalid_request', __('MCP is disabled on this site.', 'pageforge'), 400);
        }

        $ip = Request::client_ip();
        if (!Mcp_Rate_Limit::allow('oauth_token', $ip)) {
            return self::token_error('slow_down', __('Too many token requests.', 'pageforge'), 429, [
                'Retry-After' => (string) Mcp_Rate_Limit::retry_after('oauth_token'),
            ]);
        }

        $params = self::form_params($request);
        $grant_type = (string) ($params['grant_type'] ?? '');

        $client = self::authenticate_client($request, $params);
        if (\is_wp_error($client)) {
            // RFC 6749 s5.2: invalid client authentication is 401.
            return self::token_error('invalid_client', $client->get_error_message(), 401);
        }

        if ('authorization_code' === $grant_type) {
            return self::grant_authorization_code($client, $params);
        }
        if ('refresh_token' === $grant_type) {
            return self::grant_refresh_token($client, $params);
        }
        return self::token_error('unsupported_grant_type', __('Unsupported grant_type.', 'pageforge'), 400);
    }

    /**
     * Exchange an authorization code.
     *
     * Every value the code was minted with is re-verified against what the
     * token request now claims. This is where redirect substitution, code
     * injection across clients, and audience swapping are caught.
     */
    private static function grant_authorization_code(array $client, array $params) {
        $code = (string) ($params['code'] ?? '');
        $verifier = (string) ($params['code_verifier'] ?? '');
        if ('' === $code || '' === $verifier) {
            return self::token_error('invalid_request', __('code and code_verifier are required.', 'pageforge'), 400);
        }

        $record = Oauth_Tokens::consume_code($code);
        if (null === $record) {
            return self::token_error('invalid_grant', __('Authorization code is invalid, expired, or already used.', 'pageforge'), 400);
        }

        // The code belongs to one client only.
        if (!\hash_equals((string) ($record['client_id'] ?? ''), (string) $client['client_id'])) {
            return self::token_error('invalid_grant', __('Authorization code was not issued to this client.', 'pageforge'), 400);
        }

        // Redirect URI must be identical to the one the code was bound to.
        $redirect_uri = (string) ($params['redirect_uri'] ?? '');
        if ('' !== $redirect_uri && !\hash_equals((string) ($record['redirect_uri'] ?? ''), $redirect_uri)) {
            return self::token_error('invalid_grant', __('redirect_uri does not match the authorization request.', 'pageforge'), 400);
        }

        // PKCE: SHA-256 the verifier and compare against the stored challenge.
        if (!self::verify_pkce($verifier, (string) ($record['code_challenge'] ?? ''))) {
            return self::token_error('invalid_grant', __('PKCE verification failed.', 'pageforge'), 400);
        }

        // RFC 8707: if the token request names a resource it must be the same
        // audience the code was issued for.
        $resource = (string) ($params['resource'] ?? '');
        $bound_resource = (string) ($record['resource'] ?? self::resource_identifier());
        if ('' !== $resource && !\hash_equals(\untrailingslashit($bound_resource), \untrailingslashit($resource))) {
            if (!self::resource_matches($resource)) {
                return self::token_error('invalid_target', __('resource does not match the authorization request.', 'pageforge'), 400);
            }
        }

        // Entitlement is re-evaluated at issue time, not trusted from the code.
        $scopes = Mcp_Scopes::filter_grantable((array) ($record['scopes'] ?? []));
        if (empty($scopes)) {
            return self::token_error('invalid_scope', __('The approved scopes can no longer be granted on this site.', 'pageforge'), 400);
        }

        $user_id = (int) ($record['user_id'] ?? 0);
        if ($user_id <= 0 || !self::user_may_authorize($user_id)) {
            return self::token_error('invalid_grant', __('The approving user can no longer authorize AI clients.', 'pageforge'), 400);
        }

        return self::token_response($client, $user_id, $scopes, $bound_resource, !empty($record['offline_access']));
    }

    private static function grant_refresh_token(array $client, array $params) {
        $token = (string) ($params['refresh_token'] ?? '');
        if ('' === $token) {
            return self::token_error('invalid_request', __('refresh_token is required.', 'pageforge'), 400);
        }

        $requested = Mcp_Scopes::parse((string) ($params['scope'] ?? ''));
        $rotated = Oauth_Tokens::rotate_refresh_token($token, (string) $client['client_id'], array_values(array_filter($requested, static function (string $scope): bool {
            return Mcp_Scopes::OFFLINE_ACCESS !== $scope;
        })));
        if (\is_wp_error($rotated)) {
            return self::token_error((string) $rotated->get_error_code(), $rotated->get_error_message(), 400);
        }

        $record = $rotated['record'];
        $user_id = (int) ($record['user_id'] ?? 0);
        if ($user_id <= 0 || !self::user_may_authorize($user_id)) {
            Oauth_Tokens::revoke_refresh_token($rotated['token']);
            return self::token_error('invalid_grant', __('The approving user can no longer authorize AI clients.', 'pageforge'), 400);
        }

        $access = Oauth_Tokens::issue_access_token([
            'client_id' => (string) $client['client_id'],
            'user_id' => $user_id,
            'scopes' => (array) $record['scopes'],
            'resource' => (string) $record['resource'],
        ]);

        return self::json_no_store([
            'access_token' => $access['token'],
            'token_type' => 'Bearer',
            'expires_in' => $access['expires_in'],
            'refresh_token' => $rotated['token'],
            'scope' => Mcp_Scopes::to_string((array) $record['scopes']),
        ]);
    }

    private static function token_response(array $client, int $user_id, array $scopes, string $resource, bool $offline) {
        $access = Oauth_Tokens::issue_access_token([
            'client_id' => (string) $client['client_id'],
            'user_id' => $user_id,
            'scopes' => $scopes,
            'resource' => $resource,
        ]);

        $body = [
            'access_token' => $access['token'],
            'token_type' => 'Bearer',
            'expires_in' => $access['expires_in'],
            // Echoed so a client can see it received less than it asked for.
            'scope' => Mcp_Scopes::to_string($scopes),
        ];

        if ($offline) {
            $body['refresh_token'] = Oauth_Tokens::issue_refresh_token([
                'client_id' => (string) $client['client_id'],
                'user_id' => $user_id,
                'scopes' => $scopes,
                'resource' => $resource,
            ]);
        }

        return self::json_no_store($body);
    }

    /**
     * PKCE S256 verification. Constant-time compare, and the verifier length
     * bounds from RFC 7636 s4.1 are enforced so a trivially short verifier
     * cannot be brute forced.
     */
    public static function verify_pkce(string $verifier, string $challenge): bool {
        $length = strlen($verifier);
        if ($length < 43 || $length > 128 || '' === $challenge) {
            return false;
        }
        if (1 !== preg_match('/^[A-Za-z0-9\-._~]+$/', $verifier)) {
            return false;
        }
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return \hash_equals($challenge, $computed);
    }

    /**
     * Client authentication.
     *
     * A confidential client MUST present its secret; a public client (CIMD or
     * dynamically registered) authenticates by PKCE alone, which is what
     * OAuth 2.1 prescribes. Critically, a client registered as confidential
     * cannot downgrade itself to public by simply omitting the secret.
     *
     * @return array|\WP_Error
     */
    private static function authenticate_client(\WP_REST_Request $request, array $params) {
        $client_id = (string) ($params['client_id'] ?? '');
        $secret = (string) ($params['client_secret'] ?? '');

        // HTTP Basic per RFC 6749 s2.3.1 takes precedence when present.
        $authorization = (string) $request->get_header('Authorization');
        if ('' !== $authorization && 0 === stripos($authorization, 'Basic ')) {
            $decoded = base64_decode(trim(substr($authorization, 6)), true);
            if (false !== $decoded && false !== strpos($decoded, ':')) {
                [$basic_id, $basic_secret] = explode(':', $decoded, 2);
                $client_id = urldecode($basic_id);
                $secret = urldecode($basic_secret);
            }
        }

        if ('' === $client_id) {
            return new \WP_Error('invalid_client', __('client_id is required.', 'pageforge'));
        }

        $client = Oauth_Clients::resolve($client_id);
        if (null === $client) {
            return new \WP_Error('invalid_client', __('Unknown client.', 'pageforge'));
        }

        if (!empty($client['confidential'])) {
            if (!Oauth_Clients::verify_secret($client, $secret)) {
                return new \WP_Error('invalid_client', __('Client authentication failed.', 'pageforge'));
            }
        }

        return $client;
    }

    // --------------------------------------------------- revocation (7009) --

    public static function rest_revoke(\WP_REST_Request $request) {
        $ip = Request::client_ip();
        if (!Mcp_Rate_Limit::allow('oauth_token', $ip)) {
            return self::token_error('slow_down', __('Too many requests.', 'pageforge'), 429);
        }

        $params = self::form_params($request);
        $client = self::authenticate_client($request, $params);
        if (\is_wp_error($client)) {
            return self::token_error('invalid_client', $client->get_error_message(), 401);
        }

        $token = (string) ($params['token'] ?? '');
        if ('' !== $token) {
            if (0 === strpos($token, 'pfrt_')) {
                Oauth_Tokens::revoke_refresh_token($token);
            } elseif (0 === strpos($token, 'pfat_')) {
                $record = Oauth_Tokens::lookup_access_token($token);
                // Only the owning client may revoke a token.
                if (is_array($record) && \hash_equals((string) ($record['client_id'] ?? ''), (string) $client['client_id'])) {
                    Oauth_Tokens::revoke_access_token($token);
                }
            }
        }

        // RFC 7009 s2.2: an unknown token is still a successful revocation, so
        // this endpoint cannot be used to probe which tokens exist.
        return self::json_no_store([], 200);
    }

    // ------------------------------------------------- registration (7591) --

    public static function rest_register(\WP_REST_Request $request) {
        if (!Oauth_Clients::dcr_enabled()) {
            // Not advertised in metadata either, so a compliant client will
            // have used CIMD instead.
            return self::token_error('invalid_request', __('Dynamic client registration is disabled. Use a Client ID Metadata Document, or ask the site administrator for a pre-registered client.', 'pageforge'), 404);
        }

        $ip = Request::client_ip();
        if (!Mcp_Rate_Limit::allow('oauth_register', $ip)) {
            return self::token_error('slow_down', __('Too many registration attempts.', 'pageforge'), 429);
        }

        $body = json_decode((string) $request->get_body(), true);
        if (!is_array($body)) {
            return self::token_error('invalid_client_metadata', __('Request body must be a JSON object.', 'pageforge'), 400);
        }

        $registered = Oauth_Clients::register_dynamic(
            (string) ($body['client_name'] ?? ''),
            (array) ($body['redirect_uris'] ?? [])
        );
        if (\is_wp_error($registered)) {
            return self::token_error((string) $registered->get_error_code(), $registered->get_error_message(), 400);
        }

        return self::json_no_store($registered, 201);
    }

    // ------------------------------------------------------------ helpers --

    /**
     * Token endpoint params. OAuth uses form encoding, but some clients send
     * JSON, so both are accepted; form values win when both are present.
     */
    private static function form_params(\WP_REST_Request $request): array {
        $params = [];
        $body = (string) $request->get_body();
        if ('' !== $body) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $params = $decoded;
            } else {
                parse_str($body, $parsed);
                if (is_array($parsed)) {
                    $params = $parsed;
                }
            }
        }
        if (method_exists($request, 'get_body_params')) {
            $form = $request->get_body_params();
            if (is_array($form) && !empty($form)) {
                $params = array_merge($params, $form);
            }
        }
        return array_map(static function ($value) {
            return is_scalar($value) ? (string) $value : $value;
        }, $params);
    }

    private static function json_no_store(array $data, int $status = 200): \WP_REST_Response {
        $response = new \WP_REST_Response($data, $status);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        // RFC 6749 s5.1: token responses must never be cached.
        $response->header('Cache-Control', 'no-store');
        $response->header('Pragma', 'no-cache');
        return $response;
    }

    private static function token_error(string $error, string $description, int $status, array $headers = []): \WP_REST_Response {
        $response = self::json_no_store([
            'error' => $error,
            'error_description' => $description,
        ], $status);
        if (401 === $status) {
            $response->header('WWW-Authenticate', 'Basic realm="PageForge OAuth"');
        }
        foreach ($headers as $key => $value) {
            $response->header($key, $value);
        }
        return $response;
    }

    /**
     * On-site error page. Used only for failures that must NOT be redirected
     * to a client: an unverified client, a rejected redirect URI, or a
     * permission problem with the WordPress account.
     */
    private static function render_error(string $title, string $message, int $status = 400): void {
        \status_header($status);
        \nocache_headers();
        \wp_die(
            '<h1>' . \esc_html($title) . '</h1><p>' . \esc_html($message) . '</p>',
            \esc_html($title),
            ['response' => $status, 'back_link' => false]
        );
    }

    /**
     * The consent screen. Shows who is asking, what they will be able to do,
     * and which WordPress account the grant will act as, because "approve" is
     * meaningless without those three facts.
     */
    private static function render_consent(array $client, $user, array $scopes, string $resource): void {
        \nocache_headers();
        $client_name = (string) ($client['client_name'] ?? $client['client_id']);
        $client_uri = (string) ($client['client_uri'] ?? '');
        $is_cimd = Oauth_Clients::TYPE_CIMD === ($client['type'] ?? '');

        \status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html <?php \language_attributes(); ?>>
<head>
    <meta charset="<?php \bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <title><?php echo \esc_html__('Authorize AI client access', 'pageforge'); ?></title>
    <?php /* Self-contained styles: this page must render identically regardless of theme, and must not depend on an admin stylesheet being registered on a front-end request. */ ?>
    <style>
        body { background: #f0f0f1; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1d2327; margin: 0; padding: 40px 20px; }
        .pf-consent { max-width: 480px; margin: 0 auto; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 28px; }
        .pf-consent h1 { font-size: 20px; margin: 0 0 4px; }
        .pf-consent .pf-sub { color: #50575e; font-size: 13px; margin: 0 0 20px; }
        .pf-client { display: flex; align-items: center; gap: 12px; padding: 12px; border: 1px solid #dcdcde; border-radius: 6px; margin-bottom: 20px; }
        .pf-client strong { display: block; font-size: 14px; }
        .pf-client span { color: #50575e; font-size: 12px; word-break: break-all; }
        .pf-scopes { list-style: none; margin: 0 0 20px; padding: 0; }
        .pf-scopes li { padding: 10px 0; border-top: 1px solid #f0f0f1; font-size: 13px; }
        .pf-scopes li:first-child { border-top: none; }
        .pf-scopes strong { display: block; }
        .pf-scopes span { color: #50575e; }
        .pf-meta { background: #f6f7f7; border-radius: 6px; padding: 12px; font-size: 12px; color: #50575e; margin-bottom: 20px; }
        .pf-meta code { word-break: break-all; font-size: 11px; }
        .pf-actions { display: flex; gap: 10px; }
        .pf-actions button { flex: 1; padding: 10px 16px; border-radius: 4px; font-size: 14px; cursor: pointer; border: 1px solid transparent; }
        .pf-approve { background: #2271b1; border-color: #2271b1 !important; color: #fff; }
        .pf-approve:hover { background: #135e96; }
        .pf-deny { background: #fff; border-color: #2271b1 !important; color: #2271b1; }
        .pf-warn { background: #fcf9e8; border-left: 4px solid #dba617; padding: 10px 12px; font-size: 12px; margin-bottom: 20px; }
        button:focus-visible, a:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; }
    </style>
</head>
<body>
    <main class="pf-consent">
        <h1><?php echo \esc_html__('Authorize AI client access', 'pageforge'); ?></h1>
        <p class="pf-sub"><?php echo \esc_html(sprintf(
            /* translators: %s: WordPress display name of the approving user. */
            __('You are signed in as %s. This client will act with your permissions.', 'pageforge'),
            (string) $user->display_name
        )); ?></p>

        <div class="pf-client">
            <div>
                <strong><?php echo \esc_html($client_name); ?></strong>
                <span><?php echo \esc_html('' !== $client_uri ? $client_uri : (string) $client['client_id']); ?></span>
            </div>
        </div>

        <?php if ($is_cimd) : ?>
            <p class="pf-warn"><?php echo \esc_html__('This client identified itself with a metadata document at the address above. PageForge verified the document, but anyone controlling that address can present this identity. Only approve it if you started this connection.', 'pageforge'); ?></p>
        <?php endif; ?>

        <p class="pf-sub"><strong><?php echo \esc_html__('It is requesting permission to:', 'pageforge'); ?></strong></p>
        <ul class="pf-scopes">
            <?php foreach ($scopes as $scope) : ?>
                <li>
                    <strong><?php echo \esc_html(Mcp_Scopes::label($scope)); ?></strong>
                    <span><?php echo \esc_html(Mcp_Scopes::description($scope)); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="pf-meta">
            <?php echo \esc_html__('Access is limited to this PageForge endpoint:', 'pageforge'); ?><br />
            <code><?php echo \esc_html($resource); ?></code>
        </div>

        <form method="post" action="<?php echo \esc_url(self::current_url()); ?>">
            <?php \wp_nonce_field('pageforge_oauth_consent', 'pageforge_oauth_nonce'); ?>
            <div class="pf-actions">
                <button type="submit" class="pf-deny" name="pageforge_oauth_decision" value="deny"><?php echo \esc_html__('Cancel', 'pageforge'); ?></button>
                <button type="submit" class="pf-approve" name="pageforge_oauth_decision" value="approve" autofocus><?php echo \esc_html__('Approve', 'pageforge'); ?></button>
            </div>
        </form>
    </main>
</body>
</html><?php
        exit;
    }
}
