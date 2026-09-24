<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesNavs;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Fields\Field;

#[Name('navigations_get')]
#[Description("Read a navigation (a menu) for one site. tree is the raw shape navigations_update accepts: a list of branches {id, entry?, title?, url?, data?, children?}. An entry branch links an entry by id, and a title on it replaces the entry's title in the menu; a branch without an entry is a link (url, usually with a title) or a text item (title only). Entry branches also carry resolved {title, url, status}: the entry's current values, read-only and ignored by navigations_update. status 'missing' means the entry is gone, and navigations_update rejects that branch, so drop it. The response also holds what a write must respect: max_depth (null = unlimited), expects_root (the first branch is the root page and cannot have children), collections (the ones entry branches may link), and fields (the navigation blueprint's fields, which data is keyed by).")]
#[IsReadOnly]
class NavigationsGet extends Tool
{
    use ResolvesNavs;
    use ResolvesSites;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()->description('Navigation handle, e.g. "main" (statamic_overview lists them).')->required(),
            'site' => $schema->string()->description('Site handle. Defaults to the default site. Each site has its own tree.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(
            [
                'handle' => 'required|string',
                'site' => 'nullable|string',
            ],
            ['handle.required' => 'Pass a navigation handle, e.g. "main".'],
        );

        $handle = $validated['handle'];

        $this->ensureExposed('navigations', $handle);

        $user = $this->user($request);

        $this->ensurePermission($user, "view {$handle} nav");

        $nav = $this->findNav($handle);

        // A navigation only has trees in its own sites; the trait enforces
        // 'access {site} site' for non-default sites on multisite.
        $site = $this->resolveSite($request, $user, $this->navSites($nav));

        // makeTree() is the empty stand-in for a navigation without a tree
        // yet, never saved here. Neither is NavTree::ensureBranchIds() called,
        // which saves: branches without an id come back without one.
        $tree = $nav->in($site) ?? $nav->makeTree($site);

        return $this->json([
            'handle' => $handle,
            'title' => $nav->title(),
            'site' => $site,
            'max_depth' => $nav->maxDepth(),
            'expects_root' => $nav->expectsRoot(),
            'collections' => $nav->collections()->map->handle()->values()->all(),
            'fields' => $this->branchFields($nav),
            'tree' => $this->presentBranches($tree->tree()),
            'cp_edit_url' => $tree->showUrl(),
        ]);
    }

    /**
     * The blueprint fields branch data is keyed by. title and url are branch
     * keys of their own, never data, even when the blueprint defines them.
     *
     * @return list<array<string, mixed>>
     */
    private function branchFields(NavContract $nav): array
    {
        return $nav->blueprint()->fields()->all()
            ->reject(fn (Field $field) => in_array($field->handle(), ['title', 'url'], true))
            ->map(fn (Field $field) => array_filter([
                'handle' => $field->handle(),
                'type' => $field->type(),
                'required' => $field->isRequired(),
                'options' => $field->get('options'),
                'instructions' => $field->get('instructions'),
            ], fn (mixed $value) => $value !== null))
            ->values()
            ->all();
    }
}
