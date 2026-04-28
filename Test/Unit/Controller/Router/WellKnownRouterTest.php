<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Controller\Router;

use Magebit\Mcp\Controller\OAuth\AuthorizationServerMetadata;
use Magebit\Mcp\Controller\OAuth\ProtectedResourceMetadata;
use Magebit\Mcp\Controller\Router\WellKnownRouter;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Route\ConfigInterface;
use Magento\Framework\App\Router\ActionList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WellKnownRouterTest extends TestCase
{
    private ActionFactory&MockObject $actionFactory;
    private ActionList&MockObject $actionList;
    private ConfigInterface&MockObject $routeConfig;
    private WellKnownRouter $router;

    protected function setUp(): void
    {
        $this->actionFactory = $this->createMock(ActionFactory::class);
        $this->actionList = $this->createMock(ActionList::class);
        $this->routeConfig = $this->createMock(ConfigInterface::class);
        $this->router = new WellKnownRouter(
            $this->actionFactory,
            $this->actionList,
            $this->routeConfig
        );
    }

    public function testMatchesAuthorizationServerMetadata(): void
    {
        $action = $this->createMock(ActionInterface::class);
        $this->routeConfig->method('getModulesByFrontName')->with('mcp')->willReturn(['Magebit_Mcp']);
        $this->actionList->method('get')
            ->with('Magebit_Mcp', '', 'oauth', 'authorizationservermetadata')
            ->willReturn(AuthorizationServerMetadata::class);
        $this->actionFactory->method('create')
            ->with(AuthorizationServerMetadata::class)
            ->willReturn($action);

        self::assertSame($action, $this->router->match($this->request('/.well-known/oauth-authorization-server')));
    }

    public function testMatchesProtectedResourceMetadata(): void
    {
        $action = $this->createMock(ActionInterface::class);
        $this->routeConfig->method('getModulesByFrontName')->with('mcp')->willReturn(['Magebit_Mcp']);
        $this->actionList->method('get')
            ->with('Magebit_Mcp', '', 'oauth', 'protectedresourcemetadata')
            ->willReturn(ProtectedResourceMetadata::class);
        $this->actionFactory->method('create')
            ->with(ProtectedResourceMetadata::class)
            ->willReturn($action);

        self::assertSame($action, $this->router->match($this->request('/.well-known/oauth-protected-resource')));
    }

    public function testMatchesProtectedResourceMetadataWithResourceSuffix(): void
    {
        // RFC 9728 §3 — the resource path is appended to the .well-known prefix.
        $action = $this->createMock(ActionInterface::class);
        $this->routeConfig->method('getModulesByFrontName')->with('mcp')->willReturn(['Magebit_Mcp']);
        $this->actionList->method('get')
            ->with('Magebit_Mcp', '', 'oauth', 'protectedresourcemetadata')
            ->willReturn(ProtectedResourceMetadata::class);
        $this->actionFactory->method('create')
            ->with(ProtectedResourceMetadata::class)
            ->willReturn($action);

        self::assertSame($action, $this->router->match($this->request('/.well-known/oauth-protected-resource/mcp')));
    }

    public function testMatchesProtectedResourceMetadataWithDeepSuffix(): void
    {
        $action = $this->createMock(ActionInterface::class);
        $this->routeConfig->method('getModulesByFrontName')->with('mcp')->willReturn(['Magebit_Mcp']);
        $this->actionList->method('get')->willReturn(ProtectedResourceMetadata::class);
        $this->actionFactory->method('create')->willReturn($action);

        self::assertSame(
            $action,
            $this->router->match($this->request('/.well-known/oauth-protected-resource/foo/bar'))
        );
    }

    public function testReturnsNullForUnrelatedPath(): void
    {
        $this->routeConfig->expects(self::never())->method('getModulesByFrontName');
        $this->actionList->expects(self::never())->method('get');
        $this->actionFactory->expects(self::never())->method('create');

        self::assertNull($this->router->match($this->request('/checkout/cart')));
    }

    public function testReturnsNullWhenFrontNameNotRegistered(): void
    {
        // Defensive: someone disables Magebit_Mcp's routes.xml — the router must not blow up.
        $this->routeConfig->method('getModulesByFrontName')->with('mcp')->willReturn([]);
        $this->actionList->expects(self::never())->method('get');
        $this->actionFactory->expects(self::never())->method('create');

        self::assertNull($this->router->match($this->request('/.well-known/oauth-authorization-server')));
    }

    public function testReturnsNullWhenActionListCannotResolveClass(): void
    {
        $this->routeConfig->method('getModulesByFrontName')->with('mcp')->willReturn(['Magebit_Mcp']);
        $this->actionList->method('get')->willReturn(null);
        $this->actionFactory->expects(self::never())->method('create');

        self::assertNull($this->router->match($this->request('/.well-known/oauth-authorization-server')));
    }

    /**
     * @param string $pathInfo
     * @return HttpRequest&MockObject
     */
    private function request(string $pathInfo): HttpRequest&MockObject
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getPathInfo')->willReturn($pathInfo);
        return $request;
    }
}
