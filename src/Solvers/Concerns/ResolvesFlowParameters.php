<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Solvers\Concerns;

/**
 * Shared helpers for resolving `{param}` placeholders inside flow inputs.
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
            return $this->resolveStringParameter($pattern, $params);
        }

        $resolved = [];
        foreach ($pattern as $key => $value) {
            $resolved[$key] = is_string($value)
                ? $this->resolveStringParameter($value, $params)
                : $value;
        }

        return $resolved;
    }

    /**
     * Resolve a single parameterized string using the provided scalar params.
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
