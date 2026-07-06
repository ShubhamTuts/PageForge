<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

class Template {
    public static function get(int $id) {
        return \get_post($id);
    }

    public static function title_pattern(int $id, string $default = '{Service} in {City}, {State}'): string {
        $v = \get_post_meta($id, '_pageforge_title_pattern', true);
        return $v !== '' ? (string) $v : $default;
    }

    public static function slug_pattern(int $id, string $default = '{service}-in-{city}-{state}'): string {
        $v = \get_post_meta($id, '_pageforge_slug_pattern', true);
        return $v !== '' ? (string) $v : $default;
    }

    public static function post_type(int $id, string $default = 'pageforge_page'): string {
        $v = \get_post_meta($id, '_pageforge_post_type', true);
        return $v !== '' ? (string) $v : $default;
    }
}

