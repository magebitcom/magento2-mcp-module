<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP tool `system.whoami` — resolve "me" to the administrator and token
 * authenticated for the current request.
 */
class WhoAmI implements ToolInterface
{
    public const TOOL_NAME = 'system.whoami';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_whoami';

    /**
     * @param AuthenticatedContextProvider $contextProvider
     * @param ModuleConfig $config
     */
    public function __construct(
        private readonly AuthenticatedContextProvider $contextProvider,
        private readonly ModuleConfig $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Who Am I';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Return the Magento administrator and MCP connection authenticated '
            . 'for this request, including whether write tools are currently allowed.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()->toArray();
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    /**
     * @inheritDoc
     */
    public function getWriteMode(): WriteMode
    {
        return WriteMode::READ;
    }

    /**
     * @inheritDoc
     */
    public function getConfirmationRequired(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     * @throws LocalizedException
     */
    public function execute(array $arguments): ToolResultInterface
    {
        $context = $this->contextProvider->get();
        $adminUserId = $context->getAdminUserId();
        $allowWrites = $this->config->isAllowWrites() && $context->token->getAllowWrites();
        $payload = [
            'admin_user_id' => $adminUserId,
            'username' => (string) $context->adminUser->getUsername(),
            'email' => (string) $context->adminUser->getEmail(),
            'token_name' => $context->token->getName(),
            'allow_writes' => $allowWrites,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode authenticated context as JSON.'));
        }

        return ToolResult::text($json, [
            'admin_user_id' => $adminUserId,
            'allow_writes' => $allowWrites,
        ]);
    }
}
