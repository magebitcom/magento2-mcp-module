<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Prompt;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\PromptInterface;
use Magebit\Mcp\Api\PromptRegistryInterface;
use Magebit\Mcp\Model\Util\RegisteredEntries;
use Magento\Framework\Exception\NoSuchEntityException;
use Throwable;

class PromptRegistry implements PromptRegistryInterface
{
    /** @var array<string, PromptInterface> */
    private array $staticPrompts;

    /** @var array<string, PromptInterface>|null */
    private ?array $merged = null;

    /**
     * @param AdminPromptProvider $adminPromptProvider
     * @param LoggerInterface $logger
     * @param array<string, PromptInterface> $prompts
     */
    public function __construct(
        private readonly AdminPromptProvider $adminPromptProvider,
        private readonly LoggerInterface $logger,
        array $prompts = []
    ) {
        RegisteredEntries::assertValid($prompts, [PromptInterface::class], 'MCP prompt');
        $this->staticPrompts = $prompts;
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        return $this->getMerged();
    }

    /**
     * @inheritDoc
     */
    public function has(string $name): bool
    {
        return isset($this->getMerged()[$name]);
    }

    /**
     * @inheritDoc
     */
    public function get(string $name): PromptInterface
    {
        $merged = $this->getMerged();
        if (!isset($merged[$name])) {
            throw NoSuchEntityException::singleField('name', $name);
        }
        return $merged[$name];
    }

    /**
     * @return array<int, string>
     */
    public function getStaticNames(): array
    {
        return array_keys($this->staticPrompts);
    }

    /**
     * @return array<string, PromptInterface>
     */
    private function getMerged(): array
    {
        if ($this->merged !== null) {
            return $this->merged;
        }

        $merged = $this->staticPrompts;
        try {
            $adminPrompts = $this->adminPromptProvider->getAll();
        } catch (Throwable $e) {
            // Persistence failure must not break the registry — `prompts/list`
            // should still serve DI prompts. Log and continue.
            $this->logger->warning('Admin prompt provider unavailable; serving DI prompts only.', [
                'exception' => $e,
            ]);
            $this->merged = $merged;
            return $this->merged;
        }

        foreach ($adminPrompts as $name => $prompt) {
            if (isset($merged[$name])) {
                $this->logger->warning('Admin prompt shadows a DI-registered prompt; keeping DI definition.', [
                    'name' => $name,
                ]);
                continue;
            }
            $merged[$name] = $prompt;
        }

        $this->merged = $merged;
        return $this->merged;
    }
}
