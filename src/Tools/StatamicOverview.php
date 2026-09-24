<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Support\GuidelineFiles;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesNavs;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection as SupportCollection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;

#[Name('statamic_overview')]
#[Description('Start here — zero parameters. Returns the sites; the collections, taxonomies, global sets, asset containers, and navigations (menus, with their max_depth, and the sites they have a tree in on multisite) exposed to MCP and visible to you; your capability flags per resource (can_create, can_edit, can_publish, can_upload, can_delete — delete flags appear only when deletes are enabled; collections whose blueprint has an author field add can_edit_other_authors, can_publish_other_authors, and can_delete_other_authors, which apply to entries you are not an author of); on dated collections, date_behavior (future/past: public, unlisted, or private — a published entry dated in the future is scheduled only where future is private); the acting user (id, email, roles, is_super — compare id with an entry\'s author); and the server block: read_only, deletes, and timezone (the zone a date without an offset is read in). When the site has written guidelines for agents (voice, tone, rules for all content), they come back in guidelines — follow them in everything you write.')]
#[IsReadOnly]
#[IsIdempotent]
class StatamicOverview extends Tool
{
    use ResolvesNavs;
    use ResolvesSites;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return []; // zero parameters
    }

    protected function execute(Request $request): Response
    {
        $user = $this->user($request);

        return $this->json([
            'sites' => $this->sites($user),
            'collections' => $this->collections($user),
            'taxonomies' => $this->taxonomies($user),
            'globals' => $this->globals($user),
            'asset_containers' => $this->assetContainers($user),
            'navigations' => $this->navigations($user),
            'user' => [
                'id' => $user->id(),
                'email' => $user->email(),
                'roles' => $user->roles()->map->handle()->values()->all(),
                'is_super' => $user->isSuper(),
            ],
            'server' => [
                'read_only' => ! $this->writesEnabled(),
                'deletes' => $this->deletesEnabled(),
                'timezone' => config('app.timezone'),
            ],
            ...array_filter(['guidelines' => app(GuidelineFiles::class)->site()]),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sites(UserContract $user): array
    {
        $multisite = Site::multiEnabled();

        return Site::all()->map(function ($site) use ($user, $multisite) {
            $shape = [
                'handle' => $site->handle(),
                'name' => $site->name(),
                'url' => $site->url(),
                'locale' => $site->locale(),
            ];

            // The same predicate ensureSiteAccess enforces, so the model is
            // never offered a site it will be denied on (single-site: no flag).
            if ($multisite) {
                $shape['can_access'] = $this->canAccessSite($user, $site->handle());
            }

            return $shape;
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collections(UserContract $user): array
    {
        $collections = Collection::all()->keyBy->handle();

        return $this->sortedExposed('collections')
            ->filter(fn (string $handle) => $this->can($user, "view {$handle} entries"))
            ->map(function (string $handle) use ($collections, $user) {
                $collection = $collections->get($handle);
                $blueprints = $collection->entryBlueprints();

                $resource = [
                    'handle' => $handle,
                    'title' => $collection->title(),
                    'dated' => $collection->dated(),
                    'revisions' => $collection->revisionsEnabled(),
                    'blueprints' => $blueprints->map->handle()->values()->all(),
                    'can_create' => $this->can($user, "create {$handle} entries"),
                    'can_edit' => $this->can($user, "edit {$handle} entries"),
                    'can_publish' => $this->can($user, "publish {$handle} entries"),
                ];

                if ($collection->dated()) {
                    $resource['date_behavior'] = [
                        'future' => $collection->futureDateBehavior(),
                        'past' => $collection->pastDateBehavior(),
                    ];
                }

                if ($this->deletesEnabled()) {
                    $resource['can_delete'] = $this->can($user, "delete {$handle} entries");
                }

                // The same author rule the entry tools enforce: with an author
                // field, entries you are not an author of need these instead.
                if ($blueprints->contains(fn ($blueprint) => $blueprint->hasField('author'))) {
                    $resource['can_edit_other_authors'] = $this->can($user, "edit other authors {$handle} entries");
                    $resource['can_publish_other_authors'] = $this->can($user, "publish other authors {$handle} entries");

                    if ($this->deletesEnabled()) {
                        $resource['can_delete_other_authors'] = $this->can($user, "delete other authors {$handle} entries");
                    }
                }

                return $resource;
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function taxonomies(UserContract $user): array
    {
        $taxonomies = Taxonomy::all()->keyBy->handle();

        return $this->sortedExposed('taxonomies')
            ->filter(fn (string $handle) => $this->can($user, "view {$handle} terms"))
            ->map(function (string $handle) use ($taxonomies, $user) {
                $taxonomy = $taxonomies->get($handle);

                $resource = [
                    'handle' => $handle,
                    'title' => $taxonomy->title(),
                    'blueprints' => $taxonomy->termBlueprints()->map->handle()->values()->all(),
                    // no can_publish: v6 has no 'publish {taxonomy} terms' permission — terms have no status
                    'can_create' => $this->can($user, "create {$handle} terms"),
                    'can_edit' => $this->can($user, "edit {$handle} terms"),
                ];

                if ($this->deletesEnabled()) {
                    $resource['can_delete'] = $this->can($user, "delete {$handle} terms");
                }

                return $resource;
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function globals(UserContract $user): array
    {
        $sets = GlobalSet::all()->keyBy->handle();

        return $this->sortedExposed('globals')
            // v6 has no 'view {global} globals' permission — the CP itself gates viewing on edit
            ->filter(fn (string $handle) => $this->can($user, "edit {$handle} globals"))
            ->map(fn (string $handle) => [
                'handle' => $handle,
                'title' => $sets->get($handle)->title(),
                'can_edit' => true, // the visibility filter above IS the edit-permission check
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function assetContainers(UserContract $user): array
    {
        $containers = AssetContainer::all()->keyBy->handle();

        return $this->sortedExposed('asset_containers')
            ->filter(fn (string $handle) => $this->can($user, "view {$handle} assets"))
            ->map(function (string $handle) use ($containers, $user) {
                $resource = [
                    'handle' => $handle,
                    'title' => $containers->get($handle)->title(),
                    // no allow_uploads flag: v6 has no per-container upload
                    // toggle — the upload permission is the whole gate
                    'can_upload' => $this->can($user, "upload {$handle} assets"),
                    'can_edit' => $this->can($user, "edit {$handle} assets"),
                ];

                if ($this->deletesEnabled()) {
                    $resource['can_delete'] = $this->can($user, "delete {$handle} assets");
                }

                return $resource;
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function navigations(UserContract $user): array
    {
        $navs = Nav::all()->keyBy->handle();

        return $this->sortedExposed('navigations')
            ->filter(fn (string $handle) => $this->can($user, "view {$handle} nav"))
            ->map(function (string $handle) use ($navs, $user) {
                $nav = $navs->get($handle);

                $resource = [
                    'handle' => $handle,
                    'title' => $nav->title(),
                    'max_depth' => $nav->maxDepth(),
                ];

                if (Site::multiEnabled()) {
                    $resource['sites'] = $this->navSites($nav)->all();
                }

                $resource['can_edit'] = $this->can($user, "edit {$handle} nav");

                return $resource;
            })
            ->values()
            ->all();
    }

    /**
     * @param  'collections'|'taxonomies'|'globals'|'asset_containers'|'navigations'  $type
     * @return SupportCollection<int, string> exposed handles, sorted for deterministic output
     */
    private function sortedExposed(string $type): SupportCollection
    {
        return collect($this->exposedHandles($type))->sort()->values();
    }
}
