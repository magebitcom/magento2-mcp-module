<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

use Magebit\Mcp\Model\OAuth\GrantDrift;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders the token's grant (`scopes_json`, column label "Grant").
 *
 * A wildcard grant reads "All tools (follows role)". A literal grant reads "N tools" plus its tool-name chips, and
 * "N tools, M missing" when the admin's role allows tools the grant lacks ({@see GrantDrift}); the missing names
 * are in the `title` in wire form. Revoked and expired rows show the plain count. The page's tokens are loaded in
 * one query. See {@see \Magebit\Mcp\Api\Data\TokenInterface::getScopes()}.
 */
class TokenScopes extends Column
{
    private const INLINE_LIST_THRESHOLD = 3;

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param Escaper $escaper
     * @param TokenRepository $tokenRepository
     * @param GrantDrift $grantDrift
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly Escaper $escaper,
        private readonly TokenRepository $tokenRepository,
        private readonly GrantDrift $grantDrift,
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
        $tokens = $this->loadTokens($dataSource['data']['items']);

        foreach ($dataSource['data']['items'] as &$item) {
            $raw = $item[$name] ?? null;
            $id = $this->rowId($item);
            $drift = isset($tokens[$id]) ? $this->grantDrift->forToken($tokens[$id]) : null;
            $item[$name] = $this->renderCell($raw, $drift);
        }
        unset($item);

        return $dataSource;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, Token>
     */
    private function loadTokens(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $id = $this->rowId($item);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $tokens = [];
        foreach ($this->tokenRepository->listByIds($ids) as $token) {
            $tokenId = $token->getId();
            if ($tokenId !== null) {
                $tokens[$tokenId] = $token;
            }
        }
        return $tokens;
    }

    /**
     * @param array<string, mixed> $item
     * @return int
     */
    private function rowId(array $item): int
    {
        $id = $item['id'] ?? null;
        return is_scalar($id) ? (int) $id : 0;
    }

    /**
     * @param mixed $raw
     * @param array{granted: int, missing: list<string>}|null $drift
     * @return string
     */
    private function renderCell(mixed $raw, ?array $drift): string
    {
        if (!is_string($raw) || $raw === '') {
            return $this->renderWildcard();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            return $this->renderWildcard();
        }

        $names = [];
        foreach ($decoded as $value) {
            if (is_string($value) && $value !== '') {
                $names[] = $value;
            }
        }
        if ($names === []) {
            return $this->renderWildcard();
        }

        $out = $this->renderCount(count($names), $drift === null ? [] : $drift['missing']) . ' ';
        if (count($names) <= self::INLINE_LIST_THRESHOLD) {
            return $out . $this->renderInlineChips($names);
        }
        return $out . $this->renderDomainSummary($names);
    }

    /**
     * @return string
     */
    private function renderWildcard(): string
    {
        return '<span class="mcp-audit-muted">'
            . HtmlEscape::toString($this->escaper->escapeHtml((string) __('All tools (follows role)')))
            . '</span>';
    }

    /**
     * "N tools", or "N tools, M missing" with the missing wire names and a hint in the title.
     *
     * @param int $granted
     * @param list<string> $missing
     * @return string
     */
    private function renderCount(int $granted, array $missing): string
    {
        $countLabel = $granted === 1 ? (string) __('1 tool') : (string) __('%1 tools', $granted);
        if ($missing === []) {
            return '<span class="mcp-scope-count">'
                . HtmlEscape::toString($this->escaper->escapeHtml($countLabel))
                . '</span>';
        }

        $label = (string) __('%1, %2 missing', $countLabel, count($missing));
        $wireNames = array_map(static fn (string $name): string => str_replace('.', '_', $name), $missing);
        $title = (string) __('Missing: %1', implode(', ', $wireNames))
            . "\n" . (string) __('Re-authorise to pick up new tools.');
        return '<span class="mcp-scope-count mcp-scope-drift" title="'
            . HtmlEscape::toString($this->escaper->escapeHtmlAttr($title))
            . '"><strong>'
            . HtmlEscape::toString($this->escaper->escapeHtml($label))
            . '</strong></span>';
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

        $parts = [];
        foreach ($byDomain as $domain => $tools) {
            $tooltip = implode("\n", $tools);
            $parts[] = '<span class="mcp-scope-domain" title="'
                . HtmlEscape::toString($this->escaper->escapeHtmlAttr($tooltip))
                . '">'
                . HtmlEscape::toString($this->escaper->escapeHtml((string) $domain))
                . ' <em>('
                . count($tools)
                . ')</em></span>';
        }
        return implode(' ', $parts);
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
