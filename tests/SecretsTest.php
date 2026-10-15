<?php

use PageForge\Secrets;
use PageForge\Tests\TestCase;

function test_pageforge_secrets_status_reports_not_configured_for_blank_value(): void {
    TestCase::same(['configured' => false, 'fingerprint' => ''], Secrets::status(''));
    TestCase::same(['configured' => false, 'fingerprint' => ''], Secrets::status('   '));
}

function test_pageforge_secrets_status_exposes_only_a_short_fingerprint(): void {
    $status = Secrets::status('sk-1234567890abcdef');

    TestCase::same(true, $status['configured']);
    TestCase::same('••••cdef', $status['fingerprint']);
    // The fingerprint must never contain the full secret.
    TestCase::same(false, false !== strpos($status['fingerprint'], 'sk-1234567890'));
}

function test_pageforge_secrets_redact_settings_never_carries_the_raw_value(): void {
    $redacted = Secrets::redact_settings([
        'openai_key' => 'sk-live-secret-value',
        'brand_context' => 'Friendly and direct',
    ]);

    TestCase::same(true, is_array($redacted['openai_key']));
    TestCase::same(true, $redacted['openai_key']['configured']);
    TestCase::same('Friendly and direct', $redacted['brand_context']);
    $serialized = wp_json_encode($redacted);
    TestCase::same(false, false !== strpos($serialized, 'sk-live-secret-value'));
}

function test_pageforge_secrets_resolve_incoming_keeps_existing_value_when_field_absent(): void {
    $resolved = Secrets::resolve_incoming([], 'openai_key', 'sk-existing', false);

    TestCase::same('sk-existing', $resolved);
}

function test_pageforge_secrets_resolve_incoming_keeps_existing_value_for_unchanged_sentinel(): void {
    $resolved = Secrets::resolve_incoming(
        ['openai_key' => Secrets::UNCHANGED],
        'openai_key',
        'sk-existing',
        true
    );

    TestCase::same('sk-existing', $resolved);
}

function test_pageforge_secrets_resolve_incoming_accepts_a_new_value(): void {
    $resolved = Secrets::resolve_incoming(
        ['openai_key' => 'sk-new-value'],
        'openai_key',
        'sk-existing',
        true
    );

    TestCase::same('sk-new-value', $resolved);
}

function test_pageforge_secrets_resolve_incoming_allows_explicit_removal(): void {
    $resolved = Secrets::resolve_incoming(
        ['openai_key' => ''],
        'openai_key',
        'sk-existing',
        true
    );

    TestCase::same('', $resolved);
}

function test_pageforge_secrets_field_placeholder_value_never_contains_the_secret(): void {
    TestCase::same(Secrets::UNCHANGED, Secrets::field_placeholder_value('sk-existing'));
    TestCase::same('', Secrets::field_placeholder_value(''));
}

function test_pageforge_secrets_keys_cover_every_known_credential(): void {
    $keys = Secrets::keys();

    foreach ([
        'openai_key', 'anthropic_key', 'gemini_key', 'deepseek_key',
        'custom_ai_key', 'pexels_key', 'google_sheets_api_key', 'ga4_api_secret',
    ] as $expected) {
        TestCase::true(in_array($expected, $keys, true), 'Missing secret key: ' . $expected);
    }
    TestCase::same(false, Secrets::is_secret_key('brand_context'));
    TestCase::same(true, Secrets::is_secret_key('openai_key'));
}
