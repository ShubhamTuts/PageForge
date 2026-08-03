# PageForge UI And MCP Connectors Implementation Plan

> **Superseded:** Use `docs/superpowers/plans/2026-08-03-pageforge-production-upgrade-program.md`. This file is retained as historical audit context and must not be executed alongside the production-upgrade plans.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the approved PageForge Free UI cleanup, Connection Center, MCP Lite, and Pro MCP Operator path without breaking existing CSV generation.

**Architecture:** Free gets a clearer admin workflow, provider health checks, and read-only/dry-run MCP surfaces. Pro keeps the full operator layer, write tools, queue/scheduler integration, scopes, audit logs, and advanced connector auth. Free and Pro alignment is handled by explicit migration work instead of mixing CPT/meta conventions silently.

**Tech Stack:** WordPress PHP 7.4+, WordPress admin/AJAX/REST APIs, localized JavaScript, existing PageForge PHP classes, existing `assets/admin.js`, existing `assets/admin.css`, Freemius helpers in premium, MCP JSON-RPC over authenticated HTTP REST routes.

## Global Constraints

- Do not write to `C:\Users\wpshu\OneDrive\Documents\pageforge-premium` unless the user explicitly requests premium edits.
- Free plugin root is `C:\Users\wpshu\OneDrive\Documents\pageforge`.
- Free MCP is off by default.
- Free MCP first release exposes read-only and dry-run tools only.
- Perplexity appears in Free as a locked Pro connector card only.
- Credentials must not be placed in query strings.
- Every new PHP string uses the `pageforge` text domain.
- Every new JavaScript string is passed through localized data.
- Keep WordPress.org claims aligned with implemented Free behavior.

---

## File Structure

- Modify `includes/class-pageforge-plugin.php`: register new admin page, AJAX handlers, REST/MCP class loading.
- Modify `includes/class-pageforge-admin.php`: simplify topbar, remove dead renderers, add Connections screen, reorganize settings.
- Modify `includes/class-pageforge-ai.php`: expose safe provider health check helpers if needed.
- Modify `includes/helpers.php`: add reusable connection/provider status helpers.
- Create `includes/class-pageforge-connection-health.php`: provider and MCP health-check service.
- Create `includes/class-pageforge-mcp.php`: REST route registration and JSON-RPC dispatcher.
- Create `includes/class-pageforge-mcp-auth.php`: API key or Application Password validation.
- Create `includes/class-pageforge-mcp-tools.php`: Free read-only and dry-run tools.
- Modify `assets/admin.js`: remove dead bulk-meta path, add Connections interactions, add preflight interactions.
- Modify `assets/admin.css`: reduce visual bloat, add compact admin tables/status rows, style Connections.
- Modify `readme.txt`: update claims, external service disclosures, and shortcode examples.
- Modify `README.md`: align public product docs with Free/Pro split.
- Modify `languages/pageforge.pot` and PO files: refresh strings after UI changes.
- Create later in premium only after explicit approval: Pro MCP operator, audit log, OAuth, and migration classes.

---

### Task 1: Remove Dead Free Admin Paths

**Files:**
- Modify: `includes/class-pageforge-admin.php`
- Modify: `assets/admin.js`
- Modify: `languages/pageforge.pot`

**Interfaces:**
- Consumes: Existing admin page renderers and localized JS object `PAGEFORGE`.
- Produces: A free admin source without unreachable Plan/Account UI and without calls to unregistered bulk-meta AJAX actions.

- [ ] **Step 1: Inspect references**

Run:

```powershell
rg -n "render_plan|render_account|pageforge_ai_generate_meta_bulk|pageforge-plan|pageforge-account" includes assets languages -S
```

Expected: references are limited to free admin source, JavaScript, and translations.

- [ ] **Step 2: Remove unreachable renderers**

Edit `includes/class-pageforge-admin.php` to delete `render_plan()` and `render_account()` completely. Do not remove active submenu pages.

- [ ] **Step 3: Remove dead free JavaScript bulk-meta call**

Edit `assets/admin.js` to remove the branch that posts:

```javascript
action: 'pageforge_ai_generate_meta_bulk'
```

If the UI still needs a visible bulk affordance, render it as a disabled Pro message without sending AJAX.

- [ ] **Step 4: Verify syntax**

Run:

```powershell
php -l includes/class-pageforge-admin.php
node --check assets/admin.js
```

Expected: both commands exit 0.

- [ ] **Step 5: Commit**

```powershell
git add includes/class-pageforge-admin.php assets/admin.js languages/pageforge.pot
git commit -m "chore: remove dead free admin paths"
```

---

### Task 2: Add Connection Health Service

**Files:**
- Create: `includes/class-pageforge-connection-health.php`
- Modify: `includes/class-pageforge-plugin.php`
- Modify: `includes/helpers.php`
- Modify: `assets/admin.js`

**Interfaces:**
- Consumes: `\PageForge\Helpers\settings()`, provider catalog, provider chat helpers, current AJAX nonce.
- Produces: `\PageForge\Connection_Health::test_provider(string $provider): array` and AJAX action `pageforge_test_connection`.

- [ ] **Step 1: Add failing health-check test command target**

If the project has no test suite, use syntax and manual AJAX verification for this task. First confirm PHP cannot load a missing file:

```powershell
Test-Path includes/class-pageforge-connection-health.php
```

Expected: `False`.

- [ ] **Step 2: Create service class**

Create `includes/class-pageforge-connection-health.php` with:

```php
<?php
namespace PageForge;

use PageForge\Helpers;

if (!defined('ABSPATH')) exit;

class Connection_Health {
    public static function test_provider(string $provider): array {
        $settings = Helpers\settings();
        $catalog = Helpers\ai_provider_catalog();

        if (!isset($catalog[$provider])) {
            return ['ok' => false, 'stage' => 'provider', 'message' => __('Unknown provider.', 'pageforge')];
        }

        $key_field = $catalog[$provider]['key_field'] ?? '';
        $api_key = $key_field && !empty($settings[$key_field]) ? (string) $settings[$key_field] : '';

        if ($api_key === '') {
            return ['ok' => false, 'stage' => 'credentials', 'message' => __('API key missing.', 'pageforge')];
        }

        $result = Helpers\ai_chat_completion($provider, [
            ['role' => 'user', 'content' => 'Reply with exactly: PageForge connection OK']
        ], ['max_tokens' => 12]);

        if (is_wp_error($result)) {
            return ['ok' => false, 'stage' => 'provider_call', 'message' => $result->get_error_message()];
        }

        return ['ok' => true, 'stage' => 'complete', 'message' => __('Connection OK.', 'pageforge')];
    }
}
```

- [ ] **Step 3: Load class and register AJAX**

In `includes/class-pageforge-plugin.php`, require the new file and register `wp_ajax_pageforge_test_connection`.

Add handler:

```php
public function ajax_test_connection(): void {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied', 'pageforge')], 403);
    }
    check_ajax_referer('pageforge_admin', 'nonce');
    $provider = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
    $result = Connection_Health::test_provider($provider);
    $result['ok'] ? wp_send_json_success($result) : wp_send_json_error($result, 400);
}
```

- [ ] **Step 4: Add JavaScript action**

In `assets/admin.js`, add a click handler for `.pf-test-connection` that posts `pageforge_test_connection` with `provider`.

- [ ] **Step 5: Verify syntax**

Run:

```powershell
php -l includes/class-pageforge-connection-health.php
php -l includes/class-pageforge-plugin.php
node --check assets/admin.js
```

Expected: all commands exit 0.

- [ ] **Step 6: Commit**

```powershell
git add includes/class-pageforge-connection-health.php includes/class-pageforge-plugin.php assets/admin.js
git commit -m "feat: add AI connection health checks"
```

---

### Task 3: Add Connections Admin Screen

**Files:**
- Modify: `includes/class-pageforge-plugin.php`
- Modify: `includes/class-pageforge-admin.php`
- Modify: `assets/admin.css`
- Modify: `assets/admin.js`

**Interfaces:**
- Consumes: `Connection_Health::test_provider()`, existing settings save AJAX, existing AI provider catalog.
- Produces: Admin page slug `pageforge-connections`.

- [ ] **Step 1: Add submenu**

In `register_admin_menu()`, add:

```php
add_submenu_page(
    'pageforge',
    __('Connections', 'pageforge'),
    __('Connections', 'pageforge'),
    'manage_options',
    'pageforge-connections',
    [Admin::class, 'render_connections']
);
```

- [ ] **Step 2: Add topbar item**

Add Connections to `Admin::topbar()` after AI Planner.

- [ ] **Step 3: Render provider cards**

Add `Admin::render_connections()` with cards/rows for:

- ChatGPT/OpenAI
- Claude/Anthropic
- Gemini
- DeepSeek
- GA4
- MCP Lite
- Perplexity locked Pro

Each provider row includes status, masked credential state, save/test controls, and help text.

- [ ] **Step 4: Keep Perplexity locked**

Render Perplexity as disabled in Free:

```php
<button class="button" disabled><?php echo esc_html__('Unlock in Pro', 'pageforge'); ?></button>
```

Do not store a Perplexity key in Free.

- [ ] **Step 5: Style compact status rows**

Add CSS classes:

```css
.pf-connection-list { display: grid; gap: 12px; }
.pf-connection-row { border: 1px solid var(--pf-border); border-radius: 8px; padding: 14px; background: #fff; }
.pf-status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
```

- [ ] **Step 6: Verify syntax**

Run:

```powershell
php -l includes/class-pageforge-plugin.php
php -l includes/class-pageforge-admin.php
node --check assets/admin.js
```

Expected: all commands exit 0.

- [ ] **Step 7: Commit**

```powershell
git add includes/class-pageforge-plugin.php includes/class-pageforge-admin.php assets/admin.css assets/admin.js
git commit -m "feat: add connections admin screen"
```

---

### Task 4: Redesign Existing Admin Screens Around Workflow

**Files:**
- Modify: `includes/class-pageforge-admin.php`
- Modify: `assets/admin.css`

**Interfaces:**
- Consumes: Existing admin render methods.
- Produces: Cleaner Dashboard, Campaigns/Pages, Data, Templates, SEO, AI Planner, and Settings screens with fewer repeated marketing cards.

- [ ] **Step 1: Reduce topbar bloat**

Update `topbar()` to use short operational labels:

```php
[
    'pageforge' => __('Dashboard', 'pageforge'),
    'pageforge-pages' => __('Campaigns', 'pageforge'),
    'pageforge-datasources' => __('Data', 'pageforge'),
    'pageforge-templates' => __('Templates', 'pageforge'),
    'pageforge-shortcodes' => __('SEO', 'pageforge'),
    'pageforge-ai' => __('AI Planner', 'pageforge'),
    'pageforge-connections' => __('Connections', 'pageforge'),
    'pageforge-settings' => __('Settings', 'pageforge'),
]
```

- [ ] **Step 2: Add dashboard setup status**

In `render_dashboard()`, add status rows for:

- CSV data source exists.
- Template exists.
- AI provider connected.
- SEO plugin detected.
- GA4 configured.

- [ ] **Step 3: Simplify Pages screen**

Rename visible heading to Campaigns. Keep existing generation form behavior, but group fields under Data, Template, Publishing, and Generate.

- [ ] **Step 4: Simplify Settings**

Remove provider-key fields from Settings after Connections owns them. Keep:

- Brand context.
- Schema defaults.
- Default post type.
- CPT base.
- Daily limit.
- Advanced diagnostics.

- [ ] **Step 5: Lower visual weight**

Change CSS:

```css
:root { --pf-radius: 8px; }
.pf-card { border-radius: 8px; }
.pf-pill, .pf-kpi { border-radius: 8px; }
```

Remove nested card styling where content can be a table or section.

- [ ] **Step 6: Verify syntax**

Run:

```powershell
php -l includes/class-pageforge-admin.php
```

Expected: command exits 0.

- [ ] **Step 7: Commit**

```powershell
git add includes/class-pageforge-admin.php assets/admin.css
git commit -m "feat: streamline free admin workflow"
```

---

### Task 5: Add Campaign Preflight

**Files:**
- Modify: `includes/class-pageforge-plugin.php`
- Modify: `includes/class-pageforge-template.php`
- Modify: `includes/class-pageforge-generator.php`
- Modify: `includes/class-pageforge-admin.php`
- Modify: `assets/admin.js`

**Interfaces:**
- Consumes: CSV rows from `Datasource::parse_csv()`, template metadata, slug pattern, target post type.
- Produces: AJAX action `pageforge_campaign_preflight` returning row count, token coverage, sample title/slug, duplicate count, and builder compatibility.

- [ ] **Step 1: Add preflight handler**

Register `wp_ajax_pageforge_campaign_preflight` in `class-pageforge-plugin.php`.

- [ ] **Step 2: Implement token extraction helper**

Add helper method:

```php
private function extract_tokens(string $text): array {
    preg_match_all('/\{([A-Za-z0-9_\- ]+)\}/', $text, $matches);
    return array_values(array_unique(array_map('trim', $matches[1] ?? [])));
}
```

- [ ] **Step 3: Return token coverage**

For selected template and CSV, return:

```php
[
    'headers' => $headers,
    'tokens' => $tokens,
    'missing_tokens' => array_values(array_diff($tokens, $headers)),
    'unused_headers' => array_values(array_diff($headers, $tokens)),
]
```

- [ ] **Step 4: Return slug duplicate preview**

Build the first 20 sample slugs using existing placeholder replacement and `sanitize_title()`. Count existing posts with matching slug and selected post type.

- [ ] **Step 5: Return builder compatibility**

Detect base layout metadata prefixes:

- `_elementor_`
- `_et_`
- `_wpb_`

Return a readable compatibility message and risk level.

- [ ] **Step 6: Render preflight panel**

Add a preflight button and output area to the Campaigns screen.

- [ ] **Step 7: Verify syntax**

Run:

```powershell
php -l includes/class-pageforge-plugin.php
php -l includes/class-pageforge-template.php
php -l includes/class-pageforge-generator.php
php -l includes/class-pageforge-admin.php
node --check assets/admin.js
```

Expected: all commands exit 0.

- [ ] **Step 8: Commit**

```powershell
git add includes/class-pageforge-plugin.php includes/class-pageforge-template.php includes/class-pageforge-generator.php includes/class-pageforge-admin.php assets/admin.js
git commit -m "feat: add campaign preflight checks"
```

---

### Task 6: Add Free MCP Lite

**Files:**
- Create: `includes/class-pageforge-mcp.php`
- Create: `includes/class-pageforge-mcp-auth.php`
- Create: `includes/class-pageforge-mcp-tools.php`
- Modify: `pageforge.php`
- Modify: `includes/class-pageforge-plugin.php`
- Modify: `includes/class-pageforge-admin.php`
- Modify: `assets/admin.js`
- Modify: `assets/admin.css`

**Interfaces:**
- Consumes: WordPress REST API, PageForge settings, templates, data sources, generated pages.
- Produces: REST route `/wp-json/pageforge/v1/mcp` and Free tools/list with read-only/dry-run tools.

- [ ] **Step 1: Add MCP setting defaults**

Add settings keys:

```php
'mcp_enabled' => '0',
'mcp_api_key_hash' => '',
'mcp_last_test' => '',
```

- [ ] **Step 2: Create auth class**

Create `PageForge\MCP_Auth` with:

```php
public static function current_request_is_allowed(\WP_REST_Request $request): bool
```

It reads `X-PageForge-MCP-Key`, compares with stored hash using `hash_equals()`, and requires HTTPS unless `WP_DEBUG` is enabled.

- [ ] **Step 3: Create tool registry**

Create `PageForge\MCP_Tools::list_tools(): array` returning tool schemas for:

- `pageforge_get_site_info`
- `pageforge_list_templates`
- `pageforge_list_data_sources`
- `pageforge_list_generated_pages`
- `pageforge_get_seo_status`
- `pageforge_preview_generation`
- `pageforge_preview_meta_update`

- [ ] **Step 4: Create dispatcher**

Create `PageForge\MCP` with:

```php
public static function register_routes(): void
public static function handle(\WP_REST_Request $request): \WP_REST_Response
```

Handle JSON-RPC methods:

- `initialize`
- `tools/list`
- `tools/call`

- [ ] **Step 5: Register REST route**

Hook:

```php
add_action('rest_api_init', [\PageForge\MCP::class, 'register_routes']);
```

- [ ] **Step 6: Add no-store headers**

Every MCP response includes:

```php
$response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
```

- [ ] **Step 7: Add Connections UI controls**

Add:

- Enable MCP Lite toggle.
- Generate API key button.
- Revoke API key button.
- Copy endpoint button.
- Test MCP button.
- Tool list preview.

- [ ] **Step 8: Verify unauthenticated rejection**

Run against a local WordPress site when available:

```powershell
Invoke-WebRequest -Uri "https://example.test/wp-json/pageforge/v1/mcp" -Method POST -Body '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' -ContentType "application/json"
```

Expected: HTTP 401 when no credential is sent.

- [ ] **Step 9: Verify syntax**

Run:

```powershell
php -l includes/class-pageforge-mcp.php
php -l includes/class-pageforge-mcp-auth.php
php -l includes/class-pageforge-mcp-tools.php
php -l includes/class-pageforge-plugin.php
php -l includes/class-pageforge-admin.php
node --check assets/admin.js
```

Expected: all commands exit 0.

- [ ] **Step 10: Commit**

```powershell
git add pageforge.php includes/class-pageforge-mcp.php includes/class-pageforge-mcp-auth.php includes/class-pageforge-mcp-tools.php includes/class-pageforge-plugin.php includes/class-pageforge-admin.php assets/admin.js assets/admin.css
git commit -m "feat: add free MCP Lite"
```

---

### Task 7: Update Documentation And Translations

**Files:**
- Modify: `readme.txt`
- Modify: `README.md`
- Modify: `languages/pageforge.pot`
- Modify: `languages/pageforge-en_US.po`
- Modify: `languages/pageforge-en_GB.po`
- Modify: `languages/pageforge-de_DE.po`
- Modify: `languages/pageforge-fr_FR.po`
- Modify: `languages/pageforge-pt_PT.po`

**Interfaces:**
- Consumes: final UI strings and implemented feature set.
- Produces: public docs aligned with actual Free behavior and Pro-locked features.

- [ ] **Step 1: Fix shortcode examples**

Replace old examples with:

```text
[pageforge_sitemap]
[pageforge_sitemap group_by="city" columns="3" style="cards"]
[pageforge_sitemap post_type="pageforge_page,page,post" group_by="state" show_counts="1"]
```

- [ ] **Step 2: Update external service disclosures**

Add MCP/connector disclosure explaining:

- MCP is disabled by default.
- Connected clients can receive site/page/template context only when enabled.
- Free MCP Lite is read-only/dry-run.
- Credentials are sent by header or WordPress auth, not query string.

- [ ] **Step 3: Align provider copy**

State that Free supports OpenAI/ChatGPT, Claude/Anthropic, Gemini, and DeepSeek with the user's own API key. State that Perplexity is a Pro-locked connector preview in Free.

- [ ] **Step 4: Refresh POT**

Use the repository's existing translation generation method if available. If none exists, run WP-CLI i18n extraction in a WordPress development environment:

```powershell
wp i18n make-pot . languages/pageforge.pot --domain=pageforge
```

- [ ] **Step 5: Fix Portuguese quality**

Correct broken Portuguese strings such as:

```po
msgstr "Chave de API %s em falta"
msgstr "Adicione a chave de API nas definicoes"
```

- [ ] **Step 6: Verify docs**

Run:

```powershell
rg -n "pf_page|generated_only|anchor_source|description_source|database|API data source|Perplexity remote MCP" readme.txt README.md docs -S
```

Expected: no misleading free-feature claim remains.

- [ ] **Step 7: Commit**

```powershell
git add readme.txt README.md languages/pageforge.pot languages/pageforge-en_US.po languages/pageforge-en_GB.po languages/pageforge-de_DE.po languages/pageforge-fr_FR.po languages/pageforge-pt_PT.po
git commit -m "docs: align PageForge free and connector claims"
```

---

### Task 8: Pro Migration And MCP Operator Plan

**Files:**
- Create after explicit premium-edit approval: `C:\Users\wpshu\OneDrive\Documents\pageforge-premium\includes\class-pageforge-migration.php`
- Create after explicit premium-edit approval: `C:\Users\wpshu\OneDrive\Documents\pageforge-premium\includes\class-pageforge-mcp-operator-tools.php`
- Create after explicit premium-edit approval: `C:\Users\wpshu\OneDrive\Documents\pageforge-premium\includes\class-pageforge-mcp-audit-log.php`
- Create after explicit premium-edit approval: `C:\Users\wpshu\OneDrive\Documents\pageforge-premium\includes\class-pageforge-mcp-oauth.php`

**Interfaces:**
- Consumes: premium `pf_feature_allowed()`, queue, campaign, Google Sheets, AI usage, and generator helpers.
- Produces: non-destructive Free-to-Pro migration and Pro-only write tools.

- [ ] **Step 1: Stop if premium edits are not approved**

Do not create or modify premium files unless the user explicitly grants premium write scope.

- [ ] **Step 2: Design migration dry-run**

Migration dry-run returns:

```php
[
    'free_templates' => 0,
    'free_generated_pages' => 0,
    'already_migrated' => 0,
    'conflicts' => [],
]
```

- [ ] **Step 3: Design Pro write tools**

Pro MCP tools must call premium capability and entitlement checks before executing:

```php
current_user_can('manage_options')
pf_feature_allowed('mcp_operator')
```

- [ ] **Step 4: Add audit log requirements**

Each tool call records:

- timestamp
- user id
- client id
- tool name
- scope
- dry_run flag
- result status
- redacted arguments

- [ ] **Step 5: Verify on copied premium site only**

Use a copied local WordPress database. Never test migration on production first.

- [ ] **Step 6: Commit premium work separately**

Commit premium migration and MCP Operator separately from Free UI work.

---

## Self-Review

Spec coverage:

- Free audit context is covered by Tasks 1, 4, 5, and 7.
- Connections and Perplexity lock are covered by Tasks 2 and 3.
- Free MCP Lite is covered by Task 6.
- Multilingual/docs alignment is covered by Task 7.
- Pro migration and MCP Operator are covered by Task 8.

Placeholder scan:

- This plan contains no banned placeholder markers or deferred work labels.

Type consistency:

- `Connection_Health::test_provider(string $provider): array` is introduced in Task 2 and consumed in Task 3.
- `MCP_Auth::current_request_is_allowed(\WP_REST_Request $request): bool` is introduced in Task 6 and consumed by the MCP dispatcher.
- `MCP_Tools::list_tools(): array` is introduced in Task 6 and consumed by the MCP dispatcher.
