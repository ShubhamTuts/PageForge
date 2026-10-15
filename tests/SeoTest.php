<?php

use PageForge\Seo;
use PageForge\Tests\TestCase;

final class PageForge_Test_Aioseo_Service {
    public static $read = [];
    public static $written = [];

    public function getSeoData($post_id): array {
        return self::$read[(int) $post_id] ?? [];
    }

    public function updateSeoData($post_id, $fields): array {
        self::$written[(int) $post_id] = $fields;
        return ['updated' => true, 'post' => $fields];
    }
}

/**
 * SEO integration adapter.
 *
 * The failure these guard against is writing to the wrong plugin's meta keys:
 * the operator is told "saved" and the page does not change, because a different
 * plugin owns rendering. The second failure is PageForge emitting its own title
 * and description tags while another plugin is also emitting them.
 */

function pageforge_seo_setup(int $user_id = 6): void {
    pageforge_test_reset_state();
    pageforge_test_add_post(10, 'page', 'Plumber in Perth', 'plumber-perth', 'publish');
    pageforge_test_add_post(11, 'page', 'Plumber in Perth', 'plumber-perth-2', 'publish');
    pageforge_test_add_post(12, 'page', 'Private draft', 'private-draft', 'draft');
    $GLOBALS['pageforge_test_caps'] = [
        6 => [
            'read' => true, 'edit_posts' => true, 'publish_posts' => true, 'manage_options' => true,
            'read_post' => [10 => true, 11 => true, 12 => false],
            'edit_post' => [10 => true, 11 => true, 12 => false],
        ],
    ];
    wp_set_current_user($user_id);
}

// ------------------------------------------------------------ detection --

/** With no SEO plugin present PageForge owns rendering and uses its own keys. */
function test_pageforge_seo_defaults_to_native_when_no_plugin_is_active(): void {
    pageforge_seo_setup();

    TestCase::same(Seo::NATIVE, Seo::active());
    TestCase::same(true, Seo::pageforge_owns_output());
    TestCase::same([], Seo::detected());
    TestCase::same(true, Seo::is_writable(Seo::NATIVE));
}

/** AIOSEO needs its ability/service loaded; post-meta guessing stays disabled. */
function test_pageforge_seo_requires_aioseo_supported_api_for_writes(): void {
    pageforge_seo_setup();

    TestCase::same(false, Seo::is_writable(Seo::AIOSEO));
    TestCase::same(true, Seo::is_writable(Seo::YOAST));
    TestCase::same(true, Seo::is_writable(Seo::RANK_MATH));
    TestCase::same(true, Seo::is_writable(Seo::SEOPRESS));
}

/** Every writable integration must expose the fields PageForge writes. */
function test_pageforge_seo_key_map_covers_the_written_fields(): void {
    foreach ([Seo::YOAST, Seo::RANK_MATH, Seo::SEOPRESS, Seo::NATIVE] as $integration) {
        $map = Seo::key_map()[$integration];
        foreach (['title', 'description', 'focus_keyword'] as $field) {
            TestCase::true(isset($map[$field]), $integration . ' is missing the ' . $field . ' key');
        }
    }
}

// ------------------------------------------------------------- analysis --

function test_pageforge_seo_read_flags_missing_title_and_description(): void {
    pageforge_seo_setup();

    $view = Seo::read(10);

    TestCase::same(10, $view['post_id']);
    TestCase::same(false, $view['analysis']['has_seo_title']);
    TestCase::same(false, $view['analysis']['has_meta_description']);
    TestCase::true(in_array('missing_seo_title', $view['analysis']['issues'], true));
    TestCase::true(in_array('missing_meta_description', $view['analysis']['issues'], true));
}

function test_pageforge_seo_read_refuses_a_post_the_user_cannot_see(): void {
    pageforge_seo_setup();

    // Seo::read itself does not authorize; the MCP tool does. What matters here
    // is that a missing post is reported as such rather than throwing.
    TestCase::same('post_not_found', Seo::read(9999)['error']);
}

function test_pageforge_seo_flags_an_over_long_title(): void {
    pageforge_seo_setup();
    update_post_meta(10, '_pageforge_meta_title', str_repeat('a', 75));

    $issues = Seo::read(10)['analysis']['issues'];
    TestCase::true(in_array('seo_title_too_long', $issues, true));
    TestCase::same(false, in_array('missing_seo_title', $issues, true));
}

function test_pageforge_seo_flags_a_short_description(): void {
    pageforge_seo_setup();
    update_post_meta(10, '_pageforge_meta_description', 'Too short.');

    TestCase::true(in_array('meta_description_too_short', Seo::read(10)['analysis']['issues'], true));
}

/** Unresolved PageForge tokens in metadata are a generation fault. */
function test_pageforge_seo_flags_unresolved_variables_in_metadata(): void {
    pageforge_seo_setup();
    update_post_meta(10, '_pageforge_meta_title', 'Plumber in {City} services');

    TestCase::true(in_array('unresolved_variables_in_metadata', Seo::read(10)['analysis']['issues'], true));
}

function test_pageforge_seo_detects_unresolved_tokens_and_shortcodes(): void {
    TestCase::same(true, Seo::has_unresolved_variables('Plumber in {City}'));
    TestCase::same(true, Seo::has_unresolved_variables('Plumber in [pageforge var="City"]'));
    TestCase::same(true, Seo::has_unresolved_variables('Plumber in [pf var="City"]'));
    TestCase::same(false, Seo::has_unresolved_variables('Plumber in Perth'));
    TestCase::same(false, Seo::has_unresolved_variables(''));
}

/** Length is multibyte-safe, so a non-Latin title is not falsely over-length. */
function test_pageforge_seo_length_is_multibyte_safe(): void {
    TestCase::same(5, Seo::text_length('サイトです'));
    TestCase::same(5, Seo::text_length('  hello  '));
    TestCase::same(5, Seo::text_length('<b>hello</b>'));
}

// ---------------------------------------------------------------- write --

/**
 * A write must land on the active integration's keys AND PageForge's own, so
 * switching plugin later does not lose the data.
 */
function test_pageforge_seo_write_applies_to_native_and_pageforge_keys(): void {
    pageforge_seo_setup();

    $result = Seo::write(10, [
        'title' => 'Emergency Plumber in Perth',
        'description' => str_repeat('Fast local plumbing help across Perth. ', 3),
        'focus_keyword' => 'emergency plumber perth',
    ]);

    TestCase::same(false, \is_wp_error($result));
    TestCase::same('Emergency Plumber in Perth', get_post_meta(10, '_pageforge_meta_title', true));
    TestCase::same('emergency plumber perth', get_post_meta(10, '_pageforge_focus_keyword', true));
    TestCase::same(true, $result['applied'][Seo::NATIVE]['written']);
}

function test_pageforge_seo_write_supports_canonical_social_and_robots_fields(): void {
    pageforge_seo_setup();

    $result = Seo::write(10, [
        'canonical' => 'https://example.test/plumber-perth/',
        'og_title' => 'Plumber Perth on social',
        'og_description' => 'Fast local plumbing support across Perth.',
        'twitter_title' => 'Plumber Perth',
        'twitter_description' => 'Book a local plumber in Perth.',
        'noindex' => true,
        'nofollow' => false,
    ]);

    TestCase::same(false, \is_wp_error($result));
    TestCase::same('https://example.test/plumber-perth/', get_post_meta(10, '_pageforge_canonical', true));
    TestCase::same('Plumber Perth on social', get_post_meta(10, '_pageforge_og_title', true));
    TestCase::same('Book a local plumber in Perth.', get_post_meta(10, '_pageforge_twitter_description', true));
    TestCase::same(true, get_post_meta(10, '_pageforge_noindex', true));
    TestCase::same(false, (bool) get_post_meta(10, '_pageforge_nofollow', true));
}

/** Object authorization is enforced inside the adapter, not just at call sites. */
function test_pageforge_seo_write_refuses_a_post_the_user_cannot_edit(): void {
    pageforge_seo_setup();

    $result = Seo::write(12, ['title' => 'Should not apply']);

    TestCase::same(true, \is_wp_error($result));
    TestCase::same('cannot_edit', $result->get_error_code());
    TestCase::same('', get_post_meta(12, '_pageforge_meta_title', true));
}

/** Metadata is plain text: markup from a model must not become stored HTML. */
function test_pageforge_seo_write_strips_markup_from_metadata(): void {
    pageforge_seo_setup();

    Seo::write(10, ['title' => 'Plumber <script>alert(1)</script> Perth']);

    $stored = (string) get_post_meta(10, '_pageforge_meta_title', true);
    TestCase::same(false, false !== strpos($stored, '<script'));
    TestCase::same(false, false !== strpos($stored, 'alert(1)'));
}

function test_pageforge_seo_write_refuses_unsupported_fields_only(): void {
    pageforge_seo_setup();

    $result = Seo::write(10, ['not_a_field' => 'x']);

    TestCase::same(true, \is_wp_error($result));
    TestCase::same('nothing_to_write', $result->get_error_code());
}

function test_pageforge_seo_reads_and_writes_through_aioseo_service(): void {
    pageforge_seo_setup();
    PageForge_Test_Aioseo_Service::$read = [
        10 => [
            'title' => 'AIOSEO title',
            'description' => 'AIOSEO description',
            'focus_keyphrase' => 'plumber perth',
            'canonical_url' => 'https://example.test/plumber/',
            'robots' => ['noindex' => false, 'nofollow' => true],
            'social' => ['og_title' => 'Social title'],
        ],
    ];
    PageForge_Test_Aioseo_Service::$written = [];
    add_filter('pageforge_seo_detected_integrations', static function () {
        return [Seo::AIOSEO];
    });
    add_filter('pageforge_aioseo_service_class', static function () {
        return PageForge_Test_Aioseo_Service::class;
    });

    TestCase::same(true, Seo::is_writable(Seo::AIOSEO));
    $view = Seo::read(10);
    TestCase::same('AIOSEO title', $view['fields']['title']);
    TestCase::same(true, $view['fields']['nofollow']);
    TestCase::same('Social title', $view['fields']['og_title']);

    $result = Seo::write(10, [
        'title' => 'New title',
        'description' => 'New description',
        'focus_keyword' => 'new keyphrase',
        'canonical' => 'https://example.test/new/',
        'og_description' => 'New social description',
        'noindex' => true,
    ]);

    TestCase::same(false, \is_wp_error($result));
    TestCase::same(Seo::AIOSEO, $result['write_integration']);
    TestCase::same('new keyphrase', PageForge_Test_Aioseo_Service::$written[10]['focus_keyphrase']);
    TestCase::same('https://example.test/new/', PageForge_Test_Aioseo_Service::$written[10]['canonical_url']);
    TestCase::same('New social description', PageForge_Test_Aioseo_Service::$written[10]['social']['og_description']);
    TestCase::same(true, PageForge_Test_Aioseo_Service::$written[10]['robots']['noindex']);
    TestCase::same(false, PageForge_Test_Aioseo_Service::$written[10]['robots']['use_default']);
}

function test_pageforge_seo_prefers_aioseo_wordpress_abilities(): void {
    pageforge_seo_setup();
    add_filter('pageforge_seo_detected_integrations', static function () {
        return [Seo::AIOSEO];
    });
    $GLOBALS['pageforge_test_abilities']['aioseo-posts/seo-data-get'] = new PageForge_Test_Ability(
        static function ($input) {
            return [
                'title' => 'Ability title ' . (int) $input['postId'],
                'description' => 'Ability description',
                'robots' => [],
                'social' => [],
            ];
        }
    );
    $GLOBALS['pageforge_test_abilities']['aioseo-posts/seo-data-update'] = new PageForge_Test_Ability(
        static function ($input) {
            $GLOBALS['pageforge_test_aioseo_ability_write'] = $input;
            return ['updated' => true, 'post' => $input];
        }
    );

    TestCase::same('Ability title 10', Seo::read(10)['fields']['title']);
    $result = Seo::write(10, ['description' => 'Written through an ability']);

    TestCase::same(Seo::AIOSEO, $result['write_integration']);
    TestCase::same(10, $GLOBALS['pageforge_test_aioseo_ability_write']['postId']);
    TestCase::same('Written through an ability', $GLOBALS['pageforge_test_aioseo_ability_write']['description']);
}

// ---------------------------------------------------------------- audit --

function test_pageforge_seo_audit_counts_issues_and_withholds_private_posts(): void {
    pageforge_seo_setup();

    $audit = Seo::audit(['page'], 50);

    // Post 12 is unreadable for this user and must not appear.
    TestCase::same(1, $audit['withheld_by_permission']);
    TestCase::same(2, $audit['scanned']);
    TestCase::true((int) $audit['issue_counts']['missing_seo_title'] >= 2);
}

/** Duplicate titles are a property of the set, not of one row. */
function test_pageforge_seo_audit_detects_duplicate_titles(): void {
    pageforge_seo_setup();

    $audit = Seo::audit(['page'], 50);

    TestCase::same(1, count($audit['duplicate_titles']));
    TestCase::same([10, 11], $audit['duplicate_titles'][0]['post_ids']);
}

function test_pageforge_seo_audit_can_filter_to_one_issue(): void {
    pageforge_seo_setup();
    update_post_meta(10, '_pageforge_meta_title', 'A perfectly reasonable SEO title here');

    $audit = Seo::audit(['page'], 50, 'missing_seo_title');

    foreach ($audit['rows'] as $row) {
        TestCase::true(in_array('missing_seo_title', $row['issues'], true));
        TestCase::same(false, 10 === $row['post_id']);
    }
}

// ------------------------------------------------------------ image alt --

function test_pageforge_seo_image_alt_audit_finds_images_without_alt(): void {
    pageforge_seo_setup();
    $GLOBALS['pageforge_test_posts'][10]->post_content =
        '<img src="/a.jpg" alt="Described" /><img src="/b.jpg" /><img src="/c.jpg" alt="" />';

    $audit = Seo::image_alt_audit(10);

    TestCase::same(3, $audit['images_total']);
    TestCase::same(2, $audit['images_missing_alt']);
    TestCase::same('/b.jpg', $audit['missing'][0]['src']);
}

function test_pageforge_seo_image_alt_audit_includes_the_featured_image(): void {
    pageforge_seo_setup();
    $GLOBALS['pageforge_test_posts'][10]->post_content = '';
    $GLOBALS['pageforge_test_thumbnails'][10] = 900;

    $audit = Seo::image_alt_audit(10);

    TestCase::same(1, $audit['images_total']);
    TestCase::same(true, $audit['featured_image_missing_alt']);
    TestCase::same(1, $audit['images_missing_alt']);
}

function test_pageforge_seo_image_alt_audit_respects_read_permission(): void {
    pageforge_seo_setup();

    TestCase::same('post_not_found', Seo::image_alt_audit(12)['error']);
}
