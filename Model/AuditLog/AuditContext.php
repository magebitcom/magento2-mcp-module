<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
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
    public ?int $tokenId = null;
    public ?int $adminUserId = null;
    public int|string|null $requestId = null;
    public ?string $protocolVersion = null;
    public ?string $method = null;
    public ?string $toolName = null;

    /** @var array<int|string, mixed>|null */
    public ?array $arguments = null;

    /** @var array<int|string, mixed>|null */
    public ?array $resultSummary = null;

    public string $responseStatus = 'ok';
    public ?string $errorCode = null;
    public ?int $durationMs = null;
    public ?string $ipAddress = null;
    public ?string $userAgent = null;
}
