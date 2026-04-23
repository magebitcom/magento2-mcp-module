<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Util;

use InvalidArgumentException;
use Magebit\Mcp\Api\FieldResolverInterface;
use Magebit\Mcp\Model\Util\ResolverPipeline;
use PHPUnit\Framework\TestCase;

class ResolverPipelineTest extends TestCase
{
    /**
     * @var ResolverPipeline
     */
    private ResolverPipeline $pipeline;

    protected function setUp(): void
    {
        $this->pipeline = new ResolverPipeline();
    }

    public function testPlansResolversInSortOrder(): void
    {
        $plan = $this->pipeline->plan(
            [
                $this->resolver('b', 200),
                $this->resolver('a', 100),
            ],
            []
        );

        $this->assertSame(['a', 'b'], $this->keysOf($plan));
    }

    public function testFieldsWhitelistOptsIn(): void
    {
        $plan = $this->pipeline->plan(
            [
                $this->resolver('identity', 10),
                $this->resolver('totals', 50),
                $this->resolver('items', 60),
            ],
            ['fields' => ['identity', 'totals']]
        );

        $this->assertSame(['identity', 'totals'], $this->keysOf($plan));
    }

    public function testExcludeBlacklistOptsOut(): void
    {
        $plan = $this->pipeline->plan(
            [
                $this->resolver('identity', 10),
                $this->resolver('items', 20),
            ],
            ['exclude' => ['items']]
        );

        $this->assertSame(['identity'], $this->keysOf($plan));
    }

    public function testEmptyResolverListReturnsEmptyPlan(): void
    {
        $this->assertSame([], $this->pipeline->plan([], []));
    }

    public function testDuplicateKeysThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Duplicate MCP field resolver for key "totals"/');

        $this->pipeline->plan(
            [
                $this->resolver('totals', 10),
                $this->resolver('totals', 20),
            ],
            []
        );
    }

    public function testFieldsAndExcludeCombined(): void
    {
        $plan = $this->pipeline->plan(
            [
                $this->resolver('a', 10),
                $this->resolver('b', 20),
                $this->resolver('c', 30),
            ],
            ['fields' => ['a', 'b', 'c'], 'exclude' => ['b']]
        );

        $this->assertSame(['a', 'c'], $this->keysOf($plan));
    }

    public function testScalarFieldsArgIsTolerated(): void
    {
        $plan = $this->pipeline->plan(
            [
                $this->resolver('a', 10),
                $this->resolver('b', 20),
            ],
            ['fields' => 'a']
        );

        $this->assertSame(['a'], $this->keysOf($plan));
    }

    /**
     * @param FieldResolverInterface[] $plan
     * @return array<int, string>
     */
    private function keysOf(array $plan): array
    {
        return array_map(static fn(FieldResolverInterface $r): string => $r->getKey(), $plan);
    }

    /**
     * @param string $key
     * @param int $sortOrder
     * @return FieldResolverInterface
     */
    private function resolver(string $key, int $sortOrder): FieldResolverInterface
    {
        return new class ($key, $sortOrder) implements FieldResolverInterface {
            public function __construct(
                private readonly string $key,
                private readonly int $sortOrder
            ) {
            }

            public function getKey(): string
            {
                return $this->key;
            }

            public function getSortOrder(): int
            {
                return $this->sortOrder;
            }
        };
    }
}
