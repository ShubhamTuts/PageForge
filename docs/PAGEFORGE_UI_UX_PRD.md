# PageForge UI/UX PRD

Date: 2026-08-03

## Goal

Make PageForge feel like a focused SEO operations tool instead of a collection of bulky admin cards. Users should be able to connect data, prepare a template, validate a campaign, generate pages, and improve SEO without guessing where to go next.

## Target Users

- WordPress site owners generating local SEO pages.
- SEO agencies managing repeatable campaigns for clients.
- Freelancers using Elementor, Gutenberg, Divi, or other builders.
- Content teams using AI-assisted metadata and blog drafting.
- Future Pro users who need queue, scheduling, Google Sheets, and MCP operators.

## UX Principles

- Show the primary workflow first: Connect data, choose template, validate, generate, optimize.
- Reduce repeated explanation cards.
- Prefer status, diagnostics, and next actions over marketing copy.
- Keep settings split by job, not by implementation detail.
- Show locked Pro features as compact disabled rows/cards, not full fake workflows.
- Make every AI action previewable before writing.
- Make builder compatibility visible before generation.

## Proposed Admin Navigation

Keep the current WordPress submenu for compatibility, but redesign the PageForge topbar to this:

- Dashboard
- Campaigns
- Data
- Templates
- SEO
- AI Planner
- Connections
- Settings

Mapping from current screens:

- PageForge -> Dashboard
- Pages -> Campaigns
- Data Sources -> Data
- Templates -> Templates
- Blocks -> SEO or Tools tab
- AI Site Planner -> AI Planner
- Meta Title & Description -> SEO
- Blog Generator -> AI Planner or Content tab
- Settings -> Settings

## Screen Requirements

### Dashboard

Show:

- Setup status: data source, template, AI provider, SEO plugin, analytics.
- Last generation result with created/skipped/failed counts.
- Connection health summary.
- Quick actions: New campaign, Upload CSV, Create template, Optimize SEO.
- Recent campaigns and recent generated pages.

Remove:

- Repeated generic feature cards.
- Oversized upgrade copy.

### Campaigns

Replace the current form-table with a stepper:

1. Data source
2. Template
3. Publishing rules
4. Preflight
5. Generate

Preflight must show:

- Row count and free/pro limit.
- Required token coverage.
- Duplicate slug check.
- Builder compatibility.
- SEO metadata availability.
- Dry-run sample output.

### Data

Show sources in a compact table:

- Source name
- Type
- Rows
- Columns
- Last updated
- Health
- Actions

CSV upload should include drag/drop, sample download, delimiter detection, and first-row preview.

Google Sheets should be a locked Pro row in Free.

### Templates

Show:

- Template title.
- Builder/base layout status.
- Tokens used.
- Last generated count.
- SEO fields configured.
- Actions: edit, duplicate, export, validate.

Add a token inspector that compares template placeholders against the selected data source.

### SEO

Combine Blocks, Meta Title & Description, and SEO report entry points into a single practical SEO workspace:

- Metadata optimizer.
- SERP preview.
- Missing metadata list.
- Sitemap block/shortcode helper.
- Schema status.
- llms.txt status when implemented.
- Bulk actions locked to Pro if not available in Free.

### AI Planner

Keep chat, but add:

- Site context panel.
- Data access toggles.
- Recent reports saved server-side.
- Action preview cards.
- Export to campaign brief.

### Connections

New screen:

- AI providers: ChatGPT/OpenAI, Claude/Anthropic, Gemini, DeepSeek.
- Locked Pro card: Perplexity.
- Analytics: GA4 current settings.
- Future: Search Console.
- MCP: endpoint, enabled status, authentication method, health check, setup guides.

### Settings

Move most provider credentials out of Settings and into Connections. Keep:

- Default post type and CPT base.
- Schema defaults.
- Publishing defaults.
- Advanced cleanup and diagnostics.

## Visual Direction

- Reduce card radius to 8px or less.
- Use compact tables for repeated objects.
- Keep full-page sections unframed where possible.
- Avoid nested cards.
- Use status badges sparingly.
- Use standard WordPress admin controls where they improve familiarity.
- Keep the topbar lighter and less promotional.

## Accessibility And Localization

- Every button needs visible text or a clear accessible label.
- Status cannot rely only on color.
- Keep strings short enough for German, French, and Portuguese.
- All new PHP strings use the `pageforge` text domain.
- All new JavaScript strings go through localized `PAGEFORGE.i18n`.

## Acceptance Criteria

- A new user can identify the next setup step from the Dashboard within 10 seconds.
- A user can run a dry-run campaign before creating posts.
- A user can test an AI provider connection without generating content.
- Meta generation has a visible preview before save.
- Locked Pro features do not look like broken Free features.
- No page uses repeated marketing cards as the main UI pattern.
- Translation files can be regenerated without dead Plan/Account strings.
