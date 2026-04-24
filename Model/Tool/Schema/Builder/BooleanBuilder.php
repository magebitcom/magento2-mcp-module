<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema\Builder;

/**
 * Fluent builder for a `type: boolean` property. Has no constraints beyond
 * the cross-cutting `description` / `required` from {@see PropertyBuilder}.
 */
class BooleanBuilder extends PropertyBuilder
{
    /**
     * @inheritDoc
     */
    public function toSchemaArray(): array
    {
        return $this->withDescription(['type' => 'boolean']);
    }
}
