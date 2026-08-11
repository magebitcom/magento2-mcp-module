<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Config;

use Magebit\Mcp\Model\Util\ConfigPathFormat;
use Magento\Framework\Exception\LocalizedException;

/**
 * Which config paths the write tool may touch: DI-injected protected prefixes admin config cannot
 * weaken, checked before an empty-by-default allowlist, so no allowlist entry can re-open one.
 */
class ConfigWritePolicy
{
    /**
     * @param ConfigWriteConfig $config
     * @param ConfigPathFormat $pathFormat
     * @param array<array-key, string> $protectedPrefixes Segment-boundary matched, so `dev`
     *        protects `dev/debug/...` without catching `developer/...`.
     */
    public function __construct(
        private readonly ConfigWriteConfig $config,
        private readonly ConfigPathFormat $pathFormat,
        private readonly array $protectedPrefixes = []
    ) {
    }

    /**
     * @param string $path
     * @return void
     * @throws LocalizedException
     */
    public function assertWritable(string $path): void
    {
        if (!$this->pathFormat->isCanonical($path)) {
            throw new LocalizedException(
                __(
                    'Path "%1" is not a well-formed configuration path. Expected two or more '
                    . 'slash-separated segments of letters, digits and underscores '
                    . '(for example tax/calculation/based_on), with no leading, trailing or embedded '
                    . 'whitespace and no empty segments.',
                    $this->pathFormat->forMessage($path)
                )
            );
        }

        if (!$this->config->isEnabled()) {
            throw new LocalizedException(
                __(
                    'Configuration writing is disabled. Enable it under Stores > Configuration > '
                    . 'Magebit > MCP Server > MCP Configuration Writer (%1).',
                    ConfigWriteConfig::XML_PATH_ENABLED
                )
            );
        }

        $protectedPrefix = $this->findProtectedPrefix($path);
        if ($protectedPrefix !== null) {
            throw new LocalizedException(
                __(
                    'Path "%1" is protected by "%2" and can never be written over MCP, '
                    . 'regardless of the allowlist. Use the admin UI or CLI.',
                    $this->pathFormat->forMessage($path),
                    $protectedPrefix
                )
            );
        }

        $allowed = $this->config->getAllowedPaths();
        if ($allowed === []) {
            throw new LocalizedException(
                __(
                    'No configuration paths are writable, so no write can run. List the paths this '
                    . 'tool may set, one per line, under Stores > Configuration > Magebit > '
                    . 'MCP Server > MCP Configuration Writer > Allowed Paths (%1).',
                    ConfigWriteConfig::XML_PATH_ALLOWED_PATHS
                )
            );
        }

        if (!in_array($path, $allowed, true)) {
            throw new LocalizedException(
                __(
                    'Path "%1" is not in the configured allowlist of writable paths.',
                    $this->pathFormat->forMessage($path)
                )
            );
        }
    }

    /**
     * Not a standalone gate: a null return only means "not protected" for input that has already
     * passed assertWritable()'s shape guard. Case, ASCII whitespace and slashes are folded around
     * the edges as defence in depth, but embedded and non-ASCII whitespace is not.
     *
     * @param string $path
     * @return string|null The protected prefix that matched, for the rejection message.
     */
    public function findProtectedPrefix(string $path): ?string
    {
        $normalized = strtolower(trim($path, " \t\n\r\0\x0B/"));

        foreach ($this->protectedPrefixes as $prefix) {
            $candidate = strtolower(trim($prefix, " \t\n\r\0\x0B/"));
            if ($candidate === '') {
                continue;
            }
            if ($normalized === $candidate || str_starts_with($normalized, $candidate . '/')) {
                return $candidate;
            }
        }

        return null;
    }
}
