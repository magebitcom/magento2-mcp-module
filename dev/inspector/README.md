# MCP Inspector — local debugging UI

The [MCP Inspector](https://github.com/modelcontextprotocol/inspector) is the official interactive client for poking at MCP servers: a web UI that lists tools, calls them with arbitrary arguments, and shows the JSON-RPC envelopes on the wire. Use it when curl-driven smoke testing stops being enough (e.g. when iterating on input schemas, or debugging a client complaint).

## Requirements

- Node.js ^22.7.5 on your host (the Inspector refuses older versions; `node --version` to check).
- A running Magebit_Mcp dev instance reachable from your host (`https://magento-demo.docker/mcp` by default).
- A bearer token minted for your admin user.

## Mint a token

```bash
d/magento magebit:mcp:token:create --admin-user=<your-username> --name=inspector
```

Copy the 64-char plaintext from the "Bearer token" output — it's shown exactly once.

## Launch the Inspector

From the Magento root:

```bash
npx @modelcontextprotocol/inspector --config app/code/Magebit/Mcp/dev/inspector/mcp.json --server magebit-mcp-local
```

The Inspector prints two things you'll need:
- A **session token** — copy it; the browser tab auto-opens with the token pre-filled.
- The **web UI URL** at `http://localhost:6274`.

In the UI:
1. Transport is already set to `streamable-http` from the config preset.
2. Paste your Magebit_Mcp bearer token into the "Authentication" field (the Inspector sends it as `Authorization: Bearer <token>`).
3. Click "Connect". The initialize handshake runs; the Tools tab lights up.

## Self-signed HTTPS (`*.docker` hostnames)

If the Magento dev instance uses a self-signed Traefik cert, the Inspector's Node proxy will refuse the upstream TLS handshake. Work around it per-process:

```bash
NODE_TLS_REJECT_UNAUTHORIZED=0 \
  npx @modelcontextprotocol/inspector \
  --config app/code/Magebit/Mcp/dev/inspector/mcp.json \
  --server magebit-mcp-local
```

Dev-only. Do not export this globally or set it in CI — it turns off all cert validation in the Inspector's Node process.

## Changing upstream URL or port

Edit `mcp.json` or override the preset:

```bash
# Point at a staging host:
npx @modelcontextprotocol/inspector \
  --config app/code/Magebit/Mcp/dev/inspector/mcp.json \
  --server magebit-mcp-local \
  --url https://staging.example.com/mcp

# Change the UI / proxy ports (defaults 6274 / 6277):
CLIENT_PORT=6280 SERVER_PORT=6281 npx @modelcontextprotocol/inspector ...
```

## What to try once connected

- **`tools/list`** — the UI fetches it automatically on connect. Scan which tools the current admin's ACL grants (empty list is the signal that the ACL isn't wired for your role — not a bug).
- **`tools/call sales.order.get`** with `{"increment_id": "000000001"}` — round-trips the built-in tool.
- **Malformed inputs** — toggle "Show raw request" in the UI to watch `-32014` schema-validation errors come back with the `errors` payload.

Every Inspector-driven call is captured in the admin audit grid at `System → MCP → Audit Log`; use it to confirm the `token_id`, `duration_ms`, and redacted `arguments_json` match what you expect.

## CLI mode (no UI)

For scripted tests — pass `--transport http` so the Inspector uses streamable HTTP rather than guessing stdio:

```bash
NODE_TLS_REJECT_UNAUTHORIZED=0 \
  npx -y @modelcontextprotocol/inspector \
  --cli https://magento-demo.docker/mcp \
  --transport http \
  --header "Authorization: Bearer <token>" \
  --method tools/list

# Call a tool:
NODE_TLS_REJECT_UNAUTHORIZED=0 \
  npx -y @modelcontextprotocol/inspector \
  --cli https://magento-demo.docker/mcp \
  --transport http \
  --header "Authorization: Bearer <token>" \
  --method tools/call \
  --tool-name sales.order.get \
  --tool-arg increment_id=000000001
```

The Inspector injects its own `Mcp-Protocol-Version` header, so you don't need to add one yourself. If you do, the server tolerates the duplicate folded value (`"2025-06-18, 2025-06-18"`) — see `ProtocolVersionValidator`.

Useful in CI pipelines — returns JSON on stdout, non-zero exit on transport errors.
