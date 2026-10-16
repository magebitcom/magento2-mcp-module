<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\Security;

use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\RateLimiter\ConfigurableRateLimiter;
use Magebit\Mcp\Test\Api\McpTestCase;
use Magento\Framework\App\CacheInterface;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * With rate limiting on, the limit counts per admin and per tool inside one minute.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class RateLimitTest extends McpTestCase
{
    private const LIMIT = 3;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        // Stay inside one minute window, so the counter does not reset mid-test.
        while (time() % 60 >= 50) {
            usleep(250000);
        }
        ConfigState::set('magebit_mcp/rate_limiting/enabled', '1');
        ConfigState::set('magebit_mcp/rate_limiting/requests_per_minute', (string) self::LIMIT);
        $this->cleanCounters();
        parent::setUp();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->cleanCounters();
        ConfigState::restoreAll();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testFourthCallToSameToolIsRateLimited(): void
    {
        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->assertJsonRpcSuccess($this->toolsCall('system.whoami'));
        }

        $response = $this->toolsCall('system.whoami');

        $this->assertJsonRpcError($response, ErrorCode::RATE_LIMITED->value, 200);
        $data = $response['body']['error']['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(self::LIMIT, $data['limit'] ?? null);
        $retryAfter = $data['retry_after_seconds'] ?? null;
        self::assertIsInt($retryAfter);
        self::assertGreaterThanOrEqual(1, $retryAfter);
        self::assertLessThanOrEqual(60, $retryAfter);

        $audit = AuditLogReader::latest('system.whoami', $this->tokenRowId);
        self::assertNotNull($audit, 'Expected an audit row for system.whoami.');
        self::assertSame('-32013', $audit['error_code'] ?? null);
    }

    /**
     * @return void
     */
    public function testOtherToolIsNotAffected(): void
    {
        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->assertJsonRpcSuccess($this->toolsCall('system.whoami'));
        }
        $this->assertJsonRpcError($this->toolsCall('system.whoami'), ErrorCode::RATE_LIMITED->value, 200);

        $this->assertJsonRpcSuccess($this->toolsCall('system.store.list'));
    }

    /**
     * @return void
     */
    private function cleanCounters(): void
    {
        Bootstrap::getObjectManager()->get(CacheInterface::class)->clean([ConfigurableRateLimiter::CACHE_TAG]);
    }
}
