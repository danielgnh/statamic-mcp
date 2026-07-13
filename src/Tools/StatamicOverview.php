<?php

namespace Danielgnh\StatamicMcp\Tools;

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
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;

#[Name('statamic_overview')]
#[Description('Start here — zero parameters. Returns the sites; the collections, taxonomies, global sets, and asset containers exposed to MCP and visible to you; your capability flags per resource (can_create, can_edit, can_publish, can_upload, can_delete — delete flags appear only when deletes are enabled); the acting user (email, roles, is_super); and server flags (read_only, deletes).')]
#[IsReadOnly]
#[IsIdempotent]
class StatamicOverview extends Tool
{
    use ResolvesSites;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return []; // zero parameters (spec §4 row 1)
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
            'user' => [
                'email' => $user->email(),
                'roles' => $user->roles()->map->handle()->values()->all(),
                'is_super' => $user->isSuper(),
            ],
            'server' => [
                'read_only' => ! $this->writesEnabled(),
                'deletes' => $this->deletesEnabled(),
            ],
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

                $resource = [
                    'handle' => $handle,
                    'title' => $collection->title(),
                    'dated' => $collection->dated(),
                    'revisions' => $collection->revisionsEnabled(),
                    'blueprints' => $collection->entryBlueprints()->map->handle()->values()->all(),
                    'can_create' => $this->can($user, "create {$handle} entries"),
                    'can_edit' => $this->can($user, "edit {$handle} entries"),
                    'can_publish' => $this->can($user, "publish {$handle} entries"),
                ];

                if ($this->deletesEnabled()) {
                    $resource['can_delete'] = $this->can($user, "delete {$handle} entries");
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
                    // toggle — the upload permission is the whole gate (spec §2)
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
     * @param  'collections'|'taxonomies'|'globals'|'asset_containers'  $type
     * @return SupportCollection<int, string> exposed handles, sorted for deterministic output
     */
    private function sortedExposed(string $type): SupportCollection
    {
        return collect($this->exposedHandles($type))->sort()->values();
    }
}
