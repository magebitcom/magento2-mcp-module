<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema\Preset;

use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\SchemaContribution;

/**
 * Produces `fields` / `exclude` — whitelist/blacklist for the field-resolver
 * pipeline's per-entity resolver keys.
 */
final class FieldSelection implements SchemaContribution
{
    private readonly string $fieldsDescription;

    private readonly string $excludeDescription;

    private function __construct(
        string $fieldsDescription,
        string $excludeDescription
    ) {
        $this->fieldsDescription = $fieldsDescription;
        $this->excludeDescription = $excludeDescription;
    }

    public static function default(): self
    {
        return new self(
            'Whitelist of resolver keys per row.',
            'Resolver keys to drop from each row.'
        );
    }

    public static function describing(string $fields, string $exclude): self
    {
        return new self($fields, $exclude);
    }

    /**
     * @inheritDoc
     */
    public function applyTo(ObjectBuilder $object): void
    {
        $object
            ->array('fields', fn (ArrayBuilder $a) => $a
                ->ofStrings()
                ->description($this->fieldsDescription)
            )
            ->array('exclude', fn (ArrayBuilder $a) => $a
                ->ofStrings()
                ->description($this->excludeDescription)
            );
    }
}
