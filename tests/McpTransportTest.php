<?php

use PageForge\Mcp_Scopes;
use PageForge\Mcp_Server;
use PageForge\Tests\TestCase;

/**
 * Streamable HTTP transport conformance.
 *
 * These cover the rules a remote connector actually exercises immediately after
 * a successful OAuth handshake. Getting any of them wrong produces the same
 * unhelpful symptom on the client side: "your account was authorized, but the
 * server returned an error when connecting".
 */

function pageforge_transport_setup(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_settings']['mcp_enabled'] = true;
    $GLOBALS['pageforge_test_caps'] = [
        7 => ['read' => true, 'edit_posts' => true, 'manage_options' => true, 'read_post' => []],
    ];
    wp_set_current_user(7);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    unset($_SERVER['HTTP_MCP_PROTOCOL_VERSION'], $_SERVER['HTTP_MCP_METHOD'], $_SERVER['HTTP_USER_AGENT']);
}

function pageforge_transport_token(): string {
    $created = \PageForge\Mcp_Credentials::create('IDE', 'vscode', [Mcp_Scopes::READ, Mcp_Scopes::PREVIEW]);
    return (string) $created['token'];
}

function pageforge_transport_request(array $body, string $token = '', array $headers = []): WP_REST_Request {
    if ('' !== $token) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }
    return new WP_REST_Request($headers, (string) \wp_json_encode($body));
}

/**
 * THE CLAUDE REGRESSION.
 *
 * Revision 2026-07-28 removed the GET stream endpoint, and a server
 * implementing it MUST answer GET with 405. Returning 200 and a JSON body made a
 * client that had asked for `text/event-stream` treat the connection as broken
 * the moment OAuth completed.
 */
function test_pageforge_mcp_authenticated_get_returns_405_not_a_json_body(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $response = Mcp_Server::handle_request(new WP_REST_Request(['Authorization' => 'Bearer ' . $token], ''));
    $_SERVER['REQUEST_METHOD'] = 'POST';

    TestCase::same(405, $response->status);
    TestCase::same('POST', $response->get_headers()['Allow']);
}

/**
 * An unauthenticated GET must still yield the discovery challenge, or a client
 * with no token has nowhere to go.
 */
function test_pageforge_mcp_unauthenticated_get_still_yields_the_oauth_challenge(): void {
    pageforge_transport_setup();
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $response = Mcp_Server::handle_request(new WP_REST_Request([], ''));
    $_SERVER['REQUEST_METHOD'] = 'POST';

    TestCase::same(401, $response->status);
    TestCase::true(false !== strpos((string) $response->get_headers()['WWW-Authenticate'], 'resource_metadata="'));
}

/** Discovery POSTs are challenged before PageForge attempts JSON-RPC parsing. */
function test_pageforge_mcp_unauthenticated_empty_post_yields_oauth_challenge_not_malformed_json(): void {
    pageforge_transport_setup();
    Mcp_Server::clear_failures();

    $response = Mcp_Server::handle_request(new WP_REST_Request([], ''));

    TestCase::same(401, $response->status);
    TestCase::same('Authorization required.', $response->data['error']['message']);
    TestCase::true(false !== strpos((string) $response->get_headers()['WWW-Authenticate'], 'resource_metadata="'));
    TestCase::same([], Mcp_Server::recent_failures(1));
}

/** Once authenticated, malformed JSON remains a strict protocol error. */
function test_pageforge_mcp_authenticated_malformed_json_is_rejected_with_parse_detail(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();

    $response = Mcp_Server::handle_request(new WP_REST_Request(
        ['Authorization' => 'Bearer ' . $token],
        '{"jsonrpc":"2.0",'
    ));

    TestCase::same(400, $response->status);
    TestCase::same(-32700, $response->data['error']['code']);
    TestCase::true(false !== strpos($response->data['error']['message'], 'Malformed JSON:'));
}

function test_pageforge_mcp_delete_returns_405_because_there_are_no_sessions(): void {
    pageforge_transport_setup();
    $_SERVER['REQUEST_METHOD'] = 'DELETE';

    $response = Mcp_Server::handle_request(new WP_REST_Request([], ''));
    $_SERVER['REQUEST_METHOD'] = 'POST';

    TestCase::same(405, $response->status);
}

/**
 * DNS rebinding protection. A missing Origin is a normal server-to-server agent
 * call; a foreign Origin only ever comes from a browser on another site.
 */
function test_pageforge_mcp_rejects_a_foreign_origin(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();

    $response = Mcp_Server::handle_request(pageforge_transport_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        $token,
        ['Origin' => 'https://attacker.example']
    ));

    TestCase::same(403, $response->status);
}

function test_pageforge_mcp_accepts_its_own_origin(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();

    $response = Mcp_Server::handle_request(pageforge_transport_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        $token,
        ['Origin' => 'https://example.test']
    ));

    TestCase::same(200, $response->status);
}

function test_pageforge_mcp_allows_a_request_with_no_origin_header(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();

    $response = Mcp_Server::handle_request(pageforge_transport_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        $token
    ));

    TestCase::same(200, $response->status);
}

function test_pageforge_mcp_accepts_known_remote_connector_origins(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();

    foreach (['https://chatgpt.com', 'https://claude.ai'] as $origin) {
        $response = Mcp_Server::handle_request(pageforge_transport_request(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            $token,
            ['Origin' => $origin]
        ));
        TestCase::same(200, $response->status, 'Connector origin rejected: ' . $origin);
    }
}

function test_pageforge_mcp_accepts_a_pat_as_the_named_api_key_header(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();
    $response = Mcp_Server::handle_request(pageforge_transport_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover'],
        '',
        ['X-PageForge-MCP-API-Key' => $token]
    ));

    TestCase::same(200, $response->status);
    TestCase::same('api_key', $response->data['result']['authenticatedAs']['authentication']);
}

function test_pageforge_mcp_rejects_ambiguous_dual_authentication(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();
    $response = Mcp_Server::handle_request(pageforge_transport_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        $token,
        ['X-PageForge-MCP-API-Key' => $token]
    ));

    TestCase::same(401, $response->status);
}

function test_pageforge_mcp_responses_are_private_and_uncacheable(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();
    $response = Mcp_Server::handle_request(pageforge_transport_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        $token
    ));
    $headers = $response->get_headers();

    TestCase::true(false !== strpos($headers['Cache-Control'], 'no-store'));
    TestCase::same('no-cache', $headers['Pragma']);
    TestCase::true(false !== strpos($headers['Vary'], 'Authorization'));
}

function test_pageforge_mcp_cors_headers_include_transport_and_api_key_headers(): void {
    $allowed = Mcp_Server::allowed_cors_headers(['Authorization']);
    $exposed = Mcp_Server::exposed_cors_headers([]);

    TestCase::true(in_array('X-PageForge-MCP-API-Key', $allowed, true));
    TestCase::true(in_array('Mcp-Method', $allowed, true));
    TestCase::true(in_array('MCP-Protocol-Version', $exposed, true));
    TestCase::true(in_array('WWW-Authenticate', $exposed, true));
}

/**
 * An unimplemented protocol version must be a 400 naming what we support, so the
 * client can retry on common ground. Silently answering in our own version left
 * it believing its version had been accepted.
 */
function test_pageforge_mcp_unsupported_protocol_version_is_rejected_with_the_supported_list(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();

    $response = Mcp_Server::handle_request(pageforge_transport_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        $token,
        ['MCP-Protocol-Version' => '1999-01-01']
    ));

    TestCase::same(400, $response->status);
    TestCase::same(Mcp_Server::ERROR_UNSUPPORTED_PROTOCOL, $response->data['error']['code']);
    TestCase::same(-32022, $response->data['error']['code']);
    TestCase::true(in_array(Mcp_Server::PROTOCOL_VERSION, $response->data['error']['data']['supported'], true));
    TestCase::same('1999-01-01', $response->data['error']['data']['requested']);
}

function test_pageforge_mcp_accepts_every_advertised_protocol_version(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();

    foreach (Mcp_Server::supported_protocol_versions() as $version) {
        $response = Mcp_Server::handle_request(pageforge_transport_request(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            $token,
            ['MCP-Protocol-Version' => $version]
        ));
        TestCase::same(200, $response->status, 'Protocol version rejected: ' . $version);
    }
}

/** A request with no version at all is a pre-header client, not an error. */
function test_pageforge_mcp_request_without_a_protocol_version_is_treated_as_the_oldest(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();

    $response = Mcp_Server::handle_request(pageforge_transport_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'],
        $token
    ));

    TestCase::same(200, $response->status);
    TestCase::same(Mcp_Server::OLDEST_PROTOCOL_VERSION, $response->data['result']['protocolVersion']);
}

/**
 * The spec reserves -32020 for header/body disagreement. A generic -32600 would
 * leave a modern client unable to distinguish it from a malformed request, and
 * it might then wrongly fall back to a legacy handshake.
 */
function test_pageforge_mcp_header_body_mismatch_uses_the_reserved_error_code(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();

    $response = Mcp_Server::handle_request(pageforge_transport_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        $token,
        ['Mcp-Method' => 'tools/call']
    ));

    TestCase::same(400, $response->status);
    TestCase::same(Mcp_Server::ERROR_HEADER_MISMATCH, $response->data['error']['code']);
}

function test_pageforge_mcp_protocol_version_header_must_match_meta(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();

    $response = Mcp_Server::handle_request(pageforge_transport_request(
        [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-06-18']],
        ],
        $token,
        ['MCP-Protocol-Version' => Mcp_Server::PROTOCOL_VERSION]
    ));

    TestCase::same(400, $response->status);
    TestCase::same(Mcp_Server::ERROR_HEADER_MISMATCH, $response->data['error']['code']);
}

/**
 * Mcp-Name may arrive Base64 sentinel-encoded. Servers MUST decode before
 * comparing, or a legitimately encoded tool name reads as a mismatch and the
 * call is refused.
 */
function test_pageforge_mcp_decodes_base64_sentinel_mcp_name_before_comparing(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();
    $encoded = '=?base64?' . base64_encode('pageforge.site_status') . '?=';

    $response = Mcp_Server::handle_request(pageforge_transport_request(
        [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'pageforge.site_status', 'arguments' => (object) []],
        ],
        $token,
        ['Mcp-Method' => 'tools/call', 'Mcp-Name' => $encoded]
    ));

    TestCase::same(200, $response->status);
}

function test_pageforge_mcp_rejects_a_malformed_base64_sentinel_mcp_name(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();

    $response = Mcp_Server::handle_request(pageforge_transport_request(
        [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'pageforge.site_status', 'arguments' => (object) []],
        ],
        $token,
        ['Mcp-Method' => 'tools/call', 'Mcp-Name' => '=?base64?!!!not base64!!!?=']
    ));

    TestCase::same(400, $response->status);
    TestCase::same(Mcp_Server::ERROR_HEADER_MISMATCH, $response->data['error']['code']);
}

/** Unknown method is a 404 with -32601, which is how a client tells a modern
 * server apart from a legacy one that simply does not host this endpoint. */
function test_pageforge_mcp_unknown_method_is_404_with_method_not_found(): void {
    pageforge_transport_setup();
    $token = pageforge_transport_token();

    $response = Mcp_Server::handle_request(pageforge_transport_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list'],
        $token
    ));

    TestCase::same(404, $response->status);
    TestCase::same(-32601, $response->data['error']['code']);
}

// ------------------------------------------------------- failure logging --

/**
 * A rejected request must leave a diagnosable trace, because a connector
 * reports nothing more useful than "the server returned an error".
 */
function test_pageforge_mcp_rejected_requests_are_recorded_for_diagnostics(): void {
    pageforge_transport_setup();
    Mcp_Server::clear_failures();

    Mcp_Server::handle_request(pageforge_transport_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        'pfmcp_mcp_invalid_' . str_repeat('x', 43)
    ));

    $failures = Mcp_Server::recent_failures(5);
    TestCase::true(count($failures) > 0);
    TestCase::same(401, $failures[0]['http_status']);
    TestCase::same('The bearer token is not valid.', $failures[0]['reason']);
}

/** The failure log must never capture a credential. */
function test_pageforge_mcp_failure_log_never_stores_the_presented_token(): void {
    pageforge_transport_setup();
    Mcp_Server::clear_failures();
    $secret = 'pfmcp_mcp_aaaaaaaaaaaa_' . str_repeat('z', 43);

    Mcp_Server::handle_request(pageforge_transport_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        $secret
    ));

    $serialized = (string) \wp_json_encode(Mcp_Server::recent_failures(5));
    TestCase::same(false, false !== strpos($serialized, $secret));
    TestCase::same(false, false !== strpos($serialized, 'zzzz'));
}

/** The log is bounded, so it cannot grow without limit under a scan. */
function test_pageforge_mcp_failure_log_is_bounded(): void {
    pageforge_transport_setup();
    Mcp_Server::clear_failures();

    for ($i = 0; $i < 40; $i++) {
        Mcp_Server::handle_request(pageforge_transport_request(
            ['jsonrpc' => '2.0', 'id' => $i, 'method' => 'tools/list']
        ));
    }

    TestCase::true(count(Mcp_Server::recent_failures(100)) <= 25);
}
