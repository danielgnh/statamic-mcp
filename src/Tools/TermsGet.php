<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\PreviewsRichText;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Term;
use Statamic\Fields\Value;

#[Name('terms_get')]
#[Description('Get one taxonomy term by id ("{taxonomy}::{slug}") or by taxonomy + slug. format=raw (default) returns the round-trippable data shape for terms_update; format=augmented is read-only — never send augmented values back into terms_update. With site, data holds that site\'s local overrides and inherited holds what comes from the term\'s origin site (the taxonomy\'s first configured site — a term\'s localizations are data overrides within one term). Terms have no publish state, so there is no status. Long Bard/rich-text values are truncated to preview objects unless requested via fields (an array of top-level field handles).')]
#[IsReadOnly]
class TermsGet extends Tool
{
    use PreviewsRichText;
    use ResolvesSites;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Term id: "{taxonomy}::{slug}", e.g. "tags::php". Or pass taxonomy + slug instead.'),
            'taxonomy' => $schema->string()->description('Taxonomy handle — use together with slug when id is not passed.'),
            'slug' => $schema->string()->description('Term slug — use together with taxonomy when id is not passed.'),
            'site' => $schema->string()->description('Site handle — selects the localization view of the term (term ids are the same in every site). Defaults to the default site.'),
            'format' => $schema->string()->enum(['raw', 'augmented'])->description('raw (default): local data overrides, writable. augmented: rendered values, display only — never writable.'),
            'fields' => $schema->array()->description('Top-level field handles to return in full — Bard/rich-text fields listed here skip preview truncation.'),
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
        $requestedFields = array_values($validated['fields'] ?? []);
        $blueprint = $localized->blueprint();

        $this->assertKnownFields($requestedFields, $blueprint);

        $response = [
            'id' => $term->id(),
            'taxonomy' => $taxonomyHandle,
            'slug' => $localized->slug(),
            'site' => $site,
            'format' => $format,
        ];

        $inherited = null;

        if ($format === 'augmented') {
            // Value::jsonSerialize runs a FULL augment — a relation field would
            // inline whole augmented items including their reverse entries.
            // shallow() reduces relations to id/title/api_url-style stubs.
            $data = collect((array) $localized->toAugmentedArray())
                ->map(fn ($value) => $value instanceof Value ? $value->shallow() : $value)
                ->all();
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

        if ($requestedFields !== []) {
            $data = array_intersect_key($data, array_flip($requestedFields));
        }

        $response['data'] = $this->withRichTextPreviews($data, $blueprint, $requestedFields);

        if ($inherited !== null) {
            if ($requestedFields !== []) {
                $inherited = array_intersect_key($inherited, array_flip($requestedFields));
            }

            $response['origin_site'] = $originSite;
            $response['inherited'] = $this->withRichTextPreviews($inherited, $blueprint, $requestedFields);
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
