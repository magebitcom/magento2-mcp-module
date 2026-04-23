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
use Magebit\Mcp\Exception\SchemaValidationException;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Validator\JsonSchemaValidator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NoSuchEntityException;
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
        private readonly ScopeConfigInterface $scopeConfig
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
            return Response::failure(
                $request->id,
                ErrorCode::INVALID_PARAMS,
                'Missing or invalid "name" parameter.'
            );
        }

        try {
            $tool = $this->toolRegistry->get($name);
        } catch (NoSuchEntityException) {
            return Response::failure(
                $request->id,
                ErrorCode::TOOL_NOT_FOUND,
                sprintf('Tool "%s" is not registered.', $name)
            );
        }

        $scopes = $context->token->getScopes();
        if ($scopes !== null && !in_array($name, $scopes, true)) {
            return Response::failure(
                $request->id,
                ErrorCode::FORBIDDEN,
                'Token scope does not include this tool.'
            );
        }

        if (!$this->aclChecker->isAllowed($context->adminUser, $tool->getAclResource())) {
            return Response::failure(
                $request->id,
                ErrorCode::FORBIDDEN,
                'Your admin role does not permit this tool.'
            );
        }

        if ($tool->getWriteMode() === WriteMode::WRITE) {
            $globalAllow = $this->scopeConfig->isSetFlag(self::CONFIG_ALLOW_WRITES);
            if (!$globalAllow || !$context->token->getAllowWrites()) {
                return Response::failure(
                    $request->id,
                    ErrorCode::WRITE_NOT_ALLOWED,
                    'Write tools are disabled for this server or token.'
                );
            }
        }

        $argsRaw = $request->params['arguments'] ?? [];
        if (!is_array($argsRaw)) {
            return Response::failure(
                $request->id,
                ErrorCode::INVALID_PARAMS,
                'Parameter "arguments" must be an object.'
            );
        }
        /** @var array<string, mixed> $args */
        $args = $argsRaw;

        try {
            $this->schemaValidator->validate($tool->getInputSchema(), $args);
        } catch (SchemaValidationException $e) {
            return Response::failure(
                $request->id,
                ErrorCode::SCHEMA_VALIDATION_FAILED,
                $e->getMessage(),
                ['errors' => $e->getErrors()]
            );
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
            return Response::failure(
                $request->id,
                ErrorCode::TOOL_EXECUTION_FAILED,
                $exception->getMessage()
            );
        }

        return Response::success($request->id, [
            'content' => $result->getContent(),
            'isError' => $result->isError(),
        ]);
    }
}
