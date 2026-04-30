<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\OAuthClient\Listing\Column;

use Magebit\Mcp\Ui\Component\Listing\Column\HtmlEscape;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Per-row Edit / Delete actions for the OAuth client listing.
 */
class Actions extends Column
{
    private const URL_EDIT = 'magebit_mcp/oauthclient/edit';
    private const URL_DELETE = 'magebit_mcp/oauthclient/delete';

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param Escaper $escaper
     * @param array $components
     * @param array $data
     * @phpstan-param array<string, mixed> $components
     * @phpstan-param array<string, mixed> $data
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

            $item[$name] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_EDIT, ['id' => $id]),
                    'label' => __('Edit'),
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_DELETE, ['id' => $id]),
                    'label' => __('Delete'),
                    'confirm' => [
                        'title' => __('Delete "%1"', $label),
                        'message' => __(
                            'Deletes the OAuth client. Issued auth codes and refresh tokens are'
                            . ' removed via FK cascade; access tokens already minted keep working'
                            . ' until they expire.'
                        ),
                    ],
                    'post' => true,
                ],
            ];
        }
        unset($item);

        return $dataSource;
    }
}
