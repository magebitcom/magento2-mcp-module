<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool;

use Magebit\Mcp\Api\LoggerInterface;
use stdClass;

/**
 * Wire-shape normalizer for tool inputSchemas emitted via `tools/list`.
 * Strips composition keywords (`oneOf` / `allOf` / `anyOf`) that the MCP
 * spec forbids, and rewrites empty `properties` arrays to a stdClass so
 * `json_encode` produces `{}` instead of `[]` (JSON Schema rejects an
 * array there).
 */
class SchemaSanitizer
{
    private const FORBIDDEN = ['oneOf', 'allOf', 'anyOf'];

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @phpstan-param array<string, mixed> $schema
     * @phpstan-return array<string, mixed>
     */
    public function sanitize(string $toolName, array $schema): array
    {
        /** @var array<string, mixed> $walked */
        $walked = $this->walk($toolName, $schema, '');
        return $walked;
    }

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
            if ($key === 'properties' && is_array($value) && $value === []) {
                $cleaned[$key] = new stdClass();
                continue;
            }
            $cleaned[$key] = $this->walk($toolName, $value, $childPath);
        }
        return $cleaned;
    }
}
