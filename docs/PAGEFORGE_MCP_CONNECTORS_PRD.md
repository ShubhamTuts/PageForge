# PageForge MCP Connectors PRD

Date: 2026-08-03

## Goal

Add AI connector and MCP capabilities that let WordPress admins safely use ChatGPT, Claude, Cursor, and other MCP-compatible clients as SEO operators for PageForge.

## Approved Product Split

Free:

- Connection Center UI.
- AI provider health checks.
- MCP Lite with safe read-only and dry-run tools.
- Manual setup instructions for MCP clients.
- Perplexity locked Pro card only.

Pro:

- Full MCP Operator server.
- Authenticated write tools.
- Audit log.
- Tool scopes.
- Revocation.
- Bulk optimization tools.
- Analytics opportunity tools.
- OAuth 2.1 with PKCE where feasible.
- Application Password fallback for clients that support header/basic auth setup.

## External Reality As Of 2026-08-03

Current primary docs indicate:

- MCP HTTP authorization should use OAuth 2.1 style flows, PKCE, metadata discovery, and bearer headers.
- MCP transports use JSON-RPC and Streamable HTTP for remote HTTP servers.
- ChatGPT custom apps can use MCP-backed tools and workspace action controls.
- Claude supports OAuth DCR/CIMD patterns and static headers in limited contexts.
- Perplexity supports local MCP on macOS now and says remote MCP is coming soon, rolling out to paid subscribers first.

Implication: PageForge should not promise one-click Perplexity remote MCP in Free. Keep it locked and worded as Pro/coming when client support allows.

## Free MCP Lite Requirements

### Admin Controls

MCP is off by default.

Settings:

- Enable MCP Lite.
- Server URL display.
- Authentication method.
- Generate/revoke API key.
- Test connection.
- View available tools.
- View last error.

### Authentication

Acceptable Free MVP:

- WordPress Application Password setup instructions, or
- PageForge-generated API key sent in a request header.

Do not:

- Put credentials in query strings.
- Enable anonymous MCP access.
- Enable write tools by default.

### Free Tools

Read-only:

- `pageforge_get_site_info`
- `pageforge_list_templates`
- `pageforge_get_template`
- `pageforge_list_data_sources`
- `pageforge_get_data_source_preview`
- `pageforge_list_generated_pages`
- `pageforge_get_generated_page`
- `pageforge_get_seo_status`
- `pageforge_get_settings_summary`
- `pageforge_get_builder_compatibility`

Dry-run:

- `pageforge_preview_generation`
- `pageforge_preview_meta_update`
- `pageforge_preview_schema`
- `pageforge_preview_llms_txt`

Limited write:

- No write tools in first Free release unless the user explicitly approves a later scope increase.

## Pro MCP Operator Requirements

### Tool Groups

Content and campaigns:

- Create campaign from template and data source.
- Queue generation.
- Schedule generation.
- Pause/resume queue.
- List campaign status.
- Fetch generation logs.

SEO metadata:

- List posts missing SEO metadata.
- Generate metadata suggestions.
- Preview metadata import.
- Apply selected metadata updates.
- Sync Yoast and Rank Math fields.

Schema and technical SEO:

- Read schema status.
- Preview FAQ schema.
- Apply FAQ schema.
- Generate llms.txt.
- Preview robots/meta directives.
- Manage sitemap hub recommendations.

Builder support:

- Inspect base layout metadata.
- Check Elementor compatibility.
- Check Divi compatibility.
- Report unsupported builder risks.

Analytics:

- Read GA4/PageForge event summary when connected.
- Identify pages with weak engagement.
- Later: Search Console opportunities for high impressions and low CTR when connected.

Safety:

- Every write tool has dry-run mode.
- Bulk writes require explicit confirmation state.
- Destructive tools are disabled unless separately enabled by admin.
- All tool calls are logged.

### Scopes

Recommended scopes:

- `read_site`
- `read_pageforge`
- `preview_generation`
- `write_campaigns`
- `write_metadata`
- `write_schema`
- `write_files`
- `read_analytics`
- `admin_settings`

Default scopes:

- Free Lite: `read_site`, `read_pageforge`, `preview_generation`
- Pro Operator: admin selects scopes during connector setup.

## Connection Health Check

The Test Connection button should report exact failure stage:

- HTTPS unavailable.
- REST route unavailable.
- Authentication missing.
- Authentication invalid.
- User lacks capability.
- MCP initialize failed.
- Tools/list failed.
- Dry-run tool failed.
- Write capability disabled.

## Connector Cards

Show these cards on Connections:

- ChatGPT: available for MCP/custom app setup.
- Claude: available for MCP setup.
- Cursor: available for MCP setup.
- Perplexity: locked for Pro in Free.
- Gemini: provider key supported; MCP client support should be documented only after verification.

Each card shows:

- Status.
- Setup button.
- Required plan.
- Last tested.
- Copy config button when applicable.

## WordPress REST Architecture

Free file proposal:

- `includes/class-pageforge-mcp.php`: REST route registration, JSON-RPC dispatcher, tool registry.
- `includes/class-pageforge-mcp-auth.php`: API key/Application Password auth helpers.
- `includes/class-pageforge-mcp-tools.php`: read-only and dry-run tools.
- `includes/class-pageforge-connection-health.php`: provider and MCP health checks.

Pro file proposal:

- `includes/class-pageforge-mcp-operator-tools.php`: write tools.
- `includes/class-pageforge-mcp-audit-log.php`: tool call storage and retention.
- `includes/class-pageforge-mcp-oauth.php`: OAuth metadata, authorization, token, and registration endpoints.

## WordPress.org Compliance

Before release:

- Update external service disclosures for any connector that sends data off-site.
- Document exact data sent to AI providers and MCP clients.
- Ensure MCP is disabled by default.
- Ensure uninstall cleans stored API keys, tokens, sessions, and logs.
- Do not claim Perplexity remote MCP support until it is available and tested.

## Acceptance Criteria

- Admin can enable/disable MCP from Connections.
- Admin can generate and revoke credentials.
- Test connection reports the exact failing stage.
- ChatGPT and Claude setup instructions are shown clearly.
- Perplexity appears locked in Free.
- Free tools cannot write content.
- Dry-run previews include exactly what would change.
- No credentials are exposed in URLs, logs, or MCP tool responses.
