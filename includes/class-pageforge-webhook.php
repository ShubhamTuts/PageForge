<?php
namespace PageForge;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Lightweight webhooks endpoint with signature validation.
 *
 * Configure the webhook URL to:
 *   https://example.com/?pageforge_fs_webhook=1
 *
 * For security, define PAGEFORGE_WEBHOOK_SECRET in wp-config.php or provide it
 * via the `pageforge_webhook_secret` filter.
 */
class Webhook {
    private const OPT_WEBHOOK_LAST = 'pageforge_fs_webhook_last';

    public static function bootstrap(): void {
        \add_action( 'init', [ __CLASS__, 'maybe_handle' ] );
    }

    /**
     * Handle requests to the webhook endpoint.
     */
    public static function maybe_handle(): void {
        if ( ! isset( $_GET['pageforge_fs_webhook'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        // Do not run in admin-ajax or CLI context.
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }

        self::handle();
    }

	private static function handle(): void {
		$secret = self::product_secret();
		$raw    = file_get_contents( 'php://input' );
		// Avoid touching the superglobal directly; unslash before sanitizing.
		$sig_raw = '';
		if ( isset( $_SERVER['HTTP_X_SIGNATURE'] ) ) {
			$sig_raw = sanitize_key( wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) );
		}
		$sig = $sig_raw;

        if ( empty( $secret ) || empty( $raw ) || empty( $sig ) ) {
            self::respond( 400, [ 'ok' => false, 'error' => 'missing_signature_or_secret' ] );
        }

        if ( ! self::is_valid_signature( $raw, $sig, $secret ) ) {
            self::respond( 400, [ 'ok' => false, 'error' => 'invalid_signature' ] );
        }

		// Decode JSON (note: json_decode does not sanitize).
		$payload = json_decode( $raw, true );
        if ( ! is_array( $payload ) ) {
            self::respond( 400, [ 'ok' => false, 'error' => 'invalid_payload' ] );
        }

		// Sanitize the entire payload to prevent any unsafe data.
		$payload = map_deep( $payload, 'sanitize_text_field' );

		// Defensive sanitization for values we store/use for dynamic hooks.
		if ( isset( $payload['type'] ) ) {
			$payload['type'] = sanitize_text_field( (string) $payload['type'] );
		}

        // Persist last payload for debugging (non-sensitive event metadata only).
        \update_option( self::OPT_WEBHOOK_LAST, [
            'received_at' => time(),
            'type'        => isset( $payload['type'] ) ? (string) $payload['type'] : '',
        ], false );

		/**
		 * Generic webhook hook.
		 *
		 * @param array $payload Full decoded event payload.
		 */
		\do_action( 'pageforge_webhook', $payload );

        $type = isset( $payload['type'] ) ? (string) $payload['type'] : '';
        if ( $type !== '' ) {
			/**
			 * Action fired for specific webhook event types.
			 *
			 * Example: `add_action( 'pageforge_webhook_license-activated', ... )`.
			 */
			$hook = 'pageforge_webhook_' . sanitize_title_with_dashes( $type );
			// Dynamic hook is still prefixed with `pageforge_`.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
			\do_action( $hook, $payload );
		}

        self::respond( 200, [ 'ok' => true ] );
    }

	private static function product_secret(): string {
		if ( defined( 'PAGEFORGE_WEBHOOK_SECRET' ) && PAGEFORGE_WEBHOOK_SECRET ) {
			return (string) PAGEFORGE_WEBHOOK_SECRET;
		}

		/**
		 * Filter the secret key used for webhook validation.
		 *
		 * @param string $secret Webhook secret key.
		 */
		$secret = \apply_filters( 'pageforge_webhook_secret', '' );
		return (string) $secret;
	}

    private static function is_valid_signature( string $raw_body, string $signature, string $secret ): bool {
        $calculated = hash_hmac( 'sha256', $raw_body, $secret );
        return hash_equals( $calculated, $signature );
    }

    private static function respond( int $code, array $data ): void {
        if ( ! headers_sent() ) {
            status_header( $code );
            header( 'Content-Type: application/json; charset=utf-8' );
        }
        echo wp_json_encode( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }
}

Webhook::bootstrap();

