<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

trait ResolvesEntries
{
    /**
     * Missing and exists-but-unexposed are indistinguishable by design
     * (spec §4 / §6 layer 2).
     */
    protected function findExposedEntry(string $id): EntryContract
    {
        $entry = Entry::find($id);

        if (! $entry || ! in_array($entry->collection()->handle(), $this->exposedHandles('collections'), true)) {
            throw new ToolException("entry '{$id}' not found");
        }

        return $entry;
    }
}
