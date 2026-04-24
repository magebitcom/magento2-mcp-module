<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Auth;

use Magebit\Mcp\Api\Data\TokenInterface;
use Magento\User\Model\User;

/**
 * Result of a successful MCP authentication; references the admin user that
 * owns the bearer (never the Magento session user).
 */
class AuthenticatedContext
{
    public function __construct(
        public readonly TokenInterface $token,
        public readonly User $adminUser
    ) {
    }

    /**
     * @return int
     */
    public function getAdminUserId(): int
    {
        $id = $this->adminUser->getId();
        return is_scalar($id) ? (int) $id : 0;
    }
}
