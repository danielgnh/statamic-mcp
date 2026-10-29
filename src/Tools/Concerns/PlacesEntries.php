<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Closure;
use Danielgnh\StatamicMcp\Tools\ToolException;
use Danielgnh\StatamicMcp\Tools\TreeChangedException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Routing\UrlBuilder;
use Statamic\Contracts\Structures\CollectionTree;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
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

    protected function parentInLockedTree(CollectionTree $tree, ?string $parent, ?string $moving = null, bool $limitDepth = true): ?string
    {
        $page = $parent === null ? null : $tree->find($parent);

        if ($parent !== null && ! $page) {
            throw TreeChangedException::in($tree, "parent '{$parent}' is no longer in it");
        }

        if ($page?->isRoot()) {
            throw TreeChangedException::in($tree, "parent '{$parent}' is the root page now, which takes no children");
        }

        if ($limitDepth) {
            try {
                $this->resolveParent($parent ?? '', $tree, $moving === null ? null : $tree->find($moving));
            } catch (ToolException $refused) {
                throw TreeChangedException::in($tree, $refused->getMessage());
            }
        }

        return $parent;
    }

    protected function ensureUniqueUriInLockedTree(CollectionTree $tree, string $id): CollectionTree
    {
        if (! $tree->collection()->route($tree->locale())) {
            return $tree;
        }

        $probe = (clone $tree)->flushCache()->disableUriCache();
        $page = $probe->find($id);
        $parent = $page->parent();
        $level = $parent && ! $parent->isRoot() ? $parent->pages()->all() : $probe->pages()->all();
        $uri = $page->uri();

        $taken = $level->first(fn (Page $other) => $other->id() !== $id && $other->uri() === $uri);

        Blink::store('structure-uris')->forget($id);

        if ($taken) {
            throw TreeChangedException::in($tree, "URL '{$uri}' already belongs to entry '{$taken->id()}' in collection '{$tree->collection()->handle()}' — pick another slug or parent");
        }

        return $tree;
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
     * created outside the CP until the tree is next saved. Storing the ones
     * the tree lists first keeps them in their place, and lets one of them
     * move or be a parent. Every stored branch stays: the tree as it reads
     * leaves out entries this request's Stache doesn't know, such as the
     * ones another call just created and placed.
     */
    protected function materializeTree(CollectionTree $tree): CollectionTree
    {
        return $tree->tree($this->withUnstoredEntries(data_get($tree->fileData(), 'tree', []), $tree->tree()));
    }

    /**
     * The stored branches as they are, then the entries the tree lists but
     * doesn't store yet, at the top level where it lists them.
     *
     * @param  array<int, array<string, mixed>>  $stored
     * @param  array<int, array<string, mixed>>  $listed
     * @return list<array<string, mixed>>
     */
    private function withUnstoredEntries(array $stored, array $listed): array
    {
        $storedIds = [];

        array_walk_recursive($stored, function (mixed $value, int|string $key) use (&$storedIds) {
            if ($key === 'entry') {
                $storedIds[] = $value;
            }
        });

        return [
            ...array_values($stored),
            ...array_values(array_filter($listed, fn (array $branch) => ! in_array(data_get($branch, 'entry'), $storedIds, true))),
        ];
    }

    /**
     * CP parity (EntriesController::validateUniqueUri): an entry can't take a
     * URL another entry of its site already has, in any collection. The slug
     * alone may repeat, as it does under different parents in a tree; a
     * blueprint that wants unique slugs sets unique_entry_value on it.
     */
    protected function ensureUniqueUri(EntryContract $entry, ?CollectionTree $tree, ?string $parent): void
    {
        if (! $uri = $this->futureUri($entry, $tree, $parent)) {
            return;
        }

        $existing = Entry::findByUri($uri, $entry->locale());

        if ($existing && $existing->id() !== $entry->id()) {
            throw new ToolException(sprintf(
                "URL '%s' already belongs to entry '%s' in collection '%s' — pick another slug%s",
                $uri,
                $existing->id(),
                $existing->collectionHandle(),
                $tree ? ' or parent' : '',
            ));
        }
    }

    /**
     * The URL the entry gets under this parent, built the way the CP's
     * entryUri() builds it. Null when the collection has no route.
     */
    private function futureUri(EntryContract $entry, ?CollectionTree $tree, ?string $parent): ?string
    {
        if (! $route = $entry->route()) {
            return null;
        }

        if (! $tree) {
            return app(UrlBuilder::class)->content($entry)->merge(['id' => $entry->id() ?? Stache::generateId()])->build($route);
        }

        $page = $parent === null ? null : $tree->find($parent);

        if ($page?->isRoot()) {
            $page = null;
        }

        return app(UrlBuilder::class)->content($entry)->merge([
            'parent_uri' => $page?->uri(),
            'slug' => $entry->slug(),
            'depth' => $page ? $page->depth() + 1 : 1,
            'is_root' => false,
        ])->build($route);
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
            return $this->saveTreeChangeInLock($collection, $site, $change);
        } catch (LockTimeoutException) {
            throw new ToolException("another call is still saving the tree of collection '{$collection->handle()}' (site '{$site}') — the entry was not placed in it, and anything else this call changed was saved. Place it with entries_update and parent.");
        }
    }

    protected function placeInTree(CollectionContract $collection, string $site, EntryContract $entry, ?string $parent, bool $limitDepth = true): ?string
    {
        try {
            $unplaced = $this->saveTreeChangeInLock($collection, $site, fn (CollectionTree $tree) => $this->ensureUniqueUriInLockedTree(
                $tree->remove($entry)->appendTo($this->parentInLockedTree($tree, $parent, limitDepth: $limitDepth), $entry),
                $entry->id(),
            ))
                ? null
                : 'cancelled by a listener on this site';
        } catch (LockTimeoutException) {
            $unplaced = "another call was still saving the tree of collection '{$collection->handle()}' (site '{$site}')";
        } catch (TreeChangedException $changed) {
            $unplaced = $changed->getMessage();
        }

        return match (true) {
            $unplaced === null => null,
            $parent === null => "tree placement — {$unplaced}; the stored tree does not list the entry yet, and Statamic lists it at the end of the top level",
            default => "tree placement — {$unplaced}; Statamic lists the entry at the end of the top level, not under '{$parent}' — move it with entries_update and parent, which needs 'reorder {$collection->handle()} entries'",
        };
    }

    /**
     * @param  Closure(CollectionTree): CollectionTree  $change
     */
    private function saveTreeChangeInLock(CollectionContract $collection, string $site, Closure $change): bool
    {
        return Cache::lock("statamic-mcp-tree:{$collection->handle()}:{$site}", 10)->block(5, function () use ($collection, $site, $change) {
            $tree = $this->placementTree($collection, $site) ?? throw $this->cannotNest($collection);

            return $change($this->materializeTree(clone $tree))->save();
        });
    }
}
