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
    protected function findNav(string $handle): NavContract
    {
        return Nav::find($handle)
            ?? throw new ToolException($this->notFoundMessage('navigation', $handle, $this->exposedHandles('navigations')));
    }

    /**
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
