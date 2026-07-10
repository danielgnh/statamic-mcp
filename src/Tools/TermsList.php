<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

#[Name('terms_list')]
#[Description('List taxonomy terms with summary columns only (id, title, slug, url, updated_at) — never field data; use terms_get for a term\'s field data. Terms have no publish state, so there is no status filter. Paginated: the response carries total and next_page (null on the last page). Ordering is deterministic: alphabetical by title, with id as tiebreaker.')]
#[IsReadOnly]
class TermsList extends Tool
{
    use ResolvesSites;

    public function schema(JsonSchema $schema): array
    {
        return [
            'taxonomy' => $schema->string()->description('Taxonomy handle, e.g. "tags" — see statamic_overview for what is available.')->required(),
            'site' => $schema->string()->description('Site handle. Defaults to the default site.'),
            'search' => $schema->string()->description('Only terms whose title contains this text.'),
            'limit' => $schema->integer()->description('Page size. Defaults to the server default (25); hard-capped at 100.'),
            'page' => $schema->integer()->default(1)->description('Page number, starting at 1.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(
            [
                'taxonomy' => 'required|string',
                'site' => 'nullable|string',
                'search' => 'nullable|string',
                'limit' => 'nullable|integer|min:1',
                'page' => 'nullable|integer|min:1',
            ],
            ['taxonomy.required' => 'Pass a taxonomy handle, e.g. "tags" — see statamic_overview.'],
        );

        $taxonomy = $validated['taxonomy'];
        $this->ensureExposed('taxonomies', $taxonomy);

        $user = $this->user($request);
        $this->ensurePermission($user, "view {$taxonomy} terms");

        // Terms only exist in the taxonomy's own configured sites.
        $site = $this->resolveSite($request, $user, Taxonomy::findByHandle($taxonomy)->sites());

        $query = Term::query()
            ->where('taxonomy', $taxonomy)
            ->where('site', $site)
            // Deterministic order — offset pagination without a stable order
            // repeats/skips items between calls (entries_list, Task 10).
            ->orderBy('title')
            ->orderBy('id');

        if ($search = $validated['search'] ?? null) {
            $query->where('title', 'like', '%'.$search.'%');
        }

        $perPage = min((int) ($validated['limit'] ?? config('statamic.mcp.per_page', 25)), 100);
        $perPage = max($perPage, 1);
        $page = max((int) ($validated['page'] ?? 1), 1);

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        return $this->json([
            'taxonomy' => $taxonomy,
            'site' => $site,
            'total' => $paginated->total(),
            'page' => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'next_page' => $paginated->hasMorePages() ? $paginated->currentPage() + 1 : null,
            'terms' => collect($paginated->items())->map(fn ($term) => [
                'id' => $term->id(),
                'title' => $term->title(), // value('title') under the hood — recurses to the default locale
                'slug' => $term->slug(),
                'url' => $term->url(),
                // value('updated_at') so the fallback chain matches title()
                // (a localized view inherits the origin's timestamp) — never
                // fileLastModified(), which breaks on items that were never
                // written to disk (tests). value() never touches the file.
                'updated_at' => ($timestamp = $term->value('updated_at'))
                    ? Carbon::createFromTimestamp($timestamp, config('app.timezone'))->toIso8601String()
                    : null,
            ])->values()->all(),
        ]);
    }
}
