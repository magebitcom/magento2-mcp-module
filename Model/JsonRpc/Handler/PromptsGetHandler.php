<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc\Handler;

use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magebit\Mcp\Api\PromptRegistryInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\AuditLog\AuditContext;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;
use Magebit\Mcp\Model\Prompt\PromptRenderer;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Handles the `prompts/get` JSON-RPC method. Resolves the prompt by name,
 * enforces the same write gate as {@see PromptsListHandler} (so a client that
 * cached a stale list can't bypass filtering by issuing a direct get),
 * validates required arguments, and delegates to {@see PromptRenderer} for
 * `{{name}}` substitution.
 */
class PromptsGetHandler implements HandlerInterface
{
    /**
     * @param PromptRegistryInterface $promptRegistry
     * @param PromptRenderer $renderer
     * @param ModuleConfig $config
     * @param AuditContext $auditContext
     */
    public function __construct(
        private readonly PromptRegistryInterface $promptRegistry,
        private readonly PromptRenderer $renderer,
        private readonly ModuleConfig $config,
        private readonly AuditContext $auditContext
    ) {
    }

    /**
     * @inheritDoc
     */
    public function method(): string
    {
        return 'prompts/get';
    }

    /**
     * @inheritDoc
     */
    public function handle(Request $request, AuthenticatedContext $context): Response
    {
        $name = $request->params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return $this->fail($request, ErrorCode::INVALID_PARAMS, 'Missing or invalid "name" parameter.');
        }
        $this->auditContext->promptName = $name;

        try {
            $prompt = $this->promptRegistry->get($name);
        } catch (NoSuchEntityException) {
            return $this->fail(
                $request,
                ErrorCode::PROMPT_NOT_FOUND,
                sprintf('Prompt "%s" is not registered.', $name)
            );
        }

        $writesAllowed = $this->config->isAllowWrites() && $context->token->getAllowWrites();
        if ($prompt->getRequiresWrite() && !$writesAllowed) {
            return $this->fail(
                $request,
                ErrorCode::WRITE_NOT_ALLOWED,
                'This prompt is disabled for read-only tokens.'
            );
        }

        $argsRaw = $request->params['arguments'] ?? [];
        if (!is_array($argsRaw)) {
            return $this->fail($request, ErrorCode::INVALID_PARAMS, 'Parameter "arguments" must be an object.');
        }
        /** @var array<string, string> $arguments */
        $arguments = [];
        foreach ($argsRaw as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $arguments[$key] = is_scalar($value) ? (string) $value : '';
        }
        $this->auditContext->arguments = $arguments;

        foreach ($prompt->getArguments() as $argument) {
            if ($argument->required && ($arguments[$argument->name] ?? '') === '') {
                return $this->fail(
                    $request,
                    ErrorCode::INVALID_PARAMS,
                    sprintf('Missing required argument "%s".', $argument->name)
                );
            }
        }

        $messages = [];
        foreach ($this->renderer->render($prompt, $arguments) as $message) {
            $messages[] = [
                'role' => $message->role,
                'content' => [
                    'type' => 'text',
                    'text' => $message->text,
                ],
            ];
        }

        return Response::success($request->id, [
            'description' => $prompt->getDescription(),
            'messages' => $messages,
        ]);
    }

    /**
     * @param Request $request
     * @param ErrorCode $code
     * @param string $message
     * @return Response
     */
    private function fail(Request $request, ErrorCode $code, string $message): Response
    {
        $this->auditContext->responseStatus = AuditEntryInterface::STATUS_ERROR;
        $this->auditContext->errorCode = (string) $code->value;
        return Response::failure($request->id, $code, $message);
    }
}
