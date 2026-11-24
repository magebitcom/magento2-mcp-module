<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool;

use InvalidArgumentException;
use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapterFactory;
use Magebit\Mcp\Model\Util\RegisteredEntries;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Validates tool names match the canonical dotted regex and that each di.xml
 * key matches the tool's own getName() — catches drift between registration
 * and self-reported identity.
 */
class ToolRegistry implements ToolRegistryInterface
{
    /** @var array<string, ToolInterface> */
    private array $tools;

    /**
     * Wire-form (dot→underscore) name → canonical name. Built once at
     * construction so the dispatcher can accept the underscored names that
     * clients with stricter validators (notably Claude.ai's frontend, which
     * enforces `^[a-zA-Z0-9_-]{1,64}$`) emit on tools/call.
     *
     * @var array<string, string>
     */
    private array $wireToCanonical;

    /**
     * Entries are plain tools or bulk tools; bulk tools are wrapped in a core adapter so every registry consumer
     * keeps seeing a ToolInterface.
     *
     * @param array<string, ToolInterface|BulkToolInterface> $tools
     * @param BulkToolAdapterFactory|null $adapterFactory Wired in di.xml; only null in tests without bulk entries.
     * @return void
     */
    public function __construct(array $tools = [], private readonly ?BulkToolAdapterFactory $adapterFactory = null)
    {
        RegisteredEntries::assertValid(
            $tools,
            [[ToolInterface::class, BulkToolInterface::class]],
            'MCP tool'
        );
        $this->tools = $this->wrapBulkEntries($tools);
        $this->wireToCanonical = $this->buildWireMap($tools);
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * @inheritDoc
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * @inheritDoc
     */
    public function get(string $name): ToolInterface
    {
        if (!isset($this->tools[$name])) {
            throw NoSuchEntityException::singleField('name', $name);
        }
        return $this->tools[$name];
    }

    /**
     * @inheritDoc
     */
    public function getCanonicalName(string $name): ?string
    {
        if (isset($this->tools[$name])) {
            return $name;
        }
        return $this->wireToCanonical[$name] ?? null;
    }

    /**
     * @param array<string, ToolInterface|BulkToolInterface> $tools
     * @return array<string, ToolInterface>
     */
    private function wrapBulkEntries(array $tools): array
    {
        $wrapped = [];
        foreach ($tools as $name => $tool) {
            if ($tool instanceof ToolInterface) {
                $wrapped[$name] = $tool;
                continue;
            }
            if ($this->adapterFactory === null) {
                throw new InvalidArgumentException(sprintf(
                    'MCP tool "%s" is a bulk tool but no BulkToolAdapterFactory is wired.',
                    $name
                ));
            }
            $wrapped[$name] = $this->adapterFactory->create(['tool' => $tool]);
        }
        return $wrapped;
    }

    /**
     * Maps the dot→underscore wire form back to the canonical name. Throws on
     * collisions (two distinct canonicals projecting to the same wire form),
     * which would otherwise let `tools/call` route nondeterministically.
     *
     * @param array<string, ToolInterface|BulkToolInterface> $tools
     * @return array<string, string>
     */
    private function buildWireMap(array $tools): array
    {
        $map = [];
        foreach ($tools as $name => $tool) {
            $wire = str_replace('.', '_', $name);
            if ($wire === $name) {
                continue;
            }
            if (isset($map[$wire])) {
                throw new InvalidArgumentException(sprintf(
                    'MCP tool wire-format collision: "%s" and "%s" both project to "%s".',
                    $map[$wire],
                    $name,
                    $wire
                ));
            }
            $map[$wire] = $name;
        }
        return $map;
    }

}
