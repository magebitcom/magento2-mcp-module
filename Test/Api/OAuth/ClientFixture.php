<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\OAuth;

use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientCredentialIssuer;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Helper\Bootstrap;

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
     * @param string $name
     * @param array $redirectUris
     * @phpstan-param array<int, string> $redirectUris
     * @return array{client: Client, client_id: string, client_secret: string}
     */
    public static function issue(
        string $name = 'test-client',
        array $redirectUris = ['https://example.com/cb']
    ): array {
        $om = Bootstrap::getObjectManager();
        /** @var ClientCredentialIssuer $issuer */
        $issuer = $om->get(ClientCredentialIssuer::class);
        return $issuer->issue($name, $redirectUris);
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
}
