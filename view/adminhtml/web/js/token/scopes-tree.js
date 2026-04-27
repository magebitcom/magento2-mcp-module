/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 *
 * MCP token "Resource Access" tab widget. Forked from mage.rolesTree with
 * three behaviours added:
 *  1. Honours node `state.disabled` so scopes the chosen admin user's role
 *     does not grant cannot be checked (server-side guard mirrors this).
 *  2. Re-fetches the tree from a controller route whenever the admin-user
 *     dropdown changes — selections still allowed under the new role are
 *     preserved, the rest dropped.
 *  3. Toggles the tree container visibility based on the All/Custom select.
 */
define([
    'jquery',
    'jquery/ui',
    'jquery/jstree/jquery.jstree'
], function ($) {
    'use strict';

    $.widget('mage.mcpScopesTree', {
        options: {
            treeUrl: '',
            editFormSelector: '#edit_form',
            adminUserSelector: '',
            allResourcesSelector: '',
            treeContainerSelector: '',
            noAdminPlaceholderSelector: '',
            treeWrapperSelector: '',
            resourceFieldName: 'resource[]',
            initialTree: [],
            initialSelection: []
        },

        _create: function () {
            this._bindToggleAllResources();
            this._bindAdminUserChange();
            this._bindFormSubmitGuard();
            this._renderTree(this.options.initialTree || [], this.options.initialSelection || []);
            this._refreshAdminPlaceholder();
            this._syncSelections();
        },

        _refreshAdminPlaceholder: function () {
            var hasAdmin = this._currentAdminUserId() > 0;
            if (this.options.noAdminPlaceholderSelector) {
                $(this.options.noAdminPlaceholderSelector).toggleClass('no-display', hasAdmin);
            }
            if (this.options.treeWrapperSelector) {
                $(this.options.treeWrapperSelector).toggleClass('no-display', !hasAdmin);
            }
        },

        _currentAdminUserId: function () {
            if (!this.options.adminUserSelector) {
                return 0;
            }
            var raw = $(this.options.adminUserSelector).val();
            return parseInt(raw, 10) || 0;
        },

        _renderTree: function (treeData, selection) {
            var element = this.element;
            if (element.data('jstree')) {
                element.jstree('destroy');
            }

            var dataWithSelection = this._applySelection(
                this._cloneTree(treeData),
                Array.isArray(selection) ? selection : []
            );

            element.jstree({
                plugins: ['checkbox'],
                checkbox: {
                    three_state: false,
                    cascade: 'undetermined',
                    visible: true
                },
                core: {
                    data: dataWithSelection,
                    themes: { dots: false },
                    check_callback: function (operation, node) {
                        if (operation === 'check_node' || operation === 'select_node') {
                            return !(node && node.state && node.state.disabled);
                        }
                        return true;
                    }
                }
            });

            element.off('.mcpScopesTree');
            element.on('select_node.jstree.mcpScopesTree', $.proxy(this._selectChildNodes, this));
            element.on('deselect_node.jstree.mcpScopesTree', $.proxy(this._deselectChildNodes, this));
            element.on('changed.jstree.mcpScopesTree', $.proxy(this._syncSelections, this));
        },

        _cloneTree: function (nodes) {
            try {
                return JSON.parse(JSON.stringify(nodes || []));
            } catch (e) {
                return [];
            }
        },

        _applySelection: function (nodes, selection) {
            var i;
            if (!Array.isArray(nodes)) {
                return [];
            }
            for (i = 0; i < nodes.length; i++) {
                if (!nodes[i]) {
                    continue;
                }
                if (!nodes[i].state) {
                    nodes[i].state = {};
                }
                if (selection.indexOf(nodes[i].id) !== -1 && !nodes[i].state.disabled) {
                    nodes[i].state.selected = true;
                }
                if (Array.isArray(nodes[i].children) && nodes[i].children.length) {
                    nodes[i].children = this._applySelection(nodes[i].children, selection);
                }
            }
            return nodes;
        },

        _selectChildNodes: function (event, selected) {
            selected.instance.open_node(selected.node);
            selected.node.children.forEach(function (id) {
                var child = selected.instance.get_node(id);
                if (child && (!child.state || !child.state.disabled)) {
                    selected.instance.select_node(child);
                }
            });
        },

        _deselectChildNodes: function (event, selected) {
            selected.node.children.forEach(function (id) {
                var child = selected.instance.get_node(id);
                if (child) {
                    selected.instance.deselect_node(child);
                }
            });
        },

        _bindAdminUserChange: function () {
            var widget = this;
            if (!this.options.adminUserSelector) {
                return;
            }
            $(document).on('change.mcpScopesTree', this.options.adminUserSelector, function () {
                var preserved = widget._collectSelectedIds();
                widget._fetchTree($(this).val(), preserved);
                widget._refreshAdminPlaceholder();
            });
        },

        _bindToggleAllResources: function () {
            var widget = this;
            if (!this.options.allResourcesSelector || !this.options.treeContainerSelector) {
                return;
            }
            $(document).on('change.mcpScopesTree', this.options.allResourcesSelector, function () {
                var allSelected = $(this).val() === '1';
                $(widget.options.treeContainerSelector).toggleClass('no-display', allSelected);
                widget._syncSelections();
            });
        },

        _bindFormSubmitGuard: function () {
            var widget = this;
            $(document).on('submit.mcpScopesTree', this.options.editFormSelector, function () {
                widget._syncSelections();
            });
        },

        _fetchTree: function (adminUserId, preservedSelection) {
            var widget = this;
            $.ajax({
                url: this.options.treeUrl,
                method: 'GET',
                dataType: 'json',
                data: { admin_user_id: adminUserId || 0 }
            }).done(function (response) {
                if (response && Array.isArray(response.tree)) {
                    widget._renderTree(response.tree, preservedSelection || []);
                    widget._syncSelections();
                }
            });
        },

        _collectSelectedIds: function () {
            if (!this.element.data('jstree')) {
                return [];
            }
            var instance = this.element.jstree(true);
            if (!instance) {
                return [];
            }
            var selected = instance.get_checked() || [];
            var undetermined = instance.get_undetermined() || [];
            return selected.concat(undetermined);
        },

        _syncSelections: function () {
            var form = $(this.options.editFormSelector);
            if (!form.length) {
                return;
            }
            form.find('input[name="' + this.options.resourceFieldName + '"]').remove();

            var allResourcesField = $(this.options.allResourcesSelector);
            if (allResourcesField.length && allResourcesField.val() === '1') {
                return;
            }

            var ids = this._collectSelectedIds();
            var fieldName = this.options.resourceFieldName;
            ids.forEach(function (id) {
                $('<input>', {
                    type: 'hidden',
                    name: fieldName,
                    value: id
                }).appendTo(form);
            });
        },

        _destroy: function () {
            if (this.element.data('jstree')) {
                this.element.jstree('destroy');
            }
            this.element.off('.mcpScopesTree');
            $(document).off('.mcpScopesTree');
        }
    });

    return $.mage.mcpScopesTree;
});
