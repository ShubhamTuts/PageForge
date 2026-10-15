# PageForge Pro Alignment

## Targeted hierarchy parity — 2026-10-15

Both editions support explicit/global template targets, clear output destinations, and static native parent Pages. These are core Free capabilities. Premium adds opt-in Parent ID/Parent Path mapping with active Starter-or-higher entitlement checked on execution; Google Sheets retains Professional/Enterprise gating. Native hierarchy and legacy namespace URLs must survive edition changes. Neither edition converts existing content or creates redirects automatically. See [FIX_REPORT.md](FIX_REPORT.md).

Date: 2026-08-03

Scope: Free repository audit with read-only comparison against `C:\Users\wpshu\OneDrive\Documents\pageforge-premium`.

## Premium Strengths To Preserve

Premium already contains several features that should inform Free UX and future MCP design:

- Freemius plan and entitlement helpers.
- Queue processing and campaign tracking.
- Scheduled generation and daily limits.
- Google Sheets ingestion through API v4 or public CSV export fallback.
- Bulk AI metadata workflow.
- AI usage tracking.
- Dashboard overview metrics.
- HTML/script injection controls.
- More robust duplicate handling through row fingerprints.
- AI content token support for generated page uniqueness.
- Pexels image generation support in page generation.

## Major Free/Pro Drift

Free uses:

- `pageforge_template`
- `pageforge_page`
- `_pageforge_*` metadata

Premium uses:

- `pf_template`
- `pf_page`
- `_pf_*` metadata

This drift creates upgrade risk. A user who starts with WordPress.org Free may expect templates and generated pages to continue appearing after installing Pro. Without migration or compatibility, that path can look broken.

## Required Migration Strategy

Before shipping a major Free/Pro connector or UI release:

1. Add a Pro migration detector for existing `pageforge_template` and `pageforge_page` content.
2. Offer a dry-run migration screen with counts and sample records.
3. Map free template metadata to premium template metadata.
4. Map generated page metadata and row variables to premium `_pf_*` equivalents.
5. Preserve slugs, post status, parent relationships, SEO metadata, and builder metadata.
6. Store a migration marker to avoid repeat conversion.
7. Add rollback guidance or non-destructive copy mode for first release.

## Pricing Boundary

Approved split:

- Free: CSV workflows, cleaned UI, AI provider setup, connection health checks, MCP Lite, read-only/dry-run tools, locked Pro previews.
- Starter/Pro/Enterprise: queue, scheduling, bulk metadata, Google Sheets, AI page uniqueness, analytics opportunities, real MCP write tools, audit logs, scopes, revocation, advanced builder compatibility.

Perplexity:

- In Free, show a locked Pro card only.
- In Pro, implement when Perplexity remote MCP support and account availability are stable enough to document.

## Premium i18n Gap

The free plugin loads textdomain and has translation files. Premium does not appear aligned to that standard. Premium should inherit the same i18n discipline before public multilingual claims expand:

- Load plugin textdomain.
- Localize admin JavaScript.
- Extract PHP and JS strings.
- Add or share POT/PO workflow.
- Remove mojibake and hard-coded English strings.

## MCP Pro Reuse Opportunities

The Pro implementation should reuse:

- Entitlements from `pf_feature_allowed()`.
- Queue and campaign state from `class-pageforge-cron.php`.
- Google Sheets access from `class-pageforge-datasource.php`.
- Bulk meta generation from premium AI/admin handlers.
- AI usage tracking and quota reporting from helper functions.
- Existing generator safety controls and duplicate/fingerprint handling.

Do not copy premium internals into Free blindly. Use them as the Pro target behavior and keep Free-compatible surfaces explicit.
