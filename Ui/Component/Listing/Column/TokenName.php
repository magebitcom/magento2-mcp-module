<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Resolves `token_id` on each audit row to the token name. Uses
 * {@see TokenRepository::listByIds()} to batch the lookup in one query —
 * per-row fetch would be an N+1 hotspot on popular tokens.
 */
class TokenName extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param TokenRepository $tokenRepository
     * @param Escaper $escaper
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly TokenRepository $tokenRepository,
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
        $ids = [];
        foreach ($dataSource['data']['items'] as $item) {
            $raw = $item[$name] ?? null;
            if (is_scalar($raw) && (int) $raw > 0) {
                $ids[] = (int) $raw;
            }
        }
        $tokens = $this->tokenRepository->listByIds($ids);

        foreach ($dataSource['data']['items'] as &$item) {
            $rawId = $item[$name] ?? null;
            if (!is_scalar($rawId) || (int) $rawId === 0) {
                $item[$name] = '<span class="mcp-audit-muted">&mdash;</span>';
                continue;
            }

            $id = (int) $rawId;
            $token = $tokens[$id] ?? null;
            if ($token === null) {
                $item[$name] = sprintf(
                    '#%d <span class="mcp-audit-muted">(deleted)</span>',
                    $id
                );
                continue;
            }

            $tokenName = trim($token->getName());
            if ($tokenName === '') {
                $item[$name] = sprintf('#%d', $id);
                continue;
            }
            $item[$name] = sprintf(
                '%s <span class="mcp-audit-muted">(#%d)</span>',
                HtmlEscape::toString($this->escaper->escapeHtml($tokenName)),
                $id
            );
        }
        unset($item);

        return $dataSource;
    }
}
