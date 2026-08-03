# PageForge Agent Guide

This repository contains the WordPress.org/free PageForge plugin. Treat `C:\Users\wpshu\OneDrive\Documents\pageforge-premium` as a read-only comparison source when it exists.

## Required First Reads

Before making product, UI, AI, MCP, docs, or pricing changes, read:

- `pageforge.php`
- `includes/class-pageforge-plugin.php`
- `includes/class-pageforge-admin.php`
- `includes/class-pageforge-ai.php`
- `includes/class-pageforge-generator.php`
- `includes/class-pageforge-frontend.php`
- `includes/class-pageforge-datasource.php`
- `includes/helpers.php`
- `assets/admin.js`
- `assets/admin.css`
- `readme.txt`
- `README.md`
- `languages/pageforge.pot`
- `docs/PAGEFORGE_FREE_AUDIT.md`
- `docs/PAGEFORGE_PRO_ALIGNMENT.md`
- `docs/PAGEFORGE_UI_UX_PRD.md`
- `docs/PAGEFORGE_MCP_CONNECTORS_PRD.md`
- `docs/PAGEFORGE_IMPLEMENTATION_ROADMAP.md`
- `docs/superpowers/specs/2026-08-03-pageforge-cross-edition-production-upgrade-design.md`
- `docs/superpowers/plans/2026-08-03-pageforge-production-upgrade-program.md`

The earlier `docs/superpowers/plans/2026-08-03-pageforge-ui-mcp-connectors.md` plan is retained as historical audit context. The production-upgrade program and its five ordered plans govern implementation.

Premium is a separate repository. The user approved premium edits for the cross-edition production upgrade described in the specification above. Keep premium changes on their own branch and commits, do not edit its bundled vendor code, and do not broaden premium work beyond the approved specification without fresh approval:

- `C:\Users\wpshu\OneDrive\Documents\pageforge-premium\pageforge.php`
- `C:\Users\wpshu\OneDrive\Documents\pageforge-premium\includes\class-pageforge-plugin.php`
- `C:\Users\wpshu\OneDrive\Documents\pageforge-premium\includes\class-pageforge-admin.php`
- `C:\Users\wpshu\OneDrive\Documents\pageforge-premium\includes\class-pageforge-ai.php`
- `C:\Users\wpshu\OneDrive\Documents\pageforge-premium\includes\class-pageforge-generator.php`
- `C:\Users\wpshu\OneDrive\Documents\pageforge-premium\includes\class-pageforge-cron.php`
- `C:\Users\wpshu\OneDrive\Documents\pageforge-premium\includes\class-pageforge-datasource.php`
- `C:\Users\wpshu\OneDrive\Documents\pageforge-premium\includes\helpers.php`
- `C:\Users\wpshu\OneDrive\Documents\pageforge-premium\assets\admin.js`
- `C:\Users\wpshu\OneDrive\Documents\pageforge-premium\assets\admin.css`
- `C:\Users\wpshu\OneDrive\Documents\pageforge-premium\readme.txt`

## Public Source Checks

For claims, pricing, connector support, and docs wording, verify the current public sources before changing copy:

- `https://pageforge.pro/`
- `https://pageforge.pro/sitemap.xml`
- `https://wordpress.org/plugins/pageforge/`

For MCP or AI connector behavior, verify current primary docs before making technical claims:

- `https://modelcontextprotocol.io/specification/`
- `https://help.openai.com/`
- `https://developers.openai.com/`
- `https://claude.com/docs/`
- `https://www.perplexity.ai/help-center/`

## Product Direction

The approved product split is:

- Free plugin: cleaner admin UX, existing AI provider setup, connection health checks, MCP Lite/read-only or dry-run capability, and Pro-locked connector previews.
- Pro plugin: full agentic MCP operator server with authenticated write tools, audit logs, scopes, revocation, bulk actions, analytics opportunities, and advanced generation workflows.

Perplexity connector UI belongs in Free as a locked Pro card unless the user explicitly asks to implement paid functionality in premium.

## Guardrails

- Do not write to `pageforge-premium` unless explicitly requested.
- Keep WordPress.org claims aligned with actual free code.
- Keep external service disclosures updated in `readme.txt`.
- Prefer WordPress-native APIs, REST routes, nonces, capabilities, escaping, and sanitization.
- Do not expose AI/MCP write tools by default. Admin must enable them.
- For connector credentials, never place tokens in query strings. Use headers, OAuth, or WordPress Application Passwords.
- Any destructive MCP action must support preview/dry-run first.
- Preserve multilingual support. Update source strings, POT, and PO files together.
- Keep UI copy direct and operational. Avoid repeating marketing cards inside every admin screen.
