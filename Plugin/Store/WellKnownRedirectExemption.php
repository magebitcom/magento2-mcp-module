<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Plugin\Store;

use Magebit\Mcp\Model\Url\WellKnownPathParser;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Store\Model\BaseUrlChecker;

/**
 * Stops Magento from redirecting OAuth discovery URLs to the store base URL.
 * Covers exactly the paths the WellKnownRouter serves.
 */
class WellKnownRedirectExemption
{
    /**
     * @param WellKnownPathParser $pathParser
     */
    public function __construct(
        private readonly WellKnownPathParser $pathParser
    ) {
    }

    /**
     * @param BaseUrlChecker $subject
     * @param bool $result
     * @param array<string, mixed> $uri
     * @param HttpRequest $request
     * @return bool
     */
    public function afterExecute(
        BaseUrlChecker $subject,
        bool $result,
        array $uri,
        HttpRequest $request
    ): bool {
        if ($result) {
            return true;
        }

        return $this->pathParser->parse((string) $request->getPathInfo()) !== null;
    }
}
