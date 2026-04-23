<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\User\Model\ResourceModel\User\CollectionFactory;

/**
 * Resolves `admin_user_id` on each audit row to a linked username plus full
 * name, e.g. `admin (John Doe)` pointing at `adminhtml/user/edit/user_id/42`.
 *
 * All admin users visible on the current grid page are loaded in a single
 * query (not per-row) — the audit log can grow unbounded, so even the
 * paginated admin grid would be an N+1 hotspot otherwise.
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
        private readonly CollectionFactory $userCollectionFactory,
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
        $users = $this->loadUsers($dataSource['data']['items'], $name);

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
            $username = $this->escape($this->escaper->escapeHtml($user['username']));
            $fullName = trim(sprintf('%s %s', $user['firstname'], $user['lastname']));

            $label = sprintf(
                '<a href="%s">%s</a>',
                $this->escape($this->escaper->escapeUrl($url)),
                $username
            );
            if ($fullName !== '') {
                $label .= sprintf(
                    ' <span class="mcp-audit-muted">(%s)</span>',
                    $this->escape($this->escaper->escapeHtml($fullName))
                );
            }
            $item[$name] = $label;
        }
        unset($item);

        return $dataSource;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array{username: string, firstname: string, lastname: string}>
     */
    private function loadUsers(array $items, string $name): array
    {
        $ids = [];
        foreach ($items as $item) {
            $raw = $item[$name] ?? null;
            if (is_scalar($raw) && (int) $raw > 0) {
                $ids[(int) $raw] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToFilter('user_id', ['in' => array_keys($ids)]);
        $collection->addFieldToSelect(['user_id', 'username', 'firstname', 'lastname']);

        $out = [];
        foreach ($collection->getItems() as $user) {
            $rawUid = $user->getData('user_id');
            if (!is_scalar($rawUid)) {
                continue;
            }
            $uid = (int) $rawUid;
            $out[$uid] = [
                'username' => $this->stringData($user->getData('username')),
                'firstname' => $this->stringData($user->getData('firstname')),
                'lastname' => $this->stringData($user->getData('lastname')),
            ];
        }
        return $out;
    }

    private function stringData(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param string|array<int|string, mixed> $escaped
     */
    private function escape(string|array $escaped): string
    {
        return is_string($escaped) ? $escaped : '';
    }
}
