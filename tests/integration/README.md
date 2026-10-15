# Disposable WordPress integration checks

These scripts execute actual WordPress post insertion, metadata, capabilities, rewrite registration, WP_Query and permalink APIs. They are separate from the repository's stubbed unit suite. **They mutate the selected database. Use only a newly installed disposable site.** Do not select a production or staging site.

Both Premium test paths replace only the Freemius SDK accessor with a local entitlement stub. WordPress, SQLite and the product generator remain real. This verifies entitlement branching and immediate revocation, not a real Freemius account or activation service.

## Windows setup

Recorded verification runtime: WordPress 7.1.3, PHP 8.3.29, SQLite 3.40.0, official SQLite Database Integration 1.8.0. PHP with PDO SQLite and SQLite3 extension DLLs was used. Extensions are enabled per process, without changing global php.ini. Use a temporary directory outside either product repository:

```powershell
$testRoot = Join-Path $env:TEMP 'pageforge-wp-live'
New-Item -ItemType Directory -Force $testRoot | Out-Null
Invoke-WebRequest https://wordpress.org/latest.zip -OutFile "$testRoot/wordpress.zip"
Invoke-WebRequest https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip -OutFile "$testRoot/sqlite.zip"
Expand-Archive "$testRoot/wordpress.zip" $testRoot
Expand-Archive "$testRoot/sqlite.zip" "$testRoot/wordpress/wp-content/plugins"
Copy-Item "$testRoot/wordpress/wp-content/plugins/sqlite-database-integration/db.copy" "$testRoot/wordpress/wp-content/db.php"
```

Save this as the disposable site's `wp-config.php`:

```php
<?php
 define('DB_NAME','pageforge_test'); define('DB_USER','root');
 define('DB_PASSWORD',''); define('DB_HOST','localhost');
 define('DB_CHARSET','utf8mb4'); define('DB_COLLATE','');
 define('DB_ENGINE','sqlite');
 define('WP_HOME','http://localhost:8897');
 define('WP_SITEURL','http://localhost:8897');
 $table_prefix='wp_';
 if (!defined('ABSPATH')) define('ABSPATH',__DIR__.'/');
 require_once ABSPATH.'wp-settings.php';
```

Save and run this as `pf-install.php` in the disposable WordPress directory (the email/password are throwaway fixtures):

```php
<?php
 define('WP_INSTALLING',true);
 require __DIR__.'/wp-load.php';
 require_once ABSPATH.'wp-admin/includes/upgrade.php';
 wp_install('PageForge isolated verification','pf_admin','pf-test@example.invalid',true,'','temporary-integration-only');
```

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 "$testRoot/wordpress/pf-install.php"
$env:PF_TEST_WP_ROOT = "$testRoot/wordpress"
$env:PF_TEST_PLUGIN_ROOT = 'C:/path/to/pageforge'
php -d extension=pdo_sqlite -d extension=sqlite3 tests/integration/targeted-generation.php free
php -d extension=pdo_sqlite -d extension=sqlite3 tests/integration/free-settings.php
$env:PF_TEST_PLUGIN_ROOT = 'C:/path/to/pageforge-premium'
php -d extension=pdo_sqlite -d extension=sqlite3 tests/integration/targeted-generation.php premium
php -d extension=pdo_sqlite -d extension=sqlite3 tests/integration/premium-dynamic.php
php -d extension=pdo_sqlite -d extension=sqlite3 tests/integration/premium-inactive.php
```

For release artifact verification, extract the built ZIP into a fresh temporary directory and set `PF_TEST_PLUGIN_ROOT` to the extracted plugin root. Each script emits named JSON assertions and exits nonzero on failure. Wait at least a second between repeated executions because fixture slugs include the current timestamp.

Expected assertions: common generation 12 Free / 13 Premium, Free settings 11, Premium licensed dynamic mapping/revocation 3, real SDK inactive direct-write refusal 1. The generation fixtures do not request AI content, images, or remote connectors. Premium successful generation tests declare the local SDK stub before boot; they do not activate or contact a real Freemius account.

## HTTP route check

To check public request routing as well as WordPress API permalinks, put a temporary MU plugin in this disposable WordPress site's `wp-content/mu-plugins/` that requires the chosen edition's `pageforge.php`. Use one edition at a time. Flush rewrite rules after changing edition/base, then serve WordPress with PHP's built-in server and a router that requires `index.php` for nonexistent paths. GET one generated hierarchical Page path and the generated CPT custom-base path; both must return HTTP 200. This was additionally exercised for both editions during the targeted release check. The CLI scripts do not themselves claim to exercise HTTP routing or browser admin interaction.



To verify WordPress activation, extract a built ZIP into the disposable WordPress `wp-content/plugins/` directory. In a new CLI process run:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 tests/integration/activate-artifact.php --deactivate
php -d extension=pdo_sqlite -d extension=sqlite3 tests/integration/activate-artifact.php pageforge/pageforge.php
```

The first command deactivates every active plugin in the selected disposable database. The second command activates the supplied relative plugin path in a fresh process. Use only the test site. Set `PF_TEST_PLUGIN_ROOT` to that extracted directory for subsequent suites. Switching editions needs a fresh PHP process.



The inactive test uses the full bundled SDK without a stub and requires an unlicensed disposable installation. It verifies direct generator write refusal without inserting a Page.


For reproducible HTTP checks after activating an artifact, save this as disposable-site `router.php`:

```php
<?php
 if (is_file(__DIR__.parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH))) return false;
 require __DIR__.'/index.php';
```

Run the following server in a separate terminal, then execute `tests/integration/check-http.ps1` with the same test environment variables. The site must be configured for `http://localhost:8897` as in the setup above. These checks compare actual rendered canonical URLs with the real request URL after settings changes, as well as HTTP status.

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 -S 127.0.0.1:8897 -t "$env:PF_TEST_WP_ROOT" "$env:PF_TEST_WP_ROOT/router.php"
./tests/integration/check-http.ps1
```

After serving the activated artifact, run `canonical-http.php free` (or `premium`). This creates a marked generated CPT fixture, changes the base, verifies the current canonical through an actual HTTP request, verifies a distinct manual override, then restores the fixture canonical. Premium uses the local SDK entitlement stub for generation. The server loads the actual activated edition without the stub.

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 tests/integration/canonical-http.php free
php -d extension=pdo_sqlite -d extension=sqlite3 tests/integration/premium-queue.php
```

The queue test uses a licensed local SDK stub, checks subscriber/contributor denial of an admin-owned row, and checks an authorized update binds its execution actor to the current editor (3 assertions). It restores the queue option after testing.

`premium-cron.php` exercises the actual `Cron::process()` method with real persisted static/dynamic queue items and an unauthenticated caller, verifies both hierarchy insertions and actor restoration, runs the processor again to verify no duplicate insertion, then revokes the local license fixture and verifies a queued row inserts nothing (5 assertions). It restores settings/queue/daily state and unschedules its disposable scheduler hooks afterward. It does not require the HTTP server.
