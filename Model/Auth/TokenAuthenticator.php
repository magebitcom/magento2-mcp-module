<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Auth;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Exception\UnauthorizedException;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use Throwable;

/**
 * Verifies the `Authorization: Bearer` header on every MCP HTTP request.
 * Failure throws {@see UnauthorizedException} (controller → 401 + WWW-Authenticate).
 */
class TokenAuthenticator
{
    /**
     * @param TokenHasher $tokenHasher
     * @param TokenRepository $tokenRepository
     * @param AdminUserLookup $adminUserLookup
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly TokenHasher $tokenHasher,
        private readonly TokenRepository $tokenRepository,
        private readonly AdminUserLookup $adminUserLookup,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string|null $authorizationHeader
     * @return AuthenticatedContext
     * @throws UnauthorizedException
     */
    public function authenticate(?string $authorizationHeader): AuthenticatedContext
    {
        $plaintext = $this->extractBearer($authorizationHeader);
        if ($plaintext === null) {
            $this->logger->info('MCP auth rejected: missing or malformed Authorization header.');
            throw $this->reject();
        }

        $hash = $this->tokenHasher->hash($plaintext);

        try {
            $token = $this->tokenRepository->getByHash($hash);
        } catch (NoSuchEntityException) {
            $this->logger->info('MCP auth rejected: token not recognized.');
            throw $this->reject();
        }

        if ($token->isRevoked()) {
            $this->logger->info('MCP auth rejected: token revoked.', ['token_id' => $token->getId()]);
            throw $this->reject();
        }
        if ($token->isExpired()) {
            $this->logger->info('MCP auth rejected: token expired.', ['token_id' => $token->getId()]);
            throw $this->reject();
        }

        try {
            $admin = $this->adminUserLookup->getById($token->getAdminUserId());
        } catch (NoSuchEntityException) {
            $this->logger->info('MCP auth rejected: admin user deleted.', ['token_id' => $token->getId()]);
            throw $this->reject();
        }
        if ((int) $admin->getIsActive() !== 1) {
            $this->logger->info('MCP auth rejected: admin user inactive.', ['token_id' => $token->getId()]);
            throw $this->reject();
        }

        $tokenId = $token->getId();
        if ($tokenId !== null) {
            // Best-effort telemetry — never fail a valid auth on this.
            try {
                $this->tokenRepository->touchLastUsed($tokenId);
            } catch (Throwable $e) {
                $this->logger->warning('Failed to update MCP token last_used_at.', [
                    'token_id' => $tokenId,
                    'exception' => $e,
                ]);
            }
        }

        return new AuthenticatedContext($token, $admin);
    }

    /**
     * Generic wire-level rejection. The specific cause is logged server-side; clients see one
     * uniform message so an attacker cannot enumerate token state by message contents.
     * @return UnauthorizedException
     */
    private function reject(): UnauthorizedException
    {
        return new UnauthorizedException('Invalid bearer token.');
    }

    /**
     * @param null|string $header
     * @return null|string
     */
    private function extractBearer(?string $header): ?string
    {
        if ($header === null || $header === '') {
            return null;
        }
        $trimmed = trim($header);
        if (!preg_match('/^Bearer\s+(.+)$/i', $trimmed, $matches)) {
            return null;
        }
        $token = trim($matches[1]);
        return $token === '' ? null : $token;
    }
}
