<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Validation\ValidationException;
use Statamic\Fields\Blueprint;

trait ValidatesBlueprintData
{
    /**
     * Statamic silently stores unknown keys (typos become content) — reject
     * them instead, naming valid handles plus a Levenshtein "did you mean"
     * (spec §8). 'slug' is v6's auto-injected blueprint field; it is a
     * dedicated tool parameter, never a data key, so it's excluded here.
     */
    protected function rejectUnknownKeys(Blueprint $blueprint, array $data): void
    {
        $handles = $blueprint->fields()->all()->keys()->reject(fn ($handle) => $handle === 'slug')->values()->all();
        $unknown = array_values(array_diff(array_keys($data), $handles));

        if ($unknown === []) {
            return;
        }

        $suggestions = [];

        foreach ($unknown as $key) {
            $best = null;
            $bestDistance = PHP_INT_MAX;

            foreach ($handles as $handle) {
                $distance = levenshtein((string) $key, $handle);

                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = $handle;
                }
            }

            if ($best !== null && $bestDistance <= 3) {
                $suggestions[] = sprintf("did you mean '%s' instead of '%s'?", $best, $key);
            }
        }

        sort($handles);

        $message = sprintf(
            'unknown field%s %s — valid handles: %s',
            count($unknown) === 1 ? '' : 's',
            implode(', ', $unknown),
            implode(', ', $handles),
        );

        if ($suggestions !== []) {
            $message .= ' — '.implode(' ', $suggestions);
        }

        throw new ToolException($message);
    }

    /**
     * The CP's own validation path (spec §8). Callers pass MERGED values
     * (existing + patch) so partial updates never false-fail required fields.
     * Field-level messages reach the model for one-round-trip self-correction.
     */
    protected function validateAgainstBlueprint(Blueprint $blueprint, array $merged): void
    {
        try {
            $blueprint->fields()->addValues($merged)->validator()->validate();
        } catch (ValidationException $e) {
            throw new ToolException('validation failed: '.json_encode(
                $e->errors(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        }
    }
}
