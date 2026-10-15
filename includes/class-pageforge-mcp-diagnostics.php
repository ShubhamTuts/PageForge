<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * MCP / OAuth connection diagnostics.
 *
 * The failure mode this exists to prevent is an operator seeing "connection
 * failed" in ChatGPT or Claude with no way to tell whether the problem is
 * their site, their host, or the client. Each check reports what PageForge can
 * actually verify from inside WordPress, and says so plainly when it cannot
 * verify something rather than guessing.
 *
 * Every remedy here is additive. None of them is "turn off a security
 * feature": a stripped Authorization header is a proxy configuration problem,
 * and telling an operator to accept tokens in a query string instead would
 * trade a broken connection for a credential leak.
 */
final class Mcp_Diagnostics {
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const UNKNOWN = 'unknown';

    /**
     * @return array<int, array{id:string,label:string,state:string,detail:string,remedy:string}>
     */
    public static function run(): array {
        $settings = settings_get();
        $checks = [];

        $checks[] = self::check_enabled($settings);
        $checks[] = self::check_https();
        $checks[] = self::check_permalinks();
        $checks[] = self::check_rest_reachable();
        $checks[] = self::check_wellknown_reachable();
        $checks[] = self::check_authorization_header();
        $checks[] = self::check_metadata_shape();
        $checks[] = self::check_cron();
        $checks[] = self::check_security_plugins();
        $checks[] = self::check_last_client_request();
        $checks[] = self::check_recent_failures();

        return array_values(array_filter($checks));
    }

    /**
     * Surfaces why recent MCP requests were rejected.
     *
     * A connector typically reports only "the server returned an error", which
     * leaves the operator unable to tell an authentication problem from a
     * protocol-version problem from a blocked route. This turns the server's own
     * refusals into something readable. It holds no tokens, headers or tool
     * arguments.
     */
    private static function check_recent_failures(): array {
        $failures = Mcp_Server::recent_failures(5);
        if (empty($failures)) {
            return self::result(
                'recent_failures',
                __('Recent rejected requests', 'pageforge'),
                self::OK,
                __('No MCP request has been rejected recently.', 'pageforge')
            );
        }

        $lines = [];
        foreach ($failures as $failure) {
            $lines[] = sprintf(
                /* translators: 1: time ago, 2: HTTP status, 3: HTTP method, 4: reason. */
                __('%1$s ago — HTTP %2$d on %3$s: %4$s', 'pageforge'),
                (string) \human_time_diff((int) $failure['time'], time()),
                (int) $failure['http_status'],
                '' !== (string) $failure['http_method'] ? (string) $failure['http_method'] : '?',
                (string) $failure['reason']
            );
        }

        $status = (int) $failures[0]['http_status'];

        return self::result(
            'recent_failures',
            __('Recent rejected requests', 'pageforge'),
            self::WARN,
            implode(' | ', $lines),
            self::failure_remedy($status)
        );
    }

    private static function failure_remedy(int $status): string {
        if (403 === $status) {
            return __('403 means MCP is disabled, the Origin was rejected, or the grant lacked the scope for that operation. Check the MCP toggle above and the scopes on the connected client.', 'pageforge');
        }
        if (405 === $status) {
            return __('405 is expected when a client probes for the legacy GET event stream; this server is stateless and accepts POST only. It is not an error unless the client then gives up.', 'pageforge');
        }
        if (400 === $status) {
            return __('400 means an authenticated request had an invalid JSON body or conflicting protocol metadata. "Malformed JSON" identifies a body problem; unsupported versions and header conflicts are named separately above.', 'pageforge');
        }
        if (429 === $status) {
            return __('429 means a rate limit was hit. This is protective; if a legitimate client is affected, reduce its polling frequency.', 'pageforge');
        }
        if (401 === $status) {
            return __('401 in this list means a supplied credential was rejected. Credential-free OAuth discovery challenges are expected and are not logged. Reconnect the client or regenerate its API key.', 'pageforge');
        }
        return __('Check the reason above. If it mentions a credential, reconnect the client; if it mentions the protocol, the client and this server disagree on a version.', 'pageforge');
    }

    /** Worst state across all checks, for a single headline badge. */
    public static function overall(array $checks): string {
        $states = array_column($checks, 'state');
        foreach ([self::FAIL, self::WARN, self::UNKNOWN] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }
        return self::OK;
    }

    private static function result(string $id, string $label, string $state, string $detail, string $remedy = ''): array {
        return compact('id', 'label', 'state', 'detail', 'remedy');
    }

    private static function check_enabled(array $settings): array {
        $enabled = !empty($settings['mcp_enabled']);
        return self::result(
            'mcp_enabled',
            __('PageForge MCP', 'pageforge'),
            $enabled ? self::OK : self::WARN,
            $enabled ? __('Enabled. The MCP endpoint accepts authenticated requests.', 'pageforge') : __('Disabled. Every MCP request is refused, including OAuth authorization.', 'pageforge'),
            $enabled ? '' : __('Enable PageForge MCP above before connecting a client.', 'pageforge')
        );
    }

    private static function check_https(): array {
        $https = Request::site_is_https();
        return self::result(
            'https',
            __('HTTPS', 'pageforge'),
            $https ? self::OK : self::FAIL,
            $https ? __('The site address uses HTTPS.', 'pageforge') : __('The site address is not HTTPS.', 'pageforge'),
            $https ? '' : __('Remote MCP connectors such as ChatGPT and Claude require HTTPS, and OAuth redirect URIs must be HTTPS. Install a TLS certificate and update the WordPress Address and Site Address.', 'pageforge')
        );
    }

    /**
     * A subdirectory install cannot serve the root `/.well-known/` documents,
     * because those requests never reach WordPress. Header-based discovery
     * still works and MCP clients MUST support it, so this is a warning rather
     * than a failure — but the operator deserves to know.
     */
    private static function check_wellknown_reachable(): array {
        if (Oauth::is_root_install()) {
            return self::result(
                'wellknown',
                __('Well-known discovery paths', 'pageforge'),
                self::OK,
                __('WordPress serves the site root, so the standard discovery paths are available.', 'pageforge')
            );
        }
        return self::result(
            'wellknown',
            __('Well-known discovery paths', 'pageforge'),
            self::WARN,
            __('WordPress is installed in a subdirectory, so requests to the site root for /.well-known/oauth-protected-resource do not reach PageForge.', 'pageforge'),
            __('Clients that read the WWW-Authenticate challenge (all current MCP clients must) will still discover OAuth correctly. To also serve the root paths, proxy /.well-known/oauth-protected-resource and /.well-known/oauth-authorization-server to the PageForge REST equivalents shown below.', 'pageforge')
        );
    }

    private static function check_permalinks(): array {
        $structure = (string) \get_option('permalink_structure', '');
        if ('' === $structure) {
            return self::result(
                'permalinks',
                __('Permalinks', 'pageforge'),
                self::WARN,
                __('Plain permalinks are active.', 'pageforge'),
                __('The REST endpoints still work, but the consent URL is cleaner and more reliable with pretty permalinks. Settings → Permalinks → any option other than Plain.', 'pageforge')
            );
        }
        return self::result('permalinks', __('Permalinks', 'pageforge'), self::OK, __('Pretty permalinks are active.', 'pageforge'));
    }

    /**
     * Loops back to our own Protected Resource Metadata document over HTTP.
     * This is the check that catches a WAF or security plugin blocking the
     * REST API, which is otherwise invisible from inside PHP.
     */
    private static function check_rest_reachable(): array {
        $url = Oauth::protected_resource_metadata_url();
        $response = \wp_remote_get($url, ['timeout' => 8, 'sslverify' => true]);

        if (\is_wp_error($response)) {
            return self::result(
                'rest',
                __('REST endpoint reachable', 'pageforge'),
                self::UNKNOWN,
                sprintf(
                    /* translators: %s: HTTP error message. */
                    __('PageForge could not reach its own discovery document: %s', 'pageforge'),
                    (string) $response->get_error_message()
                ),
                __('This is often a local DNS or loopback restriction on the host rather than a real fault. Fetch the URL below from outside the server to confirm whether clients can reach it.', 'pageforge')
            );
        }

        $code = (int) \wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return self::result(
                'rest',
                __('REST endpoint reachable', 'pageforge'),
                self::FAIL,
                sprintf(
                    /* translators: %d: HTTP status code. */
                    __('The discovery document returned HTTP %d.', 'pageforge'),
                    $code
                ),
                __('A security plugin, WAF, or server rule is likely blocking the WordPress REST API. Allow requests to /wp-json/pageforge/v1/.', 'pageforge')
            );
        }

        $body = json_decode((string) \wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['authorization_servers'])) {
            return self::result(
                'rest',
                __('REST endpoint reachable', 'pageforge'),
                self::FAIL,
                __('The discovery document was reachable but did not contain the expected OAuth metadata.', 'pageforge'),
                __('Another plugin may be filtering REST responses. Test with other plugins disabled.', 'pageforge')
            );
        }

        return self::result('rest', __('REST endpoint reachable', 'pageforge'), self::OK, __('The Protected Resource Metadata document is served correctly.', 'pageforge'));
    }

    /**
     * Some hosts (notably CGI/FastCGI setups) drop the Authorization header
     * before PHP sees it, which makes every bearer token look invalid. This
     * cannot be detected from a normal admin page load, so it is reported as
     * a conditional caution with the actual fix.
     */
    private static function check_authorization_header(): array {
        $visible = isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $cgi = false !== stripos((string) ($_SERVER['GATEWAY_INTERFACE'] ?? ''), 'CGI')
            || false !== stripos((string) (PHP_SAPI), 'cgi');

        if ($visible) {
            return self::result('auth_header', __('Authorization header', 'pageforge'), self::OK, __('PHP can see Authorization headers on this request.', 'pageforge'));
        }

        return self::result(
            'auth_header',
            __('Authorization header', 'pageforge'),
            $cgi ? self::WARN : self::UNKNOWN,
            $cgi
                ? __('This site runs PHP through CGI/FastCGI, where some configurations strip the Authorization header before PHP receives it. Every bearer token then appears invalid.', 'pageforge')
                : __('No Authorization header was present on this admin request, which is normal. PageForge cannot confirm from here whether your host forwards it on API requests.', 'pageforge'),
            __('If clients report invalid credentials while the token is correct, add this to .htaccess: RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}] — or the nginx equivalent. Do not work around it by putting tokens in a URL.', 'pageforge')
        );
    }

    /** Validates our own metadata documents against what the spec requires. */
    private static function check_metadata_shape(): array {
        $prm = Oauth::protected_resource_metadata();
        $as = Oauth::authorization_server_metadata();
        $problems = [];

        if (empty($prm['resource'])) {
            $problems[] = __('resource identifier missing', 'pageforge');
        }
        if (empty($prm['authorization_servers'])) {
            $problems[] = __('authorization_servers missing', 'pageforge');
        }
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint'] as $required) {
            if (empty($as[$required])) {
                $problems[] = sprintf('%s missing', $required);
            }
        }
        if (!in_array('S256', (array) ($as['code_challenge_methods_supported'] ?? []), true)) {
            $problems[] = __('PKCE S256 not advertised', 'pageforge');
        }
        // RFC 8414: the document's issuer must equal the identifier used to
        // build its well-known URL, or a conforming client rejects it.
        if (\untrailingslashit((string) ($as['issuer'] ?? '')) !== \untrailingslashit(Oauth::issuer())) {
            $problems[] = __('issuer does not match its own discovery URL', 'pageforge');
        }

        if (!empty($problems)) {
            return self::result(
                'metadata',
                __('OAuth metadata', 'pageforge'),
                self::FAIL,
                implode('; ', $problems),
                __('This indicates a PageForge configuration fault. Check that the WordPress Address and Site Address are correct.', 'pageforge')
            );
        }

        return self::result(
            'metadata',
            __('OAuth metadata', 'pageforge'),
            self::OK,
            sprintf(
                /* translators: %s: OAuth issuer URL. */
                __('Protected Resource Metadata and authorization server metadata are valid. Issuer: %s', 'pageforge'),
                (string) $as['issuer']
            )
        );
    }

    private static function check_cron(): array {
        if (defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON')) {
            return self::result(
                'cron',
                __('WordPress cron', 'pageforge'),
                self::WARN,
                __('WP-Cron is disabled by DISABLE_WP_CRON.', 'pageforge'),
                __('Long-running MCP jobs and scheduled generation depend on cron. Configure a real system cron to call wp-cron.php.', 'pageforge')
            );
        }
        return self::result('cron', __('WordPress cron', 'pageforge'), self::OK, __('WP-Cron is available for background MCP jobs.', 'pageforge'));
    }

    /**
     * Named, not guessed: only plugins actually known to interfere with REST
     * bearer authentication are listed, and the check reports presence rather
     * than asserting a fault.
     */
    private static function check_security_plugins(): array {
        $known = [
            'wordfence/wordfence.php' => 'Wordfence',
            'better-wp-security/better-wp-security.php' => 'Solid Security',
            'all-in-one-wp-security-and-firewall/wp-security.php' => 'All In One WP Security',
            'sucuri-scanner/sucuri.php' => 'Sucuri',
            'disable-json-api/disable-json-api.php' => 'Disable REST API',
        ];
        $active = [];
        if (function_exists('is_plugin_active')) {
            foreach ($known as $file => $name) {
                if (\is_plugin_active($file)) {
                    $active[] = $name;
                }
            }
        }
        if (empty($active)) {
            return self::result('security_plugins', __('Security plugins', 'pageforge'), self::OK, __('No plugin known to block REST bearer authentication was detected.', 'pageforge'));
        }
        return self::result(
            'security_plugins',
            __('Security plugins', 'pageforge'),
            self::WARN,
            sprintf(
                /* translators: %s: comma-separated plugin names. */
                __('Detected: %s. These can block REST requests or strip Authorization headers.', 'pageforge'),
                implode(', ', $active)
            ),
            __('Allow-list /wp-json/pageforge/v1/mcp and the OAuth endpoints in that plugin. Do not disable it wholesale.', 'pageforge')
        );
    }

    /**
     * Evidence-based connection state. "Connected" is only ever claimed on the
     * basis of an observed authenticated request, never on the basis of having
     * created a credential.
     */
    private static function check_last_client_request(): array {
        $latest = 0;
        $client = '';
        foreach (Mcp_Credentials::all() as $credential) {
            if (!is_array($credential)) {
                continue;
            }
            $used = (int) ($credential['last_used'] ?? 0);
            if ($used > $latest) {
                $latest = $used;
                $client = (string) ($credential['label'] ?? '');
            }
        }
        foreach (Oauth_Tokens::grants_redacted() as $grant) {
            $issued = (int) ($grant['issued'] ?? 0);
            if ($issued > $latest) {
                $latest = $issued;
                $client = (string) ($grant['client_id'] ?? '');
            }
        }

        if ($latest <= 0) {
            return self::result(
                'last_request',
                __('Client activity', 'pageforge'),
                self::WARN,
                __('No AI client has successfully authenticated yet.', 'pageforge'),
                __('Complete the setup in your AI client. PageForge will report the connection here once it sees an authenticated request.', 'pageforge')
            );
        }

        return self::result(
            'last_request',
            __('Client activity', 'pageforge'),
            self::OK,
            sprintf(
                /* translators: 1: client name, 2: human-readable time difference. */
                __('Last authenticated request: %1$s, %2$s ago.', 'pageforge'),
                '' !== $client ? $client : __('an MCP client', 'pageforge'),
                (string) \human_time_diff($latest, time())
            )
        );
    }
}
