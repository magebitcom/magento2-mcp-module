<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

/**
 * Immutable carrier for the per-client authorization knobs: auth mode, pinned
 * service admin, optional personal-mode whitelists, and the disabled flag.
 */
final class AuthorizationOptions
{
    /**
     * @param AuthMode $mode
     * @param int|null $serviceAdminUserId
     * @param array<int, int> $allowedAdminUserIds
     * @param array<int, int> $allowedAdminRoleIds
     * @param bool $disabled
     */
    public function __construct(
        public readonly AuthMode $mode,
        public readonly ?int $serviceAdminUserId,
        public readonly array $allowedAdminUserIds,
        public readonly array $allowedAdminRoleIds,
        public readonly bool $disabled
    ) {
    }

    /**
     * @return self
     */
    public static function personalDefault(): self
    {
        return new self(
            mode: AuthMode::PERSONAL,
            serviceAdminUserId: null,
            allowedAdminUserIds: [],
            allowedAdminRoleIds: [],
            disabled: false
        );
    }
}
