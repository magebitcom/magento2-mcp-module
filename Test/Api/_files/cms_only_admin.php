<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

/**
 * Fixture: admin user `cmsAdmin` with role `mcp_cms_only_role`. The role allows only
 * `system.whoami`, CMS page/block read and create tools, and the matching Magento CMS ACLs.
 */

use Magento\Authorization\Model\Acl\Role\Group as RoleGroup;
use Magento\Authorization\Model\Role;
use Magento\Authorization\Model\RoleFactory;
use Magento\Authorization\Model\Rules;
use Magento\Authorization\Model\RulesFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\User\Model\User;

// Remove a leftover from an interrupted run first.
require __DIR__ . '/cms_only_admin_rollback.php';

$objectManager = Bootstrap::getObjectManager();

/** @var Role $role */
$role = $objectManager->get(RoleFactory::class)->create();
$role->setName('mcp_cms_only_role');
$role->setData('role_name', 'mcp_cms_only_role');
$role->setRoleType(RoleGroup::ROLE_TYPE);
$role->setUserType((string) UserContextInterface::USER_TYPE_ADMIN);
$role->save();

/** @var Rules $rules */
$rules = $objectManager->get(RulesFactory::class)->create();
$rules->setRoleId($role->getId());
$rules->setResources([
    'Magebit_Mcp::tool_system_whoami',
    'Magebit_McpCmsTools::tool_cms_page_list',
    'Magebit_McpCmsTools::tool_cms_page_get',
    'Magebit_McpCmsTools::tool_cms_block_list',
    'Magebit_McpCmsTools::tool_cms_block_get',
    'Magebit_McpCmsTools::tool_cms_page_create',
    'Magebit_McpCmsTools::tool_cms_block_create',
    'Magento_Cms::page',
    'Magento_Cms::save',
    'Magento_Cms::block',
]);
$rules->saveRel();

/** @var User $user */
$user = $objectManager->create(User::class);
$user->setFirstname('Cms')
    ->setLastname('Admin')
    ->setUsername('cmsAdmin')
    ->setPassword(\Magento\TestFramework\Bootstrap::ADMIN_PASSWORD)
    ->setEmail('cmsAdmin@example.com')
    ->setIsActive(1)
    ->setRoleId($role->getId())
    ->save();
