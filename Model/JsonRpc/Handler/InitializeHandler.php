<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc\Handler;

use Magebit\Mcp\Model\Auth\AuthenticatedContext;
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
 * `serverName` / `serverVersion` come from etc/di.xml arguments — when we
 * bump the module version, they change in one place.
 */
class InitializeHandler implements HandlerInterface
{
    /**
     * @param ProtocolVersionValidator $protocolVersionValidator
     * @param string $serverName
     * @param string $serverVersion
     */
    public function __construct(
        private readonly ProtocolVersionValidator $protocolVersionValidator,
        private readonly string $serverName,
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
        return Response::success($request->id, [
            'protocolVersion' => $this->protocolVersionValidator->getLatest(),
            'capabilities' => [
                'tools' => new stdClass(),
            ],
            'serverInfo' => [
                'name' => $this->serverName,
                'version' => $this->serverVersion,
            ],
        ]);
    }
}
