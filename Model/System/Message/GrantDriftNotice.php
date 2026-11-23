<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\System\Message;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\OAuth\GrantDrift;
use Magebit\Mcp\Model\OAuth\GrantDriftCache;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use Throwable;

/**
 * Admin notice: how many live MCP connections hold a fixed tool list that misses tools their admin role allows.
 *
 * Visibility only, shown to admins who may manage tokens. The count comes from {@see GrantDriftCache} and is
 * computed by {@see GrantDrift} only on a cache miss. Identity is derived from the count, so a dismissal holds
 * until the count changes.
 */
class GrantDriftNotice implements MessageInterface
{
    private const TOKEN_GRID_ROUTE = 'magebit_mcp/token/index';

    /**
     * @var int|null
     */
    private ?int $count = null;

    /**
     * @var bool|null
     */
    private ?bool $allowed = null;

    /**
     * @param AuthorizationInterface $authorization
     * @param GrantDriftCache $grantDriftCache
     * @param GrantDrift $grantDrift
     * @param UrlInterface $urlBuilder
     * @param Escaper $escaper
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly GrantDriftCache $grantDriftCache,
        private readonly GrantDrift $grantDrift,
        private readonly UrlInterface $urlBuilder,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return string
     */
    public function getIdentity()
    {
        // MessageList asks every message for its identity before isDisplayed(), for every admin: without the
        // token ACL the count is never read or computed.
        $suffix = $this->isAllowed() ? (string) $this->getCount() : 'none';

        // md5() here is a content fingerprint, not for cryptographic use.
        // phpcs:ignore Magento2.Security.InsecureFunction
        return md5('magebit_mcp_grant_drift:' . $suffix);
    }

    /**
     * @return bool
     */
    public function isDisplayed()
    {
        return $this->isAllowed() && $this->getCount() > 0;
    }

    /**
     * @return string
     */
    public function getText()
    {
        $url = $this->escaper->escapeUrl($this->urlBuilder->getUrl(self::TOKEN_GRID_ROUTE));

        return (string) __(
            '%1 MCP connection(s) are missing tools their admin role allows. '
            . 'Re-authorise them to pick up new tools. <a href="%2">Open MCP Connections</a>',
            $this->getCount(),
            $url
        );
    }

    /**
     * @return int
     */
    public function getSeverity()
    {
        return MessageInterface::SEVERITY_NOTICE;
    }

    /**
     * @return bool
     */
    private function isAllowed(): bool
    {
        if ($this->allowed === null) {
            $this->allowed = $this->authorization->isAllowed('Magebit_Mcp::mcp_tokens');
        }
        return $this->allowed;
    }

    /**
     * Memoised per request. Never throws into page rendering: a failure is logged and read as zero.
     *
     * @return int
     */
    private function getCount(): int
    {
        if ($this->count !== null) {
            return $this->count;
        }
        try {
            $count = $this->grantDriftCache->load();
            if ($count === null) {
                $count = $this->grantDrift->countDriftingActiveTokens();
                $this->grantDriftCache->save($count);
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                'Could not count MCP connections with missing tools.',
                ['exception' => $e->getMessage()]
            );
            $count = 0;
        }
        $this->count = $count;
        return $count;
    }
}
