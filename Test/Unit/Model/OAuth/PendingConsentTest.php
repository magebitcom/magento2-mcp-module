<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Model\OAuth\AuthorizeHandoffStorage;
use Magebit\Mcp\Model\OAuth\PendingConsent;
use Magento\Backend\Model\Session;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;

class PendingConsentTest extends TestCase
{
    private const NONCE = '0123456789abcdef0123456789abcdef';

    private const START = 1_800_000_000;

    private Session $session;
    private int $now = self::START;
    private PendingConsent $pendingConsent;

    protected function setUp(): void
    {
        $this->session = self::createArraySession();
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);
        $this->pendingConsent = new PendingConsent($this->session, $dateTime);
    }

    /**
     * @return void
     */
    public function testNothingPendingByDefault(): void
    {
        self::assertNull($this->pendingConsent->getRoutePath());
    }

    /**
     * @return void
     */
    public function testRememberedNonceBecomesConsentRoutePath(): void
    {
        $this->pendingConsent->remember(self::NONCE);

        self::assertSame('magebit_mcp/oauth/authorize/h/' . self::NONCE, $this->pendingConsent->getRoutePath());
    }

    /**
     * @return void
     */
    public function testForgetClearsThePendingConsent(): void
    {
        $this->pendingConsent->remember(self::NONCE);
        $this->pendingConsent->forget();

        self::assertNull($this->pendingConsent->getRoutePath());
    }

    /**
     * @return void
     */
    public function testPendingConsentLastsForTheHandoffLifetime(): void
    {
        $this->pendingConsent->remember(self::NONCE);
        $this->now = self::START + PendingConsent::TTL_SECONDS;

        self::assertSame('magebit_mcp/oauth/authorize/h/' . self::NONCE, $this->pendingConsent->getRoutePath());
    }

    /**
     * @return void
     */
    public function testExpiredPendingConsentIsIgnoredAndCleared(): void
    {
        $this->pendingConsent->remember(self::NONCE);
        $this->now = self::START + PendingConsent::TTL_SECONDS + 1;

        self::assertNull($this->pendingConsent->getRoutePath());

        $this->now = self::START;
        self::assertNull($this->pendingConsent->getRoutePath());
    }

    /**
     * @return void
     */
    public function testTtlMatchesTheHandoffLifetime(): void
    {
        self::assertSame(AuthorizeHandoffStorage::DEFAULT_TTL_SECONDS, PendingConsent::TTL_SECONDS);
    }

    /**
     * @dataProvider malformedNonceProvider
     * @param mixed $nonce
     * @return void
     */
    public function testMalformedNonceIsNotRemembered(mixed $nonce): void
    {
        $this->pendingConsent->remember($nonce);

        self::assertNull($this->pendingConsent->getRoutePath());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedNonceProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'not hex' => ['../../admin/dashboard'],
            'too short' => ['abc123'],
            'array' => [[self::NONCE]],
        ];
    }

    /**
     * @dataProvider tamperedSessionValueProvider
     * @param mixed $value
     * @return void
     */
    public function testTamperedSessionValueIsIgnored(mixed $value): void
    {
        $this->session->setData('magebit_mcp_pending_consent', $value);

        self::assertNull($this->pendingConsent->getRoutePath());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function tamperedSessionValueProvider(): array
    {
        return [
            'bare nonce' => [self::NONCE],
            'url as nonce' => [['nonce' => 'https://evil.test/', 'at' => self::START]],
            'no timestamp' => [['nonce' => self::NONCE]],
            'string timestamp' => [['nonce' => self::NONCE, 'at' => (string) self::START]],
        ];
    }

    /**
     * Backend session whose magic data accessors work on a plain array.
     *
     * @return Session
     */
    private static function createArraySession(): Session
    {
        return new class extends Session {
            /** @var array<string, mixed> */
            private array $values = [];

            public function __construct()
            {
            }

            /**
             * @param string $key
             * @param bool $clear
             * @return mixed
             */
            public function getData($key = '', $clear = false)
            {
                $value = $this->values[$key] ?? null;
                if ($clear) {
                    unset($this->values[$key]);
                }
                return $value;
            }

            /**
             * @param string $method
             * @param array<int, mixed> $args
             * @return mixed
             */
            public function __call($method, $args)
            {
                if ($method === 'setData' && is_string($args[0] ?? null)) {
                    $this->values[$args[0]] = $args[1] ?? null;
                }
                return $this;
            }
        };
    }
}
