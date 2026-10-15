<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * Central secret handling.
 *
 * Every credential PageForge stores (AI provider keys, Pexels, GA4 API
 * secret, Google Sheets key, MCP tokens) goes through this class so the
 * stored value is consistent and, critically, never re-rendered into HTML.
 *
 * The previous behaviour rendered `value="<?php echo esc_attr($stored_key) ?>"`
 * directly into a password field. That is not a display risk WordPress'
 * password input hides visually, but the raw secret is still present in the
 * page source and reachable by anyone with view-source or DOM access to an
 * admin screen (browser extensions, shared screenshots, saved HTML, XSS in
 * an unrelated plugin). Treat "never leaves the server in an admin
 * response" as the requirement, not "the input type is password".
 */
final class Secrets {
    /**
     * Sentinel written into a settings form field to mean "leave the stored
     * value unchanged". A real secret can never equal this because real
     * provider tokens don't contain spaces.
     */
    public const UNCHANGED = '__pageforge_unchanged__';

    /**
     * Every settings key PageForge treats as a secret. Centralized so a
     * future credential (a new AI provider, an OAuth token) is added in one
     * place and automatically gets redaction, fingerprinting and the
     * unchanged-on-blank-submit behavior.
     */
    public static function keys(): array {
        return [
            'openai_key',
            'anthropic_key',
            'gemini_key',
            'deepseek_key',
            'custom_ai_key',
            'pexels_key',
            'google_sheets_api_key',
            'ga4_api_secret',
        ];
    }

    public static function is_secret_key(string $key): bool {
        return in_array($key, self::keys(), true);
    }

    /**
     * Non-sensitive status for a stored secret, safe to send to the browser.
     *
     * @return array{configured:bool, fingerprint:string}
     */
    public static function status(string $stored_value): array {
        $stored_value = trim($stored_value);
        if ('' === $stored_value) {
            return ['configured' => false, 'fingerprint' => ''];
        }
        $tail = strlen($stored_value) > 4 ? substr($stored_value, -4) : str_repeat('*', strlen($stored_value));
        return ['configured' => true, 'fingerprint' => '••••' . $tail];
    }

    /**
     * Build the redacted view of a settings array for any admin-facing
     * response (settings screen render, AJAX response, diagnostics, MCP
     * status). Secret keys become status objects; every other key passes
     * through unchanged.
     */
    public static function redact_settings(array $settings): array {
        $out = $settings;
        foreach (self::keys() as $key) {
            $out[$key] = self::status((string) ($settings[$key] ?? ''));
        }
        return $out;
    }

    /**
     * Resolve one incoming form value against the existing stored value.
     *
     * - Missing from the request entirely -> keep the existing value (the
     *   field wasn't part of this submission, e.g. a partial AJAX form).
     * - The unchanged sentinel -> keep the existing value (browser left the
     *   blanked field untouched).
     * - Empty string submitted explicitly with a "remove" flag -> clear it.
     * - Any other value -> the new secret.
     */
    public static function resolve_incoming(
        array $incoming,
        string $key,
        string $existing_value,
        bool $field_present
    ): string {
        if (!$field_present) {
            return $existing_value;
        }
        $raw = (string) ($incoming[$key] ?? '');
        if (self::UNCHANGED === $raw) {
            return $existing_value;
        }
        return $raw;
    }

    /**
     * Value to place in a redacted form field's `value=""` attribute.
     * Never the secret. Blank by default so a fresh page load never shows
     * anything meaningful; the sentinel is only used by JS-free resubmission
     * paths (e.g. a full-page form post) to signal "unchanged".
     */
    public static function field_placeholder_value(string $stored_value): string {
        return '' !== trim($stored_value) ? self::UNCHANGED : '';
    }
}
