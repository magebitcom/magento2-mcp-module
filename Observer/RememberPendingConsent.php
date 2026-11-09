<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Observer;

use Magebit\Mcp\Model\OAuth\PendingConsent;
use Magebit\Mcp\Plugin\Backend\StartupPageToPendingConsent;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Keeps the consent screen pending when another admin step intercepts it.
 *
 * Listens to `controller_action_predispatch_magebit_mcp_oauth_authorize`,
 * which fires after the generic `controller_action_predispatch` observers.
 * Magento's two-factor authentication is one of those: it redirects the
 * request to its own form (setting FLAG_NO_DISPATCH) without keeping the
 * requested URL, and sends the admin to the startup page once the code is
 * accepted. When that happens to a signed-in admin on the consent screen,
 * the handoff nonce is remembered and {@see StartupPageToPendingConsent}
 * makes the startup page the consent screen. When the consent action is
 * about to run, the pending consent is forgotten. Other interceptors that set
 * the flag without returning to the startup page (such as a forced password
 * change) are not covered; their marker expires with the handoff.
 */
class RememberPendingConsent implements ObserverInterface
{
    /**
     * @param Auth $auth
     * @param ActionFlag $actionFlag
     * @param PendingConsent $pendingConsent
     */
    public function __construct(
        private readonly Auth $auth,
        private readonly ActionFlag $actionFlag,
        private readonly PendingConsent $pendingConsent
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->actionFlag->get('', ActionInterface::FLAG_NO_DISPATCH)) {
            $this->pendingConsent->forget();
            return;
        }

        $request = $observer->getEvent()->getData('request');
        if ($request instanceof HttpRequest && $request->isGet() && $this->auth->isLoggedIn()) {
            $this->pendingConsent->remember($request->getParam('h'));
        }
    }
}
