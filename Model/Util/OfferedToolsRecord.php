<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Util;

/**
 * JSON form of the "offered at grant" record kept on auth codes and tokens (`offered_tools_json`).
 *
 * Unlike a granted tool list, an empty record is still a record: `[]` is stored as `[]`, and only "no record"
 * (rows written before the record existed) is NULL. Grant drift relies on that difference.
 */
class OfferedToolsRecord
{
    /**
     * @param array<int, string>|null $tools
     * @return string|null
     */
    public static function encode(?array $tools): ?string
    {
        if ($tools === null) {
            return null;
        }
        $encoded = json_encode(self::normalise($tools), JSON_UNESCAPED_SLASHES);
        return $encoded === false ? null : $encoded;
    }

    /**
     * @param string|null $raw
     * @return array<int, string>|null Null when there is no record or it cannot be read.
     */
    public static function decode(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? self::normalise($decoded) : null;
    }

    /**
     * @param array<mixed> $tools
     * @return array<int, string>
     */
    private static function normalise(array $tools): array
    {
        $names = [];
        foreach ($tools as $name) {
            if (is_string($name) && $name !== '') {
                $names[$name] = $name;
            }
        }
        return array_values($names);
    }
}
