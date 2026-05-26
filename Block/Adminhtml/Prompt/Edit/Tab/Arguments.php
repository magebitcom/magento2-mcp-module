<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\Prompt\Edit\Tab;

use Magebit\Mcp\Controller\Adminhtml\Prompt\Edit as EditController;
use Magebit\Mcp\Model\Adminhtml\FormDataPersistence;
use Magebit\Mcp\Model\Prompt\AdminPrompt;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Registry;

/**
 * "Arguments" tab — repeater of `{name, description, required}` rows. Renders
 * a static row template the JS clones on "Add argument".
 */
class Arguments extends Template implements TabInterface
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param FormDataPersistence $formDataPersistence
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly FormDataPersistence $formDataPersistence,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return string
     */
    public function getTabLabel()
    {
        return (string) __('Arguments');
    }

    /**
     * @return string
     */
    public function getTabTitle()
    {
        return $this->getTabLabel();
    }

    /**
     * @return bool
     */
    public function canShowTab(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function isHidden(): bool
    {
        return false;
    }

    /**
     * @return array<int, array{name: string, description: string, required: bool}>
     */
    public function getArgumentsForRender(): array
    {
        $restored = $this->formDataPersistence->get();
        if (is_array($restored) && isset($restored['arguments']) && is_array($restored['arguments'])) {
            return $this->normaliseRestoredArguments($restored['arguments']);
        }

        $prompt = $this->registry->registry(EditController::REGISTRY_KEY);
        if ($prompt instanceof AdminPrompt) {
            return $prompt->getArguments();
        }
        return [];
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return array<int, array{name: string, description: string, required: bool}>
     */
    private function normaliseRestoredArguments(array $raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = isset($row['name']) && is_scalar($row['name']) ? (string) $row['name'] : '';
            if ($name === '') {
                continue;
            }
            $description = isset($row['description']) && is_scalar($row['description'])
                ? (string) $row['description']
                : '';
            $required = isset($row['required']) && is_scalar($row['required'])
                && in_array((string) $row['required'], ['1', 'on', 'true'], true);
            $out[] = [
                'name' => $name,
                'description' => $description,
                'required' => $required,
            ];
        }
        return $out;
    }
}
