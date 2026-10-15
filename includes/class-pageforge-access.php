<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * Object-level authorization for PageForge.
 *
 * A blanket `current_user_can('edit_posts')` answers "may this user edit
 * something?", not "may this user edit *this*". Every PageForge entry point that
 * accepts an object id from the request therefore routes through here, so the
 * rule is written once and cannot drift between AJAX, REST, MCP and cron.
 *
 * Three distinct questions, deliberately kept apart:
 *
 *   - can_read_post()      may the acting user see this object at all
 *   - can_edit_post()      may they modify it
 *   - can_use_status()     may they move content *into* a given status
 *
 * The third exists because WordPress separates editing from publishing. An
 * author-level account can draft but not publish, so a feature that accepts a
 * `status` parameter must check the publish capability separately or it hands
 * out publishing rights to anyone who can edit.
 */
final class Access {
    /**
     * Statuses that make content publicly reachable or otherwise require the
     * publish capability. `private` is included because it is a published state
     * in WordPress terms, not a draft.
     */
    public const PUBLISHING_STATUSES = ['publish', 'private', 'future'];

    /**
     * May the acting user read this specific object?
     *
     * `read_post` is the correct primitive: it accounts for post status, post
     * type and ownership, so a draft belonging to someone else is refused
     * without this code needing to know the rules.
     */
    public static function can_read_post(int $post_id): bool {
        if ($post_id <= 0 || !\get_post($post_id)) {
            return false;
        }
        return \current_user_can('read_post', $post_id);
    }

    public static function can_edit_post(int $post_id): bool {
        if ($post_id <= 0 || !\get_post($post_id)) {
            return false;
        }
        return \current_user_can('edit_post', $post_id);
    }

    /**
     * May the acting user place content of this post type into this status?
     *
     * Resolved from the post type's own capability map rather than assuming the
     * `publish_posts` name, so custom post types with custom capabilities are
     * honoured instead of silently falling through to a check that always
     * passes.
     */
    public static function can_use_status(string $post_type, string $status): bool {
        $status = \sanitize_key($status);
        if (!in_array($status, self::PUBLISHING_STATUSES, true)) {
            // draft / pending only need edit rights, checked separately.
            return true;
        }

        $object = \get_post_type_object($post_type);
        $cap = 'publish_posts';
        if (is_object($object) && isset($object->cap->publish_posts)) {
            $cap = (string) $object->cap->publish_posts;
        }
        return \current_user_can($cap);
    }

    /**
     * Reduce a requested status to one the acting user is allowed to use.
     * Returning `draft` rather than refusing outright keeps a legitimate
     * lower-privileged workflow usable: the work still happens, it just does not
     * go live without the right capability.
     */
    public static function clamp_status(string $post_type, string $status): string {
        $status = \sanitize_key($status);
        if (!in_array($status, ['publish', 'private', 'future', 'draft', 'pending'], true)) {
            return 'draft';
        }
        return self::can_use_status($post_type, $status) ? $status : 'draft';
    }

    /**
     * May the acting user create content of this post type at all?
     */
    public static function can_create(string $post_type): bool {
        $object = \get_post_type_object($post_type);
        $cap = 'edit_posts';
        if (is_object($object) && isset($object->cap->create_posts)) {
            $cap = (string) $object->cap->create_posts;
        } elseif (is_object($object) && isset($object->cap->edit_posts)) {
            $cap = (string) $object->cap->edit_posts;
        }
        return \current_user_can($cap);
    }

    /**
     * Authorize a data-source attachment before its file is read.
     *
     * Two separate problems are addressed. First, an attachment id arriving from
     * the request is an arbitrary object reference, so `read_post` must be
     * checked or any contributor could have PageForge open a file uploaded
     * privately by someone else. Second, the file must actually be a
     * PageForge data source of an expected type: without that, the id could
     * point at any attachment on the site and its contents would be parsed and
     * returned.
     *
     * @return string|\WP_Error Absolute path to the file.
     */
    public static function resolve_data_source(int $attachment_id) {
        if ($attachment_id <= 0) {
            return new \WP_Error('missing_source', __('No data source was selected.', 'pageforge'));
        }

        $post = \get_post($attachment_id);
        if (!$post || 'attachment' !== $post->post_type) {
            return new \WP_Error('invalid_source', __('That data source does not exist.', 'pageforge'));
        }
        if (!\current_user_can('read_post', $attachment_id)) {
            // Same message as "does not exist": telling the caller it exists but
            // is off limits lets them enumerate the media library.
            return new \WP_Error('invalid_source', __('That data source does not exist.', 'pageforge'));
        }

        $path = \get_attached_file($attachment_id);
        if (!$path || !is_string($path) || !is_readable($path)) {
            return new \WP_Error('unreadable_source', __('The data source file could not be read.', 'pageforge'));
        }

        // Confine the path to the uploads directory. get_attached_file() can
        // return an absolute path stored in meta, so this stops a manipulated
        // _wp_attached_file value from reaching outside the media library.
        $uploads = \wp_get_upload_dir();
        $base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        $real = (string) realpath($path);
        $real_base = '' !== $base ? (string) realpath($base) : '';
        if ('' === $real || '' === $real_base || 0 !== strpos($real, $real_base)) {
            return new \WP_Error('unreadable_source', __('The data source file could not be read.', 'pageforge'));
        }

        $extension = strtolower((string) pathinfo($real, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt', 'tsv'], true)) {
            return new \WP_Error('invalid_source_type', __('Only CSV data sources can be used for generation.', 'pageforge'));
        }

        return $real;
    }

    /**
     * Filter a set of post ids down to those the acting user may edit.
     *
     * Bulk operations must authorize every target, not sample one. Returning the
     * denied ids as well lets the caller report an honest partial result instead
     * of silently doing less than the user asked for.
     *
     * @param int[] $post_ids
     * @return array{allowed:int[], denied:int[]}
     */
    public static function partition_editable(array $post_ids): array {
        $allowed = [];
        $denied = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                continue;
            }
            if (self::can_edit_post($post_id)) {
                $allowed[] = $post_id;
            } else {
                $denied[] = $post_id;
            }
        }
        return ['allowed' => $allowed, 'denied' => $denied];
    }

    /**
     * @param int[] $post_ids
     * @return array{allowed:int[], denied:int[]}
     */
    public static function partition_readable(array $post_ids): array {
        $allowed = [];
        $denied = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                continue;
            }
            if (self::can_read_post($post_id)) {
                $allowed[] = $post_id;
            } else {
                $denied[] = $post_id;
            }
        }
        return ['allowed' => $allowed, 'denied' => $denied];
    }
}
