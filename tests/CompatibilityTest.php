<?php
use PageForge\Compatibility;
use PageForge\Tests\TestCase;

function test_pageforge_new_site_selects_pageforge_namespace(): void {
    pageforge_test_reset_state();
    TestCase::same('pageforge', Compatibility::select_namespace([
        'pageforge_templates' => 0,
        'pageforge_pages' => 0,
        'pf_templates' => 0,
        'pf_pages' => 0,
    ]));
}

function test_pageforge_legacy_pro_site_keeps_pf_namespace(): void {
    pageforge_test_reset_state();
    TestCase::same('pf', Compatibility::select_namespace([
        'pageforge_templates' => 0,
        'pageforge_pages' => 0,
        'pf_templates' => 2,
        'pf_pages' => 40,
    ]));
}

function test_pageforge_free_content_wins_on_mixed_site(): void {
    pageforge_test_reset_state();
    TestCase::same('pageforge', Compatibility::select_namespace([
        'pageforge_templates' => 1,
        'pageforge_pages' => 5,
        'pf_templates' => 3,
        'pf_pages' => 20,
    ]));
}

function test_pageforge_read_meta_falls_back_to_legacy_alias(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_meta'][71]['_pf_vars'] = ['City' => 'Pune'];
    TestCase::same(['City' => 'Pune'], Compatibility::read_meta(71, 'vars', []));
}

function test_pageforge_write_meta_uses_selected_namespace(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_options']['pageforge_content_namespace'] = 'pageforge';
    TestCase::true(Compatibility::write_meta(71, 'generated', 1));
    TestCase::same(1, $GLOBALS['pageforge_test_meta'][71]['_pageforge_generated']);
}
