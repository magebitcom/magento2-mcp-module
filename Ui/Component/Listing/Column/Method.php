<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders the JSON-RPC method as a monospace chip. `(request)` is the
 * placeholder the controller writes when auth / origin / parse fails before
 * the envelope is read; re-labelled "(unparsed request)" so it doesn't look
 * like a bug.
 */
class Method extends Column
{
    /**
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

            $item[$name] = $this->renderCell($raw);
        }
        unset($item);

        return $dataSource;
    }

    /**
     * @param string $method
     * @return string
     */
    private function renderCell(string $method): string
    {
        if ($method === '(request)') {
            return '<span class="mcp-audit-muted">(unparsed request)</span>';
        }

        return sprintf(
            '<code class="mcp-audit-method">%s</code>',
            HtmlEscape::toString($this->escaper->escapeHtml($method))
        );
    }
}
