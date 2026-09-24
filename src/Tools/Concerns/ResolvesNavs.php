<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Illuminate\Support\Collection;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Facades\Site;

trait ResolvesNavs
{
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
}
