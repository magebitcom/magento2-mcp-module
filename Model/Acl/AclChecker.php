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
 * Bypasses Magento's RoleLocator (which resolves via current request context) and
 * queries the raw ACL graph via {@see User::getAclRole()} — RoleLocator is useless
 * for token-authed MCP. Laminas ACL inheritance resolves group-level grants.
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
     * @param User $user
     * @param string $resource
     * @return bool
     */
    public function isAllowed(User $user, string $resource): bool
    {
        return $this->isAllowedForRole((string) $user->getAclRole(), $resource);
    }

    /**
     * @param string $aclRole
     * @param string $resource
     * @return bool
     */
    public function isAllowedForRole(string $aclRole, string $resource): bool
    {
        if ($aclRole === '' || $resource === '') {
            return false;
        }
        try {
            return (bool) $this->aclBuilder->getAcl()->isAllowed($aclRole, $resource);
        } catch (Throwable) {
            return false;
        }
    }
}
