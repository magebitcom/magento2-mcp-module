<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool;

use Magebit\Mcp\Api\AvailabilityAwareInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magento\Framework\Acl\Builder as AclBuilder;
use Throwable;

/**
 * Decides whether a registered tool can run on this store at all, independent of who calls it.
 *
 * A tool is unavailable when it implements {@see AvailabilityAwareInterface} and says so, or when its
 * underlying ACL resource is not declared in the merged acl.xml tree. No role can ever hold an undeclared
 * resource, so such a tool cannot run for anyone; children that hide a tool whose optional module is off
 * by returning an undeclared resource get the same answer as the explicit interface.
 */
class ToolAvailability
{
    /**
     * @param AclBuilder $aclBuilder
     */
    public function __construct(
        private readonly AclBuilder $aclBuilder
    ) {
    }

    /**
     * @param ToolInterface $tool
     * @return bool
     */
    public function isAvailable(ToolInterface $tool): bool
    {
        if ($tool instanceof AvailabilityAwareInterface && !$tool->isAvailable()) {
            return false;
        }
        if ($tool instanceof UnderlyingAclAwareInterface) {
            $underlying = $tool->getUnderlyingAclResource();
            if ($underlying !== null && !$this->isDeclared($underlying)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string $resource
     * @return bool
     */
    private function isDeclared(string $resource): bool
    {
        if ($resource === '') {
            return true;
        }
        try {
            return $this->aclBuilder->getAcl()->hasResource($resource);
        } catch (Throwable) {
            // An ACL that cannot be built says nothing about the feature; let the ACL gate refuse.
            return true;
        }
    }
}
