# Magebit_Mcp

Magento 2 implementation of the [Model Context Protocol](https://modelcontextprotocol.io/specification/2025-06-18) — a single `POST /mcp` endpoint that lets AI clients (Claude Desktop / Code, ChatGPT, Cursor, the MCP Inspector, …) discover and call tools that read or mutate Magento data, gated by bearer tokens bound to an admin user's ACL.

This module provides the **transport, authentication, authorization, audit, and tool-registry primitives**. Domain-specific tools live in sub-modules (see below) or ship inside this module when they belong to the core system surface — stores, websites, system configuration.

- JSON-RPC 2.0 + HTTP POST
- MCP protocol version **2025-06-18**
- Bearer-token auth (`Authorization: Bearer <token>`), bcrypt-hashed at rest, one-shot plaintext on creation
- ACL enforcement per tool — tokens inherit their owning admin's role; every `tools/call` re-checks the tool's ACL resource, and tools that implement `UnderlyingAclAwareInterface` also re-check the Magento admin-UI resource they delegate to
- Write-tool gating via a global store-config kill-switch + a per-token `allow_writes` flag
- DNS-rebinding defense via an admin-editable `Origin` allowlist
- Full audit trail of every call (method, arguments, result, duration, admin, token) with PII redacted to HMAC fingerprints at write time
- Admin UI for browsing tokens + the audit log, plus store-config for name/description/kill-switch/origins/retention
- Console commands for token lifecycle, tool listing, and ACL validation

## Sub-modules

### Included
- [Order module](https://github.com/magebitcom/magento2-mcp-order-tools)

## Install

```bash
composer require magebitcom/module2-mcp-module
bin/magento module:enable Magebit_Mcp
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

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

The ACL resource `Magebit_Mcp::config` gates access to this section, separate from `Magebit_Mcp::mcp_tokens` and `Magebit_Mcp::mcp_audit`.

## Core tool catalog

Bundled with the base module — available on every installation.

| Tool | Purpose |
|---|---|
| `system.store.list` | Enumerate every website, store group, and store view. Use the returned ids to scope other tools (e.g. filter `sales.order.list` by `store_id`). |
| `system.store.info` | Return general settings for a single store view — name, base URLs (secure / unsecure / link / media / static), locale, timezone, currencies, merchant contact block. |
| `system.config.get` | Read one `core_config_data` path at default / website / store scope. Encrypted or `type="password"` / `type="obscure"` fields are rejected with `FORBIDDEN_FIELD`; a keyword fallback covers config-only paths that lack a system.xml entry. |

All three are read-only.

## Mint a token + first call

```bash
bin/magento magebit:mcp:token:create \
    --admin-user=<username> \
    --name='Claude Desktop — my laptop'
# Plaintext printed once; bcrypt hash stored in magebit_mcp_token.
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
| `-32001` | `UNAUTHENTICATED` | Missing / malformed / revoked bearer token. |
| `-32002` | `ADMIN_DISABLED` | The token's owning admin is inactive or deleted. |
| `-32003` | `PROTOCOL_MISMATCH` | `Mcp-Protocol-Version` header absent or unsupported. |
| `-32004` | `FORBIDDEN` | ACL denial on the tool's own resource or its `UnderlyingAclAwareInterface`-declared resource. |
| `-32010` | `TOOL_UNKNOWN` | `tools/call` referenced a tool not in the registry. |
| `-32011` | `VALIDATION_FAILED` | Tool arguments failed JSON Schema validation. |
| `-32012` | `WRITE_NOT_ALLOWED` | Write tool blocked by the global kill-switch or the token's `allow_writes=0`. |
| `-32013` | `ORIGIN_REJECTED` | `Origin` header not on the allowlist. |
| `-32014` | `PAYLOAD_TOO_LARGE` | Request body exceeds 256 KiB. |
| `-32015` | `SERVER_DISABLED` | `magebit_mcp/general/enabled=0`. Returned with HTTP 503. |

## Extending

- **Adding a tool from a satellite module** — see `docs/EXTENDING.md`. Implement `Magebit\Mcp\Api\ToolInterface`, declare an ACL resource under `Magebit_Mcp::tools`, register with `ToolRegistry` via `etc/di.xml`.
- **Adding a per-entity field to a read tool that composes its response from resolvers** — see the target satellite's own `docs/EXTENDING.md` (e.g. `Magebit_McpOrderTools` docs `EXTENDING.md` for orders/invoices/shipments/credit-memos/comments). Resolvers all extend the base `Magebit\Mcp\Api\FieldResolverInterface` marker and are run through a shared `Magebit\Mcp\Model\Util\ResolverPipeline`.
- **Call-lifecycle observers** (rate limiting, custom audit sinks, result masking) — subscribe to `magebit_mcp_tool_call_before` / `magebit_mcp_tool_call_after`. Event params are read-only; arguments are already redacted for audit when the event fires.

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
    Auth/           Token store, bcrypt hasher, AuthenticatedContext
    AuditLog/       Audit writer, PII redactor, AuditContext
    Config/         ModuleConfig — single reader for magebit_mcp/* store-config paths
    JsonRpc/        Dispatcher, handlers (initialize, ping, tools/list, tools/call), Request/Response DTOs, ErrorCode enum
    RateLimiter/    No-op default; swap via DI preference for a real limiter
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
      system.xml    Store config UI — enabled, server_name, server_description, allow_writes, allowed_origins, retention_days
      routes.xml
    config.xml      Defaults (incl. the Allowed Origins list)
    crontab.xml     Audit purge schedule
    db_schema.xml   Tables: magebit_mcp_token, magebit_mcp_audit_log
    di.xml          Dispatcher handler wiring, core-tool registrations, UI data sources, console commands
    frontend/routes.xml  /mcp route binding
    module.xml
```
