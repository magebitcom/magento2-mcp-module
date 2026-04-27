<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

/**
 * Fixture: admin user `limitedAdmin` with a role granting only the admin
 * dashboard. Used by AclTest to prove that absence of a tool's ACL resource
 * results in `-32004 FORBIDDEN` from the MCP dispatcher.
 */

use Magento\Authorization\Model\Acl\Role\Group as RoleGroup;
use Magento\Authorization\Model\Role;
use Magento\Authorization\Model\RoleFactory;
use Magento\Authorization\Model\Rules;
use Magento\Authorization\Model\RulesFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\User\Model\User;

$objectManager = Bootstrap::getObjectManager();

/** @var Role $role */
$role = $objectManager->get(RoleFactory::class)->create();
$role->setName('mcp_limited_role');
$role->setData('role_name', 'mcp_limited_role');
$role->setRoleType(RoleGroup::ROLE_TYPE);
$role->setUserType((string) UserContextInterface::USER_TYPE_ADMIN);
$role->save();

/** @var Rules $rules */
$rules = $objectManager->get(RulesFactory::class)->create();
$rules->setRoleId($role->getId());
// Only the dashboard — deliberately excludes Magebit_Mcp::* so tool
// dispatch hits the ACL deny branch.
$rules->setResources(['Magento_Backend::dashboard']);
$rules->saveRel();

/** @var User $user */
$user = $objectManager->create(User::class);
$user->setFirstname('Limited')
    ->setLastname('Admin')
    ->setUsername('limitedAdmin')
    ->setPassword(\Magento\TestFramework\Bootstrap::ADMIN_PASSWORD)
    ->setEmail('limitedAdmin@example.com')
    ->setIsActive(1)
    ->setRoleId($role->getId())
    ->save();
