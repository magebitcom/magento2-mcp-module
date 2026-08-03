<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Auth;

use Magento\Framework\Exception\LocalizedException;

/**
 * Request-scoped access to the bearer identity for tools that need to resolve
 * the current administrator without changing ToolInterface::execute().
 */
class AuthenticatedContextProvider
{
    private ?AuthenticatedContext $context = null;

    /**
     * @param AuthenticatedContext $context
     * @return void
     */
    public function set(AuthenticatedContext $context): void
    {
        $this->context = $context;
    }

    /**
     * @return AuthenticatedContext
     * @throws LocalizedException
     */
    public function get(): AuthenticatedContext
    {
        if ($this->context === null) {
            throw new LocalizedException(__('Authenticated context is unavailable.'));
        }

        return $this->context;
    }
}
