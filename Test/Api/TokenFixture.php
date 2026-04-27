<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api;

use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\Auth\TokenGenerator;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\TokenFactory;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Helper\Bootstrap;
use RuntimeException;

/**
 * Programmatic MCP token issuance for api-functional tests.
 *
 * Composes the same services {@see \Magebit\Mcp\Console\Command\TokenCreateCommand}
 * uses, so the token row written here is byte-identical to one minted via the
 * CLI — same hash format, same admin foreign key, same scope serialization.
 *
 * Returns the plaintext bearer to the caller; it is never persisted, matching
 * production behaviour.
 */
final class TokenFixture
{
    /**
     * @phpstan-param array<int, string> $scope
     * @return array{token: string, id: int, admin_user_id: int}
     */
    public static function issueForAdmin(
        string $username = 'adminUser',
        bool $allowWrites = false,
        ?int $ttlSeconds = null,
        array $scope = []
    ): array {
        $om = Bootstrap::getObjectManager();
        /** @var AdminUserLookup $adminUserLookup */
        $adminUserLookup = $om->get(AdminUserLookup::class);
        /** @var TokenFactory $tokenFactory */
        $tokenFactory = $om->get(TokenFactory::class);
        /** @var TokenGenerator $tokenGenerator */
        $tokenGenerator = $om->get(TokenGenerator::class);
        /** @var TokenHasher $tokenHasher */
        $tokenHasher = $om->get(TokenHasher::class);
        /** @var TokenRepository $tokenRepository */
        $tokenRepository = $om->get(TokenRepository::class);

        try {
            $admin = $adminUserLookup->getByUsername($username);
        } catch (NoSuchEntityException) {
            throw new RuntimeException(sprintf(
                'Admin user "%s" not found. Did the @magentoApiDataFixture annotation run?',
                $username
            ));
        }

        $rawAdminId = $admin->getId();
        $adminId = is_scalar($rawAdminId) ? (int) $rawAdminId : 0;
        if ($adminId === 0) {
            throw new RuntimeException(sprintf('Admin user "%s" has no id.', $username));
        }

        $plaintext = $tokenGenerator->generate();
        $hash = $tokenHasher->hash($plaintext);

        $token = $tokenFactory->create();
        $token->setAdminUserId($adminId);
        $token->setName(sprintf('api-functional %s @ %s', $username, gmdate('Y-m-d\\TH:i:s\\Z')));
        $token->setTokenHash($hash);
        $token->setAllowWrites($allowWrites);
        $token->setScopes($scope === [] ? null : $scope);

        if ($ttlSeconds !== null) {
            $expires = (new \DateTimeImmutable('@' . (time() + $ttlSeconds)))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
            $token->setExpiresAt($expires);
        }

        $tokenRepository->save($token);
        $rowId = $token->getId();
        if ($rowId === null) {
            throw new RuntimeException('Saved MCP token row returned no id.');
        }

        return ['token' => $plaintext, 'id' => $rowId, 'admin_user_id' => $adminId];
    }

    /**
     * Mint a token then immediately stamp `revoked_at` on it.
     *
     * @return array{token: string, id: int, admin_user_id: int}
     */
    public static function issueRevoked(string $username = 'adminUser'): array
    {
        $issued = self::issueForAdmin($username);
        $om = Bootstrap::getObjectManager();
        /** @var TokenRepository $tokenRepository */
        $tokenRepository = $om->get(TokenRepository::class);
        $tokenRepository->revoke($issued['id']);
        return $issued;
    }

    /**
     * Mint a token whose `expires_at` is already in the past.
     *
     * @return array{token: string, id: int, admin_user_id: int}
     */
    public static function issueExpired(string $username = 'adminUser'): array
    {
        $om = Bootstrap::getObjectManager();
        /** @var AdminUserLookup $adminUserLookup */
        $adminUserLookup = $om->get(AdminUserLookup::class);
        /** @var TokenFactory $tokenFactory */
        $tokenFactory = $om->get(TokenFactory::class);
        /** @var TokenGenerator $tokenGenerator */
        $tokenGenerator = $om->get(TokenGenerator::class);
        /** @var TokenHasher $tokenHasher */
        $tokenHasher = $om->get(TokenHasher::class);
        /** @var TokenRepository $tokenRepository */
        $tokenRepository = $om->get(TokenRepository::class);

        $admin = $adminUserLookup->getByUsername($username);
        $rawAdminId = $admin->getId();
        $adminId = is_scalar($rawAdminId) ? (int) $rawAdminId : 0;

        $plaintext = $tokenGenerator->generate();
        $hash = $tokenHasher->hash($plaintext);

        $token = $tokenFactory->create();
        $token->setAdminUserId($adminId);
        $token->setName('api-functional expired');
        $token->setTokenHash($hash);
        $token->setAllowWrites(false);
        $token->setExpiresAt(gmdate('Y-m-d H:i:s', time() - 3600));

        $tokenRepository->save($token);
        $rowId = $token->getId();
        if ($rowId === null) {
            throw new RuntimeException('Saved MCP token row returned no id.');
        }

        return ['token' => $plaintext, 'id' => $rowId, 'admin_user_id' => $adminId];
    }

    /**
     * Hard-delete a token row by id. Idempotent — a missing id is silently ignored.
     */
    public static function delete(int $id): void
    {
        $om = Bootstrap::getObjectManager();
        /** @var TokenRepository $tokenRepository */
        $tokenRepository = $om->get(TokenRepository::class);
        try {
            $tokenRepository->deleteById($id);
        } catch (NoSuchEntityException) {
            // Already gone — nothing to do.
        }
    }
}
