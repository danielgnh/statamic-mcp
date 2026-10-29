<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\PresentsAugmentedData;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\SelectsFields;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Term;

#[Name('terms_get')]
#[Description('Get one taxonomy term by id ("{taxonomy}::{slug}") or by taxonomy + slug. format=raw (default) returns the round-trippable data shape for terms_update; format=augmented is read-only — never send augmented values back into terms_update. With site, data holds that site\'s local overrides and inherited holds what comes from the term\'s origin site (the taxonomy\'s first configured site — a term\'s localizations are data overrides within one term). Terms have no publish state, so there is no status. Large values are truncated to preview objects (Bard and Markdown over 500 bytes, any other value over 4 KB, a Grid excepted), and a large Replicator field to an index of its sets, unless requested via fields (an array of top-level field handles, or handle.id for one set, as in entries_get).')]
#[IsReadOnly]
class TermsGet extends Tool
{
    use PresentsAugmentedData;
    use ResolvesSites;
    use SelectsFields;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Term id: "{taxonomy}::{slug}", e.g. "tags::php". Or pass taxonomy + slug instead.'),
            'taxonomy' => $schema->string()->description('Taxonomy handle — use together with slug when id is not passed.'),
            'slug' => $schema->string()->description('Term slug — use together with taxonomy when id is not passed.'),
            'site' => $schema->string()->description('Site handle — selects the localization view of the term (term ids are the same in every site). Defaults to the default site.'),
            'format' => $schema->string()->enum(['raw', 'augmented'])->description('raw (default): local data overrides, writable. augmented: rendered values, display only — never writable.'),
            'fields' => $schema->array()->description('Top-level field handles to return in full — a large value listed here skips the preview. handle.id, with a set id from the field\'s index, returns one set of a Replicator or Grid field as an object keyed by its id: the shape the update tool merges by id.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(
            [
                'id' => 'required_without_all:taxonomy,slug|string',
                'taxonomy' => 'required_without:id|string',
                'slug' => 'required_without:id|string',
                'site' => 'nullable|string',
                'format' => 'nullable|string|in:raw,augmented',
                'fields' => 'nullable|array',
                'fields.*' => 'string',
            ],
            [
                'id.required_without_all' => 'Pass id ("{taxonomy}::{slug}", e.g. "tags::php") or taxonomy + slug.',
                'format.in' => 'format must be one of: raw, augmented.',
            ],
        );

        $id = $validated['id'] ?? $validated['taxonomy'].'::'.$validated['slug'];

        if (! str_contains((string) $id, '::')) {
            throw new ToolException("term ids look like '{taxonomy}::{slug}', e.g. 'tags::php' — got '{$id}'");
        }

        [$taxonomyHandle] = explode('::', (string) $id, 2);

        $this->ensureExposed('taxonomies', $taxonomyHandle);

        $user = $this->user($request);

        $this->ensurePermission($user, "view {$taxonomyHandle} terms");

        if (! $term = Term::find($id)) {
            throw new ToolException("term '{$id}' not found — use terms_list with taxonomy '{$taxonomyHandle}' to see available terms");
        }

        // Terms only exist in the taxonomy's own configured sites; a term id is
        // stable across localizations, so site is a separate axis, not a match check.
        $site = $this->resolveSite($request, $user, $term->taxonomy()->sites());

        $localized = $term->in($site);
        // The term's origin locale is the taxonomy's FIRST configured site
        // (Term::defaultLocale()), not necessarily the global default site.
        $originSite = $term->taxonomy()->sites()->first();
        $format = $validated['format'] ?? 'raw';
        $blueprint = $localized->blueprint();

        $response = [
            'id' => $term->id(),
            'taxonomy' => $taxonomyHandle,
            'slug' => $localized->slug(),
            'site' => $site,
            'format' => $format,
        ];

        $inherited = null;

        if ($format === 'augmented') {
            $data = $this->augmentedData($localized->toAugmentedArray());
        } else {
            // raw: the round-trippable write shape — this site's own data
            // overrides only. updated_at/updated_by are Statamic-managed
            // metadata, stripped so agents can't round-trip stale values.
            $data = $localized->data()->except(['updated_at', 'updated_by'])->all();

            if ($site !== $originSite) {
                // A term's localizations are data overrides within one term (the
                // globals rule, not the entries rule): everything not overridden
                // locally comes from the origin site's data — the taxonomy's
                // first configured site.
                $inherited = array_diff_key(
                    $term->in($originSite)->data()->except(['updated_at', 'updated_by'])->all(),
                    $data,
                );
            }
        }

        [$requestedFields, $selectedSets] = $this->parseFieldSelection(
            array_values($validated['fields'] ?? []),
            $blueprint,
            $format === 'raw' ? array_keys([...$data, ...($inherited ?? [])]) : [],
        );

        if ($requestedFields !== []) {
            $data = array_intersect_key($data, array_flip($requestedFields));
        }

        $data = $this->withSelectedSets($data, $selectedSets, $blueprint);
        $response['data'] = $this->withPreviews($data, $blueprint, $requestedFields);

        if ($inherited !== null) {
            if ($requestedFields !== []) {
                $inherited = array_intersect_key($inherited, array_flip($requestedFields));
            }

            $inherited = $this->withSelectedSets($inherited, $selectedSets, $blueprint);

            $response['origin_site'] = $originSite;
            $response['inherited'] = $this->withPreviews($inherited, $blueprint, $requestedFields);
            $response['note'] = "data = this site's local overrides (the round-trippable shape for terms_update with site '{$site}'); inherited = values coming from the origin site (the taxonomy's first configured site)";
        }

        // value('updated_at') so the fallback chain matches title() — a
        // localized view inherits the origin's timestamp
        // (terms_list uses the same chain). Never fileLastModified().
        $response['updated_at'] = ($timestamp = $localized->value('updated_at'))
            ? Carbon::createFromTimestamp($timestamp, config('app.timezone'))->toIso8601String()
            : null;

        if ($format === 'augmented') {
            $response['warning'] = 'augmented values are read-only — never send them back into terms_update; fetch format=raw first';
        }

        $response['cp_edit_url'] = $localized->editUrl();

        return $this->json($response);
    }
}
