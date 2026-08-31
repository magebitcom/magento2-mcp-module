# Setting up Magebit_Mcp — a runbook for AI agents

This page is written for an AI coding agent installing and verifying the MCP server on a Magento 2 store. Humans should read the [README](../README.md) or the [Quick Setup guide](https://magebitcom.github.io/magento2-mcp-module/quick-setup/) instead — those explain the same work with screenshots and prose. Everything here is done from the command line, and every stage ends with a check whose output you can read.

## How to use this page

- **Do the stages in order.** Each one assumes the checks in the previous one passed.
- **Run the check, read the output.** Do not report a stage as done because the command exited quietly. Every check below tells you what a good result looks like.
- **Never invent a value.** Base URL, admin username, redirect URI and tool list all come from the store or from the person you are working for. If you do not have one, ask.
- **Never print a secret into a shared channel, a commit, or a file in the repository.** Bearer tokens and OAuth client secrets are shown once and cannot be read back; hand them to the person and stop.
- Commands are written as `bin/magento`. If the project wraps the CLI (a container, a `d/` script, `ddev`, `warden`), use that wrapper — check the project's own `CLAUDE.md` / `AGENTS.md` / `README` first.

Two things you cannot do from the command line, so plan to ask a human for them:

- **Assigning MCP permissions to an admin role** — admin roles are edited only in *System → Permissions → User Roles*.
- **Editing the allowed-origins list** — it is a multi-line value; setting it via `config:set` is awkward and easy to corrupt.

---

## Stage 1 — Install and enable

```bash
composer require magebitcom/magento2-mcp-module
bin/magento module:enable Magebit_Mcp
bin/magento setup:upgrade
```

For the whole tool suite (catalog, CMS, customer, inventory, marketing, order, report, tax) require `magebitcom/magento2-mcp-suite` instead and run `setup:upgrade`. Be aware that `setup:upgrade` **enables every module it has not seen before** — if some should be off in this environment, disable them in the same deploy, before the store serves traffic.

**Check:**

```bash
bin/magento module:status Magebit_Mcp
```

Expect `Module is enabled`. Anything else means `setup:upgrade` did not complete — read its output rather than re-running it blindly.

## Stage 2 — Configure the server

The defaults are already usable for development. These are the paths worth setting explicitly:

```bash
bin/magento config:set magebit_mcp/general/enabled 1
bin/magento config:set magebit_mcp/general/server_name "<store name> MCP"

# Write tools: layer one of two. Leave at 0 on production unless writes are the point.
bin/magento config:set magebit_mcp/general/allow_writes 1

# Recommended on production
bin/magento config:set magebit_mcp/rate_limiting/enabled 1
bin/magento config:set magebit_mcp/rate_limiting/requests_per_minute 60

bin/magento cache:flush config
```

**Check:**

```bash
bin/magento config:show magebit_mcp/general/enabled
bin/magento config:show magebit_mcp/general/allow_writes
```

Each prints the value you set. `config:show` reading back a value you never wrote is normal — it reports the *effective* value, merged with the module's own defaults.

Leave `magebit_mcp/security/allowed_origins` alone unless you have been asked to change it. The shipped list covers loopback plus the major AI clients; it is a multi-line value, so a human should edit it in *Stores → Configuration → Magebit → MCP Server → Security*.

## Stage 3 — Confirm the tool surface is sound

```bash
bin/magento magebit:mcp:tools:validate-acl
bin/magento magebit:mcp:tools:list
```

**Check:** `validate-acl` prints `OK — every registered MCP tool has its ACL resource declared.` and exits 0. It is a build gate: a failure means a tool references a permission that does not exist, and that tool is invisible to every role. Fix that before going further.

`tools:list` prints every registered tool with its ACL resource and write mode. **Note the total** — you will compare it against what the server actually serves in stage 6, and the difference is the most informative number in this whole runbook.

## Stage 4 — Choose an authentication path

| | Bearer token | OAuth 2.1 |
|---|---|---|
| Best for | local clients you configure by hand: Claude Code, Cursor, MCP Inspector, scripts | hosted clients that sign the operator in themselves: Claude web, ChatGPT |
| Setup | one command, done | register a client, then each admin consents in the browser |
| Identity | fixed: the admin you name | the admin who consented |

You can set up both. Pick bearer first if you are verifying the install, because it needs nothing from a browser.

### 4a — Bearer token

```bash
bin/magento magebit:mcp:token:create \
  --admin-user <username> \
  --name "<who or what is using this>" \
  [--allow-writes] \
  [--expires "+30 days"] \
  [-s <tool.name> -s <tool.name>]
```

The plaintext token is printed once, on the last line, and is never recoverable. `--allow-writes` is layer two of the write gate — the config toggle from stage 2 is layer one, and **both** are needed before a write tool will run. Repeat `-s` to scope the token to specific tools; with no `-s`, it can reach every tool the admin's role permits.

**Check:**

```bash
bin/magento magebit:mcp:token:list -u <username>
```

The row should read `active`, with the `Writes` and `Scopes` columns matching what you asked for.

### 4b — OAuth 2.1 client

```bash
# Known AI clients ship as presets that fill in the name and redirect URI
bin/magento magebit:mcp:oauth:client:create --list-presets
bin/magento magebit:mcp:oauth:client:create --preset claude_web --allow-all-tools
```

Spelled out, for a client with no preset:

```bash
bin/magento magebit:mcp:oauth:client:create \
  --name "<label>" \
  --redirect-uri "https://<the client's callback URL>" \
  --tool catalog.product.list --tool sales.order.get
```

The redirect URI must match the client's callback **byte for byte** and must be HTTPS (`http://localhost` and `http://127.0.0.1` are allowed for development). Get it from the client's own documentation — do not guess it.

Tool surface: repeat `--tool` for an explicit list, or pass `--allow-all-tools` to also cover tools installed later. Unknown tool names are rejected, so a typo fails loudly rather than silently narrowing the client.

Who may consent:

- Default (`--auth-mode personal`) — every admin authorizes for themselves and gets their own token. Narrow it with `--allowed-admin-user <username>` or `--allowed-admin-role "<role name>"`, repeatable.
- `--auth-mode shared --service-admin-user <username>` — every consent issues a token bound to that one admin. Use it for an organization-wide connector.

Add `--disabled` to register a client without switching it on.

The client secret is printed once. Along with it the command prints the MCP endpoint URL and the client id — those three values are what the client needs.

**Check:**

```bash
bin/magento magebit:mcp:oauth:client:list
```

Confirm the row's `Status` is `enabled`, the `Redirect URIs` cell matches the client's callback exactly, and `Mode` / `Bound admin` are what you intended. A shared-mode client showing `(unset — cannot issue tokens)` cannot complete a single consent — fix it before handing the credentials over.

## Stage 5 — Grant the admin role its permissions

This is the step most likely to be silently wrong, and you cannot do it yourself.

Every tool is gated by its own admin-role permission, so a token or OAuth consent can only reach tools the **admin's role** already holds. An admin whose role is *All* (a full administrator) needs nothing. For any narrower role, ask a human to open *System → Permissions → User Roles → \<role\> → Role Resources* and tick, under *System → MCP*:

- the individual tools that role should be able to drive, under **MCP Tools**
- **MCP OAuth — Approve Client Access** (`Magebit_Mcp::mcp_oauth_authorize`) if this admin will complete an OAuth consent. Without it the consent screen refuses them, and the client's sign-in dead-ends with nothing wrong on the client's side — an easy failure to misdiagnose
- the management permissions the role actually needs, which are deliberately separate: `Magebit_Mcp::mcp_tokens` (bearer tokens), `Magebit_Mcp::mcp_oauth_clients` (OAuth clients), `Magebit_Mcp::mcp_audit` (audit log), `Magebit_Mcp::mcp_tool_management` (switching tools on and off), `Magebit_Mcp::mcp_prompts` (prompts), `Magebit_Mcp::config` (the module's settings)

None of this is needed for a full administrator, and none of it can be granted from the command line. Stage 6 tells you whether it landed.

## Stage 6 — Verify over HTTP

Now check the endpoint the AI client will actually talk to. Set two shell variables and run the four probes in order — each one isolates a different layer, so the first failure tells you where the problem is.

```bash
BASE="https://<your-store>"     # exactly the value of web/secure/base_url
TOKEN="<the bearer token from stage 4a>"
```

**Probe 1 — the endpoint exists and demands authentication.**

```bash
curl -s -o /dev/null -D - -X POST "$BASE/mcp" \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
```

Good: `HTTP/… 401` plus a `www-authenticate: Bearer realm="…", resource_metadata="…"` header. That single line proves the request reached MCP code — routing, TLS and the module are all fine, and the server correctly refuses anonymous callers.

Bad, and what it means:

- **`301` / `302`** — you used the wrong scheme or host. The redirect target is the base URL Magento expects; use exactly that.
- **`404`** — the module is not enabled, or `setup:upgrade` has not run.
- **`503`** — `magebit_mcp/general/enabled` is `0`, or Magento is in maintenance mode.
- **Connection refused / DNS failure** — you are probing the wrong host, not a misconfigured store.

**Probe 2 — the token authenticates and the handshake completes.**

```bash
curl -s -X POST "$BASE/mcp" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"setup-check","version":"1"}}}'
```

Good: a `result` carrying `protocolVersion`, a `capabilities` block with `tools` and `prompts`, and your store's `serverInfo.name`.

`"error":{"code":-32001}` means the token is wrong, revoked, or expired — mint a fresh one rather than debugging the old one. `-32002` is the origin allowlist rejecting you: harmless from `curl`, which sends no `Origin` header, so if you see it you added one.

**Probe 3 — identity and the write gate.**

```bash
curl -s -X POST "$BASE/mcp" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Mcp-Protocol-Version: 2025-06-18' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"system.whoami","arguments":{}}}'
```

Good: `username` is the admin you named in stage 4a, and `allow_writes` is `true` only if you intended writes. This is the fastest way to confirm you are looking at the identity you think you are. `allow_writes: false` when you expected `true` means one of the two write layers is off — the config toggle from stage 2, or the token's own flag.

**Probe 4 — the tool surface, and the number that matters.**

```bash
curl -s -X POST "$BASE/mcp" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Mcp-Protocol-Version: 2025-06-18' \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/list","params":{}}'
```

Compare the number of tools here against the total from `magebit:mcp:tools:list` in stage 3. **They are usually not equal, and the gap is diagnostic**, in this order of likelihood:

1. **All the missing ones are write tools** → the write gate is closed. Expected and correct if you left `allow_writes` off; if not, see probe 3.
2. **Whole domains are missing** (every `sales.*`, say) → the admin's role lacks those tool permissions. Go back to stage 5.
3. **Scattered individual tools are missing** → either the token was scoped with `-s`, or those tools were switched off in *System → MCP → Tools*, or their underlying admin-UI permission does not resolve on this install (`validate-acl` warns about the last case).

A tool switched off is indistinguishable on the wire from one that was never installed — both are absent from `tools/list` and both answer `tools/call` with `-32010 TOOL_NOT_FOUND`. So absence alone never tells you which; check the config and the role.

One naming detail: `tools/list` returns names in the underscore form (`system_whoami`) that some hosted clients require. Both that and the canonical dotted form (`system.whoami`) are accepted by `tools/call`.

## Stage 7 — Verify OAuth, if you set it up

```bash
curl -s "$BASE/.well-known/oauth-protected-resource/mcp"
curl -s "$BASE/.well-known/oauth-authorization-server"
```

Good: two JSON documents. The first names your `/mcp` endpoint as the `resource` and lists `mcp:read` / `mcp:write` in `scopes_supported`. The second lists the `authorization_endpoint` and `token_endpoint` under your base URL, with `"code_challenge_methods_supported":["S256"]` and `"pkce_required":true`.

If the URLs in those documents do not match the base URL you are testing, the store's `web/secure/base_url` disagrees with how you are reaching it — hosted clients will follow the advertised URL and fail. Fix the base URL, not the client.

The rest of the OAuth flow needs a browser: an admin visits the client's connect button, signs in to the Magento admin, and approves the consent screen. You cannot complete or verify that from a shell, so hand it to a human. The [MCP Inspector guide](https://magebitcom.github.io/magento2-mcp-module/quick-setup/inspector.html) walks through it, and the [Connection Checker](https://magebitcom.github.io/magento2-mcp-module/quick-setup/diagnose.html) probes the same endpoints from a browser.

## Stage 8 — Hand over

Report, in plain terms:

- the endpoint URL, and which authentication path you set up
- for a bearer token: the label and the admin it belongs to — **never the token itself in a shared channel**
- for OAuth: the client id and the redirect URI you registered, and that the secret needs to reach whoever configures the client
- whether write tools are on
- the tool count from probe 4, and — if it is lower than the registered total — which of the three causes above explains it
- anything you could not do: role permissions not yet granted, the browser consent still outstanding, the origin list left at defaults

Then stop. Do not connect the client yourself, and do not commit any credential.

## Cleaning up after yourself

If you minted a token or client only to test the install, remove it:

```bash
bin/magento magebit:mcp:token:revoke <id>          # keeps the audit trail
bin/magento magebit:mcp:token:delete <id>          # removes the row

bin/magento magebit:mcp:oauth:client:set-status <id> --disabled
bin/magento magebit:mcp:oauth:client:delete <id>
```

Two revocation traps, both about tokens outliving the thing that issued them:

- **Disabling an OAuth client does not stop the tokens it already issued** — the flag only blocks new grants. `set-status --disabled` revokes them for you; the admin-UI toggle does too.
- **Deleting an OAuth client leaves its tokens behind** — the database clears the reference rather than the row. `delete` revokes them first, unless you pass `--keep-tokens`.

Rotating a client secret is the exception: it deliberately leaves live tokens working, so a client can pick up the new secret without an outage. Pass `--revoke-tokens` when the old secret may have leaked.

## Where to look when something is wrong

The codes you are most likely to meet: `-32001` unauthorized (bad, revoked or expired credential), `-32002` origin rejected, `-32004` forbidden (the role lacks the permission), `-32010` tool not found (absent or switched off), `-32012` write not allowed (one of the two write layers is off), `-32013` rate limited, `-32015` server disabled.

| Symptom | Look here |
|---|---|
| Any MCP-specific failure | `var/log/magebit_mcp.log` — the module's own channel |
| "Did my call even arrive?" | the audit log, *System → MCP → Audit Log*. One row per request, including unauthenticated attempts, so an empty log means the request never reached MCP |
| A tool errors mid-call | the audit row carries the error code and a redacted copy of the arguments |
| An unfamiliar error code | `Model/JsonRpc/ErrorCode` lists every module-specific code with its label |

Argument values in the audit log are PII-redacted before storage — do not expect to read back exactly what was sent.
