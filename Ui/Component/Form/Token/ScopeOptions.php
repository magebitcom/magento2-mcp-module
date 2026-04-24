<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Form\Token;

use Magebit\Mcp\Api\ToolRegistryInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Multiselect source for the token form's "Scopes" field. Empty on save means
 * "every tool the admin's role grants" — see {@see \Magebit\Mcp\Api\Data\TokenInterface::getScopes()}.
 */
class ScopeOptions implements OptionSourceInterface
{
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->toolRegistry->all() as $tool) {
            $options[] = [
                'value' => $tool->getName(),
                'label' => sprintf('%s — %s', $tool->getName(), $tool->getTitle()),
            ];
        }
        return $options;
    }
}
