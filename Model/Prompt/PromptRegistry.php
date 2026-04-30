<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Prompt;

use Magebit\Mcp\Api\PromptInterface;
use Magebit\Mcp\Api\PromptRegistryInterface;
use Magebit\Mcp\Model\Util\RegisteredEntries;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * DI-array registry of MCP prompts. Validation is delegated to
 * {@see RegisteredEntries} so the same compile-time drift check fires for both
 * Prompts and Tools.
 */
class PromptRegistry implements PromptRegistryInterface
{
    /** @var array<string, PromptInterface> */
    private array $prompts;

    /**
     * @param array<string, PromptInterface> $prompts
     * @return void
     */
    public function __construct(array $prompts = [])
    {
        RegisteredEntries::assertValid($prompts, [PromptInterface::class], 'MCP prompt');
        $this->prompts = $prompts;
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        return $this->prompts;
    }

    /**
     * @inheritDoc
     */
    public function has(string $name): bool
    {
        return isset($this->prompts[$name]);
    }

    /**
     * @inheritDoc
     */
    public function get(string $name): PromptInterface
    {
        if (!isset($this->prompts[$name])) {
            throw NoSuchEntityException::singleField('name', $name);
        }
        return $this->prompts[$name];
    }
}
