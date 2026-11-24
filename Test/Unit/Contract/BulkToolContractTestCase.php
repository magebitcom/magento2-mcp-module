<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Contract;

use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Model\Tool\Contract\ToolContractChecker;
use PHPUnit\Framework\TestCase;

/**
 * Contract checks for a module's bulk tools: permission declared (C5), key field in the
 * item schema, sane caps, and a full batch that fits the request body limit, measured as the
 * complete JSON-RPC `tools/call` request a client sends (envelope, wire name and `dry_run` included).
 */
abstract class BulkToolContractTestCase extends TestCase
{
    /**
     * One entry per bulk tool: the tool and one realistic, largest-case item.
     *
     * @return array<int, array{tool: BulkToolInterface, sample: array<string, mixed>}>
     */
    abstract protected function bulkToolSamples(): array;

    /**
     * Every ACL resource id of the merged acl.xml tree the tools may name.
     *
     * @return array<int, string>
     */
    abstract protected function declaredAclIds(): array;

    public function testBulkToolsMeetContract(): void
    {
        $samples = $this->bulkToolSamples();
        if ($samples === []) {
            self::markTestSkipped('bulkToolSamples() is empty: list the module\'s bulk tools to run the contract.');
        }

        $checker = new ToolContractChecker();
        $declared = $this->declaredAclIds();
        $violations = [];
        foreach ($samples as $entry) {
            foreach ($checker->bulkViolations($entry['tool'], $declared, $entry['sample']) as $violation) {
                $violations[] = $violation;
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * Resource ids declared in a module's etc/acl.xml.
     *
     * @param string $aclXml
     * @return array<int, string>
     */
    protected function aclIdsFromFile(string $aclXml): array
    {
        $xml = simplexml_load_file($aclXml);
        self::assertNotFalse($xml);
        $nodes = $xml->xpath('//resource/@id');
        self::assertIsArray($nodes);

        $ids = [];
        foreach ($nodes as $node) {
            $ids[] = (string) $node;
        }
        return $ids;
    }
}
