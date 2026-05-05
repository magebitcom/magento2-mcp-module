# Magento2 MCP extension

Extensible [MCP](https://modelcontextprotocol.io/specification/2025-06-18) implementation for Magento 2 stores. Connect your Magento2 store to any AI Agent - Fetch and mutate customer, product or CMS data; Fetch store reports, configuration settings and more.

Note - the base module only contains tools to interact with system data (cache, indexers, store configuration). Check available sub-modules below for additional functionality.

## Base module installation

```
composer require magebitcom/magento2-mcp-module
```

## Sub-modules

By design, the base MCP module only comes with a few system related tools, for example, tools for interacting with cache and indexers.
Additional functionality is added by MCP sub-modules (see below). You can extend these existing modules or create your own extension to add custom functionality.

### Order module
Features:
- Read and search orders, invoices, shipments, payments, order comments, credit memos
- Create invoices, shipments, shipment tracks, credit memos, order comments
- Cancel, hold or unhold orders

#### Installation

```
composer require magebitcom/mcp-module-order-tools
```

### Catalog module
Features:
- Read and search products and categories
- Create, update or delete products
- Create, update or delete categories

```
composer require magebitcom/mcp-module-catalog-tools
```

### Customer module
Features:
- Read or search customers, addresses or customer groups
- Fetch customer confirmation status
- Create, update or delete customers or addresses
- Trigger password reset or resend confirmation

```
composer require magebitcom/mcp-module-customer-tools
```

### CMS module
Features:
- Read or search CMS pages and blocks
- Create, update or delete CMS pages and blocks

```
composer require magebitcom/mcp-module-cms-tools
```

### Marketing module
Features:
- Read or search catalog rules, cart rules, coupons
- Delete, toggle and apply catalog and cart rules
- Generate or delete coupon codes

```
composer require magebitcom/mcp-module-marketing-tools
```

### Report module
Features:
- Create cart reports (products in cart, abandoned carts)
- List popular search queries or newsletter problems (bounces, send failures)
- Fetch product reviews, review counts, average ratings
- Fetch aggregated sales reports for orders, tax, invoices, shipments, refunds and coupons
- Fetch customer reports (orders, totals, new customers, online visitors)
- Fetch product reports (most viewed, bestsellers, low-stock, qty ordered, downloads)
- Fetch dashboard summary (lifetime sales, average order, revenue for a period, recent orders, top search terms, top bestsellers)
- Refresh statistics (read status, refresh recent, refresh lifetime)

```
composer require magebitcom/mcp-module-report-tools
```

### Creating custom tools
You can implement your own MCP tools by implementing the `Magebit\Mcp\Api\ToolInterface` and registering it in the `ToolRegistry` via di.xml.
See [docs/EXTENDING.md](docs/EXTENDING.md) for details.

## Auth and security

-- add some details here

## Contributing

Found a bug, have a feature suggestion or just want to help in general? Contributions are very welcome! Check out the list of active issues or submit one yourself.

---
![magebit (1)](https://github.com/user-attachments/assets/cdc904ce-e839-40a0-a86f-792f7ab7961f)

*Have questions or need help? Contact us at info@magebit.com*
