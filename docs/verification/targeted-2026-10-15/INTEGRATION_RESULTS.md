# Targeted artifact integration verification — 2026-10-15

Both exact release ZIPs were extracted under a disposable WordPress installation, activated through WordPress, then tested. This uses real WordPress APIs and a real SQLite database, separately from the repository unit-test harness.

Runtime: WordPress 7.1.3; PHP 8.3.29; SQLite 3.40.0; official SQLite Database Integration 1.8.0. HTTP: PHP built-in server at localhost port 8897.

| Artifact | SHA-256 | Activation | Named assertions |
| --- | --- | --- | --- |
| Free 1.2.6 | c97721cf081cac84fe9e85ee03716955734fb661a5b09e42f1aa64d96484c38d | PASS | 27/27 |
| Premium 1.1.6 | 1aabad49273bbb1a80c918913f43342085726bc9001a8550a7245c85e9189b3d | PASS | 29/29 |

Free: 12 generation assertions; 11 settings, destination and hierarchy assertions; 2 canonical HTTP assertions; 2 HTTP route/canonical assertions. Premium: 13 generation assertions; 3 dynamic mapping/revocation assertions; 1 real SDK inactive direct-write refusal; 3 actual AJAX queue ownership/actor assertions; 5 actual Cron process assertions; 2 canonical HTTP assertions; 2 HTTP route/canonical assertions. Total 56/56 named assertions, plus both activations passed. Corresponding raw logs are in this directory.

Verified native Page/Post insertion and normal WP_Query visibility, real parent hierarchy and public permalinks, preview/insertion agreement, duplicate behavior, separate-parent identical leaf slugs, custom CPT bases, mixed historical namespaces, invalid/deleted/non-Page parents, base-layout independence, inherited global target versus saved template override, clearing target aliases, actual settings AJAX save with exactly one rewrite generation on change and none on unchanged save, root Post/Page URL collision handling, canonical refresh after base change with distinct manual overrides preserved, license denial/revocation, protected queue ownership, persisted actor restoration in the actual Cron processor, static/dynamic queued hierarchy, second-run idempotence, and license revocation before queued insertion.

Successful Premium generator/dynamic/queue tests replace only the Freemius accessor with a local active-license fixture. WordPress, database, generator and AJAX handlers remain real. This does not verify a real paid account or the Freemius licensing service. The separate inactive test bootstraps the actual bundled SDK and verifies no Page insertion is permitted without a license. The HTTP server loads the actual activated artifact without the SDK fixture.

A non-fatal WP_DEBUG notice about early `pageforge` textdomain loading appears during the actual Premium SDK bootstrap; it is preserved in the inactive/HTTP logs. It did not prevent activation, routing or canonical output. The HTTP route harness reads the final structured JSON line so the diagnostic cannot corrupt route discovery.

The previous automatic-canonical regression discovered during the first artifact pass was fixed and retested against these final hashes. The records here contain only the final passing runs. No production or hosted site was used. Test plugins were deactivated and the temporary PHP server was stopped afterward. Reproducible scripts and setup are in `tests/integration/README.md`.

