<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Auth;

use Magebit\Mcp\Model\Config\ModuleConfig;

/**
 * The two write layers of a request (store setting and token flag), kept apart so a
 * refusal can tell the client which one is closed: the store owner opens the first,
 * a new token opens the second.
 */
class WriteAccess
{
    public const REASON_STORE_SETTING = 'store_setting';
    public const REASON_TOKEN = 'token';

    /**
     * @param bool $storeAllowsWrites
     * @param bool $tokenAllowsWrites
     */
    public function __construct(
        public readonly bool $storeAllowsWrites,
        public readonly bool $tokenAllowsWrites
    ) {
    }

    /**
     * @param ModuleConfig $config
     * @param AuthenticatedContext $context
     * @return self
     */
    public static function resolve(ModuleConfig $config, AuthenticatedContext $context): self
    {
        return new self($config->isAllowWrites(), $context->token->getAllowWrites());
    }

    /**
     * @return bool
     */
    public function isAllowed(): bool
    {
        return $this->storeAllowsWrites && $this->tokenAllowsWrites;
    }

    /**
     * The closed layer, store setting first because a new token cannot open it.
     *
     * @return string|null
     */
    public function getClosedReason(): ?string
    {
        if (!$this->storeAllowsWrites) {
            return self::REASON_STORE_SETTING;
        }
        if (!$this->tokenAllowsWrites) {
            return self::REASON_TOKEN;
        }
        return null;
    }

    /**
     * Completes "Write tools are ..." for the closed layer; empty when writes are allowed.
     *
     * @return string
     */
    public function describeClosedLayer(): string
    {
        return match ($this->getClosedReason()) {
            self::REASON_STORE_SETTING => 'switched off for this store (setting '
                . ModuleConfig::XML_PATH_ALLOW_WRITES . '); the store owner can turn them on',
            self::REASON_TOKEN => 'not allowed for this token, which was issued without write access; '
                . 'a token with write access is needed',
            default => '',
        };
    }
}
