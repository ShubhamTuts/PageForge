<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * Personal Access Tokens for the PageForge MCP endpoint.
 *
 * A PAT is for clients where the operator supplies the Authorization header
 * themselves: VS Code and other IDE MCP configurations, Codex, GitHub Copilot,
 * Roo Code, Cursor, Claude Code, and generic MCP clients. It is deliberately
 * NOT the mechanism for ChatGPT or Claude remote connectors: those perform
 * OAuth discovery and never consume a pasted token. Mixing the two is what
 * made the previous "Connect" button unable to complete a connection.
 *
 * A PAT is not a "connection". Creating one produces a credential that may
 * never be used; it only becomes a connection once a client authenticates with
 * it. `status()` reflects that distinction rather than claiming success early.
 *
 * The raw token is shown exactly once. Only a keyed digest is stored, so the
 * server can verify a presented token but can never reproduce it, matching how
 * WordPress Application Passwords behave.
 *
 * Every PAT is bound to the WordPress user who created it. Tool calls act as
 * that user and are limited by that user's own capabilities, so a token cannot
 * outrank the person who made it.
 */
final class Mcp_Credentials {
    private const OPTION = 'pageforge_mcp_credentials';

    /**
     * `last_used` is only persisted at this granularity. Writing an option on
     * every agent request would make a busy MCP client a write-heavy workload
     * on wp_options; five minutes is plenty to answer "is this still in use".
     */
    private const TOUCH_INTERVAL = 300;

    /**
     * Scopes a PAT may hold. Sourced from the shared catalog so a PAT can
     * never be issued a scope the site cannot honour.
     */
    public static function known_scopes(): array {
        return Mcp_Scopes::grantable();
    }

    /** @return array<string, array> */
    public static function all(): array {
        $stored = \get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /**
     * @return array List with digests removed and a computed status. Safe for
     *               the browser.
     */
    public static function all_redacted(): array {
        $out = [];
        foreach (self::all() as $id => $credential) {
            if (!is_array($credential)) {
                continue;
            }
            unset($credential['token_hash']);
            $credential['scopes'] = self::normalize_scopes((array) ($credential['scopes'] ?? []));
            $credential['status'] = self::status($credential);
            // A short, non-reversible fingerprint so two tokens can be told
            // apart in the UI without displaying any part of the secret.
            $credential['fingerprint'] = self::fingerprint((string) $id);
            $out[$id] = $credential;
        }
        return $out;
    }

    /**
     * Honest lifecycle state. A freshly created credential is active but
     * unused; calling that "Connected" is what made the old UI misleading.
     *
     * @return array{state:string, label:string}
     */
    public static function status(array $credential): array {
        if (!empty($credential['revoked'])) {
            return ['state' => 'revoked', 'label' => __('Revoked', 'pageforge')];
        }
        $expires = (int) ($credential['expires'] ?? 0);
        if ($expires > 0 && $expires < time()) {
            return ['state' => 'expired', 'label' => __('Expired', 'pageforge')];
        }
        if (empty($credential['last_used'])) {
            return ['state' => 'never_used', 'label' => __('Credential active · Never used', 'pageforge')];
        }
        return ['state' => 'in_use', 'label' => __('In use', 'pageforge')];
    }

    /**
     * Create a PAT and return the raw token once.
     *
     * @param int $user_id The WordPress user this token acts as. Defaults to
     *                     the current user; a token with no user is refused
     *                     because it could not be capability-checked.
     * @return array{id:string, token:string}|\WP_Error
     */
    public static function create(string $label, string $client, array $scopes, int $ttl_seconds = 0, int $user_id = 0) {
        $user_id = $user_id > 0 ? $user_id : (function_exists('get_current_user_id') ? (int) \get_current_user_id() : 0);
        if ($user_id <= 0) {
            return new \WP_Error('no_user', __('A personal access token must belong to a WordPress user.', 'pageforge'));
        }

        $scopes = self::normalize_scopes($scopes);
        // A token may never exceed what its owner could do themselves.
        $scopes = array_values(array_filter($scopes, static function (string $scope) use ($user_id): bool {
            return self::user_may_hold_scope($user_id, $scope);
        }));
        if (empty($scopes)) {
            $scopes = [Mcp_Scopes::READ];
        }

        $id = 'mcp_' . \wp_generate_password(12, false, false);
        $token = self::generate_token($id);

        $credentials = self::all();
        $credentials[$id] = [
            'id' => $id,
            'label' => \sanitize_text_field($label) ?: __('Unnamed client', 'pageforge'),
            'client' => \sanitize_key($client) ?: 'generic',
            'user_id' => $user_id,
            'token_hash' => self::hash_token($token),
            'scopes' => $scopes,
            'created' => time(),
            'expires' => $ttl_seconds > 0 ? time() + $ttl_seconds : 0,
            'last_used' => 0,
            'last_used_ip' => '',
            'revoked' => false,
        ];
        \update_option(self::OPTION, $credentials, false);

        return ['id' => $id, 'token' => $token];
    }

    /**
     * Rotate: new token, same identity and scopes, old token dead immediately.
     */
    public static function rotate(string $id): ?string {
        $credentials = self::all();
        if (!isset($credentials[$id]) || !empty($credentials[$id]['revoked'])) {
            return null;
        }
        $token = self::generate_token($id);
        $credentials[$id]['token_hash'] = self::hash_token($token);
        $credentials[$id]['last_used'] = 0;
        $credentials[$id]['last_used_ip'] = '';
        $credentials[$id]['rotated'] = time();
        \update_option(self::OPTION, $credentials, false);
        return $token;
    }

    public static function revoke(string $id): bool {
        $credentials = self::all();
        if (!isset($credentials[$id])) {
            return false;
        }
        $credentials[$id]['revoked'] = true;
        $credentials[$id]['revoked_at'] = time();
        \update_option(self::OPTION, $credentials, false);
        return true;
    }

    /**
     * Verify a presented token.
     *
     * The token embeds its own record id, so this is a single keyed lookup and
     * one constant-time comparison. It deliberately does not iterate every
     * stored credential: that made verification cost grow with the number of
     * issued tokens, on the hottest path in the whole MCP surface.
     *
     * The digest comparison still runs even when the id is unknown, so a wrong
     * id and a wrong secret take the same time and the id space is not
     * enumerable by timing.
     */
    public static function verify(string $token): ?array {
        $token = trim($token);
        if ('' === $token || 0 !== strpos($token, 'pfmcp_')) {
            return null;
        }

        $id = self::extract_id($token);
        $credentials = self::all();
        $credential = ($id !== '' && isset($credentials[$id]) && is_array($credentials[$id])) ? $credentials[$id] : null;

        // Compared against a fixed dummy when the id is unknown, to keep the
        // work constant.
        $stored = (string) ($credential['token_hash'] ?? str_repeat('0', 64));
        $matches = \hash_equals($stored, self::hash_token($token));

        // Tokens issued before the pepper was introduced were stored as a bare
        // sha256. They are still honoured so an upgrade does not silently break
        // a working client, and are re-hashed on first use below.
        $legacy = !$matches && \hash_equals($stored, hash('sha256', $token));
        if ($legacy) {
            $matches = true;
        }

        if (null === $credential || !$matches) {
            return null;
        }
        if (!empty($credential['revoked'])) {
            return null;
        }
        $expires = (int) ($credential['expires'] ?? 0);
        if ($expires > 0 && $expires < time()) {
            return null;
        }

        if ($legacy) {
            $credentials[$id]['token_hash'] = self::hash_token($token);
            \update_option(self::OPTION, $credentials, false);
        }

        $credential['scopes'] = self::normalize_scopes((array) ($credential['scopes'] ?? []));
        return $credential;
    }

    /**
     * Record usage, throttled. Returns true when a write actually happened,
     * which makes the throttle observable in tests.
     */
    public static function touch(string $id, string $ip): bool {
        $credentials = self::all();
        if (!isset($credentials[$id]) || !is_array($credentials[$id])) {
            return false;
        }
        $last = (int) ($credentials[$id]['last_used'] ?? 0);
        $now = time();
        if ($last > 0 && ($now - $last) < self::TOUCH_INTERVAL) {
            return false;
        }
        $credentials[$id]['last_used'] = $now;
        // Already reduced to a network prefix by Request::client_ip_for_audit.
        $credentials[$id]['last_used_ip'] = substr(\sanitize_text_field($ip), 0, 64);
        \update_option(self::OPTION, $credentials, false);
        return true;
    }

    /**
     * Scope check honouring the shared hierarchy, so a broader granted scope
     * satisfies a narrower requirement.
     */
    public static function has_scope(array $credential, string $scope): bool {
        return Mcp_Scopes::satisfies(self::normalize_scopes((array) ($credential['scopes'] ?? [])), $scope);
    }

    /**
     * Map legacy names onto catalog scopes and drop anything this site cannot
     * grant. Applied on read as well as write, so tokens issued before the
     * catalog existed keep working without a migration step, and a Pro-scoped
     * token stops conferring write access the moment the licence lapses.
     */
    public static function normalize_scopes(array $scopes): array {
        return Mcp_Scopes::filter_grantable(Mcp_Scopes::canonicalize($scopes));
    }

    /**
     * A PAT may only carry a scope its owner could exercise directly. Without
     * this, an editor-level account could mint a token that administers the
     * site.
     */
    public static function user_may_hold_scope(int $user_id, string $scope): bool {
        if (Mcp_Scopes::ADMIN === $scope) {
            return \user_can($user_id, 'manage_options');
        }
        if (Mcp_Scopes::is_write($scope)) {
            return \user_can($user_id, 'edit_posts');
        }
        return \user_can($user_id, 'read');
    }

    /**
     * Token layout: `pfmcp_<record id>_<43 random chars>`. The embedded id is
     * what makes verification a keyed lookup; it is not a secret, and the
     * random tail is what is actually verified.
     */
    private static function generate_token(string $id): string {
        return 'pfmcp_' . $id . '_' . \wp_generate_password(43, false, false);
    }

    private static function extract_id(string $token): string {
        // pfmcp_ | mcp_xxxxxxxxxxxx | secret
        $parts = explode('_', $token, 4);
        if (count($parts) < 4) {
            return '';
        }
        $id = $parts[1] . '_' . $parts[2];
        return 1 === preg_match('/^mcp_[A-Za-z0-9]{6,32}$/', $id) ? $id : '';
    }

    private static function fingerprint(string $id): string {
        return substr(hash('sha256', $id), 0, 8);
    }

    /**
     * Keyed digest with a site pepper, so lifting the options table to another
     * install yields nothing usable and our known token prefix cannot be
     * precomputed against.
     */
    private static function hash_token(string $token): string {
        return hash_hmac('sha256', $token, self::pepper());
    }

    private static function pepper(): string {
        if (defined('AUTH_SALT') && '' !== (string) AUTH_SALT) {
            return (string) AUTH_SALT;
        }
        $stored = (string) \get_option('pageforge_oauth_pepper', '');
        if ('' === $stored) {
            $stored = \wp_generate_password(64, false, false);
            \update_option('pageforge_oauth_pepper', $stored, false);
        }
        return $stored;
    }
}
