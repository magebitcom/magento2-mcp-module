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
use Magebit\Mcp\Model\OAuth\AuthMode;
use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Form\Generic;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Data\Form;
use Magento\Framework\Data\Form\Element\Fieldset;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;

/**
 * "Client Info" tab — preset dropdown, Name, Redirect URIs, Authorization
 * fieldset. On edit also shows the read-only Client ID and secret-rotation note.
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
     * @param UserCollectionFactory $userCollectionFactory
     * @param RoleCollectionFactory $roleCollectionFactory
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
        private readonly UserCollectionFactory $userCollectionFactory,
        private readonly RoleCollectionFactory $roleCollectionFactory,
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

        $authFieldset = $form->addFieldset('authorization_fieldset', [
            'legend' => __('Authorization'),
            'collapsable' => false,
        ]);
        $this->addAuthorizationFields($authFieldset);

        $form->setValues($this->getRestoredValues($client));
        $this->setForm($form);
        return parent::_prepareForm();
    }

    /**
     * Preset dropdown shown only on the create page; the widget marker is smuggled
     * in via after_element_html because adminhtml form fields don't render data-mage-init.
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
                'The client secret is shown only once. Use the Rotate Secret button above to'
                . ' generate a new one — the Client ID stays the same.'
            ),
        ]);
    }

    /**
     * @param Fieldset $fieldset
     */
    private function addAuthorizationFields(Fieldset $fieldset): void
    {
        $adminUsers = $this->getAdminUserOptions();
        $adminRoles = $this->getAdminRoleOptions();

        $this->addAuthModeField($fieldset);
        $this->addServiceAdminField($fieldset, $adminUsers);
        $this->addAdminUserWhitelistField($fieldset, $adminUsers);
        $this->addAdminRoleWhitelistField($fieldset, $adminRoles);
        $this->addDisabledField($fieldset);
    }

    /**
     * @param Fieldset $fieldset
     */
    private function addAuthModeField(Fieldset $fieldset): void
    {
        $fieldset->addField('auth_mode', 'select', [
            'name' => 'auth_mode',
            'label' => __('Auth Mode'),
            'title' => __('Auth Mode'),
            'required' => true,
            'values' => [
                ['value' => AuthMode::PERSONAL->value, 'label' => __('Personal — each admin authorizes for themselves')],
                ['value' => AuthMode::SHARED->value, 'label' => __('Shared — pin one admin for the whole org')],
            ],
            'note' => __(
                'Choose <em>Shared</em> when a Claude organization installs this connector once and many'
                . ' org members will use it; every token gets issued on behalf of the same pinned admin.'
                . ' Choose <em>Personal</em> for single-user connections.'
            ),
            'after_element_html' => $this->renderAuthModeToggleInit(),
        ]);
    }

    /**
     * @param Fieldset $fieldset
     * @param array<int, array{value: string, label: string}> $adminUsers
     */
    private function addServiceAdminField(Fieldset $fieldset, array $adminUsers): void
    {
        $fieldset->addField('service_admin_user_id', 'select', [
            'name' => 'service_admin_user_id',
            'label' => __('Service Admin User'),
            'title' => __('Service Admin User'),
            'values' => array_merge(
                [['value' => '', 'label' => __('— Select admin —')]],
                $adminUsers
            ),
            'note' => __(
                'Required for <strong>Shared</strong> mode. Every token issued through this client is'
                . ' bound to this admin, and only this admin can complete the OAuth consent screen.'
            ),
        ]);
    }

    /**
     * @param Fieldset $fieldset
     * @param array<int, array{value: string, label: string}> $adminUsers
     */
    private function addAdminUserWhitelistField(Fieldset $fieldset, array $adminUsers): void
    {
        $fieldset->addField('allowed_admin_user_ids', 'multiselect', [
            'name' => 'allowed_admin_user_ids[]',
            'label' => __('Allowed Admin Users'),
            'title' => __('Allowed Admin Users'),
            'values' => $adminUsers,
            'note' => __(
                'Optional whitelist (Personal mode). Only the selected admins may authorize this'
                . ' client. Leave empty for no per-user restriction. Union with Allowed Admin Roles —'
                . ' an admin matched by either list may authorize.'
            ),
        ]);
    }

    /**
     * @param Fieldset $fieldset
     * @param array<int, array{value: string, label: string}> $adminRoles
     */
    private function addAdminRoleWhitelistField(Fieldset $fieldset, array $adminRoles): void
    {
        $fieldset->addField('allowed_admin_role_ids', 'multiselect', [
            'name' => 'allowed_admin_role_ids[]',
            'label' => __('Allowed Admin Roles'),
            'title' => __('Allowed Admin Roles'),
            'values' => $adminRoles,
            'note' => __(
                'Optional whitelist (Personal mode). Any admin in a selected role may authorize. Leave'
                . ' empty for no per-role restriction. Recommended for orgs with rotating staff —'
                . ' add new admins to the role rather than editing the list each time.'
            ),
        ]);
    }

    /**
     * @param Fieldset $fieldset
     */
    private function addDisabledField(Fieldset $fieldset): void
    {
        $fieldset->addField('disabled', 'select', [
            'name' => 'disabled',
            'label' => __('Disabled'),
            'title' => __('Disabled'),
            'values' => [
                ['value' => '0', 'label' => __('No — client is active')],
                ['value' => '1', 'label' => __('Yes — block new authorizations and refreshes')],
            ],
            'note' => __(
                'Disabling preserves the audit trail and existing access tokens (until they expire),'
                . ' but blocks new consents and refresh-token rotations. Use this instead of deleting'
                . ' when retiring a connector.'
            ),
        ]);
    }

    /**
     * @return string
     */
    private function renderAuthModeToggleInit(): string
    {
        $serialized = $this->jsonSerializer->serialize([
            'Magebit_Mcp/js/oauth-client-auth-mode-toggle' => [],
        ]);
        return sprintf(
            '<script type="text/x-magento-init">{"*": %s}</script>',
            is_string($serialized) ? $serialized : '{}'
        );
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function getAdminUserOptions(): array
    {
        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToFilter('is_active', ['eq' => 1]);
        $collection->setOrder('username', 'ASC');
        $options = [];
        foreach ($collection->getItems() as $user) {
            $id = $user->getId();
            if ($id === null) {
                continue;
            }
            $username = self::scalarToString($user->getData('username'));
            $firstName = self::scalarToString($user->getData('firstname'));
            $lastName = self::scalarToString($user->getData('lastname'));
            $fullName = trim($firstName . ' ' . $lastName);
            $label = $fullName !== ''
                ? sprintf('%s (%s)', $username, $fullName)
                : $username;
            $options[] = ['value' => (string) $id, 'label' => $label];
        }
        return $options;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function getAdminRoleOptions(): array
    {
        $collection = $this->roleCollectionFactory->create();
        $collection->setRolesFilter();
        $collection->setOrder('role_name', 'ASC');
        $options = [];
        foreach ($collection->getItems() as $role) {
            $id = $role->getId();
            if ($id === null) {
                continue;
            }
            $name = self::scalarToString($role->getData('role_name'));
            if ($name === '') {
                continue;
            }
            $options[] = ['value' => (string) $id, 'label' => $name];
        }
        return $options;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function scalarToString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
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
     * @return array<string, string|array<int, string>>
     */
    private function getRestoredValues(?ClientInterface $client): array
    {
        $defaults = [
            'preset' => '',
            'name' => $client !== null ? $client->getName() : '',
            'redirect_uris' => $client !== null ? implode("\n", $client->getRedirectUris()) : '',
            'auth_mode' => $client !== null
                ? $client->getAuthMode()->value
                : AuthMode::PERSONAL->value,
            'service_admin_user_id' => $client !== null && $client->getServiceAdminUserId() !== null
                ? (string) $client->getServiceAdminUserId()
                : '',
            'allowed_admin_user_ids' => $client !== null
                ? array_map('strval', $client->getAllowedAdminUserIds())
                : [],
            'allowed_admin_role_ids' => $client !== null
                ? array_map('strval', $client->getAllowedAdminRoleIds())
                : [],
            'disabled' => $client !== null && $client->isDisabled() ? '1' : '0',
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
        if (isset($restored['auth_mode']) && is_scalar($restored['auth_mode'])) {
            $defaults['auth_mode'] = (string) $restored['auth_mode'];
        }
        if (isset($restored['service_admin_user_id']) && is_scalar($restored['service_admin_user_id'])) {
            $defaults['service_admin_user_id'] = (string) $restored['service_admin_user_id'];
        }
        if (isset($restored['allowed_admin_user_ids']) && is_array($restored['allowed_admin_user_ids'])) {
            $defaults['allowed_admin_user_ids'] = array_values(array_map(
                'strval',
                array_filter($restored['allowed_admin_user_ids'], 'is_scalar')
            ));
        }
        if (isset($restored['allowed_admin_role_ids']) && is_array($restored['allowed_admin_role_ids'])) {
            $defaults['allowed_admin_role_ids'] = array_values(array_map(
                'strval',
                array_filter($restored['allowed_admin_role_ids'], 'is_scalar')
            ));
        }
        if (isset($restored['disabled']) && is_scalar($restored['disabled'])) {
            $defaults['disabled'] = (string) ((int) $restored['disabled']);
        }
        return $defaults;
    }
}
