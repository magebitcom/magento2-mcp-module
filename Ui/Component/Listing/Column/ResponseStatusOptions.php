<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Filter source for the `response_status` column in the audit-log grid.
 */
class ResponseStatusOptions implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => AuditEntryInterface::STATUS_OK, 'label' => 'OK'],
            ['value' => AuditEntryInterface::STATUS_ERROR, 'label' => 'Error'],
        ];
    }
}
