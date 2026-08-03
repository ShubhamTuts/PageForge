# PageForge Free Audit

Date: 2026-08-03

Scope: `C:\Users\wpshu\OneDrive\Documents\pageforge`

## Current Free Plugin Shape

PageForge Free is a WordPress plugin for CSV-driven programmatic SEO page generation. It registers two custom post types, `pageforge_template` and `pageforge_page`, and exposes admin pages for dashboard, page generation, data sources, templates, blocks, AI Site Planner, meta generation, blog generation, and settings.

The free plugin already has meaningful foundations:

- CSV parsing with delimiter detection and UTF-8 BOM handling.
- Template variables using `{Column}` placeholders.
- Bulk generation capped by `PAGEFORGE_FREE_ROW_LIMIT`.
- Builder-aware base layout cloning for Elementor and Divi metadata.
- Runtime placeholder replacement for content, titles, widgets, blocks, Elementor output, Yoast, and Rank Math.
- SEO metadata sync for PageForge, Yoast, and Rank Math.
- JSON-LD schema injection when a supported SEO plugin is not active.
- Optional AI provider setup for OpenAI/ChatGPT, Claude/Anthropic, Gemini, and DeepSeek.
- Optional Pexels image support.
- GA4 Measurement Protocol event sending.
- Localized PHP strings and JavaScript strings.

## Admin UI Findings

The UI works, but it reads as bloated because every screen repeats a similar pattern: large topbar, multiple rounded cards, explanatory side panels, and long form tables. The plugin needs a more operational flow.

Per screen:

- Dashboard: Useful counters exist, but the screen does not clearly answer "what should I do next?" Add setup progress, connection status, last run health, and campaign actions.
- Pages: Generation is a dense form-table plus log. Add CSV preview, template compatibility check, dry run, validation summary, and post-generation result links.
- Data Sources: CSV upload works, but there is no drag/drop, column health, row preview, source status, or clear locked Google Sheets affordance.
- Templates: The screen lists templates and tips, but it does not show token coverage, builder compatibility, base layout status, or generation readiness.
- Blocks: Mostly documentation. Add copy buttons, live preview, and simpler shortcode examples.
- AI Site Planner: The chat UI is useful, but chat history is browser localStorage only and cannot become MCP context. Add server-side session storage before agent features depend on it.
- Meta Title & Description: Hidden from the topbar and single-post oriented. Add SERP preview, missing-metadata list, safe bulk queue, and Pro gating where needed.
- Blog Generator: Functional but shallow. Add outline preview, tone/intent controls, draft safety, and link to templates/campaigns.
- Settings: Too long. Split into Connections, Schema, Publishing, Analytics, and Advanced.

## Code Issues To Fix

- `assets/admin.js` calls `pageforge_ai_generate_meta_bulk`, but free `includes/class-pageforge-plugin.php` does not register that AJAX action.
- `includes/class-pageforge-admin.php` contains unreachable `render_plan()` and `render_account()` code that pollutes translations and confuses product shape.
- `includes/class-pageforge-datasource.php` has a Google Sheets placeholder only. UI and docs must make Free vs Pro explicit.
- `readme.txt` has an old `[pageforge_sitemap]` example using `pf_page`, `generated_only`, `anchor_source`, and `description_source`, which do not match the free shortcode implementation.
- Some admin strings contain or have previously produced mojibake in translation files.
- AI model IDs are time-sensitive and must be checked against provider docs before release copy or defaults are updated.

## Builder Compatibility

Current free compatibility:

- Elementor support is enabled for `pageforge_page`.
- Base layout cloning preserves `_elementor_` metadata and attempts Elementor CSS regeneration.
- Divi metadata prefixes are cloned and Divi page resources are regenerated.
- Gutenberg works through normal post content and block rendering replacement.
- Yoast and Rank Math metadata are synced.

Gaps:

- Public claims mention Beaver Builder, WPBakery, Oxygen, Avada, and most modern themes. The free code does not include builder-specific cloning or validation for all of those.
- The UI needs a builder compatibility preflight that tells the user what PageForge can safely clone for the selected base layout.
- Generated slugs are checked only within the selected post type. Premium has stronger duplicate/fingerprint logic.

## Multilingual Findings

The free plugin has a translation directory with POT and PO/MO files for English, German, French, and Portuguese. It is not yet release-grade multilingual quality.

Needed:

- Remove dead plan/account strings from the POT by deleting unreachable UI or excluding it.
- Refresh POT after source cleanup.
- Fix Portuguese spacing and terminology.
- Confirm German and French strings in a real PO editor after source cleanup.
- Keep JavaScript strings localized through `PAGEFORGE.i18n`.

## Claims Alignment

The public homepage and README mention Google Sheets, databases, APIs, many builders, bulk AI metadata, scheduling, and queue workflows. The free plugin supports CSV, limited generation, AI tools, SEO sync, schema, and base layout cloning. Premium contains more of the high-scale functionality.

Rules for public claims:

- Free copy should say CSV is included.
- Google Sheets should be labelled Pro.
- Database/API data sources should not be claimed as active plugin features unless implemented or clearly described as roadmap.
- Builder claims should distinguish "works with normal rendered content" from "clones builder layouts safely."
- MCP and Perplexity claims should not be added until code or locked UI exists.

## Recommended First Fixes

1. Add a root `AGENTS.md` and product docs so future sessions start with the same context.
2. Remove dead Plan/Account UI from free admin source.
3. Fix the missing bulk-meta AJAX mismatch by either adding a locked/free-safe handler or removing the free JS path.
4. Redesign admin CSS to reduce card bloat and form-table sprawl.
5. Add an AI Connections screen with health checks.
6. Add MCP Lite docs/UI in Free and reserve full MCP Operator implementation for Pro.
7. Refresh translations and readme after UI strings settle.
