<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Site;

trait ResolvesSites
{
    /**
     * The requested site (default: Site::default()), validated against the
     * configured sites, with 'access {site} site' enforced for non-default
     * sites on multisite installs (spec §6). Pass $validSites to limit the
     * check to a resource's own configured sites (taxonomies, global sets);
     * when omitted, every configured site is valid (entries).
     *
     * Message semantics: "not found" is reserved for sites that do not exist
     * globally — a site statamic_overview advertises but $validSites rejects
     * is "not available for this resource", and when the DEFAULT site was
     * filled in and rejected, the error says so and asks for an explicit site.
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
