<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magento\User\Model\ResourceModel\User\CollectionFactory;

/**
 * Checks that a client's authorization knobs point at admins who can actually
 * hold a token. Shared by the admin-UI save controller and the CLI so both
 * refuse the same misconfigurations.
 */
class AuthorizationOptionsValidator
{
    /**
     * @param CollectionFactory $userCollectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $userCollectionFactory
    ) {
    }

    /**
     * @param AuthorizationOptions $options
     * @return string|null Error message on misconfiguration, null when usable.
     */
    public function validate(AuthorizationOptions $options): ?string
    {
        if ($options->mode === AuthMode::SHARED) {
            if ($options->serviceAdminUserId === null) {
                return (string) __(
                    'Shared mode requires a Service Admin User. Pick the admin every issued token should be'
                    . ' bound to, or switch to Personal mode.'
                );
            }
            if (!$this->isActiveAdminUser($options->serviceAdminUserId)) {
                return (string) __(
                    'The selected Service Admin User must be an active admin. Pick a different admin.'
                );
            }

            // Whitelists are wiped in shared mode, so a stale id there is harmless.
            return null;
        }

        foreach ($options->allowedAdminUserIds as $userId) {
            if (!$this->isActiveAdminUser($userId)) {
                return (string) __(
                    'Allowed Admin Users contains an inactive or unknown admin. Refresh the page and reselect.'
                );
            }
        }

        return null;
    }

    /**
     * @param int $userId
     * @return bool
     */
    public function isActiveAdminUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToFilter('user_id', ['eq' => $userId]);
        $collection->addFieldToFilter('is_active', ['eq' => 1]);
        $collection->setPageSize(1);
        return $collection->getSize() === 1;
    }
}
