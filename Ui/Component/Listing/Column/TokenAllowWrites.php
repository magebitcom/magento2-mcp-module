<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders the `allow_writes` flag as Yes/No; Yes gets a severity badge so
 * the rare write-enabled token stands out.
 */
class TokenAllowWrites extends Column
{
    /**
     * @param array $dataSource
     * @phpstan-param array{data?: array{items?: array<int, array<string, mixed>>}} $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items']) || $dataSource['data']['items'] === []) {
            return $dataSource;
        }

        $name = $this->getName();

        foreach ($dataSource['data']['items'] as &$item) {
            $raw = $item[$name] ?? 0;
            $allow = is_scalar($raw) && (int) $raw === 1;
            $item[$name] = $allow
                ? '<span class="grid-severity-major"><span>Yes</span></span>'
                : '<span class="mcp-audit-muted">No</span>';
        }
        unset($item);

        return $dataSource;
    }
}
