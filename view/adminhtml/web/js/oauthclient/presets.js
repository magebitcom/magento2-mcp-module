/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 *
 * OAuth client form "Preset" dropdown wiring. On <select> change we replace
 * the Name input value and the Redirect URIs textarea contents. The "custom"
 * id clears both. No magic — admins can still edit anything afterwards.
 */
define([
    'jquery',
    'jquery/ui'
], function ($) {
    'use strict';

    $.widget('mage.mcpClientPresets', {
        options: {
            presets: {},
            nameSelector: '',
            urisSelector: '',
            triggerSelector: '#magebit_mcp_oauth_client_preset'
        },

        _create: function () {
            var widget = this;
            // The element this widget is initialised on is a hidden marker;
            // bind to the actual <select> (which Magento renders elsewhere).
            $(document).on('change.mcpClientPresets', this.options.triggerSelector, function () {
                widget._apply($(this).val());
            });
        },

        _apply: function (presetId) {
            var presets = this.options.presets || {};
            var preset = presets[presetId] || { name: '', redirect_uris: [] };

            if (this.options.nameSelector) {
                $(this.options.nameSelector).val(preset.name || '');
            }
            if (this.options.urisSelector) {
                var uris = Array.isArray(preset.redirect_uris) ? preset.redirect_uris : [];
                $(this.options.urisSelector).val(uris.join('\n'));
            }
        },

        _destroy: function () {
            $(document).off('.mcpClientPresets');
        }
    });

    return $.mage.mcpClientPresets;
});
