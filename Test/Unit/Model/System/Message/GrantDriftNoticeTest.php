<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\System\Message;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\OAuth\GrantDrift;
use Magebit\Mcp\Model\OAuth\GrantDriftCache;
use Magebit\Mcp\Model\System\Message\GrantDriftNotice;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GrantDriftNoticeTest extends TestCase
{
    /**
     * @phpstan-var AuthorizationInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AuthorizationInterface&MockObject $authorization;

    /**
     * @phpstan-var GrantDriftCache&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private GrantDriftCache&MockObject $cache;

    /**
     * @phpstan-var GrantDrift&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private GrantDrift&MockObject $grantDrift;

    /**
     * @phpstan-var LoggerInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private LoggerInterface&MockObject $logger;

    private GrantDriftNotice $notice;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->authorization = $this->createMock(AuthorizationInterface::class);
        $this->cache = $this->createMock(GrantDriftCache::class);
        $this->grantDrift = $this->createMock(GrantDrift::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->with('magebit_mcp/token/index')
            ->willReturn('https://shop.test/admin/magebit_mcp/token/index/?a=1&b="2"');
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeUrl')->willReturnCallback(
            static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
        $this->notice = new GrantDriftNotice(
            $this->authorization,
            $this->cache,
            $this->grantDrift,
            $url,
            $escaper,
            $this->logger
        );
    }

    /**
     * @return void
     */
    public function testHiddenWithoutTokenAclAndNothingComputed(): void
    {
        $this->authorization->method('isAllowed')->with('Magebit_Mcp::mcp_tokens')->willReturn(false);
        $this->cache->expects(self::never())->method('load');
        $this->grantDrift->expects(self::never())->method('countDriftingActiveTokens');

        self::assertFalse($this->notice->isDisplayed());
    }

    /**
     * @return void
     */
    public function testHiddenWhenNoConnectionDrifts(): void
    {
        $this->authorization->method('isAllowed')->willReturn(true);
        $this->cache->method('load')->willReturn(0);

        self::assertFalse($this->notice->isDisplayed());
    }

    /**
     * @return void
     */
    public function testShownWithTextLinkAndNoticeSeverity(): void
    {
        $this->authorization->method('isAllowed')->with('Magebit_Mcp::mcp_tokens')->willReturn(true);
        $this->cache->method('load')->willReturn(3);

        self::assertTrue($this->notice->isDisplayed());
        $text = (string) $this->notice->getText();
        self::assertStringContainsString(
            '3 MCP connection(s) are missing tools their admin role allows. '
            . 'Re-authorise them to pick up new tools.',
            $text
        );
        self::assertStringContainsString(
            'href="https://shop.test/admin/magebit_mcp/token/index/?a=1&amp;b=&quot;2&quot;"',
            $text
        );
        self::assertSame(MessageInterface::SEVERITY_NOTICE, $this->notice->getSeverity());
    }

    /**
     * @return void
     */
    public function testCacheHitDoesNotRecompute(): void
    {
        $this->authorization->method('isAllowed')->willReturn(true);
        $this->cache->expects(self::once())->method('load')->willReturn(2);
        $this->cache->expects(self::never())->method('save');
        $this->grantDrift->expects(self::never())->method('countDriftingActiveTokens');

        self::assertTrue($this->notice->isDisplayed());
        $this->notice->getText();
        $this->notice->getIdentity();
    }

    /**
     * @return void
     */
    public function testCacheMissComputesAndSaves(): void
    {
        $this->authorization->method('isAllowed')->willReturn(true);
        $this->cache->method('load')->willReturn(null);
        $this->grantDrift->expects(self::once())->method('countDriftingActiveTokens')->willReturn(4);
        $this->cache->expects(self::once())->method('save')->with(4);

        self::assertTrue($this->notice->isDisplayed());
        self::assertStringContainsString('4 MCP connection(s)', (string) $this->notice->getText());
    }

    /**
     * A failing count must never break the admin page that renders the message list.
     *
     * @return void
     */
    public function testFailureIsLoggedAndTreatedAsNoDrift(): void
    {
        $this->authorization->method('isAllowed')->willReturn(true);
        $this->cache->method('load')->willReturn(null);
        $this->grantDrift->method('countDriftingActiveTokens')->willThrowException(new RuntimeException('db down'));
        $this->logger->expects(self::once())->method('warning');

        self::assertFalse($this->notice->isDisplayed());
    }

    /**
     * Review F2: MessageList asks every message for its identity before isDisplayed(), for every admin. Without
     * the token ACL nothing may be read or computed.
     *
     * @return void
     */
    public function testIdentityWithoutTokenAclComputesNothing(): void
    {
        $this->authorization->method('isAllowed')->with('Magebit_Mcp::mcp_tokens')->willReturn(false);
        $this->cache->expects(self::never())->method('load');
        $this->grantDrift->expects(self::never())->method('countDriftingActiveTokens');

        self::assertSame(md5('magebit_mcp_grant_drift:none'), $this->notice->getIdentity());
    }

    /**
     * Dismissal is per count: a new drifting connection brings the message back.
     *
     * @return void
     */
    public function testIdentityIsStablePerCount(): void
    {
        $this->authorization->method('isAllowed')->willReturn(true);
        $this->cache->method('load')->willReturn(2);
        $identity = $this->notice->getIdentity();

        self::assertSame($identity, $this->notice->getIdentity());
        self::assertSame(md5('magebit_mcp_grant_drift:2'), $identity);
    }
}
