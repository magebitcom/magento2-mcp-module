<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Acl;

use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\User\Model\User;
use Throwable;

/**
 * Checks whether a specific admin user's role grants a specific ACL resource.
 *
 * Magento's standard {@see \Magento\Framework\AuthorizationInterface} resolves
 * the role via the *current* request's user context — fine for admin HTML
 * controllers, useless for MCP where the acting admin is identified by bearer
 * token rather than session cookie. This class bypasses the RoleLocator and
 * asks the raw ACL graph directly using the admin user's ACL identifier
 * ("U<userId>"), which inherits from their parent role.
 */
class AclChecker
{
    public function __construct(
        private readonly AclBuilder $aclBuilder
    ) {
    }

    public function isAllowed(User $user, string $resource): bool
    {
        $aclRole = $user->getAclRole();
        if (!is_string($aclRole) || $aclRole === '') {
            return false;
        }

        try {
            return (bool) $this->aclBuilder->getAcl()->isAllowed($aclRole, $resource);
        } catch (Throwable) {
            // Unknown role or resource → deny.
            return false;
        }
    }
}
