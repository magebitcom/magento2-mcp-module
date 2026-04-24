<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc\Handler;

use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;
use Magebit\Mcp\Model\Validator\ProtocolVersionValidator;
use stdClass;

/**
 * Handles the `initialize` JSON-RPC method.
 *
 * Advertises only the `tools` capability; `resources`, `prompts`,
 * `sampling`, `logging` etc. are deliberately omitted.
 *
 * `protocolVersion` is sourced from {@see ProtocolVersionValidator::LATEST}
 * so the two places that need to agree on the version never drift.
 * `serverVersion` comes from etc/di.xml — bumped once per release.
 * `serverName` / `instructions` are admin-editable via
 * Stores → Configuration → Magebit → MCP Server so each deployment can brand
 * its own server and drop a short note for the AI client.
 */
class InitializeHandler implements HandlerInterface
{
    /**
     * @param ProtocolVersionValidator $protocolVersionValidator
     * @param ModuleConfig $moduleConfig
     * @param string $serverVersion
     */
    public function __construct(
        private readonly ProtocolVersionValidator $protocolVersionValidator,
        private readonly ModuleConfig $moduleConfig,
        private readonly string $serverVersion
    ) {
    }

    /**
     * @inheritDoc
     */
    public function method(): string
    {
        return 'initialize';
    }

    /**
     * @inheritDoc
     */
    public function handle(Request $request, AuthenticatedContext $context): Response
    {
        unset($context);

        $payload = [
            'protocolVersion' => $this->protocolVersionValidator->getLatest(),
            'capabilities' => [
                'tools' => new stdClass(),
            ],
            'serverInfo' => [
                'name' => $this->moduleConfig->getServerName(),
                'version' => $this->serverVersion,
            ],
        ];

        $description = $this->moduleConfig->getServerDescription();
        if ($description !== null) {
            $payload['instructions'] = $description;
        }

        return Response::success($request->id, $payload);
    }
}
