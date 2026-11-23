<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Api\Data\OAuth\ClientInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\User\Model\User;

/**
 * Reduces consent-time inputs (client cap ∩ admin role ∩ ticked tools) into the granted
 * tool list, the derived `allow_writes` flag, and the OAuth-protocol scope summary.
 * Pure — shared by the Adminhtml authorize controller and the OAuth Token controller.
 */
class ToolGrantResolver
{
    /**
     * @param ToolRegistryInterface $toolRegistry
     * @param AclChecker $aclChecker
     * @param ConsentOfferedTools $consentOfferedTools
     */
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AclChecker $aclChecker,
        private readonly ConsentOfferedTools $consentOfferedTools
    ) {
    }

    /**
     * @param array<int, string> $clientAllowedTools
     * @param array<int, string> $tickedTools
     * @param User $admin
     * @return array<int, string>
     */
    public function intersect(array $clientAllowedTools, array $tickedTools, User $admin): array
    {
        if ($clientAllowedTools === [] || $tickedTools === []) {
            return [];
        }

        $tickedIndex = [];
        foreach ($tickedTools as $name) {
            // @phpstan-ignore function.alreadyNarrowedType
            if (is_string($name) && $name !== '') {
                $tickedIndex[$name] = true;
            }
        }
        if ($tickedIndex === []) {
            return [];
        }

        $tools = $this->toolRegistry->all();
        if (self::isWildcard($clientAllowedTools)) {
            $clientAllowedTools = array_keys($tools);
        }
        $granted = [];
        $seen = [];
        foreach ($clientAllowedTools as $toolName) {
            // @phpstan-ignore function.alreadyNarrowedType
            if (!is_string($toolName) || $toolName === '' || isset($seen[$toolName])) {
                continue;
            }
            if (!isset($tickedIndex[$toolName])) {
                continue;
            }
            if (!isset($tools[$toolName])) {
                continue;
            }
            if (!$this->aclChecker->isAllowed($admin, $tools[$toolName]->getAclResource())) {
                continue;
            }
            $seen[$toolName] = true;
            $granted[] = $toolName;
        }
        return $granted;
    }

    /**
     * What the consent screen offers this admin for this client: the consent tree's tools inside the client's cap,
     * through the same intersection the grant uses (as if the admin ticked everything). Kept on the auth code as
     * the "offered at grant" record, so grant drift later counts only tools that appeared since.
     *
     * @param array<int, string> $clientAllowedTools
     * @param User $admin
     * @return array<int, string>
     */
    public function offeredTools(array $clientAllowedTools, User $admin): array
    {
        return $this->intersect($clientAllowedTools, $this->consentOfferedTools->forAdmin($admin), $admin);
    }

    /**
     * `true` when at least one granted tool is a WRITE tool — drives the per-token
     * `allow_writes` flag and the `mcp:write` scope-string suffix.
     *
     * @param array<int, string> $grantedTools
     * @return bool
     */
    public function hasWriteTool(array $grantedTools): bool
    {
        $tools = $this->toolRegistry->all();
        foreach ($grantedTools as $name) {
            if (isset($tools[$name]) && $tools[$name]->getWriteMode() === WriteMode::WRITE) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render the OAuth-protocol scope summary for the granted tool set
     * (`mcp:read`, `mcp:write`, or both). Empty list → empty string.
     *
     * @param array<int, string> $grantedTools
     * @return string
     */
    public function summarizeScope(array $grantedTools): string
    {
        if ($grantedTools === []) {
            return '';
        }
        $hasRead = false;
        $hasWrite = false;
        $tools = $this->toolRegistry->all();
        foreach ($grantedTools as $name) {
            if (!isset($tools[$name])) {
                continue;
            }
            if ($tools[$name]->getWriteMode() === WriteMode::WRITE) {
                $hasWrite = true;
            } else {
                $hasRead = true;
            }
        }
        $parts = [];
        if ($hasRead) {
            $parts[] = Scope::READ->value;
        }
        if ($hasWrite) {
            $parts[] = Scope::WRITE->value;
        }
        return implode(' ', $parts);
    }

    /**
     * `true` when the stored cap is the single-element wildcard list — i.e. the
     * admin chose "allow all current + future tools" on the OAuth client form.
     *
     * @param array<int, string> $clientAllowedTools
     * @return bool
     */
    public static function isWildcard(array $clientAllowedTools): bool
    {
        return count($clientAllowedTools) === 1
            && ($clientAllowedTools[array_key_first($clientAllowedTools)] ?? null)
                === ClientInterface::ALLOW_ALL_TOOLS_SENTINEL;
    }

    /**
     * `true` when the granted set is exactly what the consent screen offered this admin, i.e. the admin
     * accepted everything — used to decide whether to forward the wildcard sentinel onto the issued
     * token vs. snapshotting the explicit list. Compared against the offered set rather than the whole
     * registry: tools the tree cannot offer (shared ACL resources, resources outside the tools node)
     * would otherwise turn every accept-all into a frozen literal grant.
     *
     * @param array<int, string> $grantedTools
     * @param User $admin
     * @return bool
     */
    public function grantsAllAdminAccessibleTools(array $grantedTools, User $admin): bool
    {
        $offered = array_values(array_unique($this->consentOfferedTools->forAdmin($admin)));
        if ($offered === []) {
            return false;
        }
        sort($offered);
        $granted = array_values(array_unique($grantedTools));
        sort($granted);
        return $granted === $offered;
    }
}
