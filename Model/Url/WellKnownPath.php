<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Url;

/**
 * One parsed OAuth discovery URL: which document it asks for and which path it names.
 */
class WellKnownPath
{
    public const AUTHORIZATION_SERVER = 'oauth-authorization-server';
    public const PROTECTED_RESOURCE = 'oauth-protected-resource';

    /**
     * @param string $document One of the class constants.
     * @param string $resourcePath Path the URL names, empty for the root form.
     * @param bool $trailing True when `.well-known` comes after the path instead of before it.
     */
    public function __construct(
        public readonly string $document,
        public readonly string $resourcePath,
        public readonly bool $trailing
    ) {
    }
}
