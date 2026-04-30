<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\OAuthClient\Edit\Tab;

use Magebit\Mcp\Api\Data\OAuth\ClientInterface;
use Magebit\Mcp\Api\OAuth\ClientPresetProviderInterface;
use Magebit\Mcp\Controller\Adminhtml\OAuthClient\Edit as EditController;
use Magebit\Mcp\Model\Adminhtml\FormDataPersistence;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Form\Generic;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Data\Form;
use Magento\Framework\Data\Form\Element\Fieldset;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * "Client Info" tab — preset dropdown + Name + Redirect URIs textarea, plus
 * a read-only Client ID display + secret-rotation note when editing an
 * existing row. The preset dropdown is wired by `view/adminhtml/web/js/
 * oauthclient/presets.js` to autofill Name + Redirect URIs.
 */
class Info extends Generic implements TabInterface
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param FormFactory $formFactory
     * @param ClientPresetProviderInterface $presetProvider
     * @param FormDataPersistence $formDataPersistence
     * @param Json $jsonSerializer
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        private readonly ClientPresetProviderInterface $presetProvider,
        private readonly FormDataPersistence $formDataPersistence,
        private readonly Json $jsonSerializer,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $data);
    }

    /**
     * @return string
     */
    public function getTabLabel()
    {
        return (string) __('Client Info');
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
        $clientRaw = $this->_coreRegistry->registry(EditController::REGISTRY_KEY);
        $client = $clientRaw instanceof ClientInterface ? $clientRaw : null;

        /** @var Form $form */
        $form = $this->_formFactory->create();
        $form->setHtmlIdPrefix('magebit_mcp_oauth_client_');

        if ($client !== null) {
            $form->addField('id', 'hidden', ['name' => 'id'])
                ->setValue((string) (int) $client->getId());
        }

        $fieldset = $form->addFieldset('base_fieldset', ['legend' => __('OAuth Client')]);

        if ($client === null) {
            $this->addPresetField($fieldset);
        }
        $this->addBaseFields($fieldset);
        if ($client !== null) {
            $this->addExistingClientFields($fieldset, $client);
        }

        $form->setValues($this->getRestoredValues($client));
        $this->setForm($form);
        return parent::_prepareForm();
    }

    /**
     * Preset dropdown rendered only on the create page. The widget marker is
     * smuggled in via `after_element_html` because adminhtml form fields don't
     * render data-mage-init themselves; the marker span is hidden.
     *
     * @param Fieldset $fieldset
     */
    private function addPresetField(Fieldset $fieldset): void
    {
        $options = [];
        foreach ($this->presetProvider->getAll() as $preset) {
            $options[] = ['value' => $preset->getId(), 'label' => $preset->getLabel()];
        }
        $fieldset->addField('preset', 'select', [
            'name' => 'preset',
            'label' => __('Preset'),
            'title' => __('Preset'),
            'values' => $options,
            'note' => __(
                'Pick a known MCP client to autofill Name and Redirect URIs. You can'
                . ' edit either field afterwards.'
            ),
            'after_element_html' => $this->renderPresetWidgetInit(),
        ]);
    }

    /**
     * @param Fieldset $fieldset
     */
    private function addBaseFields(Fieldset $fieldset): void
    {
        $fieldset->addField('name', 'text', [
            'name' => 'name',
            'label' => __('Name'),
            'title' => __('Name'),
            'required' => true,
            'class' => 'validate-length maximum-length-128',
            'maxlength' => 128,
            'note' => __('A human-readable label, e.g. "Claude Web".'),
        ]);

        $fieldset->addField('redirect_uris', 'textarea', [
            'name' => 'redirect_uris',
            'label' => __('Redirect URIs'),
            'title' => __('Redirect URIs'),
            'required' => true,
            'note' => __(
                'One URI per line. Must be HTTPS (or http://localhost / http://127.0.0.1 for'
                . ' development). Exact match — no trailing-slash drift.'
            ),
        ]);
    }

    /**
     * @param Fieldset $fieldset
     * @param ClientInterface $client
     */
    private function addExistingClientFields(Fieldset $fieldset, ClientInterface $client): void
    {
        $fieldset->addField('client_id_display', 'label', [
            'label' => __('Client ID'),
            'value' => $client->getClientId(),
            'note' => __('Read-only — share this with the OAuth client alongside the secret.'),
        ]);
        $fieldset->addField('client_secret_note', 'note', [
            'label' => __('Client Secret'),
            'text' => __(
                'The client secret is shown only once at creation time. To rotate, delete'
                . ' this client and create a new one.'
            ),
        ]);
    }

    /**
     * @return string
     */
    private function renderPresetWidgetInit(): string
    {
        $payload = [];
        foreach ($this->presetProvider->getAll() as $preset) {
            $payload[$preset->getId()] = [
                'name' => $preset->getName(),
                'redirect_uris' => $preset->getRedirectUris(),
            ];
        }
        $config = [
            'mcpClientPresets' => [
                'presets' => $payload,
                'nameSelector' => '#magebit_mcp_oauth_client_name',
                'urisSelector' => '#magebit_mcp_oauth_client_redirect_uris',
            ],
        ];
        $serialized = $this->jsonSerializer->serialize($config);
        return sprintf(
            '<span data-mage-init=\'%s\' style="display:none;"></span>',
            $this->escapeHtmlAttr(is_string($serialized) ? $serialized : '{}')
        );
    }

    /**
     * @param ClientInterface|null $client
     * @return array<string, string>
     */
    private function getRestoredValues(?ClientInterface $client): array
    {
        $defaults = [
            'preset' => '',
            'name' => $client !== null ? $client->getName() : '',
            'redirect_uris' => $client !== null ? implode("\n", $client->getRedirectUris()) : '',
        ];

        $restored = $this->formDataPersistence->get();
        if ($restored === null) {
            return $defaults;
        }

        if (isset($restored['name']) && is_scalar($restored['name'])) {
            $defaults['name'] = (string) $restored['name'];
        }
        if (isset($restored['redirect_uris']) && is_scalar($restored['redirect_uris'])) {
            $defaults['redirect_uris'] = (string) $restored['redirect_uris'];
        }
        return $defaults;
    }
}
