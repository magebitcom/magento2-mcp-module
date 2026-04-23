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
use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;
use Magebit\Mcp\Model\Validator\JsonSchemaValidator;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Throwable;

/**
 * Handles the `tools/call` JSON-RPC method.
 *
 * Runs in this order:
 *   1. Validate params shape (`name` + `arguments`).
 *   2. Resolve tool from registry (404-equivalent on miss).
 *   3. Validate `arguments` against the tool's JSON Schema.
 *   4. Consult the rate limiter (no-op by default).
 *   5. Dispatch `magebit_mcp_tool_call_before` — observers may mutate args.
 *   6. Invoke tool; catch Throwable so observers still see the after event.
 *   7. Dispatch `magebit_mcp_tool_call_after` with result or exception.
 *   8. Return MCP content blocks or a JSON-RPC error.
 *
 * Phase 4 will add: ACL check (admin role grants the tool's resource) and
 * write-mode gate (global config + token flag) immediately after step 2.
 */
class ToolsCallHandler implements HandlerInterface
{
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly JsonSchemaValidator $schemaValidator,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly EventManager $eventManager
    ) {
    }

    public function method(): string
    {
        return 'tools/call';
    }

    public function handle(Request $request): Response
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

        // Phase 4 will supply the real admin user id here.
        $this->rateLimiter->check(0, $name);

        $this->eventManager->dispatch('magebit_mcp_tool_call_before', [
            'tool' => $tool,
            'arguments' => $args,
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
