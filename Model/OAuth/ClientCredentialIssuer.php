<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use InvalidArgumentException;
use Magebit\Mcp\Model\Auth\TokenGenerator;
use Magebit\Mcp\Model\Auth\TokenHasher;

/**
 * Mints new OAuth client credentials.
 *
 * Generates a UUIDv4 `client_id`, a 256-bit plaintext `client_secret`, hashes
 * the secret via the shared {@see TokenHasher}, persists only the hash, and
 * returns the plaintext to the caller exactly once. Production behaviour
 * matches the api-functional fixture composition exactly — both go through
 * this service.
 *
 * Redirect URIs are validated per OAuth 2.1 §8.4.2: HTTPS only, with a
 * development-time exception for `http://localhost` and `http://127.0.0.1`.
 */
final class ClientCredentialIssuer
{
    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly ClientRepository $clientRepository,
        private readonly TokenGenerator $tokenGenerator,
        private readonly TokenHasher $tokenHasher
    ) {
    }

    /**
     * Issue a fresh client row and return the plaintext secret alongside it.
     *
     * @param string $name
     * @param array $redirectUris
     * @phpstan-param array<int, string> $redirectUris
     * @return array{client: Client, client_id: string, client_secret: string}
     * @throws InvalidArgumentException When name is empty or no redirect URIs
     *                                   are provided or any redirect URI is
     *                                   not HTTPS / http://localhost.
     */
    public function issue(string $name, array $redirectUris): array
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Client name is required.');
        }
        if ($redirectUris === []) {
            throw new InvalidArgumentException('At least one redirect URI is required.');
        }
        foreach ($redirectUris as $uri) {
            $this->assertRedirectUriValid($uri);
        }

        $clientId = self::generateUuidV4();
        $plaintextSecret = $this->tokenGenerator->generate();
        $hash = $this->tokenHasher->hash($plaintextSecret);

        $client = $this->clientFactory->create();
        $client->setName($name);
        $client->setClientId($clientId);
        $client->setClientSecretHash($hash);
        $client->setRedirectUris(array_values($redirectUris));

        $this->clientRepository->save($client);

        return [
            'client' => $client,
            'client_id' => $clientId,
            'client_secret' => $plaintextSecret,
        ];
    }

    /**
     * @param mixed $uri
     * @throws InvalidArgumentException
     */
    private function assertRedirectUriValid(mixed $uri): void
    {
        if (!is_string($uri)) {
            throw $this->redirectUriException();
        }

        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw $this->redirectUriException();
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if ($scheme === 'https') {
            return;
        }
        if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true)) {
            return;
        }

        throw $this->redirectUriException();
    }

    private function redirectUriException(): InvalidArgumentException
    {
        return new InvalidArgumentException(
            'Redirect URIs must be absolute HTTPS URIs (or http://localhost for development).'
        );
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
