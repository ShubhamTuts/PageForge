<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * Authorization code, access token and refresh token storage for the
 * PageForge MCP authorization server.
 *
 * Storage choices are driven by lifetime and churn, not convenience:
 *
 *  - Authorization codes are single-use and live ~60s. Stored as transients so
 *    expiry is automatic and lookup is O(1) by hash. Deleted on first use, so
 *    replaying a code fails even inside its window.
 *  - Access tokens are short-lived and high-churn. Also transients: O(1) hash
 *    lookup, no option rewrite per request, and they clean themselves up. This
 *    is what keeps agent traffic from turning every MCP call into a
 *    write to wp_options.
 *  - Refresh tokens are long-lived and need rotation, family tracking and
 *    revocation, so they need durable enumerable storage. They are bounded by
 *    (users x connected clients), not by request volume, so a pruned option is
 *    appropriate; every write also drops expired entries.
 *
 * Nothing here ever stores a raw token. Tokens are `hash_hmac('sha256', ...)`
 * with a site pepper, so an options/transient dump does not yield usable
 * credentials, and comparisons use hash_equals.
 */
final class Oauth_Tokens {
    private const CODE_PREFIX = 'pageforge_oauth_code_';
    private const ACCESS_PREFIX = 'pageforge_oauth_at_';
    private const REFRESH_OPTION = 'pageforge_oauth_refresh_tokens';
    private const CONSENT_OPTION = 'pageforge_oauth_consents';

    /** Codes are exchanged immediately; a long window only helps an attacker. */
    public const CODE_TTL = 60;
    /** Short access tokens limit the damage of a leaked token. */
    public const ACCESS_TTL = 3600;
    /** 30 days, refreshed by rotation on every use. */
    public const REFRESH_TTL = 2592000;
    /** Cap on stored refresh tokens so the option cannot grow unbounded. */
    private const REFRESH_MAX = 200;

    // ------------------------------------------------------ authorization codes --

    /**
     * Persist an authorization code. The code binds every value the token
     * endpoint must later re-verify: client, redirect_uri, resource
     * (audience), PKCE challenge, user and scopes. Binding them here is what
     * makes code injection and redirect substitution detectable at exchange
     * time rather than trusting whatever the token request repeats.
     */
    public static function store_code(string $code, array $data): void {
        \set_transient(self::CODE_PREFIX . self::hash($code), [
            'client_id' => (string) ($data['client_id'] ?? ''),
            'user_id' => (int) ($data['user_id'] ?? 0),
            'redirect_uri' => (string) ($data['redirect_uri'] ?? ''),
            'scopes' => array_values(array_map('strval', (array) ($data['scopes'] ?? []))),
            'resource' => (string) ($data['resource'] ?? ''),
            'code_challenge' => (string) ($data['code_challenge'] ?? ''),
            'code_challenge_method' => (string) ($data['code_challenge_method'] ?? 'S256'),
            'offline_access' => !empty($data['offline_access']),
            'issued' => time(),
        ], self::CODE_TTL);
    }

    /**
     * Consume an authorization code. Single-use is enforced by deleting before
     * returning, so two concurrent exchanges cannot both succeed.
     */
    public static function consume_code(string $code) {
        $key = self::CODE_PREFIX . self::hash($code);
        $record = \get_transient($key);
        \delete_transient($key);
        return is_array($record) ? $record : null;
    }

    // ----------------------------------------------------------- access tokens --

    /**
     * Issue an access token bound to a specific resource (audience). The
     * resource is stored, not merely echoed, so validation can reject a token
     * minted for a different MCP server even if it verifies cryptographically.
     *
     * @return array{token:string, expires_in:int}
     */
    public static function issue_access_token(array $data): array {
        $token = 'pfat_' . \wp_generate_password(48, false, false);
        $ttl = self::ACCESS_TTL;
        \set_transient(self::ACCESS_PREFIX . self::hash($token), [
            'client_id' => (string) ($data['client_id'] ?? ''),
            'user_id' => (int) ($data['user_id'] ?? 0),
            'scopes' => array_values(array_map('strval', (array) ($data['scopes'] ?? []))),
            'resource' => (string) ($data['resource'] ?? ''),
            'issued' => time(),
            'expires' => time() + $ttl,
        ], $ttl);
        return ['token' => $token, 'expires_in' => $ttl];
    }

    /**
     * Look up an access token. Returns null for unknown/expired tokens.
     * Deliberately does not touch storage, so a read-heavy agent workload
     * performs zero writes.
     */
    public static function lookup_access_token(string $token) {
        if (0 !== strpos($token, 'pfat_')) {
            return null;
        }
        $record = \get_transient(self::ACCESS_PREFIX . self::hash($token));
        if (!is_array($record)) {
            return null;
        }
        if ((int) ($record['expires'] ?? 0) < time()) {
            \delete_transient(self::ACCESS_PREFIX . self::hash($token));
            return null;
        }
        return $record;
    }

    public static function revoke_access_token(string $token): void {
        \delete_transient(self::ACCESS_PREFIX . self::hash($token));
    }

    // ---------------------------------------------------------- refresh tokens --

    /**
     * Issue a refresh token. `family` groups every token descended from one
     * authorization grant; rotation replaces a member and reuse of a retired
     * member invalidates the whole family (see rotate_refresh_token).
     */
    public static function issue_refresh_token(array $data, string $family = ''): string {
        $token = 'pfrt_' . \wp_generate_password(48, false, false);
        $tokens = self::prune_refresh(self::all_refresh());

        $tokens[self::hash($token)] = [
            'client_id' => (string) ($data['client_id'] ?? ''),
            'user_id' => (int) ($data['user_id'] ?? 0),
            'scopes' => array_values(array_map('strval', (array) ($data['scopes'] ?? []))),
            'resource' => (string) ($data['resource'] ?? ''),
            'family' => '' !== $family ? $family : 'fam_' . \wp_generate_password(16, false, false),
            'issued' => time(),
            'expires' => time() + self::REFRESH_TTL,
            'used' => false,
        ];

        \update_option(self::REFRESH_OPTION, $tokens, false);
        return $token;
    }

    /**
     * Rotate a refresh token, per OAuth 2.1's requirement that refresh tokens
     * for public clients are rotated on each use.
     *
     * Replay handling: if a token that has already been rotated away is
     * presented again, that means either the client mishandled it or it was
     * stolen. Either way the entire family is destroyed and the caller must
     * re-authorize. Silently issuing a new token here would make refresh-token
     * theft undetectable.
     *
     * @return array{token:string, record:array}|\WP_Error
     */
    public static function rotate_refresh_token(string $token, string $client_id, array $requested_scopes = []) {
        $hash = self::hash($token);
        $tokens = self::all_refresh();

        if (!isset($tokens[$hash]) || !is_array($tokens[$hash])) {
            return new \WP_Error('invalid_grant', __('Refresh token is not valid.', 'pageforge'));
        }
        $record = $tokens[$hash];

        // Replay of a retired token: burn the family.
        if (!empty($record['used'])) {
            self::revoke_family((string) ($record['family'] ?? ''));
            return new \WP_Error('invalid_grant', __('Refresh token has already been used. Re-authorization is required.', 'pageforge'));
        }
        if ((int) ($record['expires'] ?? 0) < time()) {
            unset($tokens[$hash]);
            \update_option(self::REFRESH_OPTION, $tokens, false);
            return new \WP_Error('invalid_grant', __('Refresh token has expired.', 'pageforge'));
        }
        // A refresh token belongs to exactly one client.
        if (!\hash_equals((string) ($record['client_id'] ?? ''), $client_id)) {
            self::revoke_family((string) ($record['family'] ?? ''));
            return new \WP_Error('invalid_grant', __('Refresh token was not issued to this client.', 'pageforge'));
        }

        // Refresh may narrow scope but never widen it.
        $scopes = Mcp_Scopes::filter_grantable((array) ($record['scopes'] ?? []));
        if (!empty($requested_scopes)) {
            $requested_scopes = Mcp_Scopes::canonicalize($requested_scopes);
            if (empty($requested_scopes)) {
                return new \WP_Error('invalid_scope', __('Refresh cannot request scopes beyond the original grant.', 'pageforge'));
            }
            $narrowed = array_values(array_intersect($scopes, $requested_scopes));
            if (empty(array_diff($requested_scopes, $scopes))) {
                $scopes = $narrowed;
            } else {
                return new \WP_Error('invalid_scope', __('Refresh cannot request scopes beyond the original grant.', 'pageforge'));
            }
        }

        // A live Pro entitlement is re-checked here, so an expired licence
        // stops refreshing paid scopes rather than coasting on an old grant.
        $scopes = Mcp_Scopes::filter_grantable($scopes);
        if (empty($scopes)) {
            self::revoke_family((string) ($record['family'] ?? ''));
            return new \WP_Error('invalid_scope', __('No scopes in this grant can still be honoured on this site.', 'pageforge'));
        }

        $tokens[$hash]['used'] = true;
        $tokens[$hash]['rotated'] = time();
        \update_option(self::REFRESH_OPTION, $tokens, false);

        $next = self::issue_refresh_token([
            'client_id' => $client_id,
            'user_id' => (int) ($record['user_id'] ?? 0),
            'scopes' => $scopes,
            'resource' => (string) ($record['resource'] ?? ''),
        ], (string) ($record['family'] ?? ''));

        return [
            'token' => $next,
            'record' => [
                'client_id' => $client_id,
                'user_id' => (int) ($record['user_id'] ?? 0),
                'scopes' => $scopes,
                'resource' => (string) ($record['resource'] ?? ''),
            ],
        ];
    }

    public static function revoke_refresh_token(string $token): bool {
        $hash = self::hash($token);
        $tokens = self::all_refresh();
        if (!isset($tokens[$hash])) {
            return false;
        }
        // Revoking one member revokes the grant, which is what a user means
        // when they disconnect a client.
        self::revoke_family((string) ($tokens[$hash]['family'] ?? ''));
        return true;
    }

    public static function revoke_family(string $family): void {
        if ('' === $family) {
            return;
        }
        $tokens = self::all_refresh();
        foreach ($tokens as $hash => $record) {
            if (is_array($record) && (string) ($record['family'] ?? '') === $family) {
                unset($tokens[$hash]);
            }
        }
        \update_option(self::REFRESH_OPTION, $tokens, false);
    }

    public static function revoke_for_client(string $client_id): void {
        $tokens = self::all_refresh();
        foreach ($tokens as $hash => $record) {
            if (is_array($record) && (string) ($record['client_id'] ?? '') === $client_id) {
                unset($tokens[$hash]);
            }
        }
        \update_option(self::REFRESH_OPTION, $tokens, false);
        self::forget_consent_for_client($client_id);
    }

    public static function revoke_for_user(int $user_id): void {
        $tokens = self::all_refresh();
        foreach ($tokens as $hash => $record) {
            if (is_array($record) && (int) ($record['user_id'] ?? 0) === $user_id) {
                unset($tokens[$hash]);
            }
        }
        \update_option(self::REFRESH_OPTION, $tokens, false);
    }

    /** @return array<string, array> */
    public static function all_refresh(): array {
        $stored = \get_option(self::REFRESH_OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /**
     * Grants a user/admin can see and disconnect. Hashes are never exposed;
     * a short fingerprint is enough to tell two grants apart in the UI.
     */
    public static function grants_redacted(): array {
        $out = [];
        foreach (self::all_refresh() as $hash => $record) {
            if (!is_array($record) || !empty($record['used'])) {
                continue;
            }
            $out[] = [
                'fingerprint' => substr((string) $hash, 0, 8),
                'family' => (string) ($record['family'] ?? ''),
                'client_id' => (string) ($record['client_id'] ?? ''),
                'user_id' => (int) ($record['user_id'] ?? 0),
                'scopes' => (array) ($record['scopes'] ?? []),
                'resource' => (string) ($record['resource'] ?? ''),
                'issued' => (int) ($record['issued'] ?? 0),
                'expires' => (int) ($record['expires'] ?? 0),
            ];
        }
        return $out;
    }

    private static function prune_refresh(array $tokens): array {
        $now = time();
        foreach ($tokens as $hash => $record) {
            if (!is_array($record)) {
                unset($tokens[$hash]);
                continue;
            }
            // Retired rotation members are kept briefly so replay is still
            // detectable, then dropped.
            $stale_rotated = !empty($record['used']) && (int) ($record['rotated'] ?? 0) < ($now - self::REFRESH_TTL);
            if ((int) ($record['expires'] ?? 0) < $now || $stale_rotated) {
                unset($tokens[$hash]);
            }
        }
        if (count($tokens) > self::REFRESH_MAX) {
            uasort($tokens, static function (array $a, array $b): int {
                return (int) ($a['issued'] ?? 0) <=> (int) ($b['issued'] ?? 0);
            });
            $tokens = array_slice($tokens, count($tokens) - self::REFRESH_MAX, null, true);
        }
        return $tokens;
    }

    // ------------------------------------------------------------- consent --

    /**
     * Remember that a user already approved a client for a scope set, so a
     * routine token refresh or reconnect does not re-prompt. Consent is
     * per user AND per client: one admin approving a client never grants
     * another user's identity to it.
     */
    public static function remember_consent(int $user_id, string $client_id, array $scopes): void {
        $consents = self::all_consents();
        $consents[self::consent_key($user_id, $client_id)] = [
            'user_id' => $user_id,
            'client_id' => $client_id,
            'scopes' => array_values(array_unique(array_map('strval', $scopes))),
            'granted' => time(),
        ];
        \update_option(self::CONSENT_OPTION, $consents, false);
    }

    /**
     * Consent is only reusable if it covers every scope now being requested.
     * A request for a new scope re-prompts, which is what makes step-up
     * authorization visible to the user instead of silent.
     */
    public static function has_consent(int $user_id, string $client_id, array $scopes): bool {
        $record = self::all_consents()[self::consent_key($user_id, $client_id)] ?? null;
        if (!is_array($record)) {
            return false;
        }
        $granted = (array) ($record['scopes'] ?? []);
        return empty(array_diff($scopes, $granted));
    }

    public static function forget_consent(int $user_id, string $client_id): void {
        $consents = self::all_consents();
        unset($consents[self::consent_key($user_id, $client_id)]);
        \update_option(self::CONSENT_OPTION, $consents, false);
    }

    private static function forget_consent_for_client(string $client_id): void {
        $consents = self::all_consents();
        foreach ($consents as $key => $record) {
            if (is_array($record) && (string) ($record['client_id'] ?? '') === $client_id) {
                unset($consents[$key]);
            }
        }
        \update_option(self::CONSENT_OPTION, $consents, false);
    }

    private static function all_consents(): array {
        $stored = \get_option(self::CONSENT_OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    private static function consent_key(int $user_id, string $client_id): string {
        return $user_id . '|' . md5($client_id);
    }

    // ------------------------------------------------------------- hashing --

    /**
     * Keyed hash rather than bare sha256: a site-specific pepper means stored
     * token digests are useless if lifted to another install, and it blocks
     * precomputation against our known token prefixes.
     */
    private static function hash(string $token): string {
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
