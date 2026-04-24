define([
    'underscore',
    'ko',
    'Magento_Ui/js/form/element/multiselect'
], function (_, ko, Multiselect) {
    'use strict';

    var DEBOUNCE_MS = 150;
    var OTHER_GROUP_KEY = '__other__';
    var OTHER_GROUP_LABEL = 'Other';

    function titleCase(key) {
        if (!key) {
            return '';
        }
        return key.charAt(0).toUpperCase() + key.slice(1);
    }

    return Multiselect.extend({
        defaults: {
            elementTmpl: 'Magebit_Mcp/form/element/scope-selector',
            query: '',
            debouncedQuery: '',
            expandedGroups: {},
            tracks: {
                query: true,
                debouncedQuery: true,
                expandedGroups: true
            },
            listens: {
                query: 'onQueryChange'
            }
        },

        initialize: function () {
            this._super();
            this._queryTimer = null;

            var initial = this.value() || [];
            this.allowAll = ko.observable(initial.length === 0);

            var self = this;
            this.allowAll.subscribe(function (on) {
                if (on) {
                    self.value([]);
                    self.clearSearch();
                }
            });

            return this;
        },

        /**
         * Debounce query → debouncedQuery so KO recomputes don't thrash on every keystroke.
         */
        onQueryChange: function (value) {
            var self = this;
            if (this._queryTimer) {
                clearTimeout(this._queryTimer);
            }
            this._queryTimer = setTimeout(function () {
                self.debouncedQuery = value || '';
            }, DEBOUNCE_MS);
        },

        clearSearch: function () {
            this.query = '';
            this.debouncedQuery = '';
            if (this._queryTimer) {
                clearTimeout(this._queryTimer);
                this._queryTimer = null;
            }
        },

        /**
         * Derive groups from options. Key = first dot-segment of option.value.
         */
        groups: function () {
            var options = this.options() || [];
            var selected = this.value() || [];
            var selectedSet = {};
            selected.forEach(function (v) {
                selectedSet[v] = true;
            });

            var buckets = {};
            options.forEach(function (opt) {
                var value = String(opt.value);
                var dotIdx = value.indexOf('.');
                var key = dotIdx > 0 ? value.substring(0, dotIdx) : OTHER_GROUP_KEY;
                if (!buckets[key]) {
                    buckets[key] = [];
                }
                buckets[key].push(opt);
            });

            var groupList = Object.keys(buckets).map(function (key) {
                var items = buckets[key].slice().sort(function (a, b) {
                    return String(a.value).localeCompare(String(b.value));
                });
                var selectedCount = 0;
                items.forEach(function (item) {
                    if (selectedSet[item.value]) {
                        selectedCount++;
                    }
                });
                return {
                    key: key,
                    label: key === OTHER_GROUP_KEY ? OTHER_GROUP_LABEL : titleCase(key),
                    items: items,
                    totalCount: items.length,
                    selectedCount: selectedCount,
                    allSelected: selectedCount > 0 && selectedCount === items.length,
                    someSelected: selectedCount > 0 && selectedCount < items.length
                };
            });

            groupList.sort(function (a, b) {
                if (a.key === OTHER_GROUP_KEY) return 1;
                if (b.key === OTHER_GROUP_KEY) return -1;
                return a.label.localeCompare(b.label);
            });

            return groupList;
        },

        /**
         * Apply debounced search. Groups with zero matches are dropped.
         */
        filteredGroups: function () {
            var query = (this.debouncedQuery || '').trim().toLowerCase();
            var groups = this.groups();
            if (!query) {
                return groups;
            }
            return groups
                .map(function (group) {
                    var matches = group.items.filter(function (item) {
                        return String(item.value).toLowerCase().indexOf(query) !== -1
                            || String(item.label).toLowerCase().indexOf(query) !== -1;
                    });
                    if (matches.length === 0) {
                        return null;
                    }
                    return _.extend({}, group, {
                        items: matches,
                        filtered: true
                    });
                })
                .filter(function (g) { return g !== null; });
        },

        isGroupExpanded: function (key) {
            if (this.debouncedQuery) {
                return true;
            }
            return !!this.expandedGroups[key];
        },

        toggleGroup: function (key) {
            if (this.debouncedQuery) {
                return;
            }
            var next = _.extend({}, this.expandedGroups);
            next[key] = !next[key];
            this.expandedGroups = next;
        },

        isSelected: function (value) {
            var current = this.value() || [];
            return current.indexOf(value) !== -1;
        },

        toggleItem: function (value) {
            var current = (this.value() || []).slice();
            var idx = current.indexOf(value);
            if (idx === -1) {
                current.push(value);
            } else {
                current.splice(idx, 1);
            }
            this.value(current);
        },

        toggleGroupSelection: function (group) {
            var current = (this.value() || []).slice();
            var groupValues = group.items.map(function (i) { return i.value; });
            var allCurrentlySelected = groupValues.every(function (v) {
                return current.indexOf(v) !== -1;
            });
            if (allCurrentlySelected) {
                current = current.filter(function (v) {
                    return groupValues.indexOf(v) === -1;
                });
            } else {
                groupValues.forEach(function (v) {
                    if (current.indexOf(v) === -1) {
                        current.push(v);
                    }
                });
            }
            this.value(current);
            return true;
        },

        /**
         * Select all — when a query is active, selects only filtered matches.
         */
        selectAll: function () {
            var groups = this.filteredGroups();
            var current = (this.value() || []).slice();
            groups.forEach(function (group) {
                group.items.forEach(function (item) {
                    if (current.indexOf(item.value) === -1) {
                        current.push(item.value);
                    }
                });
            });
            this.value(current);
        },

        clearAll: function () {
            this.value([]);
        },

        totalCount: function () {
            return (this.options() || []).length;
        },

        selectedCount: function () {
            return (this.value() || []).length;
        },

        hasSelection: function () {
            return this.selectedCount() > 0;
        },

        hasAnyOptions: function () {
            return this.totalCount() > 0;
        },

        hasVisibleGroups: function () {
            return this.filteredGroups().length > 0;
        },

        hasActiveQuery: function () {
            return !!(this.debouncedQuery || '').trim();
        }
    });
});
