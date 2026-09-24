<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Closure;
use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Structures\CollectionTree;
use Statamic\Structures\Page;

trait PlacesEntries
{
    /**
     * CP parity (EntriesController@store): a structured collection places its
     * entries in its tree, except an orderable one (max_depth 1), whose tree
     * lists a new entry when it is next read and never nests one.
     */
    protected function placementTree(CollectionContract $collection, string $site): ?CollectionTree
    {
        if (! $collection->hasStructure() || $collection->orderable()) {
            return null;
        }

        // A tree read earlier in the request lacks the entries saved since,
        // so flush it first, as Entry::save() does for orderable collections.
        return $collection->structure()->flushCache($site)->in($site);
    }

    protected function cannotNest(CollectionContract $collection): ToolException
    {
        return new ToolException($collection->hasStructure()
            ? "collection '{$collection->handle()}' is a flat, orderable list (max_depth 1) — omit parent"
            : "collection '{$collection->handle()}' has no tree — omit parent; only structured collections nest entries");
    }

    /**
     * The page an entry goes under, or null for the top level: '' asks for
     * it, and the root page stands for it, as in the CP. When an existing
     * entry moves, $moving is its own page: it can't go under itself or its
     * descendants, and its descendants count toward max_depth. The tree of
     * one collection and site lists all of its entries, the ones missing from
     * the stored tree included.
     */
    protected function resolveParent(string $parent, CollectionTree $tree, ?Page $moving = null): ?Page
    {
        if ($parent === '') {
            return null;
        }

        $handle = $tree->collection()->handle();
        $page = $tree->find($parent);

        if (! $page) {
            throw new ToolException("parent '{$parent}' not found in collection '{$handle}' (site '{$tree->locale()}') — pass the id of one of its entries in that site");
        }

        if ($page->isRoot()) {
            return null;
        }

        $height = 0;

        if ($moving) {
            $this->ensureMovableUnder($page, $moving, $handle);

            $height = $moving->flattenedPages()->map->depth()->push($moving->depth())->max() - $moving->depth();
        }

        $maxDepth = $tree->structure()->maxDepth();
        $depth = $page->depth() + 1 + $height;

        if ($maxDepth && $depth > $maxDepth) {
            throw new ToolException(sprintf(
                "parent '%s' is at depth %d, so %s would be at depth %d, past the %d levels collection '%s' allows (max_depth) — pick a parent higher up, or the top level",
                $parent,
                $page->depth(),
                $height > 0 ? "the entry's deepest descendant" : 'the entry',
                $depth,
                $maxDepth,
                $handle,
            ));
        }

        return $page;
    }

    private function ensureMovableUnder(Page $parent, Page $moving, string $handle): void
    {
        if ($parent->id() === $moving->id()) {
            throw new ToolException("entry '{$moving->id()}' can't be its own parent — pass another entry's id, or \"\" for the top level");
        }

        if ($moving->flattenedPages()->contains(fn (Page $descendant) => $descendant->id() === $parent->id())) {
            throw new ToolException("parent '{$parent->id()}' is inside entry '{$moving->id()}' — an entry can't move under one of its own descendants");
        }

        // The first top-level page is the root: moving it would make the
        // next one the site's root page instead.
        if ($moving->isRoot()) {
            throw new ToolException("entry '{$moving->id()}' is the root page of collection '{$handle}' — the root page can't move under another page");
        }
    }

    /**
     * appendTo() and move() edit the stored tree, which lacks the entries
     * created outside the CP until the tree is next saved. Storing the tree
     * the way it reads first keeps them in their place, and lets one of them
     * move or be a parent.
     */
    protected function materializeTree(CollectionTree $tree): CollectionTree
    {
        return $tree->tree($tree->tree());
    }

    /**
     * A tree is saved whole, so two calls editing one at the same time would
     * each drop the other's change. Every edit takes the tree's lock and
     * reads the tree afresh inside it.
     *
     * @param  Closure(CollectionTree): CollectionTree  $change
     */
    protected function saveTreeChange(CollectionContract $collection, string $site, Closure $change): bool
    {
        try {
            return Cache::lock("statamic-mcp-tree:{$collection->handle()}:{$site}", 10)->block(5, function () use ($collection, $site, $change) {
                $tree = $this->placementTree($collection, $site) ?? throw $this->cannotNest($collection);

                return $change($this->materializeTree($tree))->save();
            });
        } catch (LockTimeoutException) {
            throw new ToolException("another call is still saving the tree of collection '{$collection->handle()}' (site '{$site}') — the entry was not placed in it, and anything else this call changed was saved. Place it with entries_update and parent.");
        }
    }
}
