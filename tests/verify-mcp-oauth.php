<?php
/**
 * End-to-end MCP OAuth connector simulation.
 *
 * Replays the exact sequence a remote MCP client (ChatGPT custom connector,
 * Claude remote custom connector) performs, per MCP 2026-07-28
 * `basic/authorization` and `authorization-server-discovery`, and prints what
 * PageForge answers at each step.
 *
 * This is the same script that reproduced the original failure. It is kept so
 * the discovery chain can be re-verified after any change to the OAuth or MCP
 * code, and so its output can be pasted into a bug report.
 *
 * Run: php tests/verify-mcp-oauth.php
 */

require_once __DIR__ . '/bootstrap.php';

use PageForge\Mcp_Scopes;
use PageForge\Mcp_Server;
use PageForge\Oauth;
use PageForge\Oauth_Clients;
use PageForge\Oauth_Tokens;

$fail = 0;
$step = 0;

function vstep(string $label): void {
    global $step;
    $step++;
    echo "\n--- STEP {$step}: {$label}\n";
}
function vok(string $msg): void {
    echo "    OK    {$msg}\n";
}
function vbad(string $msg): void {
    global $fail;
    $fail++;
    echo "    FAIL  {$msg}\n";
}
function vinfo(string $msg): void {
    echo "          {$msg}\n";
}

pageforge_test_reset_state();
$GLOBALS['pageforge_test_settings']['mcp_enabled'] = true;
$GLOBALS['pageforge_test_caps'] = [7 => ['read' => true, 'edit_posts' => true, 'manage_options' => true]];
wp_set_current_user(7);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['REQUEST_METHOD'] = 'POST';

// ============================================================== step 1 ====
vstep('Unauthenticated POST to the MCP endpoint (client probes for auth)');

$response = Mcp_Server::handle_request(new WP_REST_Request([], (string) json_encode([
    'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
])));

$challenge = '';
foreach ($response->get_headers() as $key => $value) {
    if (0 === strcasecmp((string) $key, 'WWW-Authenticate')) {
        $challenge = (string) $value;
    }
}

vinfo("HTTP status      : {$response->status}");
vinfo('WWW-Authenticate : ' . ($challenge !== '' ? $challenge : '(absent)'));

401 === $response->status ? vok('401 as the spec requires') : vbad("expected 401, got {$response->status}");

if (false !== strpos($challenge, 'resource_metadata="')) {
    vok('challenge advertises resource_metadata (this is what starts OAuth discovery)');
} else {
    vbad('challenge has no resource_metadata -> client cannot discover the authorization server');
}
false !== strpos($challenge, 'scope="') ? vok('challenge advertises required scopes') : vbad('challenge advertises no scope');

// ============================================================== step 2 ====
vstep('GET the Protected Resource Metadata document (RFC 9728)');

$prm = Oauth::rest_protected_resource_metadata()->data;
vinfo('resource               : ' . (string) ($prm['resource'] ?? ''));
vinfo('authorization_servers  : ' . implode(', ', (array) ($prm['authorization_servers'] ?? [])));
vinfo('scopes_supported       : ' . implode(' ', (array) ($prm['scopes_supported'] ?? [])));
vinfo('bearer_methods         : ' . implode(', ', (array) ($prm['bearer_methods_supported'] ?? [])));

!empty($prm['resource']) ? vok('resource identifier present') : vbad('resource missing');
!empty($prm['authorization_servers']) ? vok('authorization_servers present') : vbad('authorization_servers missing (MUST per RFC 9728)');
!empty($prm['scopes_supported']) ? vok('scopes_supported present') : vbad('scopes_supported missing');

// The URL named in the challenge must be the document we actually serve.
if (false !== strpos($challenge, Oauth::protected_resource_metadata_url())) {
    vok('challenge points at the document that is actually served');
} else {
    vbad('challenge points somewhere other than the served metadata URL');
}

// ============================================================== step 3 ====
vstep('GET the authorization server metadata (RFC 8414)');

$as = Oauth::rest_authorization_server_metadata()->data;
foreach ([
    'issuer', 'authorization_endpoint', 'token_endpoint', 'revocation_endpoint',
] as $field) {
    vinfo(str_pad($field, 23) . ': ' . (string) ($as[$field] ?? ''));
}
vinfo(str_pad('code_challenge_methods', 23) . ': ' . implode(', ', (array) ($as['code_challenge_methods_supported'] ?? [])));
vinfo(str_pad('grant_types', 23) . ': ' . implode(', ', (array) ($as['grant_types_supported'] ?? [])));
vinfo(str_pad('CIMD supported', 23) . ': ' . (!empty($as['client_id_metadata_document_supported']) ? 'true' : 'false'));
vinfo(str_pad('iss param supported', 23) . ': ' . (!empty($as['authorization_response_iss_parameter_supported']) ? 'true' : 'false'));
vinfo(str_pad('registration_endpoint', 23) . ': ' . (string) ($as['registration_endpoint'] ?? '(not advertised - DCR is deprecated)'));

foreach (['issuer', 'authorization_endpoint', 'token_endpoint'] as $required) {
    !empty($as[$required]) ? vok("{$required} present") : vbad("{$required} missing");
}
in_array('S256', (array) ($as['code_challenge_methods_supported'] ?? []), true)
    ? vok('PKCE S256 advertised')
    : vbad('PKCE S256 not advertised');
in_array('plain', (array) ($as['code_challenge_methods_supported'] ?? []), true)
    ? vbad('PKCE plain advertised (must not be)')
    : vok('PKCE plain correctly not advertised');
// RFC 8414 s3.3 issuer identity rule.
rtrim((string) ($as['issuer'] ?? ''), '/') === rtrim(Oauth::issuer(), '/')
    ? vok('issuer matches its own discovery identifier')
    : vbad('issuer does not match the discovery identifier (conforming clients will reject it)');

// ============================================================== step 4 ====
vstep('Register the client (pre-registration, as Claude\'s connector UI asks for)');

$client = Oauth_Clients::create_preregistered('Claude', ['https://claude.ai/api/mcp/auth_callback'], true);
vinfo('client_id     : ' . $client['client_id']);
vinfo('client_secret : ' . substr($client['client_secret'], 0, 12) . '... (shown once)');
vok('confidential client created');

$stored = json_encode(get_option('pageforge_oauth_clients', []));
false === strpos((string) $stored, $client['client_secret'])
    ? vok('client secret is not stored in the clear')
    : vbad('client secret was stored in the clear');

// ============================================================== step 5 ====
vstep('Authorization request validation (PKCE + redirect_uri + resource)');

$verifier = str_repeat('a', 43);
$challenge_s256 = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$resolved = Oauth_Clients::resolve($client['client_id']);

Oauth_Clients::redirect_uri_allowed($resolved, 'https://claude.ai/api/mcp/auth_callback')
    ? vok('registered redirect_uri accepted')
    : vbad('registered redirect_uri rejected');
Oauth_Clients::redirect_uri_allowed($resolved, 'https://attacker.example/cb')
    ? vbad('unregistered redirect_uri accepted (open redirector)')
    : vok('unregistered redirect_uri rejected');
Oauth::resource_matches(Oauth::resource_identifier())
    ? vok('own resource identifier accepted as audience')
    : vbad('own resource identifier rejected');
Oauth::resource_matches('https://attacker.example/mcp')
    ? vbad('foreign audience accepted (confused deputy)')
    : vok('foreign audience rejected');

// The consent screen would issue this after the WordPress user approves.
$code = 'pfac_' . str_repeat('b', 48);
Oauth_Tokens::store_code($code, [
    'client_id' => $client['client_id'],
    'user_id' => 7,
    'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
    'scopes' => [Mcp_Scopes::READ, Mcp_Scopes::PREVIEW],
    'resource' => Oauth::resource_identifier(),
    'code_challenge' => $challenge_s256,
    'code_challenge_method' => 'S256',
    'offline_access' => true,
]);
vok('authorization code issued, bound to client + user + redirect_uri + audience + PKCE');

// ============================================================== step 6 ====
vstep('POST the token endpoint: exchange the code');

$token_request = static function (array $params, array $headers = []): WP_REST_Request {
    return new WP_REST_Request($headers, http_build_query($params));
};

$exchange = Oauth::rest_token($token_request([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'code_verifier' => $verifier,
    'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
    'client_id' => $client['client_id'],
    'client_secret' => $client['client_secret'],
    'resource' => Oauth::resource_identifier(),
]));

vinfo("HTTP status   : {$exchange->status}");
vinfo('token_type    : ' . (string) ($exchange->data['token_type'] ?? ''));
vinfo('expires_in    : ' . (string) ($exchange->data['expires_in'] ?? ''));
vinfo('scope         : ' . (string) ($exchange->data['scope'] ?? ''));
vinfo('access_token  : ' . substr((string) ($exchange->data['access_token'] ?? ''), 0, 14) . '...');
vinfo('refresh_token : ' . substr((string) ($exchange->data['refresh_token'] ?? ''), 0, 14) . '...');
vinfo('Cache-Control : ' . (string) ($exchange->get_headers()['Cache-Control'] ?? ''));

200 === $exchange->status ? vok('code exchanged for an access token') : vbad("exchange failed with {$exchange->status}");
!empty($exchange->data['access_token']) ? vok('access token issued') : vbad('no access token');
!empty($exchange->data['refresh_token']) ? vok('refresh token issued (offline_access)') : vbad('no refresh token');
'no-store' === (string) ($exchange->get_headers()['Cache-Control'] ?? '')
    ? vok('token response marked no-store')
    : vbad('token response is cacheable');

$access_token = (string) ($exchange->data['access_token'] ?? '');

// ============================================================== step 7 ====
vstep('Replay the same authorization code (must fail)');

$replay = Oauth::rest_token($token_request([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'code_verifier' => $verifier,
    'client_id' => $client['client_id'],
    'client_secret' => $client['client_secret'],
]));
vinfo("HTTP status : {$replay->status}  error: " . (string) ($replay->data['error'] ?? ''));
400 === $replay->status ? vok('code is single-use') : vbad('authorization code could be replayed');

// ============================================================== step 8 ====
vstep('Call the MCP endpoint with the OAuth access token');

$listed = Mcp_Server::handle_request(new WP_REST_Request(
    ['Authorization' => 'Bearer ' . $access_token],
    (string) json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])
));

vinfo("HTTP status           : {$listed->status}");
vinfo('MCP-Protocol-Version : ' . (string) ($listed->get_headers()['MCP-Protocol-Version'] ?? ''));

if (200 === $listed->status) {
    $tools = (array) ($listed->data['result']['tools'] ?? []);
    vok('authenticated tools/list succeeded with ' . count($tools) . ' tools');
    foreach ($tools as $tool) {
        vinfo(sprintf(
            '  %-38s %-18s readOnly=%s',
            (string) $tool['name'],
            (string) ($tool['_meta']['io.pageforge/scope'] ?? ''),
            !empty($tool['readOnly']) ? 'yes' : 'NO'
        ));
        if (empty($tool['readOnly'])) {
            vbad('Free exposed a non-read-only tool: ' . (string) $tool['name']);
        }
    }
} else {
    vbad("tools/list failed with {$listed->status}");
}

// ============================================================== step 9 ====
vstep('Call a tool with the OAuth token');

$called = Mcp_Server::handle_request(new WP_REST_Request(
    ['Authorization' => 'Bearer ' . $access_token],
    (string) json_encode([
        'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
        'params' => ['name' => 'pageforge.site_status', 'arguments' => (object) []],
    ])
));
vinfo("HTTP status : {$called->status}");
vinfo('result      : ' . json_encode($called->data['result']['structuredContent'] ?? null));
200 === $called->status ? vok('tool call succeeded as the authorized WordPress user') : vbad('tool call failed');

// ============================================================= step 10 ====
vstep('Audience isolation: a token minted for another resource');

$foreign = Oauth_Tokens::issue_access_token([
    'client_id' => $client['client_id'],
    'user_id' => 7,
    'scopes' => [Mcp_Scopes::READ],
    'resource' => 'https://someone-else.example/mcp',
]);
$rejected = Mcp_Server::handle_request(new WP_REST_Request(
    ['Authorization' => 'Bearer ' . $foreign['token']],
    (string) json_encode(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list'])
));
vinfo("HTTP status : {$rejected->status}");
401 === $rejected->status ? vok('foreign-audience token refused') : vbad('foreign-audience token accepted');

// ============================================================= step 11 ====
vstep('Refresh token rotation and replay detection');

$refresh = (string) ($exchange->data['refresh_token'] ?? '');
$refreshed = Oauth::rest_token($token_request([
    'grant_type' => 'refresh_token',
    'refresh_token' => $refresh,
    'client_id' => $client['client_id'],
    'client_secret' => $client['client_secret'],
]));
vinfo("rotate status : {$refreshed->status}");
200 === $refreshed->status ? vok('refresh succeeded') : vbad('refresh failed');
$refresh !== (string) ($refreshed->data['refresh_token'] ?? '')
    ? vok('refresh token rotated to a new value')
    : vbad('refresh token was not rotated');

$replayed_refresh = Oauth::rest_token($token_request([
    'grant_type' => 'refresh_token',
    'refresh_token' => $refresh,
    'client_id' => $client['client_id'],
    'client_secret' => $client['client_secret'],
]));
vinfo("replay status : {$replayed_refresh->status}  error: " . (string) ($replayed_refresh->data['error'] ?? ''));
400 === $replayed_refresh->status ? vok('replayed refresh token refused') : vbad('replayed refresh token accepted');

// ============================================================= step 12 ====
vstep('Personal Access Token path (IDE / Codex / Cursor / Claude Code)');

$pat = \PageForge\Mcp_Credentials::create('VS Code', 'vscode', [Mcp_Scopes::READ, Mcp_Scopes::PREVIEW]);
if (is_wp_error($pat)) {
    vbad('PAT creation failed: ' . $pat->get_error_message());
} else {
    vinfo('token  : ' . substr($pat['token'], 0, 20) . '... (shown once)');
    $status = \PageForge\Mcp_Credentials::all_redacted()[$pat['id']]['status'];
    vinfo('status : ' . $status['state'] . ' — ' . $status['label']);
    'never_used' === $status['state']
        ? vok('a freshly minted PAT is reported as active but never used, not "Connected"')
        : vbad('a freshly minted PAT claims to be connected');

    $pat_call = Mcp_Server::handle_request(new WP_REST_Request(
        ['Authorization' => 'Bearer ' . $pat['token']],
        (string) json_encode(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list'])
    ));
    vinfo("PAT tools/list status : {$pat_call->status}");
    200 === $pat_call->status ? vok('PAT authentication works alongside OAuth') : vbad('PAT authentication broken');

    $after = \PageForge\Mcp_Credentials::all_redacted()[$pat['id']]['status'];
    vinfo('status after use : ' . $after['state']);
    'in_use' === $after['state'] ? vok('status becomes in_use only after an observed request') : vbad('status did not update after use');
}

// ============================================================= step 13 ====
vstep('Free read-only boundary: no write tool may exist');

add_filter('pageforge_mcp_available_tools', static function ($tools) {
    $tools['pageforge.publish_everything'] = [
        'title' => 'Publish everything',
        'description' => 'hostile write tool',
        'scope' => Mcp_Scopes::WRITE,
        'readOnly' => false,
        'inputSchema' => ['type' => 'object'],
        'handler' => static function () { return ['done' => true]; },
    ];
    return $tools;
});
$hostile = Mcp_Server::handle_request(new WP_REST_Request(
    ['Authorization' => 'Bearer ' . $access_token],
    (string) json_encode([
        'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
        'params' => ['name' => 'pageforge.publish_everything', 'arguments' => (object) []],
    ])
));
vinfo("HTTP status : {$hostile->status}");
404 === $hostile->status
    ? vok('a write tool injected through the filter is unreachable on Free')
    : vbad('a write tool became reachable on Free');

vinfo('grantable scopes on this edition: ' . implode(' ', Mcp_Scopes::grantable()));

// =============================================================== verdict ==
echo "\n=====================================================\n";
if ($fail > 0) {
    echo "MCP/OAuth VERIFICATION: {$fail} problem(s)\n";
} else {
    echo "MCP/OAuth VERIFICATION: full discovery -> consent -> token -> call\n";
    echo "chain verified against MCP 2026-07-28 authorization.\n";
}
echo "=====================================================\n";
exit($fail > 0 ? 1 : 0);
