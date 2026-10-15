# PageForge targeted output, permalink, and hierarchy fix

Date: 2026-10-15. Source baselines: local Free 1.2.5 and Premium 1.1.5; output versions Free 1.2.6 and Premium 1.1.6. This report covers the attached targeted support request, not certification of every historical production-upgrade feature.

## Findings and resulting behavior

1. **The CPT slug-base setting already existed.** It changes a nonempty custom-content prefix; it never selected native Page output or promised a prefix-free CPT. Both settings screens now explain that difference and the effect on established links. Free rejects an empty base; Premium explicitly restores its documented namespace default. Saving an actually changed base re-registers types and flushes rewrites. An unchanged save does not flush. Existing namespace routing is retained.
2. **Native Pages and Posts were already Free.** A saved template target overrides the global default. Both template editors now offer explicit inheritance, delete every compatible override alias on inheritance, preserve previously explicit selections, and identify the effective destination before generation. Invalid targets cannot silently generate an unintended type.
3. **Generation-time parent assignment was missing in both editions.** Both now support one existing native parent per template. A parent is separate from the layout-cloning source. Missing, malformed, inaccessible, non-Page, trashed, cyclic, and excessively deep hierarchies fail before insertion. Posts/CPTs ignore parent configuration. Preview, validation, and insertion use the same destination resolver.
4. **Premium per-row mapping is new and licensed.** Parent ID/Parent Path aliases are case-insensitive and trimmed; optional named-column mapping is supported. Missing/invalid values fail with row-level errors. Blank values require an explicitly configured static fallback. Starter and above can use mapping; Google Sheets remains Professional/Enterprise. Entitlement is rechecked during execution, including direct calls and queued work. Unlicensed Premium cannot perform direct static generation either.
5. **Duplicates use parent context.** The same leaf slug can exist under different parents. Identical final paths are rejected. Native Page/Post collisions compare actual URLs rather than simply equal leaf names. Premium reruns reuse matching items, reject changed destinations, and do not silently move or rename published content.
6. **Scheduled work retains a valid acting user.** Newly queued rows store the initiating user; execution checks that user's current permissions. Edited rows require ownership or administrator authority, validate the new destination, and bind execution to the editor. Foreign queue rows cannot borrow a privileged original actor through batch execution.
7. **Automatic canonical URLs follow permalink changes.** New generated items track their automatic canonical separately. Native output follows the current WordPress canonical when the stored value still matches that automatic value. Distinct manual overrides remain intact. External SEO adapters receive a blank custom-canonical override, allowing their normal permalink-derived canonical. Older unmarked canonical values are preserved conservatively; review/reset historical overrides when deliberately changing an established base.

No automatic conversion, reparenting, root-CPT rewrite takeover, redirect creation, or destructive migration was added. Base/layout cloning, placeholders, existing namespace aliases, licensing SDK/product IDs, and the single-runtime guard remain in place. Vendor source was not edited.

## Feature split

| Capability | Free | Licensed Premium Starter+ |
| --- | --- | --- |
| CSV generation | Up to 100 rows per run, enforced by generator | Existing licensed limits/policy |
| Native Pages and Posts | Yes | Yes |
| Separate PageForge CPT and nonempty base | Yes | Yes |
| Explicit template/global inheritance | Yes | Yes |
| One existing static parent per template | Yes | Yes |
| Per-row Parent ID / Parent Path | Rejected at execution | Yes |
| Google Sheets source | Not implemented in Free | Professional/Enterprise only |

Parents must exist before the run. Forward references, automatic tree creation, and automatic migration of existing CPT content are not advertised or implemented.

## Targeted changed files

Free runtime: `pageforge.php`, `assets/admin.js`, `includes/class-pageforge-admin.php`, `includes/class-pageforge-plugin.php`, `includes/class-pageforge-generator.php`, `includes/class-pageforge-compatibility.php`, `includes/class-pageforge-mcp-registry.php`, `includes/class-pageforge-seo.php`, `includes/class-pageforge-frontend.php`.

Free supporting files: `readme.txt`, `README.md`, `changelog.txt`, `docs/PAGEFORGE_FREE_AUDIT.md`, `docs/PAGEFORGE_PRO_ALIGNMENT.md`, `docs/PAGEFORGE_UI_UX_PRD.md`, `docs/PAGEFORGE_IMPLEMENTATION_ROADMAP.md`, this report, `docs/SUPPORT_REPLY.md`, `tests/bootstrap.php`, `tests/HierarchyGenerationTest.php`, and `tests/integration/*`.

Premium runtime: `pageforge.php`, `assets/admin.js`, `includes/class-pageforge-access.php`, `includes/class-pageforge-admin.php`, `includes/class-pageforge-plugin.php`, `includes/class-pageforge-template.php`, `includes/class-pageforge-generator.php`, `includes/class-pageforge-cron.php`, `includes/class-pageforge-compatibility.php`, `includes/class-pageforge-mcp-registry.php`, `includes/class-pageforge-mcp-registry-pro.php`, `includes/class-pageforge-seo.php`, `includes/class-pageforge-frontend.php`, `includes/helpers.php`.

Premium supporting files: `readme.txt`, `tests/bootstrap.php`, `tests/TestCase.php`, `tests/hierarchy-regression.php`. Both editions refresh `languages/pageforge.pot` and the `pageforge-{de_DE,en_GB,en_US,fr_FR,pt_PT}.po/.mo` catalogs together. Existing translations are preserved; new untranslated strings fall back to English.

The Free checkout already contained substantial uncommitted work; it was preserved. Free targeted branch: `codex/pageforge-native-output-hierarchy`. Premium changes are separate on `codex/support-hierarchy-fixes` (implementation commits `2baf922`, `377f7d0`, `2a69d66`; release metadata/catalog commit `1e58184`). The current local trees, rather than older public GitHub branches, were used for packaging.

## Verification and reproducible commands

Dependency-free source tests:

```powershell
php tests/run.php
php ../pageforge-premium/tests/hierarchy-regression.php
node --check assets/admin.js
node --check ../pageforge-premium/assets/admin.js
php tools/verify-mo.php languages/pageforge-de_DE.mo
php tools/verify-mo.php ../pageforge-premium/languages/pageforge-fr_FR.mo
```

Free: **226 tests passed**. Premium: **41 tests passed**. Both modified JavaScript files pass syntax checks. Compiled catalogs round-trip successfully. These suites use WordPress stubs and are not represented as live integration tests.

True WordPress checks use a disposable local WordPress **7.1.3**, PHP **8.3.29**, SQLite **3.40.0**, and official SQLite Database Integration **1.8.0**. See [integration setup and commands](../tests/integration/README.md). Scripts use real post insertion, metadata, role capabilities, permalink/rewrite APIs, HTTP requests, and WordPress plugin activation. The licensed-success tests replace only the Freemius accessor with a controlled fixture; a separate inactive test loads the real bundled SDK and confirms no direct insert occurs.

Build commands:

```powershell
php tools/build-release.php . --slug=pageforge --out=dist/targeted-1.2.6
php tools/build-release.php ../pageforge-premium --slug=pageforge-premium --out=dist/targeted-1.1.6
```

The builder extracts each actual ZIP and runs the release gate against that extracted artifact, including **every packaged PHP file**, encoding, asset existence, secret/debug scans, package policy, and version consistency. Development files, tests, internal docs, scratch files, `.git`, and previous ZIPs are excluded. The repository-wide Free static scan also detected pre-existing encoding problems in local scratch files `dump.txt`/`t.txt`; these are excluded from the ZIP and were not modified. Repository-wide whitespace checking reports a pre-existing trailing space in `AGENTS.md`; targeted runtime changes pass whitespace checks.

## Manual admin reproduction

1. In a disposable site, select Settings > Default Post Type > Page. Create a new template, leave Use global default selected, and set title/slug placeholders from the CSV. Confirm Generate identifies native Pages before running.
2. Generate one row with Parent Page None. Find it under Pages > All Pages and confirm its pretty permalink has no PageForge prefix.
3. Create the native Page `maths-tuition-gurgaon`, select it as template Parent Page, and generate `dlf-phase-iv`. Confirm the parent relationship and `/maths-tuition-gurgaon/dlf-phase-iv/` URL. Select a separate layout source and confirm it affects content, not the parent.
4. Choose an explicit PageForge CPT target. Change the global default; confirm the explicit target remains. Change Generated CPT Slug Base to `locations`, save, and confirm the generated CPT URL routes correctly. Save unchanged settings and confirm no rewrite flush. Plan redirects separately for old public links.
5. With licensed Premium, select dynamic Parent source and map Parent ID or Parent Path. Preview invalid/nonexistent parents before generation; verify valid rows attach under their existing parents. Revoke entitlement and confirm direct/queued writes fail.
6. As a lower-privileged user, attempt to modify another user's queued row. Confirm rejection and unchanged row/actor; authorized edits must use the editing user's authority.

## Built artifacts

| Artifact | Size | SHA-256 |
| --- | --- | --- |
| `pageforge/dist/targeted-1.2.6/pageforge-1.2.6.zip` | 437,017 bytes | `c97721cf081cac84fe9e85ee03716955734fb661a5b09e42f1aa64d96484c38d` |
| `pageforge-premium/dist/targeted-1.1.6/pageforge-premium-1.1.6.zip` | 1,489,876 bytes | `1aabad49273bbb1a80c918913f43342085726bc9001a8550a7245c85e9189b3d` |

Both ZIPs pass integrity checks on every entry and production-file policy checks. Extracted package gates pass with zero failures/warnings: Free **27 PHP files**, Premium **179 PHP files** (including untouched required vendor code). Version headers, constants, readme stable tags, and asset version constants agree. Hash sidecars are stored beside the ZIPs.

The final Free ZIP was installed and activated in the disposable site: **27 named integration assertions passed**, covering generation, settings, HTTP canonical changes, and public routes. Sanitized output is under [verification/targeted-2026-10-15](verification/targeted-2026-10-15/).

The final Premium ZIP was also installed and activated: **29 named integration assertions passed** (13 common generation/license, 3 dynamic mapping/revocation, 1 real-SDK inactive write refusal, 3 queue-edit permissions, 2 canonical HTTP, 2 public route status/canonical, 5 real scheduled-worker checks). `Cron::process()` inserted static/dynamic children under the expected parents, did not duplicate work on a second run, restored the caller's actor, and refused queued writes after entitlement revocation. The licensed-success assertions use the disclosed SDK fixture; the inactive direct-write assertion uses the real bundled SDK. Both route types return HTTP 200 with the correct rendered canonical in each edition.

Final artifact total: **56/56 named WordPress assertions passed**, plus both WordPress plugin activations. See [the integration results](verification/targeted-2026-10-15/INTEGRATION_RESULTS.md). The temporary server was stopped and both test artifacts deactivated after verification.

## Verification limits

No claim is made that the customer's installation was reproduced. A real paid Freemius activation/account, real Google Sheets credentials, third-party SEO/plugin/builder frontends, minimum WordPress/PHP versions, browser accessibility/mobile layouts, and a full MariaDB/MySQL matrix were not available for this targeted check. The production site's content/settings were not accessed or changed. These checks do not certify every historical integration. Premium remains a local release candidate and has not been publicly published; Free publication is recorded below.

With WordPress debug output enabled, the full Premium SDK bootstrap emits an early `pageforge` textdomain notice in the disposable harness; it is preserved in the logs. Activation, routing, and assertions still pass. This was not hidden or treated as a clean runtime-log certification, and bundled vendor code was left unchanged.

## WordPress.org SVN publication — 2026-10-15

Published Free **1.2.6** at SVN revision **3748204**, atomically updating `trunk` and adding `tags/1.2.6`. The SVN working copy is clean afterward. Both trees were checked against all 48 files in the tested ZIP before commit. WordPress.org's plugin-information API reports version 1.2.6, and the [public download](https://downloads.wordpress.org/plugin/pageforge.1.2.6.zip) was downloaded and compared: all 48 files match the tested release byte for byte, with no missing or extra files. [SVN release tag](https://plugins.svn.wordpress.org/pageforge/tags/1.2.6/).

Pre-publication checks repeated: 226 Free tests passed; the extracted package release gate passed with zero failures/warnings. Premium was not uploaded to WordPress.org.

## Sources checked

[PageForge public site](https://pageforge.pro/), [public sitemap](https://pageforge.pro/sitemap.xml), [WordPress.org listing](https://wordpress.org/plugins/pageforge/), and WordPress primary documentation for [wp_insert_post](https://developer.wordpress.org/reference/functions/wp_insert_post/), [get_page_by_path](https://developer.wordpress.org/reference/functions/get_page_by_path/), and [flush_rewrite_rules](https://developer.wordpress.org/reference/functions/flush_rewrite_rules/). The sitemap endpoint redirected to its live index and was checked by HTTP after the browsing tool could not open it. Product-specific availability above follows the actual local code, not broad marketing wording.

Customer-facing draft: [SUPPORT_REPLY.md](SUPPORT_REPLY.md).
