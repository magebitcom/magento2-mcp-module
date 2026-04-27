<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

use Magento\Authorization\Model\Role;
use Magento\Authorization\Model\RoleFactory;
use Magento\Authorization\Model\Rules;
use Magento\Authorization\Model\RulesFactory;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;

$objectManager = Bootstrap::getObjectManager();

/** @var User $user */
$user = $objectManager->get(UserFactory::class)->create();
$user->load('limitedAdmin', 'username');
if ($user->getId() !== null) {
    $user->delete();
}

/** @var Role $role */
$role = $objectManager->get(RoleFactory::class)->create();
$role->load('mcp_limited_role', 'role_name');

/** @var Rules $rules */
$rules = $objectManager->get(RulesFactory::class)->create();
if ($role->getId() !== null) {
    $rules->load($role->getId(), 'role_id');
    if ($rules->getId() !== null) {
        $rules->delete();
    }
    $role->delete();
}
