<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\ModuleUpdate;

use Composer\Semver\VersionParser;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\ModuleUpdate\PackagistClient;
use Magebit\Mcp\Model\ModuleUpdate\VersionComparator;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PackagistClientTest extends TestCase
{
    private const PACKAGE = 'magebitcom/magento2-mcp-module';

    /**
     * @var Curl
     * @phpstan-var Curl&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private Curl&MockObject $curl;

    /**
     * @var LoggerInterface
     * @phpstan-var LoggerInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private LoggerInterface&MockObject $logger;

    /**
     * @var PackagistClient
     */
    private PackagistClient $client;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $versionParser = new VersionParser();
        $this->client = new PackagistClient(
            $this->curl,
            $versionParser,
            new VersionComparator($versionParser),
            $this->logger
        );
    }

    public function testReturnsHighestStableVersion(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn($this->p2Body([
            '1.0.0',
            '1.3.0',
            '1.2.0',
        ]));

        $this->assertSame('1.3.0', $this->client->getLatestStableVersion(self::PACKAGE));
    }

    public function testReturnsHighestStableVersionWithVPrefix(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn($this->p2Body([
            'v1.0.0',
            'v1.3.0',
            'v1.2.0',
        ]));

        $this->assertSame('v1.3.0', $this->client->getLatestStableVersion(self::PACKAGE));
    }

    public function testFiltersOutUnstableVersions(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn($this->p2Body([
            '2.0.0-RC1',
            '2.0.0-beta1',
            '1.9.0-alpha',
            '1.8.0',
            'dev-main',
        ]));

        $this->assertSame('1.8.0', $this->client->getLatestStableVersion(self::PACKAGE));
    }

    public function testReturnsNullWhenNoStableRelease(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn($this->p2Body(['1.0.0-beta1', 'dev-main']));

        $this->assertNull($this->client->getLatestStableVersion(self::PACKAGE));
    }

    public function testReturnsNullOnNon200(): void
    {
        $this->curl->method('getStatus')->willReturn(404);
        $this->curl->expects($this->never())->method('getBody');

        $this->assertNull($this->client->getLatestStableVersion(self::PACKAGE));
    }

    public function testReturnsNullOnOversizedBody(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(str_repeat('x', 1048577));
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->client->getLatestStableVersion(self::PACKAGE));
    }

    public function testReturnsNullOnMalformedJson(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{not json');
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->client->getLatestStableVersion(self::PACKAGE));
    }

    public function testRejectsMalformedPackageNameWithoutRequest(): void
    {
        $this->curl->expects($this->never())->method('get');
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->client->getLatestStableVersion('../../etc/passwd'));
    }

    public function testAppliesSecurityHardeningOptions(): void
    {
        $options = [];
        $this->curl->method('setOptions')
            ->willReturnCallback(function (array $arr) use (&$options): void {
                $options = $arr;
            });
        $this->curl->expects($this->once())->method('setTimeout')->with(5);
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn($this->p2Body(['1.0.0']));

        $this->client->getLatestStableVersion(self::PACKAGE);

        $this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        $this->assertSame(CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS]);
        $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame(5, $options[CURLOPT_CONNECTTIMEOUT]);
    }

    public function testRequestsExpectedHttpsEndpoint(): void
    {
        $this->curl->expects($this->once())
            ->method('get')
            ->with('https://repo.packagist.org/p2/' . self::PACKAGE . '.json');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn($this->p2Body(['1.0.0']));

        $this->client->getLatestStableVersion(self::PACKAGE);
    }

    /**
     * Builds a minimal Packagist p2 metadata body for the given version list.
     *
     * @param list<string> $versions
     * @return string
     */
    private function p2Body(array $versions): string
    {
        $releases = [];
        foreach ($versions as $version) {
            $releases[] = ['name' => self::PACKAGE, 'version' => $version];
        }

        return json_encode(['packages' => [self::PACKAGE => $releases]], JSON_THROW_ON_ERROR);
    }
}
