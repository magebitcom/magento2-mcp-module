<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Per-row Revoke / Delete actions for the token listing.
 *
 * Revoke is the safe default — it stamps `revoked_at` so the row stays in
 * the grid for audit linkage. Delete hard-removes the row (cascade
 * `ON DELETE SET NULL` on the audit foreign key); useful for cleaning up
 * test tokens that never got used in production.
 */
class TokenActions extends Column
{
    private const URL_REVOKE = 'magebit_mcp/token/revoke';
    private const URL_DELETE = 'magebit_mcp/token/delete';

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param Escaper $escaper
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Populate each row's actions column with Revoke / Delete links.
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
            $rawId = $item['id'] ?? null;
            if (!is_scalar($rawId) || (int) $rawId === 0) {
                continue;
            }
            $id = (int) $rawId;
            $rawName = $item['name'] ?? null;
            $label = HtmlEscape::toString($this->escaper->escapeHtml(
                is_scalar($rawName) && (string) $rawName !== '' ? (string) $rawName : '#' . $id
            ));

            $actions = [];

            $revokedAt = $item['revoked_at'] ?? null;
            if (!is_string($revokedAt) || $revokedAt === '') {
                $actions['revoke'] = [
                    'href' => $this->urlBuilder->getUrl(self::URL_REVOKE, ['id' => $id]),
                    'label' => __('Revoke'),
                    'confirm' => [
                        'title' => __('Revoke "%1"', $label),
                        'message' => __(
                            'Revoking is a one-way action. The bearer stops working immediately on next request.'
                        ),
                    ],
                    'post' => true,
                ];
            }

            $actions['delete'] = [
                'href' => $this->urlBuilder->getUrl(self::URL_DELETE, ['id' => $id]),
                'label' => __('Delete'),
                'confirm' => [
                    'title' => __('Delete "%1"', $label),
                    'message' => __(
                        'Deletes the row entirely. Audit log rows already written keep their '
                        . 'data but lose their token_id linkage. Prefer Revoke unless cleaning up.'
                    ),
                ],
                'post' => true,
            ];

            $item[$name] = $actions;
        }
        unset($item);

        return $dataSource;
    }
}
