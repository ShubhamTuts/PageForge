<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

class Template {
    /**
     * Return the post only when it really is a PageForge template in either
     * namespace. Callers rely on this to reject arbitrary post IDs.
     */
    public static function get(int $id) {
        $post = \get_post($id);
        if (!$post) {
            return null;
        }
        $post_type = (string) ($post->post_type ?? '');
        return in_array($post_type, Compatibility::template_post_types(), true) ? $post : null;
    }

    public static function title_pattern(int $id, string $default = '{Service} in {City}, {State}'): string {
        $v = Compatibility::read_meta($id, 'title_pattern', '');
        return $v !== '' ? (string) $v : $default;
    }

    public static function meta_description_pattern(int $id, string $default = ''): string {
        $v = Compatibility::read_meta($id, 'meta_description_pattern', '');
        return $v !== '' ? (string) $v : $default;
    }

    public static function slug_pattern(int $id, string $default = '{service}-in-{city}-{state}'): string {
        $v = Compatibility::read_meta($id, 'slug_pattern', '');
        return $v !== '' ? (string) $v : $default;
    }

    public static function post_type(int $id, string $default = 'pageforge_page'): string {
        $v = Compatibility::read_meta($id, 'post_type', '');
        return $v !== '' ? (string) $v : $default;
    }
}
