<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Route\ConfigInterface;
use Magento\Framework\App\Router\ActionList;
use Magento\Framework\App\RouterInterface;

/**
 * Routes the OAuth 2.0 / MCP discovery URLs that live under `/.well-known/`,
 * which Magento's frontName-based standard router can't reach (frontNames
 * cannot start with a dot). Two suffixes are intercepted:
 *
 *  - `oauth-authorization-server` (RFC 8414 §3) → AuthorizationServerMetadata
 *  - `oauth-protected-resource[/<resource-path>]` (RFC 9728 §3) → ProtectedResourceMetadata
 */
class WellKnownRouter implements RouterInterface
{
    private const PATH_AUTHORIZATION_SERVER = '.well-known/oauth-authorization-server';
    private const PATH_PROTECTED_RESOURCE = '.well-known/oauth-protected-resource';
    private const PATH_PROTECTED_RESOURCE_PREFIX = '.well-known/oauth-protected-resource/';

    private const FRONT_NAME = 'mcp';
    private const CONTROLLER = 'oauth';
    private const ACTION_AUTHORIZATION_SERVER = 'authorizationservermetadata';
    private const ACTION_PROTECTED_RESOURCE = 'protectedresourcemetadata';

    /**
     * @param ActionFactory $actionFactory
     * @param ActionList $actionList
     * @param ConfigInterface $routeConfig
     */
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly ActionList $actionList,
        private readonly ConfigInterface $routeConfig
    ) {
    }

    /**
     * @param RequestInterface $request
     * @return ActionInterface|null
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        // RouterInterface::match() doesn't expose getPathInfo(), but the
        // frontend area always dispatches with a Request\Http instance.
        if (!$request instanceof HttpRequest) {
            return null;
        }

        $identifier = trim((string) $request->getPathInfo(), '/');

        $action = $this->resolveAction($identifier);
        if ($action === null) {
            return null;
        }

        $modules = $this->routeConfig->getModulesByFrontName(self::FRONT_NAME);
        if ($modules === []) {
            return null;
        }

        $actionClassName = $this->actionList->get($modules[0], '', self::CONTROLLER, $action);
        if ($actionClassName === null) {
            return null;
        }

        return $this->actionFactory->create($actionClassName);
    }

    /**
     * @param string $identifier
     * @return string|null
     */
    private function resolveAction(string $identifier): ?string
    {
        if ($identifier === self::PATH_AUTHORIZATION_SERVER) {
            return self::ACTION_AUTHORIZATION_SERVER;
        }
        if ($identifier === self::PATH_PROTECTED_RESOURCE
            || str_starts_with($identifier, self::PATH_PROTECTED_RESOURCE_PREFIX)
        ) {
            return self::ACTION_PROTECTED_RESOURCE;
        }
        return null;
    }
}
