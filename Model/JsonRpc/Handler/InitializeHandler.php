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
 * Handles the `initialize` JSON-RPC method. Advertises the `tools` and
 * `prompts` capabilities; `resources`, `sampling`, `logging` are deliberately
 * omitted. `protocolVersion` is sourced from {@see ProtocolVersionValidator}
 * so the two places that need to agree on the version never drift.
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

        $serverInfo = [
            'name' => $this->moduleConfig->getServerName(),
            'version' => $this->serverVersion,
        ];

        $title = $this->moduleConfig->getServerTitle();
        if ($title !== null) {
            $serverInfo['title'] = $title;
        }

        $description = $this->moduleConfig->getServerDescription();
        if ($description !== null) {
            $serverInfo['description'] = $description;
        }

        $websiteUrl = $this->moduleConfig->getServerWebsiteUrl();
        if ($websiteUrl !== null) {
            $serverInfo['websiteUrl'] = $websiteUrl;
        }

        $icon = $this->moduleConfig->getServerIcon();
        if ($icon !== null) {
            $serverInfo['icons'] = [$icon];
        }

        $payload = [
            'protocolVersion' => $this->protocolVersionValidator->getLatest(),
            'capabilities' => [
                'tools' => new stdClass(),
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => $serverInfo,
        ];

        $instructions = $this->moduleConfig->getServerInstructions();
        if ($instructions !== null) {
            $payload['instructions'] = $instructions;
        }

        return Response::success($request->id, $payload);
    }
}
