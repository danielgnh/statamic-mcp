<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
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
        return $collection->hasStructure() && ! $collection->orderable()
            ? $collection->structure()->in($site)
            : null;
    }

    protected function cannotNest(CollectionContract $collection): ToolException
    {
        return new ToolException($collection->hasStructure()
            ? "collection '{$collection->handle()}' is a flat, orderable list (max_depth 1) — omit parent"
            : "collection '{$collection->handle()}' has no tree — omit parent; only structured collections nest entries");
    }

    /**
     * The page to nest under, or null for the top level, which is also where
     * the CP places an entry whose parent is the root page. The tree of one
     * collection and site lists all of its entries, the ones missing from the
     * stored tree included.
     */
    protected function resolveParent(string $parent, CollectionTree $tree): ?Page
    {
        $handle = $tree->collection()->handle();
        $page = $tree->find($parent);

        if (! $page) {
            throw new ToolException("parent '{$parent}' not found in collection '{$handle}' (site '{$tree->locale()}') — pass the id of one of its entries in that site");
        }

        if ($page->isRoot()) {
            return null;
        }

        $maxDepth = $tree->structure()->maxDepth();

        if ($maxDepth && $page->depth() >= $maxDepth) {
            throw new ToolException("parent '{$parent}' is at depth {$page->depth()} and collection '{$handle}' allows {$maxDepth} levels (max_depth) — pick a parent higher up, or omit parent for the top level");
        }

        return $page;
    }

    /**
     * appendTo() edits the stored tree, which lacks the entries created
     * outside the CP until the tree is next saved. Storing the tree the way
     * it reads first keeps them in their place and lets one be a parent.
     */
    protected function materializeTree(CollectionTree $tree): CollectionTree
    {
        return $tree->tree($tree->tree());
    }
}
