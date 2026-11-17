<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Support\ActingUser;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as BaseTool;
use League\Flysystem\PathTraversalDetected;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Globals\Variables;
use Statamic\Stache\Query\Builder as StacheQueryBuilder;
use Statamic\Taxonomies\LocalizedTerm;

abstract class Tool extends BaseTool
{
    public const LIVENESS_DRAFT = 'saved as draft — not live';

    public const LIVENESS_PUBLISHED = 'published';

    public const LIVENESS_PUBLISHED_WORKING_COPY = 'published — working copy is now live';

    public const LIVENESS_SCHEDULED = 'scheduled — published, but not live until its date';

    public const LIVENESS_EXPIRED = 'expired — published, but its date has passed, not live';

    public const LIVENESS_UNPUBLISHED = 'unpublished — not live';

    public const LIVENESS_UNPUBLISHED_WORKING_COPY = 'unpublished — working copy applied, not live';

    public const LIVENESS_WORKING_COPY = 'working copy created — live entry unchanged';

    public const LIVENESS_WORKING_COPY_AMENDED = 'working copy amended — live entry unchanged';

    public const LIVENESS_LIVE = 'updated — live';

    public const LIVENESS_CREATED = 'created — live';

    public const LIVENESS_UPLOADED = 'uploaded — live';

    final public function handle(Request $request): Response
    {
        try {
            return $this->execute($request);
        } catch (ToolException $e) {
            return Response::error($e->getMessage());
        } catch (PathTraversalDetected $e) {
            return Response::error("asset path '{$e->path()}' may not contain '../' — pass a path from assets_list");
        }
    }

    abstract protected function execute(Request $request): Response;

    protected function user(Request $request): UserContract
    {
        $user = ActingUser::resolve($request->user());

        if (! $user) {
            throw new ToolException(
                'no authenticated user — the MCP server requires token or OAuth authentication; see the README'
            );
        }

        return $user;
    }

    /**
     * @param  'collections'|'taxonomies'|'globals'|'asset_containers'|'navigations'|'forms'  $type
     */
    protected function ensureExposed(string $type, string $handle): void
    {
        $exposed = $this->exposedHandles($type);

        if (! in_array($handle, $exposed, true)) {
            throw new ToolException($this->notFoundMessage(Str::singular($type), $handle, $exposed));
        }
    }

    /**
     * @param  'collections'|'taxonomies'|'globals'|'asset_containers'|'navigations'|'forms'  $type
     * @return list<string> handles that exist AND pass config('statamic.mcp.resources.{$type}')
     */
    protected function exposedHandles(string $type): array
    {
        $configured = config("statamic.mcp.resources.{$type}", false);

        if ($configured === false || $configured === []) {
            return [];
        }

        $all = match ($type) {
            'collections' => Collection::handles()->all(),
            'taxonomies' => Taxonomy::handles()->all(),
            'globals' => GlobalSet::all()->map->handle()->values()->all(),
            'asset_containers' => AssetContainer::all()->map->handle()->values()->all(),
            'navigations' => Nav::all()->map->handle()->values()->all(),
            'forms' => Form::all()->map->handle()->values()->all(),
        };

        return $configured === true
            ? array_values($all)
            : array_values(array_intersect($all, $configured));
    }

    /**
     * @param  'collections'|'taxonomies'|'globals'|'asset_containers'|'navigations'|'forms'  $type
     * @return bool whether config('statamic.mcp.resources.{$type}') lets $handle through, existing or not
     */
    protected function exposureAllows(string $type, string $handle): bool
    {
        $configured = config("statamic.mcp.resources.{$type}", false);

        return $configured === true || (is_array($configured) && in_array($handle, $configured, true));
    }

    protected function containsPattern(object $query, string $text): string
    {
        return '%'.($query instanceof StacheQueryBuilder ? str_replace(['%', '_'], ['\%', '\_'], $text) : $text).'%';
    }

    protected function can(UserContract $user, string $permission): bool
    {
        if ($user->isSuper()) {
            return true;
        }

        return (bool) $user->hasPermission($permission);
    }

    protected function ensurePermission(UserContract $user, string $permission): void
    {
        if ($this->can($user, $permission)) {
            return;
        }

        throw new ToolException(sprintf(
            "requires '%s' — grant it to a role of %s in the Control Panel",
            $permission,
            $user->email(),
        ));
    }

    protected function findExposedEntry(string $id, UserContract $user, ?string $site = null): EntryContract
    {
        $entry = Entry::find($id);

        if (! $entry || ! in_array($entry->collection()->handle(), $this->exposedHandles('collections'), true)) {
            throw new ToolException("entry '{$id}' not found");
        }

        if ($site !== null && $site !== $entry->locale()) {
            $siblings = $entry->collection()->sites()
                ->map(fn (string $handle) => $entry->in($handle))
                ->filter()
                ->map(fn (EntryContract $localization) => $localization->locale().' => '.$localization->id())
                ->values()
                ->all();

            throw new ToolException(sprintf(
                "entry '%s' belongs to site '%s', not '%s' — pass the matching localization id instead (or omit site). Localizations: %s",
                $id,
                $entry->locale(),
                $site,
                implode('; ', $siblings),
            ));
        }

        $this->ensureSiteAccess($user, $entry->locale());

        return $entry;
    }

    /**
     * @param  'edit'|'publish'|'delete'  $ability
     */
    protected function ensureEntryPermission(UserContract $user, string $ability, EntryContract $entry): void
    {
        $collection = $entry->collectionHandle();

        $this->ensurePermission($user, $this->hasAnotherAuthor($user, $entry)
            ? "{$ability} other authors {$collection} entries"
            : "{$ability} {$collection} entries");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function ensureAuthorUnchanged(UserContract $user, EntryContract $entry, array $data): void
    {
        if (! array_key_exists('author', $data) || $this->sameAuthors(data_get($data, 'author'), $entry->authors()->all())) {
            return;
        }

        $permission = "edit other authors {$entry->collectionHandle()} entries";

        if (! $this->can($user, $permission)) {
            throw new ToolException(sprintf(
                "changing the author requires '%s' — grant it to a role of %s in the Control Panel, or leave author out of data",
                $permission,
                $user->email(),
            ));
        }
    }

    protected function hasAnotherAuthor(UserContract $user, EntryContract $entry): bool
    {
        return $entry->blueprint()->hasField('author')
            && ! $entry->authors()->contains($user->id());
    }

    /**
     * @param  array<int, mixed>  $authors
     */
    protected function sameAuthors(mixed $given, array $authors): bool
    {
        $ids = fn (mixed $value) => collect(Arr::wrap($value))
            ->filter(fn (mixed $id) => (is_string($id) || is_int($id)) && filled($id))
            ->map(fn (string|int $id) => (string) $id)
            ->sort()
            ->values()
            ->all();

        return $ids($given) === $ids($authors);
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

    protected function writesEnabled(): bool
    {
        return ! config('statamic.mcp.read_only');
    }

    protected function deletesEnabled(): bool
    {
        return $this->writesEnabled() && config('statamic.mcp.deletes');
    }

    protected function ensureWritesEnabled(): void
    {
        if (! $this->writesEnabled()) {
            throw new ToolException(
                'writes are disabled on this server (statamic.mcp.read_only) — reads remain available'
            );
        }
    }

    protected function ensureDeletesEnabled(): void
    {
        $this->ensureWritesEnabled();

        if (! config('statamic.mcp.deletes')) {
            throw new ToolException(
                'delete tools are disabled on this server (statamic.mcp.deletes)'
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function json(array $data): Response
    {
        return Response::json($data);
    }

    /**
     * @param  string[]  $available
     */
    protected function notFound(string $what, string $given, array $available): Response
    {
        return Response::error($this->notFoundMessage($what, $given, $available));
    }

    /**
     * @return array{result: string, cp_edit_url: mixed}
     */
    protected function liveness(EntryContract|LocalizedTerm|Variables|AssetContract $saved, string $state): array
    {
        return [
            'result' => $state,
            'cp_edit_url' => $saved instanceof AssetContract ? $this->assetEditUrl($saved) : $saved->editUrl(),
        ];
    }

    protected function assetEditUrl(AssetContract $asset): ?string
    {
        try {
            return $asset->editUrl();
        } catch (UrlGenerationException) {
            return null;
        }
    }

    /**
     * @return array{result: string, cp_edit_url: mixed}
     */
    protected function entryLiveness(EntryContract $entry, string $whenLive): array
    {
        return $this->liveness($entry, match ($entry->status()) {
            'scheduled' => self::LIVENESS_SCHEDULED,
            'expired' => self::LIVENESS_EXPIRED,
            default => $whenLive,
        });
    }

    /**
     * @param  string[]  $available
     */
    protected function notFoundMessage(string $what, string $given, array $available): string
    {
        sort($available);

        return sprintf(
            "%s '%s' not found — available: %s",
            $what,
            $given,
            $available === [] ? '(none exposed)' : implode(', ', $available),
        );
    }
}
