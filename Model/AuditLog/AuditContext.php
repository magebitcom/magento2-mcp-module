<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\AuditLog;

/**
 * Mutable, request-scoped bag of data the audit logger needs at flush time.
 *
 * The controller fills in the environment fields (IP, user-agent, protocol
 * version, request id, method) before the handler runs; the handler fills in
 * tool-specific details (tool name, redacted arguments, result summary,
 * duration); the logger writes one row using whatever is populated.
 *
 * Keeping a single mutable DTO beats passing 12+ args through every layer
 * and lets a failure path still emit an audit row (see Controller/Index/Index
 * `finally` block).
 */
class AuditContext
{
    /**
     * Placeholder used before the JSON-RPC envelope is parsed (or when parsing
     * fails). Guarantees the audit row lands even on pre-auth failures —
     * relied on by the NOT NULL constraint on `magebit_mcp_audit_log.method`.
     */
    public const METHOD_UNPARSED = '(request)';

    /** @var int|null */
    public ?int $tokenId = null;

    /** @var int|null */
    public ?int $adminUserId = null;

    /** @var int|string|null */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    public int|string|null $requestId = null;

    /** @var string|null */
    public ?string $protocolVersion = null;

    /** @var string */
    public string $method = self::METHOD_UNPARSED;

    /** @var string|null */
    public ?string $toolName = null;

    /**
     * @var array<int|string, mixed>|null
     */
    public ?array $arguments = null;

    /**
     * @var array<int|string, mixed>|null
     */
    public ?array $resultSummary = null;

    /** @var string */
    public string $responseStatus = 'ok';

    /** @var string|null */
    public ?string $errorCode = null;

    /** @var int|null */
    public ?int $durationMs = null;

    /** @var string|null */
    public ?string $ipAddress = null;

    /** @var string|null */
    public ?string $userAgent = null;
}
