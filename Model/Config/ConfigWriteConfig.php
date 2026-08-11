<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Reader for `magebit_mcp/config_write/*`. Both getters fail safe: a blank or
 * malformed field can only narrow what is writable, never widen it.
 */
class ConfigWriteConfig
{
    public const XML_PATH_ENABLED = 'magebit_mcp/config_write/enabled';
    public const XML_PATH_ALLOWED_PATHS = 'magebit_mcp/config_write/allowed_paths';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * The only paths the write tool may touch. Empty — the shipped default —
     * means every write is refused.
     *
     * @return list<string>
     */
    public function getAllowedPaths(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_ALLOWED_PATHS);

        return is_string($raw) ? $this->parseAllowedPaths($raw) : [];
    }

    /**
     * The one reading of the Allowed Paths field, so the admin-side validator and the policy can
     * never disagree on which lines count.
     *
     * @param string $raw
     * @return list<string>
     */
    public function parseAllowedPaths(string $raw): array
    {
        $paths = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $paths[] = $trimmed;
        }

        return array_values(array_unique($paths));
    }
}
