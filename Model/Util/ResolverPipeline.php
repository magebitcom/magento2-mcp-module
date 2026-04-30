<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Util;

use InvalidArgumentException;
use Magebit\Mcp\Api\FieldResolverInterface;

/**
 * Sorts + filters field resolvers before a tool dispatches them.
 *
 * Tool-module agnostic via the {@see \Magebit\Mcp\Api\FieldResolverInterface}
 * marker. The pipeline deliberately does NOT call `resolve()` itself — per-entity
 * signatures differ (OrderInterface vs InvoiceInterface …) so tools invoke each
 * planned resolver with its correctly-typed entity.
 *
 * Caller-driven selectors:
 *   - `fields: string[]`  — whitelist; when non-empty, only listed keys run.
 *   - `exclude: string[]` — blacklist; always subtracted.
 *
 * Duplicate keys fail loud — two modules fighting for the same slice is a
 * configuration error, not a silent "last wins".
 */
class ResolverPipeline
{
    /**
     * @template T of FieldResolverInterface
     * @param array<int, T> $resolvers
     * @param array<string, mixed> $args
     * @return array<int, T>
     * @throws InvalidArgumentException
     */
    public function plan(array $resolvers, array $args): array
    {
        $include = $this->stringList($args, 'fields');
        $exclude = $this->stringList($args, 'exclude');

        $ordered = $this->sortedCopy($resolvers);

        $plan = [];
        $seenKeys = [];
        foreach ($ordered as $resolver) {
            $key = $resolver->getKey();

            if (isset($seenKeys[$key])) {
                throw new InvalidArgumentException(sprintf(
                    'Duplicate MCP field resolver for key "%s" — two resolvers registered.',
                    $key
                ));
            }
            $seenKeys[$key] = true;

            if ($include !== [] && !in_array($key, $include, true)) {
                continue;
            }
            if (in_array($key, $exclude, true)) {
                continue;
            }

            $plan[] = $resolver;
        }

        return $plan;
    }

    /**
     * Copy + sort so the DI-supplied array isn't mutated across calls.
     *
     * @template T of FieldResolverInterface
     * @param array<int, T> $resolvers
     * @return array<int, T>
     */
    private function sortedCopy(array $resolvers): array
    {
        $sorted = array_values($resolvers);
        usort(
            $sorted,
            static fn(FieldResolverInterface $a, FieldResolverInterface $b): int
                => $a->getSortOrder() <=> $b->getSortOrder()
        );
        return $sorted;
    }

    /**
     * Tolerates scalar misuse: `fields: "totals"` becomes `["totals"]`.
     *
     * @param array<string, mixed> $args
     * @param string $key
     * @return array<int, string>
     */
    private function stringList(array $args, string $key): array
    {
        $raw = $args[$key] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            return [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }
}
