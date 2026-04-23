<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders `response_status` as a Magento grid-severity badge — green for
 * "ok", red for "error" — reusing the stock `.grid-severity-*` CSS that
 * ships with the admin theme so we don't need a custom stylesheet.
 */
class ResponseStatus extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param Escaper $escaper
     * @param array $components
     * @param array $data
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
     * Render each row's response_status as a grid-severity badge.
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
            $item[$name] = $this->renderCell($raw);
        }
        unset($item);

        return $dataSource;
    }

    /**
     * Render a single status value as a grid-severity badge.
     *
     * @param string $status
     * @return string
     */
    private function renderCell(string $status): string
    {
        [$severity, $label] = match ($status) {
            AuditEntryInterface::STATUS_OK => ['notice', 'OK'],
            AuditEntryInterface::STATUS_ERROR => ['critical', 'Error'],
            default => ['minor', $status],
        };

        return sprintf(
            '<span class="grid-severity-%s"><span>%s</span></span>',
            $this->escaper->escapeHtmlAttr($severity),
            HtmlEscape::toString($this->escaper->escapeHtml($label))
        );
    }
}
