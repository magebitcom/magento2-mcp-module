<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema\Preset;

use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\SchemaContribution;

/**
 * Produces `sort_by` / `sort_dir`.
 */
final class Sort implements SchemaContribution
{
    /** @var array<int, string> */
    private readonly array $sortableFields;

    private readonly string $defaultField;

    private readonly string $defaultDirection;

    /**
     * @param array<int, string> $sortableFields
     */
    private function __construct(
        array $sortableFields,
        string $defaultField,
        string $defaultDirection
    ) {
        $this->sortableFields = array_values($sortableFields);
        $this->defaultField = $defaultField;
        $this->defaultDirection = $defaultDirection;
    }

    /**
     * @param array<int, string> $sortableFields
     * @param string $defaultDirection `asc` or `desc`.
     */
    public static function fields(
        array $sortableFields,
        string $defaultField = 'created_at',
        string $defaultDirection = 'desc'
    ): self {
        return new self($sortableFields, $defaultField, $defaultDirection);
    }

    /**
     * @inheritDoc
     */
    public function applyTo(ObjectBuilder $object): void
    {
        $object
            ->string('sort_by', fn (StringBuilder $s) => $s
                ->enum($this->sortableFields)
                ->description(sprintf('Sort field. Defaults to `%s`.', $this->defaultField))
            )
            ->string('sort_dir', fn (StringBuilder $s) => $s
                ->enum(['asc', 'desc'])
                ->description(sprintf('Sort direction. Defaults to `%s`.', $this->defaultDirection))
            );
    }
}
