# Extending Magebit_Mcp — Adding a tool from another module

Third-party modules expose new MCP tools by implementing `Magebit\Mcp\Api\ToolInterface` and registering the class with the `ToolRegistry` via `etc/di.xml`. The registry is a DI-array — Magento merges contributions at compile time, so conflicts fail at `bin/magento setup:di:compile` rather than at runtime.

For a canonical satellite that ships a full catalog of read + write tools with caller-driven field selection, see `Magebit_McpOrderTools`. It demonstrates:

- The **field-resolver pattern** (`FieldResolverInterface` + `ResolverPipeline` in this module; per-entity sub-interfaces like `OrderFieldResolverInterface` in the satellite) for building tool responses out of DI-injected, 3rd-party-extendable fragments.
- The **underlying-ACL layering** (`UnderlyingAclAwareInterface`) for write tools that should refuse calls from admins who wouldn't have the equivalent permission in the admin UI. `tools/list` filters on it too, so such a tool is never advertised to a role that cannot call it.
- The **availability hook** (`AvailabilityAwareInterface`) for tools that need an optional module: while it is off the tool answers like an unregistered one.
- The **contextual-ACL layering** (`ContextualAclAwareInterface`, described below) for tools whose required permission is not knowable until the arguments arrive.

## Step 1 — Implement `ToolInterface`

```php
<?php
declare(strict_types=1);

namespace Vendor\Module\Mcp\Tool;

use Magebit\Mcp\Api\Data\ToolResultInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
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
        return Schema::object()
            ->string('sku', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->maxLength(64)
                ->description('Product SKU.')
                ->required()
            )
            ->toArray();
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

## Declaring input schema

Use `Magebit\Mcp\Model\Tool\Schema\Schema` — a fluent, typed builder — rather than hand-writing JSON-Schema arrays. The builder locks in the invariants this module requires of every input schema (draft-07 `$schema`, `type=object`, `additionalProperties=false`) and refuses composition keywords (`oneOf` / `anyOf` / `allOf` / `if` / `then` / `else` / `not` / `$ref` / `$defs`). That refusal is this module's own rule for input schemas, because some MCP clients and model APIs reject those keywords in tool parameters; the MCP spec (2025-06-18) itself only requires `type: "object"` at the schema root.

### Typed property builders

Type-hint the closure parameter (`fn (StringBuilder $s) => …`) — PHPStan level 9 in this module requires it, and it unlocks IDE autocomplete for the type-specific constraint methods inside the closure.

```php
use Magebit\Mcp\Model\Tool\Schema\Builder\{ArrayBuilder, BooleanBuilder, IntegerBuilder, NumberBuilder, ObjectBuilder, StringBuilder};

Schema::object()
    ->string('name', fn (StringBuilder $s) => $s
        ->minLength(1)->maxLength(255)->description('Display name.')->required()
    )
    ->integer('website_id', fn (IntegerBuilder $i) => $i->minimum(1)->description('Website scope.'))
    ->number('price', fn (NumberBuilder $n) => $n->minimum(0)->description('Gross price.'))
    ->boolean('is_active', fn (BooleanBuilder $b) => $b->description('Whether the entity is live.'))
    ->array('sku', fn (ArrayBuilder $a) => $a
        ->ofStrings(fn (StringBuilder $s) => $s->minLength(1))
        ->minItems(1)
        ->description('One or more SKUs.')
    )
    ->array('items', fn (ArrayBuilder $a) => $a->ofObjects(fn (ObjectBuilder $o) => $o
        ->string('item_id', fn (StringBuilder $s) => $s->minLength(1)->required())
        ->integer('qty', fn (IntegerBuilder $i) => $i->minimum(1)->required())
    ))
    ->object('comment', fn (ObjectBuilder $o) => $o
        ->string('text', fn (StringBuilder $s) => $s->minLength(1)->required())
        ->boolean('is_visible_on_front', fn (BooleanBuilder $b) => $b)
        ->description('Optional comment payload.')
    )
    ->toArray();
```

`->required()` on a property marks it required on the enclosing object — no separate `required` array to keep in sync. `->description()` is available at every level and belongs on every property: descriptions are what the AI client sees when choosing arguments.

### Presets for list-tool patterns

Four presets bundle the property blocks every list tool repeats. They live in `Magebit\Mcp\Model\Tool\Schema\Preset\`:

```php
use Magebit\Mcp\Model\Tool\Schema\Preset\{Filters, Sort, Pagination, FieldSelection};

public function getInputSchema(): array
{
    return Schema::object()
        ->with(Filters::describing(
            'Filter clauses. Built-in keys: status, state, store_id, website_id.'
        ))
        ->with(Sort::fields(
            OrderSearchCriteriaBuilder::SORTABLE_FIELDS,
            defaultField: 'created_at',
            defaultDirection: 'desc'
        ))
        ->with(Pagination::maxPageSize(OrderSearchCriteriaBuilder::MAX_PAGE_SIZE))
        ->with(FieldSelection::default())
        ->string('store_id', fn ($s) => $s->description('Limit to a single store view id.'))
        ->toArray();
}
```

Presets implement `Magebit\Mcp\Model\Tool\Schema\SchemaContribution`. Ship your own with a `Vendor\Module\Mcp\Schema\Preset` namespace if you have cross-tool patterns inside a single domain module.

### Escape hatch

Anything the typed DSL cannot express (an open-bag object whose keys aren't known in advance, an exotic keyword, composition for a genuinely polymorphic argument) can be injected via `ObjectBuilder::rawProperty()`:

```php
Schema::object()
    ->rawProperty('filters', [
        'type' => 'object',
        'description' => 'Open-ended filter bag.',
    ])
    ->toArray();
```

Raw schemas still flow through `SchemaSanitizer` so stray composition keywords get stripped with a logged warning — prefer fixing the schema to taking the warning. Argument validation runs against this same sanitized schema — the one `tools/list` serves over the wire — so `oneOf` / `allOf` / `anyOf` are always stripped before validation and must not be relied on for enforcement.

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

## Argument-dependent ACL — `ContextualAclAwareInterface`

`UnderlyingAclAwareInterface` returns one fixed resource, which is enough when a tool always wraps the same Magento service. It is not enough when the required permission depends on what the caller asked for — a tool that edits configuration sections, targeting `tax/calculation/…`, needs `Magento_Tax::config_tax`, and the very same tool targeting `payment/…` needs the payment section's own `<resource>`.

Implement `Magebit\Mcp\Api\ContextualAclAwareInterface` alongside `ToolInterface` on the same class:

```php
class Publish implements ToolInterface, ContextualAclAwareInterface
{
    /**
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return array<int, string>
     */
    public function getContextualAclResources(array $arguments): array
    {
        $section = $arguments['section'] ?? null;
        if (!is_string($section)) {
            return [];
        }
        $resource = $this->sectionResourceResolver->resolve($section);

        return $resource === null ? [] : [$resource];
    }
}
```

What the dispatcher does with the return value:

- **Every** entry must pass `AclChecker::isAllowed()` for the calling admin's role. The first failure short-circuits with `-32004 FORBIDDEN` and the message *"Your admin role does not permit the Magento section this call targets."*
- The gate runs **after** the tool's own ACL and any underlying ACL, and **before** input-schema validation. Arguments are coerced but not yet validated, so `$arguments['section']` may be missing, an `int`, or an array — narrow before use, as above.
- **Never throw.** A throw, a blank string, or a non-string entry is treated as a hard deny (`-32004`, with the distinct message *"Could not determine which Magento permission this call requires…"*) and logged to `var/log/magebit_mcp.log`. `['']` is **not** equivalent to `[]`.
- `[]` means "no extra gate" — the call proceeds on the strength of the tool's own ACL alone. Return it only when the tool itself refuses that input in `execute()`; otherwise the gate silently disappears. `Tool/System/ConfigSet` shows the rule: a section that declares no `<resource>` is refused in `execute()` exactly as `Structure\Element\Section::isAllowed()` refuses it in the admin UI. `ConfigSet` is a bulk tool now, so it implements `BulkContextualAclAwareInterface::getItemAclResources()` (see [Bulk tools](#bulk-tools)) instead of this interface, answers `['']` (a FORBIDDEN item) for that section, and keeps a matching refusal in `processItem()`.
- The resolver is called **once per tool call** with no caching in the dispatcher. Memoise inside your implementation if resolution is expensive.

## Refusing a call at run time — `ForbiddenException`

The ACL gates run before `execute()` and cannot see an entity's store or website. When `execute()` finds that the caller may not act on the entity, throw `Magebit\Mcp\Exception\ForbiddenException`:

```php
use Magebit\Mcp\Exception\ForbiddenException;

if (!$this->scopeChecker->canEditStore($admin, $page->getStoreId())) {
    throw new ForbiddenException(
        'This page belongs to a store view outside your admin scope.',
        ['reason' => 'scope', 'page_id' => (int) $page->getId()]
    );
}
```

For a missing permission, pass `['reason' => 'acl', 'resource' => 'Vendor_Module::resource_id']`.

The client gets `-32004 FORBIDDEN` with your message, and `$data` as `error.data` (omitted when empty). The call is audited like an ACL refusal and not logged. Both reach the client verbatim, so keep internals out of them.

- **`ForbiddenException`**: a scope or permission refusal.
- **Soft error (`isError: true`)**: the call was allowed but cannot be done, for example a missing entity or an unmet precondition.
- **`LocalizedException`**: `-32011` with its message. Any other exception is `-32011` with a generic message and is logged.

## Tools that need an optional module — `AvailabilityAwareInterface`

A tool that wraps an optional module (one that may be missing or disabled on a store) should implement `Magebit\Mcp\Api\AvailabilityAwareInterface` next to `ToolInterface`:

```php
use Magebit\Mcp\Api\AvailabilityAwareInterface;

public function isAvailable(): bool
{
    return $this->moduleManager->isEnabled('Vendor_OptionalFeature');
}
```

While `isAvailable()` returns `false` the tool counts as not installed, for every role: `tools/list` hides it and `tools/call` answers `-32010 TOOL_NOT_FOUND` ("Tool \"…\" is not registered."), the same answer as an unregistered or admin-disabled tool. The check runs before the token scope and ACL gates, so the client is never told that the admin role is the problem. `ToolInterface` is unchanged; tools that do not implement the interface are always available.

A tool whose `getUnderlyingAclResource()` returns a resource that no `acl.xml` declares gets the same answer: no role can hold an undeclared resource, so the tool cannot run for anyone. Older children use this to hide a tool while its module is off; prefer the interface in new code. `magebit:mcp:tools:validate` still lists such resources as not resolving.

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

## Registering a domain label (optional)

The first dotted segment of a tool name is its **domain** (`catalog.product.get` → `catalog`). The domain drives two presentation surfaces: the `Domain: Title` prefix on `tools/list` display titles, and the grouping in the OAuth consent tree. `Magebit\Mcp\Model\Util\ToolDomain` resolves a domain key to its label from a DI-injected map, falling back to `ucfirst()` for anything unmapped — so a new domain works out of the box (`inventory` → "Inventory").

Register a label only when `ucfirst()` gets it wrong (acronyms, special casing). The `labels` argument is a merged array, so your `di.xml` adds to the core map without replacing it:

```xml
<!-- Vendor/Module/etc/di.xml -->
<type name="Magebit\Mcp\Model\Util\ToolDomain">
    <arguments>
        <argument name="labels" xsi:type="array">
            <item name="msi" xsi:type="string">MSI</item>
        </argument>
    </arguments>
</type>
```

A later-loaded module reusing an existing key (e.g. `cms`) overrides it; order follows `module.xml` `<sequence>`.

## Step 4 — Run `bin/magento magebit:mcp:tools:validate`

This console command (the old name `magebit:mcp:tools:validate-acl` is kept as an alias through 2.x) walks every registered tool and confirms:

1. Its `getAclResource()` resolves against the loaded ACL tree.
2. Its `getName()` matches the MCP tool-name regex `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$`.
3. No tool description names a tool with dots (agent-facing text uses `catalog_product_get`).
4. Every bulk tool meets the contract: its underlying ACL resource is declared, its key field is in the item schema, and `1 <= sync cap <= async ceiling`.

If you see `UNKNOWN ACL RESOURCE`, your `acl.xml` didn't load — re-run `bin/magento setup:upgrade` and `bin/magento cache:clean config`.

## Bulk tools

A tool that changes many records in one call (price updates, stock changes, config writes) implements `Magebit\Mcp\Api\BulkToolInterface` instead of `ToolInterface`. You write the item schema and `processItem()`. The core writes everything around it:

- the call's input schema (`items` with `minItems: 1` and `maxItems` set to the async ceiling, plus `dry_run`);
- the loop, the per-item results and the envelope;
- running a batch above the sync cap as a background job (see the README's "Background jobs").

`BulkToolInterface` extends `ToolDescriptorInterface` (name, description, ACL, write mode and so on) and `UnderlyingAclAwareInterface`. It does **not** extend `ToolInterface`: do not implement `getInputSchema()` or `execute()`. Register the class in the same `ToolRegistry` `tools` array as any other tool (Step 3); the core wraps it in `BulkToolAdapter`.

```php
class PriceUpdate implements BulkToolInterface
{
    // getName(), getDescription(), getAclResource(), getWriteMode() ... as for a normal tool
    public function getItemSchema(): array { return $this->schema->toArray(); } // one item: sku, price
    public function getItemKeyField(): string { return 'sku'; }                  // echoed as `key` in every row
    public function getSyncCap(): int { return 100; }                            // run immediately up to here
    public function getAsyncCeiling(): int { return 1000; }                      // accepted at all; above the cap it is a job
    public function getUnderlyingAclResource(): string { return 'Magento_Catalog::products'; } // mandatory

    public function processItem(array $item, bool $dryRun): ItemResult
    {
        try {
            $product = $this->products->get($item['sku']);
        } catch (NoSuchEntityException $e) {
            return ItemResult::error(BulkErrorCode::NOT_FOUND, 'No product with this SKU.');
        }
        if (!$dryRun) { $product->setPrice($item['price']); $this->products->save($product); }
        return ItemResult::ok((int) $product->getId());
    }
}
```

**`processItem` and the result rows.** The schema validator has already checked the item against `getItemSchema()`. Return `ItemResult::ok($id, $message)`, `ItemResult::error($code, $message)` or `ItemResult::skipped($code, $message)` (nothing needed changing). Core error codes are in `Model\Tool\Bulk\BulkErrorCode`: `VALIDATION_FAILED`, `NOT_FOUND`, `FORBIDDEN`, `CONFLICT`, `ITEM_FAILED`, `CANCELLED`, `INTERRUPTED` (set by the core only, see below); a module may add its own in the same upper snake case. Return `NOT_FOUND`, `VALIDATION_FAILED` and `CONFLICT` yourself: the executor does not map exceptions to them (a `NoSuchEntityException` left uncaught becomes `ITEM_FAILED` with its message). Exceptions from `processItem()` are caught per item, and the rest of the batch still runs:

| Thrown | Row |
|---|---|
| `Magebit\Mcp\Exception\ForbiddenException` | `FORBIDDEN` with its message |
| `Magento\Framework\Exception\LocalizedException` | `ITEM_FAILED` with its message |
| anything else | `ITEM_FAILED` "Item failed.", the detail goes to `var/log/magebit_mcp.log` |

**The envelope.** Every call returns `{ dry_run, summary: { total, ok, failed, skipped }, results: [ { index, key, status, error_code, message, id } ] }`, as one text block of pretty JSON and as `structuredContent`. `isError` is true only when every item failed. `tools/list` advertises the matching `outputSchema`. `structuredContent` and `outputSchema` are sent only to sessions on protocol `2025-06-18`; `2025-03-26` sessions get the text block alone. The audit row holds the `summary` plus `item_count`, and per-item detail when the tool gives it (see below).

**The dry-run duty.** When `$dryRun` is true, `processItem()` must check the item as fully as it can and **not save anything**. The core cannot roll a save back generically (emails, files and indexers would already have fired), so a tool that ignores `$dryRun` writes during a dry run. State in your tool's description what a dry run does not check (`system.config.set` runs the field's backend model up to `beforeSave()` but cannot catch a check Magento makes only in `afterSave()`, such as the currency settings).

**Jobs run each item at most once.** In a background job the core writes an in-flight marker for the item before it calls `processItem()`. An item whose worker stopped mid-item (the worker died, or its result could not be saved) may already have been applied, so it is never run again: it is reported as `error` / `INTERRUPTED` and the resume continues with the next item. Your tool needs no idempotency key for this, but an agent may send such an item again in a new call, so say in your description what a repeated item does.

**Hooks (optional).** Work done once per call, such as one reindex or one cache clean, goes in `Magebit\Mcp\Api\BulkToolLifecycleInterface`:

```php
class PriceUpdate implements BulkToolInterface, BulkToolLifecycleInterface
{
    public function beforeBatch(array $items, bool $dryRun): void { /* e.g. take a lock */ }
    public function afterBatch(BatchResult $result, bool $dryRun): void
    {
        if (!$dryRun && $result->summary()['ok'] > 0) { $this->indexer->invalidate(); }
    }
}
```

In a job the hooks run once per job run. On a **resume**, `beforeBatch` receives the full item list again, while `afterBatch` sees only the results of that run. A throw from `beforeBatch` fails the job. The worker refreshes the job's heartbeat right before `afterBatch`, so `afterBatch` in a job must finish within the 10-minute stale timeout, or the stale cron marks the job `failed`. `afterBatch` is not guaranteed in a job: a run that loses its job to the stale cron skips it, so a lock taken in `beforeBatch` needs an expiry time, and follow-up work must be safe to repeat (a resume runs `afterBatch` again).

**Per-item ACL (optional).** When the permission depends on the item (a store scope, a config section), implement `Magebit\Mcp\Api\BulkContextualAclAwareInterface::getItemAclResources(array $item): array`. Every returned resource must pass for the admin's role. A denied item becomes `FORBIDDEN` and the rest proceed. An empty list means no extra check; a blank entry (`''`) or a throw refuses the item as `FORBIDDEN`, so return `[]` only where `processItem()` itself refuses the input. This interface replaces `ContextualAclAwareInterface`, which stays for non-bulk tools.

**Per-item audit detail (optional).** By default the audit row of a bulk call says only how many items succeeded. When an auditor needs to know what each item changed, and from what to what, implement `Magebit\Mcp\Api\BulkItemAuditInterface::getItemAuditDetail(array $item, ItemResult $result, bool $dryRun): ?array`. The core calls it right after each item is processed, for dry runs too, and adds `items: [ { index, key, status, detail } ]` to the audit summary of the call (and of each job run, with that run's items only); `detail` is your return value, and an item for which you return `null` is left out.

```php
class PriceUpdate implements BulkToolInterface, BulkItemAuditInterface
{
    public function getItemAuditDetail(array $item, ItemResult $result, bool $dryRun): ?array
    {
        // $this->oldPrices was filled by processItem(); never read anything a refused item was not allowed to read.
        return ['sku' => $item['sku'], 'previous' => $this->oldPrices[$item['sku']] ?? null, 'price' => $item['price']];
    }
}
```

- **What goes in `detail`.** Only what an auditor needs: the target, the value before, the value written (or, on a dry run, the value that would be written). No whole entities and no secrets. `system.config.set` is the reference: `{path, scope, scope_code, previous, value}`, without `previous` for an item refused before its current value was read, and with `value` recorded as `[sensitive]` for a path its sensitivity guard refuses (the redactor cannot recognise a password or an API key, so mask such values yourself). This masks the summary only; the raw request arguments in `arguments_json` are redacted by key name as before.
- **Budget.** The audit summary (`result_summary_json`) has a 32 KiB budget of its own; the arguments stay at 4 KiB. When the entries do not fit, the core keeps the leading entries that do and adds `items_truncated: <count left out>`, so the JSON stays valid. Any other audit payload over its budget is stored as `{"__truncated__": true, "original_bytes": N, "head": "<start of the encoded JSON>"}`: both columns are JSON columns, and invalid JSON would lose the whole row. A job run also stops holding entries once it has collected about twice the budget. Plan for roughly `32768 / sync cap` bytes per entry, overhead included (about 650 bytes at a cap of 50).
- **PII.** The detail goes through the same PII redactor as the rest of the audit row (key names such as `email`, `token` or `password` are fingerprinted, and e-mail addresses, phone numbers and similar patterns inside values are caught by the text pass), but the redactor is a last line of defence: leave personal data out of `detail` where you can.
- A throw from `getItemAuditDetail()` is logged and leaves that item out of the audit; it never changes the item's result. Tools without the interface keep their summary exactly as before.

**Snapshot provider (optional).** A tool whose job can change a lot of data may export the state it is about to change. Implement `Magebit\Mcp\Api\JobSnapshotProviderInterface` (`getFormat()`, for example `json` or `csv`, and `export(BulkToolInterface $tool, array $items): string`) and register it in `Magebit\Mcp\Model\Job\SnapshotProviderPool`'s `providers` array, keyed by tool name:

```xml
<type name="Magebit\Mcp\Model\Job\SnapshotProviderPool">
    <arguments>
        <argument name="providers" xsi:type="array">
            <item name="catalog.product.price_update" xsi:type="object">Vendor\Module\Mcp\PriceSnapshot</item>
        </argument>
    </arguments>
</type>
```

It runs on the first run of a non-dry-run **job** only, never for synchronous calls or dry runs, and at most once per job: a job resumed before its first item finished keeps the snapshot it already has. A throw fails the job before any item runs; a `LocalizedException` message is shown to the agent. The core stores the payload; it does not build a rollback.

**Caps that fit 256 KiB.** The request body is capped at 256 KiB by default (*General -> Max Request Body*), and a full batch is a single request. Choose the async ceiling so that the whole `tools/call` request, `ceiling x your largest realistic item` plus the JSON-RPC envelope, the tool name and `dry_run`, stays within 262144 bytes of JSON (measured with escaped slashes and unicode, the larger encoding). A product item with a 2 KB description and a 1000 ceiling does not fit; lower the ceiling or shorten the item. The cap must satisfy `1 <= sync cap <= async ceiling`; equal values mean the tool is synchronous only (`system.config.set` uses 50 and 50). `BulkToolContractTestCase` checks both for you.

## Add these two tests to your module

The core ships two abstract PHPUnit test cases in its `Test/` directory (autoloaded with the package, not export-ignored). Subclass them in your module's `Test/Unit/`:

```php
class WireNameTextTest extends \Magebit\Mcp\Test\Unit\Contract\WireNameTextTestCase
{
    protected function moduleDir(): string { return dirname(__DIR__, 2); } // the directory holding etc/di.xml
    // Optional: protected function extraDomainPrefixes(): array { return ['loyalty']; }
}

class PriceUpdateContractTest extends \Magebit\Mcp\Test\Unit\Contract\BulkToolContractTestCase
{
    protected function bulkToolSamples(): array
    {
        return [['tool' => $this->buildPriceUpdateTool(), 'sample' => ['sku' => str_repeat('x', 64), 'price' => 1999.99]]];
    }
    protected function declaredAclIds(): array
    {
        return array_merge($this->aclIdsFromFile(dirname(__DIR__, 2) . '/etc/acl.xml'), ['Magento_Catalog::products']);
    }
}
```

- `WireNameTextTestCase` fails when agent-facing text (tool descriptions, prompt bodies, messages) names a tool with dots. It flags your registered names and any string with three or more dotted segments whose first segment is a suite domain prefix (`catalog`, `sales`, `customer`, `cms`, `system`, `inventory`, `tax`, `marketing`, `reports`, `directory`, `hyva`, `google_analytics`, `db`). That catches an inventory module naming a catalog tool in CI, where the catalog module is not installed. Add your own prefixes with `extraDomainPrefixes()`.
- `BulkToolContractTestCase` takes one realistic **largest** item per bulk tool and asserts: the underlying ACL resource is declared (list the ids your `acl.xml` and the Magento modules you depend on declare), the key field is in the item schema, `sync cap <= async ceiling`, and a full batch at the ceiling JSON-encodes below 256 KiB. With an empty `bulkToolSamples()` it is skipped.

The store command `magebit:mcp:tools:validate` runs the same rules over every registered tool, without the size check (it has no sample).

## Hooking into the call lifecycle

For cross-cutting behavior (result masking, custom audit sinks, bespoke throttling on top of the shipped limiter) subscribe to the events:

- `magebit_mcp_tool_call_before` — params: `tool`, `arguments`, `admin_user`, `token`
- `magebit_mcp_tool_call_after` — params: `tool`, `arguments`, `result`, `exception`, `duration_ms`, `admin_user`, `token`

Example:

```xml
<!-- Vendor/Module/etc/events.xml -->
<event name="magebit_mcp_tool_call_before">
    <observer name="vendor_module_audit_sink"
              instance="Vendor\Module\Observer\StreamMcpToolCallToSiem"/>
</event>
```

Observers must treat the event params as read-only. Arguments have already been redacted for audit purposes upstream; mutating them mid-flight causes the audit row to disagree with what the tool actually ran on.

## Swapping the rate limiter

The module ships `Magebit\Mcp\Model\RateLimiter\ConfigurableRateLimiter` — a fixed-window-per-minute limiter that keys counters per `(admin_user_id, tool_name)` via Magento's cache frontend. It is wired through the `Magebit\Mcp\Api\RateLimiterInterface` DI preference and configured from Stores → Configuration → Magebit → MCP Server → **Rate Limiting**. Default: off, so existing deployments retain unlimited throughput until an operator opts in.

To supply a different algorithm (sliding-window log, token bucket, Redis `INCR` with atomic semantics, …), implement `RateLimiterInterface::check()` and override the preference:

```xml
<!-- Vendor/Module/etc/di.xml -->
<preference for="Magebit\Mcp\Api\RateLimiterInterface"
            type="Vendor\Module\Model\RateLimiter\MyRateLimiter"/>
```

Throw `Magebit\Mcp\Exception\RateLimitedException($message, $limit, $retryAfterSeconds)` from `check()` to surface as a `-32013 RATE_LIMITED` JSON-RPC error with `data.limit` and `data.retry_after_seconds`. To disable the interface entirely (e.g. in a test environment) swap in `Magebit\Mcp\Model\RateLimiter\NoOpRateLimiter`, which is retained as the documented escape hatch.

## Write tools

To ship a write tool, return `WriteMode::WRITE` from `getWriteMode()`. The dispatcher then checks:

- `magebit_mcp/general/allow_writes` is `1` in the Magento config (global kill-switch).
- The acting token has `allow_writes = 1`.

Both must pass; either fails → `-32012 Write not allowed`, with `error.data.reason` = `store_setting` (the config switch, checked first) or `token`.

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
