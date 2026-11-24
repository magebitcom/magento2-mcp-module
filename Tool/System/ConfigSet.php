<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System;

use Magebit\Mcp\Api\BulkContextualAclAwareInterface;
use Magebit\Mcp\Api\BulkItemAuditInterface;
use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Api\BulkToolLifecycleInterface;
use Magebit\Mcp\Model\Config\ConfigPathWriter;
use Magebit\Mcp\Model\Config\ConfigWritePolicy;
use Magebit\Mcp\Model\Config\SensitiveFieldGuard;
use Magebit\Mcp\Model\Tool\Bulk\BatchResult;
use Magebit\Mcp\Model\Tool\Bulk\BulkErrorCode;
use Magebit\Mcp\Model\Tool\Bulk\ItemResult;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Framework\App\Cache\Manager;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Throwable;

/**
 * MCP bulk tool `system.config.set` — write `core_config_data` paths, one item each. Four gates must
 * pass per item: the write policy, the sensitivity guard, the target section's own admin ACL, and every
 * check the writer makes.
 *
 * The audit row keeps each item's `{path, scope, scope_code, previous, value}` ({@see BulkItemAuditInterface}),
 * for dry runs too. An item refused before the current value was read (validation, policy, sensitivity, section,
 * or a dry-run writer refusal) has no `previous`: a value the gates refused is never read. For a path the
 * sensitivity guard refuses, `value` is recorded as `[sensitive]`: the redactor cannot recognise a password or key.
 */
class ConfigSet implements
    BulkToolInterface,
    BulkToolLifecycleInterface,
    BulkContextualAclAwareInterface,
    BulkItemAuditInterface
{
    public const TOOL_NAME = 'system.config.set';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_config_set';

    private const MAX_ITEMS = 50;
    private const CACHE_TYPE = 'config';
    private const MASKED_VALUE = '[sensitive]';

    /**
     * The value read for an item, keyed by path and scope, until its audit detail takes it. Duplicates are refused
     * per call, so the key is unique within a batch.
     *
     * @var array<string, string|null>
     */
    private array $previousByItem = [];

    /**
     * @param ConfigWritePolicy $writePolicy
     * @param SensitiveFieldGuard $sensitiveFieldGuard
     * @param ConfigPathWriter $pathWriter
     * @param Manager $cacheManager
     */
    public function __construct(
        private readonly ConfigWritePolicy $writePolicy,
        private readonly SensitiveFieldGuard $sensitiveFieldGuard,
        private readonly ConfigPathWriter $pathWriter,
        private readonly Manager $cacheManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Set System Configuration Values';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Write Magento system-configuration values. Each item sets one value by its '
            . 'slash-separated path, and the items are written in order, each checked on its own. '
            . 'Only paths an administrator has added to the MCP Configuration Writer allowlist can be '
            . 'written, and a protected set (admin, payment, web URLs, system, dev, oauth and the MCP '
            . 'module\'s own settings) is refused regardless of the allowlist. Paths with no field in '
            . 'system.xml are refused: if the admin UI cannot set it, neither can this tool. That '
            . 'excludes the whole `design/*` family, which lives in Content > Design > Configuration '
            . 'rather than in system.xml, and any field that takes a file upload. Two items with the '
            . 'same path and scope refuse the whole call. Each written row reports the previous value. '
            . 'A dry run makes the same checks without saving anything, including the field\'s own value '
            . 'validation; it cannot catch a check the store makes only after saving (such as the allowed, '
            . 'base and default currencies), nor check the value of the few fields whose check itself changes '
            . 'something.';
    }

    /**
     * @inheritDoc
     */
    public function getItemSchema(): array
    {
        return Schema::object()
            ->string('path', fn (StringBuilder $s) => $s
                ->pattern('^[A-Za-z0-9_]+(?:/[A-Za-z0-9_]+){2,}$')
                ->description('Slash-separated config path (section/group/field).')
                ->required())
            ->string('value', fn (StringBuilder $s) => $s
                ->description('New value. Booleans are `0` / `1`; the field\'s '
                    . 'backend model coerces and validates it.')
                ->required())
            ->string('scope', fn (StringBuilder $s) => $s
                ->enum(['default', 'websites', 'stores'])
                ->description('Scope level. Defaults to `default`.'))
            ->string('scope_code', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Website / store code when `scope` is `websites` or `stores`.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    public function getItemKeyField(): string
    {
        return 'path';
    }

    /**
     * @inheritDoc
     */
    public function getSyncCap(): int
    {
        return self::MAX_ITEMS;
    }

    /**
     * @inheritDoc
     */
    public function getAsyncCeiling(): int
    {
        return self::MAX_ITEMS;
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    /**
     * @inheritDoc
     */
    public function getUnderlyingAclResource(): string
    {
        return 'Magento_Config::config';
    }

    /**
     * An unresolvable path answers [] (the writer refuses it with a better message); a resolvable
     * section with no usable <resource> answers [''], which the executor turns into FORBIDDEN.
     *
     * @inheritDoc
     */
    public function getItemAclResources(array $item): array
    {
        $rawPath = $item['path'] ?? null;
        if (!is_string($rawPath) || $rawPath === '') {
            return [];
        }

        $section = $this->pathWriter->sectionFor($rawPath);
        if ($section === null) {
            return [];
        }

        return [$this->sectionResource($section) ?? ''];
    }

    /**
     * @inheritDoc
     */
    public function getWriteMode(): WriteMode
    {
        return WriteMode::WRITE;
    }

    /**
     * @inheritDoc
     */
    public function getConfirmationRequired(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function beforeBatch(array $items, bool $dryRun): void
    {
        $this->previousByItem = [];
        $seen = [];
        foreach ($items as $item) {
            $path = $item['path'] ?? null;
            if (!is_string($path) || $path === '') {
                continue;
            }

            [$scope, $scopeCode] = $this->normaliseScope($item);
            $identity = $this->canonicalIdentity($path, $scope, $scopeCode);
            if (isset($seen[$identity])) {
                throw new LocalizedException(
                    __(
                        'Duplicate item for %1 (%2/%3); send one value per path and scope.',
                        $path,
                        $scope,
                        (string) $scopeCode
                    )
                );
            }
            $seen[$identity] = true;
        }
    }

    /**
     * @inheritDoc
     */
    public function afterBatch(BatchResult $result, bool $dryRun): void
    {
        if ($dryRun || $result->summary()['ok'] === 0) {
            return;
        }

        $this->cacheManager->clean([self::CACHE_TYPE]);
    }

    /**
     * @inheritDoc
     */
    public function processItem(array $item, bool $dryRun): ItemResult
    {
        $path = $this->stringField($item, 'path');
        $value = $this->stringField($item, 'value');
        if ($path === null || $value === null) {
            return ItemResult::error(
                BulkErrorCode::VALIDATION_FAILED,
                sprintf('Parameter "%s" is required.', $path === null ? 'path' : 'value')
            );
        }

        try {
            [$scope, $scopeCode] = $this->normaliseScope($item);
            $this->assertKnownScope($scope);
        } catch (LocalizedException $e) {
            return ItemResult::error(BulkErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        if ($scope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT && $scopeCode === null) {
            return ItemResult::error(
                BulkErrorCode::VALIDATION_FAILED,
                'Parameter "scope_code" is required when "scope" is "websites" or "stores".'
            );
        }

        $refusal = $this->gateRefusal($path);
        if ($refusal !== null) {
            return $refusal;
        }

        try {
            // A dry run runs the same checks as the write, the field's backend model included, without saving.
            if ($dryRun) {
                $this->pathWriter->validate($path, $value, $scope, $scopeCode);
            }
            $previous = $this->pathWriter->currentValue($path, $scope, $scopeCode);
            $this->previousByItem[$this->identity($path, $scope, $scopeCode)] = $previous;
            if (!$dryRun) {
                $this->pathWriter->write($path, $value, $scope, $scopeCode);
            }
        } catch (LocalizedException $e) {
            // The writer signals every refusal (undeclared, redirected, file upload, env lock, scope)
            // and a failed save the same way, so all of it is reported as a validation failure.
            return ItemResult::error(BulkErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return ItemResult::ok(null, 'previous: ' . ($previous ?? '(not set)'));
    }

    /**
     * `{path, scope, scope_code, previous, value}`; without `previous` when the item never reached the read.
     *
     * @inheritDoc
     */
    public function getItemAuditDetail(array $item, ItemResult $result, bool $dryRun): ?array
    {
        $path = $this->stringField($item, 'path');
        if ($path === null) {
            return null;
        }
        [$scope, $scopeCode] = $this->normaliseScope($item);
        $detail = ['path' => $path, 'scope' => $scope, 'scope_code' => $scopeCode];

        $identity = $this->identity($path, $scope, $scopeCode);
        if (array_key_exists($identity, $this->previousByItem)) {
            $detail['previous'] = $this->previousByItem[$identity];
            unset($this->previousByItem[$identity]);
        }
        $value = $item['value'] ?? null;
        $detail['value'] = $this->isSensitive($path)
            ? self::MASKED_VALUE
            : (is_scalar($value) ? (string) $value : null);

        return $detail;
    }

    /**
     * Asks the same guard processItem() asks, so the per-item audit detail never keeps a value the guard calls
     * sensitive (the raw request arguments in `arguments_json` are not masked here). Asked again rather than
     * remembered, because an item refused by the per-item ACL never reaches processItem(). A guard that throws counts
     * as a refusal.
     *
     * @param string $path
     * @return bool
     */
    private function isSensitive(string $path): bool
    {
        try {
            return $this->sensitiveFieldGuard->rejectionFor($path) !== null;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @param string $path
     * @param string $scope
     * @param string|null $scopeCode
     * @return string
     */
    private function identity(string $path, string $scope, ?string $scopeCode): string
    {
        return $path . "\0" . $scope . "\0" . (string) $scopeCode;
    }

    /**
     * The duplicate key: the scope as the writer resolves it, so a website's or store's id and code are one scope.
     * A code that resolves to nothing keeps its own text under another prefix, so it never collapses with a
     * resolved scope; its item is refused on its own.
     *
     * @param string $path
     * @param string $scope
     * @param string|null $scopeCode
     * @return string
     */
    private function canonicalIdentity(string $path, string $scope, ?string $scopeCode): string
    {
        $id = $this->pathWriter->canonicalScopeId($scope, $scopeCode);

        return $path . "\0" . $scope . "\0" . ($id === null ? 'unresolved:' . (string) $scopeCode : 'id:' . $id);
    }

    /**
     * The policy, the sensitivity guard and the section check, in that order.
     *
     * @param string $path
     * @return ItemResult|null
     */
    private function gateRefusal(string $path): ?ItemResult
    {
        try {
            $this->writePolicy->assertWritable($path);
        } catch (LocalizedException $e) {
            return ItemResult::error(BulkErrorCode::FORBIDDEN, $e->getMessage());
        }

        $reason = $this->sensitiveFieldGuard->rejectionFor($path);
        if ($reason !== null) {
            return ItemResult::error(
                BulkErrorCode::FORBIDDEN,
                (string) __('Path "%1" cannot be written over MCP (%2). Use the admin UI or CLI.', $path, $reason)
            );
        }

        // A section with no <resource> is refused by Stores > Configuration for every role. The item ACL
        // answers '' for it, so this is the backstop that keeps that refusal from depending on the caller.
        $section = $this->pathWriter->sectionFor($path);
        if ($section !== null && $this->sectionResource($section) === null) {
            return ItemResult::error(
                BulkErrorCode::FORBIDDEN,
                (string) __(
                    'Path "%1" is in a configuration section that declares no admin permission, which '
                    . 'the admin UI refuses to save for every role. This tool refuses it too.',
                    $path
                )
            );
        }

        return null;
    }

    /**
     * The admin resource guarding the section the row lands in, or null when there is none to check.
     *
     * @param Section $section
     * @return string|null
     */
    private function sectionResource(Section $section): ?string
    {
        $resource = $section->getAttribute('resource');

        return is_string($resource) && $resource !== '' ? $resource : null;
    }

    /**
     * Default scope never carries a scope code, so `{scope: default}` and `{scope: default, scope_code: ""}`
     * are the same item.
     *
     * @param array $item
     * @phpstan-param array<string, mixed> $item
     * @return array{0: string, 1: string|null}
     */
    private function normaliseScope(array $item): array
    {
        $rawScope = $item['scope'] ?? ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $scope = is_string($rawScope) ? $rawScope : '';

        $rawScopeCode = $item['scope_code'] ?? null;
        $scopeCode = is_string($rawScopeCode) && $rawScopeCode !== '' ? $rawScopeCode : null;

        return [$scope, $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT ? null : $scopeCode];
    }

    /**
     * @param array $item
     * @phpstan-param array<string, mixed> $item
     * @param string $key
     * @return string|null
     */
    private function stringField(array $item, string $key): ?string
    {
        $raw = $item[$key] ?? null;

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * @param string $scope
     * @return void
     * @throws LocalizedException
     */
    private function assertKnownScope(string $scope): void
    {
        if (!in_array(
            $scope,
            [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, ScopeInterface::SCOPE_WEBSITES, ScopeInterface::SCOPE_STORES],
            true
        )) {
            throw new LocalizedException(
                __('Unsupported scope "%1". Expected "default", "websites", or "stores".', $scope)
            );
        }
    }
}
