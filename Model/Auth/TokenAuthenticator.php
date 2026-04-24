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
    public function __construct(
        private readonly TokenHasher $tokenHasher,
        private readonly TokenRepository $tokenRepository,
        private readonly AdminUserLookup $adminUserLookup,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws UnauthorizedException
     */
    public function authenticate(?string $authorizationHeader): AuthenticatedContext
    {
        $plaintext = $this->extractBearer($authorizationHeader);
        if ($plaintext === null) {
            throw new UnauthorizedException('Missing or malformed Authorization header.');
        }

        $hash = $this->tokenHasher->hash($plaintext);

        try {
            $token = $this->tokenRepository->getByHash($hash);
        } catch (NoSuchEntityException) {
            throw new UnauthorizedException('Invalid bearer token.');
        }

        if ($token->isRevoked()) {
            throw new UnauthorizedException('Token has been revoked.');
        }
        if ($token->isExpired()) {
            throw new UnauthorizedException('Token has expired.');
        }

        try {
            $admin = $this->adminUserLookup->getById($token->getAdminUserId());
        } catch (NoSuchEntityException) {
            throw new UnauthorizedException('Admin user is inactive or deleted.');
        }
        if ((int) $admin->getIsActive() !== 1) {
            throw new UnauthorizedException('Admin user is inactive or deleted.');
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
