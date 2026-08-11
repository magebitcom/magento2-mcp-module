<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\ViewModel;

use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Tool\DisabledTools;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Rows for the Tools management page, grouped by owning module (derived from
 * the ACL resource prefix), core module first.
 */
class ToolManagement implements ArgumentInterface
{
    /**
     * @param ToolRegistryInterface $toolRegistry
     * @param DisabledTools $disabledTools
     */
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly DisabledTools $disabledTools
    ) {
    }

    /**
     * @return array<string, array<int, array{name: string, title: string, description: string,
     *     write: bool, acl: string, confirmation: bool, disabled: bool}>>
     */
    public function getModules(): array
    {
        $disabled = $this->disabledTools->getAll();
        $modules = [];
        foreach ($this->toolRegistry->all() as $tool) {
            $acl = $tool->getAclResource();
            $module = str_contains($acl, '::') ? explode('::', $acl)[0] : 'Unknown';
            $modules[$module][] = [
                'name' => $tool->getName(),
                'title' => $tool->getTitle(),
                'description' => $tool->getDescription(),
                'write' => $tool->getWriteMode() === WriteMode::WRITE,
                'acl' => $acl,
                'confirmation' => $tool->getConfirmationRequired(),
                'disabled' => in_array($tool->getName(), $disabled, true),
            ];
        }

        foreach ($modules as &$tools) {
            usort($tools, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        }
        unset($tools);

        uksort($modules, static function (string $a, string $b): int {
            if ($a === 'Magebit_Mcp') {
                return -1;
            }
            if ($b === 'Magebit_Mcp') {
                return 1;
            }
            return strcmp($a, $b);
        });
        return $modules;
    }
}
