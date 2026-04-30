<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * OAuth 2.1 protocol-level error. Carries the RFC 6749 / 8628 `error` token
 * (e.g. `invalid_grant`, `invalid_client`, `unsupported_grant_type`) plus the
 * HTTP status the controller should map it to. The human-readable description
 * flows through {@see LocalizedException::getMessage()} so it can show up in
 * logs and audit rows verbatim — never include token plaintext or hashes.
 */
class OAuthException extends LocalizedException
{
    /**
     * @param string $oauthError
     * @param string $description
     * @param int $httpStatus
     */
    public function __construct(
        public readonly string $oauthError,
        string $description,
        public readonly int $httpStatus = 400
    ) {
        parent::__construct(new Phrase($description));
    }
}
