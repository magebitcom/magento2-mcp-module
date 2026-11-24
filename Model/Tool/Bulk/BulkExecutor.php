<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Bulk;

use Magebit\Mcp\Api\BulkContextualAclAwareInterface;
use Magebit\Mcp\Api\BulkItemAuditInterface;
use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Api\BulkToolLifecycleInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Exception\ForbiddenException;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magento\Framework\Exception\LocalizedException;
use Magento\User\Model\User;
use Throwable;

/**
 * Runs a bulk tool's items one by one: per-item ACL, error capture, row collection.
 *
 * Used for sync calls by the adapter and for background jobs by the job runner (which passes
 * `startIndex`, `afterItem`, `shouldStop`, `beforeAfterBatch` and `beforeItem`). Both pass an
 * {@see ItemAuditCollector}; for a tool that implements {@see BulkItemAuditInterface} each processed item's audit
 * detail goes into it.
 */
class BulkExecutor
{
    private const MESSAGE_ACL_DENIED = 'Your admin role does not permit the Magento section this item targets.';
    private const MESSAGE_ACL_UNRESOLVED
        = 'The permission this item needs could not be determined, so it was refused.';
    private const MESSAGE_GENERIC = 'Item failed.';
    private const NOTICE_AFTER_BATCH
        = "The items were processed, but the tool's follow-up step after the batch failed; see the store log.";

    /**
     * @param AclChecker $aclChecker
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AclChecker $aclChecker,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param BulkToolInterface $tool
     * @param array<int|string, array<string, mixed>> $items
     * @param bool $dryRun
     * @param User $admin
     * @param int $startIndex Position in array_values($items) to start from.
     * @param (callable(int, array{index: int, key: string|null, status: string, error_code: string|null, message: string|null, id: int|string|null}): void)|null $afterItem Gets each row as added to the result.
     * @param (callable(): bool)|null $shouldStop Checked before each item; true ends the loop.
     * @param (callable(): bool)|null $beforeAfterBatch Called after the loop, right before afterBatch; false
     *                                                  skips afterBatch (a job run that lost its job).
     * @param ItemAuditCollector|null $auditCollector Gets each processed item's audit detail when the tool gives it.
     * @param (callable(int, string|null): (ItemResult|null))|null $beforeItem Called with each item's index and key
     *        before the item runs. Null runs the item; a result is used instead of running it (no item ACL, no
     *        processItem, no audit detail). A throw ends the loop before the item; afterBatch then runs as after
     *        any other stop, and the throw is raised once it has.
     * @return BatchResult Rows of the items actually processed.
     * @throws Throwable When beforeBatch throws (nothing has run then), or what beforeItem threw.
     */
    public function run(
        BulkToolInterface $tool,
        array $items,
        bool $dryRun,
        User $admin,
        int $startIndex = 0,
        ?callable $afterItem = null,
        ?callable $shouldStop = null,
        ?callable $beforeAfterBatch = null,
        ?ItemAuditCollector $auditCollector = null,
        ?callable $beforeItem = null
    ): BatchResult {
        $items = array_values($items);
        $result = new BatchResult();
        $auditTool = null;
        if ($auditCollector !== null && $tool instanceof BulkItemAuditInterface) {
            $auditTool = $tool;
            $auditCollector->begin();
        }

        if ($tool instanceof BulkToolLifecycleInterface) {
            $tool->beforeBatch($items, $dryRun);
        }

        $keyField = $tool->getItemKeyField();
        $count = count($items);
        $stopError = null;
        for ($index = max(0, $startIndex); $index < $count; $index++) {
            if ($shouldStop !== null && $shouldStop()) {
                break;
            }
            $item = $items[$index];
            $key = $this->keyOf($item, $keyField);
            $itemResult = null;
            if ($beforeItem !== null) {
                // Kept, not thrown here: a throw would skip the tool's afterBatch.
                try {
                    $itemResult = $beforeItem($index, $key);
                } catch (Throwable $e) {
                    $stopError = $e;
                    break;
                }
            }
            $processed = $itemResult === null;
            $itemResult ??= $this->processOne($tool, $item, $index, $dryRun, $admin);
            $result->add($index, $key, $itemResult);
            if ($auditTool !== null && $processed) {
                $this->collectAuditDetail($auditTool, $auditCollector, $item, $itemResult, $index, $key, $dryRun);
            }
            if ($afterItem !== null) {
                $afterItem($index, $itemResult->toRow($index, $key));
            }
        }

        if ($tool instanceof BulkToolLifecycleInterface
            && ($beforeAfterBatch === null || $beforeAfterBatch())
        ) {
            try {
                $tool->afterBatch($result, $dryRun);
            } catch (Throwable $e) {
                $this->logger->error('MCP bulk tool afterBatch failed.', [
                    'tool' => $tool->getName(),
                    'exception' => $e,
                ]);
                $result->setNotice(self::NOTICE_AFTER_BATCH);
            }
        }

        if ($stopError !== null) {
            throw $stopError;
        }
        return $result;
    }

    /**
     * A detail that cannot be produced is logged and left out; it never changes the item's result.
     *
     * @param BulkToolInterface&BulkItemAuditInterface $tool
     * @param ItemAuditCollector $collector
     * @param array<string, mixed> $item
     * @param ItemResult $itemResult
     * @param int $index
     * @param string|null $key
     * @param bool $dryRun
     * @return void
     */
    private function collectAuditDetail(
        BulkToolInterface&BulkItemAuditInterface $tool,
        ItemAuditCollector $collector,
        array $item,
        ItemResult $itemResult,
        int $index,
        ?string $key,
        bool $dryRun
    ): void {
        try {
            $detail = $tool->getItemAuditDetail($item, $itemResult, $dryRun);
        } catch (Throwable $e) {
            $this->logger->error('MCP bulk item audit detail failed.', [
                'tool' => $tool->getName(),
                'index' => $index,
                'exception' => $e,
            ]);
            return;
        }
        if ($detail !== null) {
            $collector->add($index, $key, $itemResult->getStatus(), $detail);
        }
    }

    /**
     * @param BulkToolInterface $tool
     * @param array<string, mixed> $item
     * @param int $index
     * @param bool $dryRun
     * @param User $admin
     * @return ItemResult
     */
    private function processOne(
        BulkToolInterface $tool,
        array $item,
        int $index,
        bool $dryRun,
        User $admin
    ): ItemResult {
        if ($tool instanceof BulkContextualAclAwareInterface) {
            $refusal = $this->checkItemAcl($tool, $item, $index, $admin);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        try {
            return $tool->processItem($item, $dryRun);
        } catch (ForbiddenException $e) {
            return ItemResult::error(BulkErrorCode::FORBIDDEN, $e->getMessage());
        } catch (LocalizedException $e) {
            return ItemResult::error(BulkErrorCode::ITEM_FAILED, $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error('MCP bulk item failed.', [
                'tool' => $tool->getName(),
                'index' => $index,
                'exception' => $e,
            ]);
            return ItemResult::error(BulkErrorCode::ITEM_FAILED, self::MESSAGE_GENERIC);
        }
    }

    /**
     * @param BulkToolInterface&BulkContextualAclAwareInterface $tool
     * @param array<string, mixed> $item
     * @param int $index
     * @param User $admin
     * @return ItemResult|null A FORBIDDEN result, or null when every resource passes.
     */
    private function checkItemAcl(
        BulkToolInterface&BulkContextualAclAwareInterface $tool,
        array $item,
        int $index,
        User $admin
    ): ?ItemResult {
        try {
            foreach ($tool->getItemAclResources($item) as $resource) {
                // The contract types entries as strings, but a tool can still return anything.
                // @phpstan-ignore function.alreadyNarrowedType
                if (!is_string($resource) || $resource === '') {
                    return $this->unresolvedAcl($tool, $index, null);
                }
                if (!$this->aclChecker->isAllowed($admin, $resource)) {
                    return ItemResult::error(BulkErrorCode::FORBIDDEN, self::MESSAGE_ACL_DENIED);
                }
            }
        } catch (Throwable $e) {
            return $this->unresolvedAcl($tool, $index, $e);
        }
        return null;
    }

    /**
     * @param BulkToolInterface $tool
     * @param int $index
     * @param Throwable|null $exception
     * @return ItemResult
     */
    private function unresolvedAcl(BulkToolInterface $tool, int $index, ?Throwable $exception): ItemResult
    {
        $this->logger->error('MCP bulk item ACL resolution failed; refusing the item.', [
            'tool' => $tool->getName(),
            'index' => $index,
            'exception' => $exception,
        ]);
        return ItemResult::error(BulkErrorCode::FORBIDDEN, self::MESSAGE_ACL_UNRESOLVED);
    }

    /**
     * @param array<string, mixed> $item
     * @param string $keyField
     * @return string|null
     */
    private function keyOf(array $item, string $keyField): ?string
    {
        $value = $item[$keyField] ?? null;
        return is_scalar($value) ? (string)$value : null;
    }
}
