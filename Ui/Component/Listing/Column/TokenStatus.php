<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Derives token state (active / revoked / expired) from the row and renders
 * it as a stock admin `grid-severity-*` badge — the raw columns `revoked_at`
 * and `expires_at` on their own are harder to glance at.
 */
class TokenStatus extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param Escaper $escaper
     * @param DateTime $dateTime
     * @param array $components
     * @param array $data
     * @phpstan-param array<string, mixed> $components
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly Escaper $escaper,
        private readonly DateTime $dateTime,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Render each row's status as a grid-severity badge.
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

        $now = $this->dateTime->gmtTimestamp();
        $name = $this->getName();

        foreach ($dataSource['data']['items'] as &$item) {
            [$severity, $label] = $this->classify($item, $now);
            $item[$name] = sprintf(
                '<span class="grid-severity-%s"><span>%s</span></span>',
                $this->escaper->escapeHtmlAttr($severity),
                HtmlEscape::toString($this->escaper->escapeHtml($label))
            );
        }
        unset($item);

        return $dataSource;
    }

    /**
     * Pick the severity + label for one row.
     *
     * @param array $item
     * @phpstan-param array<string, mixed> $item
     * @param int $now
     * @return array{0: string, 1: string}
     */
    private function classify(array $item, int $now): array
    {
        $revokedAt = $item['revoked_at'] ?? null;
        if (is_string($revokedAt) && $revokedAt !== '') {
            return ['critical', 'Revoked'];
        }

        $expiresAt = $item['expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '') {
            $ts = strtotime($expiresAt . ' UTC');
            if ($ts !== false && $ts <= $now) {
                return ['minor', 'Expired'];
            }
        }

        return ['notice', 'Active'];
    }
}
