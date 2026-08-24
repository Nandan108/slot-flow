<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Solvers\Concerns;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;

/**
 * Shared helpers for resolving `{param}` placeholders inside flow inputs.
 *
 * A slot pattern may name an execute-time parameter instead of a literal value —
 * `['loc' => '{location}']`, `'sup.{own}.{state}'` — and the solver substitutes it from
 * `$context['params']` when the flow runs. One definition therefore serves every value of
 * that dimension: the same `write_in` flow receives into any warehouse, and a two-location
 * transfer names both ends as parameters (`{from}` → `{to}`), which no compile-time pattern
 * could express.
 */
trait ResolvesFlowParameters
{
    /**
     * Resolve `{param}` placeholders inside slot-pattern inputs.
     *
     * @param array<string, string>                      $params
     * @param string|array<int|string, string|null>|null $pattern
     */
    protected function resolvePatternParameters(string | array | null $pattern, array $params): string | array | null
    {
        if (null === $pattern) {
            return null;
        }

        if (is_string($pattern)) {
            return $this->resolvePatternValue($pattern, $params, null);
        }

        $resolved = [];
        foreach ($pattern as $key => $value) {
            $resolved[$key] = is_string($value)
                ? $this->resolvePatternValue($value, $params, $key)
                : $value;
        }

        return $resolved;
    }

    /**
     * Resolve one slot-pattern value, and refuse a placeholder no parameter answered.
     *
     * Left alone, an unresolved `{location}` travels on as a literal dimension value and the
     * codec rejects it with "Value '{location}' is not valid for dimension 'loc'" — which reads
     * as a schema problem and sends the reader looking for a missing dimension value. The cause
     * is a caller that did not pass the parameter, so say that instead, and name it.
     *
     * @param array<string, string> $params
     */
    protected function resolvePatternValue(string $value, array $params, int | string | null $dimension): string
    {
        $resolved = $this->resolveStringParameter($value, $params);

        if (1 === preg_match('/\{([-a-z_]*)\}/i', $resolved, $matches)) {
            $name = $matches[1];
            // Supplied-but-empty and never-supplied both leave the placeholder standing, and they
            // are different mistakes: one is a caller passing a blank, the other a caller passing
            // nothing. Saying which halves the search.
            $cause = array_key_exists($name, $params)
                ? 'resolved to an empty value'
                : ([] === $params
                    ? 'was not supplied (no execute params were given)'
                    : 'was not supplied — given: '.implode(', ', array_keys($params)));

            throw new SlotFlowInvalidArgumentException(
                sprintf(
                    'Slot pattern %sneeds parameter "%s", which %s.',
                    null === $dimension ? '' : sprintf('for dimension \'%s\' ', $dimension),
                    $name,
                    $cause,
                ),
                ['parameter' => $name, 'dimension' => $dimension, 'pattern' => $value],
            );
        }

        return $resolved;
    }

    /**
     * Resolve a single parameterized string using the provided scalar params.
     *
     * Unmatched placeholders are returned as-is; callers that require resolution use
     * {@see resolvePatternValue()}. Edge labels deliberately stay tolerant — an unresolved
     * label simply matches no edge.
     *
     * @param array<string, string> $params
     */
    protected function resolveStringParameter(string $value, array $params): string
    {
        if (!$params) {
            return $value;
        }

        if (1 === preg_match('/^\{([-a-z_]*)\}$/i', $value, $matches)) {
            return $params[$matches[1]] ?? $value ?: $value;
        }

        $resolved = preg_replace_callback(
            '/\{([-a-z_]*)\}/i',
            static function (array $matches) use ($params) {
                $resolved = $params[$matches[1]] ?? null;

                return $resolved ?? "\{$matches[0]\}" ?: "\{$matches[0]\}";
            },
            $value,
        );

        return $resolved ?? $value ?: $value;
    }
}
