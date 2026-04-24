<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool;

use InvalidArgumentException;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Validates tool names match the canonical dotted regex and that each di.xml
 * key matches the tool's own getName() — catches drift between registration
 * and self-reported identity.
 */
class ToolRegistry implements ToolRegistryInterface
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    /** @var array<string, ToolInterface> */
    private array $tools;

    /**
     * @phpstan-param array<string, ToolInterface> $tools
     */
    public function __construct(array $tools = [])
    {
        $this->validate($tools);
        $this->tools = $tools;
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
     * @phpstan-param array<string, ToolInterface> $tools
     */
    private function validate(array $tools): void
    {
        foreach ($tools as $key => $tool) {
            if (!$tool instanceof ToolInterface) {
                throw new InvalidArgumentException(sprintf(
                    'MCP tool "%s" must implement %s, got %s.',
                    (string) $key,
                    ToolInterface::class,
                    get_debug_type($tool)
                ));
            }
            $name = $tool->getName();
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'MCP tool name "%s" is invalid — must match %s.',
                    $name,
                    self::NAME_PATTERN
                ));
            }
            if ($key !== $name) {
                throw new InvalidArgumentException(sprintf(
                    'MCP tool registration mismatch: di.xml key "%s" does not match getName() "%s".',
                    (string) $key,
                    $name
                ));
            }
        }
    }
}
