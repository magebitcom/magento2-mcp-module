<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Contract;

use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Model\Config\ModuleConfig;

/**
 * Framework-free contract rules shared by the abstract PHPUnit test cases and the
 * `magebit:mcp:tools:validate` command. Every method returns human-readable
 * violation strings, each naming the tool or text and the rule it breaks.
 */
class ToolContractChecker
{
    public const DOMAIN_PREFIXES = [
        'catalog', 'sales', 'customer', 'cms', 'system', 'inventory', 'tax',
        'marketing', 'reports', 'directory', 'hyva', 'google_analytics', 'db',
    ];

    /**
     * Request body cap of the MCP endpoint at its default: the controller reads it through
     * {@see ModuleConfig::getMaxBodyBytes()}, whose default this is, so the two cannot drift.
     */
    public const MAX_BODY_BYTES = ModuleConfig::DEFAULT_MAX_BODY_KB * 1024;

    /**
     * Request id of the measured batch: a 36-character UUID string, longer than the integer ids most clients send.
     */
    private const SAMPLE_REQUEST_ID = '00000000-0000-4000-8000-000000000000';

    private const FILE_EXTENSIONS = ['php', 'xml', 'phtml', 'js', 'css', 'json'];

    /**
     * Dotted tool names inside agent-facing text: known registered names, and any
     * token of three or more dotted segments whose first segment is a domain prefix.
     *
     * @param string $text
     * @param array<int, string> $knownDottedNames
     * @param array<int, string> $prefixes
     * @return array<int, string>
     */
    public function dottedNameViolations(string $text, array $knownDottedNames, array $prefixes): array
    {
        $found = [];
        foreach ($knownDottedNames as $name) {
            if (preg_match('/(?<![\w.])' . preg_quote($name, '/') . '(?![\w])/', $text) === 1) {
                $found[$name] = $name;
            }
        }

        $matches = [];
        preg_match_all('/\b([a-z_]+)(\.[a-z0-9_]+){2,}\b/', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            [$token, $offset] = $match[0];
            if ($offset > 0 && preg_match('/[\w.]/', $text[$offset - 1]) === 1) {
                continue;
            }
            if (!in_array($match[1][0], $prefixes, true)) {
                continue;
            }
            $extension = substr($token, (int) strrpos($token, '.') + 1);
            if (in_array($extension, self::FILE_EXTENSIONS, true)) {
                continue;
            }
            $found[$token] = $token;
        }

        return array_values(array_map(
            static fn (string $name): string => sprintf(
                'dotted tool name "%s" in agent-facing text: use the underscore wire name',
                $name
            ),
            $found
        ));
    }

    /**
     * The size check measures a complete JSON-RPC `tools/call` body for a full batch (`ceiling` x the sample,
     * `dry_run`, the wire name and the envelope), because the endpoint caps the whole body. It is encoded with
     * json_encode()'s default flags, which escape slashes and non-ASCII characters: the larger of the encodings a
     * client may send, so a batch that passes here fits whichever one it uses.
     *
     * @param BulkToolInterface $tool
     * @param array<int, string> $declaredAclIds
     * @param array<string, mixed>|null $sampleItem a realistic largest item, or null to skip the size check
     * @return array<int, string>
     */
    public function bulkViolations(BulkToolInterface $tool, array $declaredAclIds, ?array $sampleItem): array
    {
        $name = $tool->getName();
        $violations = [];

        $underlying = $tool->getUnderlyingAclResource();
        if (!in_array($underlying, $declaredAclIds, true)) {
            $violations[] = sprintf(
                '%s: underlying ACL resource "%s" is not declared in the merged acl.xml (C5)',
                $name,
                $underlying
            );
        }

        $properties = $tool->getItemSchema()['properties'] ?? null;
        $key = $tool->getItemKeyField();
        if (!is_array($properties) || !array_key_exists($key, $properties)) {
            $violations[] = sprintf('%s: key field "%s" is not in the item schema properties', $name, $key);
        }

        $cap = $tool->getSyncCap();
        $ceiling = $tool->getAsyncCeiling();
        if ($cap < 1 || $cap > $ceiling) {
            $violations[] = sprintf(
                '%s: sync cap %d must satisfy 1 <= cap <= async ceiling (%d)',
                $name,
                $cap,
                $ceiling
            );
        }

        if ($sampleItem !== null && $ceiling >= 1) {
            $body = json_encode($this->fullBatchRequest($name, array_fill(0, $ceiling, $sampleItem)));
            if ($body === false) {
                $violations[] = sprintf('%s: the sample item cannot be encoded as JSON', $name);
            } elseif (strlen($body) > self::MAX_BODY_BYTES) {
                $violations[] = sprintf(
                    '%s: a full tools/call request with %d sample items is %d bytes, over the %d byte request '
                    . 'body limit',
                    $name,
                    $ceiling,
                    strlen($body),
                    self::MAX_BODY_BYTES
                );
            }
        }

        return $violations;
    }

    /**
     * @param string $toolName
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function fullBatchRequest(string $toolName, array $items): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => self::SAMPLE_REQUEST_ID,
            'method' => 'tools/call',
            'params' => [
                'name' => str_replace('.', '_', $toolName),
                'arguments' => ['items' => $items, 'dry_run' => false],
            ],
        ];
    }
}
