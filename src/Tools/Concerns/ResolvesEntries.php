<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

trait ResolvesEntries
{
    /**
     * Exposure, site-match, and site-access checks in one place — every
     * id-based entry lookup must go through here so none of them can become
     * a site-permission bypass. Requires ResolvesSites (ensureSiteAccess()).
     *
     * Missing and exists-but-unexposed are indistinguishable by design
     * (spec §4 / §6 layer 2). A supplied $site must match the entry's own
     * site — selecting a localization by site is the collection + slug
     * lookup's job.
     */
    protected function findExposedEntry(string $id, UserContract $user, ?string $site = null): EntryContract
    {
        $entry = Entry::find($id);

        if (! $entry || ! in_array($entry->collection()->handle(), $this->exposedHandles('collections'), true)) {
            throw new ToolException("entry '{$id}' not found");
        }

        if ($site !== null && $site !== $entry->locale()) {
            throw new ToolException(sprintf(
                "entry '%s' belongs to site '%s' — omit site or pass '%s'",
                $id,
                $entry->locale(),
                $entry->locale(),
            ));
        }

        $this->ensureSiteAccess($user, $entry->locale());

        return $entry;
    }
}
