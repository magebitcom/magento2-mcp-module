<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\ModuleUpdate;

use Composer\Semver\VersionParser;
use Magebit\Mcp\Api\LoggerInterface;
use Magento\Framework\HTTP\Client\Curl;
use Throwable;

/**
 * Looks up the latest stable release of a package from the Packagist p2 metadata API.
 *
 * Hardened for unattended use: HTTPS-only, no redirects, bounded timeout, capped response.
 * Never throws — every failure path logs and returns null so cron can't be broken by Packagist.
 */
class PackagistClient
{
    private const ENDPOINT = 'https://repo.packagist.org/p2/%s.json';
    private const TIMEOUT_SECONDS = 5;
    private const MAX_RESPONSE_BYTES = 1048576;

    /**
     * Packagist's own package-name grammar; guards against a tampered composer.json
     * smuggling path traversal or a host into the URL.
     */
    private const PACKAGE_NAME_PATTERN = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#';

    /**
     * @param Curl $curl
     * @param VersionParser $versionParser
     * @param VersionComparator $versionComparator
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Curl $curl,
        private readonly VersionParser $versionParser,
        private readonly VersionComparator $versionComparator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Highest stable version published for the package, or null on any failure.
     *
     * @param string $packageName
     * @return ?string
     */
    public function getLatestStableVersion(string $packageName): ?string
    {
        if (preg_match(self::PACKAGE_NAME_PATTERN, $packageName) !== 1) {
            $this->logger->warning('MCP update check rejected malformed package name.', ['package' => $packageName]);
            return null;
        }

        try {
            $body = $this->fetch(sprintf(self::ENDPOINT, $packageName));
            if ($body === null) {
                return null;
            }

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return $this->highestStableVersion($decoded, $packageName);
        } catch (Throwable $e) {
            $this->logger->warning(
                'MCP update check failed to query Packagist.',
                ['package' => $packageName, 'exception' => $e]
            );
            return null;
        }
    }

    /**
     * @param string $uri
     * @return ?string Raw response body, or null if the request did not return a usable 200.
     */
    private function fetch(string $uri): ?string
    {
        $this->curl->setTimeout(self::TIMEOUT_SECONDS);
        $this->curl->setOptions([
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $this->curl->get($uri);

        if ($this->curl->getStatus() !== 200) {
            return null;
        }

        $body = $this->curl->getBody();
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            $this->logger->warning('MCP update check ignored oversized Packagist response.', ['uri' => $uri]);
            return null;
        }

        return $body;
    }

    /**
     * Walks the p2 release list, keeps stable versions, returns the highest.
     *
     * @param array<string, mixed> $decoded
     * @param string $packageName
     * @return ?string
     */
    private function highestStableVersion(array $decoded, string $packageName): ?string
    {
        $packages = $decoded['packages'] ?? null;
        if (!is_array($packages) || !isset($packages[$packageName]) || !is_array($packages[$packageName])) {
            return null;
        }

        $highest = null;
        foreach ($packages[$packageName] as $release) {
            if (!is_array($release)) {
                continue;
            }
            $version = $release['version'] ?? null;
            if (!is_string($version) || $version === '') {
                continue;
            }
            if ($this->versionParser->parseStability($version) !== 'stable') {
                continue;
            }
            if ($highest === null || $this->versionComparator->isNewer($version, $highest)) {
                $highest = $version;
            }
        }

        return $highest;
    }
}
