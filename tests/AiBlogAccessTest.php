<?php

use PageForge\AI;
use PageForge\Tests\TestCase;

function pageforge_ai_blog_access_setup(bool $can_publish): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_caps'] = [
        5 => [
            'read' => true,
            'edit_posts' => true,
            'edit_post' => [100 => true],
            'publish_posts' => $can_publish,
        ],
    ];
    wp_set_current_user(5);
}

function test_pageforge_ai_blog_clamps_publish_to_draft_without_publish_capability(): void {
    pageforge_ai_blog_access_setup(false);

    $out = AI::generate_blog_draft([
        'topic' => 'Programmatic SEO',
        'post_type' => 'post',
        'status' => 'publish',
    ]);

    TestCase::same(false, isset($out['_error']));
    $inserted = end($GLOBALS['pageforge_test_inserted_posts']);
    TestCase::same('draft', $inserted['post_status']);
}

function test_pageforge_ai_blog_allows_publish_with_publish_capability(): void {
    pageforge_ai_blog_access_setup(true);

    $out = AI::generate_blog_draft([
        'topic' => 'Programmatic SEO',
        'post_type' => 'post',
        'status' => 'publish',
    ]);

    TestCase::same(false, isset($out['_error']));
    $inserted = end($GLOBALS['pageforge_test_inserted_posts']);
    TestCase::same('publish', $inserted['post_status']);
}

function test_pageforge_ai_blog_always_creates_a_post_and_saves_seo_metadata(): void {
    pageforge_ai_blog_access_setup(true);

    $out = AI::generate_blog_draft([
        'topic' => 'Programmatic SEO',
        'post_type' => 'page',
        'status' => 'draft',
    ]);

    TestCase::same(false, isset($out['_error']));
    $inserted = end($GLOBALS['pageforge_test_inserted_posts']);
    TestCase::same('post', $inserted['post_type']);
    TestCase::same('Generated Blog', get_post_meta((int) $out['post_id'], '_pageforge_meta_title', true));
    TestCase::same('Generated Blog', get_post_meta((int) $out['post_id'], '_pageforge_og_title', true));
}
