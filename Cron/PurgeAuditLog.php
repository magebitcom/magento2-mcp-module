<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes audit log rows older than `magebit_mcp/audit/retention_days`.
 *
 * Raw DELETE (not a collection sweep) to keep memory flat under large logs.
 * A retention of 0 means "never purge" — legitimate for compliance-heavy
 * deployments that offload the log before deletion.
 */
class PurgeAuditLog
{
    private const TABLE = 'magebit_mcp_audit_log';
    private const CONFIG_RETENTION_DAYS = 'magebit_mcp/audit/retention_days';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $raw = $this->scopeConfig->getValue(self::CONFIG_RETENTION_DAYS);
            $days = is_scalar($raw) ? (int) $raw : 0;
        } catch (Throwable $e) {
            $this->logger->warning('MCP audit retention config unreadable, skipping purge.', ['exception' => $e]);
            return;
        }

        if ($days <= 0) {
            return;
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
            $deleted = $connection->delete(
                $this->resourceConnection->getTableName(self::TABLE),
                ['created_at < ?' => $cutoff]
            );
            if ($deleted > 0) {
                $this->logger->info(sprintf(
                    'MCP audit purge removed %d rows older than %s.',
                    $deleted,
                    $cutoff
                ));
            }
        } catch (Throwable $e) {
            $this->logger->error('MCP audit purge failed.', ['exception' => $e]);
        }
    }
}
