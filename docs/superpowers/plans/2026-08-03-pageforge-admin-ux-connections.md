# PageForge Shared Admin UX And Connections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the bloated admin experience in both editions with a focused workflow, connection health, campaign preflight, and a compliant Free review request after a successful generation.

**Architecture:** Shared services own connection health, review-prompt state, and campaign preflight independently from HTML. Free and Pro render the same navigation and interaction patterns while applying edition capabilities server-side. Existing AJAX action names remain stable unless a new action is added, so installed sites and cached admin pages continue working.

**Tech Stack:** PHP 7.4, WordPress admin menus and AJAX, jQuery, CSS, WordPress capabilities and nonces, gettext.

## Global Constraints

- Preserve every existing admin page slug so bookmarks and WordPress menu URLs continue working.
- The visible navigation is Dashboard, Campaigns, Data, Templates, SEO, AI Planner, Connections, Settings.
- Pro Queue appears as a Campaigns tab, while the existing `pageforge-queue` slug remains registered as a compatibility redirect.
- Connections owns provider credentials after this plan; Settings keeps all existing option values and does not erase omitted secrets.
- Free displays Perplexity as Pro-locked and never accepts or stores a Perplexity key.
- The review request exists only in Free and follows the state machine in the approved specification.
- Admin controls must remain usable at 320px width and 200 percent zoom.
- Do not use nested cards or marketing-style hero sections.

---

### Task 1: Shared Connection Health Service

**Files:**
- Create in Free: `includes/class-pageforge-connection-health.php`
- Create in Free: `tests/ConnectionHealthTest.php`
- Modify in Free: `pageforge.php`
- Modify in Free: `includes/class-pageforge-plugin.php:14-38,162-220`
- Create in Premium: `includes/class-pageforge-connection-health.php`
- Create in Premium: `tests/ConnectionHealthTest.php`
- Modify in Premium: `pageforge.php`
- Modify in Premium: `includes/class-pageforge-plugin.php:14-52`

**Interfaces:**
- Consumes: existing provider catalogs, settings, `wp_remote_post()`, and `is_wp_error()`.
- Produces: `PageForge\Connection_Health::test(string $provider, ?array $settings = null): array`, `configured(string $provider, ?array $settings = null): bool`, and AJAX action `pageforge_test_connection`.

- [ ] **Step 1: Write failing connection-state tests in Free**

Add tests for unknown provider, missing credentials, provider success, 401 authentication failure, 403 permission failure, 429 rate limit, TLS failure, and malformed provider response. Use a test transport callback set with `Connection_Health::set_transport()` only when `defined('PAGEFORGE_TESTING') && PAGEFORGE_TESTING` is true.

Assert this exact missing-key result:

```php
[
    'ok' => false,
    'stage' => 'credentials',
    'code' => 'missing_credentials',
    'message' => 'API key missing.',
]
```

- [ ] **Step 2: Run Free tests to verify RED**

Run `php tests\run.php`.

Expected: connection tests fail because `Connection_Health` is absent.

- [ ] **Step 3: Implement Free connection health**

Create the class with provider-specific bounded probes using existing provider request helpers. Return only these stages: `provider`, `credentials`, `network`, `authentication`, `permission`, `rate_limit`, `provider_response`, and `complete`. Never return API keys, raw authorization headers, or complete upstream response bodies.

Register this handler in `Plugin`:

```php
public function ajax_test_connection(): void {
    \check_ajax_referer('pageforge_admin', 'nonce');
    if (!\current_user_can('manage_options')) {
        \wp_send_json_error(['code' => 'forbidden', 'message' => __('Permission denied.', 'pageforge')], 403);
    }
    $provider = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
    $result = Connection_Health::test($provider);
    if (!empty($result['ok'])) \wp_send_json_success($result);
    \wp_send_json_error($result, 400);
}
```

- [ ] **Step 4: Verify Free GREEN**

```powershell
php tests\run.php
php -l includes\class-pageforge-connection-health.php
php -l includes\class-pageforge-plugin.php
```

- [ ] **Step 5: Port the tested service to Premium**

Use Premium's provider configuration functions and add `perplexity` plus `custom` probes. Preserve the same return schema and AJAX action. Gate Perplexity configuration with `pf_feature_allowed('mcp_operator')` or the final entitlement key chosen in Plan 3.

- [ ] **Step 6: Verify Premium GREEN**

Run `php tests\run.php` and lint both changed Premium PHP files.

- [ ] **Step 7: Commit each repository separately**

Free:

```powershell
git add pageforge.php includes/class-pageforge-connection-health.php includes/class-pageforge-plugin.php tests/ConnectionHealthTest.php
git commit -m "feat: add connection health diagnostics"
```

Premium:

```powershell
git add pageforge.php includes/class-pageforge-connection-health.php includes/class-pageforge-plugin.php tests/ConnectionHealthTest.php
git commit -m "feat: add pro connection health diagnostics"
```

### Task 2: Connections Screen In Free And Pro

**Files:**
- Modify in Free: `includes/class-pageforge-plugin.php:109-160,222-282`
- Modify in Free: `includes/class-pageforge-admin.php:7-82,490-644`
- Modify in Free: `assets/admin.js`
- Modify in Free: `assets/admin.css`
- Modify in Premium: `includes/class-pageforge-plugin.php`
- Modify in Premium: `includes/class-pageforge-admin.php`
- Modify in Premium: `assets/admin.js`
- Modify in Premium: `assets/admin.css`

**Interfaces:**
- Consumes: `Connection_Health` from Task 1 and existing settings-save AJAX.
- Produces: admin slug `pageforge-connections`, `.pf-connection-row` components, and `.pf-test-connection` actions with a shared response renderer.

- [ ] **Step 1: Add menu-registration tests**

Extend the test bootstrap to capture `add_submenu_page()` calls. Test that both editions register `pageforge-connections` with `manage_options`, and that all old slugs remain registered.

- [ ] **Step 2: Run tests to verify RED**

Run `php tests\run.php` in both repositories.

Expected: no `pageforge-connections` registration is captured.

- [ ] **Step 3: Register and render Connections in Free**

Add Connections after AI Planner in the top bar and submenu. `Admin::render_connections()` renders rows for OpenAI, Anthropic, Gemini, DeepSeek, GA4 event measurement, MCP, and locked Perplexity.

Each active provider row includes this stable structure:

```html
<section class="pf-connection-row" data-provider="openai">
  <div class="pf-connection-main">
    <span class="pf-status-dot" aria-hidden="true"></span>
    <div><h2>OpenAI</h2><p class="description">ChatGPT and OpenAI API models.</p></div>
  </div>
  <div class="pf-connection-status" aria-live="polite"></div>
  <div class="pf-connection-actions"></div>
</section>
```

Use PHP escaping and translated strings in actual output. Provider credential fields retain stored values when omitted. Perplexity has no input and renders one disabled `Unlock in Pro` action.

- [ ] **Step 4: Add shared connection interactions**

In both `admin.js` files, post `pageforge_test_connection`, disable only the clicked row during the request, and render stage-specific status. Escape all upstream messages through the existing `escHtml()` helper.

Add copy-endpoint behavior using `navigator.clipboard.writeText()` with a textarea fallback. Do not copy API secrets automatically.

- [ ] **Step 5: Render Pro-specific connections**

Pro uses the same row structure and adds Google Sheets, GA4 Data, Search Console, custom OpenAI-compatible, and Perplexity. Locked rows are determined by server-side entitlements. MCP controls are rendered as a placeholder status row in this task and become functional in Plan 3.

- [ ] **Step 6: Apply compact shared CSS**

Add these base constraints to both editions and adapt existing selectors rather than duplicating declarations:

```css
.pf-connection-list { display: grid; gap: 12px; }
.pf-connection-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(180px, auto) auto; gap: 16px; align-items: center; padding: 16px 0; border-bottom: 1px solid var(--pf-border); }
.pf-connection-row:last-child { border-bottom: 0; }
.pf-connection-main { display: flex; gap: 10px; align-items: flex-start; min-width: 0; }
.pf-status-dot { width: 8px; height: 8px; margin-top: 8px; border-radius: 50%; background: #8c8f94; flex: 0 0 auto; }
@media (max-width: 782px) { .pf-connection-row { grid-template-columns: 1fr; } }
```

- [ ] **Step 7: Verify syntax and menu tests**

Run in both repositories:

```powershell
php tests\run.php
php -l includes\class-pageforge-admin.php
php -l includes\class-pageforge-plugin.php
node --check assets\admin.js
```

- [ ] **Step 8: Commit each repository separately**

Use commit messages `feat: add free connections workspace` and `feat: add pro connections workspace`.

### Task 3: Free Post-Win Review Request

**Files:**
- Create in Free: `includes/class-pageforge-review-prompt.php`
- Create in Free: `tests/ReviewPromptTest.php`
- Modify in Free: `pageforge.php`
- Modify in Free: `includes/class-pageforge-plugin.php:162-220,345-400`
- Modify in Free: `assets/admin.js:150-188`
- Modify in Free: `assets/admin.css`

**Interfaces:**
- Consumes: bulk-generation result `{processed:int, generated:int, errors:array}` and current administrator capability.
- Produces: `Review_Prompt::record_win(array $state, array $result, int $now): array`, `should_show(array $state, int $now, bool $is_admin_user, bool $is_pageforge_screen): bool`, `apply_action(array $state, string $action, int $now): array`, AJAX action `pageforge_review_prompt_action`, and response field `review_prompt`.

- [ ] **Step 1: Write the full failing state-machine test**

Test these mutations independently:

- zero generated items leaves state empty
- errors with at least one generated item still qualify as a win
- first win sets `pending`, `shown_count => 0`, and `first_win_at`
- first eligible display increments `shown_count` to `1`
- `later` sets `snoozed` and `snooze_until` to exactly 14 days later
- snoozed state does not show before the deadline
- snoozed state requires a later successful generation before showing again
- second display sets `shown_count` to `2`
- `reviewed` and `dismiss` never show again
- Pro mode always returns false
- non-admin users never receive prompt markup

Use fixed timestamps `1700000000` and `1701209600` so expected values are literal.

- [ ] **Step 2: Run Free tests to verify RED**

Run `php tests\run.php`.

Expected: review-prompt class is missing.

- [ ] **Step 3: Implement the pure state transitions**

Store only:

```php
[
    'first_win_at' => 1700000000,
    'status' => 'pending',
    'snooze_until' => 0,
    'shown_count' => 0,
    'last_shown_at' => 0,
    'wins_after_snooze' => 0,
]
```

Accept only actions `shown`, `later`, `reviewed`, and `dismiss`. Unknown actions return the state unchanged. Cap `shown_count` at `2`.

- [ ] **Step 4: Connect eligibility to real generation results**

Update `Generator::bulk_generate()` to return both `processed` and `generated` accurately. In `ajax_start_generation()`, call `Review_Prompt::record_win()` only after generation returns and save `pageforge_review_prompt` with autoload disabled. Add `review_prompt => true` only when the current user has `manage_options` and `should_show()` passes.

The review URL is exactly:

`https://wordpress.org/support/plugin/pageforge/reviews/#new-post`

- [ ] **Step 5: Render the contextual prompt in JavaScript**

After the normal success summary, append `.pf-review-request` only when `res.data.review_prompt === true`. Include translated buttons for `Leave an honest review`, `Maybe later`, `Already reviewed`, and an icon-only dismiss button with an accessible label.

Post every local action to `pageforge_review_prompt_action`. Open the review URL in a new tab only after the user clicks the review action. Do not emit GA4 or other telemetry.

- [ ] **Step 6: Verify review behavior**

```powershell
php tests\run.php
php -l includes\class-pageforge-review-prompt.php
php -l includes\class-pageforge-plugin.php
node --check assets\admin.js
```

Expected: all state transitions pass and syntax checks exit `0`.

- [ ] **Step 7: Commit Free review request**

```powershell
git add pageforge.php includes/class-pageforge-review-prompt.php includes/class-pageforge-generator.php includes/class-pageforge-plugin.php assets/admin.js assets/admin.css tests/ReviewPromptTest.php
git commit -m "feat: request honest reviews after a generation win"
```

### Task 4: Shared Campaign Preflight

**Files:**
- Create in Free: `includes/class-pageforge-preflight.php`
- Create in Free: `tests/PreflightTest.php`
- Modify in Free: `pageforge.php`
- Modify in Free: `includes/class-pageforge-plugin.php`
- Modify in Free: `includes/class-pageforge-admin.php:293-382`
- Modify in Free: `assets/admin.js`
- Create in Premium: `includes/class-pageforge-preflight.php`
- Create in Premium: `tests/PreflightTest.php`
- Modify in Premium: `pageforge.php`
- Modify in Premium: `includes/class-pageforge-plugin.php`
- Modify in Premium: `includes/class-pageforge-admin.php`
- Modify in Premium: `assets/admin.js`

**Interfaces:**
- Consumes: parsed headers and rows, `Template`, `Compatibility`, selected status, and base-layout metadata.
- Produces: `Preflight::analyze(array $headers, array $rows, array $template, array $existing_slugs, array $builder_meta): array` and AJAX action `pageforge_campaign_preflight`.

- [ ] **Step 1: Write failing deterministic preflight tests**

Use a template containing `{Service}`, `{City}`, and `{Phone}` with headers `Service`, `City`, and `State`. Assert:

```php
[
    'row_count' => 2,
    'tokens' => ['City', 'Phone', 'Service'],
    'missing_tokens' => ['Phone'],
    'unused_headers' => ['State'],
    'sample_title' => 'Dentist in Pune',
    'sample_slug' => 'dentist-in-pune',
    'duplicate_count' => 1,
    'blocking' => true,
]
```

Add builder tests for Elementor, Divi, WPBakery, Beaver, Avada, native blocks, and unknown metadata. Unknown builder state is a warning, not a blocker, when status is `draft`; it is a blocker for direct `publish`.

- [ ] **Step 2: Run tests to verify RED**

Run `php tests\run.php` in Free and Premium.

Expected: preflight service missing.

- [ ] **Step 3: Implement the pure analyzer in Free**

Extract tokens with `/\{([A-Za-z0-9_\- ]+)\}/`, trim, deduplicate, sort case-insensitively, and compare headers case-insensitively while preserving display spelling. Generate no more than 20 slug samples. Return `errors`, `warnings`, and `blocking` separately.

- [ ] **Step 4: Add bounded Free AJAX and UI**

Require `edit_posts`, verify nonce, accept attachment and template IDs with `absint`, read at most the Free per-run row limit, query duplicate slugs across `Compatibility::generated_post_types()`, and return the analyzer result. Add a `Run preflight` button, stable output region, and disable Generate when `blocking` is true.

- [ ] **Step 5: Port analyzer and endpoint to Premium**

Use the same pure analyzer. Add queue, AI usage, Google Sheets, and entitlement warnings outside the pure method so shared tests stay identical.

- [ ] **Step 6: Verify both editions**

Run tests, PHP lint for new and modified PHP files, and `node --check assets\admin.js` in both repositories.

- [ ] **Step 7: Commit each repository separately**

Use `feat: add campaign generation preflight` in Free and `feat: add pro campaign generation preflight` in Premium.

### Task 5: Shared Information Architecture And Visual Cleanup

**Files:**
- Modify in Free: `includes/class-pageforge-plugin.php:109-160`
- Modify in Free: `includes/class-pageforge-admin.php`
- Modify in Free: `assets/admin.css`
- Modify in Free: `assets/admin.js`
- Modify in Premium: `includes/class-pageforge-plugin.php`
- Modify in Premium: `includes/class-pageforge-admin.php`
- Modify in Premium: `assets/admin.css`
- Modify in Premium: `assets/admin.js`

**Interfaces:**
- Consumes: Connections and preflight components from earlier tasks.
- Produces: shared top navigation, compact sections and tables, responsive controls, stable legacy redirects, and no dead Free admin actions.

- [ ] **Step 1: Add navigation regression tests**

Capture submenu registrations and assert every old slug plus `pageforge-connections` remains available. Assert topbar configuration contains exactly the eight approved visible labels in order.

- [ ] **Step 2: Run tests to verify RED**

Expected: current topbar labels and order differ.

- [ ] **Step 3: Extract a small admin shell**

Create private render helpers in each existing `Admin` class without moving page ownership to a new framework:

```php
private static function page_start(string $active, string $title, string $description = ''): void
private static function page_end(): void
private static function status_row(string $label, string $status, string $detail, string $action_html = ''): void
private static function tabs(array $tabs, string $active): void
```

Keep helpers focused on escaping and structure. Do not create a generic component abstraction for every form field.

- [ ] **Step 4: Refine each Free screen**

- Dashboard: readiness checklist, recent activity, totals, issues, one next action.
- Campaigns: Data, Template, Publishing, Preflight, Generate, Result sections.
- Data: source table and focused upload panel.
- Templates: operational table with token, builder, and usage columns.
- SEO: tabs for Overview, Metadata, Schema, Internal Links, Sitemap and llms.txt, Social and Images, Technical; preserve old page slugs by opening the matching tab.
- AI Planner: conversation workspace with source references and structured proposed actions.
- Connections: Task 2 output.
- Settings: brand, schema, publishing, privacy, diagnostics; no provider secrets.

Delete unreachable `render_plan()` and `render_account()` methods. Remove the Free JavaScript request to unregistered `pageforge_ai_generate_meta_bulk`; render that affordance as a disabled Pro row without posting AJAX.

- [ ] **Step 5: Refine each Pro screen**

Apply the same shell. Keep queue and campaign behavior, but place Queue, Campaigns, and Schedule as tabs in the Campaigns workspace. Preserve old slugs with a server-side redirect that retains safe query parameters such as campaign ID.

- [ ] **Step 6: Replace card-heavy CSS tokens**

Set `--pf-radius: 8px`. Remove decorative gradients, nested shadows, and broad pill styling. Use full-width sections with dividers, compact tables, and 8px cards only for repeated connection or campaign items where the boundary is interactive.

Add `:focus-visible`, `prefers-reduced-motion`, 320px wrapping, WordPress admin-bar offsets, and fixed dimensions for icon buttons and status dots.

- [ ] **Step 7: Verify static and browser behavior**

Run tests and syntax checks. Then on a local WordPress copy, capture each Free and Pro screen at 1440x900, 768x1024, and 320x800. Verify no overlap, horizontal overflow, clipped translated labels, nested cards, console errors, or blocked primary workflow.

- [ ] **Step 8: Commit each repository separately**

Use `feat: streamline pageforge admin workflow` in Free and `feat: streamline pageforge pro admin workflow` in Premium.

## Plan 2 Verification Gate

Run the full test runner and syntax checks in both repositories. On a copied WordPress site, complete one CSV upload, template selection, preflight, draft generation, review-request action, provider health check, Pro queue operation, and legacy-slug redirect. Record screenshots and console output in the plan's subagent-development workspace, not in release ZIPs.
