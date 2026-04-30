# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this module is

Magento 2 implementation of the Model Context Protocol (MCP, spec version `2025-06-18`). Ships the **transport, auth, ACL, audit, and tool registry** — a single `POST /mcp` endpoint that speaks JSON-RPC 2.0 over HTTP with bearer auth. Domain tools live in satellite modules (`Magebit_McpOrderTools`, `Magebit_McpCatalogTools`, `Magebit_McpCustomerTools`, `Magebit_McpCmsTools`); this repo ships only the core `system.store.list`, `system.store.info`, `system.config.get` tools.

The repo is checked out as a Magento module at `app/code/Magebit/Mcp`. The Magento root is `/var/www/demo` — Composer, `bin/magento`, and `vendor/bin/*` all run from there, not from this directory. Read the root `README.md` for protocol-level detail and client-onboarding snippets — this file complements it with architecture and workflow notes.

## Commands

All commands run from the Magento root (`/var/www/demo`).

```bash
# Enable / rebuild after XML or DI changes
bin/magento module:enable Magebit_Mcp
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush

# Module-specific CLI
bin/magento magebit:mcp:tools:list            # Every registered tool, ACL, write mode
bin/magento magebit:mcp:tools:validate-acl    # CI gate: tool ACLs must resolve + names must match regex
bin/magento magebit:mcp:token:create --admin-user=<u> --name='<label>'
bin/magento magebit:mcp:token:{list,revoke,delete}

# Static analysis — PHPStan level 9 on this module
vendor/bin/phpstan analyse app/code/Magebit/Mcp -c app/code/Magebit/Mcp/phpstan.neon

# Unit tests (module-scoped)
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Magebit/Mcp/Test/Unit
# Single test class
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Magebit/Mcp/Test/Unit/Model/JsonRpc/DispatcherTest.php
# Single method
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist --filter testDispatchMethodNotFound app/code/Magebit/Mcp/Test/Unit/Model/JsonRpc/DispatcherTest.php
```

Local smoke-testing via MCP Inspector: see `dev/inspector/README.md`. For self-signed `*.docker` hostnames the Inspector needs `NODE_TLS_REJECT_UNAUTHORIZED=0` per-process.

## Request pipeline (Controller/Index/Index.php)

Every `POST /mcp` runs this fixed sequence. Every stage has an audit row flushed from the `finally` block — unauthenticated attempts leave a trail:

1. **Origin header** — `Model/Validator/OriginValidator`, DNS-rebinding defense, allowlist in store config.
2. **Bearer auth** — `Model/Auth/TokenAuthenticator`. 401 + `WWW-Authenticate: Bearer` on failure. Tokens are HMAC-SHA256 hashed at rest (keyed by the install's `crypt/key`).
3. **Body parse** — JSON-RPC envelope. Body capped at 256 KiB (`MAX_BODY_BYTES`) so unauthenticated attackers can't OOM the FPM worker pre-auth.
4. **`Mcp-Protocol-Version` header** — `Model/Validator/ProtocolVersionValidator` (required on every request *after* `initialize`; tolerates folded duplicates).
5. **Dispatch** — `Model/JsonRpc/Dispatcher` routes by method to the DI-registered handler, passing `AuthenticatedContext`.
6. **Audit** — `Model/AuditLog/AuditLogger` writes the row; `PiiRedactor` replaces sensitive fields with 16-char HMAC fingerprints keyed by `crypt/key` before write. This happens even on auth failure.

The controller bypasses layout and writes directly to the HTTP response. It implements `CsrfAwareActionInterface` to opt out of Magento's form-key CSRF — bearer auth is the CSRF gate.

## Prompts

In addition to Tools, the module ships the MCP **Prompts** primitive — a `/`-menu of pre-built workflows that surface in clients like Claude Web / Claude Desktop. Resources are intentionally not implemented (their unique value is subscriptions, which need streaming transport).

**Architecture mirrors Tools:** `Api/PromptInterface` declares the contract, `Model/Prompt/PromptRegistry` is a DI-array registry validating that the di.xml key matches `getName()` (regex same as tools), and `etc/di.xml` registers each prompt under its canonical name. Satellite modules wire their own prompts the same way.

**Handlers:** `Model/JsonRpc/Handler/PromptsListHandler` and `…/PromptsGetHandler` serve `prompts/list` and `prompts/get`. The list handler filters out write-requiring prompts when either `magebit_mcp/general/allow_writes` or the token's own `allow_writes` is off — the menu never offers an option that `prompts/get` would just reject. `Model/Prompt/PromptRenderer` does dumb `{{argument_name}}` substitution; missing required args raise `INVALID_PARAMS` before reaching the renderer, missing optional args substitute to empty string.

**Audit:** `prompts/get` writes one row to `magebit_mcp_audit_log` with `method='prompts/get'` and `prompt_name=<name>` (new column added to the schema). Argument values flow through the same `PiiRedactor` pass as tool arguments.

**Audience constraint:** every user-facing string on a prompt — `getTitle()`, `getDescription()`, *and the LLM-generated response the body elicits* — targets the store owner, not a Magento dev. Bodies explicitly tell the LLM to translate jargon ("cache disabled" → "page speed feature turned off", etc.). Tool names inside prompt bodies stay technical because they're LLM-facing, not user-facing.

**Shipped prompts** (under `Magebit\Mcp\Prompt\System\`):
- `system.health_check` — "Is my store healthy?". Walks `system.cache.list`, `system.index.list`, `system.notification.list`. Read-only.
- `system.refresh_after_edit` — "I just made changes — make them visible". Calls `system.cache.flush` then `system.index.reindex` per stale indexer. **Write-gated.**
- `system.list_stores` — "Show me my stores". Calls `system.store.list`. Read-only.
- `system.find_setting` — "Find a setting". Takes a `query` argument; maps natural-language description to a config path then calls `system.config.get`. Read-only.

The `initialize` handler advertises both `tools` and `prompts: { listChanged: false }` in the capabilities block. The error code `-32016 PROMPT_NOT_FOUND` was added; next free is `-32017`.

## OAuth 2.1 authorization server

The module also ships an OAuth 2.1 + PKCE authorization server at `/mcp/oauth/*`:

- `GET /mcp/oauth/protectedresourcemetadata` — RFC 9728. Advertised by `WWW-Authenticate` on every 401.
- `GET /mcp/oauth/authorizationservermetadata` — RFC 8414. Lists endpoints + supported grants.
- `GET|POST /mcp/oauth/authorize` — interactive consent screen. Requires Magento admin session.
- `POST /mcp/oauth/token` — `authorization_code` and `refresh_token` grants.

Issued access tokens are written to `magebit_mcp_token` with `oauth_client_id` set, so the bearer pipeline (`TokenAuthenticator`) doesn't change. Refresh tokens live in their own table with revoke-on-use rotation.

**Scope catalog (`Model/OAuth/Scope`):** two scopes — `mcp:read` (read tools) and `mcp:write` (also enables write tools). Advertised in `scopes_supported` on both metadata endpoints. The OAuth `scope` parameter flows through every step:

1. **Authorize endpoint** (`Controller/OAuth/Authorize.php`) — `ScopeValidator::parse` rejects unknown values; `validateAgainstClient` rejects requests that exceed the client's per-row `allowed_scopes_json` cap with `error=invalid_scope`. Missing/empty scope defaults to `mcp:read` (RFC 6749 §3.3).
2. **Consent screen** (`view/adminhtml/templates/oauth/authorize/consent.phtml`) — renders one checkbox per requested scope, all pre-ticked; the admin can untick scopes to issue a narrower token. Submitting nothing = deny.
3. **Auth-code → access-token exchange** (`Controller/OAuth/Token.php`) — derives `allow_writes` from `Scope::WRITE` membership in the granted scope set; `granted_scope` (varchar 255 on `magebit_mcp_token`) carries the canonical space-separated string.
4. **Refresh** (`Model/OAuth/RefreshTokenRotator.php`) — replays `granted_scope` and `allow_writes` from the rotated access token onto the new pair, so scope persists across rotation.

**Per-client cap:** `magebit_mcp_oauth_client.allowed_scopes_json` is the upper bound a client may request. NULL on the column (legacy rows) is interpreted as `[mcp:read]` for backwards compat — admins must explicitly tick `mcp:write` on the OAuth Clients edit form before a client can request write scope.

Tables: `magebit_mcp_oauth_client`, `magebit_mcp_oauth_auth_code`, `magebit_mcp_oauth_refresh_token`. Cleanup cron: `magebit_mcp_purge_oauth` (daily 04:00).

Admin UI: System → MCP → OAuth Clients (separate ACL `Magebit_Mcp::mcp_oauth_clients`).

## Core extensibility surface

Satellite modules must reuse these five contracts — never duplicate them.

- **`Api/ToolInterface`** — every MCP tool. Registered by DI array into `Model/Tool/ToolRegistry`. The registry validates at construction that the di.xml key matches `getName()` and matches `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$`. Duplicates fail `setup:di:compile`.
- **`Api/UnderlyingAclAwareInterface`** — opt-in second ACL check. If a tool wraps a Magento service contract (e.g. an invoice-create tool wrapping `InvoiceOrderInterface`), return the underlying Magento admin-UI resource here. `ToolsCallHandler` then enforces *both* the MCP-specific ACL AND the admin-UI ACL. Invariant: "MCP cannot do what the admin UI cannot."
- **`Api/FieldResolverInterface`** — marker for the field-resolver pattern used by satellite read tools. Each resolver owns a named slice of the response (`totals`, `items`, …). `Model/Util/ResolverPipeline` walks heterogeneous resolver arrays and orders them by `getSortOrder()` (default 100). Satellites define entity-typed sub-interfaces (`OrderFieldResolverInterface` etc.); the pipeline only needs the marker.
- **`Api/ToolRegistryInterface`** / **`ToolResultInterface`** — registry + result envelope.
- **Events `magebit_mcp_tool_call_before` / `_after`** — cross-cutting concerns (result masking, custom audit sinks, bespoke throttling on top of the shipped limiter). Params are read-only; arguments have already been redacted for audit when the event fires, so mutating them desyncs the audit row from what ran.

For DI wiring and a full worked example see `docs/EXTENDING.md`.

## ACL layout

Tool ACL resources live under `Magebit_Mcp::tools` (see `etc/acl.xml`). When adding a new tool ACL in a satellite, follow the convention:

- MCP tool name `catalog.product.get` → ACL resource `Vendor_Module::mcp_tool_catalog_product_get` (dots → underscores, because ACL ids can't contain dots).
- Group under a top-level `Vendor_Module::mcp` node nested under `Magento_Backend::system`, NOT under any wide-allow resource.

Four admin-UI resources also gate the module itself: `Magebit_Mcp::mcp_tokens`, `Magebit_Mcp::mcp_audit`, `Magebit_Mcp::config`, and `Magebit_Mcp::tools`. They are intentionally separate so a token-manager role need not see the audit log and vice versa.

## Write-tool gating

Write tools (`WriteMode::WRITE` from `getWriteMode()`) require BOTH:
1. `magebit_mcp/general/allow_writes = 1` (Stores → Configuration → Magebit → MCP Server → **Allow Write Tools**).
2. Per-token `allow_writes = 1` on the `magebit_mcp_token` row.

Either fails → `-32012 WRITE_NOT_ALLOWED`. Write tools SHOULD also return `getConfirmationRequired(): true` so MCP clients that support user confirmation (Claude Desktop does) prompt the human.

## Logging

This module ships a **dedicated log channel**. Classes inside the module inject `Magebit\Mcp\Api\LoggerInterface` (preferenced to `Magebit\Mcp\Logger\Logger`), which writes to `var/log/magebit_mcp.log`. PSR-3 injections (`Psr\Log\LoggerInterface`) still receive Magento's default system logger — use the module interface for anything MCP-specific so grepping the module log stays meaningful.

## Conventions

- PHP 8.1+; `declare(strict_types=1)` everywhere.
- Every file starts with the Magebit `@author` / `@copyright` / `@license` header block — match it when creating new files.
- PHPStan level 9 is the floor — no array-of-mixed without `@phpstan-param`, no unions without narrowing.
- `docs/plan/*` is gitignored — historical design artifacts, not part of the shipping docs.

## Database

Two tables, declared in `etc/db_schema.xml`:
- `magebit_mcp_token` — bearer tokens (HMAC-SHA256 hash keyed by `crypt/key`, `admin_user_id` FK with `onDelete=CASCADE`, `allow_writes`, revocation + expiry timestamps).
- `magebit_mcp_audit_log` — one row per `POST /mcp` (PII-redacted `arguments_json`, tool-computed `result_summary_json`, duration, error code). `token_id` / `admin_user_id` both nullable with `onDelete=SET NULL` so revoking/deleting a token or admin preserves the history.

Purged by `Cron/PurgeAuditLog` per `magebit_mcp/general/retention_days` (`0` disables purging).

## Error codes

Module-specific JSON-RPC codes are declared in `Model/JsonRpc/ErrorCode`:
`-32001 UNAUTHORIZED`, `-32002 INVALID_ORIGIN`, `-32003 UNSUPPORTED_PROTOCOL_VERSION`, `-32004 FORBIDDEN`, `-32010 TOOL_NOT_FOUND`, `-32011 TOOL_EXECUTION_FAILED`, `-32012 WRITE_NOT_ALLOWED`, `-32013 RATE_LIMITED`, `-32014 SCHEMA_VALIDATION_FAILED`, `-32015 SERVER_DISABLED`. Next free: `-32016`. When adding a new one, extend `ErrorCode` (value + `label()` case) *and* document in the root `README.md` error-codes table.
