<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Fields\Blueprint;

trait NormalizesEntryInput
{
    protected function parseEntryDate(string $date): Carbon
    {
        try {
            return Carbon::parse($date, config('app.timezone'));
        } catch (InvalidArgumentException) {
            throw new ToolException(sprintf("could not parse date '%s' — use e.g. 2026-07-09 or 2026-07-09T15:30:00+02:00", $date));
        }
    }

    protected function rejectPublishedArgument(Request $request, string $tool): void
    {
        if ($request->get('published') !== null) {
            throw new ToolException(sprintf(
                'published is not accepted by %s — publish state changes only through entries_publish and entries_unpublish',
                $tool,
            ));
        }
    }

    /**
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

    protected function rejectSlugWithoutSlugField(?string $slug, Blueprint $blueprint, CollectionContract $collection): void
    {
        if ($slug !== null && ! $blueprint->hasField('slug')) {
            throw new ToolException("collection '{$collection->handle()}' has slugs turned off, so its entries have none — omit slug");
        }
    }
}
