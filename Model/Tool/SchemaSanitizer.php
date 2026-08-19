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

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $toolName
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function sanitize(string $toolName, array $schema): array
    {
        /** @var array<string, mixed> $walked */
        $walked = $this->walk($toolName, $schema, '', false);
        return $walked;
    }

    /**
     * Same as {@see sanitize()} plus the rewrites clients need. Only for what
     * `tools/list` advertises — argument validation keeps the stricter schema.
     *
     * @param string $toolName
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function sanitizeForClient(string $toolName, array $schema): array
    {
        /** @var array<string, mixed> $walked */
        $walked = $this->walk($toolName, $schema, '', true);
        return $walked;
    }

    /**
     * @param string $toolName
     * @param mixed $node
     * @param string $path
     * @param bool $forClient
     * @return mixed
     */
    private function walk(string $toolName, mixed $node, string $path, bool $forClient): mixed
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

        if ($forClient) {
            $node = $this->foldNonStringEnum($node);
        }

        $cleaned = [];
        foreach ($node as $key => $value) {
            $childPath = $path === '' ? (string) $key : $path . '.' . $key;
            if ($key === 'properties' && is_array($value) && $value === []) {
                $cleaned[$key] = new stdClass();
                continue;
            }
            $cleaned[$key] = $this->walk($toolName, $value, $childPath, $forClient);
        }
        return $cleaned;
    }

    /**
     * Replaces an `enum` holding non-string values with a description of them.
     *
     * @param array<mixed> $node
     * @return array<mixed>
     */
    private function foldNonStringEnum(array $node): array
    {
        $values = $node['enum'] ?? null;
        if (!is_array($values) || !array_is_list($values) || $values === []) {
            return $node;
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                unset($node['enum']);
                $node['description'] = $this->describeValues($node['description'] ?? null, $values);
                return $node;
            }
        }
        return $node;
    }

    /**
     * @param mixed $description
     * @param array<int, mixed> $values
     * @return string
     */
    private function describeValues(mixed $description, array $values): string
    {
        $encoded = array_map(
            static fn (mixed $value): string => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            $values
        );
        $sentence = sprintf('Allowed values: %s.', implode(', ', $encoded));
        $existing = is_string($description) ? trim($description) : '';
        return $existing === '' ? $sentence : $existing . ' ' . $sentence;
    }
}
