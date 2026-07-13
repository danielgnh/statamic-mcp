<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

trait NormalizesEntryInput
{
    /**
     * Carbon throws InvalidFormatException for malformed input and other
     * \InvalidArgumentException subclasses for out-of-range values — catch the
     * shared root so both surface as one clean tool error.
     */
    protected function parseEntryDate(string $date): Carbon
    {
        try {
            return Carbon::parse($date);
        } catch (InvalidArgumentException) {
            throw new ToolException(sprintf("could not parse date '%s' — use e.g. 2026-07-09 or 2026-07-09 15:30", $date));
        }
    }

    /**
     * slug and (on dated collections) date are top-level tool parameters: v6
     * auto-injects them as blueprint fields but entries never store them in
     * data, so the ambiguous data-key spelling gets a targeted error instead of
     * the generic unknown-field one.
     *
     * @param  array<string, mixed>  $data
     */
    protected function rejectAmbiguousDataKeys(array $data, bool $dated): void
    {
        if ($dated && array_key_exists('date', $data)) {
            throw new ToolException('pass date as a top-level parameter, not inside data');
        }

        if (array_key_exists('slug', $data)) {
            throw new ToolException('pass slug as a top-level parameter, not inside data');
        }
    }
}
