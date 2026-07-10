<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;

/**
 * Shared patch hygiene for the update tools (entries, terms, globals):
 * the strict no-op comparison and the preview-object round-trip guard.
 */
trait ComparesPatchData
{
    /**
     * Recursively sort associative keys (list order is content, key order is
     * not) so no-op detection can compare strictly: loose == would juggle
     * null == '' and '1' == 1 into false no-ops, silently dropping writes.
     */
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
     * The one raw-path artifact an agent can accidentally round-trip: the
     * truncated {__preview, truncated, note} shape the get tools substitute
     * for long Bard/markdown values (T11 quality review).
     *
     * @param  string  $getTool  the tool to point the agent back to, e.g. 'entries_get'
     */
    protected function rejectPreviewObjects(array $data, string $getTool): void
    {
        foreach ($data as $handle => $value) {
            if (is_array($value) && array_key_exists('__preview', $value)) {
                throw new ToolException(sprintf(
                    'field %s is a truncated preview object from %s, not raw content — fetch the raw value first (%s with fields: ["%s"]) and send that back',
                    $handle,
                    $getTool,
                    $getTool,
                    $handle,
                ));
            }
        }
    }
}
