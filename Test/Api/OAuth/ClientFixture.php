<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\OAuth;

use Magebit\Mcp\Model\Auth\TokenGenerator;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientFactory;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Helper\Bootstrap;
use RuntimeException;

/**
 * Programmatic OAuth client issuance for api-functional tests.
 *
 * Returns the plaintext secret to the caller; it is never persisted, matching
 * production behaviour.
 */
final class ClientFixture
{
    /**
     * Issue a fresh OAuth client row and return the plaintext secret alongside it.
     *
     * @param array $redirectUris
     * @phpstan-param array<int, string> $redirectUris
     * @return array{client: Client, client_id: string, client_secret: string}
     */
    public static function issue(
        string $name = 'test-client',
        array $redirectUris = ['https://example.com/cb']
    ): array {
        $om = Bootstrap::getObjectManager();

        // TODO(task 5): replace with ClientCredentialIssuer once that service exists.
        /** @var ClientFactory $factory */
        $factory = $om->get(ClientFactory::class);
        /** @var ClientRepository $repo */
        $repo = $om->get(ClientRepository::class);
        /** @var TokenGenerator $tokenGenerator */
        $tokenGenerator = $om->get(TokenGenerator::class);
        /** @var TokenHasher $tokenHasher */
        $tokenHasher = $om->get(TokenHasher::class);

        $plaintextSecret = $tokenGenerator->generate();
        $hash = $tokenHasher->hash($plaintextSecret);
        $clientId = self::generateUuidV4();

        $client = $factory->create();
        $client->setClientId($clientId);
        $client->setClientSecretHash($hash);
        $client->setName($name);
        $client->setRedirectUris($redirectUris);
        $repo->save($client);

        if ($client->getId() === null) {
            throw new RuntimeException('Saved OAuth client row returned no id.');
        }

        return [
            'client' => $client,
            'client_id' => $clientId,
            'client_secret' => $plaintextSecret,
        ];
    }

    /**
     * Hard-delete a client row by id. Idempotent — a missing id is silently ignored.
     */
    public static function delete(int $id): void
    {
        $om = Bootstrap::getObjectManager();
        /** @var ClientRepository $repo */
        $repo = $om->get(ClientRepository::class);
        try {
            $repo->deleteById($id);
        } catch (NoSuchEntityException) {
            // Already gone — nothing to do.
        }
    }

    /**
     * RFC 4122 v4 UUID from cryptographically secure randomness.
     */
    private static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        // Version 4 (random): set the high nibble of byte 6 to 0100.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        // Variant RFC 4122: set the high two bits of byte 8 to 10.
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
