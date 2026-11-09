<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Plugin\Backend;

use Magebit\Mcp\Controller\Adminhtml\Oauth\Authorize;
use Magebit\Mcp\Model\OAuth\ConsentLoginReturn;
use Magebit\Mcp\Plugin\Backend\ReturnToConsentAfterLogin;
use Magento\Backend\Model\Auth;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReturnToConsentAfterLoginTest extends TestCase
{
    private const NONCE = 'aabbccddeeff00112233445566778899';
    private const CONSENT_URL = 'https://store.test/admin/magebit_mcp/oauth/authorize/h/'
        . self::NONCE . '/';

    private Auth&MockObject $auth;
    private Authorize&MockObject $subject;
    private HttpResponse&MockObject $response;
    private HttpRequest&MockObject $request;
    private ReturnToConsentAfterLogin $plugin;

    protected function setUp(): void
    {
        $this->auth = $this->createMock(Auth::class);
        $this->response = $this->createMock(HttpResponse::class);
        $this->subject = $this->createMock(Authorize::class);
        $this->subject->method('getResponse')->willReturn($this->response);
        $this->request = $this->createMock(HttpRequest::class);
        $this->request->method('getParam')->with('h')->willReturn(self::NONCE);

        $backendUrl = $this->createMock(BackendUrl::class);
        $backendUrl->method('getUrl')->willReturn(self::CONSENT_URL);
        $this->plugin = new ReturnToConsentAfterLogin($this->auth, new ConsentLoginReturn($backendUrl));
    }

    /**
     * @return void
     */
    public function testLoginOnConsentUrlRedirectsBackToConsent(): void
    {
        $this->request->method('isPost')->willReturn(true);
        $loginPost = ['username' => 'u', 'password' => 'p'];
        $this->request->method('getPost')->with('login')->willReturnCallback(
            static function () use (&$loginPost): mixed {
                return $loginPost;
            }
        );
        $this->auth->method('isLoggedIn')->willReturnOnConsecutiveCalls(false, true);

        $this->response->expects(self::once())->method('setRedirect')->with(self::CONSENT_URL);

        // Magento's login step clears the login post value while it runs, so the plugin must read it first.
        $proceed = function () use (&$loginPost): HttpResponse {
            $loginPost = null;
            return $this->response;
        };
        $result = $this->plugin->aroundDispatch($this->subject, $proceed, $this->request);
        self::assertSame($this->response, $result);
    }

    /**
     * @return void
     */
    public function testConsentSubmitKeepsControllerRedirect(): void
    {
        $this->request->method('isPost')->willReturn(true);
        $this->request->method('getPost')->with('login')->willReturn(null);
        $this->auth->method('isLoggedIn')->willReturn(true);

        $this->response->expects(self::never())->method('setRedirect');

        $this->plugin->aroundDispatch($this->subject, fn () => $this->response, $this->request);
    }

    /**
     * @return void
     */
    public function testFailedLoginKeepsLoginPage(): void
    {
        $this->request->method('isPost')->willReturn(true);
        $this->request->method('getPost')->with('login')->willReturn(['username' => 'u', 'password' => 'bad']);
        $this->auth->method('isLoggedIn')->willReturn(false);

        $this->response->expects(self::never())->method('setRedirect');

        $this->plugin->aroundDispatch($this->subject, fn () => $this->response, $this->request);
    }
}
