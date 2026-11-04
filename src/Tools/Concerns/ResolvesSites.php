<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Facades\Site;

trait ResolvesSites
{
    /**
     * @param  Collection<int, string>|null  $validSites
     */
    protected function resolveSite(Request $request, UserContract $user, ?Collection $validSites = null): string
    {
        $requested = $request->get('site');
        $site = $requested ?? Site::default()->handle();

        $configured = Site::all()->map->handle()->values()->all();
        $handles = $validSites?->values()->all() ?? $configured;

        if (! in_array($site, $handles, true)) {
            sort($handles);
            $available = implode(', ', $handles);

            throw new ToolException(match (true) {
                ! in_array($site, $configured, true) => sprintf(
                    "site '%s' not found — available: %s", $site, $available,
                ),
                $requested === null => sprintf(
                    "this resource is not available in the default site '%s' — pass site explicitly; available: %s", $site, $available,
                ),
                default => sprintf(
                    "site '%s' is not available for this resource — available: %s", $site, $available,
                ),
            });
        }

        $this->ensureSiteAccess($user, $site);

        return $site;
    }

    protected function ensureCollectionInSite(CollectionContract $collection, string $site): void
    {
        if ($collection->sites()->contains($site)) {
            return;
        }

        throw new ToolException(sprintf(
            "collection '%s' is not available in site '%s' — available sites: %s",
            $collection->handle(),
            $site,
            $collection->sites()->sort()->implode(', '),
        ));
    }

    protected function ensureSiteAccess(UserContract $user, string $site): void
    {
        if (! $this->canAccessSite($user, $site)) {
            $this->ensurePermission($user, "access {$site} site");
        }
    }

    protected function canAccessSite(UserContract $user, string $site): bool
    {
        if (! Site::multiEnabled()) {
            return true;
        }

        return $this->can($user, "access {$site} site");
    }
}
