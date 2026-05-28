<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\OAuthClient\Listing\Column;

use Magebit\Mcp\Api\Data\OAuth\ClientInterface;
use Magebit\Mcp\Model\OAuth\ToolGrantResolver;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders `allowed_tools_json` as a human label: "All tools (current + future)"
 * for the wildcard sentinel, "N tools" otherwise.
 */
class AllowedToolsSummary extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param array $components
     * @param array $data
     * @phpstan-param array<string, mixed> $components
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
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
            $raw = $item[ClientInterface::ALLOWED_TOOLS_JSON] ?? null;
            $item[$name] = self::summarize(is_string($raw) ? $raw : null);
        }
        unset($item);

        return $dataSource;
    }

    /**
     * @param string|null $json
     * @return string
     */
    private static function summarize(?string $json): string
    {
        if ($json === null || $json === '') {
            return (string) __('—');
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || $decoded === []) {
            return (string) __('—');
        }
        $names = array_values(array_filter(
            $decoded,
            static fn (mixed $v): bool => is_string($v) && $v !== ''
        ));
        if (ToolGrantResolver::isWildcard($names)) {
            return (string) __('All tools (current + future)');
        }
        return (string) __('%1 tools', count($names));
    }
}
