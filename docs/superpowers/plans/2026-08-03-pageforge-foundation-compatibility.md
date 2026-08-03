# PageForge Compatibility And Test Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add repeatable tests and a non-destructive compatibility layer so Free and Pro can read both current `pageforge_*` and legacy `pf_*` content safely.

**Architecture:** Each repository receives a dependency-free PHP test runner and the same public `PageForge\Compatibility` contract. Compatibility centralizes post-type aliases, metadata aliases, and immutable namespace selection. Existing generator, template, frontend, admin, queue, and cron queries are then routed through that contract without rewriting stored content.

**Tech Stack:** PHP 7.4, WordPress post types and metadata APIs, a repository-local PHP test runner with deterministic WordPress stubs.

## Global Constraints

- Free target version is `1.2.0`; Pro target version is `1.1.0`, but version headers are changed only in the release-hardening plan.
- Production code must remain PHP 7.4 compatible; do not use enums, union types, attributes, `str_starts_with()`, or constructor property promotion.
- Compatibility repairs may add missing alias metadata but may not change post IDs, post types, slugs, URLs, statuses, dates, authors, revisions, or builder-owned metadata.
- Namespace selection is stored once in `pageforge_content_namespace` and is not changed automatically later.
- A new site selects `pageforge`; a site containing Free content selects `pageforge`; a site containing only legacy Pro content selects `pf`.
- Both repositories expose matching method signatures for `PageForge\Compatibility`.

---

### Task 1: Free Compatibility Contract And Test Harness

**Files:**
- Create: `tests/bootstrap.php`
- Create: `tests/TestCase.php`
- Create: `tests/run.php`
- Create: `tests/CompatibilityTest.php`
- Create: `includes/class-pageforge-compatibility.php`
- Modify: `pageforge.php:28-40`

**Interfaces:**
- Consumes: WordPress functions `get_option()`, `update_option()`, `get_post_meta()`, `metadata_exists()`, `update_post_meta()`, `post_type_exists()`, and `wp_count_posts()`.
- Produces: `PageForge\Compatibility::template_post_types(): array`, `generated_post_types(): array`, `meta_keys(string $logical): array`, `read_meta(int $post_id, string $logical, $default = '')`, `write_meta(int $post_id, string $logical, $value): bool`, `select_namespace(array $counts): string`, `namespace(): string`, and `post_type(string $kind, ?string $namespace = null): string`.

- [ ] **Step 1: Add the dependency-free runner and WordPress state stubs**

Create `tests/TestCase.php` with exact assertion primitives:

```php
<?php
namespace PageForge\Tests;

final class TestCase {
    public static function same($expected, $actual, string $message = ''): void {
        if ($expected !== $actual) {
            throw new \RuntimeException($message !== '' ? $message : sprintf(
                "Expected %s, got %s",
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    public static function true($actual, string $message = ''): void {
        self::same(true, $actual, $message);
    }
}
```

Create `tests/bootstrap.php` with in-memory options, post meta, and post-type counts. Define `ABSPATH`, load `TestCase.php`, and provide stubs whose signatures match the WordPress functions used by `Compatibility`. Reset all globals through `pageforge_test_reset_state(): void` before each test.

Create `tests/run.php` to load every `*Test.php`, execute user-defined functions prefixed `test_pageforge_`, print one `PASS <name>` line per success, print one `FAIL <name>: <message>` line per failure, and exit `1` when any test fails. Fixture helpers retain the separate `pageforge_test_` prefix and are never executed as tests.

- [ ] **Step 2: Write failing Free compatibility tests**

Create `tests/CompatibilityTest.php`:

```php
<?php
use PageForge\Compatibility;
use PageForge\Tests\TestCase;

function test_pageforge_new_site_selects_pageforge_namespace(): void {
    pageforge_test_reset_state();
    TestCase::same('pageforge', Compatibility::select_namespace([
        'pageforge_templates' => 0,
        'pageforge_pages' => 0,
        'pf_templates' => 0,
        'pf_pages' => 0,
    ]));
}

function test_pageforge_legacy_pro_site_keeps_pf_namespace(): void {
    pageforge_test_reset_state();
    TestCase::same('pf', Compatibility::select_namespace([
        'pageforge_templates' => 0,
        'pageforge_pages' => 0,
        'pf_templates' => 2,
        'pf_pages' => 40,
    ]));
}

function test_pageforge_free_content_wins_on_mixed_site(): void {
    pageforge_test_reset_state();
    TestCase::same('pageforge', Compatibility::select_namespace([
        'pageforge_templates' => 1,
        'pageforge_pages' => 5,
        'pf_templates' => 3,
        'pf_pages' => 20,
    ]));
}

function test_pageforge_read_meta_falls_back_to_legacy_alias(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_meta'][71]['_pf_vars'] = ['City' => 'Pune'];
    TestCase::same(['City' => 'Pune'], Compatibility::read_meta(71, 'vars', []));
}

function test_pageforge_write_meta_uses_selected_namespace(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_options']['pageforge_content_namespace'] = 'pageforge';
    TestCase::true(Compatibility::write_meta(71, 'generated', 1));
    TestCase::same(1, $GLOBALS['pageforge_test_meta'][71]['_pageforge_generated']);
}
```

- [ ] **Step 3: Run the tests to verify RED**

Run:

```powershell
php tests\run.php
```

Expected: exit `1` because `PageForge\Compatibility` and its methods do not exist.

- [ ] **Step 4: Implement the minimal Free compatibility class**

Create `includes/class-pageforge-compatibility.php` with this mapping and public contract:

```php
<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

final class Compatibility {
    private const POST_TYPES = [
        'template' => ['pageforge' => 'pageforge_template', 'pf' => 'pf_template'],
        'generated' => ['pageforge' => 'pageforge_page', 'pf' => 'pf_page'],
    ];

    private const META = [
        'generated' => ['pageforge' => ['_pageforge_generated', '_pageforge_generated_status'], 'pf' => ['_pf_generated']],
        'template_id' => ['pageforge' => ['_pageforge_template_id', '_pageforge_template'], 'pf' => ['_pf_template_id', '_pf_template']],
        'vars' => ['pageforge' => ['_pageforge_vars'], 'pf' => ['_pf_vars']],
        'title_pattern' => ['pageforge' => ['_pageforge_title_pattern'], 'pf' => ['_pf_title_pattern']],
        'slug_pattern' => ['pageforge' => ['_pageforge_slug_pattern'], 'pf' => ['_pf_slug_pattern']],
        'post_type' => ['pageforge' => ['_pageforge_post_type'], 'pf' => ['_pf_post_type']],
        'base_post_id' => ['pageforge' => ['_pageforge_base_post_id'], 'pf' => ['_pf_base_post_id']],
        'meta_title' => ['pageforge' => ['_pageforge_meta_title'], 'pf' => ['_pf_meta_title']],
        'meta_description' => ['pageforge' => ['_pageforge_meta_description'], 'pf' => ['_pf_meta_description']],
        'focus_keyword' => ['pageforge' => ['_pageforge_focus_keyword'], 'pf' => ['_pf_focus_keyword']],
        'row_fingerprint' => ['pageforge' => ['_pageforge_row_fingerprint'], 'pf' => ['_pf_row_fingerprint']],
    ];

    public static function template_post_types(): array {
        return array_values(self::POST_TYPES['template']);
    }

    public static function generated_post_types(): array {
        return array_values(self::POST_TYPES['generated']);
    }

    public static function meta_keys(string $logical): array {
        if (!isset(self::META[$logical])) return [];
        $selected = self::namespace();
        $alternate = $selected === 'pageforge' ? 'pf' : 'pageforge';
        return array_merge(self::META[$logical][$selected], self::META[$logical][$alternate]);
    }

    public static function select_namespace(array $counts): string {
        if ((int) ($counts['pageforge_templates'] ?? 0) > 0 || (int) ($counts['pageforge_pages'] ?? 0) > 0) {
            return 'pageforge';
        }
        if ((int) ($counts['pf_templates'] ?? 0) > 0 || (int) ($counts['pf_pages'] ?? 0) > 0) {
            return 'pf';
        }
        return 'pageforge';
    }

    public static function namespace(): string {
        $stored = (string) \get_option('pageforge_content_namespace', '');
        return in_array($stored, ['pageforge', 'pf'], true) ? $stored : 'pageforge';
    }

    public static function post_type(string $kind, ?string $namespace = null): string {
        $namespace = $namespace ?: self::namespace();
        return self::POST_TYPES[$kind][$namespace] ?? '';
    }

    public static function read_meta(int $post_id, string $logical, $default = '') {
        foreach (self::meta_keys($logical) as $key) {
            if (\metadata_exists('post', $post_id, $key)) {
                return \get_post_meta($post_id, $key, true);
            }
        }
        return $default;
    }

    public static function write_meta(int $post_id, string $logical, $value): bool {
        $keys = self::META[$logical][self::namespace()] ?? [];
        $key = $keys[0] ?? '';
        if ($key === '') return false;
        if (\metadata_exists('post', $post_id, $key) && \get_post_meta($post_id, $key, true) === $value) return true;
        return false !== \update_post_meta($post_id, $key, $value);
    }
}
```

Require the file in `pageforge.php` before classes that consume it.

- [ ] **Step 5: Run the Free tests to verify GREEN**

Run:

```powershell
php tests\run.php
php -l includes\class-pageforge-compatibility.php
```

Expected: five compatibility tests pass and PHP lint exits `0`.

- [ ] **Step 6: Commit the Free foundation**

```powershell
git add pageforge.php includes/class-pageforge-compatibility.php tests
git commit -m "test: add free compatibility foundation"
```

### Task 2: Free Namespace Initialization And Shared Reads

**Files:**
- Create: `includes/class-pageforge-upgrade.php`
- Create: `tests/UpgradeTest.php`
- Create: `tests/TemplateCompatibilityTest.php`
- Modify: `pageforge.php`
- Modify: `includes/class-pageforge-plugin.php:47-107`
- Modify: `includes/class-pageforge-template.php`
- Modify: `includes/class-pageforge-generator.php`
- Modify: `includes/class-pageforge-frontend.php`
- Modify: `includes/class-pageforge-admin.php:83-176,1412-1433`

**Interfaces:**
- Consumes: `Compatibility` from Task 1.
- Produces: `PageForge\Upgrade::run(): void`, `Upgrade::content_counts(): array`, and Free queries that include both post-type namespaces.

- [ ] **Step 1: Write failing upgrade and alias-read tests**

Add tests that prove:

```php
function test_pageforge_upgrade_persists_namespace_only_once(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_post_counts']['pf_template'] = 2;
    \PageForge\Upgrade::run();
    \PageForge\Tests\TestCase::same('pf', get_option('pageforge_content_namespace'));
    $GLOBALS['pageforge_test_post_counts']['pageforge_template'] = 5;
    \PageForge\Upgrade::run();
    \PageForge\Tests\TestCase::same('pf', get_option('pageforge_content_namespace'));
}

function test_pageforge_template_reads_legacy_title_pattern(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_meta'][9]['_pf_title_pattern'] = '{Service} in {City}';
    \PageForge\Tests\TestCase::same('{Service} in {City}', \PageForge\Template::title_pattern(9));
}
```

Extend stubs with `wp_count_posts()` so `Upgrade::content_counts()` can count deterministic fixtures.

- [ ] **Step 2: Run tests to verify RED**

Run `php tests\run.php`.

Expected: failures because `Upgrade` is absent and `Template` reads only `_pageforge_title_pattern`.

- [ ] **Step 3: Implement idempotent namespace initialization**

Create `includes/class-pageforge-upgrade.php`:

```php
<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

final class Upgrade {
    public static function run(): void {
        $stored = (string) \get_option('pageforge_content_namespace', '');
        if (in_array($stored, ['pageforge', 'pf'], true)) return;
        \update_option('pageforge_content_namespace', Compatibility::select_namespace(self::content_counts()), false);
    }

    public static function content_counts(): array {
        return [
            'pageforge_templates' => self::count('pageforge_template'),
            'pageforge_pages' => self::count('pageforge_page'),
            'pf_templates' => self::count('pf_template'),
            'pf_pages' => self::count('pf_page'),
        ];
    }

    private static function count(string $post_type): int {
        $counts = \wp_count_posts($post_type);
        if (!$counts) return 0;
        return array_sum(array_map('intval', get_object_vars($counts)));
    }
}
```

Call `Upgrade::run()` from activation and from `plugins_loaded` before admin or generation queries. Extend the test stub with `wp_count_posts()` rather than changing production logic to suit tests.

- [ ] **Step 4: Route Free shared fields through Compatibility**

Change `Template::title_pattern()`, `slug_pattern()`, and `post_type()` to call `Compatibility::read_meta()`.

In `Generator`, use `Compatibility::template_post_types()` to validate templates, `Compatibility::post_type('generated')` as the default target, `Compatibility::read_meta()` for shared template fields, and `Compatibility::write_meta()` for generated marker, template ID, vars, and shared SEO fields.

Update frontend generated-page detection and admin generated-page counts to query `Compatibility::generated_post_types()` and accept either generated marker key. Preserve builder-specific and Free-only meta keys unchanged.

- [ ] **Step 5: Run tests and syntax checks**

```powershell
php tests\run.php
Get-ChildItem includes -Filter *.php | ForEach-Object { php -l $_.FullName }
```

Expected: all tests pass and every file reports no syntax errors.

- [ ] **Step 6: Commit Free compatibility routing**

```powershell
git add pageforge.php includes tests
git commit -m "feat: preserve free and legacy pageforge content"
```

### Task 3: Pro Compatibility Contract And Test Harness

**Files:**
- Create in Premium: `tests/bootstrap.php`
- Create in Premium: `tests/TestCase.php`
- Create in Premium: `tests/run.php`
- Create in Premium: `tests/CompatibilityTest.php`
- Create in Premium: `includes/class-pageforge-compatibility.php`
- Create in Premium: `includes/class-pageforge-upgrade.php`
- Modify in Premium: `pageforge.php:244-280`

**Interfaces:**
- Consumes: The exact public `PageForge\Compatibility` and `PageForge\Upgrade` signatures established in Free Tasks 1-2.
- Produces: Matching Pro compatibility behavior plus `Upgrade::diagnose(): array` for the later migration screen.

- [ ] **Step 1: Copy the test infrastructure contract into Premium**

Add the same runner and assertions from Free. Use `test_pageforge_` for executable tests and `pageforge_test_` for fixture helpers so commands remain identical across repositories.

- [ ] **Step 2: Write failing Pro compatibility tests**

Copy the five Free compatibility tests and add:

```php
function test_pageforge_diagnostic_counts_both_namespaces(): void {
    pageforge_test_reset_state();
    $GLOBALS['pageforge_test_post_counts'] = [
        'pageforge_template' => 3,
        'pageforge_page' => 120,
        'pf_template' => 2,
        'pf_page' => 80,
    ];
    \PageForge\Tests\TestCase::same([
        'namespace' => 'pageforge',
        'free_templates' => 3,
        'free_generated_pages' => 120,
        'legacy_pro_templates' => 2,
        'legacy_pro_generated_pages' => 80,
        'mixed' => true,
    ], \PageForge\Upgrade::diagnose());
}
```

- [ ] **Step 3: Run Premium tests to verify RED**

Run `php tests\run.php` from the Premium worktree.

Expected: failures because the compatibility and upgrade classes do not exist.

- [ ] **Step 4: Implement the matching Premium classes**

Use the same post-type and meta mapping as Free. Add Pro-only mappings for `default_status`, `ai_seo_enabled`, `ai_seo_length`, `ai_seo_autolink`, `seo_content`, `seo_warning`, `campaign_ref`, `last_campaign_id`, and `last_queue_id` while preserving the public method signatures.

Implement `Upgrade::diagnose()`:

```php
public static function diagnose(): array {
    $counts = self::content_counts();
    return [
        'namespace' => Compatibility::select_namespace($counts),
        'free_templates' => (int) $counts['pageforge_templates'],
        'free_generated_pages' => (int) $counts['pageforge_pages'],
        'legacy_pro_templates' => (int) $counts['pf_templates'],
        'legacy_pro_generated_pages' => (int) $counts['pf_pages'],
        'mixed' => ((int) $counts['pageforge_templates'] + (int) $counts['pageforge_pages']) > 0
            && ((int) $counts['pf_templates'] + (int) $counts['pf_pages']) > 0,
    ];
}
```

Require both classes before `Plugin` is instantiated and call `Upgrade::run()` on activation and `plugins_loaded`.

- [ ] **Step 5: Verify Premium GREEN**

```powershell
php tests\run.php
php -l includes\class-pageforge-compatibility.php
php -l includes\class-pageforge-upgrade.php
```

Expected: six tests pass and both lint commands exit `0`.

- [ ] **Step 6: Commit Premium compatibility foundation**

```powershell
git add pageforge.php includes/class-pageforge-compatibility.php includes/class-pageforge-upgrade.php tests
git commit -m "test: add pro compatibility foundation"
```

### Task 4: Pro Shared Reads, Writes, Queue, And Cron Compatibility

**Files:**
- Create in Premium: `tests/ProCompatibilityIntegrationTest.php`
- Modify in Premium: `includes/class-pageforge-plugin.php`
- Modify in Premium: `includes/class-pageforge-template.php`
- Modify in Premium: `includes/class-pageforge-generator.php`
- Modify in Premium: `includes/class-pageforge-frontend.php`
- Modify in Premium: `includes/class-pageforge-admin.php`
- Modify in Premium: `includes/class-pageforge-cron.php`
- Modify in Premium: `includes/helpers.php`

**Interfaces:**
- Consumes: Premium `Compatibility` and `Upgrade` from Task 3.
- Produces: Pro behavior that lists, generates, deduplicates, queues, optimizes, and renders both namespaces without changing stored post types.

- [ ] **Step 1: Write failing mixed-site integration tests**

Add fixtures for one `pageforge_template`, one `pf_template`, generated posts in both namespaces, and alias metadata. Test these outcomes:

```php
function test_pageforge_pro_template_accepts_both_template_post_types(): void {
    pageforge_test_reset_state();
    pageforge_test_add_post(11, 'pageforge_template', 'Free template');
    pageforge_test_add_post(12, 'pf_template', 'Legacy Pro template');
    \PageForge\Tests\TestCase::true(\PageForge\Template::get(11) !== null);
    \PageForge\Tests\TestCase::true(\PageForge\Template::get(12) !== null);
}

function test_pageforge_duplicate_detection_includes_both_generated_post_types(): void {
    pageforge_test_reset_state();
    pageforge_test_add_post(30, 'pageforge_page', 'Existing', 'dentist-in-pune');
    $types = (new \PageForge\Generator())->duplicate_check_post_types_for_test('pf_page');
    sort($types);
    \PageForge\Tests\TestCase::same(['pageforge_page', 'pf_page'], array_values(array_intersect($types, ['pageforge_page', 'pf_page'])));
}
```

Do not add a production method only for tests. Expose duplicate types through a small `Compatibility::generated_post_types()` consumer or test the public generation result with a slug fixture.

- [ ] **Step 2: Run Premium tests to verify RED**

Run `php tests\run.php`.

Expected: `Template::get(11)` fails and legacy duplicate detection misses `pageforge_page`.

- [ ] **Step 3: Route Pro template and generation behavior**

- Accept any `Compatibility::template_post_types()` result in template validation and export.
- Use the selected namespace for new post types and shared meta writes.
- Use both generated post types and both generated marker aliases for duplicate detection.
- Keep queue and campaign option names unchanged.
- Include both generated types in bulk metadata queries, AI post search, dashboard counts, cron daily counts, sitemap output, and generated-page reports.
- Keep builder payload keys unchanged and preserve current fingerprint logic.

- [ ] **Step 4: Register the selected namespace and existing aliases**

In `Plugin::register_cpts()`, always register the selected template and generated post types. Register the alternate post types only when `Upgrade::content_counts()` reports existing objects for them. Set `show_ui => false` for the alternate template type so templates appear through the unified PageForge list instead of a duplicate WordPress menu.

- [ ] **Step 5: Run Premium tests and syntax checks**

```powershell
php tests\run.php
Get-ChildItem includes -Filter *.php | ForEach-Object { php -l $_.FullName }
```

Expected: mixed-site tests pass and every PHP file lints.

- [ ] **Step 6: Commit Premium compatibility routing**

```powershell
git add includes tests
git commit -m "feat: preserve free and legacy pro content"
```

### Task 5: Pro Migration Diagnostic And Repair Preview

**Files:**
- Create in Premium: `includes/class-pageforge-migration.php`
- Create in Premium: `tests/MigrationTest.php`
- Modify in Premium: `pageforge.php`
- Modify in Premium: `includes/class-pageforge-plugin.php`

**Interfaces:**
- Consumes: `Compatibility::meta_keys()`, `Compatibility::read_meta()`, and `Upgrade::diagnose()`.
- Produces: `PageForge\Migration::scan(): array`, `preview_repairs(array $post_ids): array`, and `apply_repairs(array $repair_ids): array`; AJAX actions `pageforge_migration_scan`, `pageforge_migration_preview`, and `pageforge_migration_apply` for the later Pro Settings UI.

- [ ] **Step 1: Write failing migration tests**

Test that preview reports a missing canonical alias without mutating state, apply creates only the missing alias, a second apply changes nothing, and a conflicting non-empty alias is reported but never overwritten.

Use this exact expected repair record:

```php
[
    'repair_id' => hash('sha256', '71|vars|_pageforge_vars'),
    'post_id' => 71,
    'logical' => 'vars',
    'source_key' => '_pf_vars',
    'target_key' => '_pageforge_vars',
    'status' => 'ready',
]
```

- [ ] **Step 2: Run Premium tests to verify RED**

Run `php tests\run.php`.

Expected: failure because `PageForge\Migration` is missing.

- [ ] **Step 3: Implement bounded diagnostic and repair methods**

`scan()` returns namespace counts, mixed status, conflicts, and a maximum of 200 candidate post IDs per request. `preview_repairs()` reads aliases and returns deterministic repair IDs. `apply_repairs()` recomputes each repair from current state, refuses stale or conflicting repairs, writes only missing compatibility aliases, and returns `applied`, `skipped`, and `conflicts` arrays.

Handlers require `manage_options`, check `pageforge_admin` nonce, sanitize all IDs with `absint`, and return structured `code` and translated `message` fields.

- [ ] **Step 4: Verify idempotency and syntax**

```powershell
php tests\run.php
php -l includes\class-pageforge-migration.php
php -l includes\class-pageforge-plugin.php
```

Expected: all migration tests pass, including the second no-op repair.

- [ ] **Step 5: Commit Premium migration service**

```powershell
git add pageforge.php includes/class-pageforge-migration.php includes/class-pageforge-plugin.php tests/MigrationTest.php
git commit -m "feat: add non-destructive pro migration diagnostics"
```

## Plan 1 Verification Gate

Run in Free:

```powershell
php tests\run.php
Get-ChildItem -Recurse -Filter *.php | Where-Object { $_.FullName -notmatch '\\vendor\\' } | ForEach-Object { php -l $_.FullName }
node --check assets\admin.js
git diff --check
```

Run in Premium:

```powershell
php tests\run.php
Get-ChildItem -Recurse -Filter *.php | Where-Object { $_.FullName -notmatch '\\vendor\\' } | ForEach-Object { php -l $_.FullName }
node --check assets\admin.js
git diff --check
```

Expected: all test functions pass, all syntax checks exit `0`, and both diffs are whitespace-clean.
