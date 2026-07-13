<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ComparesPatchData;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Statamic\Facades\Term;
use Statamic\Support\Str;

#[Name('terms_update')]
#[Description("Update a taxonomy term with a shallow top-level-key merge of raw field data onto that site's local overrides (the terms_get raw shape) — nested structures are replaced wholesale; explicit null clears a field. With site, writes that site's localized data override, creating it transparently on first write. slug renames the term: on the default site this changes the term id (\"{taxonomy}::{slug}\") and moves the yaml file; on other sites it stores a localized slug override. An update that changes nothing is a no-op. Terms have no draft state — changes are live immediately. Never send augmented data.")]
#[IsIdempotent]
class TermsUpdate extends Tool
{
    use ComparesPatchData;
    use ResolvesSites;
    use ValidatesBlueprintData;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Term id: "{taxonomy}::{slug}", e.g. "tags::php".')->required(),
            'data' => $schema->object()->description('Raw field data to merge, keyed by blueprint field handle. May be empty when only changing the slug.')->required(),
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

        // 'present' not 'required': a slug-only update sends an empty data
        // object, and Laravel's 'required' fails on [].
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

        // Terms only exist in the taxonomy's own configured sites; the trait
        // enforces 'access {site} site' for non-default sites on multisite.
        $site = $this->resolveSite($request, $user, $term->taxonomy()->sites());

        $localized = $term->in($site);
        // The term's origin locale is the taxonomy's FIRST configured site
        // (Term::defaultLocale()), not necessarily the global default site.
        $defaultSite = $term->taxonomy()->sites()->first();
        $blueprint = $localized->blueprint();

        // updated_at/updated_by are Statamic-managed metadata (terms_get
        // strips them from raw output, but stale copies may live in agent
        // context) — silently ignored, never merged or treated as a change.
        $patch = collect((array) $validated['data'])->except(['updated_at', 'updated_by'])->all();

        $this->rejectPreviewObjects($patch, 'terms_get');

        // On non-default sites a localized slug rename stores itself as a
        // data['slug'] override (vendor LocalizedTerm::slug()), so terms_get's
        // round-trippable bucket can legitimately contain it: an UNCHANGED
        // value is harmless and stripped like updated_at. A differing value
        // is ambiguous with the top-level rename parameter.
        if (array_key_exists('slug', $patch)) {
            if ($patch['slug'] !== $localized->slug()) {
                throw new ToolException('pass slug as a top-level parameter, not inside data');
            }

            unset($patch['slug']);
        }

        $this->rejectUnknownKeys($blueprint, $patch);

        // The merge basis is the SITE's local override bucket — the exact
        // round-trippable shape terms_get returns for that site (globals
        // rule: localizations are data overrides within one term).
        $existingLocal = $localized->data()->all();
        $newLocal = array_merge($existingLocal, $patch);

        $newSlug = $this->resolveSlug($validated['slug'] ?? null);
        $slugChanged = $newSlug !== null && $newSlug !== $localized->slug();

        // On the default site the id IS taxonomy::slug — a collided rename
        // would silently OVERWRITE the existing term's file. Localized slugs
        // are data overrides and never touch another term's file.
        if ($slugChanged && $site === $defaultSite && Term::find("{$taxonomyHandle}::{$newSlug}")) {
            throw new ToolException("term '{$newSlug}' already exists in taxonomy '{$taxonomyHandle}' — pick another slug");
        }

        // Strict compare over normalized values: assoc key
        // order is irrelevant, but types matter — loose == would turn an
        // explicit null-clear of a falsy field into a false no-op.
        if (! $slugChanged && $this->normalize($newLocal) === $this->normalize($existingLocal)) {
            return $this->json([
                'id' => $term->id(),
                'site' => $site,
                'result' => 'no-op — merged data equals current data; nothing saved',
                'cp_edit_url' => $localized->editUrl(),
            ]);
        }

        // Validate the EFFECTIVE values (origin-site data under the local
        // overrides) so a partial localized patch never false-fails required
        // fields — only the local override is stored. v6 injects a required
        // 'slug' field into term blueprints: feed the effective slug in the
        // same way the CP does. Terms need no rule replacements (the CP's
        // term update path passes none; the id is the uniqueness key).
        $this->validateAgainstBlueprint($blueprint, array_merge(
            $term->in($defaultSite)->data()->all(),
            $newLocal,
            ['slug' => $newSlug ?? $localized->slug()],
        ));

        $renamed = $slugChanged && $site === $defaultSite;
        $previousId = $term->id();

        // Rename integrity (CP parity — TermsController@update syncs before
        // mutating): the Stache store and the UpdateTermReferences listener
        // both key renames off getOriginal('slug'). A file-hydrated term has
        // no synced original, so without this the old file survives as an
        // orphaned duplicate and entry references are never rewritten.
        // (Term::find returns a LocalizedTerm — dirty state lives on the
        // underlying Term, hence ->term(), same as the CP.)
        $term->term()->syncOriginal();

        // data() BEFORE slug(): on non-default sites the localized slug is
        // itself a key in the data override bucket — setting data afterwards
        // would wipe it.
        $localized->data($newLocal);

        if ($slugChanged) {
            // v6 LocalizedTerm::slug(): default site renames the term (new
            // id, file moved, old file deleted by the store); other sites
            // store a localized 'slug' data override.
            $localized->slug($newSlug);
        }

        // CP parity: updates refresh updated_by/updated_at on this
        // localization. save() returns false when a TermSaving listener
        // cancels (approval addons do this) — never report success for it.
        if (! $localized->updateLastModified($user)->save()) {
            throw new ToolException('the save was cancelled by a listener — the term was not updated');
        }

        $payload = [
            'id' => $term->id(),
            'taxonomy' => $taxonomyHandle,
            'slug' => $localized->slug(),
            'site' => $site,
            // Mirror terms_get's round-trippable raw shape: this site's local
            // overrides, minus Statamic-managed metadata.
            'data' => $localized->data()->except(['updated_at', 'updated_by'])->all(),
        ];

        if ($site !== $defaultSite) {
            $payload['localization'] = $existingLocal === [] ? 'created' : 'amended';
        }

        if ($renamed) {
            $payload['previous_id'] = $previousId;
            $payload['note'] = "slug renamed: the term id changed and its file moved — the old id no longer resolves; term references in entries are rewritten by Statamic's reference updater (runs on the queue; skipped when statamic.system.update_references is false), possibly with a delay when a queue worker is used — an immediate re-read may still show the old slug in entry fields; do not rewrite references manually";
        }

        // Terms have no draft state — updates are live
        // immediately.
        return $this->json([...$payload, ...$this->liveness($localized, self::LIVENESS_LIVE)]);
    }

    private function resolveSlug(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }

        // Term::slug() re-normalizes through Str::slug() on set — language-
        // agnostic, unlike entries' site-language Routable::slug() — so run
        // the exact same call here: the collision check and no-op comparison
        // must see what will actually be persisted.
        $normalized = Str::slug($slug);

        if ($normalized === '') {
            throw new ToolException("slug '{$slug}' normalizes to an empty string — pass a usable slug");
        }

        return $normalized;
    }
}
