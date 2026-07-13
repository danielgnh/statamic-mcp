<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesAssets;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Contracts\Assets\QueryBuilder;
use Statamic\Facades\Asset;

#[Name('assets_list')]
#[Description('List assets in a container — summary columns only (id, path, basename, folder, url, is_image, size, dimensions, alt); use assets_get for full metadata. Optionally filter to a folder subtree. Paginated: the response carries total, total_pages, and next_page (null on the last page); ordered by path.')]
#[IsReadOnly]
class AssetsList extends Tool
{
    use ResolvesAssets;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'container' => $schema->string()->description('Asset container handle — see statamic_overview for what is available.')->required(),
            'folder' => $schema->string()->description("Only assets under this folder (subtree), e.g. 'blog' or 'blog/2026'."),
            'limit' => $schema->integer()->description('Page size. Defaults to the server default (25); hard-capped at 100.'),
            'page' => $schema->integer()->default(1)->description('Page number, starting at 1.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(
            [
                'container' => 'required|string',
                'folder' => 'nullable|string',
                'limit' => 'nullable|integer|min:1',
                'page' => 'nullable|integer|min:1',
            ],
            ['container.required' => 'Pass an asset container handle, e.g. "images" — see statamic_overview.'],
        );

        $handle = $validated['container'];
        $this->resolveContainer($handle);

        $user = $this->user($request);
        $this->ensurePermission($user, "view {$handle} assets");

        $folder = $this->normalizeFolder($validated['folder'] ?? null);

        $perPage = min((int) ($validated['limit'] ?? config('statamic.mcp.per_page', 25)), 100);
        $perPage = max($perPage, 1);
        $page = max((int) ($validated['page'] ?? 1), 1);

        /** @var QueryBuilder $query */
        $query = Asset::query()->where('container', $handle);

        if ($folder !== null) {
            // Subtree filter: the folder and everything nested below it.
            // LIKE treats % and _ as wildcards — escape them so the filter
            // is exact (backslashes are already rejected by normalizeFolder).
            $query->where('path', 'like', str_replace(['%', '_'], ['\%', '\_'], $folder).'/%');
        }

        $total = (clone $query)->count();
        $totalPages = max((int) ceil($total / $perPage), 1);

        // Deterministic order — offset pagination without a stable order
        // repeats/skips items between calls (same rule as entries_list).
        $assets = $query->orderBy('path')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return $this->json([
            'assets' => $assets->map(fn ($asset) => $this->assetSummary($asset))->values()->all(),
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
