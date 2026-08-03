# PageForge Pro Operator And Advanced SEO Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn PageForge Pro into a controlled programmatic SEO operator with 35 or more MCP tools, builder adapters, readable analytics, and a tool-backed admin AI assistant.

**Architecture:** Pro tools are grouped by domain and registered through the MCP registry from Plan 3. Domain services perform real work independently from MCP, allowing the admin UI and agent loop to reuse them. Write services expose preview and execute pairs, with confirmation, idempotency, entitlements, capabilities, scopes, revisions, and audit enforced centrally.

**Tech Stack:** PHP 7.4, WordPress posts/meta/revisions/REST/AJAX/cron, PageForge queue and campaigns, Yoast and Rank Math metadata, Google Analytics Data API, Google Search Console API, provider tool-calling APIs, MCP registry.

## Global Constraints

- `pf_feature_allowed('mcp_operator')` gates Pro operator discovery and execution.
- Read-only tools may run automatically within the admin agent's budgets; write tools never run without an approved preview.
- Publish, delete, robots, indexing, migration, and bulk operations always require confirmation.
- Every execute tool has one corresponding preview tool and declares `write => true`.
- All bulk tools accept `limit` between 1 and 100 and return a continuation cursor rather than processing an unbounded site.
- PageForge writes revisions before changing post title, content, excerpt, status, or PageForge-owned SEO fields.
- Third-party builder payloads are changed only by the adapter that owns them.
- Analytics tools are absent from discovery until the matching read connection is healthy.
- Search and analytics results include property, date range, timezone, and freshness.

---

### Task 1: Pro Operator Catalog And Domain Service Boundaries

**Files:**
- Create in Premium: `includes/class-pageforge-operator.php`
- Create in Premium: `includes/class-pageforge-operator-tools.php`
- Create in Premium: `tests/OperatorCatalogTest.php`
- Modify in Premium: `pageforge.php`
- Modify in Premium: `includes/class-pageforge-mcp-server.php`

**Interfaces:**
- Consumes: `Tool_Registry`, `MCP_Confirmation`, `MCP_Audit_Log`, PageForge entitlements, and current user capabilities.
- Produces: `Operator::authorize(array $principal, string $scope, string $capability, bool $require_pro = true)` returning `true` or `WP_Error`, `Operator::preview(string $operation, array $changes, array $principal): array`, and `Operator_Tools::definitions(): array`.

- [ ] **Step 1: Write failing catalog tests**

Assert that a paid principal with all scopes discovers at least 40 unique tool names, while a principal without `mcp_operator` discovers only Free tools. Assert every write tool declares an existing preview tool and every preview tool is read-only.

The required Pro catalog is:

```text
pageforge_list_campaigns
pageforge_get_campaign
pageforge_preview_create_campaign
pageforge_create_campaign
pageforge_preview_pause_campaign
pageforge_pause_campaign
pageforge_preview_resume_campaign
pageforge_resume_campaign
pageforge_preview_retry_campaign_items
pageforge_retry_campaign_items
pageforge_preview_cancel_campaign
pageforge_cancel_campaign
pageforge_get_queue_health
pageforge_preview_run_queue_batch
pageforge_run_queue_batch
pageforge_preview_generate_pages
pageforge_generate_pages
pageforge_preview_update_content
pageforge_update_content
pageforge_preview_publish_posts
pageforge_publish_posts
pageforge_preview_delete_generated_posts
pageforge_delete_generated_posts
pageforge_get_revision_diff
pageforge_preview_restore_revision
pageforge_restore_revision
pageforge_list_missing_metadata
pageforge_preview_update_metadata
pageforge_update_metadata
pageforge_get_schema_status
pageforge_preview_update_schema
pageforge_update_schema
pageforge_get_internal_link_opportunities
pageforge_preview_apply_internal_links
pageforge_apply_internal_links
pageforge_get_sitemap_status
pageforge_preview_update_llms_txt
pageforge_update_llms_txt
pageforge_get_robots_status
pageforge_preview_update_robots
pageforge_update_robots
pageforge_preview_update_robots_meta
pageforge_update_robots_meta
pageforge_list_missing_image_alt
pageforge_preview_update_image_alt
pageforge_update_image_alt
pageforge_preview_update_social_meta
pageforge_update_social_meta
pageforge_get_seo_score
pageforge_get_builder_status
pageforge_preview_refresh_builder_assets
pageforge_refresh_builder_assets
pageforge_get_ga4_landing_pages
pageforge_get_search_console_opportunities
pageforge_get_declining_pages
pageforge_get_migration_status
pageforge_preview_migration_repair
pageforge_apply_migration_repair
pageforge_query_audit_log
```

- [ ] **Step 2: Run catalog tests to verify RED**

Run `php tests\run.php` in Premium.

- [ ] **Step 3: Implement central authorization and preview envelope**

`authorize()` checks, in order, authenticated user, WordPress capability, paid entitlement, and exact scope. Return stable codes `authentication_required`, `capability_denied`, `upgrade_required`, and `insufficient_scope`.

`preview()` returns:

```php
[
    'operation' => $operation,
    'dry_run' => true,
    'affected_count' => count($changes),
    'changes' => $changes,
    'warnings' => [],
    'confirmation' => MCP_Confirmation::issue($principal, $operation, $changes, self::object_versions($changes), time()),
]
```

Limit returned changes to 100 and include a continuation cursor when more exist.

- [ ] **Step 4: Build and validate the catalog manifest**

Return all catalog definitions from `Operator_Tools::definitions()` with exact scopes, risk, capability, edition, input schema, and output schema. This task does not register the manifest with `MCP_Server`; each domain task binds its completed handlers, and Task 6 enables final discovery after the catalog completeness test proves every definition has a callable handler.

- [ ] **Step 5: Verify and commit the catalog boundary**

Run tests and lint. Commit the manifest and authorization boundary using `feat: add pro operator tool catalog`.

### Task 2: Campaign And Content Operator Services

**Files:**
- Create in Premium: `includes/class-pageforge-campaign-operator.php`
- Create in Premium: `includes/class-pageforge-content-operator.php`
- Create in Premium: `tests/CampaignOperatorTest.php`
- Create in Premium: `tests/ContentOperatorTest.php`
- Modify in Premium: `includes/class-pageforge-operator-tools.php`
- Modify in Premium: `includes/class-pageforge-generator.php`
- Modify in Premium: `includes/helpers.php`

**Interfaces:**
- Consumes: PageForge campaign and queue options, `Generator`, `Preflight`, `Compatibility`, revisions, confirmation context, and idempotency key.
- Produces: preview and execute methods for campaign create/pause/resume/retry/cancel/run, page generate/update/publish/delete, and revision restore.

- [ ] **Step 1: Write failing campaign tests**

Use in-memory campaign and queue fixtures. Test:

- create preview returns selected source/template, row count, preflight, and no mutations
- create execution queues exactly the previewed rows once
- repeated idempotency key returns the original campaign ID without duplicate rows
- pause and resume affect only the requested campaign
- retry changes only failed items whose retry time has elapsed
- cancel preview lists queued items; execute removes only that campaign's items
- batch run honors limit and entitlement

- [ ] **Step 2: Write failing content tests**

Test generation dry-run, content diff, publish status transition, delete-to-trash rather than permanent deletion, revision creation, restore preview, restore execution, stale object-version rejection, both content namespaces, and mixed successful/error batches.

- [ ] **Step 3: Run tests to verify RED**

Run `php tests\run.php`.

- [ ] **Step 4: Implement Campaign_Operator**

Expose public methods named after operations without the `pageforge_` prefix. Reuse existing queue normalization and generation functions instead of creating a second queue format. Require `edit_posts` for queue state changes and `publish_posts` for direct publish behavior.

- [ ] **Step 5: Implement Content_Operator**

Preview returns field-level before and after values, not complete raw builder payloads. Execute creates a WordPress revision with `wp_save_post_revision()` before updates. Delete uses `wp_trash_post()` and requires `delete_post` for every ID. Restore validates the revision belongs to the target post.

- [ ] **Step 6: Wire and verify tools**

Bind schemas to service methods in `Operator_Tools`. Run all Premium tests and lint.

- [ ] **Step 7: Commit campaign and content operators**

```powershell
git add includes/class-pageforge-campaign-operator.php includes/class-pageforge-content-operator.php includes/class-pageforge-operator-tools.php includes/class-pageforge-generator.php includes/helpers.php tests
git commit -m "feat: add campaign and content operator tools"
```

### Task 3: SEO Operator Services

**Files:**
- Create in Premium: `includes/class-pageforge-seo-operator.php`
- Create in Premium: `includes/class-pageforge-seo-score.php`
- Create in Premium: `tests/SeoOperatorTest.php`
- Create in Premium: `tests/SeoScoreTest.php`
- Modify in Premium: `includes/class-pageforge-operator-tools.php`
- Modify in Premium: `includes/class-pageforge-frontend.php`

**Interfaces:**
- Consumes: PageForge SEO meta, Yoast meta, Rank Math meta, visible post content, attachments, sitemap output, and WordPress robots filters.
- Produces: metadata, schema, internal-link, llms.txt, robots, image-alt, social-meta, and 13-factor score methods.

- [ ] **Step 1: Write failing SEO score tests**

Use literal fixtures for the 13 factors: title presence and length, description presence and length, focus keyword in title, keyword in introduction, one H1, heading hierarchy, internal link, external link, image alt, canonical/indexability, schema validity, and readable content length. Assert each factor returns `pass`, `warning`, or `fail`, one explanation, one action, and a deterministic weighted score from 0 to 100.

- [ ] **Step 2: Write failing SEO operation tests**

Test missing metadata inventory across both namespaces, metadata preview/write with Yoast and Rank Math synchronization, schema visible-content validation, PageForge-owned schema replacement without deleting third-party schema, internal link duplicate prevention, llms.txt preview, robots conflict detection, robots meta update, image alt update, and social metadata.

- [ ] **Step 3: Run tests to verify RED**

Run `php tests\run.php`.

- [ ] **Step 4: Implement score and SEO operations**

Keep score analysis pure where possible. Use `DOMDocument` only when available and a conservative fallback parser otherwise. Never invent ratings, reviews, addresses, prices, or business claims for schema. Refuse robots changes when `DISALLOW_FILE_EDIT` or an ownership conflict prevents safe PageForge control.

- [ ] **Step 5: Wire SEO tools and explanations**

Tool results link every recommendation to a post ID and factor. The `Explain with AI` UI introduced later receives the factor's structured evidence rather than sending the whole site by default.

- [ ] **Step 6: Verify and commit SEO operator**

Run tests and lint. Commit with `feat: add agentic seo operator tools`.

### Task 4: Builder Adapter Registry

**Files:**
- Create in Premium: `includes/builders/interface-pageforge-builder-adapter.php`
- Create in Premium: `includes/builders/class-pageforge-builder-registry.php`
- Create in Premium: `includes/builders/class-pageforge-builder-native.php`
- Create in Premium: `includes/builders/class-pageforge-builder-elementor.php`
- Create in Premium: `includes/builders/class-pageforge-builder-divi.php`
- Create in Premium: `includes/builders/class-pageforge-builder-wpbakery.php`
- Create in Premium: `includes/builders/class-pageforge-builder-beaver.php`
- Create in Premium: `includes/builders/class-pageforge-builder-avada.php`
- Create in Premium: `tests/BuilderAdaptersTest.php`
- Modify in Premium: `pageforge.php`
- Modify in Premium: `includes/class-pageforge-generator.php:240-449`
- Modify in Premium: `includes/class-pageforge-preflight.php`
- Modify in Premium: `includes/class-pageforge-operator-tools.php`

**Interfaces:**
- Produces: `Builder_Adapter::key(): string`, `detect(int $post_id): bool`, `inspect(int $post_id): array`, `clone_layout(int $source_id, int $target_id, array $row): array`, `preview_refresh(int $post_id): array`, and `refresh(int $post_id): array`; `Builder_Registry::for_post(int $post_id): Builder_Adapter`.

- [ ] **Step 1: Write failing adapter fixture tests**

Create serialized fixtures for each builder using only keys already found in current PageForge code or verified local builder installations. Test detection precedence, exact allowed meta prefixes, placeholder replacement inside arrays and serialized strings, no replacement in binary/unknown values, target post-type normalization, and refresh calls captured through test callbacks.

- [ ] **Step 2: Run tests to verify RED**

Run `php tests\run.php`.

- [ ] **Step 3: Implement adapters**

Native owns post content only. Each third-party adapter owns its verified metadata allowlist and refresh behavior. Unknown metadata is not copied. An unavailable builder plugin yields status `builder_inactive` and causes publish preflight to block while draft preflight warns.

- [ ] **Step 4: Replace generator prefix heuristics**

Route clone and refresh through `Builder_Registry`. Keep the current behavior as fixture-backed adapter logic. Return adapter diagnostics to Preflight and MCP tools.

- [ ] **Step 5: Verify real builder copies**

On copied local sites with each available builder, generate one draft from a base layout, open it in the builder editor, view frontend output, and verify no console/PHP errors. Do not advertise an adapter that lacks both fixture and local verification; record it as experimental in Pro UI until verified.

- [ ] **Step 6: Commit builder adapters**

Run tests and lint. Commit with `feat: add tested builder compatibility adapters`.

### Task 5: Google Analytics And Search Console Read Connections

**Files:**
- Create in Premium: `includes/class-pageforge-secret-store.php`
- Create in Premium: `includes/class-pageforge-google-connection.php`
- Create in Premium: `includes/class-pageforge-analytics.php`
- Create in Premium: `tests/SecretStoreTest.php`
- Create in Premium: `tests/GoogleConnectionTest.php`
- Create in Premium: `tests/AnalyticsTest.php`
- Modify in Premium: `pageforge.php`
- Modify in Premium: `includes/class-pageforge-plugin.php`
- Modify in Premium: `includes/class-pageforge-admin.php`
- Modify in Premium: `includes/class-pageforge-connection-health.php`
- Modify in Premium: `includes/class-pageforge-operator-tools.php`

**Interfaces:**
- Produces: encrypted refresh-token storage; Google connect/callback/revoke; `Analytics::ga4_landing_pages(array $args): array`, `search_console_opportunities(array $args): array`, and `declining_pages(array $args): array`.

- [ ] **Step 1: Write failing secret-store tests**

Test authenticated encryption round-trip, random nonce producing different ciphertext for the same plaintext, tamper rejection, key derivation from WordPress salts, and no plaintext token in stored options. If sodium is unavailable, use AES-256-GCM through OpenSSL; if neither exists, refuse the connection with `secure_storage_unavailable`.

- [ ] **Step 2: Write failing Google OAuth tests**

Test state expiry, exact redirect URI, one-time state, required scopes, refresh rotation, revoke clearing stored credentials, and separation between GA4 Measurement Protocol settings and Analytics Data authorization.

- [ ] **Step 3: Write failing analytics tests**

Mock complete Google API response fixtures. Test pagination, date ranges, property IDs, timezone, stale token refresh, quota errors, high-impression low-CTR ranking, declining-page comparison, and missing connection behavior.

- [ ] **Step 4: Implement outbound Google connection**

Use authorization code with PKCE where Google supports it, exact callback, least-privilege read scopes, encrypted refresh token, short-lived access token cache, bounded HTTP timeouts, and one refresh retry on 401. Never mix inbound MCP OAuth tokens with outbound Google credentials.

- [ ] **Step 5: Implement analytics services and tools**

GA4 returns landing page, sessions, engaged sessions, engagement rate, conversions, and date range. Search Console returns page, query, clicks, impressions, CTR, position, property, and date range. Opportunity ranking uses literal, tested thresholds supplied in arguments with conservative defaults.

- [ ] **Step 6: Complete Connections UI and health**

Show GA4 event measurement separately from GA4 Data and Search Console. Add Connect, Reconnect, Test, and Revoke actions. Register analytics MCP tools only when healthy and entitled.

- [ ] **Step 7: Verify and commit analytics**

Run tests, lint, and a local OAuth round trip against a non-production Google property. Commit with `feat: add analytics and search console insights`.

### Task 6: Provider Tool Calling And Admin AI Operator

**Files:**
- Create in Premium: `includes/class-pageforge-agent.php`
- Create in Premium: `includes/class-pageforge-agent-provider.php`
- Create in Premium: `tests/AgentTest.php`
- Create in Premium: `tests/AgentProviderTest.php`
- Modify in Premium: `includes/helpers.php`
- Modify in Premium: `includes/class-pageforge-ai.php`
- Modify in Premium: `includes/class-pageforge-plugin.php`
- Modify in Premium: `includes/class-pageforge-admin.php`
- Modify in Premium: `assets/admin.js`
- Modify in Premium: `assets/admin.css`

**Interfaces:**
- Produces: `Agent_Provider::request(array $messages, array $tools, array $context)` returning an array or `WP_Error`, and `Agent::run(array $messages, array $principal, array $context): array`.

- [ ] **Step 1: Write failing provider normalization tests**

Use complete response fixtures for OpenAI, Anthropic, Gemini, DeepSeek, Perplexity, and custom OpenAI-compatible providers. Normalize to:

```php
[
    'message' => ['role' => 'assistant', 'content' => ''],
    'tool_calls' => [
        ['id' => 'call_1', 'name' => 'pageforge_get_seo_status', 'arguments' => ['post_id' => 91]],
    ],
    'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
    'stop_reason' => 'tool_call',
]
```

Malformed tool JSON returns `invalid_provider_tool_arguments`; it never executes.

- [ ] **Step 2: Write failing agent-loop tests**

Test read call execution, write preview rendering, refusal to execute writes automatically, maximum eight tool calls, maximum two repeated identical failures, 60-second deadline, token budget stop, provider error, registry error, and source object links in the final transcript.

- [ ] **Step 3: Implement provider adapters**

Use existing credential and HTTP helpers. Send only tools discoverable for the principal. Parse provider-specific tool calls into the normalized shape. Add Perplexity to Pro provider configuration and health; Free remains locked and stores no key.

- [ ] **Step 4: Implement bounded Agent loop**

Read-only tools may run automatically. A write preview may run automatically, but its execute tool is returned to the UI as a pending approval. The loop never invokes an execute tool. After user approval, a separate AJAX request calls the confirmed execute tool and appends its audit ID.

- [ ] **Step 5: Integrate the admin planner**

Preserve existing conversation history. Add scope selection, tool activity rows, preview diffs, confirmation actions, affected object links, and clear error recovery. Do not expose chain-of-thought or hidden provider payloads.

Bind every completed domain handler to `Operator_Tools::definitions()`, assert each definition has one callable handler, register the catalog with `MCP_Server`, and enable Pro operator discovery only after this completeness assertion passes.

- [ ] **Step 6: Verify and commit agent operator**

Run tests, PHP lint, and JavaScript syntax checks. Complete one read-only analysis and one preview-confirm-execute flow on a copied local site. Commit with `feat: add pageforge admin seo operator`.

### Task 7: Functional SEO Workspace In Free And Pro

**Files:**
- Modify in Free: `includes/class-pageforge-admin.php`
- Modify in Free: `includes/class-pageforge-plugin.php`
- Modify in Free: `assets/admin.js`
- Modify in Free: `assets/admin.css`
- Modify in Premium: `includes/class-pageforge-admin.php`
- Modify in Premium: `includes/class-pageforge-plugin.php`
- Modify in Premium: `assets/admin.js`
- Modify in Premium: `assets/admin.css`

**Interfaces:**
- Consumes: Free read/dry-run tools and Pro SEO operator services.
- Produces: operational Overview, Metadata, Schema, Internal Links, Sitemap and llms.txt, Social and Images, and Technical tabs.

- [ ] **Step 1: Add tab routing tests**

Test old slugs map to the correct tab and unauthorized tabs are inaccessible. Test Free bulk actions are absent from server-rendered forms.

- [ ] **Step 2: Implement Free views**

Show inventory, status, single-item AI assistance, schema preview, sitemap status, and read-only recommendations. Render Pro bulk capabilities as one restrained upgrade row per relevant tab, not repeated cards.

- [ ] **Step 3: Implement Pro views**

Add filters, row selection, previews, diffs, confirmations, progress, partial failures, undo through revisions, analytics opportunities, SEO scores, and Explain with AI. Every action calls the same domain service used by MCP.

- [ ] **Step 4: Verify responsive workflows**

Capture all tabs at desktop, tablet, and 320px mobile widths. Test keyboard selection, modal focus return, bulk progress, empty states, and 100-row result tables.

- [ ] **Step 5: Commit each repository separately**

Use `feat: unify free seo workspace` and `feat: add pro seo operations workspace`.

## Plan 4 Verification Gate

Run all tests and syntax checks in both repositories. On a copied site, exercise every Pro tool group, one confirmed write in each risk category, audit search, revision undo, each available builder, Google OAuth, GA4 Data, Search Console, each AI provider, and the Free absence of write tools. Compare public tool discovery against the required catalog and confirm at least 40 unique Pro tools.
