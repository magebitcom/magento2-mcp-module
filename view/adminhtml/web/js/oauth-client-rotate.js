/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
define([
    'jquery',
    'Magento_Ui/js/modal/modal'
], function ($, modal) {
    'use strict';

    return function () {
        var $el = $('#mcp-rotate-secret-modal');
        if ($el.length === 0 || $el.data('mcpRotateInit')) {
            return;
        }
        $el.data('mcpRotateInit', true);

        modal({
            type: 'popup',
            responsive: true,
            innerScroll: true,
            title: $.mage.__('Rotate Client Secret'),
            buttons: [
                {
                    text: $.mage.__('Cancel'),
                    class: 'action-secondary',
                    click: function () {
                        this.closeModal();
                    }
                },
                {
                    text: $.mage.__('Rotate Secret'),
                    class: 'action-primary',
                    click: function () {
                        var form = document.getElementById('mcp-rotate-secret-form');
                        if (form) {
                            form.submit();
                        }
                    }
                }
            ]
        }, $el);

        $(document).on('click', '[data-mcp-action="rotate-secret"]', function (event) {
            event.preventDefault();
            $el.modal('openModal');
        });
    };
});
