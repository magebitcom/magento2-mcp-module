<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Router;

use Magebit\Mcp\Model\Url\WellKnownPath;
use Magebit\Mcp\Model\Url\WellKnownPathParser;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Route\ConfigInterface;
use Magento\Framework\App\Router\ActionList;
use Magento\Framework\App\RouterInterface;

/**
 * Routes the OAuth discovery URLs, which Magento's standard router cannot
 * reach because a front name may not start with a dot.
 */
class WellKnownRouter implements RouterInterface
{
    private const FRONT_NAME = 'mcp';
    private const CONTROLLER = 'oauth';
    private const ACTION_AUTHORIZATION_SERVER = 'authorizationservermetadata';
    private const ACTION_PROTECTED_RESOURCE = 'protectedresourcemetadata';

    /**
     * @param ActionFactory $actionFactory
     * @param ActionList $actionList
     * @param ConfigInterface $routeConfig
     * @param WellKnownPathParser $pathParser
     */
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly ActionList $actionList,
        private readonly ConfigInterface $routeConfig,
        private readonly WellKnownPathParser $pathParser
    ) {
    }

    /**
     * @param RequestInterface $request
     * @return ActionInterface|null
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        // The frontend area always dispatches with a Request\Http instance.
        if (!$request instanceof HttpRequest) {
            return null;
        }

        $wellKnown = $this->pathParser->parse((string) $request->getPathInfo());
        if ($wellKnown === null) {
            return null;
        }

        $modules = $this->routeConfig->getModulesByFrontName(self::FRONT_NAME);
        if ($modules === []) {
            return null;
        }

        $action = $wellKnown->document === WellKnownPath::AUTHORIZATION_SERVER
            ? self::ACTION_AUTHORIZATION_SERVER
            : self::ACTION_PROTECTED_RESOURCE;

        $actionClassName = $this->actionList->get($modules[0], '', self::CONTROLLER, $action);
        if ($actionClassName === null) {
            return null;
        }

        return $this->actionFactory->create($actionClassName);
    }
}
