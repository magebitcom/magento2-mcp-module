<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Bulk;

use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * Builds the agent-facing schemas and description of a bulk tool: the `items[]`
 * input wrapper, the shared result envelope and the sync / background cap sentence.
 */
class BulkSchemaFactory
{
    /**
     * @param BulkToolInterface $tool
     * @return array<string, mixed>
     */
    public function inputSchema(BulkToolInterface $tool): array
    {
        return Schema::object()
            ->rawProperty('items', [
                'type' => 'array',
                'description' => 'Items to process, in order.',
                'items' => $tool->getItemSchema(),
                'minItems' => 1,
                'maxItems' => $tool->getAsyncCeiling(),
            ], true)
            ->rawProperty('dry_run', [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Validate every item and report what would happen without writing.',
            ])
            ->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'oneOf' => [$this->envelopeSchema(), $this->jobStartedSchema()],
        ];
    }

    /**
     * Reply of a call above the sync cap: the job was queued, no results yet.
     *
     * @return array<string, mixed>
     */
    private function jobStartedSchema(): array
    {
        $count = ['type' => 'integer', 'minimum' => 0];
        return [
            'type' => 'object',
            'properties' => [
                'job_id' => ['type' => 'string'],
                'state' => ['type' => 'string', 'enum' => ['queued']],
                'total' => $count,
                'sync_cap' => $count,
                'message' => ['type' => 'string'],
            ],
            'required' => ['job_id', 'state', 'total', 'sync_cap', 'message'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function envelopeSchema(): array
    {
        $count = ['type' => 'integer', 'minimum' => 0];
        $nullableString = ['type' => ['string', 'null']];
        return [
            'type' => 'object',
            'properties' => [
                'dry_run' => ['type' => 'boolean'],
                'summary' => [
                    'type' => 'object',
                    'properties' => [
                        'total' => $count,
                        'ok' => $count,
                        'failed' => $count,
                        'skipped' => $count,
                    ],
                    'required' => ['total', 'ok', 'failed', 'skipped'],
                ],
                'results' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'index' => ['type' => 'integer', 'minimum' => 0],
                            'key' => $nullableString,
                            'status' => [
                                'type' => 'string',
                                'enum' => [
                                    ItemResult::STATUS_OK,
                                    ItemResult::STATUS_ERROR,
                                    ItemResult::STATUS_SKIPPED,
                                ],
                            ],
                            'error_code' => $nullableString,
                            'message' => $nullableString,
                            'id' => ['type' => ['integer', 'string', 'null']],
                        ],
                        'required' => ['index', 'key', 'status', 'error_code', 'message', 'id'],
                    ],
                ],
                'notice' => ['type' => 'string'],
            ],
            'required' => ['dry_run', 'summary', 'results'],
        ];
    }

    /**
     * @param BulkToolInterface $tool
     * @return string
     */
    public function description(BulkToolInterface $tool): string
    {
        $cap = $tool->getSyncCap();
        $ceiling = $tool->getAsyncCeiling();
        $sentence = $cap === $ceiling
            ? sprintf('Up to %d items per call.', $cap)
            : sprintf(
                'Up to %d items run immediately; up to %d run as a background job (follow it with system_job_get).',
                $cap,
                $ceiling
            );
        return rtrim($tool->getDescription()) . ' ' . $sentence;
    }
}
