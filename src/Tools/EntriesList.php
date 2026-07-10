<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

#[Name('entries_list')]
#[Description('List entries in a collection — summary columns only (id, title, slug, status, url, date, updated_at); field data is never included, use entries_get for that. Paginated: the response carries total, total_pages, and next_page (null on the last page). Ordering is deterministic: dated collections newest-first by date, others alphabetical by title, with id as tiebreaker.')]
#[IsReadOnly]
class EntriesList extends Tool
{
    use ResolvesSites;

    public function schema(JsonSchema $schema): array
    {
        return [
            'collection' => $schema->string()->description('Collection handle — see statamic_overview for what is available.')->required(),
            'site' => $schema->string()->description('Site handle. Defaults to the default site.'),
            'status' => $schema->string()->enum(['published', 'draft', 'scheduled'])->description('Filter by status.'),
            'search' => $schema->string()->description('Only entries whose title contains this text.'),
            'limit' => $schema->integer()->description('Page size. Defaults to the server default (25); hard-capped at 100.'),
            'page' => $schema->integer()->default(1)->description('Page number, starting at 1.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(
            [
                'collection' => 'required|string',
                'site' => 'nullable|string',
                'status' => 'nullable|string|in:published,draft,scheduled',
                'search' => 'nullable|string',
                'limit' => 'nullable|integer|min:1',
                'page' => 'nullable|integer|min:1',
            ],
            [
                'collection.required' => 'Pass a collection handle, e.g. "blog" — see statamic_overview.',
                'status.in' => 'status must be one of: published, draft, scheduled.',
            ],
        );

        $collection = $validated['collection'];
        $this->ensureExposed('collections', $collection);

        $user = $this->user($request);
        $this->ensurePermission($user, "view {$collection} entries");

        $site = $this->resolveSite($request, $user);

        $perPage = min((int) ($validated['limit'] ?? config('statamic.mcp.per_page', 25)), 100);
        $perPage = max($perPage, 1);
        $page = max((int) ($validated['page'] ?? 1), 1);

        $dated = Collection::findByHandle($collection)->dated();

        $query = Entry::query()
            ->where('collection', $collection)
            ->where('site', $site);

        if ($status = $validated['status'] ?? null) {
            $query->whereStatus($status); // v6: never where('status', ...)
        }

        if ($search = $validated['search'] ?? null) {
            $query->where('title', 'like', '%'.$search.'%');
        }

        $total = (clone $query)->count();
        $totalPages = max((int) ceil($total / $perPage), 1);

        // Deterministic order — the Stache builder's natural order is traversal
        // order (date-ASCENDING for dated collections), and offset pagination
        // without a stable order repeats/skips entries between calls.
        $dated
            ? $query->orderByDesc('date')->orderBy('id')
            : $query->orderBy('title')->orderBy('id');

        $entries = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return $this->json([
            'entries' => $entries->map(fn ($entry) => [
                'id' => $entry->id(),
                'title' => $entry->value('title'), // value() recurses the full origin chain
                'slug' => $entry->slug(),
                'status' => $entry->status(),
                'url' => $entry->url(),
                'date' => $dated ? $entry->date()?->toIso8601String() : null,
                'updated_at' => $entry->lastModified()?->toIso8601String(),
            ])->values()->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'next_page' => $page < $totalPages ? $page + 1 : null,
            ],
        ]);
    }
}
