<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Helper\Acl\ToolResourceTree;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;

/**
 * Helpers for the consent screen — translating jstree ACL ids back to tool names,
 * deriving the default tick selection, resolving the OAuth client from a handoff
 * record. Pure logic, no controller / response coupling.
 */
class ConsentParamsResolver
{
    /**
     * @param ClientRepository $clientRepository
     * @param ToolRegistryInterface $toolRegistry
     * @param ToolGrantResolver $toolGrantResolver
     * @param AdminUserLookup $adminUserLookup
     */
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly ToolGrantResolver $toolGrantResolver,
        private readonly AdminUserLookup $adminUserLookup
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return Client|null
     */
    public function resolveClient(array $params): ?Client
    {
        $clientId = self::stringFromParams($params, 'client_id');
        if ($clientId === '') {
            return null;
        }
        try {
            return $this->clientRepository->getByClientId($clientId);
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * Translate jstree `resource[]` ids → MCP tool names. Synthetic group nodes
     * (`mcp_group_*`) and the tools-root id never round-trip.
     *
     * @param array<int|string, mixed>|null $rawResources
     * @return array<int, string>
     */
    public function parseSubmittedToolNames(?array $rawResources): array
    {
        if ($rawResources === null) {
            return [];
        }
        $toolByAclId = [];
        foreach ($this->toolRegistry->all() as $tool) {
            $toolByAclId[$tool->getAclResource()] = $tool->getName();
        }

        $tools = [];
        $seen = [];
        foreach ($rawResources as $value) {
            if (!is_string($value)) {
                continue;
            }
            $rid = trim($value);
            if ($rid === '' || $rid === ToolResourceTree::ROOT_RESOURCE_ID || str_starts_with($rid, 'mcp_group_')) {
                continue;
            }
            $name = $toolByAclId[$rid] ?? null;
            if ($name === null || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $tools[] = $name;
        }
        return $tools;
    }

    /**
     * Default tick set: client.allowedTools ∩ admin role, narrowed to read-only
     * tools when the request hint is `mcp:read`.
     *
     * @param Client|null $client
     * @param User|null $admin
     * @param array<int, Scope> $requestedScopes
     * @return array<int, string>
     */
    public function computePreTickedTools(?Client $client, ?User $admin, array $requestedScopes): array
    {
        if ($client === null || $admin === null) {
            return [];
        }
        $adminId = $admin->getId();
        if (!is_scalar($adminId) || (int) $adminId <= 0) {
            return [];
        }
        try {
            $resolvedAdmin = $this->adminUserLookup->getById((int) $adminId);
        } catch (NoSuchEntityException) {
            return [];
        }

        $allowed = $client->getAllowedTools();
        if ($allowed === []) {
            return [];
        }

        $hintReadOnly = !in_array(Scope::WRITE, $requestedScopes, true);
        $tools = $this->toolRegistry->all();

        $out = [];
        foreach ($allowed as $name) {
            if (!isset($tools[$name])) {
                continue;
            }
            if ($hintReadOnly && $tools[$name]->getWriteMode() === WriteMode::WRITE) {
                continue;
            }
            $out[] = $name;
        }

        return $this->toolGrantResolver->intersect($allowed, $out, $resolvedAdmin);
    }

    /**
     * @param array<string, mixed> $params
     * @param string $key
     * @param string $default
     * @return string
     */
    public static function stringFromParams(array $params, string $key, string $default = ''): string
    {
        $value = $params[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $params
     * @param string $key
     * @return string|null
     */
    public static function nullableStringFromParams(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;
        return is_string($value) ? $value : null;
    }
}
