<?php

use PageForge\Mcp_Credentials;
use PageForge\Mcp_Scopes;
use PageForge\Mcp_Server;
use PageForge\Tests\TestCase;

function pageforge_mcp_test_request(array $body, string $token = '', array $extra_headers = []): WP_REST_Request {
    $headers = $extra_headers;
    if ('' !== $token) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    return new WP_REST_Request($headers, (string) \wp_json_encode($body));
}

function pageforge_mcp_test_enable(): void {
    $GLOBALS['pageforge_test_settings']['mcp_enabled'] = true;
    $GLOBALS['pageforge_test_caps'] = [
        7 => ['read' => true, 'edit_posts' => true, 'manage_options' => true, 'read_post' => []],
    ];
    wp_set_current_user(7);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
}

/** A read+preview PAT belonging to the administrator set up above. */
function pageforge_mcp_test_token(array $scopes = [Mcp_Scopes::READ, Mcp_Scopes::PREVIEW]): string {
    $created = Mcp_Credentials::create('IDE', 'vscode', $scopes);
    return (string) $created['token'];
}

function pageforge_mcp_test_challenge(WP_REST_Response $response): string {
    foreach ($response->get_headers() as $key => $value) {
        if (0 === strcasecmp((string) $key, 'WWW-Authenticate')) {
            return (string) $value;
        }
    }
    return '';
}

function test_pageforge_mcp_disabled_by_default_rejects_every_call(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_caps'] = [7 => ['read' => true, 'edit_posts' => true, 'manage_options' => true]];
    wp_set_current_user(7);
    $token = pageforge_mcp_test_token();

    $response = Mcp_Server::handle_request(pageforge_mcp_test_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        $token
    ));

    TestCase::same(403, $response->status);
    TestCase::same(-32003, $response->data['error']['code']);
}

/**
 * THE ROOT-CAUSE REGRESSION TEST.
 *
 * A remote connector (ChatGPT, Claude) discovers OAuth solely from the
 * WWW-Authenticate challenge on an unauthenticated call. A bare
 * `Bearer realm="..."` gives it nothing to act on, which is why the OAuth
 * connection could never start. The challenge MUST carry resource_metadata.
 */
function test_pageforge_mcp_401_challenge_advertises_protected_resource_metadata(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();

    $response = Mcp_Server::handle_request(pageforge_mcp_test_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']
    ));

    TestCase::same(401, $response->status);
    $challenge = pageforge_mcp_test_challenge($response);

    TestCase::true(0 === strpos($challenge, 'Bearer '));
    TestCase::true(false !== strpos($challenge, 'resource_metadata="'));
    TestCase::true(false !== strpos($challenge, \PageForge\Oauth::protected_resource_metadata_url()));
    // Scope guidance, so the client requests least privilege rather than guessing.
    TestCase::true(false !== strpos($challenge, 'scope="'));
    TestCase::true(false !== strpos($challenge, Mcp_Scopes::READ));
}

/** An unauthenticated GET probe must also produce the discovery challenge. */
function test_pageforge_mcp_unauthenticated_get_probe_returns_the_challenge(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $response = Mcp_Server::handle_request(new WP_REST_Request([], ''));
    $_SERVER['REQUEST_METHOD'] = 'POST';

    TestCase::same(401, $response->status);
    TestCase::true(false !== strpos(pageforge_mcp_test_challenge($response), 'resource_metadata="'));
}

function test_pageforge_mcp_rejects_revoked_credential(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $created = Mcp_Credentials::create('IDE', 'vscode', [Mcp_Scopes::READ]);
    Mcp_Credentials::revoke($created['id']);

    $response = Mcp_Server::handle_request(pageforge_mcp_test_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        $created['token']
    ));

    TestCase::same(401, $response->status);
}

/**
 * A legacy version we genuinely support is echoed back rather than being
 * answered with 2026-07-28. Claiming the new protocol to an old client is how a
 * "compatible" server ends up ambiguous.
 */
function test_pageforge_mcp_legacy_initialize_echoes_a_version_it_can_speak(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $token = pageforge_mcp_test_token();

    $legacy = Mcp_Server::handle_request(pageforge_mcp_test_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']],
        $token
    ));

    TestCase::same(200, $legacy->status);
    TestCase::same('2025-06-18', $legacy->data['result']['protocolVersion']);
    TestCase::same('pageforge', $legacy->data['result']['serverInfo']['name']);
}

/**
 * An unimplemented version is refused with the supported list rather than
 * silently answered in our own revision, which would have left the client
 * believing its version had been accepted.
 */
function test_pageforge_mcp_initialize_with_an_unknown_version_is_refused(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $token = pageforge_mcp_test_token();

    $unknown = Mcp_Server::handle_request(pageforge_mcp_test_request(
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']],
        $token
    ));

    TestCase::same(400, $unknown->status);
    TestCase::same(Mcp_Server::ERROR_UNSUPPORTED_PROTOCOL, $unknown->data['error']['code']);
    TestCase::true(in_array(Mcp_Server::PROTOCOL_VERSION, $unknown->data['error']['data']['supported'], true));
}

function test_pageforge_mcp_notification_gets_no_body_and_is_not_authorization_bypass(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $token = pageforge_mcp_test_token();

    // An authenticated notification (no "id") is acknowledged with no body,
    // and remains stateless.
    $response = Mcp_Server::handle_request(pageforge_mcp_test_request(
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        $token
    ));

    TestCase::same(202, $response->status);
    TestCase::same(null, $response->data);
}

/**
 * 2026-07-28 adds header-based routing. If a client states the method or tool
 * in headers, it must agree with the body: resolving a disagreement by
 * preferring one side creates a surface where a proxy authorises one operation
 * while the server executes another.
 */
function test_pageforge_mcp_rejects_contradictory_header_and_body_method(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $token = pageforge_mcp_test_token();

    $response = Mcp_Server::handle_request(pageforge_mcp_test_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        $token,
        ['Mcp-Method' => 'tools/call']
    ));

    TestCase::same(400, $response->status);
    // The spec reserves this code for header/body disagreement; a generic
    // -32600 would be indistinguishable from a malformed request.
    TestCase::same(Mcp_Server::ERROR_HEADER_MISMATCH, $response->data['error']['code']);
}

function test_pageforge_mcp_rejects_contradictory_header_and_body_tool_name(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $token = pageforge_mcp_test_token();

    $response = Mcp_Server::handle_request(pageforge_mcp_test_request(
        [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'pageforge.site_status', 'arguments' => (object) []],
        ],
        $token,
        ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'pageforge.pages_list']
    ));

    TestCase::same(400, $response->status);
}

function test_pageforge_mcp_server_discover_is_stateless_and_repeatable(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $token = pageforge_mcp_test_token();

    $first = Mcp_Server::handle_request(pageforge_mcp_test_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover'],
        $token
    ));
    $second = Mcp_Server::handle_request(pageforge_mcp_test_request(
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'server/discover'],
        $token
    ));

    TestCase::same($first->data['result']['protocolVersions'], $second->data['result']['protocolVersions']);
    TestCase::true(in_array(Mcp_Server::PROTOCOL_VERSION, $first->data['result']['protocolVersions'], true));
}

/** There is no session to tear down in the stateless core. */
function test_pageforge_mcp_delete_is_refused_because_there_is_no_session(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $_SERVER['REQUEST_METHOD'] = 'DELETE';

    $response = Mcp_Server::handle_request(new WP_REST_Request([], ''));
    $_SERVER['REQUEST_METHOD'] = 'POST';

    TestCase::same(405, $response->status);
}

function test_pageforge_mcp_tools_list_only_exposes_read_scope_tools_on_free(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $token = pageforge_mcp_test_token();

    $response = Mcp_Server::handle_request(pageforge_mcp_test_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        $token
    ));

    $names = array_column($response->data['result']['tools'], 'name');
    TestCase::true(in_array('pageforge.site_status', $names, true));
    TestCase::true(in_array('pageforge.generation_preview', $names, true));
    foreach ($response->data['result']['tools'] as $tool) {
        TestCase::same(true, $tool['readOnly']);
        TestCase::same(true, $tool['annotations']['readOnlyHint']);
        TestCase::same(false, $tool['annotations']['destructiveHint']);
    }
}

/** A read-only PAT must not see preview tools it cannot call. */
function test_pageforge_mcp_tools_list_is_filtered_to_the_granted_scopes(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $token = pageforge_mcp_test_token([Mcp_Scopes::READ]);

    $response = Mcp_Server::handle_request(pageforge_mcp_test_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        $token
    ));

    $names = array_column($response->data['result']['tools'], 'name');
    TestCase::true(in_array('pageforge.site_status', $names, true));
    TestCase::same(false, in_array('pageforge.generation_preview', $names, true));
}

function test_pageforge_mcp_tools_list_result_is_cacheable_and_private(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $token = pageforge_mcp_test_token();

    $response = Mcp_Server::handle_request(pageforge_mcp_test_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        $token
    ));

    TestCase::same('complete', $response->data['result']['resultType']);
    TestCase::same('private', $response->data['result']['cacheScope']);
    TestCase::true($response->data['result']['ttlMs'] > 0);
}

function test_pageforge_mcp_tools_call_runs_a_read_tool(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $token = pageforge_mcp_test_token();

    $response = Mcp_Server::handle_request(pageforge_mcp_test_request([
        'jsonrpc' => '2.0',
        'id' => 5,
        'method' => 'tools/call',
        'params' => ['name' => 'pageforge.site_status', 'arguments' => (object) []],
    ], $token));

    TestCase::same(200, $response->status);
    TestCase::same(false, $response->data['result']['isError']);
    TestCase::true(isset($response->data['result']['structuredContent']['edition']));
}

function test_pageforge_mcp_tools_call_rejects_unknown_tool_name(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $token = pageforge_mcp_test_token();

    $response = Mcp_Server::handle_request(pageforge_mcp_test_request([
        'jsonrpc' => '2.0',
        'id' => 6,
        'method' => 'tools/call',
        'params' => ['name' => 'pageforge.delete_everything', 'arguments' => (object) []],
    ], $token));

    TestCase::same(404, $response->status);
    TestCase::same(-32602, $response->data['error']['code']);
}

/**
 * Calling a tool that exists but is outside the grant must produce a 403
 * insufficient_scope challenge naming the scope needed, so the client can step
 * up. Answering "unknown tool" would leave a client with no path forward.
 */
function test_pageforge_mcp_out_of_scope_call_returns_insufficient_scope_challenge(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $token = pageforge_mcp_test_token([Mcp_Scopes::READ]);

    $response = Mcp_Server::handle_request(pageforge_mcp_test_request([
        'jsonrpc' => '2.0',
        'id' => 7,
        'method' => 'tools/call',
        'params' => ['name' => 'pageforge.generation_preview', 'arguments' => ['template_id' => 1, 'row' => []]],
    ], $token));

    TestCase::same(403, $response->status);
    $challenge = pageforge_mcp_test_challenge($response);
    TestCase::true(false !== strpos($challenge, 'error="insufficient_scope"'));
    TestCase::true(false !== strpos($challenge, 'scope="' . Mcp_Scopes::PREVIEW . '"'));
    TestCase::true(false !== strpos($challenge, 'resource_metadata="'));
}

/**
 * Free's read-only promise is structural. Even a third-party filter cannot
 * introduce a write tool, because a scope Free can never grant is dropped from
 * the catalog before scope filtering happens.
 */
function test_pageforge_mcp_free_never_exposes_a_write_tool_even_via_the_filter(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    add_filter('pageforge_mcp_available_tools', function ($tools) {
        $tools['pageforge.create_campaign'] = [
            'title' => 'Create campaign',
            'description' => 'Write tool',
            'scope' => Mcp_Scopes::WRITE,
            'readOnly' => false,
            'inputSchema' => ['type' => 'object'],
            'handler' => function () { return ['created' => true]; },
        ];
        return $tools;
    });
    $token = pageforge_mcp_test_token();

    $listed = Mcp_Server::handle_request(pageforge_mcp_test_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        $token
    ));
    TestCase::same(false, in_array('pageforge.create_campaign', array_column($listed->data['result']['tools'], 'name'), true));

    $called = Mcp_Server::handle_request(pageforge_mcp_test_request([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'pageforge.create_campaign', 'arguments' => (object) []],
    ], $token));

    // Dropped entirely, so it is genuinely unknown here rather than merely
    // out of scope.
    TestCase::same(404, $called->status);
}

function test_pageforge_mcp_invalid_json_rpc_body_is_rejected(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $token = pageforge_mcp_test_token();

    $response = Mcp_Server::handle_request(new WP_REST_Request(
        ['Authorization' => 'Bearer ' . $token],
        '{"not":"jsonrpc"}'
    ));

    TestCase::same(400, $response->status);
    TestCase::same(-32600, $response->data['error']['code']);
}

function test_pageforge_mcp_malformed_json_is_rejected_after_authentication(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $token = pageforge_mcp_test_token();

    $response = Mcp_Server::handle_request(new WP_REST_Request(
        ['Authorization' => 'Bearer ' . $token],
        '{not json'
    ));

    TestCase::same(400, $response->status);
    TestCase::same(-32700, $response->data['error']['code']);
}

/**
 * Every response states the revision the exchange actually used, which is the
 * negotiated version rather than unconditionally our own. A request that names
 * no version is a pre-header client and is answered as the oldest revision.
 */
function test_pageforge_mcp_responses_echo_the_negotiated_protocol_version(): void {
    pageforge_test_reset_state();
    pageforge_mcp_test_enable();
    $token = pageforge_mcp_test_token();

    $modern = Mcp_Server::handle_request(pageforge_mcp_test_request(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
        $token,
        ['MCP-Protocol-Version' => Mcp_Server::PROTOCOL_VERSION]
    ));
    TestCase::same(Mcp_Server::PROTOCOL_VERSION, $modern->get_headers()['MCP-Protocol-Version']);

    $unversioned = Mcp_Server::handle_request(pageforge_mcp_test_request(
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
        $token
    ));
    TestCase::same(Mcp_Server::OLDEST_PROTOCOL_VERSION, $unversioned->get_headers()['MCP-Protocol-Version']);
}
