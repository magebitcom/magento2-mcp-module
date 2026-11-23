<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Job;

use Magebit\Mcp\Api\Data\TokenInterface;

/**
 * The connection a job belongs to, as the job table matches it: an OAuth connection is the admin and the client
 * (every access token of it, rotated ones included), a personal connection is the token itself.
 */
class JobConnection
{
    /**
     * @param int|null $adminUserId Set for an OAuth connection.
     * @param int|null $oauthClientId Set for an OAuth connection.
     * @param int|null $tokenId Set for a personal connection.
     */
    private function __construct(
        public readonly ?int $adminUserId,
        public readonly ?int $oauthClientId,
        public readonly ?int $tokenId
    ) {
    }

    /**
     * @param int $adminUserId
     * @param int $oauthClientId
     * @return self
     */
    public static function oauth(int $adminUserId, int $oauthClientId): self
    {
        return new self($adminUserId, $oauthClientId, null);
    }

    /**
     * @param int $tokenId
     * @return self
     */
    public static function personal(int $tokenId): self
    {
        return new self(null, null, $tokenId);
    }

    /**
     * @param TokenInterface $token
     * @return self|null Null for a personal token without an id, which no job can point at.
     */
    public static function fromToken(TokenInterface $token): ?self
    {
        $clientId = $token->getOAuthClientId();
        if ($clientId !== null) {
            return self::oauth($token->getAdminUserId(), $clientId);
        }
        $tokenId = $token->getId();
        return $tokenId === null ? null : self::personal($tokenId);
    }

    /**
     * Equal for two tokens of the same connection, so a selection can be de-duplicated.
     *
     * @return string
     */
    public function key(): string
    {
        return $this->oauthClientId !== null
            ? 'oauth:' . $this->adminUserId . ':' . $this->oauthClientId
            : 'token:' . $this->tokenId;
    }
}
