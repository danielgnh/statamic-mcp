<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Support\Sets;
use Danielgnh\StatamicMcp\Tools\Concerns\ComparesPatchData;
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
#[Description("Create a taxonomy term from raw field data (get the shape from blueprints_get; never send augmented data). Slug is generated from data.title when omitted. A new Replicator or Grid set whose id is blank, 0, or shared with another new set gets a fresh id; terms_get shows the stored ids. Terms are created in the taxonomy's origin site (its first configured site) — localize afterwards with terms_update and its site parameter. Terms have no draft state: a created term is live immediately.")]
class TermsCreate extends Tool
{
    use ComparesPatchData;
    use ResolvesSites;
    use ValidatesBlueprintData;

    #[\Override]
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
        $this->ensureWritesEnabled();

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

        if (! $taxonomy) {
            throw new ToolException($this->notFoundMessage('taxonomy', $taxonomyHandle, $this->exposedHandles('taxonomies')));
        }

        $defaultSite = $taxonomy->sites()->first();

        if (($site = $validated['site'] ?? null) && $site !== $defaultSite) {
            throw new ToolException(sprintf(
                "terms are created in the default site '%s' — create the term first, then localize it with terms_update and site '%s'",
                $defaultSite,
                $site,
            ));
        }

        $this->ensureSiteAccess($user, $defaultSite);

        $blueprint = $taxonomy->termBlueprint();

        if (array_key_exists('slug', $data)) {
            throw new ToolException('pass slug as a top-level parameter, not inside data');
        }

        $this->rejectUnknownKeys($blueprint, $data);

        $data = Sets::withoutUnaddressableIds($blueprint->fields(), $data);

        $slug = $this->resolveSlug($validated['slug'] ?? null, $data);

        $data = $this->processAgainstBlueprint($blueprint, [...$data, 'slug' => $slug], array_keys($data));

        if ($existing = Term::find("{$taxonomyHandle}::{$slug}")) {
            throw new ToolException(sprintf(
                "term '%s' already exists — use terms_update with id '%s'",
                $slug,
                $existing->id(),
            ));
        }

        $term = Term::make()->taxonomy($taxonomyHandle)->slug($slug);
        $localized = $term->in($defaultSite)->data($data);

        if (! $localized->updateLastModified($user)->save()) {
            throw new ToolException('the save was cancelled by a listener — nothing was created');
        }

        return $this->json([
            'id' => $term->id(),
            'taxonomy' => $taxonomyHandle,
            'slug' => $slug,
            'site' => $defaultSite,
            ...$this->liveness($localized, self::LIVENESS_CREATED),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSlug(?string $slug, array $data): string
    {
        if (! $slug) {
            $title = $data['title'] ?? null;

            $slug = is_string($title) ? $title : '';
        }

        $slug = Str::slug($slug);

        if ($slug === '') {
            throw new ToolException('pass a slug, or include a title in data so one can be generated');
        }

        return $slug;
    }
}
