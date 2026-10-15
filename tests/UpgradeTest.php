<?php

function test_pageforge_upgrade_clears_obsolete_mcp_failures_once(): void {
    pageforge_test_reset_state();
    set_transient('pageforge_mcp_failures', [['reason' => 'Malformed JSON.']], DAY_IN_SECONDS);

    \PageForge\Upgrade::run();

    \PageForge\Tests\TestCase::same([], \PageForge\Mcp_Server::recent_failures(5));
    \PageForge\Tests\TestCase::same('1.2.4', get_option('pageforge_maintenance_version'));

    set_transient('pageforge_mcp_failures', [['reason' => 'New failure']], DAY_IN_SECONDS);
    \PageForge\Upgrade::run();
    \PageForge\Tests\TestCase::same('New failure', \PageForge\Mcp_Server::recent_failures(1)[0]['reason']);
}

function test_pageforge_upgrade_persists_namespace_only_once(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_post_counts']['pf_template'] = 2;
    \PageForge\Upgrade::run();
    \PageForge\Tests\TestCase::same('pf', get_option('pageforge_content_namespace'));
    $GLOBALS['pageforge_test_post_counts']['pageforge_template'] = 5;
    \PageForge\Upgrade::run();
    \PageForge\Tests\TestCase::same('pf', get_option('pageforge_content_namespace'));
}

function test_pageforge_upgrade_detects_legacy_content_without_registered_post_type(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_post_counts']['pf_page'] = 3;

    \PageForge\Upgrade::run();

    \PageForge\Tests\TestCase::same('pf', get_option('pageforge_content_namespace'));
}

function test_pageforge_activation_selects_namespace_before_registering_cpts_and_flushing_rules(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_post_counts']['pf_page'] = 3;

    \PageForge\Plugin::instance()->activate();

    \PageForge\Tests\TestCase::same('pf', get_option('pageforge_content_namespace'));
    // A legacy pf site must register the pf types it actually owns, not a second
    // pageforge_* runtime, and the flush must land after registration.
    \PageForge\Tests\TestCase::same([
        'namespace',
        'register_pf_template',
        'register_pf_page',
        'flush',
    ], $GLOBALS['pageforge_test_events']);
}
