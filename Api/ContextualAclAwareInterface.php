<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

/**
 * Third ACL check for tools whose required Magento permission depends on the call arguments —
 * a config writer needs the target section's own resource, which a static
 * {@see UnderlyingAclAwareInterface} value cannot express. Every returned resource must pass.
 */
interface ContextualAclAwareInterface
{
    /**
     * Called before schema validation, so arguments may be malformed. Return an empty array when
     * no resource can be determined; the tool must refuse such input itself. Implementations must
     * not throw — a throw, a blank entry (`['']` is not `[]`) or a non-string entry is a hard deny.
     *
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return array<int, string>
     */
    public function getContextualAclResources(array $arguments): array;
}
