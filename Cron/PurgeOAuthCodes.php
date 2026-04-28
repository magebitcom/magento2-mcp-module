<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Cron;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\OAuth\AuthCodeRepository;
use Magebit\Mcp\Model\OAuth\AuthorizeHandoffStorage;
use Magebit\Mcp\Model\OAuth\RefreshTokenRepository;
use Throwable;

/**
 * Daily cleanup of expired OAuth authorization codes, refresh tokens, and
 * authorize-handoff rows. Each repository's `deleteExpired()` /
 * {@see AuthorizeHandoffStorage::purgeExpired()} call is a single-statement
 * DELETE that exercises the `expires_at` index, keeping memory flat
 * regardless of table size. A 24-hour grace window is built into each query
 * so audit-log correlations to recently-expired rows still resolve.
 */
class PurgeOAuthCodes
{
    public function __construct(
        private readonly AuthCodeRepository $authCodeRepository,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly AuthorizeHandoffStorage $handoffStorage,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $codes = $this->authCodeRepository->deleteExpired();
            $refreshes = $this->refreshTokenRepository->deleteExpired();
            $handoffs = $this->handoffStorage->purgeExpired();
            $this->logger->info('OAuth purge complete.', [
                'auth_codes_deleted' => $codes,
                'refresh_tokens_deleted' => $refreshes,
                'authorize_handoffs_deleted' => $handoffs,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('OAuth purge failed.', ['exception' => $e]);
        }
    }
}
