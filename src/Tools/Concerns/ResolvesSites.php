<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Laravel\Mcp\Request;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Site;

trait ResolvesSites
{
    /**
     * The requested site (default: Site::default()), validated against the
     * configured sites, with 'access {site} site' enforced for non-default
     * sites on multisite installs (spec §6).
     */
    protected function resolveSite(Request $request, UserContract $user): string
    {
        $site = $request->get('site') ?? Site::default()->handle();

        $handles = Site::all()->map->handle()->values()->all();

        if (! in_array($site, $handles, true)) {
            sort($handles);

            throw new ToolException(sprintf(
                "site '%s' not found — available: %s",
                $site,
                implode(', ', $handles),
            ));
        }

        $this->ensureSiteAccess($user, $site);

        return $site;
    }

    /**
     * The 'access {site} site' permission only exists on multisite installs
     * (verified facts §3) — never check it on single-site.
     */
    protected function ensureSiteAccess(UserContract $user, string $site): void
    {
        if (! Site::multiEnabled() || $site === Site::default()->handle()) {
            return;
        }

        $this->ensurePermission($user, "access {$site} site");
    }
}
