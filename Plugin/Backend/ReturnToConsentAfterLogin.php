<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Plugin\Backend;

use Magebit\Mcp\Controller\Adminhtml\Oauth\Authorize;
use Magebit\Mcp\Model\OAuth\ConsentLoginReturn;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Sends an admin who signs in on the OAuth consent URL back to the consent
 * screen rather than to the dashboard. Wraps Magento's admin authentication
 * plugin (sortOrder 100), which performs the login and sets the redirect.
 */
class ReturnToConsentAfterLogin
{
    /**
     * @param Auth $auth
     * @param ConsentLoginReturn $consentLoginReturn
     */
    public function __construct(
        private readonly Auth $auth,
        private readonly ConsentLoginReturn $consentLoginReturn
    ) {
    }

    /**
     * @param Authorize $subject
     * @param callable $proceed
     * @param RequestInterface $request
     * @return mixed
     */
    public function aroundDispatch(Authorize $subject, callable $proceed, RequestInterface $request): mixed
    {
        // Read before proceeding: the authentication plugin clears the login post value.
        $loginSubmitted = $request instanceof HttpRequest
            && $request->isPost()
            && $request->getPost('login') !== null;
        $wasLoggedIn = $this->auth->isLoggedIn();

        $result = $proceed($request);

        $url = $this->consentLoginReturn->resolve(
            $loginSubmitted,
            $wasLoggedIn,
            $this->auth->isLoggedIn(),
            $request->getParam('h')
        );
        $response = $subject->getResponse();
        if ($url !== null && $response instanceof HttpResponse) {
            $response->setRedirect($url);
        }

        return $result;
    }
}
