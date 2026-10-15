<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * OAuth client identification for the PageForge MCP authorization server.
 *
 * MCP 2026-07-28 defines three ways a client can obtain a client_id, in this
 * priority order:
 *
 *   1. Client ID Metadata Documents (CIMD) — the client_id *is* an HTTPS URL
 *      pointing at its own metadata document. This is the direction the spec
 *      endorses and the only mechanism that needs no prior relationship, so
 *      it is PageForge's primary path.
 *   2. Pre-registration — an admin creates a client here and pastes the
 *      client_id/secret into the MCP client. Claude's remote custom connector
 *      UI asks for exactly this, so it is a first-class path, not a fallback.
 *   3. Dynamic Client Registration (RFC 7591) — retained for clients that
 *      still probe for a `registration_endpoint`. It is enabled by default
 *      for interoperability, rate limited, bounded, and issues no secret.
 *
 * Sites can disable DCR with the saved compatibility setting or the
 * `pageforge_mcp_dcr_enabled` filter.
 */
final class Oauth_Clients {
    private const OPTION = 'pageforge_oauth_clients';
    private const CIMD_CACHE_PREFIX = 'pageforge_cimd_';
    private const CIMD_MAX_BYTES = 32768;
    private const CIMD_DEFAULT_TTL = 3600;

    public const TYPE_CIMD = 'cimd';
    public const TYPE_PREREGISTERED = 'preregistered';
    public const TYPE_DYNAMIC = 'dynamic';

    /** @return array<string, array> Stored (pre-registered + dynamic) clients keyed by client_id. */
    public static function all(): array {
        $stored = \get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /**
     * @return array Same shape as all() with secret hashes stripped. Safe for
     *               the browser.
     */
    public static function all_redacted(): array {
        return array_map(static function (array $client): array {
            unset($client['secret_hash']);
            return $client;
        }, self::all());
    }

    /**
     * Resolve a presented client_id into a usable client record.
     *
     * @return array{
     *   client_id:string, client_name:string, redirect_uris:string[],
     *   type:string, confidential:bool, secret_hash:string,
     *   logo_uri:string, client_uri:string
     * }|null
     */
    public static function resolve(string $client_id) {
        $client_id = trim($client_id);
        if ('' === $client_id) {
            return null;
        }

        // A URL-shaped client_id is a Client ID Metadata Document.
        if (self::looks_like_cimd($client_id)) {
            return self::resolve_cimd($client_id);
        }

        $stored = self::all();
        if (!isset($stored[$client_id]) || is_array($stored[$client_id]) === false) {
            return null;
        }
        $client = $stored[$client_id];
        if (!empty($client['revoked'])) {
            return null;
        }
        return self::normalize($client);
    }

    /**
     * A CIMD client_id must be an https URL with a path component. The path
     * requirement is normative and also what stops a bare origin from being
     * used as an identifier.
     */
    public static function looks_like_cimd(string $client_id): bool {
        if (0 !== stripos($client_id, 'https://')) {
            return false;
        }
        $parts = \wp_parse_url($client_id);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }
        if (!empty($parts['fragment'])) {
            return false;
        }
        $path = (string) ($parts['path'] ?? '');
        return '' !== $path && '/' !== $path;
    }

    /**
     * Fetch, validate and cache a Client ID Metadata Document.
     *
     * Security properties enforced here:
     *  - https only, and the URL is SSRF-screened (resolved IP must be public
     *    unicast) so a client_id cannot be used to make the site probe its own
     *    private network.
     *  - the document's own `client_id` MUST equal the URL exactly, which is
     *    what prevents one host from claiming another's identity.
     *  - response size is bounded before JSON decoding.
     *  - cached with respect for the document's own cache headers, so a
     *    compromised client document can be rotated out.
     */
    private static function resolve_cimd(string $client_id) {
        $cache_key = self::CIMD_CACHE_PREFIX . md5($client_id);
        $cached = \get_transient($cache_key);
        if (is_array($cached)) {
            return empty($cached['client_id']) ? null : $cached;
        }

        if (!pf_url_is_safe_remote_target($client_id)) {
            return null;
        }

        $response = \wp_remote_get($client_id, [
            'timeout' => 8,
            'redirection' => 0, // A redirect would break the client_id === URL identity rule.
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (\is_wp_error($response)) {
            return null;
        }
        if (200 !== (int) \wp_remote_retrieve_response_code($response)) {
            return null;
        }

        $body = (string) \wp_remote_retrieve_body($response);
        if ('' === $body || strlen($body) > self::CIMD_MAX_BYTES) {
            return null;
        }

        $document = json_decode($body, true);
        if (!is_array($document)) {
            return null;
        }

        // Identity rule: the document must claim exactly this URL.
        if (!isset($document['client_id']) || !\hash_equals($client_id, (string) $document['client_id'])) {
            return null;
        }

        $redirect_uris = array_values(array_filter(array_map('strval', (array) ($document['redirect_uris'] ?? []))));
        if (empty($redirect_uris)) {
            return null;
        }

        $client = self::normalize([
            'client_id' => $client_id,
            'client_name' => (string) ($document['client_name'] ?? $client_id),
            'client_uri' => (string) ($document['client_uri'] ?? ''),
            'logo_uri' => (string) ($document['logo_uri'] ?? ''),
            'redirect_uris' => $redirect_uris,
            'type' => self::TYPE_CIMD,
            // CIMD clients are public: they hold no secret we could verify.
            'confidential' => false,
            'secret_hash' => '',
        ]);

        \set_transient($cache_key, $client, self::cimd_ttl($response));
        return $client;
    }

    /** Respect the document's own caching directives, within sane bounds. */
    private static function cimd_ttl($response): int {
        $header = (string) \wp_remote_retrieve_header($response, 'cache-control');
        if ('' !== $header && preg_match('/max-age\s*=\s*(\d+)/i', $header, $m)) {
            return max(300, min(86400, (int) $m[1]));
        }
        return self::CIMD_DEFAULT_TTL;
    }

    /**
     * Create a pre-registered client. This is what an admin uses when a
     * connector (Claude's remote custom connector, for example) asks them to
     * paste a Client ID and Client Secret.
     *
     * @return array{client_id:string, client_secret:string}
     */
    public static function create_preregistered(string $name, array $redirect_uris, bool $confidential = true): array {
        $client_id = 'pfc_' . \wp_generate_password(24, false, false);
        $secret = $confidential ? 'pfs_' . \wp_generate_password(48, false, false) : '';

        $clients = self::all();
        $clients[$client_id] = [
            'client_id' => $client_id,
            'client_name' => \sanitize_text_field($name) ?: __('Unnamed MCP client', 'pageforge'),
            'client_uri' => '',
            'logo_uri' => '',
            'redirect_uris' => self::sanitize_redirect_uris($redirect_uris),
            'type' => self::TYPE_PREREGISTERED,
            'confidential' => $confidential,
            'secret_hash' => '' === $secret ? '' : self::hash_secret($secret),
            'created' => time(),
            'revoked' => false,
        ];
        \update_option(self::OPTION, $clients, false);

        return ['client_id' => $client_id, 'client_secret' => $secret];
    }

    /**
     * Register a public client dynamically (RFC 7591). No secret is issued,
     * entries are bounded, and the endpoint is rate limited. Sites that need a
     * closed registration surface can explicitly disable compatibility.
     *
     * @return array|\WP_Error
     */
    public static function register_dynamic(string $name, array $redirect_uris) {
        if (!self::dcr_enabled()) {
            return new \WP_Error('registration_not_supported', __('Dynamic client registration is disabled on this site.', 'pageforge'));
        }

        $redirect_uris = self::sanitize_redirect_uris($redirect_uris);
        if (empty($redirect_uris)) {
            return new \WP_Error('invalid_redirect_uri', __('At least one valid redirect URI is required.', 'pageforge'));
        }

        $clients = self::all();
        // Bounded so an open registration endpoint cannot grow an option
        // without limit. Oldest dynamic entries are pruned first.
        $clients = self::prune_dynamic($clients, 50);

        $client_id = 'pfd_' . \wp_generate_password(24, false, false);
        $clients[$client_id] = [
            'client_id' => $client_id,
            'client_name' => \sanitize_text_field($name) ?: __('Dynamically registered client', 'pageforge'),
            'client_uri' => '',
            'logo_uri' => '',
            'redirect_uris' => $redirect_uris,
            'type' => self::TYPE_DYNAMIC,
            'confidential' => false,
            'secret_hash' => '',
            'created' => time(),
            'revoked' => false,
        ];
        \update_option(self::OPTION, $clients, false);

        return [
            'client_id' => $client_id,
            'client_name' => $clients[$client_id]['client_name'],
            'redirect_uris' => $redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
    }

    private static function prune_dynamic(array $clients, int $max): array {
        $dynamic = array_filter($clients, static function ($client): bool {
            return is_array($client) && self::TYPE_DYNAMIC === ($client['type'] ?? '');
        });
        if (count($dynamic) < $max) {
            return $clients;
        }
        uasort($dynamic, static function (array $a, array $b): int {
            return (int) ($a['created'] ?? 0) <=> (int) ($b['created'] ?? 0);
        });
        $remove = count($dynamic) - $max + 1;
        foreach (array_slice(array_keys($dynamic), 0, max(0, $remove)) as $id) {
            unset($clients[$id]);
        }
        return $clients;
    }

    public static function revoke(string $client_id): bool {
        $clients = self::all();
        if (!isset($clients[$client_id])) {
            return false;
        }
        $clients[$client_id]['revoked'] = true;
        \update_option(self::OPTION, $clients, false);
        Oauth_Tokens::revoke_for_client($client_id);
        return true;
    }

    /**
     * Verify a presented client secret in constant time. A client with no
     * stored secret is public and must not be authenticable by secret.
     */
    public static function verify_secret(array $client, string $secret): bool {
        $hash = (string) ($client['secret_hash'] ?? '');
        if ('' === $hash || '' === $secret) {
            return false;
        }
        return \hash_equals($hash, self::hash_secret($secret));
    }

    /**
     * Exact-match redirect URI validation. No prefix or wildcard matching:
     * that is the open-redirection hole the spec's security considerations
     * call out. Loopback ports are the one allowed variance, because native
     * clients bind an ephemeral port and RFC 8252 permits it.
     */
    public static function redirect_uri_allowed(array $client, string $redirect_uri): bool {
        $registered = (array) ($client['redirect_uris'] ?? []);
        foreach ($registered as $candidate) {
            if (\hash_equals((string) $candidate, $redirect_uri)) {
                return true;
            }
            if (self::loopback_match((string) $candidate, $redirect_uri)) {
                return true;
            }
        }
        return false;
    }

    /**
     * RFC 8252 s7.3: for native clients on http://127.0.0.1 or ::1, the port
     * is chosen at runtime, so scheme/host/path must match exactly but the
     * port may differ. `localhost` is intentionally excluded from the port
     * variance because it is DNS-resolvable and therefore hijackable.
     */
    private static function loopback_match(string $registered, string $presented): bool {
        $a = \wp_parse_url($registered);
        $b = \wp_parse_url($presented);
        if (!is_array($a) || !is_array($b)) {
            return false;
        }
        if ('http' !== strtolower((string) ($a['scheme'] ?? '')) || 'http' !== strtolower((string) ($b['scheme'] ?? ''))) {
            return false;
        }
        $host_a = strtolower((string) ($a['host'] ?? ''));
        $host_b = strtolower((string) ($b['host'] ?? ''));
        if ($host_a !== $host_b || !in_array($host_a, ['127.0.0.1', '::1'], true)) {
            return false;
        }
        if ((string) ($a['path'] ?? '') !== (string) ($b['path'] ?? '')) {
            return false;
        }
        return (string) ($a['query'] ?? '') === (string) ($b['query'] ?? '');
    }

    /**
     * A redirect URI must be absolute, fragment-free, and either https or a
     * loopback http address. Plain http to a remote host is rejected: it
     * would leak the authorization code in cleartext.
     */
    public static function sanitize_redirect_uris(array $uris): array {
        $out = [];
        foreach ($uris as $uri) {
            $uri = trim((string) $uri);
            if ('' === $uri || strlen($uri) > 2000) {
                continue;
            }
            $parts = \wp_parse_url($uri);
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                continue;
            }
            if (!empty($parts['fragment'])) {
                continue;
            }
            $scheme = strtolower((string) $parts['scheme']);
            $host = strtolower((string) $parts['host']);
            $is_loopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
            if ('https' !== $scheme && !('http' === $scheme && $is_loopback)) {
                continue;
            }
            if (!in_array($uri, $out, true)) {
                $out[] = $uri;
            }
        }
        return $out;
    }

    private static function normalize(array $client): array {
        return [
            'client_id' => (string) ($client['client_id'] ?? ''),
            'client_name' => (string) ($client['client_name'] ?? ''),
            'client_uri' => (string) ($client['client_uri'] ?? ''),
            'logo_uri' => (string) ($client['logo_uri'] ?? ''),
            'redirect_uris' => array_values(array_map('strval', (array) ($client['redirect_uris'] ?? []))),
            'type' => (string) ($client['type'] ?? self::TYPE_PREREGISTERED),
            'confidential' => (bool) ($client['confidential'] ?? false),
            'secret_hash' => (string) ($client['secret_hash'] ?? ''),
        ];
    }

    private static function hash_secret(string $secret): string {
        return hash_hmac('sha256', $secret, self::secret_pepper());
    }

    /**
     * Site-specific pepper so client-secret hashes are not portable between
     * installs even if an options table is copied.
     */
    private static function secret_pepper(): string {
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

    public static function dcr_enabled(): bool {
        $settings = settings_get();
        $enabled = !array_key_exists('mcp_oauth_dcr_enabled', $settings)
            || !empty($settings['mcp_oauth_dcr_enabled']);
        return (bool) \apply_filters('pageforge_mcp_dcr_enabled', $enabled);
    }
}
