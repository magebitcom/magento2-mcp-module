<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\OAuthClient\Edit;

use Magebit\Mcp\Api\Data\OAuth\ClientInterface;
use Magebit\Mcp\Controller\Adminhtml\OAuthClient\Edit as EditController;
use Magento\Backend\Block\Widget\Form\Generic;

/**
 * Renders the OAuth client form. Two fields on create — Name + Redirect URIs —
 * plus a read-only Client ID display and a non-recoverable-secret hint when
 * editing an existing client. Posts to {@see \Magebit\Mcp\Controller\Adminhtml\OAuthClient\Save}.
 */
class Form extends Generic
{
    /**
     * @return $this
     */
    protected function _prepareForm(): self
    {
        $client = $this->_coreRegistry->registry(EditController::REGISTRY_KEY);
        $isExisting = $client instanceof ClientInterface;

        /** @var \Magento\Framework\Data\Form $form */
        $form = $this->_formFactory->create([
            'data' => [
                'id' => 'edit_form',
                'action' => $this->getUrl('magebit_mcp/oauthclient/save'),
                'method' => 'post',
                'enctype' => 'multipart/form-data',
            ],
        ]);
        $form->setHtmlIdPrefix('magebit_mcp_oauth_client_');

        if ($isExisting) {
            /** @var ClientInterface $client */
            $form->addField('id', 'hidden', ['name' => 'id'])
                ->setValue((string) (int) $client->getId());
        }

        $fieldset = $form->addFieldset('base_fieldset', [
            'legend' => __('OAuth Client'),
        ]);

        $nameField = $fieldset->addField('name', 'text', [
            'name' => 'name',
            'label' => __('Name'),
            'title' => __('Name'),
            'required' => true,
            'class' => 'validate-length maximum-length-128',
            'maxlength' => 128,
            'note' => __('A human-readable label, e.g. "Claude Web".'),
        ]);
        $nameField->setValue($isExisting ? $client->getName() : '');

        $urisField = $fieldset->addField('redirect_uris', 'textarea', [
            'name' => 'redirect_uris',
            'label' => __('Redirect URIs'),
            'title' => __('Redirect URIs'),
            'required' => true,
            'note' => __(
                'One URI per line. Must be HTTPS (or http://localhost / http://127.0.0.1 for'
                . ' development). Exact match — no trailing-slash drift.'
            ),
        ]);
        $urisField->setValue($isExisting ? implode("\n", $client->getRedirectUris()) : '');

        if ($isExisting) {
            /** @var ClientInterface $client */
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

        $form->setUseContainer(true);
        $this->setForm($form);
        return parent::_prepareForm();
    }
}
