<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

/**
 * PageForge MCP scope catalog.
 *
 * Scopes are the single source of truth for "what may this grant do", shared
 * by three enforcement points that must never disagree:
 *
 *   1. The OAuth authorization endpoint, which may only offer a user scopes
 *      this site/edition can actually honour.
 *   2. The token endpoint, which may never widen a grant on refresh.
 *   3. The MCP transport, which filters the tool catalog per request and
 *      returns a 403 `insufficient_scope` challenge naming the scopes a
 *      call actually needs.
 *
 * Deliberately granular rather than one blanket admin scope: a connected
 * ChatGPT/Claude app that only needs to read templates must not receive a
 * grant that could publish pages. Scope hierarchy is explicit (see
 * `implied_by()`) because the spec requires servers to account for broader
 * scopes implying narrower ones when judging sufficiency.
 *
 * A scope authorises an *intent*. It never substitutes for the WordPress
 * capability check on the acting user, which still runs on every call.
 */
final class Mcp_Scopes {
    /** Read PageForge configuration, templates, pages, status. */
    public const READ = 'pageforge:read';
    /** Compute previews/dry-runs. Costs AI budget, so separated from read. */
    public const PREVIEW = 'pageforge:preview';
    /** Create/update PageForge-generated content. Pro. */
    public const WRITE = 'pageforge:write';
    /** Campaign and queue operation. Pro. */
    public const CAMPAIGNS = 'pageforge:campaigns';
    /** SEO metadata and schema mutation. Pro. */
    public const SEO = 'pageforge:seo';
    /** Datasets, CSV, Google Sheets. Pro. */
    public const CONTENT = 'pageforge:content';
    /** GA4 / Search Console reporting reads. Pro. */
    public const ANALYTICS = 'pageforge:analytics';
    /** Settings, credentials, connector administration. Pro, never default. */
    public const ADMIN = 'pageforge:admin';

    /**
     * `offline_access` is requested by clients that want a refresh token. Per
     * the spec a protected resource MUST NOT advertise it in
     * `scopes_supported` or in a WWW-Authenticate challenge, because it is a
     * client concern and not a requirement of this resource. It is honoured
     * at the token endpoint only.
     */
    public const OFFLINE_ACCESS = 'offline_access';

    /** Scope names issued by early MCP Lite builds. */
    private const LEGACY_SCOPE_MAP = [
        'read' => self::READ,
        'preview' => self::PREVIEW,
        'write' => self::WRITE,
    ];

    /**
     * @return array<string, array{label:string, description:string, write:bool, pro:bool}>
     */
    public static function catalog(): array {
        return [
            self::READ => [
                'label' => __('Read PageForge data', 'pageforge'),
                'description' => __('View templates, generated pages, SEO status, and site diagnostics.', 'pageforge'),
                'write' => false,
                'pro' => false,
            ],
            self::PREVIEW => [
                'label' => __('Preview and validate', 'pageforge'),
                'description' => __('Run previews, dry runs, and validation without changing anything.', 'pageforge'),
                'write' => false,
                'pro' => false,
            ],
            self::WRITE => [
                'label' => __('Create and update content', 'pageforge'),
                'description' => __('Create, update, and publish PageForge-generated pages.', 'pageforge'),
                'write' => true,
                'pro' => true,
            ],
            self::CAMPAIGNS => [
                'label' => __('Operate campaigns', 'pageforge'),
                'description' => __('Start, pause, resume, retry, and cancel generation campaigns.', 'pageforge'),
                'write' => true,
                'pro' => true,
            ],
            self::SEO => [
                'label' => __('Manage SEO', 'pageforge'),
                'description' => __('Update meta titles, descriptions, schema, and internal links.', 'pageforge'),
                'write' => true,
                'pro' => true,
            ],
            self::CONTENT => [
                'label' => __('Manage data sources', 'pageforge'),
                'description' => __('Manage datasets, CSV imports, and Google Sheets connections.', 'pageforge'),
                'write' => true,
                'pro' => true,
            ],
            self::ANALYTICS => [
                'label' => __('Read analytics', 'pageforge'),
                'description' => __('Read GA4 and Search Console performance data for generated pages.', 'pageforge'),
                'write' => false,
                'pro' => true,
            ],
            self::ADMIN => [
                'label' => __('Administer PageForge', 'pageforge'),
                'description' => __('Change PageForge settings and connector configuration.', 'pageforge'),
                'write' => true,
                'pro' => true,
            ],
        ];
    }

    public static function all(): array {
        return array_keys(self::catalog());
    }

    public static function exists(string $scope): bool {
        return isset(self::catalog()[$scope]);
    }

    public static function is_write(string $scope): bool {
        return (bool) (self::catalog()[$scope]['write'] ?? false);
    }

    public static function requires_pro(string $scope): bool {
        return (bool) (self::catalog()[$scope]['pro'] ?? false);
    }

    public static function label(string $scope): string {
        return (string) (self::catalog()[$scope]['label'] ?? $scope);
    }

    public static function description(string $scope): string {
        return (string) (self::catalog()[$scope]['description'] ?? '');
    }

    /**
     * Scope hierarchy. `pageforge:admin` is deliberately NOT a superset of
     * everything: administering settings is a different act from bulk
     * rewriting content, and collapsing them would recreate the single
     * all-powerful scope this catalog exists to avoid.
     *
     * @return string[] Scopes that, if granted, also satisfy $scope.
     */
    public static function implied_by(string $scope): array {
        $hierarchy = [
            // Anything that can mutate content can also read and preview it.
            self::READ => [self::PREVIEW, self::WRITE, self::CAMPAIGNS, self::SEO, self::CONTENT],
            self::PREVIEW => [self::WRITE, self::CAMPAIGNS],
        ];
        return $hierarchy[$scope] ?? [];
    }

    /**
     * Whether a granted scope set satisfies a required scope, honouring the
     * hierarchy above.
     *
     * @param string[] $granted
     */
    public static function satisfies(array $granted, string $required): bool {
        if ('' === $required) {
            return false;
        }
        if (in_array($required, $granted, true)) {
            return true;
        }
        foreach (self::implied_by($required) as $broader) {
            if (in_array($broader, $granted, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Scopes this installation can actually grant right now.
     *
     * Free can only ever grant the read/preview pair, because Free registers
     * no write tool at all. Pro scopes additionally require a live entitlement
     * so an expired licence cannot keep handing out write grants.
     *
     * @return string[]
     */
    public static function grantable(): array {
        $pro = self::pro_active();
        $out = [];
        foreach (self::catalog() as $scope => $meta) {
            if (!empty($meta['pro']) && !$pro) {
                continue;
            }
            $out[] = $scope;
        }
        return $out;
    }

    /**
     * Default resource scopes offered to a newly connected client.
     *
     * Free resolves to read/preview only. A licensed Pro installation also
     * advertises its operational read/write scopes so clients can discover and
     * authorize the tools during their initial scan. The administration scope
     * remains explicit opt-in and is never part of a default grant.
     *
     * @return string[]
     */
    public static function advertised(): array {
        return array_values(array_diff(self::grantable(), [self::ADMIN]));
    }

    /**
     * Reduce a requested scope set to what may actually be granted.
     * Unknown scopes are dropped rather than rejected, per OAuth 2.1's
     * guidance that the AS may issue a narrower scope than requested; the
     * token response then echoes the effective scope so the client can see
     * exactly what it got.
     *
     * @param string[] $requested
     * @return string[]
     */
    public static function filter_grantable(array $requested): array {
        $grantable = self::grantable();
        $out = [];
        foreach ($requested as $scope) {
            $scope = trim((string) $scope);
            $scope = self::LEGACY_SCOPE_MAP[$scope] ?? $scope;
            if (self::OFFLINE_ACCESS === $scope) {
                continue; // Handled separately; never a resource scope.
            }
            if (in_array($scope, $grantable, true) && !in_array($scope, $out, true)) {
                $out[] = $scope;
            }
        }
        return $out;
    }

    /**
     * Canonicalize known scopes without applying edition/entitlement limits.
     * Used when comparing an existing grant with a refresh request so legacy
     * names remain usable but unknown or wider scopes are still rejected.
     *
     * @param string[] $scopes
     * @return string[]
     */
    public static function canonicalize(array $scopes): array {
        $out = [];
        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            $scope = self::LEGACY_SCOPE_MAP[$scope] ?? $scope;
            if ('' !== $scope && !in_array($scope, $out, true)) {
                $out[] = $scope;
            }
        }
        return $out;
    }

    /**
     * Parse an OAuth space-delimited scope string.
     *
     * @return string[]
     */
    public static function parse(string $scope): array {
        $parts = preg_split('/\s+/', trim($scope)) ?: [];
        return array_values(array_filter(array_map('strval', $parts), static function (string $value): bool {
            return '' !== $value;
        }));
    }

    /**
     * @param string[] $scopes
     */
    public static function to_string(array $scopes): string {
        return implode(' ', array_values(array_unique($scopes)));
    }

    /**
     * Live Pro entitlement. Checked at call time, never cached into a token,
     * so a lapsed licence stops authorising paid scopes immediately.
     */
    public static function pro_active(): bool {
        if (Edition::PRO !== Edition::current()) {
            return false;
        }
        /**
         * Pro overrides this with its Freemius check. Free ships the filter so
         * the scope catalog needs no edition-specific branching.
         */
        return (bool) \apply_filters('pageforge_pro_entitlement_active', false);
    }
}
