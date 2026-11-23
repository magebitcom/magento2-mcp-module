<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool\Bulk;

use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Exception\SchemaValidationException;
use Magebit\Mcp\Model\Tool\Bulk\BatchResult;
use Magebit\Mcp\Model\Tool\Bulk\BulkSchemaFactory;
use Magebit\Mcp\Model\Tool\Bulk\ItemResult;
use Magebit\Mcp\Model\Validator\JsonSchemaValidator;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

class BulkSchemaFactoryTest extends TestCase
{
    private const ITEM_SCHEMA = [
        'type' => 'object',
        'properties' => ['sku' => ['type' => 'string', 'minLength' => 1]],
        'required' => ['sku'],
        'additionalProperties' => false,
    ];

    private BulkSchemaFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new BulkSchemaFactory();
    }

    public function testInputSchemaWrapsItemSchema(): void
    {
        $schema = $this->factory->inputSchema($this->tool(50, 150));

        self::assertSame('object', $schema['type']);
        self::assertSame(['items'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame('array', $this->path($schema, 'properties', 'items', 'type'));
        self::assertSame(self::ITEM_SCHEMA, $this->path($schema, 'properties', 'items', 'items'));
        self::assertSame(1, $this->path($schema, 'properties', 'items', 'minItems'));
        self::assertSame(150, $this->path($schema, 'properties', 'items', 'maxItems'));
        self::assertSame('boolean', $this->path($schema, 'properties', 'dry_run', 'type'));
        self::assertFalse($this->path($schema, 'properties', 'dry_run', 'default'));
    }

    public function testDescriptionStatesBothNumbers(): void
    {
        self::assertSame(
            'Does things. Up to 50 items run immediately; up to 150 run as a background job '
            . '(follow it with system_job_get).',
            $this->factory->description($this->tool(50, 150))
        );
    }

    public function testDescriptionWhenCapEqualsCeiling(): void
    {
        self::assertSame(
            'Does things. Up to 50 items per call.',
            $this->factory->description($this->tool(50, 50))
        );
    }

    public function testOutputSchemaOffersEnvelopeOrJobStarted(): void
    {
        $schema = $this->factory->outputSchema();

        self::assertSame('object', $schema['type']);
        $branches = $schema['oneOf'];
        self::assertIsArray($branches);
        self::assertCount(2, $branches);
        $envelope = $this->branch($branches, 0);
        self::assertSame(['dry_run', 'summary', 'results'], $envelope['required']);
        self::assertSame(
            ['ok', 'error', 'skipped'],
            $this->path($envelope, 'properties', 'results', 'items', 'properties', 'status', 'enum')
        );
        self::assertSame(
            ['total', 'ok', 'failed', 'skipped'],
            $this->path($envelope, 'properties', 'summary', 'required')
        );
        self::assertSame('string', $this->path($envelope, 'properties', 'notice', 'type'));
        self::assertNotContains('notice', $envelope['required']);

        $job = $this->branch($branches, 1);
        self::assertSame(['job_id', 'state', 'total', 'sync_cap', 'message'], $job['required']);
        self::assertSame(['queued'], $this->path($job, 'properties', 'state', 'enum'));
    }

    public function testOutputSchemaAcceptsRealEnvelope(): void
    {
        $batch = new BatchResult();
        $batch->add(0, 'a', ItemResult::ok(7));
        $batch->add(1, null, ItemResult::error('x', 'm'));
        $batch->add(2, 'c', ItemResult::skipped('y'));
        $batch->setNotice('n');

        $this->validator()->validate($this->factory->outputSchema(), $batch->toEnvelope(false));
        $this->addToAssertionCount(1);
    }

    public function testOutputSchemaAcceptsJobStartReply(): void
    {
        $reply = [
            'job_id' => 'b0f1c2d3',
            'state' => 'queued',
            'total' => 120,
            'sync_cap' => 50,
            'message' => 'Queued as a background job.',
        ];

        $this->validator()->validate($this->factory->outputSchema(), $reply);
        $this->addToAssertionCount(1);
    }

    public function testOutputSchemaRejectsReplyMatchingNeitherBranch(): void
    {
        $this->expectException(SchemaValidationException::class);
        $this->validator()->validate($this->factory->outputSchema(), ['job_id' => 'x', 'state' => 'running']);
    }

    public function testInputSchemaPassesValidatorForOneItem(): void
    {
        $this->validator()->validate(
            $this->factory->inputSchema($this->tool(2, 3)),
            ['items' => [['sku' => 'a']], 'dry_run' => true]
        );
        $this->addToAssertionCount(1);
    }

    public function testInputSchemaRejectsCeilingPlusOne(): void
    {
        $this->expectException(SchemaValidationException::class);
        $this->validator()->validate(
            $this->factory->inputSchema($this->tool(2, 3)),
            ['items' => [['sku' => 'a'], ['sku' => 'b'], ['sku' => 'c'], ['sku' => 'd']]]
        );
    }

    /**
     * @param array<mixed> $branches
     * @return array<string, mixed>
     */
    private function branch(array $branches, int $index): array
    {
        self::assertIsArray($branches[$index]);
        /** @var array<string, mixed> $branch */
        $branch = $branches[$index];
        return $branch;
    }

    /**
     * @param array<string, mixed> $data
     * @param string ...$path
     * @return mixed
     */
    private function path(array $data, string ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($data);
            self::assertArrayHasKey($key, $data);
            $data = $data[$key];
        }
        return $data;
    }

    private function validator(): JsonSchemaValidator
    {
        return new JsonSchemaValidator(new Validator());
    }

    private function tool(int $cap, int $ceiling): BulkToolInterface
    {
        $tool = $this->createMock(BulkToolInterface::class);
        $tool->method('getItemSchema')->willReturn(self::ITEM_SCHEMA);
        $tool->method('getSyncCap')->willReturn($cap);
        $tool->method('getAsyncCeiling')->willReturn($ceiling);
        $tool->method('getDescription')->willReturn('Does things. ');
        return $tool;
    }
}
