# Magebit_Mcp

Magento 2 implementation of the [Model Context Protocol](https://modelcontextprotocol.io/specification/2025-06-18) — a single `POST /mcp` endpoint that lets AI clients (Claude Desktop / Code, ChatGPT, Cursor, the MCP Inspector, …) discover and call tools that read or mutate Magento data, gated by bearer tokens bound to an admin user's ACL.

This module provides the **transport, authentication, authorization, audit, and tool-registry primitives**. Domain-specific tools live in sub-modules (see below) or ship inside this module when they belong to the core system surface — stores, websites, system configuration.

## Install

```bash
composer require magebitcom/module2-mcp-module
bin/magento module:enable Magebit_Mcp
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Tool sub-modules

### Included as dependency
- [Order module](https://github.com/magebitcom/magento2-mcp-order-tools)
- [Catalog module](https://github.com/magebitcom/magento2-mcp-catalog-tools)
- [Customer module](https://github.com/magebitcom/magento2-mcp-customer-tools)
- [CMS module](https://github.com/magebitcom/magento2-mcp-cms-tools)

## Configure

Stores → Configuration → **Magebit → MCP Server**:

| Field | Purpose |
|---|---|
| **Enable MCP Server** | Master kill-switch. When off, every `POST /mcp` returns HTTP 503 before auth runs. |
| **Server Name** | Advertised to clients via `initialize.serverInfo.name`. Override per-deployment so staging and production don't look identical to the AI. |
| **Server Description** | Free-text guidance surfaced via `initialize.instructions`. Describe what the store sells, its locale/currency, or any operator-specific hint the model should keep in mind. |
| **Allow Write Tools** | Global override. A token's `allow_writes` flag is only honoured when this is **Yes**. |
| **Allowed Origins** | DNS-rebinding defense. One origin per line, `#` comments ignored, `*` wildcard anchored to a host-component boundary. Defaults cover loopback plus Claude / ChatGPT / Gemini / Copilot / Grok / Perplexity. |
| **Audit Log Retention (days)** | Rows older than this are purged by the `magebit_mcp_audit_purge` cron. `0` disables purging. |
| **Enable Rate Limiting** | Caps how many `tools/call` invocations a single admin user may issue against a single tool per minute. Off by default; existing deployments are not throttled until flipped on. Counters are keyed per `(admin_user_id, tool)` — multiple bearer tokens owned by the same admin share a budget. |
| **Requests Per Minute** | Per-admin-per-tool budget when rate limiting is on. Fixed-window counter; short bursts across a minute boundary may briefly allow up to 2× the configured value. Over-limit calls return `-32013 RATE_LIMITED` with `data.limit` + `data.retry_after_seconds`. Flush in-flight counters with `bin/magento cache:clean MAGEBIT_MCP_RATE_LIMIT`. |

The ACL resource `Magebit_Mcp::config` gates access to this section, separate from `Magebit_Mcp::mcp_tokens` and `Magebit_Mcp::mcp_audit`.

## Core tool catalog

Bundled with the base module — available on every installation.

| Tool | Purpose |
|---|---|
| `system.store.list` | Enumerate every website, store group, and store view. Use the returned ids to scope other tools (e.g. filter `sales.order.list` by `store_id`). |
| `system.store.info` | Return general settings for a single store view — name, base URLs (secure / unsecure / link / media / static), locale, timezone, currencies, merchant contact block. |
| `system.config.get` | Read one `core_config_data` path at default / website / store scope. Encrypted or `type="password"` / `type="obscure"` fields are rejected with `FORBIDDEN_FIELD`; a keyword fallback covers config-only paths that lack a system.xml entry. |

All three are read-only.

## Pre-built prompts

The module also ships a handful of MCP **Prompts** — pre-built workflows that surface in the client's prompt menu (in Claude Web / Claude Desktop, the `/`-style picker). Each is a tiny templated workflow that nudges the LLM to call the right tools and respond in plain English.

| Prompt | Title | What it does |
|---|---|---|
| `system.health_check` | Is my store healthy? | Checks page caches, indexer state, and admin alerts; reports any issues without jargon. |
| `system.refresh_after_edit` | I just made changes — make them visible | Flushes all caches and reindexes whatever is stale, so customers see fresh content. **Requires a write-capable token.** |
| `system.list_stores` | Show me my stores | Lists every store and store view with public URL, language, and currency. |
| `system.find_setting` | Find a setting | Takes a plain-English description (`query` argument), maps it to a Magento config path, and reports the current value. |

Write-requiring prompts are filtered out of `prompts/list` for tokens / installations that can't write, so the menu never shows an option that would just error.

## Mint a token + first call

```bash
bin/magento magebit:mcp:token:create \
    --admin-user=<username> \
    --name='Claude Desktop — my laptop'
# Plaintext printed once; HMAC-SHA256 hash (keyed by crypt/key) stored in magebit_mcp_token.
```

```bash
curl -sS -X POST https://<host>/mcp \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <token>' \
  -H 'Mcp-Protocol-Version: 2025-06-18' \
  -d '{
        "jsonrpc": "2.0",
        "id": 1,
        "method": "tools/call",
        "params": {
            "name": "system.store.list",
            "arguments": {}
        }
      }'
```

## Connect an AI client

The server speaks MCP's **Streamable HTTP** transport — one URL (`https://<host>/mcp`), bearer auth via the `Authorization` header. Any MCP client that supports HTTP transport connects natively; clients that still only speak the stdio transport connect through the official [`mcp-remote`](https://www.npmjs.com/package/mcp-remote) proxy.

Every snippet below assumes a token minted via `magebit:mcp:token:create`. Production stores MUST be reached over HTTPS — bearer tokens travel in the request header.

### Claude Desktop

Claude Desktop supports remote MCP servers as **Custom Connectors** on paid plans. Settings → Connectors → **Add custom connector**:

- **Name** — whatever you want shown in the UI (the server-side label from `Server Name` is advertised back at handshake)
- **URL** — `https://<host>/mcp`
- **Advanced → Authentication** — `Bearer`, paste the token

On Free plans (or for any client that can't hold a custom-header value) use the stdio bridge below via `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "magebit": {
      "command": "npx",
      "args": [
        "-y", "mcp-remote",
        "https://<host>/mcp",
        "--header", "Authorization:Bearer <token>"
      ]
    }
  }
}
```

Config lives at `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows).

### Claude Code

Claude Code supports HTTP transport natively via `claude mcp add`:

```bash
claude mcp add --transport http magebit https://<host>/mcp \
  --header "Authorization: Bearer <token>"
```

Scope it to the current project with `--scope project` or keep it user-global (the default). `claude mcp list` shows every registered server; `/mcp` inside a session confirms handshake + tool discovery. Config is written to `~/.claude.json` (global) or `.mcp.json` at the project root (project scope).

### ChatGPT (Developer Mode connectors)

On plans that expose custom MCP connectors (Business / Enterprise / Edu, and Plus/Pro via Developer Mode), Settings → Connectors → **Create**:

- **MCP server URL** — `https://<host>/mcp`
- **Authentication** — `Custom header`, name `Authorization`, value `Bearer <token>`

Public-internet reachability is required — ChatGPT calls the MCP from OpenAI infrastructure, not your browser, so a localhost URL will not work. Expose staging via a tunnel (Cloudflare Tunnel, ngrok, Tailscale Funnel) if you need to test before production DNS is cut over.

### Cursor / Cline / Windsurf

All three read an `mcp.json` file. Cursor's lives at `~/.cursor/mcp.json` (global) or `<project>/.cursor/mcp.json` (per-workspace):

```json
{
  "mcpServers": {
    "magebit": {
      "url": "https://<host>/mcp",
      "headers": {
        "Authorization": "Bearer <token>"
      }
    }
  }
}
```

If your Cursor/Cline/Windsurf build only supports stdio, swap the block for the same `npx mcp-remote …` recipe shown under Claude Desktop.

### MCP Inspector (smoke test before wiring a real client)

```bash
npx @modelcontextprotocol/inspector
```

Open the UI it prints, pick **Streamable HTTP**, enter `https://<host>/mcp`, and set a **Bearer token** in the auth panel. `tools/list` and `tools/call` are one-click from there.

### Anything else (raw HTTP, scripts, other clients)

The server implements MCP 2025-06-18 verbatim. Any client or script that can speak JSON-RPC 2.0 over `POST` works as long as it sends:

```
Authorization: Bearer <token>
Mcp-Protocol-Version: 2025-06-18
Content-Type: application/json
```

`Mcp-Protocol-Version` is required on every request *after* the initial `initialize` handshake.

### Local development

Use a loopback origin (`http://localhost*`, `http://127.0.0.1*`) — they're in the default allowlist. If Claude Desktop / ChatGPT needs to reach your dev box from outside, expose it with Cloudflare Tunnel / ngrok / Tailscale Funnel and add the tunnel hostname to **Stores → Configuration → Magebit → MCP Server → Allowed Origins**.

## Connecting Claude Web (or any OAuth-capable MCP host)

The MCP module ships an OAuth 2.1 + PKCE authorization server, so Claude Web's "Custom Integration" flow works out of the box — no external IdP, no proxy, no extra services.

### One-time setup

1. In Magento admin: **System → MCP → OAuth Clients → New Client**.
2. Enter:
   - **Name** — e.g. `Claude Web`.
   - **Redirect URIs** — paste the URI Claude Web's connector form requests (one per line). Exact-match: no trailing-slash drift.
   - **Allowed scopes** — tick `mcp:read` for read-only clients; tick `mcp:write` as well to let the client request write tools (creates, updates, cancels). The consent screen still gives the approving admin a per-grant veto, so this is the upper bound, not the default grant.
3. Save. Magento shows the **Client ID** and **Client Secret** once — copy both before navigating away.

### In Claude Web

1. Open the Custom Connector form.
2. Fill in:
   - **Server URL**: `https://yourstore.com/mcp`
   - **OAuth Client ID**: paste from step 3 above.
   - **OAuth Client Secret**: paste from step 3 above.
3. Connect. Claude Web redirects you to your store. If you're already logged into Magento admin in the same browser, you'll see a one-screen consent listing the requested OAuth scopes; otherwise you'll see a "Log in to admin first" page with a link.
4. Tick the scopes you want to grant (`mcp:read` is enabled by default, `mcp:write` is shown when the client asked for it) and Approve. Claude Web finishes the OAuth dance and the tools list populates. Tokens issued without `mcp:write` reject write tools with `-32012 WRITE_NOT_ALLOWED`.

### How it works under the hood

- `POST /mcp` with no token → `401 + WWW-Authenticate: Bearer realm=…, resource_metadata=https://yourstore.com/.well-known/oauth-protected-resource`.
- That metadata document (RFC 9728) points at the authorization server (the same Magento host); the AS metadata at `/.well-known/oauth-authorization-server` (RFC 8414) lists the authorize / token endpoints and supported grants.
- Both discovery documents advertise URLs derived from the storefront's `web/secure/base_url`, so `web/secure/base_url` must match the hostname remote MCP clients reach the store on. If you're running behind a tunnel or proxy that preserves the upstream `Host` header (the default for ngrok — the public hostname only appears in `X-Forwarded-Host`), the metadata will point at the internal hostname and remote clients won't be able to follow it.
- Claude Web walks the authorization-code + PKCE flow. The user's consent step requires an active Magento admin session — the issued access token is bound to that admin user, so all ACL checks downstream work as if the admin was using the admin UI directly.
- Access tokens default to 1-hour TTL; refresh tokens to 30 days. Both lifetimes are configurable under **Stores → Configuration → Magebit → MCP Server → OAuth 2.1**.
- Issued access tokens are stored in the same `magebit_mcp_token` table as CLI/admin-issued tokens — they show up in **System → MCP → Connections** with the label `OAuth: <client name>` and can be revoked the same way.
- **Scopes** advertised in `scopes_supported`:
  - `mcp:read` — invoke any read tool the admin's role allows. Default if the client omits the `scope` parameter.
  - `mcp:write` — additionally invoke write tools (`create`, `update`, `cancel`, `hold`, etc.). Requires both the per-token grant *and* the global **Allow Write Tools** setting.

  The OAuth client's **Allowed scopes** registration acts as the upper bound; the consent screen lets the approving admin issue a narrower token by un-ticking individual scopes. The granted scope is persisted on the token row (`granted_scope` column) and replayed on refresh, so a `mcp:read`-only token stays read-only across the entire refresh chain.

### OAuth endpoints

| Endpoint | Purpose |
|---|---|
| `GET /.well-known/oauth-protected-resource` | RFC 9728 resource metadata (advertised in `WWW-Authenticate`). Also reachable at `/.well-known/oauth-protected-resource/mcp` per RFC 9728 §3. |
| `GET /.well-known/oauth-authorization-server` | RFC 8414 authorization server metadata (lists endpoints, grants, PKCE methods). |
| `GET\|POST /mcp/oauth/authorize` | Interactive consent UI; renders login-required if no admin session. |
| `POST /mcp/oauth/token` | Exchanges auth code or refresh token for a new access+refresh pair. |

### Configuration paths

| Path | Default | Purpose |
|---|---|---|
| `magebit_mcp/oauth/access_token_lifetime` | `3600` | Access token TTL (seconds). |
| `magebit_mcp/oauth/refresh_token_lifetime_days` | `30` | Refresh token TTL (days). |
| `magebit_mcp/oauth/auth_code_lifetime` | `60` | Authorization code TTL (seconds). Increase only when debugging. |

### Revocation

- **Revoke a single Claude session**: revoke the access token in the Connections grid.
- **Disconnect a client entirely**: delete the OAuth client. Cascades to its auth codes and refresh tokens. Already-issued access tokens stay (they cap at the access lifetime; revoke individually if urgent).

## Console commands

| Command | Purpose |
|---|---|
| `magebit:mcp:tools:list` | Show every registered tool, its ACL resource, its write mode, and whether it requests confirmation. |
| `magebit:mcp:tools:validate-acl` | Fail if any registered tool references an ACL resource not declared in `acl.xml`. Wire into CI. |
| `magebit:mcp:token:create` | Mint a bearer token for an admin user. Plaintext is shown once. |
| `magebit:mcp:token:list` | List tokens (last used, revoked state, owning admin). |
| `magebit:mcp:token:revoke` | Revoke a token — auth fails immediately, audit rows are preserved. |
| `magebit:mcp:token:delete` | Hard-delete a token (and keep the audit row pointing to `deleted-<id>`). |

## Error codes

Standard JSON-RPC 2.0 codes (`-32700` parse error, `-32600` invalid request, `-32601` method not found, `-32602` invalid params, `-32603` internal error) plus the following module-specific ones:

| Code | Constant | When |
|---|---|---|
| `-32001` | `UNAUTHORIZED` | Missing / malformed / revoked bearer token, or the token's owning admin is inactive or deleted. Returned with HTTP 401 + `WWW-Authenticate: Bearer`. |
| `-32002` | `INVALID_ORIGIN` | `Origin` header not on the allowlist. Returned with HTTP 403. |
| `-32003` | `UNSUPPORTED_PROTOCOL_VERSION` | `Mcp-Protocol-Version` header absent or unsupported. |
| `-32004` | `FORBIDDEN` | ACL denial on the tool's own resource, its `UnderlyingAclAwareInterface`-declared resource, or the token's explicit scope list. |
| `-32010` | `TOOL_NOT_FOUND` | `tools/call` referenced a tool not in the registry. |
| `-32011` | `TOOL_EXECUTION_FAILED` | Tool raised a `LocalizedException` (message passed through) or an unexpected throwable (generic message; full exception logged to `var/log/magebit_mcp.log`). |
| `-32012` | `WRITE_NOT_ALLOWED` | Write tool blocked by the global `allow_writes` kill-switch or the token's `allow_writes=0`. |
| `-32013` | `RATE_LIMITED` | Caller exceeded their per-minute allowance for the tool. `data` carries `limit` (int, requests/minute) and `retry_after_seconds` (int, 1..60) so clients can back off. |
| `-32014` | `SCHEMA_VALIDATION_FAILED` | Tool arguments failed JSON Schema validation. `data.errors` carries the structured opis error tree. |
| `-32015` | `SERVER_DISABLED` | `magebit_mcp/general/enabled=0`. Returned with HTTP 503. |
| `-32016` | `PROMPT_NOT_FOUND` | `prompts/get` referenced a prompt not in the registry. |

Oversized request bodies (> 256 KiB) short-circuit with HTTP 413 + JSON-RPC `INVALID_REQUEST`.

## Extending

- **Adding a tool from a satellite module** — see `docs/EXTENDING.md`. Implement `Magebit\Mcp\Api\ToolInterface`, declare an ACL resource under `Magebit_Mcp::tools`, register with `ToolRegistry` via `etc/di.xml`.
- **Adding a per-entity field to a read tool that composes its response from resolvers** — see the target satellite's own `docs/EXTENDING.md` (e.g. `Magebit_McpOrderTools` docs `EXTENDING.md` for orders/invoices/shipments/credit-memos/comments). Resolvers all extend the base `Magebit\Mcp\Api\FieldResolverInterface` marker and are run through a shared `Magebit\Mcp\Model\Util\ResolverPipeline`.
- **Call-lifecycle observers** (custom audit sinks, result masking, bespoke throttling) — subscribe to `magebit_mcp_tool_call_before` / `magebit_mcp_tool_call_after`. Event params are read-only; arguments are already redacted for audit when the event fires.
- **Replacing the rate limiter** — the shipped `Magebit\Mcp\Model\RateLimiter\ConfigurableRateLimiter` is admin-configurable (see above) and throws `Magebit\Mcp\Exception\RateLimitedException` when a caller is over budget. Swap it out by overriding the `Magebit\Mcp\Api\RateLimiterInterface` DI preference — `NoOpRateLimiter` is retained as the documented escape hatch for deployments that need to bypass throttling entirely. A sliding-window or token-bucket implementation can also drop in behind the same interface.

## What's where

```
app/code/Magebit/Mcp/
  Api/              Contracts: ToolInterface, ToolRegistryInterface, FieldResolverInterface, UnderlyingAclAwareInterface, …
  Block/            Admin UI blocks (token grid actions, "show plaintext once" page, etc.)
  Console/Command/  CLI: token lifecycle, tool introspection, ACL validation
  Controller/       POST /mcp route — entry point for all JSON-RPC traffic
  Cron/             PurgeAuditLog (respects retention_days config)
  Exception/        Typed exceptions mapped to JSON-RPC error codes
  Model/
    Auth/           Token store, HMAC-SHA256 hasher, AuthenticatedContext
    AuditLog/       Audit writer, PII redactor, AuditContext
    Config/         ModuleConfig — single reader for magebit_mcp/* store-config paths
    JsonRpc/        Dispatcher, handlers (initialize, ping, tools/list, tools/call), Request/Response DTOs, ErrorCode enum
    RateLimiter/    Admin-configurable fixed-window limiter (ConfigurableRateLimiter) + NoOp escape hatch
    Tool/           ToolRegistry + ToolResult + WriteMode enum
    Util/           ResolverPipeline — shared resolver sort/filter used by satellite read tools
    Validator/      OriginValidator, ProtocolVersionValidator, JsonSchemaValidator
  Tool/System/      Core tools: StoreList, StoreInfo, ConfigGet
  Ui/Component/     Admin-grid columns / actions for tokens + audit log
  Cron/
  docs/
    EXTENDING.md    How to ship a tool from a satellite module
    plan/PLAN.md    Historical design doc (kept for reference)
  etc/
    acl.xml         Magebit_Mcp::mcp → mcp_tokens / mcp_audit / tools / config
    adminhtml/
      menu.xml      Admin → System → MCP (tokens, audit)
      system.xml    Store config UI — enabled, server_name, server_description, allow_writes, allowed_origins, retention_days, rate_limiting.*
      routes.xml
    config.xml      Defaults (incl. the Allowed Origins list)
    crontab.xml     Audit purge schedule
    db_schema.xml   Tables: magebit_mcp_token, magebit_mcp_audit_log
    di.xml          Dispatcher handler wiring, core-tool registrations, UI data sources, console commands
    frontend/routes.xml  /mcp route binding
    module.xml
```

## License

Released under the [MIT License](LICENSE).
