<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

/**
 * Contract every MCP tool must implement. Registered via DI array into ToolRegistryInterface and gated by an ACL resource.
 */
interface ToolInterface extends ToolDescriptorInterface
{
    /**
     * JSON Schema for arguments; validated before execute().
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * @param array $arguments Already validated against getInputSchema().
     * @phpstan-param array<string, mixed> $arguments
     * @return ToolResultInterface
     * @throws \Magento\Framework\Exception\LocalizedException on expected errors.
     */
    public function execute(array $arguments): ToolResultInterface;
}
