<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * Request-context helpers for the PageForge MCP/OAuth surface.
 *
 * Client IP resolution defaults to REMOTE_ADDR, which is the only address the
 * web server actually observed. `X-Forwarded-For` is attacker-controlled on a
 * site that is not behind a proxy that overwrites it, so trusting it by
 * default would let a caller forge the identity used for rate limiting and
 * audit records. Forwarded headers are only consulted when the site explicitly
 * declares a trusted-proxy strategy.
 */
final class Request {
    /**
     * @return string A validated IP string, or '' if none could be determined.
     */
    public static function client_ip(): string {
        $remote = self::validate_ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        if (!self::trusts_proxy($remote)) {
            return $remote;
        }

        foreach (self::forwarded_headers() as $header) {
            $raw = (string) ($_SERVER[$header] ?? '');
            if ('' === $raw) {
                continue;
            }
            // XFF is a comma-separated chain appended by each hop. The
            // left-most entry is the original client but is also the one a
            // caller can forge; with a trusted proxy in front, the right-most
            // untrusted entry is the correct choice.
            $parts = array_reverse(array_map('trim', explode(',', $raw)));
            foreach ($parts as $candidate) {
                $candidate = self::normalize_forwarded($candidate);
                $ip = self::validate_ip($candidate);
                if ('' !== $ip && !self::is_trusted_proxy_ip($ip)) {
                    return $ip;
                }
            }
        }

        return $remote;
    }

    /**
     * A truncated, non-reversible representation for audit records. Storing
     * full addresses of every agent call is more personal data than an audit
     * log needs; the /24 (or /48 for v6) is enough to spot an anomaly.
     */
    public static function client_ip_for_audit(): string {
        $ip = self::client_ip();
        if ('' === $ip) {
            return '';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $octets = explode('.', $ip);
            return $octets[0] . '.' . $octets[1] . '.' . $octets[2] . '.0/24';
        }
        $groups = explode(':', $ip);
        return implode(':', array_slice($groups, 0, 3)) . '::/48';
    }

    /**
     * Proxy trust is opt-in. `PAGEFORGE_TRUSTED_PROXIES` (constant or filter)
     * holds CIDR ranges or exact IPs of proxies the site sits behind.
     *
     * @return string[]
     */
    public static function trusted_proxies(): array {
        $configured = [];
        if (defined('PAGEFORGE_TRUSTED_PROXIES')) {
            $value = constant('PAGEFORGE_TRUSTED_PROXIES');
            $configured = is_array($value) ? $value : explode(',', (string) $value);
        }
        /** Allows a host to declare its load balancer without editing wp-config. */
        $configured = \apply_filters('pageforge_trusted_proxies', $configured);
        return array_values(array_filter(array_map('trim', array_map('strval', (array) $configured))));
    }

    private static function trusts_proxy(string $remote): bool {
        $proxies = self::trusted_proxies();
        if (empty($proxies)) {
            return false;
        }
        return '' !== $remote && self::is_trusted_proxy_ip($remote);
    }

    private static function is_trusted_proxy_ip(string $ip): bool {
        foreach (self::trusted_proxies() as $range) {
            if (self::ip_in_range($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] */
    private static function forwarded_headers(): array {
        return ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'];
    }

    /** Strips the port and brackets some proxies add (`[::1]:443`, `1.2.3.4:56`). */
    private static function normalize_forwarded(string $value): string {
        $value = trim($value);
        if ('' === $value) {
            return '';
        }
        if ('[' === $value[0]) {
            $end = strpos($value, ']');
            return false === $end ? '' : substr($value, 1, $end - 1);
        }
        // Only strip a port from IPv4; a bare IPv6 address is full of colons.
        if (substr_count($value, ':') === 1) {
            $value = (string) strstr($value, ':', true);
        }
        return $value;
    }

    private static function validate_ip(string $ip): string {
        $ip = trim($ip);
        if ('' === $ip || strlen($ip) > 45) {
            return '';
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    public static function ip_in_range(string $ip, string $range): bool {
        if ('' === $ip || '' === $range) {
            return false;
        }
        if (false === strpos($range, '/')) {
            return \hash_equals($range, $ip);
        }
        [$subnet, $bits] = explode('/', $range, 2);
        $bits = (int) $bits;
        $ip_bin = @inet_pton($ip);
        $subnet_bin = @inet_pton($subnet);
        if (false === $ip_bin || false === $subnet_bin || strlen($ip_bin) !== strlen($subnet_bin)) {
            return false;
        }
        $max_bits = strlen($ip_bin) * 8;
        if ($bits < 0 || $bits > $max_bits) {
            return false;
        }
        $whole = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($whole > 0 && !\hash_equals(substr($subnet_bin, 0, $whole), substr($ip_bin, 0, $whole))) {
            return false;
        }
        if (0 === $remainder) {
            return true;
        }
        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);
        return (ord($ip_bin[$whole]) & ord($mask)) === (ord($subnet_bin[$whole]) & ord($mask));
    }

    /**
     * Whether the current request reached WordPress over TLS. Remote MCP
     * connectors require https, so this drives an honest readiness signal
     * instead of letting a connection fail opaquely later.
     */
    public static function site_is_https(): bool {
        return 'https' === strtolower((string) \wp_parse_url((string) \home_url(), PHP_URL_SCHEME));
    }
}
