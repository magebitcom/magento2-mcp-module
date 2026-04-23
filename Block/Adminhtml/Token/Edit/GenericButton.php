<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\Token\Edit;

use Magento\Backend\Block\Widget\Context;

/**
 * Base class for the token-form buttons ({@see SaveButton}, {@see BackButton}).
 *
 * We don't extend Magento's CMS GenericButton because it pulls in a
 * `PageRepositoryInterface` we don't need — the token form has no "load
 * existing entity" flow; see {@see \Magebit\Mcp\Ui\DataProvider\Form\TokenDataProvider}
 * for why.
 */
class GenericButton
{
    public function __construct(
        protected readonly Context $context
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getUrl(string $route = '', array $params = []): string
    {
        return $this->context->getUrlBuilder()->getUrl($route, $params);
    }
}
