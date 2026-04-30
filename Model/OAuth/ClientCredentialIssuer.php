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
 * Mints new OAuth client credentials. Only the HMAC hash is persisted; plaintext is
 * returned to the caller exactly once. Redirect URIs must be HTTPS per OAuth 2.1
 * §8.4.2 (exception: `http://localhost` / `http://127.0.0.1` for development).
 */
class ClientCredentialIssuer
{
    /**
     * @param ClientFactory $clientFactory
     * @param ClientRepository $clientRepository
     * @param TokenGenerator $tokenGenerator
     * @param TokenHasher $tokenHasher
     */
    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly ClientRepository $clientRepository,
        private readonly TokenGenerator $tokenGenerator,
        private readonly TokenHasher $tokenHasher
    ) {
    }

    /**
     * @param string $name
     * @param array<int, string> $redirectUris
     * @param array<int, string> $allowedTools
     * @return array{client: Client, client_id: string, client_secret: string}
     * @throws InvalidArgumentException
     */
    public function issue(string $name, array $redirectUris, array $allowedTools): array
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Client name is required.');
        }
        if ($redirectUris === []) {
            throw new InvalidArgumentException('At least one redirect URI is required.');
        }
        if ($allowedTools === []) {
            throw new InvalidArgumentException('At least one allowed tool is required.');
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
        $client->setAllowedTools(array_values($allowedTools));

        $this->clientRepository->save($client);

        return [
            'client' => $client,
            'client_id' => $clientId,
            'client_secret' => $plaintextSecret,
        ];
    }

    /**
     * @param mixed $uri
     * @return void
     * @throws InvalidArgumentException
     */
    private function assertRedirectUriValid(mixed $uri): void
    {
        if (!is_string($uri)) {
            throw $this->redirectUriException();
        }

        // Reject `?` and `#` so the byte-exact match in {@see RedirectUriValidator} is
        // unambiguous — a request URI carrying decorations the client never registered
        // would otherwise either spuriously match or spuriously fail.
        if (str_contains($uri, '?') || str_contains($uri, '#')) {
            throw new InvalidArgumentException(
                'Redirect URIs must not contain query strings or fragments.'
            );
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

    /**
     * @return InvalidArgumentException
     */
    private function redirectUriException(): InvalidArgumentException
    {
        return new InvalidArgumentException(
            'Redirect URIs must be absolute HTTPS URIs (or http://localhost for development).'
        );
    }

    /**
     * RFC 4122 v4 UUID from cryptographically secure randomness.
     *
     * @return string
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
