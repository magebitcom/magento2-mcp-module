<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\Security;

use PHPUnit\Framework\Assert;

/**
 * Small readers for MCP responses returned by McpTestCase.
 */
final class ToolPayload
{
    /**
     * Decode `result.content[0].text`, which holds the tool output as a JSON string.
     *
     * @phpstan-param array{status: int, headers: array<string, string>, body: array<string, mixed>|null} $response
     * @param array $response
     * @return array<mixed>
     */
    public static function decode(array $response): array
    {
        $body = $response['body'] ?? null;
        Assert::assertIsArray($body, 'Expected a JSON body.');
        $result = $body['result'] ?? null;
        Assert::assertIsArray($result, 'Expected a JSON-RPC result, got: ' . json_encode($body['error'] ?? null));
        $content = $result['content'] ?? null;
        Assert::assertIsArray($content, 'Expected result.content.');
        $first = $content[0] ?? null;
        Assert::assertIsArray($first, 'Expected result.content[0].');
        $text = $first['text'] ?? null;
        Assert::assertIsString($text, 'Expected result.content[0].text to be a string.');
        $decoded = json_decode($text, true);
        Assert::assertIsArray($decoded, 'Expected result.content[0].text to be a JSON object or list.');

        return $decoded;
    }

    /**
     * True when any string inside the value (at any depth) contains the needle.
     *
     * @param mixed $value
     * @param string $needle
     * @return bool
     */
    public static function contains(mixed $value, string $needle): bool
    {
        if (is_string($value)) {
            return str_contains($value, $needle);
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::contains($item, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Tool names from a `tools/list` response. These use underscores, e.g. `system_whoami`.
     *
     * @phpstan-param array{status: int, headers: array<string, string>, body: array<string, mixed>|null} $response
     * @param array $response
     * @return array<int, string>
     */
    public static function toolNames(array $response): array
    {
        $body = $response['body'] ?? null;
        Assert::assertIsArray($body, 'Expected a JSON body.');
        $result = $body['result'] ?? null;
        Assert::assertIsArray($result, 'Expected a JSON-RPC result, got: ' . json_encode($body['error'] ?? null));
        $tools = $result['tools'] ?? null;
        Assert::assertIsArray($tools, 'Expected result.tools.');

        $names = [];
        foreach ($tools as $tool) {
            if (is_array($tool) && is_string($tool['name'] ?? null)) {
                $names[] = $tool['name'];
            }
        }

        return $names;
    }
}
