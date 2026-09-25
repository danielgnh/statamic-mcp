<?php

namespace Danielgnh\StatamicMcp\Support;

use Danielgnh\StatamicMcp\Rules\WithoutTemplateSyntax;
use Statamic\Contracts\Addons\Settings;
use Statamic\Facades\Addon;
use Statamic\Facades\Blueprint;
use Statamic\Facades\GlobalSet;
use Statamic\Fields\Blueprint as BlueprintInstance;
use Statamic\Globals\GlobalSet as GlobalSetInstance;

/**
 * Guidelines for agents live in the addon's settings, under the guidelines
 * key, and super admins edit them under Tools → MCP → Guidelines. The site
 * text holds voice and tone for every agent; each row of resources names
 * collections and taxonomies and says how their entries are put together.
 */
class AgentGuidelines
{
    public function site(): ?string
    {
        return $this->text(data_get($this->forAgents(), 'site'));
    }

    /**
     * The enabled rows naming this resource, in their order.
     *
     * @param  'collections'|'taxonomies'|'globals'  $type
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
     * The stored values as written, without the Antlers rendering Statamic
     * applies to every addon setting.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return (array) data_get($this->settings()->raw(), 'guidelines', []);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        $this->settings()->set('guidelines', $values)->save();
    }

    /**
     * Neither a site text nor a row, even after a Save with nothing in it.
     */
    public function isEmpty(): bool
    {
        return $this->isBlank($this->values());
    }

    public function blueprint(): BlueprintInstance
    {
        return Blueprint::make('mcp_guidelines')->setContents($this->contents());
    }

    /**
     * The global set 0.6.0 kept guidelines in, while one is left: the set the
     * old config key names, with a blueprint of exactly the two guideline
     * fields. A set by that name with other fields is the site's own.
     */
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
     * What the 0.6.0 set holds for its first site, the only one 0.6.0 read.
     *
     * @return array<string, mixed>
     */
    public function leftoverValues(): array
    {
        $set = $this->leftoverSet();

        return $set?->in((string) $set->sites()->first())?->data()->only(['site', 'resources'])->all() ?? [];
    }

    private function settings(): Settings
    {
        return Addon::get('danielgnh/statamic-mcp')->settings();
    }

    /**
     * Until mcp:guidelines moves a 0.6.0 site's guidelines, agents keep
     * reading them from the global set, so updating never leaves them without.
     * A settings file edited by hand can fail to load; agents then work
     * without guidelines rather than not at all.
     *
     * @return array<string, mixed>
     */
    private function forAgents(): array
    {
        return rescue(function () {
            $values = $this->values();

            return $this->isBlank($values) ? $this->leftoverValues() : $values;
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
                    'display' => 'Main',
                    'sections' => [
                        [
                            'fields' => [
                                ['handle' => 'site', 'field' => [
                                    'type' => 'markdown',
                                    'display' => 'Site',
                                    'instructions' => 'For every agent, in everything it writes: voice and tone, words to use or avoid, how formal to be. Agents read this first, with statamic_overview.',
                                    'validate' => [$plain],
                                ]],
                                ['handle' => 'resources', 'field' => [
                                    'type' => 'replicator',
                                    'display' => 'Collections and taxonomies',
                                    'instructions' => 'How an entry or term is put together: which blocks open it, which never repeat, how many a page usually has, which existing entry to follow. An agent gets a row with every blueprint of the collections and taxonomies it names, through blueprints_get. A rule about one field or block belongs in that blueprint instead.',
                                    'button_label' => 'Add guidelines',
                                    'sets' => [
                                        'main' => [
                                            'display' => 'Main',
                                            'sets' => [
                                                'resource' => [
                                                    'display' => 'Guidelines',
                                                    'instructions' => 'One row per group of collections and taxonomies that share the same rules.',
                                                    'fields' => [
                                                        ['handle' => 'collections', 'field' => ['type' => 'collections', 'display' => 'Collections', 'mode' => 'select', 'width' => 50]],
                                                        ['handle' => 'taxonomies', 'field' => ['type' => 'taxonomies', 'display' => 'Taxonomies', 'mode' => 'select', 'width' => 50]],
                                                        ['handle' => 'guidelines', 'field' => ['type' => 'markdown', 'display' => 'Guidelines', 'validate' => ['required', $plain]]],
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
