<?php

namespace Danielgnh\StatamicMcp\Tools;

use Carbon\Exceptions\InvalidFormatException;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Support\Str;

#[Name('entries_update')]
#[Description('Update an entry with a shallow top-level merge of raw field data: nested structures (Bard, arrays) are replaced wholesale, never deep-merged — always send the complete new value for a nested field. Explicit null clears a field (stores a local null); resetting a field to inherit from its origin localization is not supported in v1. Publish state is untouched unless published is sent; changing it in either direction requires the publish permission. site is a selector only — it must match the entry\'s own site and never creates or moves localizations. If the merged result equals the current entry, nothing is saved.')]
#[IsIdempotent]
class EntriesUpdate extends Tool
{
    use ResolvesEntries;
    use ResolvesSites;
    use ValidatesBlueprintData;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id.')->required(),
            'data' => $schema->object()->description('Raw field values to merge over the current top-level data. Unknown keys are rejected; null clears a field.')->required(),
            'slug' => $schema->string()->description('New slug.'),
            'date' => $schema->string()->description('New date (e.g. 2026-07-09 or 2026-07-09 15:30) — dated collections only.'),
            'published' => $schema->boolean()->description('Omit to leave publish state untouched. Changing it requires the publish permission for the collection.'),
            'site' => $schema->string()->description("Selector only: must match the entry's own site, or be omitted."),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        // Re-check the registration gate: stale client tool caches are a
        // documented UX wart, not a security hole (spec §6 layer 1).
        $this->ensureWritesEnabled();

        // laravel/mcp doesn't enforce the JSON schema server-side (T10) —
        // validate shapes before touching them.
        $validated = $request->validate(
            [
                'id' => 'required|string',
                'data' => 'required|array',
                'slug' => 'nullable|string',
                'date' => 'nullable|string',
                'published' => 'nullable|boolean',
                'site' => 'nullable|string',
            ],
            ['data.required' => "Pass 'data' as an object of raw field values to merge — call entries_get (format raw) first."],
        );

        $user = $this->user($request);

        // Exposure + site-match + site-access in one place — never look up by bare id.
        $entry = $this->findExposedEntry($validated['id'], $user, $validated['site'] ?? null);

        $collection = $entry->collection();
        $collectionHandle = $collection->handle();

        $this->ensurePermission($user, "edit {$collectionHandle} entries");

        // updated_at/updated_by are Statamic-managed metadata (entries_get
        // strips them from raw output, but stale copies may live in agent
        // context) — silently ignored, never merged or treated as a change.
        $data = collect($validated['data'])->except(['updated_at', 'updated_by'])->all();

        $this->rejectPreviewObjects($data);

        // Dated collections inject a 'date' blueprint field — the tool models
        // it as a top-level param, so reject the ambiguous data-key spelling.
        if ($collection->dated() && array_key_exists('date', $data)) {
            throw new ToolException('pass date as a top-level parameter, not inside data');
        }

        $blueprint = $entry->blueprint();
        $this->rejectUnknownKeys($blueprint, $data);

        $date = $this->resolveDate($validated['date'] ?? null, $entry);

        $published = $validated['published'] ?? null;

        if ($published !== null && $published !== $entry->published()) {
            // Any publish-state transition is gated on 'publish' — the CP's
            // unpublish route authorizes the same ability (spec §6 layer 3;
            // v6 has no separate unpublish permission).
            $this->ensurePermission($user, "publish {$collectionHandle} entries");
        }

        $current = $entry->data()->all();
        $merged = array_merge($current, $data); // shallow top-level merge by design (spec §4/§8)

        $slug = $this->resolveSlug($validated['slug'] ?? null, $entry);

        // Strict compare over normalized values: assoc key order is
        // irrelevant (sorted recursively), but types matter — loose == would
        // juggle null == '' and '1' == 1 into false no-ops, so an explicit
        // null could never clear a falsy field and the write would be
        // silently dropped.
        $dirty = $this->normalize($merged) !== $this->normalize($current)
            || ($slug !== null && $slug !== $entry->slug())
            || ($date !== null && ! $date->equalTo($entry->date()))
            || ($published !== null && $published !== $entry->published());

        if (! $dirty) {
            return $this->json([
                'id' => $entry->id(),
                'result' => 'no-op — merged result equals the current entry; nothing saved, no revision created',
                'cp_edit_url' => $entry->editUrl(),
            ]);
        }

        // The injected date field on dated collections is required — satisfy
        // it with a Carbon (Statamic\Rules\DateFieldtype accepts Carbon,
        // rejects plain strings). Replacements mirror the CP's update path,
        // so unique_entry_value excludes this entry itself.
        $this->validateAgainstBlueprint(
            $blueprint,
            $collection->dated() ? [...$merged, 'date' => $date ?? $entry->date()] : $merged,
            ['id' => $entry->id(), 'collection' => $collectionHandle, 'site' => $entry->locale()],
        );

        $entry->data($merged);

        if ($slug !== null) {
            $entry->slug($slug);
        }

        if ($date !== null) {
            $entry->date($date);
        }

        if ($published !== null) {
            $entry->published($published);
        }

        // CP parity: updates refresh updated_by/updated_at. save() returns
        // false when an EntrySaving listener cancels (approval addons do
        // this) — never report success for it.
        if (! $entry->updateLastModified($user)->save()) {
            throw new ToolException('the save was cancelled by a listener on this site — the entry was not updated');
        }

        $payload = [
            'id' => $entry->id(),
            'slug' => $entry->slug(),
            'status' => $entry->status(),
            'url' => $entry->url(),
            ...$this->liveness($entry, $entry->published() ? self::LIVENESS_PUBLISHED : self::LIVENESS_DRAFT),
        ];

        if ($collection->dated()) {
            $payload['date'] = $entry->date()?->toIso8601String();
        }

        return $this->json($payload);
    }

    /**
     * Recursively sort associative keys (list order is content, key order is
     * not) so the dirty check can compare strictly — see the comment there.
     */
    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($this->normalize(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * The one raw-path artifact an agent can accidentally round-trip: the
     * truncated {__preview, truncated, note} shape entries_get substitutes
     * for long Bard/markdown values (T11 quality review).
     */
    private function rejectPreviewObjects(array $data): void
    {
        foreach ($data as $handle => $value) {
            if (is_array($value) && array_key_exists('__preview', $value)) {
                throw new ToolException(sprintf(
                    'field %s is a truncated preview object from entries_get, not raw content — fetch the raw value first (entries_get with fields: ["%s"]) and send that back',
                    $handle,
                    $handle,
                ));
            }
        }
    }

    private function resolveDate(?string $date, EntryContract $entry): ?Carbon
    {
        if ($date === null) {
            return null;
        }

        // Symmetry with the slug path: an empty value is an error, never a
        // silent ignore (Carbon::parse('') would quietly mean "now").
        if (trim($date) === '') {
            throw new ToolException('date is empty — pass e.g. 2026-07-09 or 2026-07-09 15:30, or omit date');
        }

        if (! $entry->collection()->dated()) {
            throw new ToolException(sprintf(
                "collection '%s' is not dated — omit date",
                $entry->collection()->handle(),
            ));
        }

        try {
            return Carbon::parse($date);
        } catch (InvalidFormatException) {
            throw new ToolException(sprintf("could not parse date '%s' — use e.g. 2026-07-09 or 2026-07-09 15:30", $date));
        }
    }

    /**
     * The normalized new slug, or null when none was sent. Entry::save()
     * re-normalizes through Routable::slug() with the site's language — run
     * the exact same call here so the no-op comparison and the collision
     * check both see what will actually be persisted.
     */
    private function resolveSlug(?string $slug, EntryContract $entry): ?string
    {
        if ($slug === null) {
            return null;
        }

        $normalized = Str::slug($slug, '-', Site::get($entry->locale())->lang());

        if ($normalized === '') {
            throw new ToolException(sprintf("slug '%s' normalizes to an empty string — pass a usable slug", $slug));
        }

        // Its own slug is never a collision — only a changed slug can collide,
        // and the existing holder of the old slug is this entry itself.
        if ($normalized === $entry->slug()) {
            return $normalized;
        }

        $existing = Entry::query()
            ->where('collection', $entry->collection()->handle())
            ->where('slug', $normalized)
            ->where('site', $entry->locale())
            ->first();

        if ($existing && $existing->id() !== $entry->id()) {
            throw new ToolException(sprintf(
                "slug '%s' already exists in collection '%s' (site '%s') as entry '%s'",
                $normalized,
                $entry->collection()->handle(),
                $entry->locale(),
                $existing->id(),
            ));
        }

        return $normalized;
    }
}
