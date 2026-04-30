<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema\Preset;

use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\SchemaContribution;

/**
 * Produces `page` / `page_size`.
 */
class Pagination implements SchemaContribution
{
    /**
     * @param int $maxPageSize
     */
    private function __construct(
        private readonly int $maxPageSize
    ) {
    }

    /**
     * @param int $maxPageSize
     * @return self
     */
    public static function maxPageSize(int $maxPageSize): self
    {
        return new self($maxPageSize);
    }

    /**
     * @inheritDoc
     */
    public function applyTo(ObjectBuilder $object): void
    {
        $object
            ->integer('page', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('1-based page number.')
            )
            ->integer('page_size', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->maximum($this->maxPageSize)
                ->description(sprintf('Rows per page (capped at %d).', $this->maxPageSize))
            );
    }
}
