<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

/**
 * Admin-managed set of disabled MCP tools. Disabled tools behave exactly like
 * unregistered ones on the wire.
 */
class DisabledTools
{
    public const XML_PATH_DISABLED_TOOLS = 'magebit_mcp/tools/disabled';

    /**
     * @var list<string>|null
     */
    private ?array $cache = null;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param WriterInterface $configWriter
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    /**
     * Memoized per instance so repeated calls within one request (a ~150-tool
     * loop, or back-to-back setDisabled() calls) don't re-parse or re-read
     * stale config.
     *
     * @return list<string>
     */
    public function getAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $raw = $this->scopeConfig->getValue(self::XML_PATH_DISABLED_TOOLS);
        if (!is_string($raw) || trim($raw) === '') {
            return $this->cache = [];
        }

        $names = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $names[] = $line;
            }
        }

        return $this->cache = array_values(array_unique($names));
    }

    /**
     * @param string $toolName
     * @return bool
     */
    public function isDisabled(string $toolName): bool
    {
        return in_array($toolName, $this->getAll(), true);
    }

    /**
     * No-op when the requested state already holds; otherwise persists and
     * cleans the config cache so the MCP endpoint sees it immediately.
     *
     * @param string $toolName
     * @param bool $disabled
     * @return void
     */
    public function setDisabled(string $toolName, bool $disabled): void
    {
        $current = $this->getAll();
        $updated = $disabled
            ? array_merge($current, [$toolName])
            : array_values(array_diff($current, [$toolName]));
        $updated = array_values(array_unique($updated));

        if ($updated === $current) {
            return;
        }

        $this->configWriter->save(self::XML_PATH_DISABLED_TOOLS, implode("\n", $updated));
        $this->cacheTypeList->cleanType('config');
        $this->cache = $updated;
    }
}
