<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\System\Message;

use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\ModuleUpdate\UpdateSummaryStorage;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Notification\MessageInterface;

/**
 * Admin banner nudging the operator to upgrade outdated Magebit MCP modules.
 *
 * Reads the cron's cached summary. Identity is content-derived from the outdated set, so a
 * dismissed banner stays dismissed until a newer release changes the set, then resurfaces.
 */
class ModuleUpdateAvailable implements MessageInterface
{
    /**
     * @var ?list<array{package: string, installed: string, latest: string}>
     */
    private ?array $summary = null;

    /**
     * @param AuthorizationInterface $authorization
     * @param UpdateSummaryStorage $storage
     * @param ModuleConfig $config
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly UpdateSummaryStorage $storage,
        private readonly ModuleConfig $config
    ) {
    }

    /**
     * @return string
     */
    public function getIdentity()
    {
        $summary = $this->getSummary();
        usort($summary, static fn (array $a, array $b): int => strcmp($a['package'], $b['package']));

        // md5() here is a content fingerprint, not for cryptographic use.
        // phpcs:ignore Magento2.Security.InsecureFunction
        return md5('magebit_mcp_updates:' . json_encode($summary));
    }

    /**
     * @return bool
     */
    public function isDisplayed()
    {
        return $this->config->isModuleUpdateCheckEnabled()
            && $this->authorization->isAllowed('Magebit_Mcp::config')
            && $this->getSummary() !== [];
    }

    /**
     * @return string
     */
    public function getText()
    {
        $summary = $this->getSummary();

        $items = [];
        foreach ($summary as $entry) {
            $items[] = sprintf('%s (%s → %s)', $entry['package'], $entry['installed'], $entry['latest']);
        }

        return (string) __(
            'Newer versions of your MCP modules are available: %1. Update with Composer to get the latest fixes.',
            implode(', ', $items)
        );
    }

    /**
     * @return int
     */
    public function getSeverity()
    {
        return MessageInterface::SEVERITY_MINOR;
    }

    /**
     * Memoized per request so the three interface calls share one cache read.
     *
     * @return list<array{package: string, installed: string, latest: string}>
     */
    private function getSummary(): array
    {
        if ($this->summary === null) {
            $this->summary = $this->storage->load();
        }

        return $this->summary;
    }
}
