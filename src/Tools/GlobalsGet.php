<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Globals\Variables;
use Statamic\Facades\GlobalSet;
use Statamic\Globals\GlobalSet as GlobalSetInstance;

#[Name('globals_get')]
#[Description("Read global variables in the raw round-trippable shape for globals_update. Pass handle for one set, or omit it to get every set you can access (others are silently omitted). With site, data holds that site's localization; when the set localizes through an origin site, inherited holds the values falling back from it. Globals have no publish state — values are always live.")]
#[IsReadOnly]
class GlobalsGet extends Tool
{
    use ResolvesSites;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()->description('Global set handle, e.g. "settings". Omit to list every readable set.'),
            'site' => $schema->string()->description('Site handle. Defaults to the default site.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'handle' => 'nullable|string',
            'site' => 'nullable|string',
        ]);

        $user = $this->user($request);

        if ($handle = $validated['handle'] ?? null) {
            return $this->one($request, $user, $handle);
        }

        return $this->all($request, $user);
    }

    private function one(Request $request, UserContract $user, string $handle): Response
    {
        $this->ensureExposed('globals', $handle);

        $this->ensurePermission($user, "edit {$handle} globals");

        $set = GlobalSet::findByHandle($handle);

        if ($set === null) {
            return $this->notFound('global', $handle, $this->exposedHandles('globals'));
        }

        $site = $this->resolveSite($request, $user, $set->sites());

        return $this->json([
            'handle' => $handle,
            'title' => $set->title(),
            'site' => $site,
            ...$this->variablesPayload($set->in($site) ?? $set->makeLocalization($site)),
        ]);
    }

    private function all(Request $request, UserContract $user): Response
    {
        $site = $this->resolveSite($request, $user);

        $globals = collect($this->exposedHandles('globals'))
            ->sort()
            ->filter(fn (string $handle) => $this->can($user, "edit {$handle} globals"))
            ->map(fn (string $handle) => GlobalSet::findByHandle($handle))
            ->filter(fn ($set) => $set instanceof GlobalSetInstance)
            ->filter(fn ($set) => $set->sites()->contains($site))
            ->map(fn ($set) => [
                'handle' => $set->handle(),
                'title' => $set->title(),
                ...$this->variablesPayload($set->in($site) ?? $set->makeLocalization($site)),
            ])
            ->values()
            ->all();

        return $this->json([
            'site' => $site,
            'globals' => $globals,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function variablesPayload(Variables $variables): array
    {
        $data = $variables->data()->all();

        $payload = ['data' => $data];

        if ($variables->hasOrigin()) {
            $origin = $variables->origin();

            $payload['origin_site'] = $origin->locale();
            $payload['inherited'] = array_diff_key($origin->values()->all(), $data);
            $payload['note'] = sprintf(
                "data = this site's own values (the round-trippable shape for globals_update with site '%s'); inherited = values falling back from the origin site",
                $variables->locale(),
            );
        }

        $payload['cp_edit_url'] = $variables->editUrl();

        return $payload;
    }
}
