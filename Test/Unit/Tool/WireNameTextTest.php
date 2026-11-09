<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Agent-facing text (tool descriptions, prompt bodies, error messages) must name
 * tools the way tools/list publishes them: `system_log_list`, not `system.log.list`.
 */
class WireNameTextTest extends TestCase
{
    private const SCANNED_DIRS = ['Tool', 'Prompt', 'Model'];

    public function testNoStringLiteralNamesARegisteredToolWithDots(): void
    {
        $moduleDir = dirname(__DIR__, 3);
        $toolNames = $this->registeredToolNames($moduleDir . '/etc/di.xml');
        self::assertContains('system.log.list', $toolNames);

        $found = [];
        foreach (self::SCANNED_DIRS as $dir) {
            foreach ($this->phpFiles($moduleDir . '/' . $dir) as $file) {
                foreach ($this->agentFacingStrings($file) as [$line, $text]) {
                    foreach ($toolNames as $name) {
                        if (preg_match('/(?<![\w.])' . preg_quote($name, '/') . '(?![\w])/', $text) === 1) {
                            $found[] = sprintf('%s:%d %s', substr($file, strlen($moduleDir) + 1), $line, $name);
                        }
                    }
                }
            }
        }

        self::assertSame([], $found, 'Use the underscore wire name in agent-facing text.');
    }

    /**
     * @param string $diXml
     * @return array<int, string>
     */
    private function registeredToolNames(string $diXml): array
    {
        $xml = simplexml_load_file($diXml);
        self::assertNotFalse($xml);
        $items = $xml->xpath(
            '//type[@name="Magebit\Mcp\Model\Tool\ToolRegistry"]/arguments/argument[@name="tools"]/item/@name'
        );
        self::assertIsArray($items);

        $names = [];
        foreach ($items as $item) {
            $names[] = (string) $item;
        }
        return $names;
    }

    /**
     * @param string $dir
     * @return array<int, string>
     */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /**
     * String literals and heredoc bodies, skipping the value of `const TOOL_NAME` and
     * similar constants (the canonical dotted name stays the registry key).
     *
     * @param string $file
     * @return array<int, array{int, string}>
     */
    private function agentFacingStrings(string $file): array
    {
        $code = file_get_contents($file);
        self::assertIsString($code);

        $strings = [];
        $inConst = false;
        foreach (token_get_all($code) as $token) {
            if (!is_array($token)) {
                if ($token === ';') {
                    $inConst = false;
                }
                continue;
            }
            [$id, $text, $line] = $token;
            if ($id === T_CONST) {
                $inConst = true;
                continue;
            }
            if (!$inConst && ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE)) {
                $strings[] = [$line, $text];
            }
        }
        return $strings;
    }
}
