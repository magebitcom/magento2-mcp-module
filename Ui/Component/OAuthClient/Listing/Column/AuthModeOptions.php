<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\OAuthClient\Listing\Column;

use Magebit\Mcp\Model\OAuth\AuthMode;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Options source for the OAuth Clients listing's "Mode" column.
 */
class AuthModeOptions implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => AuthMode::PERSONAL->value, 'label' => __('Personal')->render()],
            ['value' => AuthMode::SHARED->value, 'label' => __('Shared')->render()],
        ];
    }
}
