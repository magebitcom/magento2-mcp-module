<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc\Handler;

use Magebit\Mcp\Api\RateLimiterInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magebit\Mcp\Exception\SchemaValidationException;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\AuditLog\AuditContext;
use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Validator\JsonSchemaValidator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles the `tools/call` JSON-RPC method.
 *
 * Enforcement order:
 *   1. Param shape (`name`, `arguments`).
 *   2. Tool resolved from registry.
 *   3. Token scope narrows — tool name must appear if scopes is non-empty.
 *   4. ACL — admin's role must grant `tool.getAclResource()`.
 *   5. Write gate — WRITE tools need global config AND token flag.
 *   6. Input schema validation.
 *   7. Rate limiter consulted with real admin user id.
 *   8. Dispatch before/after events around `tool.execute()`.
 */
class ToolsCallHandler implements HandlerInterface
{
    private const CONFIG_ALLOW_WRITES = 'magebit_mcp/general/allow_writes';

    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AclChecker $aclChecker,
        private readonly JsonSchemaValidator $schemaValidator,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly EventManager $eventManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AuditContext $auditContext,
        private readonly LoggerInterface $logger
    ) {
    }

    public function method(): string
    {
        return 'tools/call';
    }

    public function handle(Request $request, AuthenticatedContext $context): Response
    {
        $name = $request->params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return $this->fail($request, ErrorCode::INVALID_PARAMS, 'Missing or invalid "name" parameter.');
        }
        $this->auditContext->toolName = $name;

        try {
            $tool = $this->toolRegistry->get($name);
        } catch (NoSuchEntityException) {
            return $this->fail($request, ErrorCode::TOOL_NOT_FOUND, sprintf('Tool "%s" is not registered.', $name));
        }

        $argsRaw = $request->params['arguments'] ?? [];
        if (!is_array($argsRaw)) {
            return $this->fail($request, ErrorCode::INVALID_PARAMS, 'Parameter "arguments" must be an object.');
        }
        /** @var array<string, mixed> $args */
        $args = $argsRaw;
        $this->auditContext->arguments = $args;

        $scopes = $context->token->getScopes();
        if ($scopes !== null && !in_array($name, $scopes, true)) {
            return $this->fail($request, ErrorCode::FORBIDDEN, 'Token scope does not include this tool.');
        }

        if (!$this->aclChecker->isAllowed($context->adminUser, $tool->getAclResource())) {
            return $this->fail($request, ErrorCode::FORBIDDEN, 'Your admin role does not permit this tool.');
        }

        if ($tool->getWriteMode() === WriteMode::WRITE) {
            $globalAllow = $this->scopeConfig->isSetFlag(self::CONFIG_ALLOW_WRITES);
            if (!$globalAllow || !$context->token->getAllowWrites()) {
                return $this->fail($request, ErrorCode::WRITE_NOT_ALLOWED, 'Write tools are disabled for this server or token.');
            }
        }

        try {
            $this->schemaValidator->validate($tool->getInputSchema(), $args);
        } catch (SchemaValidationException $e) {
            return $this->fail($request, ErrorCode::SCHEMA_VALIDATION_FAILED, $e->getMessage(), ['errors' => $e->getErrors()]);
        }

        $this->rateLimiter->check($context->getAdminUserId(), $name);

        $this->eventManager->dispatch('magebit_mcp_tool_call_before', [
            'tool' => $tool,
            'arguments' => $args,
            'admin_user' => $context->adminUser,
            'token' => $context->token,
        ]);

        $startedAt = microtime(true);
        try {
            $result = $tool->execute($args);
            $exception = null;
        } catch (Throwable $e) {
            $result = null;
            $exception = $e;
        }
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->auditContext->durationMs = $durationMs;

        $this->eventManager->dispatch('magebit_mcp_tool_call_after', [
            'tool' => $tool,
            'arguments' => $args,
            'result' => $result,
            'exception' => $exception,
            'duration_ms' => $durationMs,
            'admin_user' => $context->adminUser,
            'token' => $context->token,
        ]);

        if ($exception !== null) {
            // LocalizedException messages are the tool's published contract —
            // safe to surface to the client. Anything else may embed stack
            // traces, SQL fragments, or absolute paths; log it server-side and
            // hand the client a generic label.
            if ($exception instanceof LocalizedException) {
                return $this->fail($request, ErrorCode::TOOL_EXECUTION_FAILED, $exception->getMessage());
            }
            $this->logger->error('MCP tool raised unexpected exception.', [
                'tool' => $name,
                'exception' => $exception,
            ]);
            return $this->fail($request, ErrorCode::TOOL_EXECUTION_FAILED, 'Tool execution failed.');
        }

        $this->auditContext->resultSummary = $result->getAuditSummary();
        if ($result->isError()) {
            $this->auditContext->responseStatus = AuditEntryInterface::STATUS_ERROR;
        }

        return Response::success($request->id, [
            'content' => $result->getContent(),
            'isError' => $result->isError(),
        ]);
    }

    /**
     * Build a failure response and stamp audit status in one place.
     *
     * @param array<string, mixed>|null $data
     */
    private function fail(Request $request, int $code, string $message, ?array $data = null): Response
    {
        $this->auditContext->responseStatus = AuditEntryInterface::STATUS_ERROR;
        $this->auditContext->errorCode = (string) $code;
        return Response::failure($request->id, $code, $message, $data);
    }
}
