<?php

use PageForge\Access;
use PageForge\Tests\TestCase;

/**
 * Object-level authorization.
 *
 * These exist because a blanket `edit_posts` check answers "may this user edit
 * something?", not "may this user edit *this*". Every case below was reachable
 * before: an account with ordinary edit rights could write metadata onto another
 * author's private draft, publish content it had no right to publish, or have
 * PageForge read an arbitrary attachment from the media library.
 */

/** An author: may edit its own post 10, may not touch post 20, cannot publish. */
function pageforge_access_setup(): void {
    pageforge_test_reset_state();
    pageforge_test_add_post(10, 'page', 'Own page', 'own', 'draft');
    pageforge_test_add_post(20, 'page', 'Someone else', 'theirs', 'private');
    $GLOBALS['pageforge_test_caps'] = [
        5 => [
            'read' => true,
            'edit_posts' => true,
            'publish_posts' => false,
            'edit_post' => [10 => true, 20 => false],
            'read_post' => [10 => true, 20 => false],
        ],
        // An editor: full rights over both.
        6 => [
            'read' => true,
            'edit_posts' => true,
            'publish_posts' => true,
            'edit_post' => [10 => true, 20 => true],
            'read_post' => [10 => true, 20 => true],
        ],
    ];
    wp_set_current_user(5);
}

function test_pageforge_access_edit_is_evaluated_per_object(): void {
    pageforge_access_setup();

    TestCase::same(true, Access::can_edit_post(10));
    TestCase::same(false, Access::can_edit_post(20));
}

function test_pageforge_access_read_is_evaluated_per_object(): void {
    pageforge_access_setup();

    TestCase::same(true, Access::can_read_post(10));
    TestCase::same(false, Access::can_read_post(20));
}

/** A nonexistent object is never authorized, regardless of capability. */
function test_pageforge_access_rejects_a_missing_object(): void {
    pageforge_access_setup();
    wp_set_current_user(6);

    TestCase::same(false, Access::can_edit_post(9999));
    TestCase::same(false, Access::can_read_post(9999));
    TestCase::same(false, Access::can_edit_post(0));
}

/**
 * Editing is not publishing. WordPress separates them, so a feature accepting a
 * status parameter has to check the publish capability separately or it grants
 * publishing rights to anyone who can edit.
 */
function test_pageforge_access_publishing_requires_the_publish_capability(): void {
    pageforge_access_setup();

    TestCase::same(false, Access::can_use_status('page', 'publish'));
    TestCase::same(false, Access::can_use_status('page', 'private'));
    TestCase::same(false, Access::can_use_status('page', 'future'));
    // Draft and pending only need edit rights.
    TestCase::same(true, Access::can_use_status('page', 'draft'));
    TestCase::same(true, Access::can_use_status('page', 'pending'));
}

function test_pageforge_access_publishing_is_allowed_for_a_publisher(): void {
    pageforge_access_setup();
    wp_set_current_user(6);

    TestCase::same(true, Access::can_use_status('page', 'publish'));
    TestCase::same(true, Access::can_use_status('page', 'private'));
}

/**
 * A requested status is reduced rather than refused, so a legitimate
 * lower-privileged workflow still completes — as drafts.
 */
function test_pageforge_access_clamps_a_status_the_user_cannot_use(): void {
    pageforge_access_setup();

    TestCase::same('draft', Access::clamp_status('page', 'publish'));
    TestCase::same('draft', Access::clamp_status('page', 'private'));
    TestCase::same('pending', Access::clamp_status('page', 'pending'));
    // An unrecognised status is never trusted through.
    TestCase::same('draft', Access::clamp_status('page', 'trash'));
    TestCase::same('draft', Access::clamp_status('page', 'anything-else'));
}

function test_pageforge_access_does_not_clamp_for_a_publisher(): void {
    pageforge_access_setup();
    wp_set_current_user(6);

    TestCase::same('publish', Access::clamp_status('page', 'publish'));
}

/**
 * Bulk operations must authorize every target. Sampling one and applying to all
 * is how a batch job escalates a single permission into a site-wide rewrite.
 */
function test_pageforge_access_partitions_a_bulk_set_by_permission(): void {
    pageforge_access_setup();

    $partition = Access::partition_editable([10, 20, 9999, 0]);

    TestCase::same([10], $partition['allowed']);
    TestCase::same([20, 9999], $partition['denied']);
}

function test_pageforge_access_partitions_readable_separately_from_editable(): void {
    pageforge_access_setup();

    $partition = Access::partition_readable([10, 20]);
    TestCase::same([10], $partition['allowed']);
    TestCase::same([20], $partition['denied']);
}

// --------------------------------------------------------- data sources --

/**
 * An attachment id from the request is an arbitrary object reference. Passing it
 * straight to get_attached_file() let any caller have PageForge open and parse
 * the contents of any media item on the site.
 */
function pageforge_access_seed_attachment(int $id, string $filename, string $contents, bool $readable = true): string {
    $path = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($path, $contents);
    $post = pageforge_test_add_post($id, 'attachment', $filename, $filename, 'inherit');
    $GLOBALS['pageforge_test_attached_files'][$id] = $path;
    $GLOBALS['pageforge_test_caps'][5]['read_post'][$id] = $readable;
    $GLOBALS['pageforge_test_caps'][6]['read_post'][$id] = true;
    return $path;
}

function test_pageforge_access_resolves_a_readable_csv_data_source(): void {
    pageforge_access_setup();
    $path = pageforge_access_seed_attachment(30, 'pf-access-ok.csv', "City\nPerth\n", true);

    $resolved = Access::resolve_data_source(30);

    TestCase::same(false, \is_wp_error($resolved));
    TestCase::same(realpath($path), $resolved);
    @unlink($path);
}

function test_pageforge_access_refuses_an_attachment_the_user_cannot_read(): void {
    pageforge_access_setup();
    $path = pageforge_access_seed_attachment(31, 'pf-access-private.csv', "City\nPerth\n", false);

    $resolved = Access::resolve_data_source(31);

    TestCase::same(true, \is_wp_error($resolved));
    // Same code as "does not exist", so the media library cannot be enumerated.
    TestCase::same('invalid_source', $resolved->get_error_code());
    @unlink($path);
}

/** Only data-source file types may be parsed, whatever the attachment is. */
function test_pageforge_access_refuses_a_non_csv_attachment(): void {
    pageforge_access_setup();
    $path = pageforge_access_seed_attachment(32, 'pf-access-secret.php', "<?php echo 'x';", true);

    $resolved = Access::resolve_data_source(32);

    TestCase::same(true, \is_wp_error($resolved));
    TestCase::same('invalid_source_type', $resolved->get_error_code());
    @unlink($path);
}

function test_pageforge_access_refuses_a_post_that_is_not_an_attachment(): void {
    pageforge_access_setup();

    $resolved = Access::resolve_data_source(10);

    TestCase::same(true, \is_wp_error($resolved));
    TestCase::same('invalid_source', $resolved->get_error_code());
}

function test_pageforge_access_refuses_a_missing_data_source(): void {
    pageforge_access_setup();

    TestCase::same('missing_source', Access::resolve_data_source(0)->get_error_code());
    TestCase::same('invalid_source', Access::resolve_data_source(4242)->get_error_code());
}
