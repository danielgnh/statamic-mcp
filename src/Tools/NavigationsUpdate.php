<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ComparesPatchData;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesNavs;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Exception;
use Facades\Statamic\Structures\BranchIdGenerator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Facades\Entry;
use Statamic\Fields\Blueprint;
use Statamic\Support\Arr;

#[Name('navigations_update')]
#[Description("Replace a navigation's tree for one site with the complete tree you send, as the CP's tree save does: send every branch, in order, in the shape navigations_get returns. Keep id on existing branches; omit it on new ones and one is generated. An entry branch links an entry by id, which must exist in one of the navigation's collections, in the same site; a branch without an entry needs a url, a title, or both (a title alone is a text item). data takes the navigation blueprint's fields, validated and stored the way the CP stores them. resolved blocks from navigations_get are read-only and ignored. max_depth and the root rule (with expects_root, the first branch cannot have children) are enforced. An update that changes nothing is a no-op. Navigations have no draft state: the saved tree is live immediately.")]
#[IsIdempotent]
class NavigationsUpdate extends Tool
{
    use ComparesPatchData;
    use ResolvesNavs;
    use ResolvesSites;
    use ValidatesBlueprintData;

    private const array BRANCH_KEYS = ['id', 'entry', 'title', 'url', 'data', 'children'];

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()->description('Navigation handle, e.g. "main".')->required(),
            'tree' => $schema->array()->items($schema->object())->description('The complete new tree: a list of branches {id?, entry?, title?, url?, data?, children?}. An empty list clears the navigation.')->required(),
            'site' => $schema->string()->description('Site handle. Defaults to the default site. Each site has its own tree.'),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        $this->ensureWritesEnabled();

        // 'present' not 'required': an empty list clears the tree, and
        // Laravel's 'required' fails on [].
        $validated = $request->validate(
            [
                'handle' => 'required|string',
                'tree' => 'present|array',
                'site' => 'nullable|string',
            ],
            [
                'handle.required' => 'Pass a navigation handle, e.g. "main".',
                'tree.present' => 'Pass the complete new tree as a list of branches (an empty list clears it) — navigations_get returns the current one.',
            ],
        );

        $handle = $validated['handle'];

        $this->ensureExposed('navigations', $handle);

        $user = $this->user($request);

        $this->ensurePermission($user, "edit {$handle} nav");

        $nav = $this->findNav($handle);

        // A navigation only has trees in its own sites; the trait enforces
        // 'access {site} site' on multisite.
        $site = $this->resolveSite($request, $user, $this->navSites($nav));

        // A navigation without a tree yet gets its first one on this write.
        $tree = $nav->in($site) ?? $nav->makeTree($site);

        $branches = $this->branches($nav, $site, $validated['tree'], 'tree', 1);

        $this->rejectDuplicateIds($branches);

        try {
            $nav->validateTree($branches, $site);
        } catch (Exception $e) {
            throw new ToolException(sprintf(
                "%s — navigation '%s' expects a root page, its first branch: move the children to the top level",
                lcfirst($e->getMessage()),
                $handle,
            ), $e->getCode(), $e);
        }

        if ($this->normalize($branches) === $this->normalize($tree->tree())) {
            return $this->json([
                'handle' => $handle,
                'site' => $site,
                'result' => 'no-op — the tree equals the current tree; nothing saved',
                'cp_edit_url' => $tree->showUrl(),
            ]);
        }

        // save() returns false when a NavTreeSaving listener cancels
        // (approval addons do this) — never report success for it.
        if (! $tree->tree($branches)->save()) {
            throw new ToolException('the save was cancelled by a listener — the navigation was not updated');
        }

        return $this->json([
            'handle' => $handle,
            'site' => $site,
            'tree' => $this->presentBranches($tree->tree()),
            'result' => self::LIVENESS_LIVE,
            // showUrl() is the CP's tree editor; editUrl() is the
            // navigation's settings, which need 'configure navs'.
            'cp_edit_url' => $tree->showUrl(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function branches(NavContract $nav, string $site, mixed $branches, string $path, int $depth): array
    {
        if (! is_array($branches) || ! array_is_list($branches)) {
            throw new ToolException("{$path} must be a list of branches");
        }

        return array_map(
            fn (mixed $branch, int $index): array => $this->branch($nav, $site, $branch, "{$path}.{$index}", $depth),
            $branches,
            array_keys($branches),
        );
    }

    /**
     * One branch in the shape the CP's tree save stores: {id, entry, title,
     * url, data, children}, with empty values left out.
     *
     * @return array<string, mixed>
     */
    private function branch(NavContract $nav, string $site, mixed $branch, string $path, int $depth): array
    {
        if (! is_array($branch) || ($branch !== [] && array_is_list($branch))) {
            throw new ToolException("{$path} must be a branch object: {id?, entry?, title?, url?, data?, children?}");
        }

        // navigations_get's read-only resolved block is ignored, and a null
        // key counts as an absent one.
        $branch = array_filter(Arr::except($branch, 'resolved'), fn (mixed $value) => $value !== null);

        $unknown = array_values(array_diff(array_keys($branch), self::BRANCH_KEYS));

        if ($unknown !== []) {
            $keys = self::BRANCH_KEYS;
            sort($keys);

            throw new ToolException(sprintf(
                'unknown key%s %s in %s — valid branch keys: %s',
                count($unknown) === 1 ? '' : 's',
                implode(', ', $unknown),
                $path,
                implode(', ', $keys),
            ));
        }

        if (($maxDepth = $nav->maxDepth()) && $depth > $maxDepth) {
            throw new ToolException(sprintf(
                "%s is at depth %d — navigation '%s' allows %d level%s (max_depth)",
                $path,
                $depth,
                $nav->handle(),
                $maxDepth,
                $maxDepth === 1 ? '' : 's',
            ));
        }

        [$id, $entry, $title, $url] = array_map(
            fn (string $key): ?string => $this->stringValue($branch, $key, $path),
            ['id', 'entry', 'title', 'url'],
        );

        if ($entry === null && $title === null && $url === null) {
            throw new ToolException("{$path} has no entry, url, or title — link an entry by id, or give a link its url and title (a title alone makes a text item)");
        }

        if ($entry !== null) {
            $this->ensureLinkable($nav, $site, $entry, $path);
        }

        $values = $this->processBranch($nav, $branch, $title, $url, $path);

        $stored = Arr::removeNullValues([
            'id' => $id ?? BranchIdGenerator::generate(),
            'entry' => $entry,
            'title' => Arr::pull($values, 'title'),
            'url' => Arr::pull($values, 'url'),
            'data' => Arr::removeNullValues($values),
        ]);

        $children = $this->branches($nav, $site, data_get($branch, 'children', []), "{$path}.children", $depth + 1);

        return $children === [] ? $stored : [...$stored, 'children' => $children];
    }

    /**
     * @param  array<array-key, mixed>  $branch
     */
    private function stringValue(array $branch, string $key, string $path): ?string
    {
        $value = data_get($branch, $key);

        if ($value !== null && ! is_string($value)) {
            throw new ToolException("{$path}.{$key} must be a string");
        }

        return blank($value) ? null : $value;
    }

    /**
     * Only the entry picker's choices are linkable: the navigation's own
     * collections, and the tree's site unless the navigation selects across
     * sites. A dangling id would silently vanish from the rendered menu.
     */
    private function ensureLinkable(NavContract $nav, string $site, string $id, string $path): void
    {
        $collections = $nav->collections()->map->handle()->sort()->values()->all();
        $linkable = $collections === [] ? '(none)' : implode(', ', $collections);

        $entry = Entry::find($id);

        if (! $entry) {
            throw new ToolException("entry '{$id}' in {$path} not found — drop the branch, or link an existing entry (linkable collections: {$linkable})");
        }

        $collection = $entry->collection()->handle();

        if (! in_array($collection, $collections, true)) {
            throw new ToolException("entry '{$id}' in {$path} is in collection '{$collection}', which navigation '{$nav->handle()}' does not link — linkable collections: {$linkable}");
        }

        if (! $nav->canSelectAcrossSites() && $entry->locale() !== $site) {
            throw new ToolException("entry '{$id}' in {$path} belongs to site '{$entry->locale()}', not '{$site}' — link its '{$site}' localization, which has its own id");
        }
    }

    /**
     * The CP's tree save runs a branch's title, url, and data through the
     * navigation blueprint (title and url ensured as text fields), then
     * stores title and url on the branch and the rest as data. A branch with
     * none of them has nothing to process, like one added from the CP's
     * entry picker without opening its editor.
     *
     * @param  array<string, mixed>  $branch
     * @return array<array-key, mixed>
     */
    private function processBranch(NavContract $nav, array $branch, ?string $title, ?string $url, string $path): array
    {
        $data = data_get($branch, 'data', []);

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ToolException("{$path}.data must be an object keyed by field handle");
        }

        foreach (['title', 'url'] as $key) {
            if (array_key_exists($key, $data)) {
                throw new ToolException("pass {$key} as a branch key, not inside {$path}.data");
            }
        }

        $blueprint = $this->branchBlueprint($nav);

        if ($data !== [] && $blueprint->fields()->except('title', 'url')->all()->isEmpty()) {
            throw new ToolException("navigation '{$nav->handle()}' defines no branch fields — omit {$path}.data");
        }

        $this->rejectUnknownFields($blueprint->fields(), $data, "{$path}.data", except: ['title', 'url']);

        $values = [...array_filter(['title' => $title, 'url' => $url], filled(...)), ...$data];

        if ($values === []) {
            return [];
        }

        // processAgainstBlueprint() names the field, not the branch.
        try {
            return $this->processAgainstBlueprint($blueprint, $values, array_keys($values));
        } catch (ToolException $e) {
            throw new ToolException("{$path}: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    private function branchBlueprint(NavContract $nav): Blueprint
    {
        return $nav->blueprint()
            ->ensureField('title', ['type' => 'text'])
            ->ensureField('url', ['type' => 'text']);
    }

    /**
     * @param  list<array<string, mixed>>  $branches
     */
    private function rejectDuplicateIds(array $branches): void
    {
        $ids = $this->branchIds($branches, 'tree');

        foreach (array_diff_key($ids, array_unique($ids)) as $path => $id) {
            $first = array_search($id, $ids, true);

            throw new ToolException("branch id '{$id}' is used by {$first} and {$path} — every branch needs its own id; omit id on new branches");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $branches
     * @return array<string, mixed> branch path => branch id, depth-first
     */
    private function branchIds(array $branches, string $path): array
    {
        $ids = [];

        foreach ($branches as $index => $branch) {
            $ids["{$path}.{$index}"] = $branch['id'];
            $ids += $this->branchIds(data_get($branch, 'children', []), "{$path}.{$index}.children");
        }

        return $ids;
    }
}
