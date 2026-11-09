<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Plugin\Backend;

use Magebit\Mcp\Model\OAuth\PendingConsent;
use Magebit\Mcp\Plugin\Backend\StartupPageToPendingConsent;
use Magento\Backend\Model\UrlInterface;
use PHPUnit\Framework\TestCase;

class StartupPageToPendingConsentTest extends TestCase
{
    /**
     * @return void
     */
    public function testStartupPageIsUnchangedWithoutPendingConsent(): void
    {
        $pendingConsent = $this->createMock(PendingConsent::class);
        $pendingConsent->method('getRoutePath')->willReturn(null);
        $plugin = new StartupPageToPendingConsent($pendingConsent);

        self::assertSame(
            'adminhtml/dashboard',
            $plugin->afterGetStartupPageUrl($this->createMock(UrlInterface::class), 'adminhtml/dashboard')
        );
    }

    /**
     * @return void
     */
    public function testStartupPageIsThePendingConsentScreen(): void
    {
        $pendingConsent = $this->createMock(PendingConsent::class);
        $pendingConsent->method('getRoutePath')->willReturn('magebit_mcp/oauth/authorize/h/aabbccddeeff0011');
        $plugin = new StartupPageToPendingConsent($pendingConsent);

        self::assertSame(
            'magebit_mcp/oauth/authorize/h/aabbccddeeff0011',
            $plugin->afterGetStartupPageUrl($this->createMock(UrlInterface::class), 'adminhtml/dashboard')
        );
    }
}
