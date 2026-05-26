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
 * Renders `scopes_json` as tool-name chips; empty/null renders as "(all granted)".
 * See {@see \Magebit\Mcp\Api\Data\TokenInterface::getScopes()}.
 */
class TokenScopes extends Column
{
    private const INLINE_LIST_THRESHOLD = 3;

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param Escaper $escaper
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

    /**
     * @param mixed $raw
     * @return string
     */
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
                $names[] = $value;
            }
        }
        if ($names === []) {
            return '<span class="mcp-audit-muted">(all granted)</span>';
        }

        if (count($names) <= self::INLINE_LIST_THRESHOLD) {
            return $this->renderInlineChips($names);
        }
        return $this->renderDomainSummary($names);
    }

    /**
     * @param array<int, string> $names
     * @return string
     */
    private function renderInlineChips(array $names): string
    {
        $escaped = array_map(
            fn (string $n): string => HtmlEscape::toString($this->escaper->escapeHtml($n)),
            $names
        );
        $separator = '</code> <code class="mcp-audit-method">';
        return '<code class="mcp-audit-method">' . implode($separator, $escaped) . '</code>';
    }

    /**
     * @param array<int, string> $names
     * @return string
     */
    private function renderDomainSummary(array $names): string
    {
        $byDomain = $this->groupByDomain($names);

        $countLabel = sprintf('%d tools', count($names));
        $out = '<span class="mcp-scope-count">'
            . HtmlEscape::toString($this->escaper->escapeHtml($countLabel))
            . '</span>';

        foreach ($byDomain as $domain => $tools) {
            $tooltip = implode("\n", $tools);
            $out .= ' <span class="mcp-scope-domain" title="'
                . HtmlEscape::toString($this->escaper->escapeHtmlAttr($tooltip))
                . '">'
                . HtmlEscape::toString($this->escaper->escapeHtml((string) $domain))
                . ' <em>('
                . count($tools)
                . ')</em></span>';
        }
        return $out;
    }

    /**
     * @param array<int, string> $names
     * @return array<string, array<int, string>>
     */
    private function groupByDomain(array $names): array
    {
        $byDomain = [];
        foreach ($names as $tool) {
            $dot = strpos($tool, '.');
            $domain = $dot === false ? $tool : substr($tool, 0, $dot);
            $byDomain[$domain] ??= [];
            $byDomain[$domain][] = $tool;
        }
        ksort($byDomain);
        return $byDomain;
    }
}
