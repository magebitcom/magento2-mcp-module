<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Observer;

use Magebit\Mcp\Model\OAuth\PendingConsent;
use Magebit\Mcp\Observer\RememberPendingConsent;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RememberPendingConsentTest extends TestCase
{
    private const NONCE = 'aabbccddeeff00112233445566778899';

    private Auth&MockObject $auth;
    private ActionFlag&MockObject $actionFlag;
    private PendingConsent&MockObject $pendingConsent;
    private HttpRequest&MockObject $request;
    private Observer $observer;
    private RememberPendingConsent $subject;

    protected function setUp(): void
    {
        $this->auth = $this->createMock(Auth::class);
        $this->actionFlag = $this->createMock(ActionFlag::class);
        $this->pendingConsent = $this->createMock(PendingConsent::class);
        $this->request = $this->createMock(HttpRequest::class);
        $this->request->method('getParam')->with('h')->willReturn(self::NONCE);
        $this->observer = new Observer(['event' => new Event(['request' => $this->request])]);
        $this->subject = new RememberPendingConsent($this->auth, $this->actionFlag, $this->pendingConsent);
    }

    /**
     * @return void
     */
    public function testInterceptedConsentScreenIsRemembered(): void
    {
        $this->givenIntercepted(true);
        $this->request->method('isGet')->willReturn(true);
        $this->auth->method('isLoggedIn')->willReturn(true);

        $this->pendingConsent->expects(self::once())->method('remember')->with(self::NONCE);
        $this->pendingConsent->expects(self::never())->method('forget');

        $this->subject->execute($this->observer);
    }

    /**
     * @return void
     */
    public function testConsentActionAboutToRunForgetsThePendingConsent(): void
    {
        $this->givenIntercepted(false);
        $this->request->method('isGet')->willReturn(true);
        $this->auth->method('isLoggedIn')->willReturn(true);

        $this->pendingConsent->expects(self::once())->method('forget');
        $this->pendingConsent->expects(self::never())->method('remember');

        $this->subject->execute($this->observer);
    }

    /**
     * @return void
     */
    public function testInterceptedPostIsNotRemembered(): void
    {
        $this->givenIntercepted(true);
        $this->request->method('isGet')->willReturn(false);
        $this->auth->method('isLoggedIn')->willReturn(true);

        $this->pendingConsent->expects(self::never())->method('remember');
        $this->pendingConsent->expects(self::never())->method('forget');

        $this->subject->execute($this->observer);
    }

    /**
     * @return void
     */
    public function testInterceptedRequestWithoutAdminSessionIsNotRemembered(): void
    {
        $this->givenIntercepted(true);
        $this->request->method('isGet')->willReturn(true);
        $this->auth->method('isLoggedIn')->willReturn(false);

        $this->pendingConsent->expects(self::never())->method('remember');

        $this->subject->execute($this->observer);
    }

    /**
     * @param bool $intercepted
     * @return void
     */
    private function givenIntercepted(bool $intercepted): void
    {
        $this->actionFlag->method('get')
            ->with('', ActionInterface::FLAG_NO_DISPATCH)
            ->willReturn($intercepted);
    }
}
