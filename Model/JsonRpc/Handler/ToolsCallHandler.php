<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc\Handler;

use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\RateLimiterInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Exception\RateLimitedException;
use Magebit\Mcp\Exception\SchemaValidationException;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\AuditLog\AuditContext;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Validator\JsonSchemaValidator;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Throwable;

/**
 * Handles `tools/call`. The pipeline is a chain of named gates — each returns
 * `null` to let the next gate run, or a {@see Response} to short-circuit.
 *
 * Order:
 *   1. Resolve params + tool from registry.
 *   2. Token scope narrows allowed tools.
 *   3. ACL — admin role + optional underlying-Magento ACL.
 *   4. Write gate (WRITE tools require both server config and token flag).
 *   5. Input schema validation.
 *   6. Rate limiter.
 *   7. Dispatch with before/after events.
 */
class ToolsCallHandler implements HandlerInterface
{
    /**
     * @param ToolRegistryInterface $toolRegistry
     * @param AclChecker $aclChecker
     * @param JsonSchemaValidator $schemaValidator
     * @param RateLimiterInterface $rateLimiter
     * @param EventManager $eventManager
     * @param ModuleConfig $config
     * @param AuditContext $auditContext
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AclChecker $aclChecker,
        private readonly JsonSchemaValidator $schemaValidator,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly EventManager $eventManager,
        private readonly ModuleConfig $config,
        private readonly AuditContext $auditContext,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function method(): string
    {
        return 'tools/call';
    }

    /**
     * @inheritDoc
     */
    public function handle(Request $request, AuthenticatedContext $context): Response
    {
        $resolution = $this->resolveTool($request);
        if ($resolution instanceof Response) {
            return $resolution;
        }
        [$tool, $args] = $resolution;

        foreach ([
            fn (): ?Response => $this->checkTokenScope($request, $context, $tool),
            fn (): ?Response => $this->checkAcl($request, $context, $tool),
            fn (): ?Response => $this->checkWriteGate($request, $context, $tool),
            fn (): ?Response => $this->validateInputSchema($request, $tool, $args),
            fn (): ?Response => $this->checkRateLimit($request, $context, $tool),
        ] as $gate) {
            $response = $gate();
            if ($response !== null) {
                return $response;
            }
        }

        return $this->dispatchTool($request, $context, $tool, $args);
    }

    /**
     * @param Request $request
     * @return Response|array{0: ToolInterface, 1: array<string, mixed>}
     */
    private function resolveTool(Request $request): Response|array
    {
        $requested = $request->params['name'] ?? null;
        if (!is_string($requested) || $requested === '') {
            return $this->fail($request, ErrorCode::INVALID_PARAMS, 'Missing or invalid "name" parameter.');
        }
        // Stamp the audit row with what the client sent before resolving — if the name
        // doesn't map to a tool, operators still see the verbatim request.
        $this->auditContext->toolName = $requested;

        $canonical = $this->toolRegistry->getCanonicalName($requested);
        if ($canonical === null) {
            return $this->fail(
                $request,
                ErrorCode::TOOL_NOT_FOUND,
                sprintf('Tool "%s" is not registered.', $requested)
            );
        }
        try {
            $tool = $this->toolRegistry->get($canonical);
        } catch (NoSuchEntityException) {
            return $this->fail(
                $request,
                ErrorCode::TOOL_NOT_FOUND,
                sprintf('Tool "%s" is not registered.', $requested)
            );
        }
        $this->auditContext->toolName = $canonical;

        $argsRaw = $request->params['arguments'] ?? [];
        if (!is_array($argsRaw)) {
            return $this->fail($request, ErrorCode::INVALID_PARAMS, 'Parameter "arguments" must be an object.');
        }
        /** @var array<string, mixed> $args */
        $args = $argsRaw;
        $this->auditContext->arguments = $args;

        return [$tool, $args];
    }

    /**
     * @param Request $request
     * @param AuthenticatedContext $context
     * @param ToolInterface $tool
     * @return Response|null
     */
    private function checkTokenScope(Request $request, AuthenticatedContext $context, ToolInterface $tool): ?Response
    {
        $scopes = $context->token->getScopes();
        if ($scopes !== null && !in_array($tool->getName(), $scopes, true)) {
            return $this->fail($request, ErrorCode::FORBIDDEN, 'Token scope does not include this tool.');
        }
        return null;
    }

    /**
     * @param Request $request
     * @param AuthenticatedContext $context
     * @param ToolInterface $tool
     * @return Response|null
     */
    private function checkAcl(Request $request, AuthenticatedContext $context, ToolInterface $tool): ?Response
    {
        if (!$this->aclChecker->isAllowed($context->adminUser, $tool->getAclResource())) {
            return $this->fail($request, ErrorCode::FORBIDDEN, 'Your admin role does not permit this tool.');
        }

        if ($tool instanceof UnderlyingAclAwareInterface) {
            $underlying = $tool->getUnderlyingAclResource();
            if ($underlying !== null
                && !$this->aclChecker->isAllowed($context->adminUser, $underlying)
            ) {
                // Preserves the "MCP cannot do what the admin UI cannot" invariant.
                return $this->fail(
                    $request,
                    ErrorCode::FORBIDDEN,
                    'Your admin role does not permit the underlying Magento action.'
                );
            }
        }
        return null;
    }

    /**
     * @param Request $request
     * @param AuthenticatedContext $context
     * @param ToolInterface $tool
     * @return Response|null
     */
    private function checkWriteGate(Request $request, AuthenticatedContext $context, ToolInterface $tool): ?Response
    {
        if ($tool->getWriteMode() !== WriteMode::WRITE) {
            return null;
        }
        if (!$this->config->isAllowWrites() || !$context->token->getAllowWrites()) {
            return $this->fail(
                $request,
                ErrorCode::WRITE_NOT_ALLOWED,
                'Write tools are disabled for this server or token.'
            );
        }
        return null;
    }

    /**
     * @param Request $request
     * @param ToolInterface $tool
     * @param array<string, mixed> $args
     * @return Response|null
     */
    private function validateInputSchema(Request $request, ToolInterface $tool, array $args): ?Response
    {
        try {
            $this->schemaValidator->validate($tool->getInputSchema(), $args);
        } catch (SchemaValidationException $e) {
            return $this->fail(
                $request,
                ErrorCode::SCHEMA_VALIDATION_FAILED,
                $e->getMessage(),
                ['errors' => $e->getErrors()]
            );
        }
        return null;
    }

    /**
     * @param Request $request
     * @param AuthenticatedContext $context
     * @param ToolInterface $tool
     * @return Response|null
     */
    private function checkRateLimit(Request $request, AuthenticatedContext $context, ToolInterface $tool): ?Response
    {
        try {
            $this->rateLimiter->check($context->getAdminUserId(), $tool->getName());
        } catch (RateLimitedException $e) {
            return $this->fail(
                $request,
                ErrorCode::RATE_LIMITED,
                $e->getMessage(),
                [
                    'limit' => $e->getLimit(),
                    'retry_after_seconds' => $e->getRetryAfterSeconds(),
                ]
            );
        }
        return null;
    }

    /**
     * @param Request $request
     * @param AuthenticatedContext $context
     * @param ToolInterface $tool
     * @param array<string, mixed> $args
     * @return Response
     */
    private function dispatchTool(
        Request $request,
        AuthenticatedContext $context,
        ToolInterface $tool,
        array $args
    ): Response {
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
            // LocalizedException messages are the tool's published contract — safe to
            // surface. Anything else may embed stack traces / SQL / paths; log + generic.
            if ($exception instanceof LocalizedException) {
                return $this->fail($request, ErrorCode::TOOL_EXECUTION_FAILED, $exception->getMessage());
            }
            $this->logger->error('MCP tool raised unexpected exception.', [
                'tool' => $tool->getName(),
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
     * Stamp audit state and build a failure response in one place.
     *
     * @param Request $request
     * @param ErrorCode $code
     * @param string $message
     * @param array<string, mixed>|null $data
     * @return Response
     */
    private function fail(Request $request, ErrorCode $code, string $message, ?array $data = null): Response
    {
        $this->auditContext->responseStatus = AuditEntryInterface::STATUS_ERROR;
        $this->auditContext->errorCode = (string) $code->value;
        return Response::failure($request->id, $code, $message, $data);
    }
}
