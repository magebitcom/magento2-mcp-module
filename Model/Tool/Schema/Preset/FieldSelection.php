<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema\Preset;

use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\SchemaContribution;

/**
 * Adds the `fields` / `exclude` pair that drives the field-resolver
 * pipeline on tools built from resolvers. Whitelist + blacklist over the
 * per-entity resolver key set.
 *
 * ```
 * fields:  { type: array, items: { type: string } }
 * exclude: { type: array, items: { type: string } }
 * ```
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

    /**
     * Default copy that matches the descriptions used across existing list
     * tools ("Whitelist of resolver keys per row." / "Resolver keys to
     * drop from each row.").
     */
    public static function default(): self
    {
        return new self(
            'Whitelist of resolver keys per row.',
            'Resolver keys to drop from each row.'
        );
    }

    /**
     * Override the descriptions when a tool has a more specific story to
     * tell — e.g. single-entity `.get` tools that speak about "this
     * entity's" fields rather than "per row".
     */
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
            ->array('fields', fn ($a) => $a
                ->ofStrings()
                ->description($this->fieldsDescription)
            )
            ->array('exclude', fn ($a) => $a
                ->ofStrings()
                ->description($this->excludeDescription)
            );
    }
}
