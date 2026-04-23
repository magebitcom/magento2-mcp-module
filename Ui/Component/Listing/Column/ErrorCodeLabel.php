<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

use Magebit\Mcp\Model\JsonRpc\ErrorCodeLabels;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Decorates the raw numeric `error_code` with its JSON-RPC / MCP meaning, so
 * auditors reading the grid don't need to memorize the -32001..-32014 codes.
 * Unknown codes still render with a fallback label rather than a blank cell.
 */
class ErrorCodeLabel extends Column
{
    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array{data?: array{items?: array<int, array<string, mixed>>}} $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items']) || $dataSource['data']['items'] === []) {
            return $dataSource;
        }

        $name = $this->getName();

        foreach ($dataSource['data']['items'] as &$item) {
            $raw = $item[$name] ?? null;
            if (!is_scalar($raw) || (string) $raw === '') {
                $item[$name] = '';
                continue;
            }

            $code = (int) $raw;
            $label = ErrorCodeLabels::labelFor($code);
            $escaped = $this->escaper->escapeHtml($label);
            $item[$name] = sprintf(
                '<span class="mcp-audit-code">%d</span> %s',
                $code,
                is_string($escaped) ? $escaped : ''
            );
        }
        unset($item);

        return $dataSource;
    }
}
