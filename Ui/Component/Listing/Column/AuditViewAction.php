<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Per-row "View" action for the audit log grid; rows are immutable so this
 * is a single-link column rather than a dropdown.
 */
class AuditViewAction extends Column
{
    private const URL_VIEW = 'magebit_mcp/auditlog/view';

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
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
            $rawId = $item['id'] ?? null;
            if (!is_scalar($rawId) || (int) $rawId === 0) {
                continue;
            }
            $item[$name] = [
                'view' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_VIEW, ['id' => (int) $rawId]),
                    'label' => __('View'),
                ],
            ];
        }
        unset($item);

        return $dataSource;
    }
}
