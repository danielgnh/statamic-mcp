<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Support\TextEdit;
use Danielgnh\StatamicMcp\Tools\Concerns\ComparesPatchData;
use Danielgnh\StatamicMcp\Tools\Concerns\MergesSets;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Facades\GlobalSet;

#[Name('globals_update')]
#[Description("Update a global set's variables with a shallow top-level-key merge of raw data (the globals_get raw shape) — nested structures are replaced wholesale; explicit null clears a variable. A Replicator or Grid field takes an object keyed by set id that merges into just those sets, or a list of sets in their order where an item with a stored id merges into that set, and a Bard, Markdown, text, or textarea field takes {\"find\": \"...\", \"replace\": \"...\"} to change one piece of its text, as in entries_update. With site, writes that site's localization, creating it transparently on first write. An update that changes nothing is a no-op. Globals have no draft state: saved values are live immediately. Never send augmented data.")]
class GlobalsUpdate extends Tool
{
    use ComparesPatchData;
    use MergesSets;
    use ResolvesSites;
    use ValidatesBlueprintData;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()->description('Global set handle, e.g. "settings".')->required(),
            'data' => $schema->object()->description('Raw variables to merge, keyed by field handle. A Replicator or Grid variable also takes {"<set id>": {...}} to merge into single sets, or a list of [{"id": "<set id>"}, ...] with new sets where they go. A Bard, Markdown, text, or textarea field also takes {"find": "...", "replace": "..."} to change one piece of its text.')->required(),
            'site' => $schema->string()->description('Site handle. Defaults to the default site. A missing localization is created on first write.'),
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
        // per-set permission.
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
        // enforces 'access {site} site' on multisite.
        $site = $this->resolveSite($request, $user, $set->sites());

        // v6: in() returns the existing localization, or a fresh unsaved one
        // via makeLocalization(); saving below persists it — the transparent-
        // creation rule.
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

        // The merge basis is the SITE's own data bucket — the exact
        // round-trippable shape globals_get returns for that site.
        $existing = $variables->data()->all();

        // Sets merge into, and the patch validates against, the EFFECTIVE
        // values (origin-site values under the local overrides, mirroring
        // the CP's full-form submit) so a partial localized patch never
        // false-fails required fields — only the local bucket is stored.
        $effective = [
            ...($variables->hasOrigin() ? $variables->origin()->values()->all() : []),
            ...$existing,
        ];

        if ($blueprint) {
            $patch = $this->mergeSetPatches($blueprint, $patch, $effective);

            $patch = $this->processAgainstBlueprint($blueprint, [...$effective, ...$patch], array_keys($patch));
        } else {
            $this->rejectSetPatchesWithoutBlueprint($patch, $effective);
        }

        $merged = array_merge($existing, $patch);

        // Strict compare over normalized values: assoc key
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

        // Variables carry no Statamic-managed metadata (no TracksLastModified
        // in v6 — CP parity: the CP update path stamps nothing either).
        // save() returns false when a GlobalVariablesSaving listener cancels
        // (approval addons do this) — never report success for it.
        if (! $variables->data($merged)->save()) {
            throw new ToolException('the save was cancelled by a listener — the global variables were not updated');
        }

        return $this->json([
            'handle' => $handle,
            'site' => $site,
            ...$this->liveness($variables, self::LIVENESS_LIVE),
        ]);
    }

    /**
     * Without a blueprint there are no fieldtypes, so nothing says which
     * variable is a Replicator — an object sent for a stored list would be
     * saved as is, and the agent's merge silently turned into a replacement.
     *
     * @param  array<string, mixed>  $patch
     * @param  array<string, mixed>  $current
     */
    private function rejectSetPatchesWithoutBlueprint(array $patch, array $current): void
    {
        foreach ($patch as $handle => $value) {
            if (TextEdit::isEdit($value)) {
                throw new ToolException("variable {$handle} takes {\"find\": \"...\", \"replace\": \"...\"} only on a global set with a blueprint, where its field type says it holds text — send its new value");
            }

            if (is_array($value) && ! array_is_list($value) && is_array($current[$handle] ?? null) && array_is_list($current[$handle])) {
                throw new ToolException("variable {$handle} is a list, and without a blueprint nothing says it holds sets to merge into by id — send the complete list");
            }
        }
    }
}
