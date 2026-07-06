<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

class DataSource {
    public static function csv_rows(int $attachment_id): array {
        $file = \get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) return [[], []];
        return parse_csv($file);
    }

	public static function list_csv_attachments(int $limit = 50): array {
		$limit = max(1, min(200, (int) $limit));
		$q = new \WP_Query([
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => $limit,
			// Using meta_key/meta_value for a dedicated flag. This query is bounded
			// by posts_per_page and disables extra caches to keep it lightweight.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_key'   => '_pageforge_csv',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_value' => 1,
			'no_found_rows' => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]);
        $items = [];
        foreach ($q->posts as $p) {
            $items[] = [ 'id' => $p->ID, 'title' => $p->post_title, 'url' => \wp_get_attachment_url($p->ID) ];
        }
        return $items;
    }

    public static function sample_csv(): string {
        return "City,State,Service\nSydney,NSW,Web Development\nMelbourne,VIC,SEO\n";
    }

    // Placeholder for Google Sheets integration
    public static function sheet_rows(string $sheet_url_or_id, ?string $api_key = null): array {
        // TODO: Implement Google Sheets fetch (public CSV export or API v4) in pro release.
        return [[], []];
    }
}

