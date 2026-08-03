# PageForge Release Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish PageForge Free 1.2.0 and Pro 1.1.0 with complete translations, accurate claims, release versions, production checks, deterministic packages, and rehearsed upgrades and rollback.

**Architecture:** Release work begins only after the first four plans pass. Source strings and public documentation are regenerated from actual behavior, then version and database upgrades are finalized. Automated static checks, copied-site integration tests, browser verification, MCP client smoke tests, and artifact inspection form one release gate for both repositories.

**Tech Stack:** PHP and JavaScript syntax tools, WordPress i18n and Plugin Check tools, gettext catalogs, PowerShell release scripts, WordPress admin/browser testing, MCP Inspector and supported clients.

## Global Constraints

- Free release is exactly `1.2.0`; Pro release is exactly `1.1.0`.
- Stable tags, plugin headers, constants, changelogs, and asset cache versions must agree.
- Free WordPress.org package must remain under 10MB and contain no tests, plans, Git data, development tools, secrets, or Premium code.
- Premium package includes required Freemius runtime files but excludes tests, plans, Git data, development tools, and secrets.
- Public claims list only capabilities that passed the release matrix.
- Builder names appear as supported only after fixture and copied-site verification.
- MCP, analytics, and provider external-service disclosures identify what data is sent, when, why, and where terms/privacy policies apply.
- No release claim promises rankings, traffic, revenue, flawless AI output, or zero bugs.

---

### Task 1: Source String And Translation Cleanup

**Files:**
- Modify in Free: all PHP and JavaScript files containing visible strings
- Modify in Free: `languages/pageforge.pot`
- Modify in Free: `languages/pageforge-en_US.po`
- Modify in Free: `languages/pageforge-en_GB.po`
- Modify in Free: `languages/pageforge-de_DE.po`
- Modify in Free: `languages/pageforge-fr_FR.po`
- Modify in Free: `languages/pageforge-pt_PT.po`
- Rebuild in Free: matching `.mo` files
- Create in Premium: `languages/pageforge.pot`
- Create in Premium: `languages/pageforge-en_US.po`
- Create in Premium: `languages/pageforge-en_GB.po`
- Create in Premium: `languages/pageforge-de_DE.po`
- Create in Premium: `languages/pageforge-fr_FR.po`
- Create in Premium: `languages/pageforge-pt_PT.po`
- Create in Premium: matching `.mo` files
- Modify in Premium: `includes/class-pageforge-plugin.php`
- Modify in Premium: all PHP and JavaScript files containing visible strings

**Interfaces:**
- Consumes: final source after Plans 1-4.
- Produces: complete `pageforge` gettext catalogs and localized admin JavaScript objects in both editions.

- [ ] **Step 1: Add translation-loading regression tests**

Capture `load_plugin_textdomain()` and assert both editions call it on `init` with domain `pageforge` and language path derived from `PAGEFORGE_BASENAME`. Scan PHP for visible hard-coded English in `wp_send_json_*`, headings, labels, buttons, and status messages changed by this program.

- [ ] **Step 2: Run tests and string scans to establish RED**

Run tests in both repositories and:

```powershell
rg -n "Unauthorized|Missing template|Upgrade required|Connection OK" pageforge.php includes assets languages -S
```

Expected: Premium lacks complete text-domain loading and both editions contain mojibake or unlocalized strings.

- [ ] **Step 3: Fix source strings**

Use UTF-8 source, translated complete sentences, context functions for ambiguous words, plural functions for counts, and localized JavaScript entries for every dynamic message. Do not concatenate translated fragments. Keep machine error codes unlocalized and translate only their user-facing messages.

- [ ] **Step 4: Regenerate POT files from source**

Use an isolated WP-CLI development tool and run in each repository:

```powershell
wp i18n make-pot . languages\pageforge.pot --domain=pageforge --exclude=vendor,tests,docs,.git,.superpowers
```

Expected: POT includes all final strings and no removed Plan/Account or dead bulk-meta strings.

- [ ] **Step 5: Merge and complete supported translations**

Merge each PO against the new POT, translate new strings in German, French, and European Portuguese, and preserve English regional spelling differences. Review Portuguese spacing and grammar manually. Build MO files with `wp i18n make-mo languages`.

- [ ] **Step 6: Validate catalogs**

Run:

```powershell
wp i18n make-json languages --no-purge
msgfmt --check-format -o NUL languages\pageforge-de_DE.po
msgfmt --check-format -o NUL languages\pageforge-fr_FR.po
msgfmt --check-format -o NUL languages\pageforge-pt_PT.po
rg -n "msgstr \"\"$" languages -S
```

Expected: format checks pass, no mojibake exists, and the only empty `msgstr` is the PO header entry.

- [ ] **Step 7: Verify and commit each repository**

Run tests, PHP lint, and JavaScript checks. Commit with `fix: complete free localization` and `fix: complete pro localization`.

### Task 2: Public Documentation And Claim Alignment

**Files:**
- Modify in Free: `readme.txt`
- Modify in Free: `README.md`
- Modify in Free: `changelog.txt`
- Modify in Premium: `readme.txt`
- Create in Premium: `changelog.txt`
- Modify in Free: `docs/PAGEFORGE_FREE_AUDIT.md`
- Modify in Free: `docs/PAGEFORGE_PRO_ALIGNMENT.md`
- Modify in Free: `docs/PAGEFORGE_MCP_CONNECTORS_PRD.md`

**Interfaces:**
- Consumes: verified feature list from Plans 1-4.
- Produces: accurate WordPress.org and Premium documentation, setup instructions, privacy disclosures, and release notes.

- [ ] **Step 1: Write the verified capability inventory**

Generate a table from actual tests and manual gates with status `verified`, `experimental`, or `not shipped`. Include every AI provider, data source, builder, MCP client, tool group, analytics connection, SEO action, and migration path. Documentation may use only `verified` items as unqualified claims.

- [ ] **Step 2: Correct Free documentation**

Document Free CSV limits, provider connections, MCP read/dry-run behavior, the disabled default, Application Password fallback, review-request behavior, accurate shortcodes, and Perplexity Pro lock. Remove claims that Free reads Google Sheets or Analytics reports when it does not.

Use these shortcode examples:

```text
[pageforge_sitemap]
[pageforge_sitemap group_by="city" columns="3" style="cards"]
[pageforge_sitemap post_type="pageforge_page,page,post" group_by="state" show_counts="1"]
```

- [ ] **Step 3: Correct Premium documentation**

Document migration diagnostics, OAuth and fallback authentication, confirmation and audit behavior, tool groups, analytics authorization, builder status labels, queue/campaign controls, and revocation. Do not claim one-click OAuth for a client whose copied-site test did not pass.

- [ ] **Step 4: Add external-service disclosures**

For OpenAI, Anthropic, Gemini, DeepSeek, Perplexity, Pexels, GA4 Measurement Protocol, Google Analytics Data, Search Console, and remote MCP clients, state data sent, trigger, purpose, opt-in/default state, and links to provider terms/privacy. State that MCP clients receive only tool inputs/results the user authorizes and that Pro writes are audited locally.

- [ ] **Step 5: Validate readmes and claims**

Run the WordPress readme validator for Free and scan:

```powershell
rg -n "#1|number one|guarantee|guaranteed|unlimited|all builders|all MCP|no bugs|Google Sheets" readme.txt README.md changelog.txt docs -i -S
```

Review each hit against the verified inventory; remove or qualify unsupported claims.

- [ ] **Step 6: Commit documentation separately**

Use `docs: align free release claims` and `docs: align pro release claims`.

### Task 3: Version, Database, And Upgrade Finalization

**Files:**
- Modify in Free: `pageforge.php:1-25`
- Modify in Free: `readme.txt:1-12`
- Modify in Free: `changelog.txt`
- Modify in Free: `includes/class-pageforge-upgrade.php`
- Create in Free: `tests/VersionTest.php`
- Modify in Premium: `pageforge.php:1-280`
- Modify in Premium: `readme.txt:1-12`
- Modify in Premium: `changelog.txt`
- Modify in Premium: `includes/class-pageforge-upgrade.php`
- Create in Premium: `tests/VersionTest.php`

**Interfaces:**
- Produces: consistent Free `1.2.0`, Pro `1.1.0`, `PAGEFORGE_DB_VERSION`, and idempotent completed upgrade markers.

- [ ] **Step 1: Write failing version consistency tests**

Parse source files and assert:

```php
// Free expected values.
['plugin_header' => '1.2.0', 'constant' => '1.2.0', 'stable_tag' => '1.2.0']

// Pro expected values.
['plugin_header' => '1.1.0', 'constant' => '1.1.0', 'stable_tag' => '1.1.0']
```

Assert changelogs contain matching headings and upgrade routines do not rerun completed schema versions.

- [ ] **Step 2: Run tests to verify RED**

Run `php tests\run.php` in each repository.

Expected: current versions are still 1.1.8 and 1.0.10.

- [ ] **Step 3: Finalize upgrade versions**

Set Free header, `PAGEFORGE_VERSION`, and Stable tag to `1.2.0`. Set Pro equivalents to `1.1.0`. Define `PAGEFORGE_DB_VERSION` to the schema version actually installed by each edition and store `pageforge_db_version` only after all table and option migrations succeed.

Keep content namespace initialization independently idempotent. A failed audit/OAuth table creation must not mark Pro database upgrade complete.

- [ ] **Step 4: Add complete changelog entries**

List compatibility, admin workflow, Connections, preflight, review request Free-only behavior, MCP, Pro operator, OAuth, audit, analytics, builders, translations, fixed bugs, and upgrade notes. Distinguish security behavior from marketing features.

- [ ] **Step 5: Verify and commit versions**

Run tests and lint. Commit with `chore: prepare pageforge 1.2.0` and `chore: prepare pageforge pro 1.1.0`.

### Task 4: Static Production Checks

**Files:**
- Create in Free: `tools/check-release.ps1`
- Create in Premium: `tools/check-release.ps1`
- Modify code found by checks across both repositories

**Interfaces:**
- Produces: one deterministic local command that fails on syntax, secret, placeholder, debug, version, translation, or package-policy violations.

- [ ] **Step 1: Create the release check script in each repository**

The script must:

1. lint every PHP file outside `vendor`
2. run `node --check` on owned JavaScript
3. run `php tests/run.php`
4. run `git diff --check`
5. fail on `var_dump`, `print_r`, `console.log`, unfinished-work markers assembled as `('TO' + 'DO')` and `('FIX' + 'ME')`, conflict markers, or known mojibake in production paths
6. fail on likely committed secrets using named provider key patterns
7. validate version consistency
8. validate POT/PO files
9. report every command and preserve the first non-zero exit

- [ ] **Step 2: Run scripts to establish failures**

Run `powershell -ExecutionPolicy Bypass -File tools\check-release.ps1` in both repositories. Record every failure in the task ledger.

- [ ] **Step 3: Fix production issues with regression tests**

For each behavioral issue, add a failing test first, verify the failure, implement the minimal correction, and rerun the focused and full checks. For pure debug or encoding cleanup, verify the release script itself catches the bad fixture before removing it.

- [ ] **Step 4: Run WordPress Plugin Check**

On a local WordPress copy with Plugin Check installed:

```powershell
wp plugin check pageforge --format=table
wp plugin check pageforge-premium --format=table
```

Resolve security, performance, accessibility, internationalization, and directory-compliance errors. Document any third-party Freemius warning that cannot be changed without modifying vendor code.

- [ ] **Step 5: Commit release checks and fixes**

Commit separately in each repository with `test: add production release checks`.

### Task 5: Upgrade, Browser, Builder, And MCP Client Matrix

**Files:**
- Create in Free: `docs/release/1.2.0-verification.md`
- Create in Premium: `docs/release/1.1.0-verification.md`
- Modify implementation files only when a matrix case reveals a tested defect

**Interfaces:**
- Produces: evidence-backed release matrix with environment, steps, result, and linked diagnostic IDs.

- [ ] **Step 1: Prepare copied-site fixtures**

Create four disposable databases:

- clean WordPress with no PageForge data
- Free 1.1.8 data with templates, generated drafts/published pages, Elementor fixture, and translations
- Pro 1.0.10 data with queue, campaign, cron state, `pf_*` content, and one failed item
- mixed Free and Pro identifiers with duplicate-looking slugs and conflicting alias metadata

Back up each fixture before testing and record hashes. Never use the production database.

- [ ] **Step 2: Run installation lifecycle matrix**

For each fixture, test install/upgrade, activation, repeated activation, deactivation/reactivation, cron, permalinks, namespace choice, counts, URLs, metadata, builder payloads, queue, campaign, settings, and rollback to the previous plugin version.

Compare pre/post exports and require zero changed post IDs, slugs, URLs, statuses, dates, or builder payloads unless the test explicitly executed a confirmed change.

- [ ] **Step 3: Run admin browser matrix**

At 1440x900, 768x1024, and 320x800, complete every primary Free and Pro workflow. Capture Dashboard, Campaigns, Data, Templates, all SEO tabs, AI Planner, Connections, Settings, success/error/empty/locked states, review request, OAuth consent, audit, and migration diagnostics.

Require no overlap, horizontal overflow, clipped controls, keyboard traps, console errors, PHP warnings, or unexpected network failures.

- [ ] **Step 4: Run builder matrix**

For every adapter marked supported, generate a draft, open the builder editor, edit and save one field, view frontend output, refresh assets, and compare the source base layout to ensure it was not mutated. Downgrade any adapter without full evidence to experimental before release.

- [ ] **Step 5: Run MCP client matrix**

Test MCP Inspector, ChatGPT/OpenAI remote MCP, Claude remote MCP, Cursor, Perplexity remote custom connector, and a generic HTTP client where access is available. For each client test connect, discover, read, preview, confirm one Pro write, reject one missing scope, revoke, and reconnect.

Free must discover only read/dry-run tools. Pro must discover at least 40 tools with full scope and fewer with restricted scope.

- [ ] **Step 6: Run provider and analytics matrix**

Test configured OpenAI, Anthropic, Gemini, DeepSeek, Perplexity, custom compatible provider, Pexels, GA4 event measurement, GA4 Data, and Search Console. Record unavailable credentials as `not tested` and remove unverified broad claims.

- [ ] **Step 7: Commit verification records**

Commit records with `test: document 1.2.0 release verification` and `test: document pro 1.1.0 release verification`.

### Task 6: Deterministic Release Packages And Rollback Bundle

**Files:**
- Create in Free: `tools/build-release.ps1`
- Create in Premium: `tools/build-release.ps1`
- Create locally, do not commit: `dist/pageforge-1.2.0.zip`
- Create locally, do not commit: `dist/pageforge-1.2.0.sha256`
- Create locally, do not commit: `dist/pageforge-premium-1.1.0.zip`
- Create locally, do not commit: `dist/pageforge-premium-1.1.0.sha256`

**Interfaces:**
- Produces: reproducible release ZIPs with one plugin root directory and SHA-256 files.

- [ ] **Step 1: Write package-content assertions before the build**

The build script fails unless source checks pass and asserts the ZIP includes plugin bootstrap, includes, owned assets, translations, readme, changelog, and Premium vendor files where required. It fails if the ZIP contains `.git`, `.worktrees`, `.superpowers`, `docs`, `tests`, `tools`, `dist`, editor files, logs, environment files, source maps not required at runtime, or credential-looking files.

- [ ] **Step 2: Build from clean worktrees**

Copy allowlisted runtime files into a versioned temporary directory under `dist/build`, normalize archive paths, create the ZIP with one root folder, calculate SHA-256, then remove only the verified `dist/build` temporary directory.

- [ ] **Step 3: Inspect package contents**

Expand each ZIP into a fresh temporary directory. Run PHP lint, JavaScript checks, version tests, secret scan, and file allowlist against the expanded package. Verify Free ZIP is under 10MB.

- [ ] **Step 4: Install packages on a fresh copied site**

Install the actual ZIPs, not source directories. Repeat activation, one Free generation, Free review prompt, Free MCP discovery, Pro upgrade, Pro OAuth, one confirmed Pro metadata change, audit lookup, and rollback.

- [ ] **Step 5: Produce release handoff**

Report commit SHAs, ZIP paths, SHA-256 hashes, package sizes, full test counts, Plugin Check status, verified clients/providers/builders, known experimental items, migration result, and rollback result. Do not publish, push, or upload without explicit user authorization.

- [ ] **Step 6: Commit build scripts**

Commit separately with `build: add deterministic free release package` and `build: add deterministic pro release package`.

## Plan 5 Final Verification Gate

From clean worktrees, run both release scripts, both package builds, both expanded-package checks, the copied-site lifecycle matrix, browser matrix, builder matrix, MCP client matrix, and rollback rehearsal. Read every command's complete output. A release is not complete while any required check is failing, skipped without documentation, or based on source files instead of the final ZIP.
