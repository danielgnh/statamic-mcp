<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ComparesPatchData;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesAssets;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('assets_update')]
#[Description("Update an asset's metadata (alt text and any custom fields on the container's asset blueprint) with a shallow top-level-key merge of raw data (the assets_get raw shape) — nested structures are replaced wholesale; explicit null clears a field. The file itself is untouched. An update that changes nothing is a no-op. Assets have no draft state: saved metadata is live immediately. Never send augmented data.")]
#[IsIdempotent]
class AssetsUpdate extends Tool
{
    use ComparesPatchData;
    use ResolvesAssets;
    use ValidatesBlueprintData;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'container' => $schema->string()->description('Asset container handle — see statamic_overview.')->required(),
            'path' => $schema->string()->description("Asset path relative to the container root, e.g. 'blog/hero.jpg'.")->required(),
            'data' => $schema->object()->description('Raw metadata to merge, keyed by blueprint field handle, e.g. {"alt": "…"}.')->required(),
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
                'container' => 'required|string',
                'path' => 'required|string',
                'data' => 'required|array',
            ],
            [
                'container.required' => 'Pass an asset container handle, e.g. "images" — see statamic_overview.',
                'data.required' => 'Pass raw metadata to merge, e.g. {"alt": "A description of the image"}.',
            ],
        );

        $handle = $validated['container'];
        $patch = $validated['data'];

        $container = $this->resolveContainer($handle);

        $user = $this->user($request);
        $this->ensurePermission($user, "edit {$handle} assets");

        $asset = $this->resolveAsset($container, $validated['path']);

        // supportsFields: false — assets_get has no fields parameter, the
        // remediation text must not invent one.
        $this->rejectPreviewObjects($patch, 'assets_get', supportsFields: false);

        // Assets always have a blueprint (Statamic falls back to a default
        // one) — unknown keys are rejected so typos never become metadata.
        // 'focus' is exempt: the CP's focal-point editor writes it into data
        // OUTSIDE the blueprint (AssetsController@update merges it around
        // field processing), so a get → edit → update round-trip must carry
        // it (spec §3 focus exception).
        $blueprint = $asset->blueprint();
        $this->rejectUnknownKeys($blueprint, Arr::except($patch, ['focus']));

        $existing = $asset->data()->all();
        $merged = array_merge($existing, $patch);

        // Strict compare over normalized values (T14 pattern): assoc key
        // order is irrelevant, but types matter — loose == would turn an
        // explicit null-clear of a falsy value into a false no-op.
        if ($this->normalize($merged) === $this->normalize($existing)) {
            return $this->json([
                'id' => $asset->id(),
                'path' => $asset->path(),
                'result' => 'no-op — merged data equals current data; nothing saved',
                'cp_edit_url' => $asset->editUrl(),
            ]);
        }

        $this->validateAgainstBlueprint($blueprint, $merged);

        // save() returns false when an AssetSaving listener cancels
        // (approval addons do this) — never report success for it.
        if (! $asset->data($merged)->save()) {
            throw new ToolException('the save was cancelled by a listener — the asset metadata was not updated');
        }

        return $this->json([
            'id' => $asset->id(),
            'path' => $asset->path(),
            'data' => $asset->data()->all(),
            ...$this->liveness($asset, self::LIVENESS_LIVE),
        ]);
    }
}
