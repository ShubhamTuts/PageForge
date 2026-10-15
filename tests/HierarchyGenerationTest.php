<?php

use PageForge\Compatibility;
use PageForge\Generator;
use PageForge\Tests\TestCase;

function pageforge_hierarchy_fixture(): void {
    pageforge_test_reset_state();
    pageforge_test_add_post(9, 'pageforge_template', 'Locations');
    $GLOBALS['pageforge_test_settings']['post_type'] = 'page';
    Compatibility::write_meta(9, 'title_pattern', '{City}');
    Compatibility::write_meta(9, 'slug_pattern', '{city}');
    pageforge_test_add_post(20, 'page', 'Maths Tuition Gurgaon', 'maths-tuition-gurgaon');
    pageforge_test_add_post(21, 'page', 'English Tuition Gurgaon', 'english-tuition-gurgaon');
}

function test_pageforge_new_template_inherits_native_page_and_explicit_cpt_is_preserved(): void {
    pageforge_hierarchy_fixture();
    TestCase::same('page', Generator::destination(9)['post_type']);
    Compatibility::write_meta(9, 'post_type', 'pageforge_page');
    (new ReflectionClass(PageForge\Plugin::class))->newInstanceWithoutConstructor()->register_cpts();
    TestCase::same('pageforge_page', Generator::destination(9)['post_type']);
}

function test_pageforge_parent_path_preview_and_insert_match_without_changing_clone(): void {
    pageforge_hierarchy_fixture();
    Compatibility::write_meta(9, 'parent_page_id', 20);
    $preview = (new Generator())->preview_one(9, ['City' => 'DLF Phase IV']);
    TestCase::same(20, $preview['post_parent']);
    TestCase::same('/maths-tuition-gurgaon/dlf-phase-iv/', $preview['expected_path']);
    $id = (new Generator())->generate_one(9, ['City' => 'DLF Phase IV']);
    TestCase::same(false, is_wp_error($id));
    TestCase::same('page', get_post($id)->post_type);
    TestCase::same(20, get_post($id)->post_parent);
    TestCase::same(get_permalink($id), Compatibility::read_meta($id, 'canonical'));
}

function test_pageforge_identical_leaf_under_different_parents_and_same_parent_duplicate(): void {
    pageforge_hierarchy_fixture();
    Compatibility::write_meta(9, 'parent_page_id', 20);
    $first = (new Generator())->generate_one(9, ['City' => 'DLF Phase IV']);
    TestCase::same(false, is_wp_error($first));
    $again = (new Generator())->generate_one(9, ['City' => 'DLF Phase IV']);
    TestCase::same('duplicate', $again->get_error_code());
    Compatibility::write_meta(9, 'parent_page_id', 21);
    $preflight = (new Generator())->validate_rows(9, [['City' => 'DLF Phase IV']]);
    TestCase::same(true, $preflight['safe_to_generate']);
    $second = (new Generator())->generate_one(9, ['City' => 'DLF Phase IV']);
    TestCase::same(false, is_wp_error($second));
    TestCase::same(21, get_post($second)->post_parent);
}

function test_pageforge_native_post_ignores_parent_and_root_page_has_no_prefix(): void {
    pageforge_hierarchy_fixture();
    TestCase::same('/dlf-phase-iv/', (new Generator())->preview_one(9, ['City' => 'DLF Phase IV'])['expected_path']);
    Compatibility::write_meta(9, 'post_type', 'post');
    Compatibility::write_meta(9, 'parent_page_id', 999);
    Compatibility::write_meta(9, 'parent_source', 'dynamic');
    $id = (new Generator())->generate_one(9, ['City' => 'DLF Phase IV']);
    TestCase::same('post', get_post($id)->post_type);
    TestCase::same(0, get_post($id)->post_parent);
}

function test_pageforge_invalid_and_unauthorized_parents_fail_before_insert(): void {
    foreach (['999', '-20', '20x', '1.5'] as $value) {
        pageforge_hierarchy_fixture();
        Compatibility::write_meta(9, 'parent_page_id', $value);
        TestCase::same('invalid_parent', (new Generator())->generate_one(9, ['City' => 'DLF Phase IV'])->get_error_code());
        TestCase::same(false, (new Generator())->validate_rows(9, [['City' => 'DLF Phase IV']])['safe_to_generate']);
        TestCase::same([], $GLOBALS['pageforge_test_inserted_posts']);
    }
    pageforge_hierarchy_fixture();
    Compatibility::write_meta(9, 'parent_page_id', 20);
    $GLOBALS['pageforge_test_caps'][0] = ['read_post' => [9 => true, 20 => false], 'edit_post' => true];
    TestCase::same('invalid_parent', Generator::destination(9)->get_error_code());
}

function test_pageforge_legacy_parent_meta_and_corrupt_hierarchy(): void {
    pageforge_hierarchy_fixture();
    $GLOBALS['pageforge_test_meta'][9]['_pf_parent_page_id'] = 20;
    TestCase::same(20, Generator::destination(9)['post_parent']);
    get_post(20)->post_parent = 21;
    get_post(21)->post_parent = 20;
    TestCase::same('invalid_parent', Generator::destination(9)->get_error_code());
}

function test_pageforge_free_dynamic_parent_mapping_is_rejected_at_execution_and_preview(): void {
    pageforge_hierarchy_fixture();
    Compatibility::write_meta(9, 'parent_source', 'dynamic');
    TestCase::same('premium_parent_mapping', (new Generator())->generate_one(9, ['City' => 'DLF', 'Parent ID' => '20'])->get_error_code());
    TestCase::same('premium_parent_mapping', (new Generator())->preview_one(9, ['City' => 'DLF', 'Parent ID' => '20'])->get_error_code());
    TestCase::same([], $GLOBALS['pageforge_test_inserted_posts']);
}

function test_pageforge_invalid_target_and_template_permissions_fail_before_insert(): void {
    pageforge_hierarchy_fixture();
    Compatibility::write_meta(9, 'post_type', 'attachment');
    TestCase::same('invalid_post_type', Generator::destination(9)->get_error_code());
    Compatibility::write_meta(9, 'post_type', 'page');
    $GLOBALS['pageforge_test_caps'][0] = ['read_post' => true, 'edit_post' => false, 'edit_posts' => true];
    TestCase::same('forbidden_generation', (new Generator())->generate_one(9, ['City' => 'DLF'])->get_error_code());
    TestCase::same('forbidden_generation', (new Generator())->preview_one(9, ['City' => 'DLF'])->get_error_code());
    TestCase::same(false, (new Generator())->validate_rows(9, [['City' => 'DLF']])['safe_to_generate']);
}

function test_pageforge_parent_rejects_non_page_deleted_or_trashed_and_clone_is_independent(): void {
    pageforge_hierarchy_fixture();
    Compatibility::write_meta(9, 'parent_page_id', 20);
    get_post(20)->post_type = 'post';
    TestCase::same('invalid_parent', Generator::destination(9)->get_error_code());
    get_post(20)->post_type = 'page';
    get_post(20)->post_status = 'trash';
    TestCase::same('invalid_parent', Generator::destination(9)->get_error_code());
    get_post(20)->post_status = 'publish';
    Compatibility::write_meta(9, 'base_post_id', 21);
    get_post(21)->post_content = 'Cloned {City} layout';
    $id = (new Generator())->generate_one(9, ['City' => 'DLF']);
    TestCase::same(20, get_post($id)->post_parent);
    TestCase::same('Cloned DLF layout', get_post($id)->post_content);
}

function test_pageforge_generation_clamps_template_publish_status_for_non_publisher(): void {
    pageforge_hierarchy_fixture();
    Compatibility::write_meta(9, 'default_status', 'publish');
    $GLOBALS['pageforge_test_caps'][0] = ['read_post' => true, 'edit_post' => true, 'edit_posts' => true, 'publish_posts' => false];
    $preview = (new Generator())->preview_one(9, ['City' => 'DLF']);
    TestCase::same('draft', $preview['status']);
    $id = (new Generator())->generate_one(9, ['City' => 'DLF']);
    TestCase::same('draft', get_post($id)->post_status);
}

function test_pageforge_saving_inherit_clears_both_post_type_aliases(): void {
    pageforge_hierarchy_fixture();
    $GLOBALS['pageforge_test_meta'][9]['_pf_post_type'] = 'pf_page';
    $GLOBALS['pageforge_test_meta'][9]['_pageforge_post_type'] = 'pageforge_page';
    $before = $_POST;
    try {
        $_POST = ['pageforge_template_nonce' => 'valid', 'pageforge_post_type' => '', 'pageforge_parent_page_id' => '20'];
        PageForge\Admin::save_template_meta(9);
        TestCase::same(false, metadata_exists('post', 9, '_pf_post_type'));
        TestCase::same(false, metadata_exists('post', 9, '_pageforge_post_type'));
        TestCase::same('page', Generator::destination(9)['post_type']);
        TestCase::same(20, Generator::destination(9)['post_parent']);
        $_POST['pageforge_parent_page_id'] = '-20';
        PageForge\Admin::save_template_meta(9);
        TestCase::same('invalid_parent', Generator::destination(9)->get_error_code());
    } finally {
        $_POST = $before;
    }
}

function test_pageforge_native_page_checks_actual_post_url_instead_of_leaf_only(): void {
    pageforge_hierarchy_fixture();
    $GLOBALS['pageforge_test_options']['permalink_structure'] = '/%postname%/';
    pageforge_test_add_post(30, 'post', 'DLF', 'dlf');
    $GLOBALS['pageforge_test_permalinks'][30] = 'https://example.test/dlf/';
    TestCase::same('duplicate', (new Generator())->generate_one(9, ['City' => 'DLF'])->get_error_code());
    $GLOBALS['pageforge_test_permalinks'][30] = 'https://example.test/blog/dlf/';
    TestCase::same(false, is_wp_error((new Generator())->generate_one(9, ['City' => 'DLF'])));
}

function test_pageforge_automatic_canonical_follows_permalink_but_custom_override_is_preserved(): void {
    pageforge_hierarchy_fixture();
    $id = (new Generator())->generate_one(9, ['City' => 'DLF']);
    $post = get_post($id);
    $current = 'https://example.test/new-base/dlf/';
    TestCase::same($current, PageForge\Frontend::filter_canonical_url($current, $post));
    Compatibility::write_meta($id, 'canonical', 'https://example.test/custom-canonical/');
    TestCase::same('https://example.test/custom-canonical/', PageForge\Frontend::filter_canonical_url($current, $post));
}

function test_pageforge_direct_bulk_generation_preserves_free_100_row_limit(): void {
    pageforge_hierarchy_fixture();
    $rows = [];
    for ($i = 0; $i < 101; $i++) $rows[] = ['City' => 'Location-' . $i];
    $result = (new Generator())->bulk_generate(9, $rows);
    TestCase::same(100, $result['processed']);
    TestCase::same(100, count($GLOBALS['pageforge_test_inserted_posts']));
    TestCase::same(1, count($result['errors']));
}
