<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * Rate limiting for the PageForge MCP and OAuth endpoints.
 *
 * The threat this addresses is not abuse by a legitimate agent, it is that
 * every unauthenticated request to an MCP or token endpoint otherwise costs a
 * full WordPress bootstrap plus a credential verification. Without a bound, a
 * brute-force attempt against the token endpoint is also a cheap denial of
 * service against the site.
 *
 * Counters live in transients: expiry is automatic, so there is no cleanup
 * cron to forget, and a missing object cache degrades to the options table
 * with a bounded key set rather than growing without limit.
 *
 * Buckets are fixed windows keyed by action + subject. Fixed windows can allow
 * up to 2x the limit across a window boundary; that is an acceptable trade for
 * storage that cannot leak, since these limits exist to stop brute force and
 * runaway cost, not to meter billing precisely.
 */
final class Mcp_Rate_Limit {
    private const PREFIX = 'pageforge_rl_';

    /**
     * Buckets: [limit, window seconds].
     *
     * Failed authentication is far tighter than successful traffic, because a
     * high failure rate from one source is only ever a scan or a brute force.
     */
    private const BUCKETS = [
        'auth_fail' => [10, 300],
        'oauth_authorize' => [30, 300],
        'oauth_token' => [30, 300],
        'oauth_register' => [5, 3600],
        'mcp_read' => [600, 300],
        'mcp_preview' => [60, 300],
        'mcp_write' => [120, 300],
    ];

    /**
     * Consume one unit from a bucket.
     *
     * @param string $action  One of BUCKETS.
     * @param string $subject Stable identity: credential id, client id, or IP.
     * @return bool True when the call is allowed.
     */
    public static function allow(string $action, string $subject): bool {
        [$limit, $window] = self::bucket($action);
        if ($limit <= 0) {
            return true;
        }

        $key = self::key($action, $subject, $window);
        $count = (int) \get_transient($key);
        if ($count >= $limit) {
            return false;
        }

        // Re-setting with the remaining window keeps the bucket anchored to
        // the window start rather than sliding forward on every hit, which
        // would let a steady stream hold a bucket open indefinitely.
        \set_transient($key, $count + 1, self::remaining($window));
        return true;
    }

    /**
     * Seconds a client should wait, for a Retry-After header.
     */
    public static function retry_after(string $action): int {
        [, $window] = self::bucket($action);
        return self::remaining($window);
    }

    public static function limit_for(string $action): int {
        return self::bucket($action)[0];
    }

    /**
     * Clear a subject's failure bucket after a success, so a user who
     * mistyped a token a few times is not locked out once they get it right.
     */
    public static function forget(string $action, string $subject): void {
        [, $window] = self::bucket($action);
        \delete_transient(self::key($action, $subject, $window));
    }

    /**
     * Map an MCP scope to the cost bucket a tool call should draw from, so an
     * AI-backed preview is not metered as cheaply as a status read.
     */
    public static function bucket_for_scope(string $scope): string {
        if (Mcp_Scopes::PREVIEW === $scope) {
            return 'mcp_preview';
        }
        return Mcp_Scopes::is_write($scope) ? 'mcp_write' : 'mcp_read';
    }

    private static function bucket(string $action): array {
        $buckets = \apply_filters('pageforge_mcp_rate_limits', self::BUCKETS);
        $bucket = $buckets[$action] ?? self::BUCKETS[$action] ?? [0, 60];
        return [(int) ($bucket[0] ?? 0), max(1, (int) ($bucket[1] ?? 60))];
    }

    private static function remaining(int $window): int {
        return $window - (time() % $window);
    }

    /**
     * The subject is hashed so no raw token fragment or IP address is ever
     * written into an option/transient name.
     */
    private static function key(string $action, string $subject, int $window): string {
        $slot = intdiv(time(), $window);
        return self::PREFIX . substr(hash('sha256', $action . '|' . $subject . '|' . $slot), 0, 24);
    }
}
