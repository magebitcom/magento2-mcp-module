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
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Form\Generic;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Data\Form;
use Magento\Framework\Data\Form\Element\Fieldset;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;

class Info extends Generic implements TabInterface
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param FormFactory $formFactory
     * @param FormDataPersistence $formDataPersistence
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        private readonly FormDataPersistence $formDataPersistence,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $data);
    }

    /**
     * @return string
     */
    public function getTabLabel()
    {
        return (string) __('General');
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
     * @return $this
     */
    protected function _prepareForm(): self
    {
        $promptRaw = $this->_coreRegistry->registry(EditController::REGISTRY_KEY);
        $prompt = $promptRaw instanceof AdminPrompt ? $promptRaw : null;

        /** @var Form $form */
        $form = $this->_formFactory->create();
        $form->setHtmlIdPrefix('magebit_mcp_prompt_');

        if ($prompt !== null) {
            $form->addField('id', 'hidden', ['name' => 'id'])
                ->setValue((string) (int) $prompt->getId());
        }

        $fieldset = $form->addFieldset('base_fieldset', ['legend' => __('Prompt')]);
        $this->addBaseFields($fieldset, $prompt);

        $configurationFieldset = $form->addFieldset('configuration_fieldset', [
            'legend' => __('Configuration'),
            'collapsable' => false,
        ]);
        $this->addConfigurationFields($configurationFieldset);

        $bodyFieldset = $form->addFieldset('body_fieldset', [
            'legend' => __('Body'),
            'collapsable' => false,
        ]);
        $this->addBodyField($bodyFieldset);

        $form->setValues($this->getRestoredValues($prompt));
        $this->setForm($form);
        return parent::_prepareForm();
    }

    /**
     * @param Fieldset $fieldset
     * @param AdminPrompt|null $prompt
     * @return void
     */
    private function addBaseFields(Fieldset $fieldset, ?AdminPrompt $prompt): void
    {
        // The 'custom.' prefix is enforced server-side; we render it as a
        // static label next to the suffix input to make the final name clear.
        $fieldset->addField('name_suffix', 'text', [
            'name' => 'name_suffix',
            'label' => __('Name'),
            'title' => __('Name'),
            'required' => true,
            'class' => 'validate-length maximum-length-88',
            'maxlength' => 88,
            'note' => __(
                'A short slug the assistant uses to find this prompt — e.g. <code>greet_customer</code>.'
                . ' Saved as <code>custom.&lt;slug&gt;</code>. Lowercase letters, numbers, dots, and'
                . ' underscores only.'
            ),
            'after_element_html' => $this->renderNamePrefixHint(),
        ]);

        if ($prompt !== null) {
            $fieldset->addField('full_name_display', 'label', [
                'label' => __('Full Name'),
                'value' => $prompt->getName(),
                'note' => __('Read-only — what MCP clients see in prompts/list.'),
            ]);
        }

        $fieldset->addField('title', 'text', [
            'name' => 'title',
            'label' => __('Title'),
            'title' => __('Title'),
            'required' => true,
            'class' => 'validate-length maximum-length-255',
            'maxlength' => 255,
            'note' => __(
                'Short user-friendly label shown in the MCP client\'s prompt menu — e.g. "Welcome a new'
                . ' customer".'
            ),
        ]);

        $fieldset->addField('description', 'text', [
            'name' => 'description',
            'label' => __('Description'),
            'title' => __('Description'),
            'class' => 'validate-length maximum-length-512',
            'maxlength' => 512,
            'note' => __(
                'One-line helper text shown under the title. Optional but recommended.'
            ),
        ]);
    }

    /**
     * @param Fieldset $fieldset
     * @return void
     */
    private function addConfigurationFields(Fieldset $fieldset): void
    {
        $fieldset->addField('is_active', 'select', [
            'name' => 'is_active',
            'label' => __('Active'),
            'title' => __('Active'),
            'values' => [
                ['value' => '1', 'label' => __('Yes — visible to MCP clients')],
                ['value' => '0', 'label' => __('No — hidden from MCP clients')],
            ],
            'note' => __(
                'Inactive prompts are kept in the database but filtered out of the prompt menu.'
            ),
        ]);

        $fieldset->addField('requires_write', 'select', [
            'name' => 'requires_write',
            'label' => __('Requires Write Tools'),
            'title' => __('Requires Write Tools'),
            'values' => [
                ['value' => '0', 'label' => __('No — read-only')],
                ['value' => '1', 'label' => __('Yes — needs write tools')],
            ],
            'note' => __(
                'Turn this on when the body nudges the assistant to call write tools (saving,'
                . ' creating, deleting). The prompt is then hidden from MCP clients that don\'t'
                . ' have write access.'
            ),
        ]);
    }

    /**
     * @param Fieldset $fieldset
     * @return void
     */
    private function addBodyField(Fieldset $fieldset): void
    {
        $fieldset->addField('body', 'textarea', [
            'name' => 'body',
            'label' => __('Body'),
            'title' => __('Body'),
            'required' => true,
            'note' => __(
                'This is what the assistant will read. Use <code>{{argument_name}}</code> to refer to'
                . ' the arguments you declare on the Arguments tab — for example,'
                . ' <code>{{customer_name}}</code>.'
            ),
            'style' => 'height: 240px;',
        ]);
    }

    /**
     * Static <code>custom.</code> prefix rendered immediately before the suffix
     * input so the admin sees the final name they are about to save.
     *
     * @return string
     */
    private function renderNamePrefixHint(): string
    {
        return '<span class="magebit-mcp-prompt-name-prefix" style="margin-left:8px;color:#666;">&larr; '
            . 'saved as <code>custom.&lt;slug&gt;</code></span>';
    }

    /**
     * @param AdminPrompt|null $prompt
     * @return array<string, mixed>
     */
    private function getRestoredValues(?AdminPrompt $prompt): array
    {
        $defaults = [
            'name_suffix' => $prompt !== null ? $this->stripPrefix($prompt->getName()) : '',
            'title' => $prompt !== null ? $prompt->getTitle() : '',
            'description' => $prompt !== null ? $prompt->getDescription() : '',
            'body' => $prompt !== null ? $prompt->getBody() : '',
            'is_active' => $prompt !== null && !$prompt->isActive() ? '0' : '1',
            'requires_write' => $prompt !== null && $prompt->getRequiresWrite() ? '1' : '0',
        ];

        $restored = $this->formDataPersistence->get();
        if ($restored === null) {
            return $defaults;
        }

        foreach (['name_suffix', 'title', 'description', 'body'] as $key) {
            if (isset($restored[$key]) && is_scalar($restored[$key])) {
                $defaults[$key] = (string) $restored[$key];
            }
        }
        foreach (['is_active', 'requires_write'] as $key) {
            if (isset($restored[$key]) && is_scalar($restored[$key])) {
                $defaults[$key] = (string) ((int) $restored[$key]);
            }
        }
        return $defaults;
    }

    /**
     * @param string $name
     * @return string
     */
    private function stripPrefix(string $name): string
    {
        if (str_starts_with($name, AdminPrompt::NAME_PREFIX)) {
            return substr($name, strlen(AdminPrompt::NAME_PREFIX));
        }
        return $name;
    }
}
