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

#[Name('globals_get')]
#[Description("Read global variables in the raw round-trippable shape for globals_update. Pass handle for one set, or omit it to get every set you can access (others are silently omitted). With site, data holds that site's localization; when the set localizes through an origin site, inherited holds the values falling back from it. Globals have no publish state — values are always live.")]
#[IsReadOnly]
class GlobalsGet extends Tool
{
    use ResolvesSites;

    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()->description('Global set handle, e.g. "settings". Omit to list every readable set.'),
            'site' => $schema->string()->description('Site handle. Defaults to the default site.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        // laravel/mcp doesn't enforce the JSON schema server-side (T10) —
        // validate shapes before touching them.
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
        // Missing and exists-but-unexposed are indistinguishable by design;
        // the error lists only exposed handles (spec §4 row 13).
        $this->ensureExposed('globals', $handle);

        // v6 has no 'view {handle} globals' permission — edit is the only
        // per-set permission and the CP gates viewing on it too.
        $this->ensurePermission($user, "edit {$handle} globals");

        $set = GlobalSet::findByHandle($handle);

        // Global sets only exist in their own configured sites; the trait
        // enforces 'access {site} site' for non-default sites on multisite.
        $site = $this->resolveSite($request, $user, $set->sites());

        return $this->json([
            'handle' => $handle,
            'title' => $set->title(),
            'site' => $site,
            ...$this->variablesPayload($set->in($site)),
        ]);
    }

    private function all(Request $request, UserContract $user): Response
    {
        // No valid-sites argument: with no specific set in play, any
        // configured site is valid (sets not in it are filtered below).
        $site = $this->resolveSite($request, $user);

        $globals = collect($this->exposedHandles('globals'))
            ->sort()
            // Exposed but not editable by this user → silently omitted,
            // exactly like statamic_overview (spec §4 row 13).
            ->filter(fn (string $handle) => $this->can($user, "edit {$handle} globals"))
            ->map(fn (string $handle) => GlobalSet::findByHandle($handle))
            // Sets not configured for the requested site are silently omitted too.
            ->filter(fn ($set) => $set->sites()->contains($site))
            ->map(fn ($set) => [
                'handle' => $set->handle(),
                'title' => $set->title(),
                ...$this->variablesPayload($set->in($site)),
            ])
            ->values()
            ->all();

        return $this->json([
            'site' => $site,
            'globals' => $globals,
        ]);
    }

    /**
     * The raw round-trippable shape for globals_update: this site's own data
     * bucket. A localization configured with an origin site falls back to it
     * for everything not overridden locally (vendor HasOrigin) — represented
     * honestly as inherited, mirroring terms_get. Variables carry no
     * Statamic-managed metadata (no TracksLastModified), so data is returned
     * verbatim.
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
