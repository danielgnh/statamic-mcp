<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\AuthorizesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ComparesPatchData;
use Danielgnh\StatamicMcp\Tools\Concerns\LocalizesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\NormalizesEntryInput;
use Danielgnh\StatamicMcp\Tools\Concerns\PlacesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Structures\CollectionTree;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Structures\Page;
use Statamic\Support\Str;

#[Name('entries_localize')]
#[Description('Add an entry to another site of its collection, the way the Control Panel\'s Localize action does. The new entry is a localization of the one passed: it inherits every field it does not override, and data holds the values of its own, such as a translated title and content. Only fields marked localizable in the blueprint take a value of their own; the rest show the origin\'s value in every site, and the Control Panel makes them read-only there. The origin is the entry passed, or the root entry when the collection\'s origin_behavior is root (see statamic_overview), as in the Control Panel. slug defaults to the origin\'s slug, and the date of a dated collection is inherited. Always saves an unpublished draft — nothing goes live here; call entries_publish afterwards. On a structured collection the localization joins the target site\'s tree under the localization of the origin\'s parent, or at the top level when the parent has none there. Needs the edit permission for the origin entry, as in the Control Panel, and access to the target site. A site that already has the entry is an error naming that entry: change it with entries_update. Collections whose propagate is on (see statamic_overview) get a localization in every site from entries_create already, and entries_get lists them under localizations. Only registered on multisite installs.')]
class EntriesLocalize extends Tool
{
    use AuthorizesEntries;
    use ComparesPatchData;
    use LocalizesEntries;
    use NormalizesEntryInput;
    use PlacesEntries;
    use ResolvesEntries;
    use ResolvesSites;
    use ValidatesBlueprintData;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Id of the entry to localize. The new entry inherits from it.')->required(),
            'site' => $schema->string()->description('Handle of the site to add the entry to.')->required(),
            'data' => $schema->object()->description('Raw values this site gets of its own, keyed by blueprint field handle — localizable fields only; everything else is inherited. May be omitted.'),
            'slug' => $schema->string()->description("Slug in the target site. Defaults to the origin's slug; rejected on a collection with slugs turned off, whose entries have none."),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled() && Site::multiEnabled();
    }

    protected function execute(Request $request): Response
    {
        $this->ensureWritesEnabled();

        // Statamic's own switch for everything site-related in the CP; a
        // client with a stale tool cache may still call this on one site.
        if (! Site::multiEnabled()) {
            throw new ToolException('this install has one site, so there is no other site to add an entry to — entries_localize is available once multisite is on (statamic.system.multisite, a Statamic Pro feature)');
        }

        $this->rejectPublishedArgument($request, 'entries_localize');

        $validated = $request->validate([
            'id' => 'required|string',
            'site' => 'required|string',
            'data' => 'nullable|array',
            'slug' => 'nullable|string',
        ]);

        if ($request->get('date') !== null) {
            throw new ToolException('a localization inherits the date of its origin — omit date; when the date field is localizable, set it afterwards with entries_update');
        }

        $user = $this->user($request);

        $entry = $this->findExposedEntry($validated['id'], $user);

        $collection = $entry->collection();
        $collectionHandle = $collection->handle();

        // CP parity: with origin_behavior root the localization originates
        // from the root entry whatever localization was passed; otherwise
        // the passed entry is the origin, the CP's active and select cases.
        $origin = $collection->originBehavior() === 'root' ? $entry->root() : $entry;

        // CP parity (LocalizeEntryController): the edit permission for the
        // origin, not create, plus access to its site and the target site.
        $this->ensureSiteAccess($user, $origin->locale());
        $this->ensureEntryPermission($user, 'edit', $origin);

        $site = $this->resolveSite($request, $user);

        $this->ensureCollectionInSite($collection, $site);

        $this->ensureNotInSite($origin, $site);

        $data = (array) data_get($validated, 'data', []);

        $this->rejectPreviewObjects($data, 'entries_get');

        $this->rejectAmbiguousDataKeys($data, $collection->dated());

        $blueprint = $origin->blueprint();

        $this->rejectUnknownKeys($blueprint, $data);

        $this->rejectUnlocalizableFields($origin, $blueprint, $data);

        $this->rejectSlugWithoutSlugField($validated['slug'] ?? null, $blueprint, $collection);

        $this->ensureAuthorUnchanged($user, $origin, $data);

        $slug = $this->resolveSlug($validated['slug'] ?? $origin->slug(), $site);

        // The localization validates with the values it inherits under its
        // own, as in the CP's form, so a required field it inherits never
        // false-fails; only its own data is stored. The date is inherited too.
        $values = [...$origin->values()->all(), ...$data, 'slug' => $slug];

        if ($collection->dated()) {
            $values['date'] = $origin->date();
        }

        $data = $this->processAgainstBlueprint(
            $blueprint,
            $values,
            array_keys($data),
            ['collection' => $collectionHandle, 'site' => $site],
        );

        // CP parity (Entry::makeLocalization, then Revisable::store): the
        // origin, its slug, and a draft. The tree placement goes through
        // saveTreeChange rather than the vendor callback so it takes the
        // tree's lock, as entries_create does.
        $localization = Entry::make()
            ->origin($origin)
            ->collection($collection)
            ->locale($site)
            ->published(false)
            ->slug($slug)
            ->data($data);

        $tree = $this->placementTree($collection, $site);

        $parent = $tree ? $this->localizedParent($origin, $site) : null;

        $this->ensureUniqueUri($localization, $tree, $parent);

        if ($tree) {
            $localization->afterSave(fn ($entry) => $this->saveTreeChange($collection, $site, fn (CollectionTree $tree) => $tree->remove($entry)->appendTo($parent, $entry)));
        }

        if (! $localization->updateLastModified($user)->save()) {
            throw new ToolException('the save was cancelled by a listener on this site — nothing was created');
        }

        if ($collection->revisionsEnabled()) {
            $localization->makeRevision()->user($user)->message('Created via MCP (entries_localize)')->save();
        }

        $payload = [
            'id' => $localization->id(),
            'slug' => $localization->slug(),
            'site' => $site,
            'origin_id' => $origin->id(),
            'status' => $localization->status(),
            'url' => $localization->url(),
            ...$this->liveness($localization, self::LIVENESS_DRAFT),
        ];

        if ($collection->dated()) {
            $payload['date'] = $localization->date()?->toIso8601String();
        }

        if ($tree) {
            $payload['parent'] = $parent;
        }

        $payload['localizations'] = $this->localizations($user, $localization);

        return $this->json($payload);
    }

    private function ensureNotInSite(EntryContract $origin, string $site): void
    {
        $existing = $origin->in($site);

        if (! $existing) {
            return;
        }

        if ($existing->id() === $origin->id()) {
            throw new ToolException(sprintf(
                "entry '%s' is in site '%s' itself — pass another site of collection '%s': %s",
                $origin->id(),
                $site,
                $origin->collectionHandle(),
                $origin->collection()->sites()->reject($site)->sort()->implode(', '),
            ));
        }

        throw new ToolException(sprintf(
            "entry '%s' is already in site '%s' as entry '%s' — change that one with entries_update",
            $origin->id(),
            $site,
            $existing->id(),
        ));
    }

    /**
     * Entry::save() re-normalizes the slug with the site's language, so the
     * blueprint's rules and the URL check run on what will be persisted, as
     * in entries_create. An origin without a slug, as in a collection with
     * slugs turned off, gives its localization none (CP parity,
     * Entry::makeLocalization).
     */
    private function resolveSlug(?string $slug, string $site): ?string
    {
        if ($slug === null) {
            return null;
        }

        $normalized = Str::slug($slug, '-', Site::get($site)->lang());

        if ($normalized === '') {
            throw new ToolException(sprintf("slug '%s' normalizes to an empty string — pass a usable slug", $slug));
        }

        return $normalized;
    }

    /**
     * CP parity (Entry::addToStructure): the id of the target site's
     * localization of the origin's parent, or null for the top level when
     * the origin is at the top level or its parent has no localization there.
     */
    private function localizedParent(EntryContract $origin, string $site): ?string
    {
        $parent = $origin->parent()?->in($site);

        if (! $parent instanceof Page || $parent->isRoot()) {
            return null;
        }

        return $parent->id();
    }
}
