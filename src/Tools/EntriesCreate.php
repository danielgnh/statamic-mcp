<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\AuthorizesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\LocalizesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\NormalizesEntryInput;
use Danielgnh\StatamicMcp\Tools\Concerns\PlacesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Structures\CollectionTree;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Support\Str;

#[Name('entries_create')]
#[Description('Create a new entry from raw field data (call blueprints_get first for the shape — never send augmented data). Always saves an unpublished draft — nothing goes live here; call entries_publish afterwards. On revision-enabled collections the draft gets an initial revision attributed to you. slug is generated from data.title when omitted, except on a collection with slugs turned off, whose entries have none. Dated collections require date. On a structured collection the entry joins its tree at the top level, or under parent: the id of an entry of the same collection and site. When the blueprint has an author field, you become the author unless data names one; naming anyone else needs \'edit other authors {collection} entries\'. On a collection in more than one site the entry exists in site only: add it to another site with entries_localize. When the collection\'s propagate is on (see statamic_overview), every site gets a localization at once, and the response lists them under localizations.')]
class EntriesCreate extends Tool
{
    use AuthorizesEntries;
    use LocalizesEntries;
    use NormalizesEntryInput;
    use PlacesEntries;
    use ResolvesSites;
    use ValidatesBlueprintData;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'collection' => $schema->string()->description('Collection handle.')->required(),
            'data' => $schema->object()->description('Raw field values keyed by blueprint field handle. Unknown keys are rejected.')->required(),
            'slug' => $schema->string()->description('URL slug. Generated from data.title when omitted; rejected on a collection with slugs turned off, whose entries have none.'),
            'parent' => $schema->string()->description('Entry id of the page to nest the new entry under, on a structured collection. Omit it, or pass "", for the top level.'),
            'site' => $schema->string()->description('Site handle. Defaults to the default site. The entry exists in this site only; entries_localize adds it to others.'),
            'date' => $schema->string()->description('Entry date: 2026-07-09, or 2026-07-09T15:30:00+02:00 with a time. A time without an offset is read in server.timezone from statamic_overview. Required for dated collections; rejected otherwise.'),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        $this->ensureWritesEnabled();

        $this->rejectPublishedArgument($request, 'entries_create');

        $validated = $request->validate(
            [
                'collection' => 'required|string',
                'data' => 'required|array',
                'slug' => 'nullable|string',
                'parent' => 'nullable|string',
                'site' => 'nullable|string',
                'date' => 'nullable|string',
            ],
            ['data.required' => "Pass 'data' as an object of raw field values — call blueprints_get for the shape."],
        );

        $collectionHandle = $validated['collection'];
        $data = $validated['data'];

        $this->ensureExposed('collections', $collectionHandle);

        $user = $this->user($request);
        $this->ensurePermission($user, "create {$collectionHandle} entries");

        $site = $this->resolveSite($request, $user);

        $collection = Collection::findByHandle($collectionHandle);

        if (! $collection) {
            throw new ToolException($this->notFoundMessage('collection', $collectionHandle, $this->exposedHandles('collections')));
        }

        $blueprint = $collection->entryBlueprint();

        $revisions = $collection->revisionsEnabled();

        $this->ensureCollectionInSite($collection, $site);

        $tree = $this->placementTree($collection, $site);

        $parent = ($validated['parent'] ?? null) === null
            ? null
            : $this->resolveParent($validated['parent'], $tree ?? throw $this->cannotNest($collection));

        // Reject the ambiguous slug/date-in-data spelling BEFORE blueprint
        // validation so our targeted error beats the validator's raw
        // "The Date field is required."
        $this->rejectAmbiguousDataKeys($data, $collection->dated());

        $date = $this->resolveDate($validated['date'] ?? null, $collection);

        $this->rejectUnknownKeys($blueprint, $data);

        $data = $this->withAuthor($user, $blueprint, $collectionHandle, $data);

        $this->rejectSlugWithoutSlugField($validated['slug'] ?? null, $blueprint, $collection);

        $slug = $blueprint->hasField('slug')
            ? $this->resolveSlug($validated['slug'] ?? null, $data, $site)
            : null;

        // The injected date field is required — satisfy it with the resolved
        // Carbon, which preProcess() turns into the date picker's shape. Slug
        // likewise: the CP form always submits it into validation, so a
        // blueprint that marks slug required must see the resolved value —
        // without it that field is unsatisfiable (slug is barred from data).
        // The replacements mirror the CP's store path (no id yet on create).
        $values = [...$data, 'slug' => $slug];

        if ($date) {
            $values['date'] = $date;
        }

        $data = $this->processAgainstBlueprint(
            $blueprint,
            $values,
            array_keys($data),
            ['collection' => $collectionHandle, 'site' => $site],
        );

        $entry = Entry::make()
            ->collection($collectionHandle)
            ->slug($slug)
            ->locale($site)
            ->data($data)
            ->published(false);

        if ($date) {
            $entry->date($date);
        }

        $this->ensureUniqueUri($entry, $tree, $parent?->id());

        if ($tree) {
            // The tree as it reads may already list this entry at the top level.
            $entry->afterSave(fn ($entry) => $this->saveTreeChange($collection, $site, fn (CollectionTree $tree) => $tree->remove($entry)->appendTo($parent?->id(), $entry)));
        }

        // CP parity: created entries carry updated_by/updated_at. save()
        // returns false when an EntryCreating/EntrySaving listener cancels
        // (approval addons do this) — never report success for it.
        if (! $entry->updateLastModified($user)->save()) {
            throw new ToolException('the save was cancelled by a listener on this site — nothing was created');
        }

        if ($revisions) {
            // CP-parity create path (EntriesController@store, 6.x): the draft
            // gets an attributed initial revision. This inlines Revisable::
            // store() — published(false) + save + makeRevision — because
            // store()'s return is the revision save, which would mask a
            // listener-cancelled entry save behind a false success.
            $entry->makeRevision()->user($user)->message('Created via MCP (entries_create)')->save();
        }

        $payload = [
            'id' => $entry->id(),
            'slug' => $entry->slug(),
            'site' => $site,
            'status' => $entry->status(),
            'url' => $entry->url(),
            ...$this->liveness($entry, self::LIVENESS_DRAFT),
        ];

        if ($collection->dated()) {
            $payload['date'] = $entry->date()?->toIso8601String();
        }

        if ($tree) {
            $payload['parent'] = $parent?->id();
        }

        if ($localizations = $this->localizations($user, $entry)) {
            $payload['localizations'] = $localizations;
        }

        return $this->json($payload);
    }

    private function resolveDate(?string $date, CollectionContract $collection): ?Carbon
    {
        if ($collection->dated() && ! $date) {
            throw new ToolException(sprintf(
                "collection '%s' is dated — pass date (e.g. 2026-07-09 or 2026-07-09T15:30:00+02:00)",
                $collection->handle(),
            ));
        }

        if (! $collection->dated() && $date) {
            throw new ToolException(sprintf("collection '%s' is not dated — omit date", $collection->handle()));
        }

        if (! $date) {
            return null;
        }

        return $this->parseEntryDate($date);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSlug(?string $slug, array $data, string $site): string
    {
        if (! $slug) {
            $title = $data['title'] ?? null;

            if (! is_string($title) || trim($title) === '') {
                throw new ToolException('pass slug, or include a title in data so a slug can be generated from it');
            }

            $slug = $title;
        }

        // Entry::save() re-normalizes through Routable::slug() with the site's
        // language — run the exact same call here so the blueprint's rules and
        // the URL check see what will actually be persisted (and Über → ueber
        // under de, CP parity).
        $slug = Str::slug($slug, '-', Site::get($site)->lang());

        if ($slug === '') {
            throw new ToolException('could not derive a slug from the title — pass slug explicitly');
        }

        return $slug;
    }
}
