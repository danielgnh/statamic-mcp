<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Collection;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Support\Arr;

trait ResolvesNavs
{
    /**
     * Call after ensureExposed(): it checked the handle against Nav::all(),
     * but the index and the item fetch can drift (a deploy deleting the
     * navigation under a warm Stache), so a miss gets the same not-found shape.
     */
    protected function findNav(string $handle): NavContract
    {
        return Nav::find($handle)
            ?? throw new ToolException($this->notFoundMessage('navigation', $handle, $this->exposedHandles('navigations')));
    }

    /**
     * A navigation exists in the sites it has a tree for. One without any
     * tree yet (defined in YAML, or made in code) counts as a default-site
     * navigation, the site the CP creates its first tree in.
     *
     * @return Collection<int, string>
     */
    protected function navSites(NavContract $nav): Collection
    {
        $sites = $nav->sites()->values();

        return $sites->isEmpty() ? collect([Site::default()->handle()]) : $sites;
    }

    /**
     * @return list<string>
     */
    protected function linkableCollections(NavContract $nav): array
    {
        return $nav->collections()->map->handle()
            ->intersect($this->exposedHandles('collections'))
            ->values()
            ->all();
    }

    /**
     * The stored branches, plus a 'resolved' block on entry branches: the
     * entry's own title, url, and status, for reading the menu. It is never
     * stored — navigations_update ignores it, so the tree round-trips as is.
     *
     * @param  array<int, array<string, mixed>>  $branches
     * @param  list<string>|null  $exposed
     * @return list<array<string, mixed>>
     */
    protected function presentBranches(array $branches, ?array $exposed = null): array
    {
        $exposed ??= $this->exposedHandles('collections');

        return array_values(array_map(function (array $branch) use ($exposed): array {
            $presented = Arr::except($branch, 'children');

            if (is_string($id = data_get($branch, 'entry'))) {
                $entry = Entry::find($id);

                $presented['resolved'] = $entry && in_array($entry->collectionHandle(), $exposed, true)
                    ? ['title' => $entry->value('title'), 'url' => $entry->url(), 'status' => $entry->status()]
                    : ['status' => 'missing'];
            }

            if (filled($children = data_get($branch, 'children'))) {
                $presented['children'] = $this->presentBranches($children, $exposed);
            }

            return $presented;
        }, $branches));
    }
}
