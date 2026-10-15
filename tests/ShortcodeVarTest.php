<?php

use PageForge\Frontend;
use PageForge\Tests\TestCase;

/**
 * Both the current and legacy variable tags must resolve through one service
 * and stay safe for unknown, empty, zero and hostile values.
 */
function pageforge_shortcode_var_fixture(int $post_id, array $meta): void {
    pageforge_test_reset_state();
    pageforge_test_add_post($post_id, 'page', 'Fixture');
    $GLOBALS['pageforge_test_meta'][$post_id] = $meta;
    $GLOBALS['pageforge_test_current_post_id'] = $post_id;
}

function test_pageforge_var_shortcode_resolves_current_namespace_values(): void {
    pageforge_shortcode_var_fixture(31, [
        '_pageforge_generated' => 1,
        '_pageforge_vars' => ['City' => 'Pune', 'Service' => 'Dentist'],
    ]);

    TestCase::same('Pune', Frontend::shortcode_var(['var' => 'City'], '', 'pageforge'));
    TestCase::same('Dentist', Frontend::shortcode_var(['var' => 'Service'], '', 'pf'));
}

function test_pageforge_var_shortcode_reads_legacy_storage_for_both_tags(): void {
    pageforge_shortcode_var_fixture(31, [
        '_pf_generated' => 1,
        '_pf_city' => 'Mumbai',
        '_pf_service' => 'Plumber',
    ]);

    TestCase::same('Mumbai', Frontend::shortcode_var(['var' => 'City'], '', 'pf'));
    TestCase::same('Mumbai', Frontend::shortcode_var(['var' => 'city'], '', 'pageforge'));
    TestCase::same('Plumber', Frontend::shortcode_var(['var' => 'SERVICE'], '', 'pf'));
}

function test_pageforge_var_shortcode_is_quiet_for_unknown_and_empty_values(): void {
    pageforge_shortcode_var_fixture(31, [
        '_pageforge_generated' => 1,
        '_pageforge_vars' => ['City' => 'Pune', 'Suburb' => ''],
    ]);

    TestCase::same('', Frontend::shortcode_var(['var' => 'Country'], '', 'pageforge'));
    TestCase::same('n/a', Frontend::shortcode_var(['var' => 'Country', 'fallback' => 'n/a'], '', 'pf'));
    TestCase::same('n/a', Frontend::shortcode_var(['var' => 'Suburb', 'fallback' => 'n/a'], '', 'pageforge'));
    TestCase::same('', Frontend::shortcode_var([], '', 'pageforge'));
}

function test_pageforge_var_shortcode_keeps_numeric_zero(): void {
    pageforge_shortcode_var_fixture(31, [
        '_pageforge_generated' => 1,
        '_pageforge_vars' => ['Floor' => '0'],
    ]);

    TestCase::same('0', Frontend::shortcode_var(['var' => 'Floor', 'fallback' => 'n/a'], '', 'pageforge'));
}

function test_pageforge_var_shortcode_escapes_markup_and_preserves_unicode(): void {
    pageforge_shortcode_var_fixture(31, [
        '_pageforge_generated' => 1,
        '_pageforge_vars' => [
            'City' => '<script>alert("x")</script>',
            'Suburb' => 'Ōtāhuhu',
        ],
    ]);

    TestCase::same(
        '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;',
        Frontend::shortcode_var(['var' => 'City'], '', 'pageforge')
    );
    TestCase::same('Ōtāhuhu', Frontend::shortcode_var(['var' => 'Suburb'], '', 'pf'));
}

function test_pageforge_var_shortcode_returns_fallback_without_a_current_post(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_current_post_id'] = 0;

    TestCase::same('n/a', Frontend::shortcode_var(['var' => 'City', 'fallback' => 'n/a'], '', 'pageforge'));
}
