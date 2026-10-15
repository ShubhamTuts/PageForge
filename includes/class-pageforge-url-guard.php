<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * SSRF guard for admin-configured outbound URLs (custom AI base URL today;
 * any future URL-fetching connector should reuse this).
 *
 * Blocks loopback, private/link-local ranges, and the common cloud metadata
 * addresses. DNS is resolved and the resolved IP is checked too, so a
 * hostname that merely points at a private address is caught, not just a
 * literal IP. Only http/https are accepted.
 */
function pf_url_is_safe_remote_target(string $url, ?callable $resolver = null): bool {
    $url = trim($url);
    if ('' === $url) {
        return false;
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    $host = strtolower((string) $parts['host']);
    if ('localhost' === $host || str_ends_with($host, '.localhost')) {
        return false;
    }

    // Cloud metadata endpoints used across AWS/GCP/Azure/DigitalOcean/etc.
    $blocked_hosts = ['169.254.169.254', 'metadata.google.internal'];
    if (in_array($host, $blocked_hosts, true)) {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return pf_ip_is_safe_remote_target($host);
    }

    // Resolver is injectable so this can be unit tested without depending on
    // real DNS/network access, and so a host with a non-standard resolver can
    // supply its own. Production resolves via gethostbyname().
    if (null === $resolver && function_exists('apply_filters')) {
        $filtered = \apply_filters('pageforge_url_guard_resolver', null, $host);
        if (is_callable($filtered)) {
            $resolver = $filtered;
        }
    }
    $resolver = $resolver ?? 'gethostbyname';
    $resolved = (string) $resolver($host);

    // gethostbyname() returns the original hostname string when resolution
    // fails; that is indistinguishable from success for an IP-shaped name,
    // so an unresolvable hostname is rejected rather than treated as safe.
    if ($resolved === $host || !filter_var($resolved, FILTER_VALIDATE_IP)) {
        return false;
    }

    return pf_ip_is_safe_remote_target($resolved);
}

function pf_ip_is_safe_remote_target(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    // Reject anything not a public unicast address: loopback, private
    // (RFC1918), link-local (including the 169.254/16 metadata range and
    // IPv6 fe80::/10), and reserved ranges.
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }
    return true;
}
