<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Support\Str;

#[Name('terms_create')]
#[Description("Create a taxonomy term from raw field data (get the shape from blueprints_get; never send augmented data). Slug is generated from data.title when omitted. Terms are created in the taxonomy's origin site (its first configured site) — localize afterwards with terms_update and its site parameter. Terms have no draft state: a created term is live immediately.")]
class TermsCreate extends Tool
{
    use ResolvesSites;
    use ValidatesBlueprintData;

    public function schema(JsonSchema $schema): array
    {
        return [
            'taxonomy' => $schema->string()->description('Taxonomy handle, e.g. "tags".')->required(),
            'data' => $schema->object()->description('Raw field values keyed by blueprint field handle. Unknown keys are rejected.')->required(),
            'slug' => $schema->string()->description('URL slug. Generated from data.title when omitted.'),
            'site' => $schema->string()->description("Must be the taxonomy's origin site (its first configured site) when given — terms are created there and localized via terms_update."),
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
                'taxonomy' => 'required|string',
                'data' => 'required|array',
                'slug' => 'nullable|string',
                'site' => 'nullable|string',
            ],
            [
                'taxonomy.required' => 'Pass a taxonomy handle, e.g. "tags".',
                'data.required' => "Pass 'data' as an object of raw field values — call blueprints_get for the shape.",
            ],
        );

        $taxonomyHandle = $validated['taxonomy'];
        $data = $validated['data'];

        $this->ensureExposed('taxonomies', $taxonomyHandle);

        $user = $this->user($request);
        $this->ensurePermission($user, "create {$taxonomyHandle} terms");

        $taxonomy = Taxonomy::findByHandle($taxonomyHandle);

        // A term's origin locale is the taxonomy's FIRST configured site
        // (Term::defaultLocale()), not necessarily the global default site.
        $defaultSite = $taxonomy->sites()->first();

        if (($site = $validated['site'] ?? null) && $site !== $defaultSite) {
            throw new ToolException(sprintf(
                "terms are created in the default site '%s' — create the term first, then localize it with terms_update and site '%s'",
                $defaultSite,
                $site,
            ));
        }

        // CP parity (TermPolicy::store gates the target site): the created
        // term lives in the origin site, so the user must be able to access
        // it. The trait exempts single-site installs and the global default
        // site, keeping common-case behavior identical.
        $this->ensureSiteAccess($user, $defaultSite);

        $blueprint = $taxonomy->termBlueprint();

        $this->rejectUnknownKeys($blueprint, $data);

        // Resolve the slug BEFORE blueprint validation: v6 injects a required
        // 'slug' field into term blueprints, and the CP satisfies it by
        // validating the request's slug value alongside the data — feed the
        // resolved slug in the same way. Our targeted empty-slug error beats
        // the validator's raw "The Slug field is required."
        $slug = $this->resolveSlug($validated['slug'] ?? null, $data);

        // The CP's term store path (TermsController@store, 6.x) passes no rule
        // placeholder replacements — slug uniqueness is its own explicit rule
        // there, and our collision check below covers it.
        $this->validateAgainstBlueprint($blueprint, [...$data, 'slug' => $slug]);

        // The id IS taxonomy::slug, so the collision check doubles as the
        // uniqueness rule — and a collided save would silently OVERWRITE the
        // existing term rather than fail.
        if ($existing = Term::find("{$taxonomyHandle}::{$slug}")) {
            throw new ToolException(sprintf(
                "term '%s' already exists — use terms_update with id '%s'",
                $slug,
                $existing->id(),
            ));
        }

        $term = Term::make()->taxonomy($taxonomyHandle)->slug($slug);
        $localized = $term->in($defaultSite)->data($data);

        // CP parity: created terms carry updated_by/updated_at. save()
        // returns false when a TermCreating/TermSaving listener cancels
        // (approval addons do this) — never report success for it.
        if (! $localized->updateLastModified($user)->save()) {
            throw new ToolException('the save was cancelled by a listener — nothing was created');
        }

        return $this->json([
            'id' => $term->id(),
            'taxonomy' => $taxonomyHandle,
            'slug' => $slug,
            'site' => $defaultSite,
            // Terms have no draft state (spec §4 rows 8-12) — a created term
            // is live immediately.
            ...$this->liveness($localized, self::LIVENESS_CREATED),
        ]);
    }

    private function resolveSlug(?string $slug, array $data): string
    {
        if (! $slug) {
            $title = $data['title'] ?? null;

            $slug = is_string($title) ? $title : '';
        }

        // Term::slug() re-normalizes through Str::slug() on set — language-
        // agnostic, unlike entries' site-language Routable::slug() — so run
        // the exact same call here: the collision check must see what will
        // actually be persisted as the id.
        $slug = Str::slug($slug);

        if ($slug === '') {
            throw new ToolException('pass a slug, or include a title in data so one can be generated');
        }

        return $slug;
    }
}
