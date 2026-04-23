<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Auth;

use Magebit\Mcp\Exception\UnauthorizedException;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\UserFactory;

/**
 * Verifies the `Authorization: Bearer` header on every MCP HTTP request.
 *
 * The full pipeline:
 *   1. Parse `Bearer <plaintext>` (reject missing / malformed).
 *   2. Hash plaintext via {@see TokenHasher} (deterministic HMAC-SHA256).
 *   3. Lookup token row by hash (UNIQUE index → single statement).
 *   4. Reject revoked / expired tokens.
 *   5. Load admin user and reject if `is_active = 0` or user deleted.
 *   6. Touch `last_used_at` (non-blocking, direct UPDATE).
 *   7. Return {@see AuthenticatedContext} for downstream ACL / audit use.
 *
 * Any failure throws {@see UnauthorizedException}. The controller converts
 * that to a 401 with `WWW-Authenticate: Bearer realm="Magento MCP"`.
 */
class TokenAuthenticator
{
    public function __construct(
        private readonly TokenHasher $tokenHasher,
        private readonly TokenRepository $tokenRepository,
        private readonly UserFactory $userFactory
    ) {
    }

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

        $admin = $this->userFactory->create();
        // @phpstan-ignore-next-line magento.serviceContract — User module ships no repository for admin users.
        $admin->load($token->getAdminUserId());
        if ($admin->getId() === null || (int) $admin->getIsActive() !== 1) {
            throw new UnauthorizedException('Admin user is inactive or deleted.');
        }

        $tokenId = $token->getId();
        if ($tokenId !== null) {
            $this->tokenRepository->touchLastUsed($tokenId);
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
