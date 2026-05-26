/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
define(['jquery'], function ($) {
    'use strict';

    return function () {
        var $mode = $('#magebit_mcp_oauth_client_auth_mode');
        if ($mode.length === 0 || $mode.data('mcpAuthModeInit')) {
            return;
        }
        $mode.data('mcpAuthModeInit', true);

        var fieldSelector = 'tr, .field, .admin__field';
        var $service = $('#magebit_mcp_oauth_client_service_admin_user_id').closest(fieldSelector);
        var $allowedUsers = $('#magebit_mcp_oauth_client_allowed_admin_user_ids').closest(fieldSelector);
        var $allowedRoles = $('#magebit_mcp_oauth_client_allowed_admin_role_ids').closest(fieldSelector);

        function sync() {
            if ($mode.val() === 'shared') {
                $service.show();
                $allowedUsers.hide();
                $allowedRoles.hide();
            } else {
                $service.hide();
                $allowedUsers.show();
                $allowedRoles.show();
            }
        }

        $mode.on('change', sync);
        sync();
    };
});
