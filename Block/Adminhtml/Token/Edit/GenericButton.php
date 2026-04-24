<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\Token\Edit;

use Magento\Backend\Block\Widget\Context;

/**
 * Base class for the token-form buttons. We don't extend Magento's CMS
 * GenericButton because it pulls in `PageRepositoryInterface` we don't need
 * — tokens are mint-only, there's no load-existing-entity flow.
 */
class GenericButton
{
    public function __construct(
        protected readonly Context $context
    ) {
    }

    /**
     * @param string $route
     * @param array $params
     * @phpstan-param array<string, mixed> $params
     * @return string
     */
    public function getUrl(string $route = '', array $params = []): string
    {
        return $this->context->getUrlBuilder()->getUrl($route, $params);
    }
}
