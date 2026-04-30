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

class PurgeOAuthCodes
{
    /**
     * @param AuthCodeRepository $authCodeRepository
     * @param RefreshTokenRepository $refreshTokenRepository
     * @param AuthorizeHandoffStorage $handoffStorage
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AuthCodeRepository $authCodeRepository,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly AuthorizeHandoffStorage $handoffStorage,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
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
