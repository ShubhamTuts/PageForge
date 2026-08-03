# PageForge MCP Core And Authentication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a disabled-by-default remote MCP server in Free and Pro, with protocol compatibility, safe Free tools, credential management, Pro OAuth, scopes, confirmations, and audit infrastructure.

**Architecture:** A transport-neutral protocol class validates JSON-RPC and negotiates MCP revisions. A shared tool registry filters tools before discovery and execution. WordPress REST adapters provide Streamable HTTP, authentication services resolve a principal and scopes, and Pro adds OAuth, confirmations, and durable redacted audit records.

**Tech Stack:** PHP 7.4, WordPress REST API, JSON-RPC 2.0, MCP 2026-07-28 and 2025-06-18, bearer API keys, WordPress Application Passwords, OAuth 2.1 Authorization Code with PKCE S256, WordPress database APIs.

## Global Constraints

- Endpoint is `/wp-json/pageforge/v1/mcp` in both editions.
- Current MCP revision `2026-07-28` is stateless and carries protocol metadata per request.
- Legacy revision `2025-06-18` uses initialize compatibility without weakening current-request validation.
- MCP is disabled by default and returns `404` while disabled.
- HTTPS is required except when both `WP_DEBUG` and `PAGEFORGE_ALLOW_INSECURE_LOCAL_MCP` are true.
- Free credentials are high-entropy bearer API keys stored only as password hashes; WordPress Application Passwords are a fallback.
- Pro OAuth access tokens are hashed at rest, resource-bound, scoped, expiring, and revocable.
- Credentials and tokens never appear in query strings, logs, notices, tool results, or downloadable diagnostics.
- Free registry contains no write handler, even if a client invents a write tool name.
- All responses include `Cache-Control: no-store, no-cache, must-revalidate, private`.

---

### Task 1: Free JSON-RPC And MCP Protocol Runtime

**Files:**
- Create in Free: `includes/class-pageforge-mcp-protocol.php`
- Create in Free: `tests/McpProtocolTest.php`
- Modify in Free: `pageforge.php`
- Modify in Free: `tests/bootstrap.php`

**Interfaces:**
- Consumes: decoded JSON request arrays.
- Produces: `PageForge\MCP_Protocol::handle(array $request, callable $method_handler): array`, `success($id, $result): array`, `error($id, int $code, string $message, array $data = []): array`, and `negotiate_version(array $request): string`.

- [ ] **Step 1: Write failing protocol tests**

Cover these exact cases:

```php
function test_pageforge_mcp_rejects_non_jsonrpc_request(): void {
    $result = \PageForge\MCP_Protocol::handle(['id' => 1, 'method' => 'ping'], function () { return []; });
    \PageForge\Tests\TestCase::same(-32600, $result['error']['code']);
}

function test_pageforge_mcp_rejects_unsupported_method(): void {
    $result = \PageForge\MCP_Protocol::handle([
        'jsonrpc' => '2.0', 'id' => 'abc', 'method' => 'unknown/method',
        '_meta' => ['io.modelcontextprotocol/protocol-version' => '2026-07-28'],
    ], function () { return null; });
    \PageForge\Tests\TestCase::same(-32601, $result['error']['code']);
}

function test_pageforge_mcp_current_request_negotiates_current_version(): void {
    $request = [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping',
        '_meta' => ['io.modelcontextprotocol/protocol-version' => '2026-07-28'],
    ];
    \PageForge\Tests\TestCase::same('2026-07-28', \PageForge\MCP_Protocol::negotiate_version($request));
}

function test_pageforge_mcp_legacy_initialize_negotiates_legacy_version(): void {
    $request = [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
    ];
    \PageForge\Tests\TestCase::same('2025-06-18', \PageForge\MCP_Protocol::negotiate_version($request));
}
```

Also test missing IDs on requests, valid notifications without IDs, invalid params, handler exceptions mapped to `-32603`, and preservation of integer, string, and null IDs.

- [ ] **Step 2: Run tests to verify RED**

Run `php tests\run.php`.

Expected: protocol class missing.

- [ ] **Step 3: Implement strict protocol validation**

Allow only `ping`, `tools/list`, `tools/call`, legacy `initialize`, legacy `notifications/initialized`, and cancellation notifications. Return standard JSON-RPC errors:

- `-32700` parse error at the REST adapter
- `-32600` invalid request
- `-32601` method not found
- `-32602` invalid params
- `-32603` internal error

For current requests, read version only from `_meta['io.modelcontextprotocol/protocol-version']`. For legacy initialize, read `params.protocolVersion`. Reject all other revisions with an error data object containing `supported => ['2026-07-28', '2025-06-18']`.

- [ ] **Step 4: Verify protocol GREEN**

```powershell
php tests\run.php
php -l includes\class-pageforge-mcp-protocol.php
```

- [ ] **Step 5: Commit Free protocol runtime**

```powershell
git add pageforge.php includes/class-pageforge-mcp-protocol.php tests
git commit -m "feat: add mcp protocol runtime"
```

### Task 2: Free MCP Authentication And Credential Lifecycle

**Files:**
- Create in Free: `includes/class-pageforge-mcp-auth.php`
- Create in Free: `tests/McpAuthTest.php`
- Modify in Free: `pageforge.php`
- Modify in Free: `includes/class-pageforge-plugin.php`
- Modify in Free: `tests/bootstrap.php`

**Interfaces:**
- Consumes: `WP_REST_Request`, PageForge settings, WordPress current user, and Application Password authentication state.
- Produces: `MCP_Auth::generate_api_key(): array`, `revoke_api_key(): void`, and `authenticate(WP_REST_Request $request)` returning an array or `WP_Error`; AJAX actions `pageforge_mcp_generate_key`, `pageforge_mcp_revoke_key`, `pageforge_mcp_toggle`.

- [ ] **Step 1: Write failing authentication tests**

Test:

- generated secret begins `pfmcp_` and contains at least 32 random bytes encoded safely
- returned plaintext is shown once and stored value contains only hash and 8-character prefix
- a matching `Authorization: Bearer` secret authenticates with `pageforge:read` and `pageforge:preview`
- the bearer principal is bound to the administrator who generated the key and fails if that user no longer has `manage_options`
- a wrong bearer secret returns code `invalid_token`
- missing authentication returns `mcp_auth_required`
- disabled MCP returns `mcp_disabled`
- non-HTTPS returns `mcp_https_required`
- query parameter `api_key` is ignored
- an authenticated WordPress Application Password user with `manage_options` receives Free scopes
- an Application Password user without `manage_options` receives `mcp_forbidden`

- [ ] **Step 2: Run tests to verify RED**

Run `php tests\run.php`.

Expected: authentication class missing.

- [ ] **Step 3: Implement key generation and hash verification**

Use `random_bytes(32)` and base64url encoding. Store:

```php
[
    'enabled' => false,
    'api_key_hash' => password_hash($secret, PASSWORD_DEFAULT),
    'api_key_prefix' => substr($secret, 0, 14),
    'key_user_id' => get_current_user_id(),
    'key_created_at' => time(),
    'last_success_at' => 0,
]
```

The plaintext secret exists only in the generate-key AJAX response. Compare with `password_verify()`. Never use a reversible option value.

- [ ] **Step 4: Implement authentication precedence**

1. Read MCP state from the non-autoloaded `pageforge_mcp` option, then reject disabled and non-HTTPS requests.
2. If `Authorization: Bearer` exists, validate only as PageForge key in Free and require the stored key owner to retain `manage_options`.
3. Otherwise accept a WordPress user already authenticated by Application Passwords and require `manage_options`.
4. Return principal schema:

```php
[
    'user_id' => 1,
    'client_id' => 'free-api-key:pfmcp_ab12',
    'auth_type' => 'api_key',
    'scopes' => ['pageforge:read', 'pageforge:preview'],
]
```

- [ ] **Step 5: Add credential AJAX handlers**

All handlers require `manage_options` and `pageforge_admin` nonce. Toggle accepts only `enabled => 0|1`; enabling without a key returns `mcp_key_required`. Revoke clears hash and prefix and disables MCP.

- [ ] **Step 6: Verify authentication GREEN**

Run tests and lint the new class and modified Plugin class.

- [ ] **Step 7: Commit Free authentication**

```powershell
git add pageforge.php includes/class-pageforge-mcp-auth.php includes/class-pageforge-plugin.php tests
git commit -m "feat: add mcp credential lifecycle"
```

### Task 3: Shared Tool Registry And Free Tool Catalog

**Files:**
- Create in Free: `includes/class-pageforge-tool-registry.php`
- Create in Free: `includes/class-pageforge-mcp-free-tools.php`
- Create in Free: `tests/ToolRegistryTest.php`
- Create in Free: `tests/FreeToolsTest.php`
- Modify in Free: `pageforge.php`

**Interfaces:**
- Consumes: `Compatibility`, `Preflight`, WordPress queries, and authenticated principal scopes.
- Produces: `Tool_Registry::register(array $definition, callable $handler): void`, `discover(array $principal, string $edition): array`, `call(string $name, array $arguments, array $principal, string $edition): array`, and `MCP_Free_Tools::register(Tool_Registry $registry): void`.

- [ ] **Step 1: Write failing registry security tests**

Register one read tool and one write tool in the fixture. Assert that Free discovery and call exclude the write tool even when the principal claims every scope. Assert invalid arguments return `invalid_tool_arguments`, unknown tools return `tool_not_found`, and missing scope returns `insufficient_scope`.

Every definition has this exact shape:

```php
[
    'name' => 'pageforge_get_site_info',
    'description' => 'Return WordPress and PageForge status without secrets.',
    'inputSchema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'outputSchema' => ['type' => 'object', 'properties' => ['site_url' => ['type' => 'string']], 'required' => ['site_url']],
    'edition' => 'free',
    'scope' => 'pageforge:read',
    'risk' => 'read_only',
    'write' => false,
]
```

- [ ] **Step 2: Run registry tests to verify RED**

Run `php tests\run.php`.

Expected: registry missing.

- [ ] **Step 3: Implement registry validation and filtering**

Reject duplicate names, definitions without `additionalProperties`, write definitions registered as edition `free`, and handlers that return non-array results. Discovery returns only MCP-facing fields plus annotations for read-only, destructive, idempotent, and open-world behavior.

- [ ] **Step 4: Write failing Free tool behavior tests**

Add fixtures and tests for these tools:

- `pageforge_get_site_info`
- `pageforge_list_templates`
- `pageforge_get_template`
- `pageforge_list_data_sources`
- `pageforge_list_generated_pages`
- `pageforge_get_seo_status`
- `pageforge_get_connection_status`
- `pageforge_validate_campaign`
- `pageforge_preview_generation`
- `pageforge_preview_meta_update`
- `pageforge_preview_schema`
- `pageforge_preview_llms_txt`

Test pagination limits of `1..100`, omitted content bodies by default, no option secrets, both namespaces, invalid IDs, and dry-run outputs with zero mutations.

- [ ] **Step 5: Implement the Free catalog**

Use bounded queries and stable output objects. Content tools accept `include_content => false` by default and truncate returned content to 20,000 UTF-8 characters when explicitly requested. Data-source tools return headers and counts, never entire CSV files by default. Connection status returns booleans, provider names, and timestamps only.

- [ ] **Step 6: Verify catalog GREEN**

```powershell
php tests\run.php
php -l includes\class-pageforge-tool-registry.php
php -l includes\class-pageforge-mcp-free-tools.php
```

- [ ] **Step 7: Commit registry and Free tools**

```powershell
git add pageforge.php includes/class-pageforge-tool-registry.php includes/class-pageforge-mcp-free-tools.php tests
git commit -m "feat: add safe free mcp tools"
```

### Task 4: Free Streamable HTTP REST Adapter And Connections Controls

**Files:**
- Create in Free: `includes/class-pageforge-mcp-server.php`
- Create in Free: `tests/McpServerTest.php`
- Modify in Free: `pageforge.php`
- Modify in Free: `includes/class-pageforge-plugin.php`
- Modify in Free: `includes/class-pageforge-admin.php`
- Modify in Free: `assets/admin.js`
- Modify in Free: `assets/admin.css`

**Interfaces:**
- Consumes: `MCP_Protocol`, `MCP_Auth`, `Tool_Registry`, and `MCP_Free_Tools`.
- Produces: REST route `/pageforge/v1/mcp`, current and legacy MCP responses, and working Free MCP controls in Connections.

- [ ] **Step 1: Write failing server contract tests**

Test that disabled returns 404, missing auth returns 401 with `WWW-Authenticate`, insufficient permissions return 403, malformed JSON returns `-32700`, `ping` succeeds, current `tools/list` returns Free tools, legacy initialize returns server name/version/capabilities, `tools/call` returns MCP content blocks, and every response contains no-store headers.

- [ ] **Step 2: Run tests to verify RED**

Run `php tests\run.php`.

- [ ] **Step 3: Implement the REST adapter**

Register:

```php
register_rest_route('pageforge/v1', '/mcp', [
    'methods' => ['POST'],
    'callback' => [self::class, 'handle'],
    'permission_callback' => '__return_true',
]);
```

The callback performs its own authentication so it can return protocol-specific `WWW-Authenticate` headers. Decode `get_body()` with `json_decode(..., true)`, reject JSON errors, dispatch through `MCP_Protocol`, and wrap tool results as MCP text plus structured content. Never send PHP output before the JSON response.

- [ ] **Step 4: Make Connections controls functional**

Add enable toggle, Generate key, Revoke key, Copy endpoint, Test MCP, credential prefix, last successful connection, and Free tool count. Require confirmation before revoke. Mark the key plaintext as one-time and remove it from the DOM when the user leaves or closes the reveal panel.

- [ ] **Step 5: Verify Free endpoint**

Run tests, lint, and JavaScript syntax checks. Against local HTTPS WordPress, verify 404 disabled, 401 enabled without key, successful current `tools/list`, successful legacy initialize, and rejected invented write tool.

- [ ] **Step 6: Commit Free MCP server**

```powershell
git add pageforge.php includes/class-pageforge-mcp-server.php includes/class-pageforge-plugin.php includes/class-pageforge-admin.php assets/admin.js assets/admin.css tests/McpServerTest.php
git commit -m "feat: expose free mcp server"
```

### Task 5: Port MCP Core To Pro And Add Audit Storage

**Files:**
- Create in Premium: `includes/class-pageforge-mcp-protocol.php`
- Create in Premium: `includes/class-pageforge-mcp-auth.php`
- Create in Premium: `includes/class-pageforge-tool-registry.php`
- Create in Premium: `includes/class-pageforge-mcp-free-tools.php`
- Create in Premium: `includes/class-pageforge-mcp-server.php`
- Create in Premium: `includes/class-pageforge-mcp-audit-log.php`
- Create in Premium: `tests/McpProtocolTest.php`
- Create in Premium: `tests/McpAuthTest.php`
- Create in Premium: `tests/ToolRegistryTest.php`
- Create in Premium: `tests/McpAuditLogTest.php`
- Modify in Premium: `pageforge.php`
- Modify in Premium: `includes/class-pageforge-plugin.php`

**Interfaces:**
- Consumes: Free MCP public contracts from Tasks 1-4 and Premium `$wpdb`.
- Produces: matching Pro endpoint plus `MCP_Audit_Log::install(): void`, `record(array $event): int`, `query(array $filters): array`, `purge(int $before_timestamp): int`, and `redact(array $arguments, array $paths): array`.

- [ ] **Step 1: Port the core tests and classes without behavior drift**

Copy protocol, Free authentication fallback, registry, Free tools, and server tests. Replace edition checks with Pro entitlement-aware discovery while keeping Free/read tools available to paid users.

- [ ] **Step 2: Write failing audit tests**

Test redaction of keys named `api_key`, `authorization`, `token`, `secret`, `password`, and schema-declared secret paths. Test one record with this normalized event:

```php
[
    'user_id' => 1,
    'client_id' => 'client-123',
    'auth_type' => 'oauth',
    'tool_name' => 'pageforge_update_meta',
    'scope' => 'pageforge:seo:write',
    'risk_level' => 'write',
    'dry_run' => false,
    'arguments' => ['post_id' => 91, 'api_key' => 'never-store-this'],
    'redact_paths' => ['api_key'],
    'affected_ids' => [91],
    'status' => 'success',
    'duration_ms' => 42,
    'error_code' => '',
]
```

Assert stored argument JSON contains `[REDACTED]` and not the secret.

- [ ] **Step 3: Create the audit table**

Use `dbDelta()` with table `{$wpdb->prefix}pageforge_mcp_audit` and columns for ID, UTC creation time, user ID, client ID, auth type, tool name, scope, risk level, dry-run flag, redacted argument summary, affected IDs, status, duration, error code, confirmation hash, and idempotency key. Index creation time, user ID, client ID, tool name, and status.

Set default retention to 30 days. `purge()` requires a concrete cutoff and deletes in batches of 500.

- [ ] **Step 4: Record every Pro tool call**

Wrap registry calls in the Pro server with monotonic timing and `try/finally`-equivalent control flow so success and failure both record. Protocol validation failures do not create content-bearing audit rows; authentication failures increment a bounded daily option counter without storing credentials.

- [ ] **Step 5: Verify and commit Premium MCP core**

Run Premium tests and lint. Commit with `feat: add pro mcp core and audit log`.

### Task 6: Pro Confirmation And Idempotency Service

**Files:**
- Create in Premium: `includes/class-pageforge-mcp-confirmation.php`
- Create in Premium: `tests/McpConfirmationTest.php`
- Modify in Premium: `pageforge.php`
- Modify in Premium: `includes/class-pageforge-tool-registry.php`

**Interfaces:**
- Consumes: principal, tool name, normalized arguments, affected object versions, and WordPress transients.
- Produces: `MCP_Confirmation::issue(array $principal, string $tool, array $arguments, array $object_versions, int $now): array`, `consume(string $confirmation_id, array $principal, string $tool, array $arguments, array $object_versions, int $now)` returning an array or `WP_Error`, and `idempotency_result(string $key, ?array $result = null): ?array`.

- [ ] **Step 1: Write failing confirmation tests**

Test a valid one-use confirmation, replay rejection, 601-second expiry, changed argument rejection, changed client rejection, changed user rejection, changed object version rejection, and idempotent return for a repeated execution key.

- [ ] **Step 2: Run tests to verify RED**

Run `php tests\run.php`.

- [ ] **Step 3: Implement bound one-use confirmations**

Canonicalize associative arrays by recursively sorting keys. Hash JSON with `wp_salt('auth')`. Store a random ID's hash, never its plaintext, in a transient for exactly 600 seconds. Delete the transient before returning success from `consume()` so concurrent replay loses.

Return preview metadata:

```php
[
    'confirmation_id' => 'pfcfm_<random>',
    'expires_at' => $now + 600,
    'tool' => $tool,
    'affected_ids' => array_keys($object_versions),
]
```

- [ ] **Step 4: Enforce registry write policy**

Definitions with `write => true` must declare `preview_tool`, `scope`, `risk`, and `idempotent`. Execute handlers receive a validated confirmation context; direct calls without it return `confirmation_required` before handler invocation.

- [ ] **Step 5: Verify and commit**

Run tests and lint. Commit with `feat: enforce preview-first mcp writes`.

### Task 7: Pro OAuth 2.1 Resource Server And Authorization Flow

**Files:**
- Create in Premium: `includes/class-pageforge-mcp-oauth.php`
- Create in Premium: `includes/class-pageforge-mcp-oauth-store.php`
- Create in Premium: `tests/McpOAuthTest.php`
- Modify in Premium: `pageforge.php`
- Modify in Premium: `includes/class-pageforge-plugin.php`
- Modify in Premium: `includes/class-pageforge-admin.php`
- Modify in Premium: `includes/class-pageforge-mcp-auth.php`

**Interfaces:**
- Consumes: WordPress login, current administrator, REST requests, HTTPS client metadata URLs, and token tables.
- Produces: protected-resource metadata, authorization-server metadata, client registration, authorization consent, token and refresh endpoints; `MCP_OAuth::authenticate_bearer(string $token, string $resource)` returning an array or `WP_Error`.

- [ ] **Step 1: Write failing OAuth security tests**

Test:

- metadata advertises the canonical MCP resource and exact endpoints
- authorization code requires PKCE method `S256`
- plain PKCE is rejected
- redirect URI must match exactly
- state is returned unchanged but never trusted as authorization
- code is one-use and expires after 300 seconds
- access token expires after 3600 seconds
- refresh token expires after 30 days and rotates on use
- revoked client and token fail
- token with wrong resource audience fails
- token with insufficient scope produces 403 and a scope challenge
- token query parameter is ignored
- client metadata URL rejects HTTP, loopback, private, link-local, reserved, credential-bearing, and redirect-to-private targets
- DCR accepts only HTTPS redirect URIs except documented loopback development clients

- [ ] **Step 2: Run tests to verify RED**

Run `php tests\run.php`.

- [ ] **Step 3: Install OAuth storage**

Create `{$wpdb->prefix}pageforge_mcp_clients` with client ID, metadata URL, display name, redirect URIs JSON, created time, and revoked time. Create `{$wpdb->prefix}pageforge_mcp_tokens` with token hash, type, user ID, client ID, scopes JSON, resource, expiry, revocation, parent hash, and created time. Index token hash, client ID, user ID, expiry, and revocation.

- [ ] **Step 4: Implement discovery and registration**

Protected-resource metadata includes `resource`, `authorization_servers`, `scopes_supported`, and bearer methods. Authorization-server metadata includes issuer, authorization, token, registration endpoint, response type `code`, grant types `authorization_code` and `refresh_token`, code challenge method `S256`, and supported scopes.

Prefer Client ID Metadata Documents. Support pre-registered clients and DCR for clients that require it. Fetch client metadata with `wp_safe_remote_get()`, no more than two redirects, five-second timeout, 128KB response limit, public DNS resolution checks before every request, and strict JSON schema validation.

- [ ] **Step 5: Implement authorization and consent**

Validate client, redirect URI, resource, scopes, state, code challenge, and method before login redirect. Store a signed request for 10 minutes. After WordPress login, show a PageForge consent screen listing client, site, exact scopes, and consequences. Only `manage_options` users can approve. Generate a one-use authorization code and redirect with `code`, `state`, and issuer identifier.

- [ ] **Step 6: Implement token, refresh, revoke, and bearer validation**

Require form-encoded POST, verify PKCE with base64url SHA-256, bind tokens to canonical resource, hash all tokens before storage, rotate refresh tokens, and return OAuth error codes without WordPress stack traces. Add one-click client and token revocation to Connections.

- [ ] **Step 7: Integrate OAuth with MCP authentication**

For Pro bearer credentials, try OAuth token hash first and static PageForge key second. Return scope challenge headers for missing scope and protected-resource metadata URL for missing or invalid authorization.

- [ ] **Step 8: Verify OAuth contract**

Run all Premium tests and lint. Use MCP Inspector against local HTTPS WordPress to complete discovery, authorization, PKCE, token exchange, tools/list, refresh, and revoke. Confirm a revoked token returns 401.

- [ ] **Step 9: Commit Pro OAuth**

```powershell
git add pageforge.php includes/class-pageforge-mcp-oauth.php includes/class-pageforge-mcp-oauth-store.php includes/class-pageforge-mcp-auth.php includes/class-pageforge-plugin.php includes/class-pageforge-admin.php tests/McpOAuthTest.php
git commit -m "feat: add oauth for pro mcp clients"
```

## Plan 3 Verification Gate

Run all tests and syntax checks in both repositories. Against a copied local site, test Free with bearer key and Application Password, Pro with OAuth and bearer fallback, current and legacy protocol requests, disabled endpoint behavior, wrong scope, expired confirmation, revoked token, malformed JSON, and an invented write tool in Free. Verify all responses are secret-free and no-store.
