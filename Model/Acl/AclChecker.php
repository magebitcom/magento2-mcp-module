<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
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
 * asks the raw ACL graph directly via {@see \Magento\User\Model\User::getAclRole()},
 * which returns the admin's user-type `authorization_role.role_id`. Its parent
 * in the ACL tree is the admin's group role — inheritance on the Laminas ACL
 * takes care of resolving group-level grants, so a non-root admin whose group
 * has `Magento_Backend::system` allowed will correctly inherit every resource
 * under that subtree.
 */
class AclChecker
{
    /**
     * @param AclBuilder $aclBuilder
     */
    public function __construct(
        private readonly AclBuilder $aclBuilder
    ) {
    }

    /**
     * True if the user's ACL role (or its ancestors) grants the given resource.
     *
     * @param User $user
     * @param string $resource
     * @return bool
     */
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
