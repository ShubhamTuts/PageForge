# PageForge Implementation Roadmap

Date: 2026-08-03

## Phase 0: Product Docs And Agent Context

Deliverables:

- `AGENTS.md`
- Free audit
- Pro alignment doc
- UI/UX PRD
- MCP connectors PRD
- Implementation plan

Verification:

- `git diff --check`
- Read docs for missing pricing boundary, premium write guardrail, and public source checks.

## Phase 1: Free Admin Cleanup

Goal: Make the existing free plugin feel cleaner without changing core generation behavior.

Work:

- Remove unreachable Plan and Account renderers.
- Remove or disable dead bulk-meta JS path.
- Fix mojibake source strings.
- Move Meta page into topbar or combine it into SEO.
- Reduce admin CSS card radius and nested card usage.
- Split Settings into clearer sections.
- Add Dashboard setup status.

Verification:

- PHP lint all modified PHP files.
- `node --check assets/admin.js`
- Run WordPress admin smoke test if a local WordPress test site is available.
- Regenerate POT and inspect changed strings.

## Phase 2: Connection Center

Goal: Give users one obvious place to connect AI, analytics, and future MCP clients.

Work:

- Add Connections admin page.
- Move AI provider credential UI from Settings to Connections.
- Add provider health check AJAX handler.
- Add status cards for OpenAI/ChatGPT, Claude, Gemini, DeepSeek, GA4.
- Add locked Perplexity Pro card.
- Keep Settings as defaults/advanced.

Verification:

- Test save and health check with missing keys.
- Test one real provider only when the user provides credentials.
- Confirm all new strings are localized.
- Confirm secrets are not printed back into HTML after save.

## Phase 3: Campaign Preflight

Goal: Prevent bad bulk generation before it happens.

Work:

- Add CSV preview and token coverage checks.
- Add slug duplicate preview.
- Add builder compatibility summary for selected base layout.
- Add dry-run sample output.
- Improve generation log into a result table.

Verification:

- Unit-test CSV header/token matching helpers if test infrastructure exists.
- Manual test with sample CSV.
- Manual test with duplicate slugs.
- Manual test with an Elementor base layout when available.

## Phase 4: Free MCP Lite

Goal: Expose safe PageForge context to MCP clients without enabling content writes.

Work:

- Add MCP classes and REST route.
- Add API key or Application Password auth path.
- Add tools/list and JSON-RPC dispatch.
- Add read-only tools.
- Add dry-run tools.
- Add MCP health check.
- Add setup guides for ChatGPT, Claude, and Cursor.

Verification:

- Unauthenticated request returns 401.
- Invalid credential returns 401.
- Valid credential can list tools.
- Read-only tools return redacted settings.
- Dry-run tools do not write posts or options.
- REST responses include no-store cache headers.

## Phase 5: SEO Workspace

Goal: Make the SEO screens actionable and less fragmented.

Work:

- Combine Blocks, Meta, schema status, and SEO report entry points.
- Add SERP preview.
- Add missing-metadata list.
- Add one-post metadata preview/apply.
- Keep bulk metadata Pro-locked unless implemented.
- Fix shortcode docs and examples.

Verification:

- Confirm Yoast and Rank Math fields sync as before.
- Confirm no duplicate frontend meta tags when SEO plugins are active.
- Confirm shortcode examples match `Frontend::sitemap()`.

## Phase 6: Pro Alignment And Migration Spec

Goal: Protect upgrade path from Free to Pro.

Work:

- Design migration from `pageforge_*` CPT/meta to `pf_*` CPT/meta.
- Add dry-run migration counts.
- Add copy mode for first release.
- Align translation loading in Pro.
- Reuse premium queue/Google Sheets/bulk meta in MCP Operator plan.

Verification:

- Dry-run migration on copied site data.
- Compare counts before and after.
- Confirm generated pages remain editable.
- Confirm SEO metadata and builder metadata survive.

## Phase 7: Pro MCP Operator

Goal: Build paid agentic SEO operations.

Work:

- Add scoped credentials.
- Add audit log.
- Add write tools with dry-run.
- Add campaign queue tools.
- Add metadata/schema/llms.txt tools.
- Add analytics opportunity tools when connected.
- Add OAuth 2.1/PKCE path when client requirements are stable.

Verification:

- Capability tests for each tool.
- Dry-run vs apply tests.
- Audit log tests.
- Revocation tests.
- Claude and ChatGPT manual connector tests.
- Perplexity only after remote MCP support is available for the tested account.

## Release Order

1. Docs and agent context.
2. Free UI cleanup.
3. Connection Center.
4. Campaign preflight.
5. Free MCP Lite.
6. SEO workspace.
7. Pro migration.
8. Pro MCP Operator.

Do not ship Pro marketing claims ahead of working code or locked UI.
