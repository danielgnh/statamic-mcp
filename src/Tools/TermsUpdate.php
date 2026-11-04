<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ComparesPatchData;
use Danielgnh\StatamicMcp\Tools\Concerns\MergesSets;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Facades\Term;
use Statamic\Support\Str;

#[Name('terms_update')]
#[Description("Update a taxonomy term with a shallow top-level-key merge of raw field data onto that site's local overrides (the terms_get raw shape) — nested structures are replaced wholesale; explicit null clears a field. A Replicator or Grid field takes an object keyed by set id that merges into just those sets, or a list of sets in their order where an item with a stored id merges into that set, and a Bard, Markdown, text, or textarea field takes {\"find\": \"...\", \"replace\": \"...\"} to change one piece of its text, as in entries_update. A new set whose id is blank, 0, or shared with another new set gets a fresh id; terms_get shows the stored ids. With site, writes that site's localized data override, creating it transparently on first write. slug renames the term: on the default site this changes the term id (\"{taxonomy}::{slug}\") and moves the yaml file; on other sites it stores a localized slug override. An update that changes nothing is a no-op. Terms have no draft state — changes are live immediately. Never send augmented data.")]
class TermsUpdate extends Tool
{
    use ComparesPatchData;
    use MergesSets;
    use ResolvesSites;
    use ValidatesBlueprintData;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Term id: "{taxonomy}::{slug}", e.g. "tags::php".')->required(),
            'data' => $schema->object()->description('Raw field data to merge, keyed by blueprint field handle. A Replicator or Grid field also takes {"<set id>": {...}} to merge into single sets, or a list of [{"id": "<set id>"}, ...] with new sets where they go. A Bard, Markdown, text, or textarea field also takes {"find": "...", "replace": "..."} to change one piece of its text. May be empty when only changing the slug.')->required(),
            'slug' => $schema->string()->description('New slug. On the default site this changes the term id; on other sites it stores a localized slug override.'),
            'site' => $schema->string()->description("Site handle. Defaults to the default site. Writes that site's data override."),
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
                'id' => 'required|string',
                'data' => 'present|array',
                'slug' => 'nullable|string',
                'site' => 'nullable|string',
            ],
            [
                'id.required' => 'Pass a term id: "{taxonomy}::{slug}", e.g. "tags::php".',
                'data.present' => 'Pass data to merge (may be an empty object when only changing the slug).',
            ],
        );

        $id = $validated['id'];

        if (! str_contains((string) $id, '::')) {
            throw new ToolException("term ids look like '{taxonomy}::{slug}', e.g. 'tags::php' — got '{$id}'");
        }

        [$taxonomyHandle] = explode('::', (string) $id, 2);

        $this->ensureExposed('taxonomies', $taxonomyHandle);

        $user = $this->user($request);

        $this->ensurePermission($user, "edit {$taxonomyHandle} terms");

        if (! $term = Term::find($id)) {
            throw new ToolException("term '{$id}' not found — use terms_list with taxonomy '{$taxonomyHandle}' to see available terms");
        }

        $site = $this->resolveSite($request, $user, $term->taxonomy()->sites());

        $localized = $term->in($site);
        $defaultSite = $term->taxonomy()->sites()->first();
        $blueprint = $localized->blueprint();

        $patch = collect((array) $validated['data'])->except(['updated_at', 'updated_by'])->all();

        $this->rejectPreviewObjects($patch, 'terms_get');

        if (array_key_exists('slug', $patch)) {
            if ($patch['slug'] !== $localized->slug()) {
                throw new ToolException('pass slug as a top-level parameter, not inside data');
            }

            unset($patch['slug']);
        }

        $existingLocal = $localized->data()->all();

        $effective = [
            ...$term->in($defaultSite)->data()->all(),
            ...$existingLocal,
        ];

        $patch = $this->mergeSetPatches($blueprint, $patch, $effective);

        $newSlug = $this->resolveSlug($validated['slug'] ?? null);
        $slugChanged = $newSlug !== null && $newSlug !== $localized->slug();

        if ($slugChanged && $site === $defaultSite && Term::find("{$taxonomyHandle}::{$newSlug}")) {
            throw new ToolException("term '{$newSlug}' already exists in taxonomy '{$taxonomyHandle}' — pick another slug");
        }

        $newLocal = array_merge($existingLocal, $this->processAgainstBlueprint($blueprint, [
            ...$effective,
            ...$patch,
            'slug' => $newSlug ?? $localized->slug(),
        ], array_keys($patch)));

        if (! $slugChanged && $this->normalize($newLocal) === $this->normalize($existingLocal)) {
            return $this->json([
                'id' => $term->id(),
                'site' => $site,
                'result' => 'no-op — merged data equals current data; nothing saved',
                'cp_edit_url' => $localized->editUrl(),
            ]);
        }

        $renamed = $slugChanged && $site === $defaultSite;
        $previousId = $term->id();

        $term->term()->syncOriginal();

        $localized->data($newLocal);

        if ($slugChanged) {
            $localized->slug($newSlug);
        }

        if (! $localized->updateLastModified($user)->save()) {
            throw new ToolException('the save was cancelled by a listener — the term was not updated');
        }

        $payload = [
            'id' => $term->id(),
            'taxonomy' => $taxonomyHandle,
            'slug' => $localized->slug(),
            'site' => $site,
        ];

        if ($site !== $defaultSite) {
            $payload['localization'] = $existingLocal === [] ? 'created' : 'amended';
        }

        if ($renamed) {
            $payload['previous_id'] = $previousId;
            $payload['note'] = "slug renamed: the term id changed and its file moved — the old id no longer resolves; term references in entries are rewritten by Statamic's reference updater (runs on the queue; skipped when statamic.system.update_references is false), possibly with a delay when a queue worker is used — an immediate re-read may still show the old slug in entry fields; do not rewrite references manually";
        }

        return $this->json([...$payload, ...$this->liveness($localized, self::LIVENESS_LIVE)]);
    }

    private function resolveSlug(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }

        $normalized = Str::slug($slug);

        if ($normalized === '') {
            throw new ToolException("slug '{$slug}' normalizes to an empty string — pass a usable slug");
        }

        return $normalized;
    }
}
