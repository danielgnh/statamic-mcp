<?php

namespace Danielgnh\StatamicMcp\Support;

use Danielgnh\StatamicMcp\Rules\WithoutTemplateSyntax;
use Statamic\Contracts\Addons\Settings;
use Statamic\Facades\Addon;
use Statamic\Facades\Blueprint;
use Statamic\Facades\GlobalSet;
use Statamic\Fields\Blueprint as BlueprintInstance;
use Statamic\Globals\GlobalSet as GlobalSetInstance;
use UnexpectedValueException;

class AgentGuidelines
{
    public function site(): ?string
    {
        return $this->text(data_get($this->forAgents(), 'site'));
    }

    /**
     * @param  'collections'|'taxonomies'|'globals'|'forms'|'navigations'  $type
     */
    public function for(string $type, string $handle): ?string
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = data_get($this->forAgents(), 'resources', []);

        $texts = collect($rows)
            ->filter(fn (array $row) => data_get($row, 'enabled', true) !== false)
            ->filter(fn (array $row) => in_array($handle, (array) data_get($row, $type), true))
            ->map(fn (array $row) => $this->text(data_get($row, 'guidelines')))
            ->filter();

        return $texts->isEmpty() ? null : $texts->implode("\n\n");
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return (array) data_get($this->settings()->raw(), 'guidelines', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function wellFormedValues(): array
    {
        return $this->withWellFormedRows($this->values());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        $this->settings()->set('guidelines', $values)->save();
    }

    public function isEmpty(): bool
    {
        return $this->isBlank($this->values());
    }

    public function blueprint(): BlueprintInstance
    {
        return Blueprint::make('mcp_guidelines')->setContents($this->contents());
    }

    public function leftoverSet(): ?GlobalSetInstance
    {
        $set = GlobalSet::find((string) config('statamic.mcp.guidelines', 'guidelines'));

        if (! $set instanceof GlobalSetInstance) {
            return null;
        }

        $fields = $set->blueprint()?->fields()->all()->keys()->sort()->values()->all();

        return $fields === ['resources', 'site'] ? $set : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function leftoverValues(): array
    {
        $set = $this->leftoverSet();

        return $set?->in((string) $set->sites()->first())?->data()->only(['site', 'resources'])->all() ?? [];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withWellFormedRows(array $values): array
    {
        if (! isset($values['resources'])) {
            return $values;
        }

        $rows = array_filter((array) $values['resources'], is_array(...));

        if ($rows !== $values['resources']) {
            report(new UnexpectedValueException("The resources in the MCP guidelines must be a list of rows. Anything else is ignored until it's fixed under Tools → MCP → Guidelines or in the addon's settings file."));
        }

        return [...$values, 'resources' => array_values(array_map(fn (array $row) => $row + ['type' => 'resource'], $rows))];
    }

    private function settings(): Settings
    {
        return Addon::get('danielgnh/statamic-mcp')->settings();
    }

    /**
     * @return array<string, mixed>
     */
    private function forAgents(): array
    {
        return rescue(function () {
            $values = $this->values();

            return $this->withWellFormedRows($this->isBlank($values) ? $this->leftoverValues() : $values);
        }, []);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function isBlank(array $values): bool
    {
        return blank(data_get($values, 'site')) && blank(data_get($values, 'resources'));
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && filled(trim($value)) ? trim($value) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function contents(): array
    {
        $plain = 'new '.WithoutTemplateSyntax::class;

        return [
            'tabs' => [
                'main' => [
                    'display' => __('Main'),
                    'sections' => [
                        [
                            'fields' => [
                                ['handle' => 'site', 'field' => [
                                    'type' => 'markdown',
                                    'display' => __('Site'),
                                    'instructions' => __('For every agent, in everything it writes: voice and tone, words to use or avoid, how formal to be. Agents read this first, with statamic_overview.'),
                                    'validate' => [$plain],
                                ]],
                                ['handle' => 'resources', 'field' => [
                                    'type' => 'replicator',
                                    'display' => __('Collections and taxonomies'),
                                    'instructions' => __('How an entry or term is put together: which blocks open it, which never repeat, how many a page usually has, which existing entry to follow. An agent gets a row with every blueprint of the collections and taxonomies it names, through blueprints_get. A rule about one field or block belongs in that blueprint instead.'),
                                    'button_label' => __('Add guidelines'),
                                    'sets' => [
                                        'main' => [
                                            'display' => __('Main'),
                                            'sets' => [
                                                'resource' => [
                                                    'display' => __('Guidelines'),
                                                    'instructions' => __('One row per group of collections and taxonomies that share the same rules.'),
                                                    'fields' => [
                                                        ['handle' => 'collections', 'field' => ['type' => 'collections', 'display' => __('Collections'), 'mode' => 'select', 'width' => 50]],
                                                        ['handle' => 'taxonomies', 'field' => ['type' => 'taxonomies', 'display' => __('Taxonomies'), 'mode' => 'select', 'width' => 50]],
                                                        ['handle' => 'guidelines', 'field' => ['type' => 'markdown', 'display' => __('Guidelines'), 'validate' => ['required', $plain]]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
