<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Log;

use Magento\Framework\App\Filesystem\DirectoryList as AppDirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

/**
 * Turns a client-supplied log file name into an absolute path, or refuses.
 *
 * This is the whole security boundary for the `system.log.*` tools: anything
 * that gets past it is read and streamed back to the caller, so an escape here
 * is an arbitrary-file read (`app/etc/env.php` carries the DB credentials and
 * the crypt key). The defence is layered:
 *
 * 1. Type and syntax, before any filesystem call — a basename only. Null bytes,
 *    `/`, `\`, `..` and anything not matching {@see FILE_NAME_PATTERN} (`*.log`
 *    or a numerically rotated `*.log.N`) are rejected outright, so no crafted
 *    string ever reaches the filesystem.
 * 2. Containment, after the path is canonicalised — the real path must start
 *    with the real log directory *plus a directory separator*. The separator is
 *    load-bearing: without it a sibling such as `var/logsecret/` shares the
 *    prefix and passes. Because canonicalisation collapses symlinks, this is
 *    also what defeats a symlink planted inside `var/log`.
 * 3. Kind — the resolved path must be a regular, readable file, so a directory
 *    named `something.log` is not mistaken for one.
 *
 * Rejection messages name the file the caller asked for but never a resolved
 * absolute path, so a probing client learns nothing about the filesystem
 * layout; driver-level failures are re-thrown generically for the same reason.
 * The base directory always comes from {@see DirectoryList} rather than a
 * hardcoded path, so an install that relocates `var/log` stays correct.
 *
 * Accepted residual risk: canonicalisation defeats symlinks, but a *hard* link
 * inside `var/log` pointing at a file outside it resolves inside the prefix
 * and would pass, as would a symlink swapped in between resolve and open
 * (TOCTOU) — Magento's File driver exposes no fd-based stat to close that
 * window. Both require pre-existing write access to `var/log`, so this is
 * post-compromise amplification, not a primary attack vector.
 */
class LogFileResolver
{
    /** Plain `*.log`, plus logrotate's numeric suffix (`system.log.1`). */
    private const FILE_NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.log(?:\.[0-9]{1,3})?$/D';

    private const MAX_NAME_LENGTH = 255;

    /** Hard ceiling on how many files a single listing reports. */
    private const MAX_LISTED_FILES = 500;

    /**
     * @param DirectoryList $directoryList
     * @param FileDriver $fileDriver
     */
    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly FileDriver $fileDriver
    ) {
    }

    /**
     * Syntax gate on a client-supplied name, with no filesystem access at all.
     * Callers use the result as the display name so the resolved path never has
     * to be taken apart again.
     *
     * @param mixed $file
     * @return string
     * @throws LocalizedException
     */
    public function normalize(mixed $file): string
    {
        if (!is_string($file)) {
            throw new LocalizedException(__('Argument "file" must be a log file name.'));
        }

        $name = trim($file);
        if ($name === '') {
            throw new LocalizedException(__('Argument "file" must be a non-empty log file name.'));
        }
        if (strlen($name) > self::MAX_NAME_LENGTH) {
            throw new LocalizedException(__('Argument "file" is too long to be a log file name.'));
        }
        if (str_contains($name, "\0")
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, '..')
        ) {
            throw new LocalizedException(__(
                'Log file "%1" is invalid — pass a plain file name from `system.log.list`, without any directory part.',
                $this->describe($name)
            ));
        }
        if (preg_match(self::FILE_NAME_PATTERN, $name) !== 1) {
            throw new LocalizedException(__(
                'Log file "%1" is invalid — only `.log` files (including rotated `.log.1`) can be read.',
                $this->describe($name)
            ));
        }

        return $name;
    }

    /**
     * @param mixed $file
     * @return string Absolute, canonical path to a readable log file.
     * @throws LocalizedException
     */
    public function resolve(mixed $file): string
    {
        $name = $this->normalize($file);
        $prefix = $this->logDirectoryPrefix();

        $resolved = $this->realPath($prefix . $name);
        if ($resolved === null) {
            throw new LocalizedException(__(
                'Log file "%1" was not found in the log directory. Call `system.log.list` for the available files.',
                $this->describe($name)
            ));
        }

        // The trailing separator on $prefix is what keeps a sibling directory
        // sharing the same prefix (`var/logsecret/`) from passing.
        if (!str_starts_with($resolved, $prefix)) {
            throw new LocalizedException(__(
                'Log file "%1" is not inside the log directory.',
                $this->describe($name)
            ));
        }

        if (!$this->isReadableFile($resolved)) {
            throw new LocalizedException(__(
                'Log file "%1" is not a readable file.',
                $this->describe($name)
            ));
        }

        return $resolved;
    }

    /**
     * Every readable log file in the log directory, name-sorted. A missing or
     * unreadable log directory lists as empty rather than raising. `truncated`
     * is true when more than {@see MAX_LISTED_FILES} exist, so a client can
     * tell the inventory was cut rather than reading it as the whole story.
     *
     * @phpstan-return array{files: list<array{file: string, size_bytes: int, modified_at: string}>, truncated: bool}
     * @return array
     */
    public function listFiles(): array
    {
        try {
            $prefix = $this->logDirectoryPrefix();
            $entries = $this->fileDriver->readDirectory(rtrim($prefix, DIRECTORY_SEPARATOR));
        } catch (LocalizedException | FileSystemException) {
            return ['files' => [], 'truncated' => false];
        }

        $names = [];
        foreach ($entries as $entry) {
            if (!str_starts_with($entry, $prefix)) {
                continue;
            }
            $name = substr($entry, strlen($prefix));
            // Defence in depth: the iterator is not recursive, so anything still
            // carrying a separator is not a direct child and is not ours to read.
            if (str_contains($name, '/') || str_contains($name, DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (preg_match(self::FILE_NAME_PATTERN, $name) === 1) {
                $names[] = $name;
            }
        }
        sort($names, SORT_STRING);

        $files = [];
        $truncated = false;
        foreach ($names as $name) {
            if (count($files) >= self::MAX_LISTED_FILES) {
                $truncated = true;
                break;
            }
            // Reuse the full containment check so a symlink out of the log
            // directory is never even advertised.
            $resolved = $this->realPath($prefix . $name);
            if ($resolved === null
                || !str_starts_with($resolved, $prefix)
                || !$this->isReadableFile($resolved)
            ) {
                continue;
            }
            $stat = $this->stat($resolved);
            $files[] = [
                'file' => $name,
                'size_bytes' => $stat['size'],
                'modified_at' => gmdate(DATE_ATOM, $stat['mtime']),
            ];
        }

        return ['files' => $files, 'truncated' => $truncated];
    }

    /**
     * @param string $path
     * @return string|null Canonical path, or null when it cannot be resolved.
     */
    private function realPath(string $path): ?string
    {
        try {
            // Annotated `string`, but it is realpath() underneath and that
            // returns false for anything that will not resolve — the guard
            // below is what turns a missing file into a rejection.
            /** @var mixed $resolved */
            $resolved = $this->fileDriver->getRealPath($path);
        } catch (FileSystemException) {
            return null;
        }

        return is_string($resolved) && $resolved !== '' ? $resolved : null;
    }

    /**
     * @param string $path
     * @return bool
     */
    private function isReadableFile(string $path): bool
    {
        try {
            return $this->fileDriver->isFile($path) && $this->fileDriver->isReadable($path);
        } catch (FileSystemException) {
            return false;
        }
    }

    /**
     * @param string $path
     * @phpstan-return array{size: int, mtime: int}
     * @return int[]
     */
    private function stat(string $path): array
    {
        try {
            $stat = $this->fileDriver->stat($path);
        } catch (FileSystemException) {
            return ['size' => 0, 'mtime' => 0];
        }
        $size = $stat['size'] ?? 0;
        $mtime = $stat['mtime'] ?? 0;

        return [
            'size' => is_numeric($size) ? (int) $size : 0,
            'mtime' => is_numeric($mtime) ? (int) $mtime : 0,
        ];
    }

    /**
     * @return string Resolved log directory with a trailing separator.
     * @throws LocalizedException
     */
    private function logDirectoryPrefix(): string
    {
        /**
         * @var mixed $configured Annotated `string`; in practice `getPath()`
         *     throws `FileSystemException` for an unrecognised code rather
         *     than returning null, but the `is_string()` guard below is kept
         *     as harmless belt-and-braces.
         */
        $configured = $this->directoryList->getPath(AppDirectoryList::LOG);
        $directory = is_string($configured) && $configured !== ''
            ? $this->realPath($configured)
            : null;
        if ($directory === null || !$this->isDirectory($directory)) {
            throw new LocalizedException(__('The log directory is not available on this installation.'));
        }

        return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * @param string $path
     * @return bool
     */
    private function isDirectory(string $path): bool
    {
        try {
            return $this->fileDriver->isDirectory($path);
        } catch (FileSystemException) {
            return false;
        }
    }

    /**
     * Renders a rejected name safely: control characters would otherwise be
     * echoed straight back into a client log line.
     *
     * @param string $name
     * @return string
     */
    private function describe(string $name): string
    {
        $safe = preg_replace('/[^\x20-\x7E]/', '?', $name) ?? '';

        return strlen($safe) > 100 ? substr($safe, 0, 100) . '…' : $safe;
    }
}
