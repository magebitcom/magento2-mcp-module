<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Etc;

use DOMDocument;
use PHPUnit\Framework\TestCase;

/**
 * The message-queue and communication files validate against the oldest schemas the module supports: the
 * Magento 2.4.7-p3 copies under `Test/Unit/_files/xsd/2.4.7-p3/` (the CI version). A newer-only attribute, such
 * as `queue` on `<publisher>` (2.4.8-p4 and later), fails the file there.
 */
class QueueConfigSchemaTest extends TestCase
{
    private const XSD_DIR = __DIR__ . '/../_files/xsd/2.4.7-p3/';
    private const ETC_DIR = __DIR__ . '/../../../etc/';

    /**
     * @dataProvider configFiles
     * @param string $file
     * @param string $xsd
     * @return void
     */
    public function testFileValidatesAgainstTheOldestSupportedSchema(string $file, string $xsd): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $dom = new DOMDocument();
            self::assertTrue($dom->load(self::ETC_DIR . $file), $file . ' is not well-formed XML.');
            $valid = $dom->schemaValidate(self::XSD_DIR . $xsd);
            $messages = array_map(
                static fn (\LibXMLError $e): string => trim($e->message) . ' (line ' . $e->line . ')',
                libxml_get_errors()
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        self::assertTrue($valid, $file . ' fails ' . $xsd . ': ' . implode('; ', $messages));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function configFiles(): array
    {
        return [
            'publisher' => ['queue_publisher.xml', 'publisher.xsd'],
            'topology' => ['queue_topology.xml', 'topology.xsd'],
            'consumer' => ['queue_consumer.xml', 'consumer.xsd'],
            'communication' => ['communication.xml', 'communication.xsd'],
        ];
    }
}
