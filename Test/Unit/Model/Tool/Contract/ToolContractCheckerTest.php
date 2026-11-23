<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool\Contract;

use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\Tool\Contract\ToolContractChecker;
use PHPUnit\Framework\TestCase;

class ToolContractCheckerTest extends TestCase
{
    private const ACL = 'Magento_Config::config';

    private ToolContractChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new ToolContractChecker();
    }

    public function testFlagsKnownDottedName(): void
    {
        $result = $this->checker->dottedNameViolations(
            'Use system.job.get to poll.',
            ['system.job.get'],
            ToolContractChecker::DOMAIN_PREFIXES
        );
        self::assertCount(1, $result);
        self::assertStringContainsString('system.job.get', $result[0]);
    }

    public function testFlagsPrefixedDottedToken(): void
    {
        $result = $this->checker->dottedNameViolations(
            'Look it up with catalog.product.get first.',
            [],
            ToolContractChecker::DOMAIN_PREFIXES
        );
        self::assertCount(1, $result);
        self::assertStringContainsString('catalog.product.get', $result[0]);
    }

    public function testIgnoresFileNames(): void
    {
        self::assertSame([], $this->checker->dottedNameViolations(
            'See Tool/System/ConfigSet.php and system.config.xml and sales.order.json',
            [],
            ToolContractChecker::DOMAIN_PREFIXES
        ));
    }

    public function testIgnoresUnknownPrefix(): void
    {
        self::assertSame([], $this->checker->dottedNameViolations(
            'foo.bar.baz',
            [],
            ToolContractChecker::DOMAIN_PREFIXES
        ));
    }

    public function testIgnoresTwoSegmentAndUnderscoreNames(): void
    {
        self::assertSame([], $this->checker->dottedNameViolations(
            'system.job and system_job_get',
            [],
            ToolContractChecker::DOMAIN_PREFIXES
        ));
    }

    public function testValidBulkToolHasNoViolations(): void
    {
        $tool = $this->bulkTool('path', ['path' => []], 50, 200);
        self::assertSame([], $this->checker->bulkViolations($tool, [self::ACL], ['path' => 'a']));
    }

    public function testUndeclaredUnderlyingResource(): void
    {
        $tool = $this->bulkTool('path', ['path' => []], 50, 200);
        $result = $this->checker->bulkViolations($tool, [], null);
        self::assertCount(1, $result);
        self::assertStringContainsString(self::ACL, $result[0]);
    }

    public function testBulkMissingKeyField(): void
    {
        $tool = $this->bulkTool('sku', ['path' => []], 50, 200);
        $result = $this->checker->bulkViolations($tool, [self::ACL], null);
        self::assertCount(1, $result);
        self::assertStringContainsString('key field "sku"', $result[0]);
    }

    public function testCeilingBatchTooLarge(): void
    {
        $tool = $this->bulkTool('path', ['path' => []], 50, 200);
        $result = $this->checker->bulkViolations($tool, [self::ACL], ['path' => str_repeat('x', 2048)]);
        self::assertCount(1, $result);
        self::assertStringContainsString('262144', $result[0]);
    }

    /**
     * PR #48 review round 4 (C2): the endpoint caps the whole JSON-RPC body, so a batch whose `items` alone fit
     * is still flagged when the full `tools/call` request does not.
     *
     * @return void
     */
    public function testFullRequestOverTheLimitIsFlaggedWhenTheItemsAloneFit(): void
    {
        $tool = $this->bulkTool('path', ['path' => []], 1, 1);
        $padding = ToolContractChecker::MAX_BODY_BYTES - strlen((string) json_encode(['items' => [['path' => '']]]));
        $sample = ['path' => str_repeat('x', $padding)];
        self::assertSame(
            ToolContractChecker::MAX_BODY_BYTES,
            strlen((string) json_encode(['items' => [$sample]])),
            'the items alone sit exactly at the limit'
        );

        $result = $this->checker->bulkViolations($tool, [self::ACL], $sample);

        $bytes = strlen($this->request([$sample]));
        self::assertGreaterThan(ToolContractChecker::MAX_BODY_BYTES, $bytes);
        self::assertSame(
            ['system.config.set: a full tools/call request with 1 sample items is ' . $bytes
                . ' bytes, over the 262144 byte request body limit'],
            $result
        );
    }

    /**
     * The measured request is exactly the one a client sends: a full request at the limit passes, one byte more is
     * flagged.
     *
     * @return void
     */
    public function testFullRequestAtTheLimitPassesAndOneByteMoreIsFlagged(): void
    {
        $tool = $this->bulkTool('path', ['path' => []], 1, 1);
        $padding = ToolContractChecker::MAX_BODY_BYTES - strlen($this->request([['path' => '']]));
        $atLimit = ['path' => str_repeat('x', $padding)];
        self::assertSame(ToolContractChecker::MAX_BODY_BYTES, strlen($this->request([$atLimit])));

        self::assertSame([], $this->checker->bulkViolations($tool, [self::ACL], $atLimit));
        self::assertCount(
            1,
            $this->checker->bulkViolations($tool, [self::ACL], ['path' => str_repeat('x', $padding + 1)])
        );
    }

    /**
     * Escaped slashes and unicode are the larger encoding a client may send, so they are what is measured.
     *
     * @return void
     */
    public function testTheSizeIsMeasuredWithEscapedSlashesAndUnicode(): void
    {
        $tool = $this->bulkTool('path', ['path' => []], 1, 1);
        // 50000 x "ā/" is 150000 bytes unescaped, 400000 escaped ("\u0101\/").
        $result = $this->checker->bulkViolations($tool, [self::ACL], ['path' => str_repeat('ā/', 50000)]);

        self::assertCount(1, $result);
    }

    /**
     * The limit is the endpoint's own default, so the two cannot drift.
     *
     * @return void
     */
    public function testTheLimitIsTheEndpointsDefault(): void
    {
        self::assertSame(ModuleConfig::DEFAULT_MAX_BODY_KB * 1024, ToolContractChecker::MAX_BODY_BYTES);
        $config = simplexml_load_file(dirname(__DIR__, 5) . '/etc/config.xml');
        self::assertNotFalse($config);
        self::assertSame(
            (string) ModuleConfig::DEFAULT_MAX_BODY_KB,
            (string) $config->default->magebit_mcp->general->max_body_kb
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return string The body of a `tools/call` request for the test tool.
     */
    private function request(array $items): string
    {
        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => '00000000-0000-4000-8000-000000000000',
            'method' => 'tools/call',
            'params' => [
                'name' => 'system_config_set',
                'arguments' => ['items' => $items, 'dry_run' => false],
            ],
        ]);
    }

    public function testCapAboveCeiling(): void
    {
        $tool = $this->bulkTool('path', ['path' => []], 300, 200);
        $result = $this->checker->bulkViolations($tool, [self::ACL], null);
        self::assertCount(1, $result);
        self::assertStringContainsString('sync cap 300', $result[0]);
    }

    public function testCapBelowOne(): void
    {
        $tool = $this->bulkTool('path', ['path' => []], 0, 200);
        self::assertCount(1, $this->checker->bulkViolations($tool, [self::ACL], null));
    }

    /**
     * @param string $key
     * @param array<string, mixed> $properties
     * @param int $cap
     * @param int $ceiling
     * @return BulkToolInterface
     */
    private function bulkTool(string $key, array $properties, int $cap, int $ceiling): BulkToolInterface
    {
        $tool = $this->createMock(BulkToolInterface::class);
        $tool->method('getName')->willReturn('system.config.set');
        $tool->method('getUnderlyingAclResource')->willReturn(self::ACL);
        $tool->method('getItemSchema')->willReturn(['type' => 'object', 'properties' => $properties]);
        $tool->method('getItemKeyField')->willReturn($key);
        $tool->method('getSyncCap')->willReturn($cap);
        $tool->method('getAsyncCeiling')->willReturn($ceiling);
        return $tool;
    }
}
