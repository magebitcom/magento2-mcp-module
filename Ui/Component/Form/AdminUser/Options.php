<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Form\AdminUser;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\User\Model\ResourceModel\User\CollectionFactory;

/**
 * Populates the admin-user dropdown on the token form with every active
 * admin. Inactive users are filtered out here so the admin can't accidentally
 * mint a token for a deactivated account (the authenticator would reject it
 * anyway, but the dropdown shouldn't suggest it).
 */
class Options implements OptionSourceInterface
{
    public function __construct(
        private readonly CollectionFactory $userCollectionFactory
    ) {
    }

    /**
     * @return array<int, array{value: int|string, label: string}>
     */
    public function toOptionArray(): array
    {
        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToFilter('is_active', ['eq' => 1]);
        $collection->addFieldToSelect(['user_id', 'username', 'firstname', 'lastname']);
        $collection->setOrder('username', 'ASC');

        $options = [
            ['value' => '', 'label' => (string) __('-- Select admin user --')],
        ];

        foreach ($collection->getItems() as $user) {
            $rawId = $user->getData('user_id');
            if (!is_scalar($rawId)) {
                continue;
            }
            $id = (int) $rawId;
            $username = $this->stringData($user->getData('username'));
            $first = $this->stringData($user->getData('firstname'));
            $last = $this->stringData($user->getData('lastname'));
            $fullName = trim($first . ' ' . $last);

            $options[] = [
                'value' => $id,
                'label' => $fullName !== ''
                    ? sprintf('%s (%s)', $username, $fullName)
                    : $username,
            ];
        }

        return $options;
    }

    private function stringData(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
