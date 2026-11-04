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
#[Description("Update a global set's variables with a shallow top-level-key merge of raw data (the globals_get raw shape) — nested structures are replaced wholesale; explicit null clears a variable. A Replicator or Grid field takes an object keyed by set id that merges into just those sets, or a list of sets in their order where an item with a stored id merges into that set, and a Bard, Markdown, text, or textarea field takes {\"find\": \"...\", \"replace\": \"...\"} to change one piece of its text, as in entries_update. A new set whose id is blank, 0, or shared with another new set gets a fresh id; globals_get shows the stored ids. With site, writes that site's localization, creating it transparently on first write. An update that changes nothing is a no-op. Globals have no draft state: saved values are live immediately. Never send augmented data.")]
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

        $this->ensurePermission($user, "edit {$handle} globals");

        $set = GlobalSet::findByHandle($handle);

        if ($set === null) {
            return $this->notFound('global', $handle, $this->exposedHandles('globals'));
        }

        $site = $this->resolveSite($request, $user, $set->sites());

        $variables = $set->in($site) ?? $set->makeLocalization($site);

        $this->rejectPreviewObjects($patch, 'globals_get', supportsFields: false);

        $this->rejectReservedKeys($patch);

        $blueprint = $set->blueprint();

        $existing = $variables->data()->all();

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

        if ($this->normalize($merged) === $this->normalize($existing)) {
            return $this->json([
                'handle' => $handle,
                'site' => $site,
                'result' => 'no-op — merged data equals current data; nothing saved',
                'cp_edit_url' => $variables->editUrl(),
            ]);
        }

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
