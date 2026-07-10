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
use Statamic\Facades\GlobalSet;

#[Name('globals_update')]
#[Description("Update a global set's variables with a shallow top-level-key merge of raw data (the globals_get raw shape) — nested structures are replaced wholesale; explicit null clears a variable. With site, writes that site's localization, creating it transparently on first write. An update that changes nothing is a no-op. Globals have no draft state: saved values are live immediately. Never send augmented data.")]
#[IsIdempotent]
class GlobalsUpdate extends Tool
{
    use ComparesPatchData;
    use ResolvesSites;
    use ValidatesBlueprintData;

    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()->description('Global set handle, e.g. "settings".')->required(),
            'data' => $schema->object()->description('Raw variables to merge, keyed by field handle.')->required(),
            'site' => $schema->string()->description('Site handle. Defaults to the default site. A missing localization is created on first write.'),
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
                'handle' => 'required|string',
                'data' => 'required|array',
                'site' => 'nullable|string',
            ],
            [
                'handle.required' => 'Pass a global set handle, e.g. "settings".',
                'data.required' => 'Pass raw variables to merge, e.g. {"site_name": "Acme"}.',
            ],
        );

        $handle = $validated['handle'];
        $patch = $validated['data'];

        $this->ensureExposed('globals', $handle);

        $user = $this->user($request);

        // v6 has no 'view {handle} globals' permission — edit is the only
        // per-set permission (spec §4 row 14).
        $this->ensurePermission($user, "edit {$handle} globals");

        $set = GlobalSet::findByHandle($handle);

        // ensureExposed() checked the handle against GlobalSet::all(), but
        // the index and the item fetch can drift (a deploy deleting the set
        // under a warm Stache) — report the indistinguishable not-found
        // shape rather than fatal on the null.
        if ($set === null) {
            return $this->notFound('global', $handle, $this->exposedHandles('globals'));
        }

        // Global sets only exist in their own configured sites; the trait
        // enforces 'access {site} site' for non-default sites on multisite.
        $site = $this->resolveSite($request, $user, $set->sites());

        // v6: in() returns the existing localization, or a fresh unsaved one
        // via makeLocalization(); saving below persists it — the transparent-
        // creation rule (spec §4 row 14). The ?? branch is belt-and-braces:
        // in() only returns null for sites outside $set->sites(), which
        // resolveSite() already rejected.
        $variables = $set->in($site) ?? $set->makeLocalization($site);

        // supportsFields: false — globals_get has no fields parameter, the
        // remediation text must not invent one.
        $this->rejectPreviewObjects($patch, 'globals_get', supportsFields: false);

        // Statamic-managed front-matter is never writable via data — enforced
        // even without a blueprint: the global-variables Stache store strips
        // an 'origin' data key on rehydration, so it would be silent data loss.
        $this->rejectReservedKeys($patch);

        // Sets without a blueprint accept free-form variables (Statamic's own
        // fallback blueprint is generated from current values, so it must not
        // be used to reject new keys); with one, unknown keys are rejected.
        $blueprint = $set->blueprint();

        if ($blueprint) {
            $this->rejectUnknownKeys($blueprint, $patch);
        }

        // The merge basis is the SITE's own data bucket — the exact
        // round-trippable shape globals_get returns for that site.
        $existing = $variables->data()->all();
        $merged = array_merge($existing, $patch);

        // Strict compare over normalized values (T14 pattern): assoc key
        // order is irrelevant, but types matter — loose == would turn an
        // explicit null-clear of a falsy variable into a false no-op.
        if ($this->normalize($merged) === $this->normalize($existing)) {
            return $this->json([
                'handle' => $handle,
                'site' => $site,
                'result' => 'no-op — merged data equals current data; nothing saved',
                'cp_edit_url' => $variables->editUrl(),
            ]);
        }

        // Validate the EFFECTIVE values (origin-site values under the local
        // overrides, mirroring the CP's full-form submit) so a partial
        // localized patch never false-fails required fields — only the local
        // bucket is stored.
        if ($blueprint) {
            $this->validateAgainstBlueprint($blueprint, array_merge(
                $variables->hasOrigin() ? $variables->origin()->values()->all() : [],
                $merged,
            ));
        }

        // Variables carry no Statamic-managed metadata (no TracksLastModified
        // in v6 — CP parity: the CP update path stamps nothing either).
        // save() returns false when a GlobalVariablesSaving listener cancels
        // (approval addons do this) — never report success for it.
        if (! $variables->data($merged)->save()) {
            throw new ToolException('the save was cancelled by a listener — the global variables were not updated');
        }

        // Globals have no draft state (spec §4 rows 13-14) — updates are live
        // immediately.
        return $this->json([
            'handle' => $handle,
            'site' => $site,
            'data' => $variables->data()->all(),
            ...$this->liveness($variables, self::LIVENESS_LIVE),
        ]);
    }
}
