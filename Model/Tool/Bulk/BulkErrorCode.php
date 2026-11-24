<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Bulk;

/**
 * Per-item error codes reported in bulk result rows.
 */
final class BulkErrorCode
{
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const NOT_FOUND = 'NOT_FOUND';
    public const FORBIDDEN = 'FORBIDDEN';
    public const CONFLICT = 'CONFLICT';
    public const ITEM_FAILED = 'ITEM_FAILED';
    public const CANCELLED = 'CANCELLED';
    /**
     * A background job's worker stopped while the item ran, so it may already have been applied. Never repeated.
     */
    public const INTERRUPTED = 'INTERRUPTED';
}
