<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * PageForge MCP transport.
 *
 * Primary target is MCP 2026-07-28, whose headline change is a stateless
 * protocol core: no `initialize` handshake, no `Mcp-Session-Id`, every request
 * self-describing. That suits PHP exactly, since there is no long-lived
 * process to hold session state in.
 *
 * Two authentication models are supported, and they are kept strictly apart:
 *
 *   1. OAuth 2.1 access tokens (Oauth/Oauth_Tokens). This is what remote
 *      connectors such as ChatGPT and Claude use. Tokens are audience-bound to
 *      this server's canonical resource URI and carry a WordPress user.
 *   2. Personal Access Tokens (Mcp_Credentials). For clients that let the
 *      operator supply a custom authentication header: IDE configurations,
 *      Codex, Copilot, Cursor, Claude Code, generic MCP clients.
 *
 * OAuth uses `Authorization: Bearer`; PATs use
 * `X-PageForge-MCP-API-Key`. Both resolve to the same internal shape: a
 * WordPress user plus a scope set. Never a cookie session or query token.
 *
 * Authorization is layered and every layer runs on every call:
 *   transport auth -> audience -> scope -> WordPress capability of the
 *   acting user -> per-tool domain validation.
 * A scope grant never substitutes for a capability check.
 */
class Mcp_Server {
    /** The revision this server implements. */
    public const PROTOCOL_VERSION = '2026-07-28';

    /**
     * Protocol-defined error codes from the MCP reserved sub-range. These are
     * not free choices: a client keys its retry and fallback behaviour on them.
     */
    public const ERROR_HEADER_MISMATCH = -32020;
    public const ERROR_UNSUPPORTED_PROTOCOL = -32022;

    /** Bounded, self-expiring log of rejected requests, for diagnostics. */
    private const FAILURE_LOG_KEY = 'pageforge_mcp_failures';
    private const FAILURE_LOG_MAX = 25;

    /**
     * Older revisions we can serve through the compatibility path. Listed
     * newest first. We echo back the version the client actually asked for
     * when we can speak it, rather than answering an old handshake with a new
     * version string and hoping the client copes.
     */
    public const LEGACY_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    /**
     * Assumed when a request carries no version at all. The spec permits
     * treating a header-less request as 2025-03-26 for clients that predate the
     * header, and assuming the oldest is safer than assuming the newest.
     */
    public const OLDEST_PROTOCOL_VERSION = '2025-03-26';

    private const ROUTE_NAMESPACE = 'pageforge/v1';

    public static function init(): void {
        \add_action('rest_api_init', [self::class, 'register_routes']);
        \add_filter('rest_allowed_cors_headers', [self::class, 'allowed_cors_headers']);
        \add_filter('rest_exposed_cors_headers', [self::class, 'exposed_cors_headers']);
    }

    public static function allowed_cors_headers(array $headers): array {
        return array_values(array_unique(array_merge($headers, [
            'MCP-Protocol-Version',
            'Mcp-Method',
            'Mcp-Name',
            'Mcp-Session-Id',
            'X-PageForge-MCP-API-Key',
        ])));
    }

    public static function exposed_cors_headers(array $headers): array {
        return array_values(array_unique(array_merge($headers, [
            'MCP-Protocol-Version',
            'Mcp-Session-Id',
            'WWW-Authenticate',
        ])));
    }

    public static function register_routes(): void {
        \register_rest_route(self::ROUTE_NAMESPACE, '/mcp', [
            'methods' => ['POST', 'GET', 'DELETE'],
            'callback' => [self::class, 'handle_request'],
            // Authorization runs inside the handler so an unauthenticated call
            // gets an RFC 6750 challenge naming our Protected Resource
            // Metadata, rather than WordPress's generic REST error shape. That
            // challenge is what lets a remote client discover OAuth at all.
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_request(\WP_REST_Request $request) {
        if (empty(settings_get()['mcp_enabled'])) {
            return self::error_response(null, -32003, __('MCP is disabled on this site.', 'pageforge'), 403);
        }

        // Streamable HTTP s1: servers MUST validate Origin to prevent DNS
        // rebinding, where a remote page resolves a hostname to this server and
        // then drives the MCP endpoint using the victim's credentials. Absent
        // Origin (a normal server-to-server agent call) is fine; a present but
        // foreign Origin is refused.
        $origin_error = self::validate_origin($request);
        if (null !== $origin_error) {
            return self::error_response(null, -32003, $origin_error, 403);
        }

        $http_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST'));

        // Streamable HTTP s7.1: this revision removed the GET stream endpoint
        // and protocol-level sessions, so a server implementing it MUST answer
        // GET (legacy standalone SSE probe) and DELETE (legacy session
        // teardown) with 405. Returning 200 and a JSON body on GET is what made
        // a client expecting `text/event-stream` treat the connection as broken
        // immediately after a successful OAuth handshake.
        //
        // Authentication is still evaluated first for GET: a client with no
        // credential gets the 401 challenge that carries our Protected Resource
        // Metadata pointer, because that is how OAuth discovery starts and
        // withholding it behind a 405 would leave an unauthenticated client with
        // nowhere to go. An authenticated GET gets the compliant 405.
        if ('GET' === $http_method || 'DELETE' === $http_method) {
            if ('GET' === $http_method) {
                $probe = self::authenticate($request);
                if (\is_wp_error($probe)) {
                    return self::auth_challenge($probe, null);
                }
            }
            $response = self::error_response(
                null,
                -32003,
                __('This MCP endpoint accepts POST only. It is stateless: there is no GET event stream and no session to delete.', 'pageforge'),
                405
            );
            $response->header('Allow', 'POST');
            return $response;
        }

        if ('POST' !== $http_method) {
            $response = self::error_response(null, -32003, __('This MCP endpoint accepts POST only.', 'pageforge'), 405);
            $response->header('Allow', 'POST');
            return $response;
        }

        $raw = (string) $request->get_body();
        // Bound the body before doing any other work. PHP already received the
        // bytes, but this prevents an oversized request from reaching token
        // lookup or JSON decoding.
        if (strlen($raw) > 1048576) {
            return self::error_response(null, -32600, __('Request body is too large.', 'pageforge'), 413);
        }

        // MCP is a protected HTTP resource. Authenticate at that boundary,
        // before inspecting JSON-RPC, so an initial empty/non-JSON discovery
        // POST receives the OAuth 401 challenge instead of a misleading parse
        // error. Authenticated clients still receive strict JSON diagnostics.
        $auth = self::authenticate($request);
        if (\is_wp_error($auth)) {
            return self::auth_challenge($auth, null);
        }

        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $message = '' === trim($raw)
                ? __('Malformed JSON: the POST body is empty.', 'pageforge')
                : sprintf(
                    /* translators: %s: PHP JSON parser error. */
                    __('Malformed JSON: %s', 'pageforge'),
                    json_last_error_msg()
                );
            return self::error_response(null, -32700, $message, 400);
        }
        if (!isset($body['jsonrpc']) || '2.0' !== $body['jsonrpc'] || !isset($body['method']) || !is_string($body['method'])) {
            return self::error_response(null, -32600, __('Invalid JSON-RPC request.', 'pageforge'), 400);
        }

        $id = $body['id'] ?? null;
        $method = (string) $body['method'];
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        $is_notification = !array_key_exists('id', $body);

        // 2026-07-28 mirrors selected body fields into headers so ordinary HTTP
        // infrastructure can route MCP traffic. Where a client sends them they
        // MUST agree with the body. A mismatch is rejected rather than resolved
        // by preference: silently trusting one over the other creates a surface
        // where a proxy authorises the header while we execute the body.
        //
        // The spec reserves a specific code for this (-32020 HeaderMismatch);
        // returning a generic -32600 would make a modern client unable to tell
        // a header problem from a malformed request, and per the backward
        // compatibility rules it might then wrongly fall back to a legacy
        // handshake.
        $header_conflict = self::header_body_conflict($request, $method, $params);
        if (null !== $header_conflict) {
            return self::error_response($id, self::ERROR_HEADER_MISMATCH, $header_conflict, 400);
        }

        $negotiated = self::negotiate_protocol($request, $params);
        if (\is_wp_error($negotiated)) {
            // Two distinct faults share this return path and must not collapse
            // into one code: a header that disagrees with the body is a
            // HeaderMismatch the client fixes by correcting its request, while
            // an unimplemented version is answered with the list of versions we
            // do support so the client can retry on common ground.
            if ('header_mismatch' === $negotiated->get_error_code()) {
                return self::error_response(
                    $id,
                    self::ERROR_HEADER_MISMATCH,
                    (string) $negotiated->get_error_message(),
                    400
                );
            }
            return self::error_response(
                $id,
                self::ERROR_UNSUPPORTED_PROTOCOL,
                (string) $negotiated->get_error_message(),
                400,
                [],
                [
                    'supported' => self::supported_protocol_versions(),
                    'requested' => self::requested_protocol_version($request, $params),
                ]
            );
        }

        if ($is_notification) {
            // Legacy `notifications/initialized`, cancellations, etc. Nothing
            // to attach state to; acknowledge statelessly.
            return self::no_content_response(202);
        }

        switch ($method) {
            case 'initialize':
                // Legacy-only. Answer with the version the client can speak,
                // not unconditionally with ours.
                return self::respond($id, self::initialize_result($negotiated, $auth), $negotiated);
            case 'server/discover':
                return self::respond($id, self::discover_result($auth), $negotiated);
            case 'tools/list':
                return self::respond($id, self::tools_list_result($auth), $negotiated);
            case 'tools/call':
                return self::handle_tool_call($id, $params, $auth, $negotiated);
            case 'ping':
                return self::respond($id, (object) [], $negotiated);
            default:
                return self::error_response($id, -32601, sprintf(
                    /* translators: %s: JSON-RPC method name. */
                    __('Method not found: %s', 'pageforge'),
                    $method
                ), 404);
        }
    }

    // ------------------------------------------------ protocol negotiation --

    /**
     * Resolve the protocol revision for this request.
     *
     * Sources, in order: the `MCP-Protocol-Version` header, the modern
     * `_meta['io.modelcontextprotocol/protocolVersion']`, then a legacy
     * `initialize` params.protocolVersion.
     *
     * Where both the header and `_meta` are present they MUST agree; a
     * disagreement is a HeaderMismatch, for the same reason the method/name
     * headers must agree with the body.
     *
     * A request with no version at all is treated as 2025-03-26, which the spec
     * explicitly permits for clients predating the header.
     *
     * @return string|\WP_Error
     */
    private static function negotiate_protocol(\WP_REST_Request $request, array $params) {
        $header = trim((string) $request->get_header('MCP-Protocol-Version'));
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $meta_version = trim((string) ($meta['io.modelcontextprotocol/protocolVersion'] ?? ''));

        if ('' !== $header && '' !== $meta_version && $header !== $meta_version) {
            return new \WP_Error('header_mismatch', __('MCP-Protocol-Version header does not match the protocol version in _meta.', 'pageforge'));
        }

        $requested = '' !== $header ? $header : $meta_version;
        if ('' === $requested) {
            $requested = trim((string) ($params['protocolVersion'] ?? ''));
        }
        if ('' === $requested) {
            // Pre-header client. Answering in the oldest revision we support is
            // safer than assuming it speaks the newest.
            return self::OLDEST_PROTOCOL_VERSION;
        }
        if (in_array($requested, self::supported_protocol_versions(), true)) {
            return $requested;
        }
        return new \WP_Error('unsupported_protocol_version', sprintf(
            /* translators: 1: requested protocol version, 2: comma-separated supported versions. */
            __('Unsupported MCP protocol version %1$s. This server supports: %2$s.', 'pageforge'),
            $requested,
            implode(', ', self::supported_protocol_versions())
        ));
    }

    /** The version named by the request, for UnsupportedProtocolVersionError data. */
    private static function requested_protocol_version(\WP_REST_Request $request, array $params): string {
        $header = trim((string) $request->get_header('MCP-Protocol-Version'));
        if ('' !== $header) {
            return $header;
        }
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $meta_version = trim((string) ($meta['io.modelcontextprotocol/protocolVersion'] ?? ''));
        if ('' !== $meta_version) {
            return $meta_version;
        }
        return trim((string) ($params['protocolVersion'] ?? ''));
    }

    /**
     * Origin validation for DNS-rebinding protection.
     *
     * A missing Origin is normal for a server-to-server agent call and is
     * allowed. A present Origin must be this site, because the only thing that
     * sends one is a browser, and a browser reaching this endpoint from another
     * site is the rebinding attack the spec requires us to block.
     *
     * @return string|null Error message when the request must be refused.
     */
    private static function validate_origin(\WP_REST_Request $request): ?string {
        $origin = trim((string) $request->get_header('Origin'));
        if ('' === $origin || 'null' === strtolower($origin)) {
            return null;
        }

        $allowed = [
            \untrailingslashit((string) \home_url()),
            \untrailingslashit((string) \site_url()),
            'https://chatgpt.com',
            'https://chat.openai.com',
            'https://claude.ai',
        ];
        /** Lets a site permit a known first-party front end to call MCP directly. */
        $allowed = array_merge($allowed, (array) \apply_filters('pageforge_mcp_allowed_origins', []));

        $origin_parts = \wp_parse_url($origin);
        if (!is_array($origin_parts) || empty($origin_parts['host'])) {
            return __('Invalid Origin header.', 'pageforge');
        }
        $normalized = strtolower((string) ($origin_parts['scheme'] ?? '')) . '://' . strtolower((string) $origin_parts['host'])
            . (isset($origin_parts['port']) ? ':' . (int) $origin_parts['port'] : '');

        foreach ($allowed as $candidate) {
            $candidate_parts = \wp_parse_url((string) $candidate);
            if (!is_array($candidate_parts) || empty($candidate_parts['host'])) {
                continue;
            }
            $candidate_origin = strtolower((string) ($candidate_parts['scheme'] ?? '')) . '://' . strtolower((string) $candidate_parts['host'])
                . (isset($candidate_parts['port']) ? ':' . (int) $candidate_parts['port'] : '');
            if (\hash_equals($candidate_origin, $normalized)) {
                return null;
            }
        }

        return __('Origin is not allowed to access this MCP endpoint.', 'pageforge');
    }

    public static function supported_protocol_versions(): array {
        return array_merge([self::PROTOCOL_VERSION], self::LEGACY_PROTOCOL_VERSIONS);
    }

    /**
     * Detect a header/body disagreement about what is being invoked.
     *
     * @return string|null Error message when the request is self-contradictory.
     */
    private static function header_body_conflict(\WP_REST_Request $request, string $method, array $params): ?string {
        $header_method = trim((string) $request->get_header('Mcp-Method'));
        if ('' !== $header_method && $header_method !== $method) {
            return __('Mcp-Method header does not match the JSON-RPC method in the body.', 'pageforge');
        }

        $header_name = trim((string) $request->get_header('Mcp-Name'));
        if ('' === $header_name) {
            return null;
        }
        // Mcp-Name only describes the target of a tools/call.
        if ('tools/call' !== $method) {
            return null;
        }
        $body_name = trim((string) ($params['name'] ?? ''));
        if ('' === $body_name) {
            return null;
        }
        // The header may carry the Base64 sentinel form for values that are not
        // safe as a plain ASCII header. Servers MUST decode before comparing,
        // otherwise a legitimately encoded name reads as a mismatch and the call
        // is rejected.
        $decoded = self::decode_header_value($header_name);
        if (null === $decoded) {
            return __('Mcp-Name header is not a valid Base64 sentinel value.', 'pageforge');
        }
        if ($decoded !== $body_name) {
            return __('Mcp-Name header does not match the tool name in the body.', 'pageforge');
        }
        return null;
    }

    /**
     * Decode the `=?base64?...?=` sentinel wrapper if present, else return the
     * value unchanged. Returns null when the wrapper is present but the payload
     * is not valid Base64.
     */
    private static function decode_header_value(string $value): ?string {
        $prefix = '=?base64?';
        $suffix = '?=';
        if (0 !== strpos($value, $prefix) || substr($value, -strlen($suffix)) !== $suffix) {
            return $value;
        }
        $payload = substr($value, strlen($prefix), -strlen($suffix));
        $decoded = base64_decode($payload, true);
        return false === $decoded ? null : $decoded;
    }

    // ------------------------------------------------------------ results --

    private static function initialize_result(string $protocol, array $auth): array {
        return [
            'protocolVersion' => $protocol,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => self::server_info(),
        ];
    }

    private static function discover_result(array $auth): array {
        return [
            'protocolVersions' => self::supported_protocol_versions(),
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => self::server_info(),
            'authenticatedAs' => [
                // Enough for a client to show who it is acting as, without
                // leaking anything about other site users.
                'user_id' => (int) ($auth['user_id'] ?? 0),
                'scopes' => array_values((array) ($auth['scopes'] ?? [])),
                'authentication' => (string) ($auth['type'] ?? ''),
            ],
        ];
    }

    private static function server_info(): array {
        return [
            'name' => 'pageforge',
            'title' => Edition::PRO === Edition::current() ? 'PageForge (Full MCP)' : 'PageForge (MCP Lite)',
            'version' => defined('PAGEFORGE_VERSION') ? PAGEFORGE_VERSION : '0.0.0',
            'websiteUrl' => 'https://pageforge.pro',
        ];
    }

    private static function tools_list_result(array $auth): array {
        $tools = [];
        foreach (self::available_tools($auth) as $name => $tool) {
            $read_only = (bool) ($tool['readOnly'] ?? true);
            $tools[] = [
                'name' => $name,
                'title' => (string) ($tool['title'] ?? $name),
                'description' => (string) ($tool['description'] ?? ''),
                'inputSchema' => $tool['inputSchema'] ?? ['type' => 'object'],
                // Current annotation shape. `readOnly` is also kept at the top
                // level for older clients that look for it there.
                'annotations' => [
                    'readOnlyHint' => $read_only,
                    'destructiveHint' => (bool) ($tool['destructive'] ?? false),
                    'idempotentHint' => (bool) ($tool['idempotent'] ?? $read_only),
                    'openWorldHint' => false,
                ],
                'readOnly' => $read_only,
                '_meta' => ['io.pageforge/scope' => (string) ($tool['scope'] ?? '')],
            ];
        }
        // Deterministic order so the result is cacheable, as the spec
        // recommends for list results.
        usort($tools, static function (array $a, array $b): int {
            return strcmp($a['name'], $b['name']);
        });
        return [
            'tools' => $tools,
            'resultType' => 'complete',
            'ttlMs' => 60000,
            // The catalog varies per grant, so it must never be shared cache.
            'cacheScope' => 'private',
        ];
    }

    private static function handle_tool_call($id, array $params, array $auth, string $protocol) {
        $name = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $all = self::all_tools($auth);
        if (!isset($all[$name])) {
            return self::error_response($id, -32602, sprintf(
                /* translators: %s: MCP tool name. */
                __('Unknown tool: %s', 'pageforge'),
                $name
            ), 404);
        }

        $tool = $all[$name];
        $required_scope = (string) ($tool['scope'] ?? '');
        $granted = array_values((array) ($auth['scopes'] ?? []));

        // Scope insufficiency is a 403 with an insufficient_scope challenge
        // naming exactly what is needed, so a client can perform step-up
        // authorization instead of guessing.
        if (!Mcp_Scopes::satisfies($granted, $required_scope)) {
            return self::insufficient_scope($id, $required_scope);
        }

        // Cost-aware rate limiting: an AI-backed preview is metered far more
        // tightly than a status read.
        $bucket = Mcp_Rate_Limit::bucket_for_scope($required_scope);
        $subject = (string) ($auth['subject'] ?? Request::client_ip());
        if (!Mcp_Rate_Limit::allow($bucket, $subject)) {
            return self::error_response($id, -32004, __('Rate limit exceeded for this tool class.', 'pageforge'), 429, [
                'Retry-After' => (string) Mcp_Rate_Limit::retry_after($bucket),
            ]);
        }

        if (!is_callable($tool['handler'] ?? null)) {
            return self::error_response($id, -32603, __('Tool handler is not available.', 'pageforge'), 500);
        }

        // The grant acts as a specific WordPress user for the duration of the
        // call. Every capability check inside a handler therefore evaluates
        // that user, not whoever happens to be logged in, and not "admin
        // because an admin approved the connection".
        $restore = self::assume_user((int) ($auth['user_id'] ?? 0));

        $context = [
            'user_id' => (int) ($auth['user_id'] ?? 0),
            'scopes' => $granted,
            'client' => (string) ($auth['client'] ?? ''),
            'credential_id' => (string) ($auth['credential_id'] ?? ''),
            'authentication' => (string) ($auth['type'] ?? ''),
            'tool' => $name,
        ];

        try {
            $result = call_user_func($tool['handler'], $arguments, $context);
        } catch (\Throwable $e) {
            $restore();
            // The message is never echoed back: it can carry file paths and
            // query fragments. It is recorded for the operator instead.
            self::log_tool_failure($name, $e);
            return self::error_response($id, -32603, __('Tool execution failed.', 'pageforge'), 500);
        }
        $restore();

        if (\is_wp_error($result)) {
            return self::respond($id, [
                'content' => [['type' => 'text', 'text' => (string) $result->get_error_message()]],
                'structuredContent' => ['error' => (string) $result->get_error_code(), 'message' => (string) $result->get_error_message()],
                'isError' => true,
                'resultType' => 'complete',
            ], $protocol);
        }

        return self::respond($id, [
            'content' => [['type' => 'text', 'text' => (string) \wp_json_encode($result)]],
            'structuredContent' => $result,
            'isError' => false,
            'resultType' => 'complete',
        ], $protocol);
    }

    /**
     * Swap the acting WordPress user for the duration of a tool call.
     *
     * @return callable Restores the previous user. Always call it, including
     *                 on the exception path, or a later request in the same
     *                 process could inherit the wrong identity.
     */
    private static function assume_user(int $user_id): callable {
        if ($user_id <= 0 || !function_exists('wp_set_current_user')) {
            return static function (): void {};
        }
        $previous = function_exists('get_current_user_id') ? (int) \get_current_user_id() : 0;
        \wp_set_current_user($user_id);
        return static function () use ($previous): void {
            \wp_set_current_user($previous);
        };
    }

    private static function log_tool_failure(string $tool, \Throwable $e): void {
        /** Pro's audit log listens here; Free just fires the hook. */
        \do_action('pageforge_mcp_tool_failed', $tool, [
            'class' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }

    /**
     * The complete tool catalog for this grant, before scope filtering.
     * Used by tools/call so an out-of-scope call can produce a precise
     * insufficient_scope challenge instead of a misleading "unknown tool".
     */
    private static function all_tools(array $auth): array {
        $tools = Mcp_Registry::tools();
        /** Pro adds its write tools here. Free never registers one. */
        $tools = \apply_filters('pageforge_mcp_available_tools', $tools, $auth);
        if (!is_array($tools)) {
            return [];
        }

        // Structural guarantee: a filter cannot introduce a write tool on a
        // site that may not grant write scopes. This is what keeps Free
        // read-only even if a third-party plugin hooks the filter.
        $grantable = Mcp_Scopes::grantable();
        return array_filter($tools, static function ($tool) use ($grantable): bool {
            if (!is_array($tool)) {
                return false;
            }
            $scope = (string) ($tool['scope'] ?? '');
            return '' !== $scope && in_array($scope, $grantable, true);
        });
    }

    private static function available_tools(array $auth): array {
        $granted = array_values((array) ($auth['scopes'] ?? []));
        return array_filter(self::all_tools($auth), static function (array $tool) use ($granted): bool {
            return Mcp_Scopes::satisfies($granted, (string) ($tool['scope'] ?? ''));
        });
    }

    // ------------------------------------------------------ authentication --

    /**
     * Resolve the bearer token into a grant.
     *
     * @return array{
     *   type:string, user_id:int, scopes:string[], client:string,
     *   credential_id:string, subject:string
     * }|\WP_Error
     */
    private static function authenticate(\WP_REST_Request $request) {
        $header = trim((string) $request->get_header('Authorization'));
        $api_key = trim((string) $request->get_header('X-PageForge-MCP-API-Key'));
        $ip = Request::client_ip();

        if ('' !== $header && '' !== $api_key) {
            return new \WP_Error('invalid_request', __('Use either Authorization Bearer or X-PageForge-MCP-API-Key, not both.', 'pageforge'));
        }
        if ('' === $header && '' === $api_key) {
            // No credential at all: this is the discovery trigger, not a
            // failure worth rate limiting.
            return new \WP_Error('missing_token', __('Authorization required.', 'pageforge'));
        }
        if ('' !== $header && 0 !== stripos($header, 'Bearer ')) {
            return new \WP_Error('invalid_request', __('Authorization must use the Bearer scheme.', 'pageforge'));
        }

        // Failed attempts are metered before any storage lookup, so a brute
        // force cannot turn into an expensive WordPress workload.
        if (!Mcp_Rate_Limit::allow('auth_fail', $ip)) {
            return new \WP_Error('rate_limited', __('Too many failed authentication attempts.', 'pageforge'));
        }

        $token = '' !== $api_key ? $api_key : trim(substr($header, 7));
        if ('' === $token || strlen($token) > 512) {
            return new \WP_Error('invalid_token', __('Malformed authentication token.', 'pageforge'));
        }

        // --- OAuth access token.
        $oauth = '' === $api_key ? Oauth_Tokens::lookup_access_token($token) : null;
        if (is_array($oauth)) {
            // Audience binding (RFC 8707 / spec s9.2): a token minted for a
            // different resource must not be accepted here, even though it is
            // cryptographically valid. This is the confused-deputy defence.
            $audience = (string) ($oauth['resource'] ?? '');
            if ('' !== $audience && !Oauth::resource_matches($audience)) {
                return new \WP_Error('invalid_token', __('Access token was not issued for this MCP server.', 'pageforge'));
            }

            $user_id = (int) ($oauth['user_id'] ?? 0);
            // The grant follows the user. If the account lost the right to
            // connect AI clients, the token stops working immediately rather
            // than lasting until expiry.
            if ($user_id <= 0 || !Oauth::user_may_authorize($user_id)) {
                return new \WP_Error('invalid_token', __('The account behind this token can no longer authorize AI clients.', 'pageforge'));
            }

            Mcp_Rate_Limit::forget('auth_fail', $ip);
            return [
                'type' => 'oauth',
                'user_id' => $user_id,
                'scopes' => Mcp_Scopes::filter_grantable((array) ($oauth['scopes'] ?? [])),
                'client' => (string) ($oauth['client_id'] ?? ''),
                'credential_id' => '',
                'subject' => 'oauth:' . substr(hash('sha256', (string) ($oauth['client_id'] ?? '') . '|' . $user_id), 0, 24),
            ];
        }

        // --- Personal Access Token.
        $credential = Mcp_Credentials::verify($token);
        if (is_array($credential)) {
            $user_id = (int) ($credential['user_id'] ?? 0);
            if ($user_id > 0 && !Oauth::user_may_authorize($user_id)) {
                return new \WP_Error('invalid_token', __('The account behind this token can no longer authorize AI clients.', 'pageforge'));
            }

            Mcp_Credentials::touch((string) $credential['id'], Request::client_ip_for_audit());
            Mcp_Rate_Limit::forget('auth_fail', $ip);
            return [
                'type' => '' !== $api_key ? 'api_key' : 'pat',
                'user_id' => $user_id,
                'scopes' => Mcp_Scopes::filter_grantable((array) ($credential['scopes'] ?? [])),
                'client' => (string) ($credential['client'] ?? ''),
                'credential_id' => (string) ($credential['id'] ?? ''),
                'subject' => 'pat:' . (string) ($credential['id'] ?? ''),
            ];
        }

        return new \WP_Error('invalid_token', __('The bearer token is not valid.', 'pageforge'));
    }

    // --------------------------------------------------------- challenges --

    /**
     * RFC 6750 401 challenge.
     *
     * `resource_metadata` is the whole point: it is how a remote client such as
     * ChatGPT or Claude discovers that this endpoint speaks OAuth and where its
     * authorization server lives. A bare `Bearer realm="..."` gives a client
     * nothing to act on, and is why an OAuth connection cannot start.
     */
    private static function auth_challenge(\WP_Error $error, $id): \WP_REST_Response {
        $code = (string) $error->get_error_code();
        $status = 'rate_limited' === $code ? 429 : 401;

        $params = [
            'error' => 'rate_limited' === $code ? 'invalid_token' : ('missing_token' === $code ? 'invalid_request' : 'invalid_token'),
            'error_description' => (string) $error->get_error_message(),
            'resource_metadata' => Oauth::protected_resource_metadata_url(),
        ];
        // Tell the client which scopes to ask for, per the spec's scope
        // selection strategy.
        $advertised = Mcp_Scopes::advertised();
        if (!empty($advertised)) {
            $params['scope'] = Mcp_Scopes::to_string($advertised);
        }

        $headers = ['WWW-Authenticate' => self::challenge_header($params)];
        if (429 === $status) {
            $headers['Retry-After'] = (string) Mcp_Rate_Limit::retry_after('auth_fail');
        }

        // A credential-free request is the normal OAuth discovery trigger.
        // Return its mandatory challenge, but do not present it to the admin as
        // a rejected request. Invalid credentials and rate limits remain logged.
        return self::error_response(
            $id,
            -32001,
            (string) $error->get_error_message(),
            $status,
            $headers,
            [],
            'missing_token' !== $code
        );
    }

    /**
     * RFC 6750 s3.1 insufficient_scope: 403, naming every scope the operation
     * needs in one challenge so the client does not have to step up repeatedly.
     */
    private static function insufficient_scope($id, string $required): \WP_REST_Response {
        $header = self::challenge_header([
            'error' => 'insufficient_scope',
            'error_description' => sprintf(
                /* translators: %s: OAuth scope name. */
                __('This operation requires the %s scope.', 'pageforge'),
                $required
            ),
            'scope' => $required,
            'resource_metadata' => Oauth::protected_resource_metadata_url(),
        ]);

        return self::error_response($id, -32002, sprintf(
            /* translators: %s: OAuth scope name. */
            __('Insufficient scope. This operation requires: %s', 'pageforge'),
            $required
        ), 403, ['WWW-Authenticate' => $header]);
    }

    /** Builds `Bearer k="v", k2="v2"` with quoted-string escaping. */
    private static function challenge_header(array $params): string {
        $parts = [];
        foreach ($params as $key => $value) {
            $value = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
            $parts[] = $key . '="' . $value . '"';
        }
        return 'Bearer ' . implode(', ', $parts);
    }

    // ------------------------------------------------------------ helpers --

    private static function respond($id, $result, string $protocol = self::PROTOCOL_VERSION): \WP_REST_Response {
        $response = new \WP_REST_Response([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], 200);
        $response->header('MCP-Protocol-Version', $protocol);
        return self::secure_response($response);
    }

    /**
     * 202 Accepted for a notification. The spec requires no body, so this sends
     * a genuinely empty one: returning `null` as data makes WordPress serialise
     * the four characters "null", which a strict client reads as an unexpected
     * payload on a response that is defined to have none.
     */
    private static function no_content_response(int $status): \WP_REST_Response {
        $response = new \WP_REST_Response(null, $status);
        $response->header('MCP-Protocol-Version', self::PROTOCOL_VERSION);
        self::secure_response($response);
        // Suppresses the JSON body entirely for this response.
        \add_filter('rest_pre_serve_request', [self::class, 'serve_empty_body'], 10, 4);
        return $response;
    }

    /**
     * @internal Emits an empty body for 202 notification acknowledgements.
     */
    public static function serve_empty_body($served, $result, $request, $server) {
        if ($result instanceof \WP_REST_Response && 202 === (int) $result->get_status()) {
            \remove_filter('rest_pre_serve_request', [self::class, 'serve_empty_body'], 10);
            return true; // Already "served": nothing is written.
        }
        return $served;
    }

    /**
     * @param array $data Extra members merged into the JSON-RPC error object,
     *                    such as the `supported` version list an unsupported
     *                    protocol version must carry.
     */
    private static function error_response($id, int $code, string $message, int $http_status, array $headers = [], array $data = [], bool $record_failure = true): \WP_REST_Response {
        $error = ['code' => $code, 'message' => $message];
        if (!empty($data)) {
            $error['data'] = $data;
        }
        $response = new \WP_REST_Response([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ], $http_status);
        $response->header('MCP-Protocol-Version', self::PROTOCOL_VERSION);
        foreach ($headers as $key => $value) {
            $response->header($key, $value);
        }

        if ($record_failure) {
            self::record_failure($code, $message, $http_status);
        }
        return self::secure_response($response);
    }

    private static function secure_response(\WP_REST_Response $response): \WP_REST_Response {
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        $response->header('Vary', 'Origin, Authorization, X-PageForge-MCP-API-Key');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    /**
     * Keep a short, bounded record of rejected MCP requests.
     *
     * When a connector reports nothing more useful than "the server returned an
     * error", the operator has no way to tell an auth problem from a protocol
     * problem from a blocked route. This is the smallest thing that turns that
     * into a diagnosable event, and it is surfaced in Connections →
     * Connection diagnostics.
     *
     * Only classification data is stored: status, JSON-RPC code, the reason, the
     * declared protocol version and client name, and a network-prefix IP. Never
     * a token, a header value, or tool arguments.
     */
    private static function record_failure(int $code, string $message, int $http_status): void {
        if ($http_status < 400) {
            return;
        }

        $log = \get_transient(self::FAILURE_LOG_KEY);
        $log = is_array($log) ? $log : [];

        $log[] = [
            'time' => time(),
            'http_status' => $http_status,
            'code' => $code,
            'reason' => $message,
            'protocol' => substr((string) ($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? ''), 0, 20),
            'method' => substr((string) ($_SERVER['HTTP_MCP_METHOD'] ?? ''), 0, 40),
            'http_method' => substr((string) ($_SERVER['REQUEST_METHOD'] ?? ''), 0, 10),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
            'ip' => Request::client_ip_for_audit(),
        ];

        // Bounded, and self-expiring, so this can never become a growing store.
        $log = array_slice($log, -self::FAILURE_LOG_MAX);
        \set_transient(self::FAILURE_LOG_KEY, $log, DAY_IN_SECONDS);
    }

    /** @return array<int, array> Most recent first. */
    public static function recent_failures(int $limit = 10): array {
        $log = \get_transient(self::FAILURE_LOG_KEY);
        $log = is_array($log) ? $log : [];
        return array_slice(array_reverse($log), 0, max(1, min(self::FAILURE_LOG_MAX, $limit)));
    }

    public static function clear_failures(): void {
        \delete_transient(self::FAILURE_LOG_KEY);
    }
}
