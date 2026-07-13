<?php

namespace Danielgnh\StatamicMcp\Tools;

use Carbon\Exceptions\InvalidFormatException;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Support\Str;

#[Name('entries_create')]
#[Description('Create a new entry from raw field data (call blueprints_get first for the shape — never send augmented data). Saves an unpublished draft by default; published: true requires the publish permission for the collection. On revision-enabled collections entries are always created as unpublished drafts with an initial revision attributed to you, and any explicit published value is rejected — publish from the Control Panel. slug is generated from data.title when omitted. Dated collections require date.')]
class EntriesCreate extends Tool
{
    use ResolvesSites;
    use ValidatesBlueprintData;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'collection' => $schema->string()->description('Collection handle.')->required(),
            'data' => $schema->object()->description('Raw field values keyed by blueprint field handle. Unknown keys are rejected.')->required(),
            'slug' => $schema->string()->description('URL slug. Generated from data.title when omitted.'),
            'site' => $schema->string()->description('Site handle. Defaults to the default site.'),
            'date' => $schema->string()->description('Entry date (e.g. 2026-07-09 or 2026-07-09 15:30). Required for dated collections; rejected otherwise.'),
            'published' => $schema->boolean()->description('Defaults to false (draft). true requires the publish permission for the collection. Rejected entirely on revision-enabled collections.'),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        $this->ensureWritesEnabled();

        $validated = $request->validate(
            [
                'collection' => 'required|string',
                'data' => 'required|array',
                'slug' => 'nullable|string',
                'site' => 'nullable|string',
                'date' => 'nullable|string',
                'published' => 'nullable|boolean',
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

        $blueprint = $collection->entryBlueprint(); // the collection's default blueprint

        $revisions = $collection->revisionsEnabled();

        // On revision collections publish state is CP-owned: ANY explicit
        // published value — true or false — is rejected outright, and the
        // rejection must win over a publish-permission denial (spec §6), so
        // this check sits above the publish gate.
        if ($revisions && ($validated['published'] ?? null) !== null) {
            throw new ToolException(sprintf(
                "collection '%s' uses revisions — entries are always created as unpublished drafts here; publish/unpublish from the Control Panel",
                $collectionHandle,
            ));
        }

        $published = ! $revisions && (bool) ($validated['published'] ?? false);

        if ($published) {
            // Publish is distinct — matches the CP's own gate (spec §6 layer 3).
            $this->ensurePermission($user, "publish {$collectionHandle} entries");
        }

        // resolveSite() only checks the site exists and is accessible — the
        // collection itself may not be configured for it.
        if (! $collection->sites()->contains($site)) {
            throw new ToolException(sprintf(
                "collection '%s' is not available in site '%s' — available sites: %s",
                $collectionHandle,
                $site,
                $collection->sites()->sort()->implode(', '),
            ));
        }

        // Dated collections inject a required 'date' blueprint field — the
        // tool models it as a top-level param (entries_get returns it
        // top-level too), so reject the ambiguous data-key spelling and
        // resolve the param BEFORE blueprint validation: our targeted error
        // beats the validator's raw "The Date field is required."
        if ($collection->dated() && array_key_exists('date', $data)) {
            throw new ToolException('pass date as a top-level parameter, not inside data');
        }

        // Same for slug (v6's auto-injected blueprint field): entries never
        // store it in data, and the generic unknown-field error gives no
        // usable hint — targeted rejection instead.
        if (array_key_exists('slug', $data)) {
            throw new ToolException('pass slug as a top-level parameter, not inside data');
        }

        $date = $this->resolveDate($validated['date'] ?? null, $collection);

        $this->rejectUnknownKeys($blueprint, $data);

        $slug = $this->resolveSlug($validated['slug'] ?? null, $data, $collectionHandle, $site);

        // The injected date field is required — satisfy it with the resolved
        // Carbon (Statamic\Rules\DateFieldtype accepts Carbon outright). Slug
        // likewise: the CP form always submits it into validation, so a
        // blueprint that marks slug required must see the resolved value —
        // without it that field is unsatisfiable (slug is barred from data).
        // The replacements mirror the CP's store path (no id yet on create).
        $values = [...$data, 'slug' => $slug];

        if ($date) {
            $values['date'] = $date;
        }

        $this->validateAgainstBlueprint(
            $blueprint,
            $values,
            ['collection' => $collectionHandle, 'site' => $site],
        );

        $entry = Entry::make()
            ->collection($collectionHandle)
            ->slug($slug)
            ->locale($site)
            ->data($data)
            ->published($published);

        if ($date) {
            $entry->date($date);
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
            ...$this->liveness($entry, $published ? self::LIVENESS_PUBLISHED : self::LIVENESS_DRAFT),
        ];

        if ($collection->dated()) {
            $payload['date'] = $entry->date()?->toIso8601String();
        }

        return $this->json($payload);
    }

    private function resolveDate(?string $date, CollectionContract $collection): ?Carbon
    {
        if ($collection->dated() && ! $date) {
            throw new ToolException(sprintf(
                "collection '%s' is dated — pass date (e.g. 2026-07-09 or 2026-07-09 15:30)",
                $collection->handle(),
            ));
        }

        if (! $collection->dated() && $date) {
            throw new ToolException(sprintf("collection '%s' is not dated — omit date", $collection->handle()));
        }

        if (! $date) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (InvalidFormatException) {
            throw new ToolException(sprintf("could not parse date '%s' — use e.g. 2026-07-09 or 2026-07-09 15:30", $date));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSlug(?string $slug, array $data, string $collection, string $site): string
    {
        if (! $slug) {
            $title = $data['title'] ?? null;

            if (! is_string($title) || trim($title) === '') {
                throw new ToolException('pass slug, or include a title in data so a slug can be generated from it');
            }

            $slug = $title;
        }

        // Entry::save() re-normalizes through Routable::slug() with the site's
        // language — run the exact same call here so the collision check sees
        // what will actually be persisted (and Über → ueber under de, CP parity).
        $slug = Str::slug($slug, '-', Site::get($site)->lang());

        if ($slug === '') {
            throw new ToolException('could not derive a slug from the title — pass slug explicitly');
        }

        $existing = Entry::query()
            ->where('collection', $collection)
            ->where('slug', $slug)
            ->where('site', $site)
            ->first();

        if ($existing) {
            throw new ToolException(sprintf(
                "slug '%s' already exists in collection '%s' (site '%s') as entry '%s' — use entries_update to modify it",
                $slug,
                $collection,
                $site,
                $existing->id(),
            ));
        }

        return $slug;
    }
}
