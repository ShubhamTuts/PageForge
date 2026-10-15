<?php

use PageForge\Mcp_Credentials;
use PageForge\Mcp_Scopes;
use PageForge\Tests\TestCase;

/**
 * Establishes an administrator as the acting user. A PAT is now bound to a
 * WordPress user, because a token that belongs to nobody cannot be
 * capability-checked when a tool runs.
 */
function pageforge_pat_test_actor(int $user_id = 7): int {
    $GLOBALS['pageforge_test_caps'] = [
        $user_id => ['read' => true, 'edit_posts' => true, 'manage_options' => true],
    ];
    wp_set_current_user($user_id);
    return $user_id;
}

function test_pageforge_mcp_credential_create_returns_raw_token_once(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    $created = Mcp_Credentials::create('IDE', 'vscode', [Mcp_Scopes::READ]);

    TestCase::true(is_string($created['token']));
    TestCase::true(strlen($created['token']) > 20);
    // The stored record must never contain the raw token.
    $stored = Mcp_Credentials::all()[$created['id']];
    TestCase::same(false, false !== strpos((string) \wp_json_encode($stored), $created['token']));
}

function test_pageforge_mcp_credential_requires_a_wordpress_user(): void {
    pageforge_test_reset_state();
    // No acting user at all.
    wp_set_current_user(0);

    $created = Mcp_Credentials::create('Orphan', 'generic', [Mcp_Scopes::READ]);

    TestCase::true($created instanceof WP_Error);
    TestCase::same('no_user', $created->get_error_code());
}

function test_pageforge_mcp_credential_verify_accepts_the_issued_token(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    $created = Mcp_Credentials::create('Claude Code', 'claude_code', [Mcp_Scopes::READ]);

    $verified = Mcp_Credentials::verify($created['token']);

    TestCase::true(null !== $verified);
    TestCase::same($created['id'], $verified['id']);
}

function test_pageforge_mcp_credential_verify_rejects_unknown_token(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    Mcp_Credentials::create('Claude Code', 'claude_code', [Mcp_Scopes::READ]);

    TestCase::same(null, Mcp_Credentials::verify('pfmcp_totally_forged_token'));
}

/**
 * The token embeds its own record id so verification is a keyed lookup rather
 * than a scan. A forged token naming a real record must still fail on the
 * digest, which is the part that is actually secret.
 */
function test_pageforge_mcp_credential_rejects_forged_token_that_names_a_real_record(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    $created = Mcp_Credentials::create('Cursor', 'cursor', [Mcp_Scopes::READ]);

    $forged = 'pfmcp_' . $created['id'] . '_' . str_repeat('a', 43);

    TestCase::same(null, Mcp_Credentials::verify($forged));
}

function test_pageforge_mcp_credential_revoke_stops_verification(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    $created = Mcp_Credentials::create('Cursor', 'cursor', [Mcp_Scopes::READ]);

    Mcp_Credentials::revoke($created['id']);

    TestCase::same(null, Mcp_Credentials::verify($created['token']));
}

function test_pageforge_mcp_credential_rotate_invalidates_the_previous_token(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    $created = Mcp_Credentials::create('Roo Code', 'roo_code', [Mcp_Scopes::READ]);

    $rotated = Mcp_Credentials::rotate($created['id']);

    TestCase::true(null !== $rotated);
    TestCase::same(null, Mcp_Credentials::verify($created['token']));
    TestCase::true(null !== Mcp_Credentials::verify($rotated));
}

function test_pageforge_mcp_credential_expires_after_ttl(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    $created = Mcp_Credentials::create('Generic Client', 'generic', [Mcp_Scopes::READ], 3600);

    // Simulate the expiry having already passed without depending on
    // real elapsed time in the test run.
    $credentials = Mcp_Credentials::all();
    $credentials[$created['id']]['expires'] = time() - 1;
    update_option('pageforge_mcp_credentials', $credentials, false);

    TestCase::same(null, Mcp_Credentials::verify($created['token']));
}

function test_pageforge_mcp_credential_with_no_ttl_never_expires(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    $created = Mcp_Credentials::create('Generic Client', 'generic', [Mcp_Scopes::READ]);

    $stored = Mcp_Credentials::all()[$created['id']];
    TestCase::same(0, $stored['expires']);
    TestCase::true(null !== Mcp_Credentials::verify($created['token']));
}

function test_pageforge_mcp_credential_defaults_to_read_scope_only(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    $created = Mcp_Credentials::create('VS Code', 'vscode', []);
    $credential = Mcp_Credentials::verify($created['token']);

    TestCase::same([Mcp_Scopes::READ], $credential['scopes']);
    TestCase::same(true, Mcp_Credentials::has_scope($credential, Mcp_Scopes::READ));
    TestCase::same(false, Mcp_Credentials::has_scope($credential, Mcp_Scopes::WRITE));
}

/**
 * Free may not grant any write scope, so asking for one yields nothing rather
 * than a partially honoured request.
 */
function test_pageforge_mcp_credential_rejects_scopes_free_cannot_grant(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    $created = Mcp_Credentials::create('Hostile Client', 'hostile', [
        Mcp_Scopes::READ,
        Mcp_Scopes::ADMIN,
        Mcp_Scopes::WRITE,
        'delete_everything',
    ]);
    $credential = Mcp_Credentials::verify($created['token']);

    TestCase::same([Mcp_Scopes::READ], $credential['scopes']);
}

/**
 * Legacy tokens issued before the scope catalog existed stored bare names like
 * 'read'. They must keep working, mapped onto the catalog, rather than
 * silently losing all access on upgrade.
 */
function test_pageforge_mcp_credential_migrates_legacy_scope_names(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    $created = Mcp_Credentials::create('Legacy', 'generic', [Mcp_Scopes::READ]);

    $credentials = Mcp_Credentials::all();
    $credentials[$created['id']]['scopes'] = ['read', 'preview'];
    update_option('pageforge_mcp_credentials', $credentials, false);

    $credential = Mcp_Credentials::verify($created['token']);
    TestCase::same([Mcp_Scopes::READ, Mcp_Scopes::PREVIEW], $credential['scopes']);
}

/**
 * A token may not carry a scope its owner could not exercise directly,
 * otherwise a lower-privileged account could mint itself an escalation.
 */
function test_pageforge_mcp_credential_cannot_exceed_its_owners_capabilities(): void {
    pageforge_test_reset_state();
    // A subscriber-like account: can read, cannot edit or administer.
    $GLOBALS['pageforge_test_caps'] = [
        9 => ['read' => true, 'edit_posts' => false, 'manage_options' => false],
    ];
    wp_set_current_user(9);

    TestCase::same(false, Mcp_Credentials::user_may_hold_scope(9, Mcp_Scopes::ADMIN));
    TestCase::same(false, Mcp_Credentials::user_may_hold_scope(9, Mcp_Scopes::WRITE));
    TestCase::same(true, Mcp_Credentials::user_may_hold_scope(9, Mcp_Scopes::READ));
}

function test_pageforge_mcp_credential_redacted_list_never_contains_hash(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    Mcp_Credentials::create('IDE', 'vscode', [Mcp_Scopes::READ]);

    $redacted = Mcp_Credentials::all_redacted();
    $serialized = \wp_json_encode($redacted);

    TestCase::same(false, false !== strpos($serialized, 'token_hash'));
}

/**
 * A credential that has never been used is not a connection. The UI depends on
 * this distinction to stop claiming "Connected" the moment a token is minted.
 */
function test_pageforge_mcp_credential_starts_active_but_never_used(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    $created = Mcp_Credentials::create('IDE', 'vscode', [Mcp_Scopes::READ]);

    $status = Mcp_Credentials::all_redacted()[$created['id']]['status'];
    TestCase::same('never_used', $status['state']);
}

function test_pageforge_mcp_credential_reports_in_use_after_a_real_request(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    $created = Mcp_Credentials::create('IDE', 'vscode', [Mcp_Scopes::READ]);

    Mcp_Credentials::touch($created['id'], '203.0.113.0/24');

    $status = Mcp_Credentials::all_redacted()[$created['id']]['status'];
    TestCase::same('in_use', $status['state']);
}

function test_pageforge_mcp_credential_touch_records_last_used(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    $created = Mcp_Credentials::create('IDE', 'vscode', [Mcp_Scopes::READ]);

    TestCase::same(true, Mcp_Credentials::touch($created['id'], '203.0.113.0/24'));

    $stored = Mcp_Credentials::all()[$created['id']];
    TestCase::true($stored['last_used'] > 0);
    TestCase::same('203.0.113.0/24', $stored['last_used_ip']);
}

/**
 * The hot path must not write an option per request. Without this throttle a
 * busy agent turns every MCP call into a wp_options write.
 */
function test_pageforge_mcp_credential_touch_is_throttled_to_avoid_option_writes(): void {
    pageforge_test_reset_state();
    pageforge_pat_test_actor();
    $created = Mcp_Credentials::create('IDE', 'vscode', [Mcp_Scopes::READ]);

    TestCase::same(true, Mcp_Credentials::touch($created['id'], '203.0.113.0/24'));
    // Immediately again: inside the throttle window, so no write.
    TestCase::same(false, Mcp_Credentials::touch($created['id'], '203.0.113.0/24'));
    TestCase::same(false, Mcp_Credentials::touch($created['id'], '203.0.113.0/24'));
}
