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
 * Sorts + filters an array of field resolvers before a tool dispatches them.
 *
 * Tool-module agnostic — operates on the
 * {@see \Magebit\Mcp\Api\FieldResolverInterface} marker so any tool module
 * (orders, CMS, catalog, …) can share this pipeline.
 *
 * The pipeline deliberately does NOT call `resolve()` itself — the per-entity
 * `resolve()` signatures differ (OrderInterface vs InvoiceInterface vs …), so
 * typing against a single method would force ugly casts. Instead the tool
 * calls `plan()` to get an ordered list of resolvers that should run, then
 * invokes each one with its correctly-typed entity.
 *
 * Caller-driven opt-in / opt-out via the same two tool args every read tool
 * exposes:
 *   - `fields: string[]`  — whitelist; when non-empty, only listed keys run.
 *   - `exclude: string[]` — blacklist; always subtracted from the active set.
 *
 * Duplicate resolver keys are a configuration error (two modules fighting for
 * the same slice). We fail loud at pipeline boot rather than silently picking
 * a winner.
 */
class ResolverPipeline
{
    /**
     * Return the resolvers that should run, in execution order.
     *
     * Applies the `fields` (whitelist) and `exclude` (blacklist) selectors
     * from the tool's arguments.
     *
     * @template T of FieldResolverInterface
     * @param array $resolvers
     * @param array $args
     * @phpstan-param array<int, T> $resolvers
     * @phpstan-param array<string, mixed> $args
     * @return array<int, T>
     * @throws InvalidArgumentException on duplicate keys.
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
     * Copy + sort so we don't mutate the DI-supplied array on repeat calls.
     *
     * @template T of FieldResolverInterface
     * @param array $resolvers
     * @phpstan-param array<int, T> $resolvers
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
     * Pull an optional `string[]` out of a mixed args array.
     *
     * Tolerates scalar misuse (`fields: "totals"` becomes `["totals"]`).
     *
     * @param array $args
     * @phpstan-param array<string, mixed> $args
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
