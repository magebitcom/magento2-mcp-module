<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Resolves `admin_user_id` on each audit row to a linked username plus full
 * name, e.g. `admin (John Doe)` pointing at `adminhtml/user/edit/user_id/42`.
 *
 * All admin users visible on the current grid page are loaded in a single
 * query through {@see AdminUserLookup::listByIds()} — the audit log can grow
 * unbounded, so even the paginated admin grid would be an N+1 hotspot
 * otherwise.
 *
 * Rows whose admin user was deleted render the numeric id followed by
 * `(deleted)` so auditors can still trace the event back to MCP logs.
 */
class AdminUser extends Column
{
    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly AdminUserLookup $adminUserLookup,
        private readonly UrlInterface $urlBuilder,
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
        $users = $this->adminUserLookup->listByIds($ids);

        foreach ($dataSource['data']['items'] as &$item) {
            $rawId = $item[$name] ?? null;
            if (!is_scalar($rawId) || (int) $rawId === 0) {
                $item[$name] = '<span class="mcp-audit-muted">&mdash;</span>';
                continue;
            }

            $id = (int) $rawId;
            $user = $users[$id] ?? null;
            if ($user === null) {
                $item[$name] = sprintf(
                    '%d <span class="mcp-audit-muted">(deleted)</span>',
                    $id
                );
                continue;
            }

            $url = $this->urlBuilder->getUrl('adminhtml/user/edit', ['user_id' => $id]);
            $username = HtmlEscape::toString(
                $this->escaper->escapeHtml((string) $user->getUsername())
            );
            $fullName = trim(
                $this->stringData($user->getData('firstname'))
                . ' '
                . $this->stringData($user->getData('lastname'))
            );

            $label = sprintf(
                '<a href="%s">%s</a>',
                HtmlEscape::toString($this->escaper->escapeUrl($url)),
                $username
            );
            if ($fullName !== '') {
                $label .= sprintf(
                    ' <span class="mcp-audit-muted">(%s)</span>',
                    HtmlEscape::toString($this->escaper->escapeHtml($fullName))
                );
            }
            $item[$name] = $label;
        }
        unset($item);

        return $dataSource;
    }

    private function stringData(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
