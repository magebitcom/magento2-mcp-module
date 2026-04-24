<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool;

use Magebit\Mcp\Api\LoggerInterface;

/**
 * Strips JSON Schema keywords that the MCP 2025-11-25 spec does not
 * permit inside a tool's `inputSchema`.
 *
 * Per the protocol the only top-level keys are `$schema`, `type`,
 * `properties`, and `required`; `oneOf` / `allOf` / `anyOf` are rejected
 * by strict clients (Anthropic's Messages API returns a 400 with
 * "input_schema does not support oneOf, allOf, or anyOf at the top level"
 * — the error path is the top level but any stray composition keyword is
 * risky). We walk the full tree, remove those keys everywhere, and log
 * each hit with the tool name and dotted JSON-pointer-ish path so the
 * offending schema is easy to locate and fix at the source.
 */
class SchemaSanitizer
{
    private const FORBIDDEN = ['oneOf', 'allOf', 'anyOf'];

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Return a copy of `$schema` with every `oneOf` / `allOf` / `anyOf`
     * entry removed. The input is not mutated.
     *
     * @param string $toolName
     * @param array $schema
     * @phpstan-param array<string, mixed> $schema
     * @return array
     * @phpstan-return array<string, mixed>
     */
    public function sanitize(string $toolName, array $schema): array
    {
        /** @var array<string, mixed> $walked */
        $walked = $this->walk($toolName, $schema, '');
        return $walked;
    }

    /**
     * @param string $toolName
     * @param mixed $node
     * @param string $path
     * @return mixed
     */
    private function walk(string $toolName, mixed $node, string $path): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        foreach (self::FORBIDDEN as $key) {
            if (array_key_exists($key, $node)) {
                $this->logger->warning(
                    sprintf(
                        'Stripped unsupported "%s" from input_schema of tool "%s" at %s.',
                        $key,
                        $toolName,
                        $path === '' ? '(root)' : $path
                    )
                );
                unset($node[$key]);
            }
        }

        $cleaned = [];
        foreach ($node as $key => $value) {
            $childPath = $path === '' ? (string) $key : $path . '.' . $key;
            $cleaned[$key] = $this->walk($toolName, $value, $childPath);
        }
        return $cleaned;
    }
}
