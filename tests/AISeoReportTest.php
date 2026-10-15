<?php

use PageForge\AI;
use PageForge\Tests\TestCase;

function pageforge_ai_seo_report_setup(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_settings'] = [];
}

function test_pageforge_ai_seo_report_accepts_markdown_and_builds_download_json(): void {
    pageforge_ai_seo_report_setup();
    $GLOBALS['pageforge_test_ai_chat_response'] =
        "# Executive Summary\nImprove technical foundations first.\n\n## Quick Wins\n- Repair missing metadata.\n\n## 30-Day Plan\n- Week 1: audit.";

    $report = AI::generate_seo_report(['include_urls' => false]);

    TestCase::same(false, isset($report['_error']));
    TestCase::same(2, $report['data']['format_version']);
    TestCase::true(false !== strpos($report['data']['markdown'], 'technical foundations'));
    TestCase::true(count($report['data']['sections']) >= 2);
    TestCase::same($report, AI::last_seo_report());
}

function test_pageforge_ai_seo_report_accepts_legacy_provider_json_without_failing(): void {
    pageforge_ai_seo_report_setup();
    $GLOBALS['pageforge_test_ai_chat_response'] = json_encode([
        'summary' => 'Prioritize crawlability.',
        'quick_wins' => ['Fix missing titles', 'Add image ALT text'],
    ]);

    $report = AI::generate_seo_report(['include_urls' => false]);

    TestCase::same(false, isset($report['_error']));
    TestCase::true(false !== strpos($report['data']['markdown'], 'Prioritize crawlability'));
    TestCase::true(false !== strpos($report['data']['markdown'], 'Fix missing titles'));
}

function test_pageforge_ai_seo_report_surfaces_provider_errors(): void {
    pageforge_ai_seo_report_setup();
    $GLOBALS['pageforge_test_ai_chat_response'] = new WP_Error('provider_error', 'Provider rejected the request.');

    $report = AI::generate_seo_report(['include_urls' => false]);

    TestCase::same('Provider rejected the request.', $report['_error']);
    TestCase::same([], AI::last_seo_report());
}
