<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders `scopes_json` as a comma-separated list of tool names; empty /
 * null scopes renderCell as "(all granted)" — the convention documented on
 * {@see \Magebit\Mcp\Api\Data\TokenInterface::getScopes()}.
 */
class TokenScopes extends Column
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
            $item[$name] = $this->renderCell($raw);
        }
        unset($item);

        return $dataSource;
    }

    private function renderCell(mixed $raw): string
    {
        if (!is_string($raw) || $raw === '') {
            return '<span class="mcp-audit-muted">(all granted)</span>';
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            return '<span class="mcp-audit-muted">(all granted)</span>';
        }

        $names = [];
        foreach ($decoded as $value) {
            if (is_string($value) && $value !== '') {
                $names[] = HtmlEscape::toString($this->escaper->escapeHtml($value));
            }
        }
        if ($names === []) {
            return '<span class="mcp-audit-muted">(all granted)</span>';
        }

        return '<code class="mcp-audit-method">' . implode('</code> <code class="mcp-audit-method">', $names) . '</code>';
    }
}
