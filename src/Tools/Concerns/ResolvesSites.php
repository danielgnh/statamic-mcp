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
        if (! $this->canAccessSite($user, $site)) {
            $this->ensurePermission($user, "access {$site} site");
        }
    }

    /**
     * The single source of truth for site access — statamic_overview's
     * advertised can_access flag and the enforcement above must never drift
     * apart. Single-site installs and the default site are never gated.
     */
    protected function canAccessSite(UserContract $user, string $site): bool
    {
        if (! Site::multiEnabled() || $site === Site::default()->handle()) {
            return true;
        }

        return $this->can($user, "access {$site} site");
    }
}
