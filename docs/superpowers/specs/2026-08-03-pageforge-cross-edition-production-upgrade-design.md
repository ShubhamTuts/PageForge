# PageForge Cross-Edition Production Upgrade Design

**Date:** 2026-08-03

**Status:** Approved for planning

**Target releases:** PageForge Free 1.2.0 and PageForge Pro 1.1.0

## 1. Purpose

This release program upgrades PageForge Free and PageForge Pro as one product without breaking existing sites. It improves the WordPress admin workflow, introduces a secure MCP server and AI connector experience, preserves content created by either edition, and asks Free users for an honest WordPress.org review only after a proven success moment.

The product should feel like an SEO operations workspace rather than a collection of unrelated generators. Remote assistants must be able to inspect PageForge state safely, preview changes, and, in Pro, execute explicitly authorized changes with a complete audit trail.

## 2. Release Decisions

The approved release decisions are:

1. Free moves from 1.1.8 to 1.2.0.
2. Pro moves from 1.0.10 to 1.1.0.
3. Existing Free and Pro content identifiers remain readable. No activation routine renames or deletes posts, post meta, options, attachments, campaigns, or queues.
4. MCP is disabled by default in both editions.
5. Free includes read-only and dry-run MCP tools.
6. Pro includes scoped write tools. Every sensitive write follows preview, confirmation, execution, and audit.
7. Provider credentials and MCP credentials are managed from a dedicated Connections screen.
8. Perplexity appears as a locked Pro connector in Free. Pro implements the same remote MCP endpoint used by ChatGPT, Claude, Cursor, Perplexity, and other compatible clients.
9. The Free review request appears only after the first successful bulk-generation win and remains honest, dismissible, and limited.
10. Shared behavior is implemented with matching interfaces in both repositories, while Pro-only files remain independently releasable.

## 3. Goals

- Preserve all existing generated content, templates, metadata, queues, campaigns, schedules, settings, and builder data.
- Give users a clear workflow from connection and data setup through generation, review, optimization, and measurement.
- Support current and recent MCP clients over remote HTTP.
- Let Free users inspect site and SEO state and preview operations without remote writes.
- Let Pro users perform controlled programmatic SEO operations through an AI assistant.
- Reuse one tool registry for remote MCP and the PageForge admin AI assistant.
- Improve accessibility, translation coverage, responsive behavior, status feedback, and error recovery.
- Make product and WordPress.org claims match shipped behavior.
- Add automated regression coverage before changing production behavior.

## 4. Non-Goals

- No destructive automatic migration between `pf_*` and `pageforge_*` identifiers.
- No MCP endpoint enabled automatically after upgrade.
- No anonymous MCP access.
- No AI tool may publish, delete, or overwrite content without the required capability, scope, and confirmation.
- No claim that existing GA4 Measurement Protocol credentials can read Analytics reports. Read access requires a separate Google authorization connection.
- No fake review counts, forced ratings, review incentives, or repeated site-wide review notices.
- No redesign of the public WordPress theme output outside PageForge-owned blocks, sitemap output, and generated metadata.

## 5. Compatibility Architecture

### 5.1 Current identifier split

Free currently uses:

- Post types: `pageforge_template`, `pageforge_page`
- Post meta: `_pageforge_*`
- Shared option prefix: `pageforge_*`

Pro currently uses:

- Post types: `pf_template`, `pf_page`
- Post meta: `_pf_*`
- Shared option prefix: mostly `pageforge_*`

This split is the highest upgrade risk. Pro must not assume that only its legacy identifiers exist.

### 5.2 Compatibility service

Both editions receive a small compatibility service with logical accessors for:

- template post types
- generated post types
- generated marker
- template ID
- source row and fingerprint
- title, slug, description, and schema patterns
- campaign and queue references
- builder metadata

Reads check the canonical key first and then known aliases. Writes use the selected site namespace and may mirror only the minimum compatibility marker needed by the other edition.

Direct `get_post_meta()` and `update_post_meta()` calls for shared PageForge fields are gradually routed through the compatibility service. Builder-owned keys remain untouched.

### 5.3 Namespace selection

An idempotent upgrade routine stores `pageforge_content_namespace` once:

1. Use `pageforge` when Free content already exists.
2. Otherwise use `pf` when legacy Pro content exists.
3. Otherwise use `pageforge` for a new installation.

The selected namespace does not change automatically later. Both namespaces remain queryable so mixed sites continue to work.

### 5.4 Registration and admin queries

Pro registers both legacy and current PageForge post types when corresponding content exists. Only the selected namespace is used for new templates and generated content. Admin lists, duplicate detection, sitemap output, bulk metadata operations, reports, MCP tools, and cleanup logic query both namespaces.

Free reads legacy Pro-generated content for reporting and sitemap purposes but does not expose Pro-only controls when Pro is inactive.

### 5.5 Migration center

Pro exposes a migration diagnostic with three steps:

- Scan: count both namespaces and identify conflicts.
- Preview: show the exact metadata aliases or registrations required.
- Repair: add missing compatibility metadata only after confirmation.

Repair is idempotent and does not change post IDs, post types, URLs, slugs, status, authors, dates, revisions, builder payloads, or SEO-plugin metadata. A JSON report can be downloaded before and after repair.

## 6. Edition Capability Model

| Capability | Free 1.2.0 | Pro 1.1.0 |
| --- | --- | --- |
| CSV generation | Up to the existing Free per-run limit | Campaign queue and high-volume workflows |
| Google Sheets | Upgrade preview only | Read, preview, queue, and refresh |
| AI providers | OpenAI, Anthropic, Gemini, DeepSeek | Free providers plus custom compatible providers and Perplexity |
| Connection health | Credential and provider checks | Full provider, OAuth, analytics, and MCP checks |
| MCP server | Read-only and dry-run tools | Read, dry-run, and scoped write tools |
| MCP authentication | Rotatable API key and WordPress Application Password fallback | OAuth 2.1 plus Free methods for compatibility |
| MCP audit | Recent connection and dry-run events | Searchable write audit with retention controls |
| SEO workspace | Inventory, status, single-item assistance | Bulk fixes, opportunities, schema, technical controls |
| Analytics | Configuration status and event-send diagnostics | Authorized GA4 and Search Console read insights |
| Review request | First successful bulk-generation win | Not shown |

Entitlements are checked server-side. Locked UI is explanatory only and is never the security boundary.

## 7. Admin Information Architecture

The shared primary navigation becomes:

1. Dashboard
2. Campaigns
3. Data
4. Templates
5. SEO
6. AI Planner
7. Connections
8. Settings

Pro adds Queue and advanced operational tabs inside Campaigns rather than creating more unrelated top-level destinations.

### 7.1 Dashboard

The first screen answers:

- Is PageForge ready to generate safely?
- What happened recently?
- What requires attention?
- What is the next useful action?

It includes a compact setup checklist, recent campaign status, generated-page totals, connection health, unresolved errors, and one primary action. Marketing cards and repeated upgrade copy are removed.

### 7.2 Campaigns

Generation follows a visible sequence:

1. Select data.
2. Select template.
3. Choose publishing behavior.
4. Run preflight.
5. Generate or queue.
6. Review the result.

Preflight reports row count, missing tokens, unused columns, duplicate slugs, post type, builder compatibility, expected AI usage, and publish risk. The generate action remains disabled until blocking issues are resolved.

### 7.3 Data

Data shows sources in a compact table with type, row count, freshness, health, and actions. Upload and connection forms open in focused panels. A preview uses real headers and rows, not generic explanatory cards.

### 7.4 Templates

Templates show token coverage, target post type, builder type, last update, and generated-page count. The edit flow includes token insertion, metadata patterns, schema, preview, and builder diagnostics.

### 7.5 SEO

The current Blocks, Meta, and SEO-report surfaces become one workspace with tabs:

- Overview
- Metadata
- Schema
- Internal Links
- Sitemap and llms.txt
- Social and Images
- Technical

Free keeps read views and focused single-item actions. Pro adds bulk operations and opportunity queues.

### 7.6 AI Planner

The planner keeps conversation history, but the screen clearly separates plan generation from executable actions. Tool-backed answers cite the PageForge objects they inspected. Proposed changes appear as previews with affected item counts.

### 7.7 Connections

Connections owns provider keys, MCP controls, analytics authorization, status, and testing. Each row shows:

- provider name and purpose
- connected, incomplete, failing, or locked status
- last successful check
- configure, test, reconnect, or revoke action
- the data shared with that provider

Free shows Perplexity as a disabled Pro capability without accepting or storing a Perplexity credential.

### 7.8 Settings

Settings contains product defaults, brand context, schema defaults, publishing defaults, privacy, audit retention, diagnostics, and advanced compatibility controls. Provider secrets are removed from this screen after Connections owns them.

## 8. Visual And Interaction System

The visual thesis is a calm WordPress-native operations workspace with compact information density, strong status hierarchy, and one accent color for primary actions.

- Use 8px or smaller radii.
- Replace dashboard card mosaics with sections, rows, tables, and dividers.
- Avoid cards inside cards.
- Keep one primary action per screen region.
- Use icons for recognizable actions and text labels where ambiguity would remain.
- Preserve native WordPress focus states and minimum 44px touch targets for primary controls.
- Use stable grid and table dimensions so loading and status changes do not shift the layout.
- Use inline progress, skeletons, and status text for asynchronous work.
- Respect `prefers-reduced-motion`.
- Keep all visible strings translatable and all dynamic output escaped.
- Ensure the navigation and forms work at 320px width without horizontal overlap.

The UI does not contain explanatory marketing prose about its design. Copy is operational: status, scope, consequence, and action.

## 9. Review Request Design

### 9.1 Eligibility

The review request is Free-only and admin-only. A user becomes eligible when a bulk generation request creates at least one new PageForge item successfully. A request with zero created items or only errors, skips, or already-existing content is not a win.

If the generating user cannot manage plugins or settings, PageForge records the win but does not interrupt that user. The next eligible administrator can see the request on a PageForge screen after another successful operation.

### 9.2 Placement and copy

The first request appears inside the successful generation result, not as a site-wide dashboard notice:

> Your first PageForge batch was created successfully. If PageForge helped, would you share an honest review on WordPress.org?

Actions:

- Leave an honest review
- Maybe later
- Already reviewed
- Dismiss

The review link goes directly to the PageForge review form without affiliate or campaign tracking.

### 9.3 State machine

The option `pageforge_review_prompt` stores:

- `first_win_at`
- `status`: `pending`, `snoozed`, `reviewed`, or `dismissed`
- `snooze_until`
- `shown_count`
- `last_shown_at`

Rules:

- Show immediately after the first qualifying win.
- `Already reviewed` and `Dismiss` stop all future requests.
- `Maybe later` snoozes for 14 days.
- A snoozed request may return only on a PageForge screen and only after another successful generation.
- Never show more than twice in total.
- Never show in Pro.
- Never block navigation or generation.
- Store no remote analytics event for review-button behavior.

This follows the WordPress.org requirement that dashboard notices be limited, contextual, and dismissible.

## 10. MCP Server Architecture

### 10.1 Protocol and transport

The endpoint is:

`https://example.com/wp-json/pageforge/v1/mcp`

The server uses JSON-RPC 2.0 over Streamable HTTP. It supports the current MCP protocol revision `2026-07-28` and a compatibility path for `2025-06-18` clients. Version negotiation is explicit; unsupported versions return a protocol error rather than silently changing behavior. Current requests are stateless and carry protocol metadata per request. The legacy path retains the older initialize lifecycle only for compatible clients.

The current MCP transport sends each message to one HTTP endpoint and may return JSON or a request-scoped SSE response. The initial PageForge implementation returns JSON for bounded requests and adds SSE only for operations that genuinely stream progress.

Supported methods include:

- `ping`
- `tools/list`
- `tools/call`
- legacy `initialize` and initialized notifications for `2025-06-18` clients
- cancellation behavior appropriate to the negotiated revision

Malformed JSON, invalid JSON-RPC envelopes, unsupported methods, invalid arguments, and tool failures use distinct error responses. Responses include no-store cache headers and never expose PHP warnings, stack traces, credentials, filesystem paths, or raw provider responses.

### 10.2 Shared tool registry

`PageForge_Tool_Registry` is independent from HTTP. It owns:

- tool names and descriptions
- input and output schemas
- edition requirement
- capability and MCP scope
- risk level
- preview requirement
- handler callback
- redaction rules

Remote MCP, the admin AI assistant, tests, and future CLI integrations call the same registry. This prevents the chat UI and remote connectors from gaining different powers accidentally.

### 10.3 Free tools

Free exposes read-only and dry-run tools such as:

- site and PageForge status
- list and inspect templates
- list data sources and safe header summaries
- list generated pages and campaign relationships
- inspect metadata, schema, sitemap, internal-link, and builder status
- identify missing metadata and token coverage
- preview page generation
- preview metadata changes
- preview schema changes
- preview llms.txt content
- validate a proposed campaign before generation

Free tools do not publish, update, delete, upload, queue, schedule, or change settings.

### 10.4 Pro tools

Pro organizes 35 or more tools into stable groups:

- Campaigns: create, queue, pause, resume, retry, and inspect.
- Content: generate, update, refresh, and compare revisions.
- Metadata: titles, descriptions, canonical data, robots, and social fields.
- Schema: validate, preview, apply, and remove PageForge-owned schema.
- Technical SEO: sitemap, robots, llms.txt, indexing controls, and diagnostics.
- Internal links: opportunities, previews, and approved application.
- Images: missing alt text, previews, and approved updates.
- Builders: compatibility diagnostics, cache refresh, and safe PageForge-owned updates.
- Analytics: GA4 and Search Console opportunities when authorized.
- Operations: connection health, queue health, audit search, and migration diagnostics.

Every tool declares whether it is read-only, idempotent, destructive, or externally networked.

### 10.5 Preview-first writes

Sensitive Pro tools use this flow:

1. The assistant calls a preview tool.
2. PageForge validates permissions, scope, entitlement, arguments, and current object state.
3. PageForge returns affected objects, before-and-after fields, warnings, and a short-lived confirmation ID.
4. The assistant or user submits the confirmation ID to the matching execute tool.
5. PageForge verifies the ID is unexpired, unused, bound to the same client, user, tool, arguments, and object versions.
6. PageForge performs the operation and records the result.

Confirmation IDs are one-use hashes with a maximum lifetime of 10 minutes. Publishing, deletion, robots changes, indexing changes, bulk edits, and migrations always require confirmation. Read-only calls never do.

### 10.6 Authentication and scopes

Free supports these compatibility authentication modes:

- a generated high-entropy API key stored only as a password hash and accepted as a bearer credential
- WordPress Application Password fallback over HTTPS

Pro adds MCP OAuth 2.1 with:

- protected-resource metadata
- authorization-server metadata
- Authorization Code flow
- PKCE S256
- exact redirect URI validation
- state and issuer validation
- bearer tokens bound to the PageForge MCP resource
- expiring access tokens and revocable refresh tokens
- Client ID Metadata Documents where supported
- pre-registered clients and Dynamic Client Registration compatibility where required

Credentials are accepted in authorization headers only, never query parameters. Non-HTTPS remote access is rejected except an explicit local development mode.

Scopes are least-privilege and grouped by consequence:

- `pageforge:read`
- `pageforge:preview`
- `pageforge:content:write`
- `pageforge:seo:write`
- `pageforge:campaigns:write`
- `pageforge:publish`
- `pageforge:delete`
- `pageforge:analytics:read`
- `pageforge:settings:write`

WordPress capabilities, Pro entitlement, client scope, and tool confirmation must all pass. One check never substitutes for another.

### 10.7 Client compatibility

Connections provides tested setup instructions for ChatGPT/OpenAI, Claude, Cursor, Perplexity, and generic remote MCP clients.

Current official client guidance supports remote MCP servers over Streamable HTTP, and OpenAI and Claude both recommend approvals for sensitive actions. Perplexity now documents custom remote connectors with HTTPS, API key or OAuth authentication, and Streamable HTTP or SSE transport. The Free UI may still show Perplexity as Pro-locked because that is a PageForge product decision, not a protocol limitation.

## 11. Admin AI Operator

The existing PageForge AI planner and chat gain a bounded agent loop in Pro:

1. Build context from the current screen and selected objects.
2. Give the model only the allowed registry tools for that user and edition.
3. Execute read tools automatically within configured limits.
4. Render write previews as structured change sets.
5. Require explicit user confirmation before write execution.
6. Show tool results, affected object links, and audit IDs in the conversation.

The loop has maximum tool-call, elapsed-time, and token budgets. It stops on repeated failures and never retries non-idempotent writes automatically.

## 12. Analytics Connections

The current GA4 Measurement Protocol settings send PageForge events but do not authorize Analytics report reads. Connections therefore separates:

- GA4 event measurement: Measurement ID and API secret.
- Google Analytics Data: OAuth or service-account read authorization.
- Google Search Console: OAuth read authorization.

Pro analytics tools are registered only when the required read connection is healthy. They can identify high-impression low-CTR pages, declining pages, indexing gaps, and content-refresh candidates. Each result includes source, property, date range, and freshness.

Free may show connection readiness and a Pro explanation but cannot claim to read analytics with the existing event credentials.

## 13. Builder Compatibility

Builder support is implemented through adapters with a common contract:

- detect builder and ownership
- inspect required metadata
- clone only documented builder metadata
- replace PageForge tokens without corrupting serialized structures
- refresh builder caches or generated assets
- report compatibility and warnings in preflight

Adapters cover native blocks, classic content, Elementor, Divi, WPBakery, Beaver Builder, and Avada where the current code or verified builder APIs support safe behavior. Unsupported or changed builder versions produce a warning and default to drafts. Builder claims in the readme are limited to adapters that pass fixtures and local integration checks.

## 14. Error Handling and Recovery

- AJAX and REST errors use stable machine codes plus translated user messages.
- Provider health checks report the failing stage: configuration, DNS/TLS, authentication, permission, rate limit, protocol negotiation, or tool discovery.
- Bulk and MCP operations use idempotency keys where a retry could duplicate work.
- Queue items retain failure reasons and attempt counts.
- External calls use bounded timeouts and do not hold WordPress locks longer than necessary.
- Secrets and full content are redacted from logs by default.
- Schema changes use `dbDelta()` and a separate `PAGEFORGE_DB_VERSION`.
- Upgrade routines are repeatable and record completion only after every required step succeeds.
- Deactivation leaves content and settings intact. Uninstall behavior remains unchanged unless separately approved.

## 15. Audit Log

Pro stores MCP and admin-agent operation records in a dedicated table. Each record includes:

- timestamp
- WordPress user ID
- client ID and authentication type
- tool name and scope
- risk level
- dry-run or execute mode
- redacted argument summary
- affected object IDs
- status, duration, and error code
- confirmation and idempotency references

Secrets, OAuth tokens, API keys, full prompts, and unnecessary page content are never stored. Retention defaults to 30 days and is configurable. Administrators can export or purge logs.

Free keeps only minimal connection-health timestamps and review-prompt state in options.

## 16. Internationalization and Accessibility

- Load the text domain in both editions before translated strings are used.
- Replace mojibake and inconsistent punctuation with UTF-8 source strings.
- Refresh the POT from final source.
- Validate English US, English GB, German, French, and Portuguese catalogs.
- Do not concatenate translatable sentence fragments.
- Give icons accessible names and decorative icons `aria-hidden`.
- Associate every field with a label, description, and inline error region.
- Announce async status changes through restrained live regions.
- Support keyboard operation, visible focus, zoom to 200 percent, and reduced motion.

## 17. Testing Strategy

No automated test harness currently exists in either repository. The release adds a development-only test setup before production behavior changes.

### 17.1 Unit coverage

- compatibility alias resolution and namespace selection
- review-prompt eligibility and state transitions
- MCP JSON-RPC validation and version negotiation
- tool registry entitlement, capability, scope, and risk filtering
- confirmation token binding, expiry, and one-use behavior
- redaction and audit serialization
- preflight token and duplicate analysis
- connection-health error mapping

### 17.2 WordPress integration coverage

- activation and repeat activation on clean, Free-only, Pro-only, and mixed fixtures
- both post-type namespaces remain queryable
- Free-to-Pro upgrade preserves URLs, metadata, builder payloads, and generated markers
- AJAX permissions, nonces, sanitization, and response schemas
- REST authentication, 401/403 distinction, no-store headers, and disabled-by-default behavior
- campaign generation, queue retries, cron processing, and duplicate prevention
- review prompt appears only for a qualifying Free win
- translation loading and menu access by capability

### 17.3 MCP contract coverage

- current and legacy initialize requests
- tools/list filtering by edition and scope
- read and dry-run calls
- rejected unauthenticated, insufficient-scope, malformed, replayed, and expired calls
- confirmed Pro write and audit record
- OpenAI, Claude, Perplexity, Cursor, and MCP Inspector smoke tests against a local HTTPS WordPress site

### 17.4 UI verification

- desktop and mobile screenshots for every PageForge screen
- keyboard-only pass
- loading, empty, success, partial-success, locked, unauthorized, and error states
- no overlap at 320px, 768px, 1280px, and 1440px widths
- no console errors
- no layout shifts caused by loading or notices

### 17.5 Release checks

- PHP lint on every PHP file
- JavaScript syntax check
- WordPress coding and plugin checks
- readme validation and accurate external-service disclosures
- POT/PO validation and rebuilt MO files
- clean install, upgrade install, deactivate/reactivate, and rollback rehearsal
- ZIP comparison to ensure development dependencies and secrets are excluded

## 18. Rollout and Rollback

1. Land test infrastructure and compatibility services first.
2. Land the shared admin shell, Connections, and review prompt behind version-aware upgrade routines.
3. Land Free MCP read and preview tools disabled by default.
4. Land Pro write tools, audit, and OAuth behind the `mcp_operator` entitlement and disabled feature flag.
5. Test copied production data locally, including mixed namespace fixtures.
6. Release Free 1.2.0 and Pro 1.1.0 as separate artifacts with aligned changelogs.
7. Enable no MCP feature automatically during upgrade.

Rollback to the previous plugin code must leave content usable because the release does not destructively convert identifiers. New options and audit tables are ignored by older code. Any content created through the selected existing namespace remains visible after rollback.

## 19. Success Criteria

The release is ready only when:

- no existing Free or Pro content is lost, hidden, duplicated, or assigned a changed URL in upgrade fixtures
- all registered tests and release checks pass
- all PageForge admin screens complete their primary workflow without console or PHP errors
- MCP is unreachable when disabled and rejects invalid credentials when enabled
- Free exposes no write-capable MCP tool
- every sensitive Pro write requires the correct capability, entitlement, scope, and confirmation
- every executed Pro write creates a redacted audit record
- the review request obeys its complete state machine and WordPress.org notice guidance
- all public claims match tested capabilities
- translations contain no known broken or unreachable strings

## 20. Source Requirements

Implementation must be checked against current primary documentation before release:

- [MCP specification 2026-07-28](https://modelcontextprotocol.io/specification/2026-07-28)
- [MCP authorization](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization)
- [MCP transports](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports)
- [OpenAI MCP and connectors](https://developers.openai.com/api/docs/guides/tools-connectors-mcp)
- [Claude MCP connector](https://platform.claude.com/docs/en/agents-and-tools/mcp-connector)
- [Perplexity custom remote connectors](https://www.perplexity.ai/help-center/en/articles/13915507-adding-custom-remote-connectors)
- [WordPress.org detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
