<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

use Magebit\Mcp\Model\Tool\Bulk\ItemResult;

/**
 * Optional for a bulk tool: what one item did, kept in the audit row.
 *
 * When a bulk tool implements it, the audit summary of a call (and of each job run) gains
 * `items: [{index, key, status, detail}]`, with `detail` as returned here; an item whose detail is null is left out.
 * The core calls it right after the item was processed, for dry runs too. The summary has a 32 KiB budget: when the
 * entries do not fit, the leading ones that do are kept and `items_truncated` counts the rest. The detail goes
 * through the same PII redactor as the rest of the audit row, but keep it to what an auditor needs (what changed,
 * from what to what) and never put secrets in it: the redactor matches key names and patterns such as e-mail
 * addresses, not passwords or API keys, so mask those yourself.
 */
interface BulkItemAuditInterface
{
    /**
     * @param array $item
     * @phpstan-param array<string, mixed> $item
     * @param ItemResult $result The item's result, as returned by processItem or set by the core.
     * @param bool $dryRun
     * @return array|null Null leaves the item out of the audit summary.
     * @phpstan-return array<string, mixed>|null
     */
    public function getItemAuditDetail(array $item, ItemResult $result, bool $dryRun): ?array;
}
