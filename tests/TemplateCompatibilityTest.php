<?php

function test_pageforge_template_reads_legacy_title_pattern(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_meta'][9]['_pf_title_pattern'] = '{Service} in {City}';
    \PageForge\Tests\TestCase::same('{Service} in {City}', \PageForge\Template::title_pattern(9));
}

function test_pageforge_template_reads_legacy_meta_description_pattern(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_meta'][9]['_pf_meta_description_pattern'] = 'Call for {Service} in {City}.';
    \PageForge\Tests\TestCase::same('Call for {Service} in {City}.', \PageForge\Template::meta_description_pattern(9));
}

function test_pageforge_generator_resolves_meta_description_pattern_into_seo_storage(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_posts'][9] = (object) [
        'post_type' => 'pageforge_template',
        'post_content' => 'Local {Service}',
        'post_excerpt' => '',
    ];
    $GLOBALS['pageforge_test_meta'][9]['_pageforge_title_pattern'] = '{Service} in {City}';
    $GLOBALS['pageforge_test_meta'][9]['_pageforge_slug_pattern'] = '{service}-in-{city}';
    $GLOBALS['pageforge_test_meta'][9]['_pageforge_meta_description_pattern'] = 'Need {Service} in {City}? Call {Phone}.';

    (new \ReflectionClass(\PageForge\Plugin::class))->newInstanceWithoutConstructor()->register_cpts();
    (new \PageForge\Generator())->generate_one(9, [
        'Service' => 'Dentist',
        'City' => 'Pune',
        'Phone' => '555-0100',
    ]);

    $generated_ids = array_values(array_filter(array_keys($GLOBALS['pageforge_test_meta']), static function ($id): bool { return (int) $id !== 9; }));
    $post_id = (int) ($generated_ids[0] ?? 0);
    \PageForge\Tests\TestCase::same('Need Dentist in Pune? Call 555-0100.', $GLOBALS['pageforge_test_meta'][$post_id]['_pageforge_meta_description'] ?? null);
}

function test_pageforge_generator_uses_defaults_for_empty_template_patterns(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_posts'][9] = (object) [
        'post_type' => 'pageforge_template',
        'post_content' => '',
        'post_excerpt' => '',
    ];
    $GLOBALS['pageforge_test_meta'][9]['_pageforge_title_pattern'] = '';
    $GLOBALS['pageforge_test_meta'][9]['_pageforge_slug_pattern'] = '';

    (new \ReflectionClass(\PageForge\Plugin::class))->newInstanceWithoutConstructor()->register_cpts();

    (new \PageForge\Generator())->generate_one(9, [
        'Service' => 'Dentist',
        'City' => 'Pune',
        'State' => 'MH',
    ]);

    \PageForge\Tests\TestCase::same('Dentist in Pune, MH', $GLOBALS['pageforge_test_inserted_posts'][0]['post_title']);
    \PageForge\Tests\TestCase::same('dentist-in-pune-mh', $GLOBALS['pageforge_test_inserted_posts'][0]['post_name']);
}
