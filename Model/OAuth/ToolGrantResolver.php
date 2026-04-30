<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

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
     */
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AclChecker $aclChecker
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
            if (is_string($name) && $name !== '') {
                $tickedIndex[$name] = true;
            }
        }
        if ($tickedIndex === []) {
            return [];
        }

        $tools = $this->toolRegistry->all();
        $granted = [];
        $seen = [];
        foreach ($clientAllowedTools as $toolName) {
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
}
