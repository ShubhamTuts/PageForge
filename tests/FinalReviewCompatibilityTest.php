<?php

use PageForge\Admin;
use PageForge\Compatibility;
use PageForge\Frontend;
use PageForge\Generator;
use PageForge\Plugin;
use PageForge\Template;
use PageForge\Tests\TestCase;
use PageForge\Upgrade;

function test_pageforge_free_provider_catalog_and_deepseek_final_content_mode_are_current(): void {
    $helpers = (string) file_get_contents(dirname(__DIR__) . '/includes/helpers.php');

    TestCase::true(false !== strpos($helpers, "'deepseek-v4-flash'"));
    TestCase::true(false !== strpos($helpers, "'gemini-3.1-pro-preview'"));
    TestCase::true(false !== strpos($helpers, "\$body['thinking'] = ['type' => 'disabled'];"));
    TestCase::true(false === strpos($helpers, "'gemini-3.1-pro' =>"));
    TestCase::true(false !== strpos($helpers, "'x-goog-api-key' => \$api_key"));
    TestCase::true(false === strpos($helpers, "':generateContent?key='"));
}

function test_pageforge_free_selected_pf_generation_uses_a_registered_cpt(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_options']['pageforge_content_namespace'] = 'pf';
    $template = pageforge_test_add_post(11, 'pf_template', 'Legacy template');
    $template->post_content = 'Book {Service} in {City}';
    $GLOBALS['pageforge_test_meta'][11] = [
        '_pageforge_post_type' => 'pageforge_page',
        '_pf_title_pattern' => '{Service} in {City}',
        '_pf_slug_pattern' => '{service}-in-{city}',
    ];

    $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
    $plugin->register_cpts();
    $result = (new Generator())->generate_one(11, ['Service' => 'Dentist', 'City' => 'Pune']);

    TestCase::same(false, is_wp_error($result));
    TestCase::same('pf_page', $GLOBALS['pageforge_test_inserted_posts'][0]['post_type']);
    TestCase::same('Pune', $GLOBALS['pageforge_test_meta'][(int) $result]['_pf_city'] ?? null);
    TestCase::same(false, isset($GLOBALS['pageforge_test_meta'][(int) $result]['_pageforge_city']));
}

function test_pageforge_free_registers_populated_alternates_with_hidden_duplicate_ui(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_options']['pageforge_content_namespace'] = 'pageforge';
    pageforge_test_add_post(11, 'pageforge_template', 'Free template');
    pageforge_test_add_post(12, 'pf_template', 'Pro template');
    pageforge_test_add_post(20, 'pageforge_page', 'Free generated');
    pageforge_test_add_post(21, 'pf_page', 'Pro generated');

    $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
    $plugin->register_cpts();

    TestCase::same(true, $GLOBALS['pageforge_test_post_type_args']['pageforge_template']['show_ui']);
    TestCase::same(false, $GLOBALS['pageforge_test_post_type_args']['pf_template']['show_ui']);
    TestCase::same(true, $GLOBALS['pageforge_test_post_type_args']['pageforge_page']['show_ui']);
    TestCase::same(false, $GLOBALS['pageforge_test_post_type_args']['pf_page']['show_ui']);
}

function test_pageforge_free_mixed_template_lists_and_shared_saves_use_compatibility(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_options']['pageforge_content_namespace'] = 'pf';
    pageforge_test_add_post(11, 'pf_template', 'Legacy template');
    pageforge_test_add_post(12, 'pageforge_template', 'Free template');
    pageforge_test_add_post(13, 'post', 'Not a template');

    TestCase::true(Template::get(11) instanceof WP_Post);
    TestCase::true(Template::get(12) instanceof WP_Post);
    TestCase::same(null, Template::get(13));

    $query_method = new ReflectionMethod(Admin::class, 'template_query_args');
    $query_method->setAccessible(true);
    $query_args = $query_method->invoke(null);
    TestCase::same(['pageforge_template', 'pf_template'], $query_args['post_type']);

    $_POST = [
        'pageforge_template_nonce' => 'valid',
        'pageforge_title_pattern' => '{Service} in {City}',
        'pageforge_slug_pattern' => '{service}-in-{city}',
        'pageforge_post_type' => 'pageforge_page',
        'pageforge_post_status' => 'draft',
        'pageforge_base_post_id' => '8',
    ];
    Admin::save_template_meta(11);
    $_POST = [
        'pageforge_seo_nonce' => 'valid',
        'pageforge_meta_title' => 'Dentist in Pune',
        'pageforge_meta_description' => 'Legacy context retained.',
        'pageforge_focus_keyword' => 'dentist pune',
    ];
    Admin::save_seo_meta(11);
    $_POST = [];

    TestCase::same('{Service} in {City}', $GLOBALS['pageforge_test_meta'][11]['_pf_title_pattern'] ?? null);
    TestCase::same('pf_page', $GLOBALS['pageforge_test_meta'][11]['_pf_post_type'] ?? null);
    TestCase::same('draft', $GLOBALS['pageforge_test_meta'][11]['_pf_default_status'] ?? null);
    TestCase::same('Dentist in Pune', $GLOBALS['pageforge_test_meta'][11]['_pf_meta_title'] ?? null);
    TestCase::same(false, isset($GLOBALS['pageforge_test_meta'][11]['_pageforge_title_pattern']));
}

function test_pageforge_free_duplicate_detection_covers_generated_types_and_native_marker_aliases(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_options']['pageforge_content_namespace'] = 'pf';
    $template = pageforge_test_add_post(11, 'pf_template', 'Legacy template');
    $template->post_content = 'Book {Service} in {City}';
    $GLOBALS['pageforge_test_meta'][11]['_pf_post_type'] = 'pf_page';
    pageforge_test_add_post(30, 'pageforge_page', 'Existing alias', 'dentist-in-pune-mh');
    $native = pageforge_test_add_post(31, 'page', 'Existing native', 'plumber-in-pune-mh');
    $GLOBALS['pageforge_test_meta'][$native->ID]['_pf_generated'] = 1;

    $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
    $plugin->register_cpts();
    $alias_result = (new Generator())->generate_one(11, ['Service' => 'Dentist', 'City' => 'Pune', 'State' => 'MH']);
    $native_result = (new Generator())->generate_one(11, ['Service' => 'Plumber', 'City' => 'Pune', 'State' => 'MH']);

    TestCase::same('duplicate', $alias_result->get_error_code());
    TestCase::same('duplicate', $native_result->get_error_code());
    TestCase::same([], $GLOBALS['pageforge_test_inserted_posts']);
}

function test_pageforge_free_sitemap_expands_only_generated_alias_requests_with_truthy_markers(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_post_types']['pageforge_page'] = true;
    $GLOBALS['pageforge_test_post_types']['pf_page'] = true;

    Frontend::sitemap(['post_type' => 'post']);
    $post_query = $GLOBALS['pageforge_test_query_args'][0];
    Frontend::sitemap(['post_type' => 'pf_page']);
    $generated_query = $GLOBALS['pageforge_test_query_args'][1];

    TestCase::same(['post'], $post_query['post_type']);
    TestCase::same(['pf_page', 'pageforge_page'], $generated_query['post_type']);
    TestCase::same(array_merge(['relation' => 'OR'], Compatibility::generated_marker_conditions()), $generated_query['meta_query']);
}

function test_pageforge_free_grouping_runtime_vars_and_schema_read_legacy_locations(): void {
    pageforge_test_reset_state();
    $post = pageforge_test_add_post(31, 'page', 'Dentist in Pune');
    $GLOBALS['pageforge_test_meta'][31] = [
        '_pf_generated' => 1,
        '_pf_city' => 'Pune',
        '_pf_state' => 'MH',
        '_pf_service' => 'Dentist',
    ];

    $group_method = new ReflectionMethod(Frontend::class, 'sitemap_group_label');
    $group_method->setAccessible(true);
    $vars_method = new ReflectionMethod(Frontend::class, 'get_vars_for_post');
    $vars_method->setAccessible(true);
    TestCase::same('Pune', $group_method->invoke(null, 31, 'city'));
    TestCase::same('Dentist', $vars_method->invoke(null, 31)['Service'] ?? null);

    $GLOBALS['post'] = $post;
    ob_start();
    Frontend::inject_schema();
    $schema = (string) ob_get_clean();
    unset($GLOBALS['post']);
    TestCase::true(false !== strpos($schema, 'Dentist in Pune'));
    TestCase::true(false !== strpos($schema, '"addressRegion":"MH"'));
}

function test_pageforge_free_mixed_custom_base_uses_one_collision_safe_resolver(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_options']['pageforge_content_namespace'] = 'pageforge';
    $GLOBALS['pageforge_test_settings']['cpt_base'] = 'locations';
    pageforge_test_add_post(30, 'pageforge_page', 'Selected unique', 'selected');
    pageforge_test_add_post(31, 'pf_page', 'Legacy unique', 'legacy');
    pageforge_test_add_post(32, 'pageforge_page', 'Selected duplicate', 'shared');
    pageforge_test_add_post(33, 'pf_page', 'Legacy duplicate', 'shared');

    $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
    $plugin->register_cpts();

    TestCase::same(false, $GLOBALS['pageforge_test_post_type_args']['pageforge_page']['rewrite']);
    TestCase::same(false, $GLOBALS['pageforge_test_post_type_args']['pf_page']['rewrite']);
    TestCase::same(1, count($GLOBALS['pageforge_test_rewrite_rules']));
    TestCase::same(['post_type' => 'pf_page', 'name' => 'legacy'], $plugin->resolve_generated_rewrite_request(['pageforge_generated_slug' => 'legacy']));
    TestCase::same(['post_type' => 'pageforge_page', 'name' => 'shared'], $plugin->resolve_generated_rewrite_request(['pageforge_generated_slug' => 'shared']));
    TestCase::same('https://example.test/locations/legacy/', $plugin->filter_generated_post_link('', get_post(31)));
}

function test_pageforge_free_normal_update_flushes_rewrites_once_after_registration(): void {
    pageforge_test_reset_state();
    $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
    $plugin->register_cpts();
    Upgrade::maybe_upgrade_rewrite_rules();
    Upgrade::maybe_upgrade_rewrite_rules();

    TestCase::same(1, count(array_filter($GLOBALS['pageforge_test_events'], static function ($event) { return 'flush' === $event; })));
    TestCase::true(array_search('register_pageforge_page', $GLOBALS['pageforge_test_events'], true) < array_search('flush', $GLOBALS['pageforge_test_events'], true));
    TestCase::same('1', get_option('pageforge_rewrite_rules_version'));
}

function test_pageforge_free_native_pf_markers_select_pf_namespace(): void {
    pageforge_test_reset_state();
    pageforge_test_add_post(41, 'page', 'Legacy native page');
    $GLOBALS['pageforge_test_meta'][41]['_pf_generated'] = 1;

    Upgrade::run();

    TestCase::same('pf', get_option('pageforge_content_namespace'));
}

function test_pageforge_free_native_pageforge_markers_win_mixed_namespace_selection(): void {
    pageforge_test_reset_state();
    pageforge_test_add_post(41, 'page', 'Legacy native page');
    pageforge_test_add_post(42, 'post', 'Free native post');
    $GLOBALS['pageforge_test_meta'][41]['_pf_generated'] = 1;
    $GLOBALS['pageforge_test_meta'][42]['_pageforge_generated_status'] = 'draft';

    Upgrade::run();

    TestCase::same('pageforge', get_option('pageforge_content_namespace'));
}

function test_pageforge_free_false_native_markers_do_not_select_pf_namespace(): void {
    pageforge_test_reset_state();
    pageforge_test_add_post(41, 'page', 'Not generated');
    $GLOBALS['pageforge_test_meta'][41] = [
        '_pf_generated' => '0',
        '_pageforge_generated_status' => 'trash',
    ];

    Upgrade::run();

    TestCase::same('pageforge', get_option('pageforge_content_namespace'));
}
