<?php

function test_pageforge_admin_counts_legacy_published_posts_without_registered_post_type(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_post_counts']['pf_page'] = ['publish' => 4];

    $method = new \ReflectionMethod(\PageForge\Admin::class, 'count_published_posts');
    $method->setAccessible(true);

    \PageForge\Tests\TestCase::same(4, $method->invoke(null, ['pf_page']));
}
