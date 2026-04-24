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
 * Adds the standard `page` / `page_size` properties to a list-tool schema.
 *
 * Mirrors the shape every list tool currently hand-rolls:
 * ```
 * page:      { type: integer, minimum: 1 }
 * page_size: { type: integer, minimum: 1, maximum: <MAX_PAGE_SIZE> }
 * ```
 */
final class Pagination implements SchemaContribution
{
    /**
     * @param int $maxPageSize Upper bound on `page_size`. Typically pulled from
     *                        the tool's `SearchCriteriaBuilder::MAX_PAGE_SIZE`.
     */
    private function __construct(
        private readonly int $maxPageSize
    ) {
    }

    /**
     * Build a pagination preset for the given max page size.
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
