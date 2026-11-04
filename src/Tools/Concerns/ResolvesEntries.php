<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

trait ResolvesEntries
{
    protected function findExposedEntry(string $id, UserContract $user, ?string $site = null): EntryContract
    {
        $entry = Entry::find($id);

        if (! $entry || ! in_array($entry->collection()->handle(), $this->exposedHandles('collections'), true)) {
            throw new ToolException("entry '{$id}' not found");
        }

        if ($site !== null && $site !== $entry->locale()) {
            $siblings = $entry->collection()->sites()
                ->map(fn (string $handle) => $entry->in($handle))
                ->filter()
                ->map(fn (EntryContract $localization) => $localization->locale().' => '.$localization->id())
                ->values()
                ->all();

            throw new ToolException(sprintf(
                "entry '%s' belongs to site '%s', not '%s' — pass the matching localization id instead (or omit site). Localizations: %s",
                $id,
                $entry->locale(),
                $site,
                implode('; ', $siblings),
            ));
        }

        $this->ensureSiteAccess($user, $entry->locale());

        return $entry;
    }
}
