<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders the JSON-RPC method as a monospace chip; for `tools/call` rows the
 * tool name appears on a second line so the grid reads naturally as
 * "tools/call → sales.order.get" without a dedicated tool column.
 *
 * `(request)` is the placeholder written by the controller when the bearer
 * auth / origin / parse step fails *before* the JSON-RPC envelope is read —
 * shown explicitly as "(unparsed request)" so it doesn't look like a bug.
 */
class Method extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param Escaper $escaper
     * @param array $components
     * @param array $data
     * @phpstan-param array<string, mixed> $components
     * @phpstan-param array<string, mixed> $data
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
     * Render the JSON-RPC method chip plus an optional tool-name sub-label.
     *
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
            $raw = $item[$name] ?? null;
            if (!is_string($raw) || $raw === '') {
                $item[$name] = '';
                continue;
            }

            $item[$name] = $this->renderCell($raw, $item);
        }
        unset($item);

        return $dataSource;
    }

    /**
     * Render a single row's method cell as HTML.
     *
     * @param string $method
     * @param array $item
     * @phpstan-param array<string, mixed> $item
     * @return string
     */
    private function renderCell(string $method, array $item): string
    {
        if ($method === '(request)') {
            return '<span class="mcp-audit-muted">(unparsed request)</span>';
        }

        $chip = sprintf(
            '<code class="mcp-audit-method">%s</code>',
            HtmlEscape::toString($this->escaper->escapeHtml($method))
        );

        if ($method === 'tools/call') {
            $tool = $item[AuditEntryInterface::TOOL_NAME] ?? null;
            if (is_string($tool) && $tool !== '') {
                return $chip . sprintf(
                    '<div class="mcp-audit-tool">%s</div>',
                    HtmlEscape::toString($this->escaper->escapeHtml($tool))
                );
            }
        }

        return $chip;
    }
}
