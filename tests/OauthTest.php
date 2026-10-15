<?php

use PageForge\Mcp_Scopes;
use PageForge\Oauth;
use PageForge\Oauth_Clients;
use PageForge\Oauth_Tokens;
use PageForge\Tests\TestCase;

// ---------------------------------------------------------------- helpers --

function pageforge_oauth_test_setup(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_settings']['mcp_enabled'] = true;
    $GLOBALS['pageforge_test_caps'] = [
        7 => ['read' => true, 'edit_posts' => true, 'manage_options' => true],
        // An account with no right to connect AI clients.
        9 => ['read' => true, 'edit_posts' => true, 'manage_options' => false],
    ];
    wp_set_current_user(7);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $_SERVER['REQUEST_METHOD'] = 'POST';
}

/** A confidential pre-registered client, as Claude's connector UI would use. */
function pageforge_oauth_test_client(bool $confidential = true): array {
    return Oauth_Clients::create_preregistered('Claude', ['https://claude.ai/api/mcp/auth_callback'], $confidential);
}

function pageforge_oauth_test_token_request(array $params, array $headers = []): WP_REST_Request {
    return new WP_REST_Request($headers, http_build_query($params));
}

/** PKCE pair: verifier plus its S256 challenge. */
function pageforge_oauth_test_pkce(): array {
    $verifier = str_repeat('a', 43);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return [$verifier, $challenge];
}

/**
 * Seeds an authorization code as if the consent screen had just issued one.
 */
function pageforge_oauth_test_seed_code(string $client_id, array $overrides = []): array {
    [$verifier, $challenge] = pageforge_oauth_test_pkce();
    $code = 'pfac_' . str_repeat('b', 48);
    Oauth_Tokens::store_code($code, array_merge([
        'client_id' => $client_id,
        'user_id' => 7,
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        'scopes' => [Mcp_Scopes::READ, Mcp_Scopes::PREVIEW],
        'resource' => Oauth::resource_identifier(),
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'offline_access' => true,
    ], $overrides));
    return [$code, $verifier];
}

// ------------------------------------------------------------- discovery --

/**
 * RFC 9728. Without `resource` and `authorization_servers` a client has no way
 * to find the authorization server, which is the failure that blocked every
 * ChatGPT/Claude connection attempt.
 */
function test_pageforge_oauth_protected_resource_metadata_is_complete(): void {
    pageforge_oauth_test_setup();
    $prm = Oauth::protected_resource_metadata();

    TestCase::same(Oauth::resource_identifier(), $prm['resource']);
    TestCase::same([Oauth::issuer()], $prm['authorization_servers']);
    TestCase::same(['header'], $prm['bearer_methods_supported']);
    TestCase::true(in_array(Mcp_Scopes::READ, $prm['scopes_supported'], true));
    // The spec forbids advertising offline_access as a resource requirement.
    TestCase::same(false, in_array(Mcp_Scopes::OFFLINE_ACCESS, $prm['scopes_supported'], true));
}

function test_pageforge_oauth_resource_identifier_is_canonical(): void {
    pageforge_oauth_test_setup();
    $resource = Oauth::resource_identifier();

    TestCase::same(0, strpos($resource, 'https://'));
    // No trailing slash and no fragment, per the canonical-URI guidance.
    TestCase::same($resource, rtrim($resource, '/'));
    TestCase::same(false, false !== strpos($resource, '#'));
    TestCase::same(strtolower($resource), $resource);
}

/**
 * RFC 8414 s3.3: a conforming client rejects metadata whose `issuer` differs
 * from the identifier used to build the discovery URL.
 */
function test_pageforge_oauth_authorization_server_metadata_issuer_is_self_consistent(): void {
    pageforge_oauth_test_setup();
    $as = Oauth::authorization_server_metadata();

    TestCase::same(Oauth::issuer(), $as['issuer']);
    TestCase::same(0, strpos((string) $as['authorization_endpoint'], $as['issuer']));
    TestCase::same(['S256'], $as['code_challenge_methods_supported']);
    TestCase::same(['code'], $as['response_types_supported']);
    TestCase::same(true, $as['authorization_response_iss_parameter_supported']);
    TestCase::same(true, $as['client_id_metadata_document_supported']);
    TestCase::true(in_array('authorization_code', $as['grant_types_supported'], true));
    TestCase::true(in_array('refresh_token', $as['grant_types_supported'], true));
}

/**
 * PKCE `plain` must never be advertised: it gives no protection against an
 * intercepted authorization code.
 */
function test_pageforge_oauth_never_advertises_plain_pkce(): void {
    pageforge_oauth_test_setup();
    TestCase::same(false, in_array('plain', Oauth::authorization_server_metadata()['code_challenge_methods_supported'], true));
}

/** DCR remains a guarded default for shipping clients that do not support CIMD. */
function test_pageforge_oauth_advertises_dcr_compatibility_by_default(): void {
    pageforge_oauth_test_setup();
    TestCase::same(Oauth::register_url(), Oauth::authorization_server_metadata()['registration_endpoint']);
}

function test_pageforge_oauth_can_disable_dcr_compatibility_explicitly(): void {
    pageforge_oauth_test_setup();
    $GLOBALS['pageforge_test_settings']['mcp_oauth_dcr_enabled'] = false;

    TestCase::same(false, isset(Oauth::authorization_server_metadata()['registration_endpoint']));
}

function test_pageforge_oauth_registration_endpoint_is_open_and_guarded_by_default(): void {
    pageforge_oauth_test_setup();

    $response = Oauth::rest_register(new WP_REST_Request([], (string) \wp_json_encode([
        'client_name' => 'Compatible MCP client',
        'redirect_uris' => ['https://client.example/cb'],
    ])));

    TestCase::same(201, $response->status);
    TestCase::true(0 === strpos((string) $response->data['client_id'], 'pfd_'));
    TestCase::same('none', $response->data['token_endpoint_auth_method']);
}

function test_pageforge_oauth_advertises_offline_access_for_refresh_tokens(): void {
    pageforge_oauth_test_setup();
    TestCase::true(in_array(Mcp_Scopes::OFFLINE_ACCESS, Oauth::authorization_server_metadata()['scopes_supported'], true));
    TestCase::same(false, in_array(Mcp_Scopes::OFFLINE_ACCESS, Oauth::protected_resource_metadata()['scopes_supported'], true));
}

// ------------------------------------------------------------------ PKCE --

function test_pageforge_oauth_pkce_accepts_the_matching_verifier(): void {
    [$verifier, $challenge] = pageforge_oauth_test_pkce();
    TestCase::same(true, Oauth::verify_pkce($verifier, $challenge));
}

function test_pageforge_oauth_pkce_rejects_a_wrong_verifier(): void {
    [, $challenge] = pageforge_oauth_test_pkce();
    TestCase::same(false, Oauth::verify_pkce(str_repeat('c', 43), $challenge));
}

/** RFC 7636 s4.1 length bounds: a short verifier would be brute-forceable. */
function test_pageforge_oauth_pkce_rejects_out_of_bounds_verifier_lengths(): void {
    [, $challenge] = pageforge_oauth_test_pkce();
    TestCase::same(false, Oauth::verify_pkce('short', $challenge));
    TestCase::same(false, Oauth::verify_pkce(str_repeat('a', 129), $challenge));
}

/**
 * A "plain" style verifier equal to the challenge must not be accepted, or
 * PKCE degrades to no protection at all.
 */
function test_pageforge_oauth_pkce_rejects_plain_style_verifier_equal_to_challenge(): void {
    [, $challenge] = pageforge_oauth_test_pkce();
    TestCase::same(false, Oauth::verify_pkce($challenge, $challenge));
}

// -------------------------------------------------------- redirect URIs --

function test_pageforge_oauth_redirect_uri_requires_an_exact_match(): void {
    pageforge_oauth_test_setup();
    $client = Oauth_Clients::resolve(pageforge_oauth_test_client()['client_id']);

    TestCase::same(true, Oauth_Clients::redirect_uri_allowed($client, 'https://claude.ai/api/mcp/auth_callback'));
    // Prefix/suffix variants are the classic open-redirect vector.
    TestCase::same(false, Oauth_Clients::redirect_uri_allowed($client, 'https://claude.ai/api/mcp/auth_callback/../evil'));
    TestCase::same(false, Oauth_Clients::redirect_uri_allowed($client, 'https://claude.ai.attacker.example/api/mcp/auth_callback'));
    TestCase::same(false, Oauth_Clients::redirect_uri_allowed($client, 'https://claude.ai/api/mcp/auth_callback?next=https://evil.example'));
    TestCase::same(false, Oauth_Clients::redirect_uri_allowed($client, 'https://attacker.example/cb'));
}

/** RFC 8252 s7.3: native clients bind an ephemeral loopback port. */
function test_pageforge_oauth_allows_loopback_port_variance_only_for_loopback(): void {
    pageforge_oauth_test_setup();
    $created = Oauth_Clients::create_preregistered('IDE', ['http://127.0.0.1:3000/callback'], false);
    $client = Oauth_Clients::resolve($created['client_id']);

    TestCase::same(true, Oauth_Clients::redirect_uri_allowed($client, 'http://127.0.0.1:51823/callback'));
    // A different path is still a different destination.
    TestCase::same(false, Oauth_Clients::redirect_uri_allowed($client, 'http://127.0.0.1:51823/other'));
    // `localhost` is DNS-resolvable and therefore hijackable, so no variance.
    TestCase::same(false, Oauth_Clients::redirect_uri_allowed($client, 'http://localhost:3000/callback'));
}

/** Cleartext http to a remote host would leak the authorization code. */
function test_pageforge_oauth_rejects_insecure_and_malformed_redirect_uris(): void {
    $sanitized = Oauth_Clients::sanitize_redirect_uris([
        'https://good.example/cb',
        'http://insecure.example/cb',
        'https://fragment.example/cb#part',
        'not-a-url',
        'javascript:alert(1)',
        'http://127.0.0.1:9000/cb',
    ]);

    TestCase::same(['https://good.example/cb', 'http://127.0.0.1:9000/cb'], $sanitized);
}

// -------------------------------------------------- audience / resource --

function test_pageforge_oauth_resource_matching_accepts_canonical_and_tolerant_forms(): void {
    pageforge_oauth_test_setup();
    $canonical = Oauth::resource_identifier();

    TestCase::same(true, Oauth::resource_matches($canonical));
    TestCase::same(true, Oauth::resource_matches($canonical . '/'));
    TestCase::same(true, Oauth::resource_matches(str_replace('https://', 'HTTPS://', $canonical)));
    TestCase::same(true, Oauth::resource_matches(Oauth::issuer()));
}

/** The confused-deputy defence: another server's audience is not ours. */
function test_pageforge_oauth_resource_matching_rejects_a_foreign_audience(): void {
    pageforge_oauth_test_setup();

    TestCase::same(false, Oauth::resource_matches('https://attacker.example/wp-json/pageforge/v1/mcp'));
    TestCase::same(false, Oauth::resource_matches('https://example.test.attacker.example/mcp'));
    TestCase::same(false, Oauth::resource_matches('https://example.test/wp-json/other/v1/mcp'));
}

/**
 * A token that is cryptographically valid but was minted for a different
 * resource must be refused at the MCP endpoint.
 */
function test_pageforge_oauth_access_token_for_another_audience_is_refused_by_mcp(): void {
    pageforge_oauth_test_setup();
    $issued = Oauth_Tokens::issue_access_token([
        'client_id' => 'pfc_test',
        'user_id' => 7,
        'scopes' => [Mcp_Scopes::READ],
        'resource' => 'https://someone-else.example/mcp',
    ]);

    $response = \PageForge\Mcp_Server::handle_request(new WP_REST_Request(
        ['Authorization' => 'Bearer ' . $issued['token']],
        (string) \wp_json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
    ));

    TestCase::same(401, $response->status);
}

function test_pageforge_oauth_access_token_for_this_audience_is_accepted_by_mcp(): void {
    pageforge_oauth_test_setup();
    $issued = Oauth_Tokens::issue_access_token([
        'client_id' => 'pfc_test',
        'user_id' => 7,
        'scopes' => [Mcp_Scopes::READ],
        'resource' => Oauth::resource_identifier(),
    ]);

    $response = \PageForge\Mcp_Server::handle_request(new WP_REST_Request(
        ['Authorization' => 'Bearer ' . $issued['token']],
        (string) \wp_json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
    ));

    TestCase::same(200, $response->status);
    TestCase::true(!empty($response->data['result']['tools']));
}

// ---------------------------------------------------- token endpoint ----

function test_pageforge_oauth_authorization_code_exchange_issues_bound_tokens(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();
    [$code, $verifier] = pageforge_oauth_test_seed_code($client['client_id']);

    $response = Oauth::rest_token(pageforge_oauth_test_token_request([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'code_verifier' => $verifier,
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
        'resource' => Oauth::resource_identifier(),
    ]));

    TestCase::same(200, $response->status);
    TestCase::same('Bearer', $response->data['token_type']);
    TestCase::true(!empty($response->data['access_token']));
    TestCase::true(!empty($response->data['refresh_token']));
    TestCase::same(Mcp_Scopes::READ . ' ' . Mcp_Scopes::PREVIEW, $response->data['scope']);
    // RFC 6749 s5.1: token responses must not be cached.
    TestCase::same('no-store', $response->get_headers()['Cache-Control']);

    $record = Oauth_Tokens::lookup_access_token((string) $response->data['access_token']);
    TestCase::same(7, $record['user_id']);
    TestCase::same(Oauth::resource_identifier(), $record['resource']);
}

/** Single use: replaying a code must fail even inside its 60s window. */
function test_pageforge_oauth_authorization_code_cannot_be_replayed(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();
    [$code, $verifier] = pageforge_oauth_test_seed_code($client['client_id']);

    $params = [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'code_verifier' => $verifier,
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ];

    TestCase::same(200, Oauth::rest_token(pageforge_oauth_test_token_request($params))->status);

    $replay = Oauth::rest_token(pageforge_oauth_test_token_request($params));
    TestCase::same(400, $replay->status);
    TestCase::same('invalid_grant', $replay->data['error']);
}

function test_pageforge_oauth_authorization_code_requires_the_matching_pkce_verifier(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();
    [$code] = pageforge_oauth_test_seed_code($client['client_id']);

    $response = Oauth::rest_token(pageforge_oauth_test_token_request([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'code_verifier' => str_repeat('z', 43),
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ]));

    TestCase::same(400, $response->status);
    TestCase::same('invalid_grant', $response->data['error']);
}

/** Code injection across clients: the code is bound to one client only. */
function test_pageforge_oauth_authorization_code_cannot_be_redeemed_by_another_client(): void {
    pageforge_oauth_test_setup();
    $victim = pageforge_oauth_test_client();
    $attacker = Oauth_Clients::create_preregistered('Attacker', ['https://attacker.example/cb'], true);
    [$code, $verifier] = pageforge_oauth_test_seed_code($victim['client_id']);

    $response = Oauth::rest_token(pageforge_oauth_test_token_request([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'code_verifier' => $verifier,
        'client_id' => $attacker['client_id'],
        'client_secret' => $attacker['client_secret'],
    ]));

    TestCase::same(400, $response->status);
    TestCase::same('invalid_grant', $response->data['error']);
}

/** Redirect substitution between authorization and exchange. */
function test_pageforge_oauth_authorization_code_rejects_a_substituted_redirect_uri(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();
    [$code, $verifier] = pageforge_oauth_test_seed_code($client['client_id']);

    $response = Oauth::rest_token(pageforge_oauth_test_token_request([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'code_verifier' => $verifier,
        'redirect_uri' => 'https://attacker.example/cb',
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ]));

    TestCase::same(400, $response->status);
    TestCase::same('invalid_grant', $response->data['error']);
}

function test_pageforge_oauth_confidential_client_cannot_authenticate_without_its_secret(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client(true);
    [$code, $verifier] = pageforge_oauth_test_seed_code($client['client_id']);

    $response = Oauth::rest_token(pageforge_oauth_test_token_request([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'code_verifier' => $verifier,
        'client_id' => $client['client_id'],
    ]));

    TestCase::same(401, $response->status);
    TestCase::same('invalid_client', $response->data['error']);
}

function test_pageforge_oauth_client_secret_is_accepted_via_http_basic(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client(true);
    [$code, $verifier] = pageforge_oauth_test_seed_code($client['client_id']);

    $response = Oauth::rest_token(pageforge_oauth_test_token_request(
        [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
        ],
        ['Authorization' => 'Basic ' . base64_encode($client['client_id'] . ':' . $client['client_secret'])]
    ));

    TestCase::same(200, $response->status);
}

/** A grant approved by an account that lost the right must stop working. */
function test_pageforge_oauth_code_is_refused_when_the_approving_user_lost_permission(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();
    [$code, $verifier] = pageforge_oauth_test_seed_code($client['client_id'], ['user_id' => 9]);

    $response = Oauth::rest_token(pageforge_oauth_test_token_request([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'code_verifier' => $verifier,
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ]));

    TestCase::same(400, $response->status);
    TestCase::same('invalid_grant', $response->data['error']);
}

/** Free cannot grant write scopes, so an approved write scope is dropped. */
function test_pageforge_oauth_scopes_are_reduced_to_what_the_edition_can_grant(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();
    [$code, $verifier] = pageforge_oauth_test_seed_code($client['client_id'], [
        'scopes' => [Mcp_Scopes::READ, Mcp_Scopes::WRITE, Mcp_Scopes::ADMIN],
    ]);

    $response = Oauth::rest_token(pageforge_oauth_test_token_request([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'code_verifier' => $verifier,
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ]));

    TestCase::same(200, $response->status);
    TestCase::same(Mcp_Scopes::READ, $response->data['scope']);
}

function test_pageforge_oauth_unsupported_grant_type_is_rejected(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();

    $response = Oauth::rest_token(pageforge_oauth_test_token_request([
        'grant_type' => 'password',
        'username' => 'admin',
        'password' => 'hunter2',
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ]));

    TestCase::same(400, $response->status);
    TestCase::same('unsupported_grant_type', $response->data['error']);
}

// ------------------------------------------------------ refresh tokens --

function test_pageforge_oauth_refresh_token_rotates_on_every_use(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();
    $refresh = Oauth_Tokens::issue_refresh_token([
        'client_id' => $client['client_id'],
        'user_id' => 7,
        'scopes' => [Mcp_Scopes::READ],
        'resource' => Oauth::resource_identifier(),
    ]);

    $response = Oauth::rest_token(pageforge_oauth_test_token_request([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh,
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ]));

    TestCase::same(200, $response->status);
    TestCase::true(!empty($response->data['refresh_token']));
    // Rotation means a genuinely different token, not the same one returned.
    TestCase::same(false, $refresh === $response->data['refresh_token']);
}

/**
 * Replay of a rotated-away refresh token means it leaked. Silently issuing a
 * new one would make refresh-token theft undetectable, so the whole family
 * is destroyed and re-authorization is required.
 */
function test_pageforge_oauth_replayed_refresh_token_destroys_the_whole_family(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();
    $first = Oauth_Tokens::issue_refresh_token([
        'client_id' => $client['client_id'],
        'user_id' => 7,
        'scopes' => [Mcp_Scopes::READ],
        'resource' => Oauth::resource_identifier(),
    ]);

    $params = [
        'grant_type' => 'refresh_token',
        'refresh_token' => $first,
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ];
    $rotated = Oauth::rest_token(pageforge_oauth_test_token_request($params));
    $second = (string) $rotated->data['refresh_token'];

    // Replay the retired token.
    $replay = Oauth::rest_token(pageforge_oauth_test_token_request($params));
    TestCase::same(400, $replay->status);
    TestCase::same('invalid_grant', $replay->data['error']);

    // The successor is now dead too, because the family was burned.
    $after = Oauth::rest_token(pageforge_oauth_test_token_request(array_merge($params, ['refresh_token' => $second])));
    TestCase::same(400, $after->status);
}

function test_pageforge_oauth_refresh_token_cannot_be_used_by_another_client(): void {
    pageforge_oauth_test_setup();
    $owner = pageforge_oauth_test_client();
    $other = Oauth_Clients::create_preregistered('Other', ['https://other.example/cb'], true);
    $refresh = Oauth_Tokens::issue_refresh_token([
        'client_id' => $owner['client_id'],
        'user_id' => 7,
        'scopes' => [Mcp_Scopes::READ],
        'resource' => Oauth::resource_identifier(),
    ]);

    $response = Oauth::rest_token(pageforge_oauth_test_token_request([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh,
        'client_id' => $other['client_id'],
        'client_secret' => $other['client_secret'],
    ]));

    TestCase::same(400, $response->status);
    TestCase::same('invalid_grant', $response->data['error']);
}

/** Refresh may narrow a grant but never widen it. */
function test_pageforge_oauth_refresh_cannot_escalate_scope(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();
    $refresh = Oauth_Tokens::issue_refresh_token([
        'client_id' => $client['client_id'],
        'user_id' => 7,
        'scopes' => [Mcp_Scopes::READ],
        'resource' => Oauth::resource_identifier(),
    ]);

    $response = Oauth::rest_token(pageforge_oauth_test_token_request([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh,
        'scope' => Mcp_Scopes::READ . ' ' . Mcp_Scopes::PREVIEW,
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ]));

    TestCase::same(400, $response->status);
    TestCase::same('invalid_scope', $response->data['error']);
}

function test_pageforge_oauth_refresh_rejects_a_mixed_unknown_scope(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();
    $refresh = Oauth_Tokens::issue_refresh_token([
        'client_id' => $client['client_id'],
        'user_id' => 7,
        'scopes' => [Mcp_Scopes::READ, Mcp_Scopes::PREVIEW],
        'resource' => Oauth::resource_identifier(),
    ]);

    $response = Oauth::rest_token(pageforge_oauth_test_token_request([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh,
        'scope' => Mcp_Scopes::READ . ' pageforge:unknown',
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ]));

    TestCase::same(400, $response->status);
    TestCase::same('invalid_scope', $response->data['error']);
}

function test_pageforge_oauth_refresh_may_narrow_scope(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();
    $refresh = Oauth_Tokens::issue_refresh_token([
        'client_id' => $client['client_id'],
        'user_id' => 7,
        'scopes' => [Mcp_Scopes::READ, Mcp_Scopes::PREVIEW],
        'resource' => Oauth::resource_identifier(),
    ]);

    $response = Oauth::rest_token(pageforge_oauth_test_token_request([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh,
        'scope' => Mcp_Scopes::READ,
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ]));

    TestCase::same(200, $response->status);
    TestCase::same(Mcp_Scopes::READ, $response->data['scope']);
}

// -------------------------------------------------------- revocation ----

function test_pageforge_oauth_revocation_kills_the_refresh_grant(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();
    $refresh = Oauth_Tokens::issue_refresh_token([
        'client_id' => $client['client_id'],
        'user_id' => 7,
        'scopes' => [Mcp_Scopes::READ],
        'resource' => Oauth::resource_identifier(),
    ]);

    $revoked = Oauth::rest_revoke(pageforge_oauth_test_token_request([
        'token' => $refresh,
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ]));
    TestCase::same(200, $revoked->status);

    $after = Oauth::rest_token(pageforge_oauth_test_token_request([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh,
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ]));
    TestCase::same(400, $after->status);
}

/** RFC 7009 s2.2: an unknown token still returns 200, so it cannot be probed. */
function test_pageforge_oauth_revoking_an_unknown_token_still_succeeds(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();

    $response = Oauth::rest_revoke(pageforge_oauth_test_token_request([
        'token' => 'pfrt_' . str_repeat('x', 48),
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ]));

    TestCase::same(200, $response->status);
}

/** One client must not be able to revoke another's access token. */
function test_pageforge_oauth_client_cannot_revoke_another_clients_access_token(): void {
    pageforge_oauth_test_setup();
    $owner = pageforge_oauth_test_client();
    $other = Oauth_Clients::create_preregistered('Other', ['https://other.example/cb'], true);
    $issued = Oauth_Tokens::issue_access_token([
        'client_id' => $owner['client_id'],
        'user_id' => 7,
        'scopes' => [Mcp_Scopes::READ],
        'resource' => Oauth::resource_identifier(),
    ]);

    Oauth::rest_revoke(pageforge_oauth_test_token_request([
        'token' => $issued['token'],
        'client_id' => $other['client_id'],
        'client_secret' => $other['client_secret'],
    ]));

    // Still valid: the other client had no standing to revoke it.
    TestCase::true(null !== Oauth_Tokens::lookup_access_token($issued['token']));
}

function test_pageforge_oauth_revoking_a_client_kills_its_grants(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();
    $refresh = Oauth_Tokens::issue_refresh_token([
        'client_id' => $client['client_id'],
        'user_id' => 7,
        'scopes' => [Mcp_Scopes::READ],
        'resource' => Oauth::resource_identifier(),
    ]);

    Oauth_Clients::revoke($client['client_id']);

    TestCase::same(null, Oauth_Clients::resolve($client['client_id']));
    TestCase::same(0, count(Oauth_Tokens::grants_redacted()));
    TestCase::true(\is_wp_error(Oauth_Tokens::rotate_refresh_token($refresh, $client['client_id'])));
}

// ------------------------------------------------------------- consent --

function test_pageforge_oauth_consent_is_per_user_and_per_client(): void {
    pageforge_oauth_test_setup();
    Oauth_Tokens::remember_consent(7, 'pfc_a', [Mcp_Scopes::READ]);

    TestCase::same(true, Oauth_Tokens::has_consent(7, 'pfc_a', [Mcp_Scopes::READ]));
    // A different user's approval is not this user's approval.
    TestCase::same(false, Oauth_Tokens::has_consent(9, 'pfc_a', [Mcp_Scopes::READ]));
    // A different client is a different grant.
    TestCase::same(false, Oauth_Tokens::has_consent(7, 'pfc_b', [Mcp_Scopes::READ]));
}

/** A newly requested scope must re-prompt, making step-up visible. */
function test_pageforge_oauth_consent_does_not_cover_a_newly_requested_scope(): void {
    pageforge_oauth_test_setup();
    Oauth_Tokens::remember_consent(7, 'pfc_a', [Mcp_Scopes::READ]);

    TestCase::same(false, Oauth_Tokens::has_consent(7, 'pfc_a', [Mcp_Scopes::READ, Mcp_Scopes::PREVIEW]));
}

// ---------------------------------------------------------------- CIMD --

function pageforge_oauth_test_cimd_handler(array $document, int $status = 200, array $headers = []): callable {
    return static function (string $url, array $args) use ($document, $status, $headers) {
        return [
            'response' => ['code' => $status],
            'headers' => $headers,
            'body' => (string) \wp_json_encode($document),
        ];
    };
}

/**
 * Resolves test hostnames to a public address so the SSRF guard's DNS check
 * can run without the suite depending on real DNS. Private/metadata addresses
 * are still resolved truthfully so the blocking tests remain honest.
 */
function pageforge_oauth_test_stub_dns(): void {
    add_filter('pageforge_url_guard_resolver', static function () {
        return static function (string $host): string {
            return '93.184.216.34'; // Public unicast.
        };
    });
}

function test_pageforge_oauth_cimd_client_is_resolved_from_its_metadata_document(): void {
    pageforge_oauth_test_setup();
    pageforge_oauth_test_stub_dns();
    $client_id = 'https://app.example.com/oauth/client.json';
    $GLOBALS['pageforge_test_http_handler'] = pageforge_oauth_test_cimd_handler([
        'client_id' => $client_id,
        'client_name' => 'Example MCP Client',
        'redirect_uris' => ['https://app.example.com/callback'],
    ]);

    $client = Oauth_Clients::resolve($client_id);

    TestCase::true(is_array($client));
    TestCase::same('Example MCP Client', $client['client_name']);
    TestCase::same(Oauth_Clients::TYPE_CIMD, $client['type']);
    // A CIMD client holds no secret, so it must be treated as public.
    TestCase::same(false, $client['confidential']);
}

/**
 * The identity rule: the document must claim exactly its own URL. Without it,
 * one host could impersonate another's client identity.
 */
function test_pageforge_oauth_cimd_rejects_a_document_claiming_a_different_client_id(): void {
    pageforge_oauth_test_setup();
    pageforge_oauth_test_stub_dns();
    $GLOBALS['pageforge_test_http_handler'] = pageforge_oauth_test_cimd_handler([
        'client_id' => 'https://trusted.example/oauth/client.json',
        'client_name' => 'Impersonator',
        'redirect_uris' => ['https://attacker.example/callback'],
    ]);

    TestCase::same(null, Oauth_Clients::resolve('https://attacker.example/oauth/client.json'));
}

function test_pageforge_oauth_cimd_requires_https_and_a_path(): void {
    TestCase::same(false, Oauth_Clients::looks_like_cimd('http://app.example.com/client.json'));
    // A bare origin is not a usable identifier.
    TestCase::same(false, Oauth_Clients::looks_like_cimd('https://app.example.com'));
    TestCase::same(false, Oauth_Clients::looks_like_cimd('https://app.example.com/'));
    TestCase::same(false, Oauth_Clients::looks_like_cimd('https://app.example.com/c.json#f'));
    TestCase::same(true, Oauth_Clients::looks_like_cimd('https://app.example.com/oauth/client.json'));
}

/** A client_id must not become a way to make the site probe its own network. */
function test_pageforge_oauth_cimd_blocks_ssrf_targets(): void {
    pageforge_oauth_test_setup();
    $GLOBALS['pageforge_test_http_handler'] = pageforge_oauth_test_cimd_handler([
        'client_id' => 'https://127.0.0.1/client.json',
        'redirect_uris' => ['https://attacker.example/cb'],
    ]);

    TestCase::same(null, Oauth_Clients::resolve('https://127.0.0.1/client.json'));
    TestCase::same(null, Oauth_Clients::resolve('https://169.254.169.254/client.json'));
}

function test_pageforge_oauth_cimd_requires_at_least_one_redirect_uri(): void {
    pageforge_oauth_test_setup();
    pageforge_oauth_test_stub_dns();
    $client_id = 'https://app.example.com/oauth/client.json';
    $GLOBALS['pageforge_test_http_handler'] = pageforge_oauth_test_cimd_handler([
        'client_id' => $client_id,
        'client_name' => 'No Redirects',
        'redirect_uris' => [],
    ]);

    TestCase::same(null, Oauth_Clients::resolve($client_id));
}

function test_pageforge_oauth_cimd_rejects_a_non_200_document(): void {
    pageforge_oauth_test_setup();
    pageforge_oauth_test_stub_dns();
    $client_id = 'https://app.example.com/oauth/client.json';
    $GLOBALS['pageforge_test_http_handler'] = pageforge_oauth_test_cimd_handler([
        'client_id' => $client_id,
        'redirect_uris' => ['https://app.example.com/cb'],
    ], 404);

    TestCase::same(null, Oauth_Clients::resolve($client_id));
}

// -------------------------------------------------------- rate limiting --

/**
 * An unauthenticated brute force against the token endpoint must not become an
 * unbounded WordPress workload.
 */
function test_pageforge_oauth_token_endpoint_is_rate_limited(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client();

    $limited = false;
    for ($i = 0; $i < 40; $i++) {
        $response = Oauth::rest_token(pageforge_oauth_test_token_request([
            'grant_type' => 'authorization_code',
            'code' => 'pfac_' . str_repeat('q', 48),
            'code_verifier' => str_repeat('a', 43),
            'client_id' => $client['client_id'],
            'client_secret' => $client['client_secret'],
        ]));
        if (429 === $response->status) {
            $limited = true;
            break;
        }
    }

    TestCase::same(true, $limited);
}

function test_pageforge_mcp_repeated_bad_bearer_tokens_are_rate_limited(): void {
    pageforge_oauth_test_setup();

    $limited = false;
    for ($i = 0; $i < 20; $i++) {
        $response = \PageForge\Mcp_Server::handle_request(new WP_REST_Request(
            ['Authorization' => 'Bearer pfmcp_mcp_aaaaaaaaaaaa_' . str_repeat('b', 43)],
            (string) \wp_json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ));
        if (429 === $response->status) {
            $limited = true;
            break;
        }
    }

    TestCase::same(true, $limited);
}

// ------------------------------------------------------- stored secrets --

/** A stored client secret must not be recoverable from the options table. */
function test_pageforge_oauth_client_secret_is_never_stored_in_the_clear(): void {
    pageforge_oauth_test_setup();
    $client = pageforge_oauth_test_client(true);

    $serialized = (string) \wp_json_encode(get_option('pageforge_oauth_clients', []));
    TestCase::same(false, false !== strpos($serialized, $client['client_secret']));
}

function test_pageforge_oauth_redacted_client_list_omits_secret_hashes(): void {
    pageforge_oauth_test_setup();
    pageforge_oauth_test_client(true);

    $serialized = (string) \wp_json_encode(Oauth_Clients::all_redacted());
    TestCase::same(false, false !== strpos($serialized, 'secret_hash'));
}

/** Nor must a raw access or refresh token be recoverable from storage. */
function test_pageforge_oauth_tokens_are_never_stored_in_the_clear(): void {
    pageforge_oauth_test_setup();
    $issued = Oauth_Tokens::issue_access_token([
        'client_id' => 'pfc_test',
        'user_id' => 7,
        'scopes' => [Mcp_Scopes::READ],
        'resource' => Oauth::resource_identifier(),
    ]);
    $refresh = Oauth_Tokens::issue_refresh_token([
        'client_id' => 'pfc_test',
        'user_id' => 7,
        'scopes' => [Mcp_Scopes::READ],
        'resource' => Oauth::resource_identifier(),
    ]);

    $dump = (string) \wp_json_encode([
        $GLOBALS['pageforge_test_transients'],
        get_option('pageforge_oauth_refresh_tokens', []),
    ]);

    TestCase::same(false, false !== strpos($dump, $issued['token']));
    TestCase::same(false, false !== strpos($dump, $refresh));
}

function test_pageforge_oauth_grant_list_exposes_only_a_short_fingerprint(): void {
    pageforge_oauth_test_setup();
    Oauth_Tokens::issue_refresh_token([
        'client_id' => 'pfc_test',
        'user_id' => 7,
        'scopes' => [Mcp_Scopes::READ],
        'resource' => Oauth::resource_identifier(),
    ]);

    $grants = Oauth_Tokens::grants_redacted();
    TestCase::same(1, count($grants));
    TestCase::same(8, strlen($grants[0]['fingerprint']));
}

/** Existing OAuth grants from early MCP Lite builds must not become tool-less. */
function test_pageforge_oauth_legacy_read_scopes_remain_usable_after_upgrade(): void {
    pageforge_oauth_test_setup();
    $issued = Oauth_Tokens::issue_access_token([
        'client_id' => 'legacy-free-client',
        'user_id' => 7,
        'scopes' => ['read', 'preview'],
        'resource' => Oauth::resource_identifier(),
    ]);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $response = \PageForge\Mcp_Server::handle_request(new WP_REST_Request(
        ['Authorization' => 'Bearer ' . $issued['token'], 'MCP-Protocol-Version' => '2025-06-18'],
        (string) wp_json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
    ));

    TestCase::same(200, $response->status);
    TestCase::true(count($response->data['result']['tools']) > 0);
    foreach ($response->data['result']['tools'] as $tool) {
        TestCase::same(true, (bool) $tool['readOnly']);
    }
}

/** RFC 9207 issuer and state values must survive the callback exactly once. */
function test_pageforge_oauth_authorization_response_values_are_encoded_once(): void {
    $url = Oauth::authorization_response_url('https://client.example/callback', [
        'code' => 'pfac_abc123',
        'state' => 'state/a+b=c',
        'iss' => 'https://example.test',
    ]);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    TestCase::same('pfac_abc123', $query['code']);
    TestCase::same('state/a+b=c', $query['state']);
    TestCase::same('https://example.test', $query['iss']);
}
