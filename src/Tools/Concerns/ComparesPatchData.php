<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;

trait ComparesPatchData
{
    protected function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($this->normalize(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function rejectPreviewObjects(array $data, string $getTool, bool $supportsFields = true): void
    {
        foreach ($data as $handle => $value) {
            if (! is_array($value) || ! array_key_exists('__preview', $value)) {
                continue;
            }

            if (array_key_exists('sets', $value)) {
                throw new ToolException(sprintf(
                    'field %s is the index of its sets from %s, not raw content — fetch one set with fields: ["%s.<id>"] and merge it back as {"%s": {"<id>": {...}}}, or fetch the whole field with fields: ["%s"]',
                    $handle,
                    $getTool,
                    $handle,
                    $handle,
                    $handle,
                ));
            }

            throw new ToolException($supportsFields
                ? sprintf(
                    'field %s is a truncated preview object from %s, not raw content — fetch the raw value first (%s with fields: ["%s"]) and send that back',
                    $handle,
                    $getTool,
                    $getTool,
                    $handle,
                )
                : sprintf(
                    'field %s is a truncated preview object, not raw content — fetch the current raw value from %s and send that back',
                    $handle,
                    $getTool,
                ));
        }
    }
}
