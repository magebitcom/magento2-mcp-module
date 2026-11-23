<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Job;

use InvalidArgumentException;
use Magebit\Mcp\Api\JobSnapshotProviderInterface;
use Magebit\Mcp\Model\Util\RegisteredEntries;

/**
 * Snapshot providers registered in di.xml, keyed by the canonical (dotted) tool name.
 */
class SnapshotProviderPool
{
    /**
     * @var array<string, JobSnapshotProviderInterface>
     */
    private array $providers = [];

    /**
     * @param array<int|string, mixed> $providers
     * @throws InvalidArgumentException When a key is not a canonical tool name or an entry is not a provider.
     */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $toolName => $provider) {
            if (!is_string($toolName) || preg_match(RegisteredEntries::NAME_PATTERN, $toolName) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'MCP job snapshot provider key "%s" must be a canonical tool name matching %s.',
                    (string) $toolName,
                    RegisteredEntries::NAME_PATTERN
                ));
            }
            if (!$provider instanceof JobSnapshotProviderInterface) {
                throw new InvalidArgumentException(sprintf(
                    'MCP job snapshot provider "%s" must implement %s, got %s.',
                    $toolName,
                    JobSnapshotProviderInterface::class,
                    get_debug_type($provider)
                ));
            }
            $this->providers[$toolName] = $provider;
        }
    }

    /**
     * @param string $toolName Canonical tool name.
     * @return JobSnapshotProviderInterface|null
     */
    public function get(string $toolName): ?JobSnapshotProviderInterface
    {
        return $this->providers[$toolName] ?? null;
    }
}
