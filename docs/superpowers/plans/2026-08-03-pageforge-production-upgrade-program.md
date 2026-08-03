# PageForge Production Upgrade Program

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this program plan-by-plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver PageForge Free 1.2.0 and PageForge Pro 1.1.0 as compatible, production-tested programmatic SEO plugins with refined admin workflows, remote MCP support, controlled Pro operations, and accurate release artifacts.

**Architecture:** The program first establishes cross-edition compatibility and a dependency-free test harness. It then builds the shared admin experience, MCP core, Pro operator capabilities, and release hardening in that order. Free and Pro remain separate repositories and use separate branches, commits, and release packages.

**Tech Stack:** PHP 7.4-compatible WordPress plugin code, WordPress REST API and AJAX, JSON-RPC 2.0, MCP 2026-07-28 with 2025-06-18 compatibility, jQuery admin JavaScript, CSS, gettext PO/POT/MO catalogs, Freemius entitlements in Pro.

## Global Constraints

- Free target version is exactly `1.2.0`; Pro target version is exactly `1.1.0`.
- WordPress minimum remains `5.8`; PHP minimum remains `7.4`.
- Existing `pageforge_*` and `pf_*` content must remain readable without destructive post-type or post-ID conversion.
- MCP remains disabled after fresh install and upgrade until an administrator enables it.
- Free MCP exposes read-only and dry-run tools only.
- Sensitive Pro writes require WordPress capability, Pro entitlement, MCP scope, a matching unexpired confirmation, and an audit record.
- Remote credentials are accepted in authorization headers, never URL query parameters.
- All admin actions require the narrowest existing WordPress capability and a nonce.
- All UI strings are translatable with the `pageforge` text domain.
- All dynamic output is escaped at output; all request input is unslashed, sanitized, and validated.
- No production dependency is downloaded at runtime.
- Do not modify Premium's bundled `vendor/` directory.
- No PageForge setting, content object, audit table, or queue is deleted on deactivation.
- The Free review request is honest, contextual, dismissible, limited to two displays, and contains no tracking parameters.

---

## Ordered Plans

- [ ] [Plan 1: Compatibility and Test Foundation](2026-08-03-pageforge-foundation-compatibility.md)
- [ ] [Plan 2: Shared Admin UX, Connections, Preflight, and Review Request](2026-08-03-pageforge-admin-ux-connections.md)
- [ ] [Plan 3: MCP Core, Authentication, and Free Tools](2026-08-03-pageforge-mcp-core.md)
- [ ] [Plan 4: Pro Operator, SEO, Analytics, Builders, and Admin Agent](2026-08-03-pageforge-pro-operator.md)
- [ ] [Plan 5: Internationalization, Verification, Versions, and Release Artifacts](2026-08-03-pageforge-release-hardening.md)

## Repository Boundaries

Free repository:

`C:\Users\wpshu\OneDrive\Documents\pageforge`

Premium repository:

`C:\Users\wpshu\OneDrive\Documents\pageforge-premium`

Use branch `codex/pageforge-1.2.0-production-upgrade` for Free and branch `codex/pageforge-pro-1.1.0-production-upgrade` for Pro. Use isolated worktrees created through the worktree workflow at execution time. Never combine Free and Pro changes in one Git commit.

## Program Gates

After each plan:

1. Run every test command named in that plan in both repositories.
2. Run `php -l` for every changed PHP file and `node --check` for every changed JavaScript file.
3. Run `git diff --check` in both repositories.
4. Dispatch a read-only code review against the plan requirements.
5. Fix every Critical and Important finding before starting the next plan.
6. Record the plan completion in the subagent-development progress ledger.

The final release gate is defined in Plan 5 and supersedes intermediate smoke checks.

## Specification Coverage

| Approved design area | Implemented by |
| --- | --- |
| Cross-edition identifiers, namespace selection, migration, rollback compatibility | Plan 1 Tasks 1-5; Plan 5 Tasks 3 and 5 |
| Free and Pro capability split | Plan 2 Connections and UI; Plan 3 registry filtering; Plan 4 operator catalog |
| Admin information architecture and visual system | Plan 2 Tasks 2, 4, and 5; Plan 4 Task 7 |
| First-win honest Free review request | Plan 2 Task 3; Plan 5 Tasks 1, 2, and 5 |
| Current and legacy MCP protocol support | Plan 3 Tasks 1 and 4 |
| Free read-only and dry-run tools | Plan 3 Tasks 3 and 4 |
| Pro write tools, previews, confirmations, scopes, and audit | Plan 3 Tasks 5-7; Plan 4 Tasks 1-4 |
| ChatGPT, Claude, Cursor, Perplexity, and generic client setup | Plan 3 Tasks 4 and 7; Plan 5 Task 5 |
| Admin AI operator | Plan 4 Task 6 |
| Analytics read connections | Plan 4 Task 5 |
| Builder adapters | Plan 4 Task 4 |
| SEO workspace and scoring | Plan 4 Tasks 3 and 7 |
| Error recovery, bounded operations, revisions, and idempotency | Plans 2-4; Plan 5 Task 5 |
| Internationalization and accessibility | Plan 2 Task 5; Plan 5 Task 1 |
| Testing, public claims, versions, packages, and release handoff | Plan 5 Tasks 1-6 |

## Current Toolchain Note

The audited workstation has PHP 8.3 and Node.js 22 available. Composer and WP-CLI are not currently on `PATH`, so Plans 1-4 use the dependency-free PHP runner. Plan 5 provisions isolated release-only WordPress tooling and does not add Composer or WP-CLI as a production dependency.
