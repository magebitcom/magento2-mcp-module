# Extending Magebit_Mcp — Adding a tool from another module

Third-party modules expose new MCP tools by implementing `Magebit\Mcp\Api\ToolInterface` and registering the class with the `ToolRegistry` via `etc/di.xml`. The registry is a DI-array — Magento merges contributions at compile time, so conflicts fail at `bin/magento setup:di:compile` rather than at runtime.

For a canonical satellite that ships a full catalog of read + write tools with caller-driven field selection, see `Magebit_McpOrderTools`. It demonstrates:

- The **field-resolver pattern** (`FieldResolverInterface` + `ResolverPipeline` in this module; per-entity sub-interfaces like `OrderFieldResolverInterface` in the satellite) for building tool responses out of DI-injected, 3rd-party-extendable fragments.
- The **underlying-ACL layering** (`UnderlyingAclAwareInterface`) for write tools that should refuse calls from admins who wouldn't have the equivalent permission in the admin UI.

## Step 1 — Implement `ToolInterface`

```php
<?php
declare(strict_types=1);

namespace Vendor\Module\Mcp\Tool;

use Magebit\Mcp\Api\Data\ToolResultInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Catalog\Api\ProductRepositoryInterface;

class ProductGet implements ToolInterface
{
    public const ACL_RESOURCE = 'Vendor_Module::mcp_tool_catalog_product_get';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository
    ) {
    }

    public function getName(): string
    {
        return 'catalog.product.get';
    }

    public function getTitle(): string
    {
        return 'Get Product';
    }

    public function getDescription(): string
    {
        return 'Return a product by SKU: name, price, status, visibility, stock.';
    }

    public function getInputSchema(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            ],
            'required' => ['sku'],
            'additionalProperties' => false,
        ];
    }

    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    public function getWriteMode(): WriteMode
    {
        return WriteMode::READ;
    }

    public function getConfirmationRequired(): bool
    {
        return false;
    }

    public function execute(array $args): ToolResultInterface
    {
        $product = $this->productRepository->get((string) $args['sku']);
        $payload = [
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'price' => (float) $product->getPrice(),
            'status' => (int) $product->getStatus(),
        ];

        return ToolResult::text(json_encode($payload, JSON_PRETTY_PRINT) ?: '{}', [
            'sku' => $product->getSku(),
        ]);
    }
}
```

## Step 2 — Declare the ACL resource

Every tool MUST declare its own ACL resource under a node that's NOT a descendant of `Magento_Backend::admin`'s default wide-allow resources. For clarity, group MCP tool resources under a top-level `mcp` node:

```xml
<!-- Vendor/Module/etc/acl.xml -->
<resource id="Magento_Backend::admin">
    <resource id="Magento_Backend::system">
        <resource id="Vendor_Module::mcp" title="Vendor MCP" sortOrder="250">
            <resource id="Vendor_Module::mcp_tool_catalog_product_get"
                      title="Tool: Get Product"
                      sortOrder="10"/>
        </resource>
    </resource>
</resource>
```

Admins granted this resource — and tokens minted for them — see the tool in `tools/list`; admins without it see an empty list (and `tools/call` fails with `-32004`).

> ACL resource IDs follow the XSD's letter-digit-underscore-colon-colon grammar. Dots in the MCP *tool name* (`catalog.product.get`) map to underscores in the ACL resource id (`mcp_tool_catalog_product_get`).

## Step 3 — Register the tool with the MCP registry

```xml
<!-- Vendor/Module/etc/di.xml -->
<type name="Magebit\Mcp\Model\Tool\ToolRegistry">
    <arguments>
        <argument name="tools" xsi:type="array">
            <item name="catalog.product.get" xsi:type="object">Vendor\Module\Mcp\Tool\ProductGet</item>
        </argument>
    </arguments>
</type>
```

The array key is informational — the registry keys by `$tool->getName()` and enforces uniqueness at construction time. A duplicate registration (same `getName()` from two classes) fails at `setup:di:compile`.

## Step 4 — Run `bin/magento magebit:mcp:tools:validate-acl`

This console command walks every registered tool and confirms:

1. Its `getAclResource()` resolves against the loaded ACL tree.
2. Its `getName()` matches the MCP tool-name regex `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$`.

If you see `UNKNOWN ACL RESOURCE`, your `acl.xml` didn't load — re-run `bin/magento setup:upgrade` and `bin/magento cache:clean config`.

## Hooking into the call lifecycle

For cross-cutting behavior (rate limiting, result masking, custom audit sinks) subscribe to the events:

- `magebit_mcp_tool_call_before` — params: `tool`, `arguments`, `admin_user`, `token`
- `magebit_mcp_tool_call_after` — params: `tool`, `arguments`, `result`, `exception`, `duration_ms`, `admin_user`, `token`

Example:

```xml
<!-- Vendor/Module/etc/events.xml -->
<event name="magebit_mcp_tool_call_before">
    <observer name="vendor_module_rate_limit"
              instance="Vendor\Module\Observer\RateLimitMcpTool"/>
</event>
```

Observers must treat the event params as read-only. Arguments have already been redacted for audit purposes upstream; mutating them mid-flight causes the audit row to disagree with what the tool actually ran on.

## Write tools

To ship a write tool, return `WriteMode::WRITE` from `getWriteMode()`. The dispatcher then checks:

- `magebit_mcp/general/allow_writes` is `1` in the Magento config (global kill-switch).
- The acting token has `allow_writes = 1`.

Both must pass; either fails → `-32012 Write not allowed`.

Write tools SHOULD return `getConfirmationRequired(): true` so MCP clients that support user confirmation (Claude Desktop does) prompt the human before executing. Read tools return `false`. See `Magebit_McpOrderTools` for a canonical example of both.

## Testing a tool locally

```bash
# From a token with the right ACL:
curl -s -X POST http://<host>/mcp \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <token>' \
  -H 'Mcp-Protocol-Version: 2025-06-18' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call",
       "params":{"name":"catalog.product.get","arguments":{"sku":"24-MB01"}}}'
```

The response envelope follows [MCP 2025-06-18 §Tools](https://modelcontextprotocol.io/specification/2025-06-18/server/tools):

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "content": [{ "type": "text", "text": "{...}" }],
    "isError": false
  }
}
```
